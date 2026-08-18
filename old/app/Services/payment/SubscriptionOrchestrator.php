<?php

namespace App\Services\payment;


use App\Events\ModuleActivated;
use App\Events\ModuleDeactivated;
use App\Events\SubscriptionUpdated;
use App\Models\Account;
use App\Models\Payment\Module;
use App\Models\Payment\Subscription;
use App\Models\Payment\SubscriptionItem;
use App\Payment\DTO\ModuleLineItem;
use App\Payment\PaymentGatewayFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Point d'entrée UNIQUE pour toute opération d'abonnement modulaire.
 * Les Controllers ne doivent JAMAIS parler directement à un PaymentGateway
 * ou manipuler les tables subscriptions/subscription_items — tout passe ici.
 */
class SubscriptionOrchestrator
{
    public function __construct(
        private PaymentGatewayFactory $gatewayFactory,
        private PricingCalculator     $pricing,
        private CouponService         $coupons,
    ) {}

    // ─── Bootstrap trial ──────────────────────────────────────────────────────

    /**
     * Crée l'abonnement trial à l'inscription : Core + tous les modules
     * included_in_trial=true, sans création d'abonnement chez le provider
     * (aucun paiement requis tant que le trial est actif).
     */
    public function createTrialSubscription(Account $account): Subscription
    {
        return DB::transaction(function () use ($account) {
            $trialDays = config('subscription.trial_days', 7);

            $subscription = Subscription::create([
                'account_id'       => $account->id,
                'payment_provider' => config('subscription.default_provider', 'paypal'),
                'status'           => 'trialing',
                'billing_cycle'    => 'monthly',
                'trial_ends_at'    => now()->addDays($trialDays),
            ]);

            // 🆕 Uniquement Core est activé automatiquement — tout le reste est
            // explicitement activé par le client (bouton "Essayer" ou "Payer").
            $core  = Module::where('slug', 'core')->active()->firstOrFail();
            $tier  = $core->defaultTier();
            $price = $tier->priceFor('monthly');

            $item = SubscriptionItem::create([
                'subscription_id' => $subscription->id,
                'module_id'       => $core->id,
                'module_tier_id'  => $tier->id,
                'unit_price_eur'  => $price?->price_eur ?? 0,
                'billing_cycle'   => 'monthly',
                'status'          => 'trialing',
                'activated_at'    => now(),
            ]);

            $item->logEvent('trial_started', null, ['module' => 'core', 'tier' => $tier->slug]);

            return $subscription;
        });
    }

    // ─── Activation d'un module ──────────────────────────────────────────────

    public function activateModule(
        Account $account,
        string  $moduleSlug,
        ?string $tierSlug = null,
        ?string $billingCycle = null
    ): Subscription {
        $module = Module::where('slug', $moduleSlug)->active()->firstOrFail();

        if ($module->isContactSales()) {
            throw new \RuntimeException("Le module '{$moduleSlug}' nécessite un contact commercial, pas d'activation directe.");
        }

        $subscription = $account->subscription;

        if (!$subscription) {
            throw new \RuntimeException("Aucun abonnement ELChat pour ce compte — createTrialSubscription() doit être appelé à l'inscription.");
        }

        if ($subscription->hasModule($moduleSlug)) {
            throw new \RuntimeException("Le module '{$moduleSlug}' est déjà actif pour ce compte.");
        }

        $cycle = $billingCycle ?? $subscription->billing_cycle;
        $tier  = $tierSlug
            ? $module->tiers()->active()->where('slug', $tierSlug)->firstOrFail()
            : $module->defaultTier();

        $price = $this->pricing->resolvePriceForTier($tier, $cycle);

        return DB::transaction(function () use ($account, $subscription, $module, $tier, $price, $cycle) {
            $item = SubscriptionItem::create([
                'subscription_id' => $subscription->id,
                'module_id'       => $module->id,
                'module_tier_id'  => $tier->id,
                'unit_price_eur'  => $price,
                'billing_cycle'   => $cycle,
                'status'          => 'active',
                'activated_at'    => now(),
            ]);

            $item->logEvent('activated', null, ['module' => $module->slug, 'tier' => $tier->slug, 'price' => $price]);

            $this->syncProvider($subscription);

            event(new ModuleActivated($subscription, $item));
            event(new SubscriptionUpdated($subscription));

            return $subscription->fresh();
        });
    }

    // ─── Désactivation d'un module (effet à la fin du cycle payé) ────────────

