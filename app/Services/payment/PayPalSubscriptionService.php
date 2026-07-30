<?php

namespace App\Services\payment;

use App\Mail\SubscriptionConfirmed;
use App\Models\Account;
use App\Models\Payment\Plan;
use App\Models\Payment\Subscription;
use App\Models\Payment\SubscriptionEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class PayPalSubscriptionService
{
    public function __construct(private PayPalService $paypal) {}

    // ─── Initier un abonnement PayPal ─────────────────────────────────────────

    /**
     * Crée la subscription PayPal et retourne l'URL d'approbation.
     * L'abonnement est créé en DB avec status='incomplete' en attente de confirmation.
     */
    public function initiate(Account $account, Plan $plan, string $billingCycle): string
    {
        $result = $this->paypal->createSubscription($account, $plan, $billingCycle);

        // Persister en DB avec status incomplete — sera mis à jour par le webhook
        DB::transaction(function () use ($account, $plan, $billingCycle, $result) {
            $existing = $account->subscription;

            $data = [
                'plan_id'               => $plan->id,
                'payment_provider'      => 'paypal',
                'billing_cycle'         => $billingCycle,
                'status'                => 'incomplete',
                'paypal_subscription_id'=> $result['subscription_id'],
                'paypal_plan_id'        => $plan->getPayPalPlanId($billingCycle),
                // Réinitialiser les champs Stripe pour éviter toute confusion
                'stripe_customer_id'    => null,
                'stripe_subscription_id'=> null,
                'stripe_price_id'       => null,
            ];

            if ($existing) {
                $existing->update($data);
            } else {
                Subscription::create(array_merge(['account_id' => $account->id], $data));
            }
        });

        Log::info('PayPalSubscriptionService: Subscription initiated', [
            'account_id'      => $account->id,
            'plan'            => $plan->slug,
            'cycle'           => $billingCycle,
            'paypal_sub_id'   => $result['subscription_id'],
        ]);

        return $result['approval_url'];
    }

    // ─── Capture après retour PayPal (return_url) ─────────────────────────────

    /**
     * Appelé quand PayPal redirige l'utilisateur vers /payment/success?provider=paypal
     * On vérifie l'état réel de la subscription via l'API PayPal.
     */
    public function capture(
        string $paypalSubscriptionId,
        string $accountId,
        string $planId,
        string $billingCycle
    ): Subscription {

        // Vérifier l'état auprès de PayPal (source of truth)
        $paypalData   = $this->paypal->getSubscription($paypalSubscriptionId);
        $paypalStatus = $paypalData['status'] ?? 'UNKNOWN';

        $subscription = Subscription::where('paypal_subscription_id', $paypalSubscriptionId)
            ->orWhere('account_id', $accountId)
            ->first();

        if (!$subscription) {
            throw new \RuntimeException("Subscription introuvable pour PayPal ID: {$paypalSubscriptionId}");
        }

        $mappedStatus = $this->paypal->mapStatus($paypalStatus);

        // Extraire les dates de la réponse PayPal
        $billingInfo      = $paypalData['billing_info'] ?? [];
        $nextBillingTime  = $this->paypal->parseDate($billingInfo['next_billing_time'] ?? null);
        $lastPaymentTime  = $this->paypal->parseDate(
            $billingInfo['last_payment']['time'] ?? null
        );

        DB::transaction(function () use (
            $subscription, $paypalData, $paypalSubscriptionId,
            $mappedStatus, $nextBillingTime, $lastPaymentTime, $paypalStatus
        ) {
            $subscription->update([
                'payment_provider'      => 'paypal',
                'paypal_subscription_id'=> $paypalSubscriptionId,
                'paypal_payer_id'       => $paypalData['subscriber']['payer_id'] ?? null,
                'status'                => $mappedStatus,
                'current_period_start'  => $lastPaymentTime  ?? now(),
                'current_period_end'    => $nextBillingTime  ?? now()->addMonth(),
                // Effacer le trial si l'utilisateur souscrit depuis le trial
                'trial_ends_at'         => null,
            ]);
        });

        // Envoyer l'email de confirmation si actif
        if (in_array($mappedStatus, ['active', 'incomplete'])) {
            $this->sendConfirmationEmail($subscription->account);
        }

        Log::info('PayPalSubscriptionService: Subscription captured', [
            'paypal_sub_id' => $paypalSubscriptionId,
            'paypal_status' => $paypalStatus,
            'local_status'  => $mappedStatus,
        ]);

        return $subscription->fresh();
    }

    // ─── Traitement des webhooks PayPal ──────────────────────────────────────

    /**
     * Point d'entrée central — dispatch vers les handlers.
     */
    public function handleWebhookEvent(array $event): void
    {
        $eventId   = $event['id']          ?? null;
        $eventType = $event['event_type']  ?? null;

        // Idempotence — ignorer si déjà traité
        if ($eventId && SubscriptionEvent::where('stripe_event_id', $eventId)->exists()) {
            Log::info('PayPalSubscriptionService: Event already processed', ['event_id' => $eventId]);
            return;
        }

        // Logger dans la table d'audit (réutilisation de subscription_events)
        $auditRecord = SubscriptionEvent::create([
            'stripe_event_id'   => $eventId, // Réutilise la colonne pour stocker l'ID PayPal
            'provider'          => 'paypal',
            'event_type'        => $eventType,
            'payload'           => $event,
            'stripe_created_at' => now(),
            'status'            => 'processed',
        ]);

        try {
            $resource = $event['resource'] ?? [];

            match ($eventType) {
                'BILLING.SUBSCRIPTION.ACTIVATED'        => $this->onActivated($resource, $auditRecord),
                'BILLING.SUBSCRIPTION.UPDATED'          => $this->onUpdated($resource, $auditRecord),
                'BILLING.SUBSCRIPTION.CANCELLED'        => $this->onCancelled($resource, $auditRecord),
                'BILLING.SUBSCRIPTION.SUSPENDED'        => $this->onSuspended($resource, $auditRecord),
                'BILLING.SUBSCRIPTION.PAYMENT.FAILED'   => $this->onPaymentFailed($resource, $auditRecord),
                'PAYMENT.SALE.COMPLETED'                => $this->onPaymentCompleted($resource, $auditRecord),
                default                                 => $auditRecord->update(['status' => 'ignored']),
            };

        } catch (\Exception $e) {
            $auditRecord->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
            ]);
            Log::error('PayPalSubscriptionService: Webhook handler failed', [
                'event_type' => $eventType,
                'error'      => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    // ─── Handlers individuels ─────────────────────────────────────────────────

    private function onActivated(array $resource, SubscriptionEvent $audit): void
    {
        $subscription = $this->findByPayPalId($resource['id'] ?? null);
        if (!$subscription) return;

        $billingInfo     = $resource['billing_info'] ?? [];
        $nextBillingTime = $this->paypal->parseDate($billingInfo['next_billing_time'] ?? null);

        $subscription->update([
            'status'              => 'active',
            'current_period_end'  => $nextBillingTime ?? now()->addMonth(),
            'trial_ends_at'       => null,
        ]);

        $audit->update([
            'subscription_id' => $subscription->id,
            'account_id'      => $subscription->account_id,
        ]);

        $this->sendConfirmationEmail($subscription->account);

        Log::info('PayPalSubscriptionService: Activated', ['sub_id' => $subscription->id]);
    }

    private function onUpdated(array $resource, SubscriptionEvent $audit): void
    {
        $subscription = $this->findByPayPalId($resource['id'] ?? null);
        if (!$subscription) return;

        $billingInfo     = $resource['billing_info'] ?? [];
        $nextBillingTime = $this->paypal->parseDate($billingInfo['next_billing_time'] ?? null);

        $subscription->update([
            'status'             => $this->paypal->mapStatus($resource['status'] ?? ''),
            'current_period_end' => $nextBillingTime ?? $subscription->current_period_end,
        ]);

        $audit->update([
            'subscription_id' => $subscription->id,
            'account_id'      => $subscription->account_id,
        ]);

        Log::info('PayPalSubscriptionService: Updated', ['sub_id' => $subscription->id]);
    }

    private function onCancelled(array $resource, SubscriptionEvent $audit): void
    {
        $subscription = $this->findByPayPalId($resource['id'] ?? null);
        if (!$subscription) return;

        $subscription->update([
            'status'      => 'canceled',
            'canceled_at' => now(),
            'ends_at'     => $subscription->current_period_end ?? now(),
        ]);

        $audit->update([
            'subscription_id' => $subscription->id,
            'account_id'      => $subscription->account_id,
        ]);

        Log::info('PayPalSubscriptionService: Cancelled', ['sub_id' => $subscription->id]);
    }

    private function onSuspended(array $resource, SubscriptionEvent $audit): void
    {
        $subscription = $this->findByPayPalId($resource['id'] ?? null);
        if (!$subscription) return;

        $subscription->update(['status' => 'past_due']);

        $audit->update([
            'subscription_id' => $subscription->id,
            'account_id'      => $subscription->account_id,
        ]);

        Log::warning('PayPalSubscriptionService: Suspended', ['sub_id' => $subscription->id]);
    }

    private function onPaymentFailed(array $resource, SubscriptionEvent $audit): void
    {
        $subscription = $this->findByPayPalId($resource['id'] ?? null);
        if (!$subscription) return;

        $subscription->update(['status' => 'past_due']);

        $audit->update([
            'subscription_id' => $subscription->id,
            'account_id'      => $subscription->account_id,
        ]);

        Log::warning('PayPalSubscriptionService: Payment failed', ['sub_id' => $subscription->id]);
        // TODO : Envoyer un email d'alerte paiement échoué
    }

    private function onPaymentCompleted(array $resource, SubscriptionEvent $audit): void
    {
        // PAYMENT.SALE.COMPLETED est émis à chaque renouvellement réussi
        $billingAgreementId = $resource['billing_agreement_id'] ?? null;
        if (!$billingAgreementId) return;

        $subscription = $this->findByPayPalId($billingAgreementId);
        if (!$subscription) return;

        // Réactiver si past_due
        if ($subscription->status === 'past_due') {
            $subscription->update(['status' => 'active']);
        }

        // Mettre à jour la période courante
        $subscription->update([
            'current_period_start' => now(),
            'current_period_end'   => $subscription->billing_cycle === 'annual'
                ? now()->addYear()
                : now()->addMonth(),
        ]);

        $audit->update([
            'subscription_id' => $subscription->id,
            'account_id'      => $subscription->account_id,
        ]);

        Log::info('PayPalSubscriptionService: Payment completed', [
            'sub_id' => $subscription->id,
            'amount' => $resource['amount']['total'] ?? '?',
        ]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function findByPayPalId(?string $paypalSubscriptionId): ?Subscription
    {
        if (!$paypalSubscriptionId) return null;

        $subscription = Subscription::where('paypal_subscription_id', $paypalSubscriptionId)
            ->with(['account.owner', 'plan'])
            ->first();

        if (!$subscription) {
            Log::warning('PayPalSubscriptionService: Subscription not found', [
                'paypal_subscription_id' => $paypalSubscriptionId,
            ]);
        }

        return $subscription;
    }

    private function sendConfirmationEmail(Account $account): void
    {
        try {
            $user = $account->owner;
            if ($user?->email) {
                Mail::to($user->email)->send(new SubscriptionConfirmed($account));
                Log::info('PayPalSubscriptionService: Confirmation email sent', [
                    'email' => $user->email,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('PayPalSubscriptionService: Failed to send email', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