    public function deactivateModule(Account $account, string $moduleSlug): Subscription
    {
        $module = Module::where('slug', $moduleSlug)->firstOrFail();

        if ($module->is_core) {
            throw new \RuntimeException('Le module Core est obligatoire et ne peut pas être désactivé.');
        }

        $subscription = $account->subscription;
        $item         = $subscription?->itemForModule($moduleSlug);

        if (!$item) {
            throw new \RuntimeException("Le module '{$moduleSlug}' n'est pas actif pour ce compte.");
        }

        return DB::transaction(function () use ($subscription, $item) {
            $previousState = ['status' => $item->status];

            // Pas de coupure immédiate — accès conservé jusqu'à la fin de la période payée
            $item->update([
                'status'         => 'pending_cancellation',
                'canceled_at'    => now(),
                'access_ends_at' => $subscription->current_period_end ?? now(),
            ]);

            $item->logEvent('deactivation_requested', $previousState, [
                'status'         => 'pending_cancellation',
                'access_ends_at' => $item->access_ends_at?->toIso8601String(),
            ]);

            // Informer le provider dès maintenant du nouveau total à venir
            // (le retrait effectif de la ligne côté ELChat se fait au job planifié,
            // mais le provider doit refléter le futur montant pour le prochain cycle)
            $this->syncProvider($subscription, excludeItemId: $item->id);

            event(new SubscriptionUpdated($subscription));

            return $subscription->fresh();
        });
    }

    /**
     * Finalise les désactivations dont la période payée est terminée.
     * Appelé par le job planifié quotidien (jamais par une requête utilisateur).
     */
    public function finalizeDueCancellations(): int
    {
        $dueItems = SubscriptionItem::where('status', 'pending_cancellation')
            ->where('access_ends_at', '<=', now())
            ->get();

        foreach ($dueItems as $item) {
            DB::transaction(function () use ($item) {
                $previousState = ['status' => $item->status];

                $item->update(['status' => 'canceled']);
                $item->logEvent('deactivated', $previousState, ['status' => 'canceled']);

                event(new ModuleDeactivated($item->subscription, $item));
            });
        }

        return $dueItems->count();
    }

    // ─── Upgrade/downgrade de tier (en place) ────────────────────────────────

    public function changeModuleTier(Account $account, string $moduleSlug, string $newTierSlug): Subscription
    {
        $module       = Module::where('slug', $moduleSlug)->active()->firstOrFail();
        $subscription = $account->subscription;
        $item         = $subscription?->itemForModule($moduleSlug);

        if (!$item) {
            throw new \RuntimeException("Le module '{$moduleSlug}' n'est pas actif — activez-le d'abord.");
        }

        $newTier = $module->tiers()->active()->where('slug', $newTierSlug)->firstOrFail();

        if ($item->module_tier_id === $newTier->id) {
            throw new \RuntimeException("Le module '{$moduleSlug}' est déjà au tier '{$newTierSlug}'.");
        }

        $newPrice = $this->pricing->resolvePriceForTier($newTier, $item->billing_cycle);

        return DB::transaction(function () use ($subscription, $item, $newTier, $newPrice, $module, $newTierSlug) {
            $previousState = [
                'tier'  => $item->moduleTier?->slug,
                'price' => $item->unit_price_eur,
            ];

            // UPGRADE EN PLACE — même ligne, même id, jamais un nouvel item
            $item->update([
                'module_tier_id' => $newTier->id,
                'unit_price_eur' => $newPrice,
            ]);

            $item->logEvent('tier_changed', $previousState, [
                'tier'  => $newTierSlug,
                'price' => $newPrice,
            ]);

            // Le Gateway gère la proration best-effort selon sa capacité (voir
            // PaypalPaymentGateway::changeModuleTier — documenté comme "best-effort").
            $this->syncProvider($subscription);

            event(new SubscriptionUpdated($subscription));

            return $subscription->fresh();
        });
    }

    // ─── Application d'un coupon ──────────────────────────────────────────────

    public function applyCoupon(Account $account, string $couponCode): Subscription
    {
        $subscription = $account->subscription;
        $coupon       = $this->coupons->findValidCoupon($couponCode);

        if (!$coupon) {
            throw new \RuntimeException('Code promo invalide ou expiré.');
        }

        return DB::transaction(function () use ($subscription, $coupon) {
            $this->coupons->attachToSubscription($subscription, $coupon);

            $lineItems = $this->currentLineItems($subscription);
            $gateway   = $this->gatewayFactory->forSubscription($subscription);

            $gateway->applyCoupon($subscription, $coupon, $lineItems);

            event(new SubscriptionUpdated($subscription));

            return $subscription->fresh();
        });
    }

    // ─── Helpers internes ─────────────────────────────────────────────────────

    /**
     * Construit les ModuleLineItem[] à partir des subscription_items actifs
     * (hors ceux explicitement exclus — utilisé lors d'une désactivation en cours).
     */
    private function currentLineItems(Subscription $subscription, ?string $excludeItemId = null): array
    {
        return $subscription->activeItems()
            ->with(['module', 'moduleTier'])
            ->get()
            ->reject(fn (SubscriptionItem $i) => $i->id === $excludeItemId)
            ->map(fn (SubscriptionItem $i) => ModuleLineItem::fromSubscriptionItem($i))
            ->values()
            ->all();
    }

    /**
     * Synchronise le montant total avec le provider après tout changement de composition.
     * Ne fait rien si l'abonnement est encore en trial pur (aucun provider_subscription_id).
     */
    private function syncProvider(Subscription $subscription, ?string $excludeItemId = null): void
    {
        if ($subscription->isTrialing() && !$subscription->provider_subscription_id) {
            // Rien à synchroniser côté provider tant que le trial n'a pas été converti
            return;
        }

        $lineItems = $this->currentLineItems($subscription, $excludeItemId);
        $gateway   = $this->gatewayFactory->forSubscription($subscription);

        try {
            if (!$subscription->provider_subscription_id) {
                $snapshot = $gateway->createSubscription($subscription->account, $lineItems, $subscription->billing_cycle);
                $subscription->update(['provider_subscription_id' => $snapshot->providerSubscriptionId]);
            } else {
                $gateway->updateSubscription($subscription, $lineItems);
            }
        } catch (\Throwable $e) {
            Log::error('SubscriptionOrchestrator: provider sync failed', [
                'subscription_id' => $subscription->id,
                'error'            => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Convertit un trial en abonnement payant réel — appelé quand l'utilisateur
     * confirme les modules à conserver après expiration du trial.
     */
    public function convertTrialToPaid(Account $account, array $modulesToKeepSlugs, string $billingCycle): Subscription
    {
        $subscription = $account->subscription;

        return DB::transaction(function () use ($subscription, $modulesToKeepSlugs) {
            $items = $subscription->items()->where('status', 'trialing')->with('module')->get();

            foreach ($items as $item) {
                if (in_array($item->module->slug, $modulesToKeepSlugs) || $item->module->is_core) {
                    $item->update(['status' => 'active']);
                    $item->logEvent('trial_converted', ['status' => 'trialing'], ['status' => 'active']);
                } else {
                    $item->update(['status' => 'canceled', 'canceled_at' => now()]);
                    $item->logEvent('deactivated', ['status' => 'trialing'], ['status' => 'canceled']);
                }
            }

            $subscription->update(['status' => 'active', 'trial_ends_at' => null]);

            $this->syncProvider($subscription);

            event(new SubscriptionUpdated($subscription));

            return $subscription->fresh();
        });
    }

    /**
     * Essai gratuit d'un module — aucun paiement, disponible uniquement
     * pendant la fenêtre de trial du compte.
     */
    public function startModuleTrial(Account $account, string $moduleSlug, ?string $tierSlug = null): Subscription
    {
        $module = Module::where('slug', $moduleSlug)->active()->firstOrFail();

        if ($module->isContactSales()) {
            throw new \RuntimeException("Le module '{$moduleSlug}' nécessite un contact commercial.");
        }
        if (!$module->included_in_trial) {
            throw new \RuntimeException("Le module '{$moduleSlug}' n'est pas disponible en essai.");
        }

        $subscription = $account->subscription;
        if (!$subscription) {
            throw new \RuntimeException('Aucun abonnement ELChat pour ce compte.');
        }
        if (!$subscription->trial_ends_at || $subscription->trial_ends_at->isPast()) {
            throw new \RuntimeException("Votre période d'essai est terminée. Payez ce module directement via PayPal.");
        }
        if ($subscription->hasModule($moduleSlug)) {
            throw new \RuntimeException("Le module '{$moduleSlug}' est déjà actif.");
        }

        $tier  = $tierSlug
            ? $module->tiers()->active()->where('slug', $tierSlug)->firstOrFail()
            : $module->defaultTier();
        $price = $this->pricing->resolvePriceForTier($tier, $subscription->billing_cycle);

        return DB::transaction(function () use ($subscription, $module, $tier, $price) {
            $item = SubscriptionItem::create([
                'subscription_id' => $subscription->id,
                'module_id'       => $module->id,
                'module_tier_id'  => $tier->id,
                'unit_price_eur'  => $price,
                'billing_cycle'   => $subscription->billing_cycle,
                'status'          => 'trialing',
                'activated_at'    => now(),
            ]);

            $item->logEvent('trial_started', null, ['module' => $module->slug, 'tier' => $tier->slug]);
            event(new SubscriptionUpdated($subscription));

            return $subscription->fresh();
        });
    }

    /**
     * Paiement réel d'un module via PayPal.
     * - Si le compte n'a JAMAIS payé (aucun provider_subscription_id) → une approbation
     *   PayPal est nécessaire → retourne une approval_url pour ouvrir la popup.
     * - Si le compte a déjà un abonnement PayPal approuvé → révision silencieuse,
     *   aucune popup nécessaire, activation immédiate.
     *
     * @return array{subscription: Subscription, approval_url: ?string}
     */
    public function purchaseModule(Account $account, string $moduleSlug, ?string $tierSlug = null, ?string $billingCycle = null): array
    {
        $module = Module::where('slug', $moduleSlug)->active()->firstOrFail();

        if ($module->isContactSales()) {
            throw new \RuntimeException("Le module '{$moduleSlug}' nécessite un contact commercial.");
        }

        $subscription = $account->subscription;
        if (!$subscription) {
            throw new \RuntimeException('Aucun abonnement ELChat pour ce compte.');
        }

        $existingItem = $subscription->itemForModule($moduleSlug);
        $cycle        = $billingCycle ?? $subscription->billing_cycle;
        $tier         = $tierSlug
            ? $module->tiers()->active()->where('slug', $tierSlug)->firstOrFail()
            : $module->defaultTier();
        $price        = $this->pricing->resolvePriceForTier($tier, $cycle);
        $needsApproval = !$subscription->provider_subscription_id;

        return DB::transaction(function () use ($account, $subscription, $module, $tier, $price, $cycle, $existingItem, $needsApproval) {

            $status = $needsApproval ? 'incomplete' : 'active';

            if ($existingItem) {
                $previousState = ['status' => $existingItem->status, 'tier' => $existingItem->moduleTier?->slug];
                $existingItem->update([
                    'module_tier_id' => $tier->id,
                    'unit_price_eur' => $price,
                    'status'         => $status,
                    'activated_at'   => now(),
                ]);
                $item = $existingItem;
                $item->logEvent('activated', $previousState, ['status' => $status, 'tier' => $tier->slug]);
            } else {
                $item = SubscriptionItem::create([
                    'subscription_id' => $subscription->id,
                    'module_id'       => $module->id,
                    'module_tier_id'  => $tier->id,
                    'unit_price_eur'  => $price,
                    'billing_cycle'   => $cycle,
                    'status'          => $status,
                    'activated_at'    => now(),
                ]);
                $item->logEvent('activated', null, ['module' => $module->slug, 'tier' => $tier->slug, 'price' => $price]);
            }

            $gateway = $this->gatewayFactory->forSubscription($subscription);

            if ($needsApproval) {
                $lineItems   = $this->currentLineItems($subscription);
                $lineItems[] = ModuleLineItem::fromSubscriptionItem($item); // l'item 'incomplete' n'est pas dans activeItems()

                $snapshot = $gateway->createSubscription($account, $lineItems, $cycle);

                return ['subscription' => $subscription->fresh(), 'approval_url' => $snapshot->approvalUrl];
            }

            $lineItems = $this->currentLineItems($subscription); // inclut déjà l'item (status='active')
            $gateway->updateSubscription($subscription, $lineItems);

            event(new ModuleActivated($subscription, $item));
            event(new SubscriptionUpdated($subscription));

            return ['subscription' => $subscription->fresh(), 'approval_url' => null];
        });
    }

    /**
     * Appelé UNIQUEMENT par PaypalCheckoutReturnController après retour de la popup.
     * Confirme via l'API PayPal (source de vérité) que le paiement est réellement actif,
     * puis finalise tous les items en attente ('incomplete') pour ce compte.
     */
    public function confirmPurchase(string $accountId, string $providerSubscriptionId): Subscription
    {
        $account      = Account::findOrFail($accountId);
        $subscription = $account->subscription;

        if (!$subscription) {
            throw new \RuntimeException('Aucun abonnement trouvé pour ce compte.');
        }

        $gateway  = $this->gatewayFactory->forSubscription($subscription);
        $snapshot = $gateway->retrieveSubscriptionStatus($providerSubscriptionId);

        if ($snapshot->status !== 'active') {
            throw new \RuntimeException("Paiement non confirmé côté PayPal (statut: {$snapshot->status}).");
        }

        return DB::transaction(function () use ($subscription, $snapshot, $providerSubscriptionId) {
            $subscription->update([
                'provider_subscription_id' => $providerSubscriptionId,
                'provider_customer_id'     => $snapshot->providerCustomerId ?? $subscription->provider_customer_id,
                'status'                   => 'active',
                'current_period_start'     => now(),
                'current_period_end'       => $snapshot->currentPeriodEnd
                    ? \DateTime::createFromImmutable($snapshot->currentPeriodEnd)
                    : ($subscription->billing_cycle === 'yearly' ? now()->addYear() : now()->addMonth()),
            ]);

            foreach ($subscription->items()->where('status', 'incomplete')->get() as $item) {
                $item->update(['status' => 'active']);
                $item->logEvent('activated', ['status' => 'incomplete'], ['status' => 'active']);
                event(new ModuleActivated($subscription, $item));
            }

            event(new SubscriptionUpdated($subscription));

            return $subscription->fresh();
        });
    }

}
