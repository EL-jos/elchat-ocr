<?php

namespace App\Payment\Gateways;

use App\Models\Account;
use App\Models\Payment\Coupon;
use App\Models\Payment\Subscription;
use App\Payment\Contracts\PaymentGatewayInterface;
use App\Payment\DTO\ModuleLineItem;
use App\Payment\DTO\SubscriptionSnapshot;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Implémentation PayPal du PaymentGatewayInterface.
 *
 * STRATÉGIE — PayPal Subscriptions API ne supporte pas nativement les lignes
 * multiples dynamiques dans un seul abonnement récurrent. On simule le modèle
 * modulaire ELChat en résolvant/créant un "plan agrégé" PayPal correspondant
 * au montant TOTAL (somme des modules actifs) pour un billing_cycle donné,
 * et en révisant l'abonnement du client vers ce plan à chaque changement de
 * composition. Le cache paypal_plan_cache évite de recréer un plan PayPal
 * identique pour deux comptes ayant la même combinaison de modules.
 *
 * Un seul Product PayPal "ELChat" est réutilisé pour tous les plans créés.
 */
class PaypalPaymentGateway implements PaymentGatewayInterface
{
    private string $baseUrl;
    private string $clientId;
    private string $clientSecret;
    private string $mode;

    public function __construct()
    {
        $this->mode         = config('paypal.mode', 'sandbox');
        $this->baseUrl      = config("paypal.base_url.{$this->mode}");
        $this->clientId     = config("paypal.{$this->mode}.client_id");
        $this->clientSecret = config("paypal.{$this->mode}.client_secret");
    }

    public function providerName(): string
    {
        return 'paypal';
    }

    // ─── Auth ─────────────────────────────────────────────────────────────────

    private function getAccessToken(): string
    {
        $cacheKey = "paypal_access_token_{$this->mode}";

        return Cache::remember($cacheKey, 28800, function () {
            $response = Http::withBasicAuth($this->clientId, $this->clientSecret)
                ->asForm()
                ->post("{$this->baseUrl}/v1/oauth2/token", ['grant_type' => 'client_credentials']);

            if (!$response->successful()) {
                throw new \RuntimeException('PayPal authentication failed: ' . $response->body());
            }

            return $response->json('access_token');
        });
    }

    private function http(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withToken($this->getAccessToken())
            ->withHeaders(['Content-Type' => 'application/json', 'Prefer' => 'return=representation'])
            ->baseUrl($this->baseUrl);
    }

    // ─── Product (unique, réutilisé) ────────────────────────────────────────────

    private function getOrCreateProductId(): string
    {
        return Cache::rememberForever("paypal_product_id_{$this->mode}", function () {
            $response = $this->http()->post('/v1/catalogs/products', [
                'name'        => 'ELChat',
                'description' => 'Plateforme IA conversationnelle modulaire',
                'type'        => 'SERVICE',
                'category'    => 'SOFTWARE',
                'home_url'    => config('app.url'),
            ]);

            if (!$response->successful()) {
                throw new \RuntimeException('PayPal product creation failed: ' . $response->body());
            }

            return $response->json('id');
        });
    }

    // ─── Résolution du plan agrégé par montant ──────────────────────────────────

    /**
     * Résout (ou crée) un plan PayPal correspondant exactement au montant total
     * et au billing_cycle donnés. Réutilise le cache paypal_plan_cache.
     */
    private function resolvePlanForAmount(int $totalAmountCents, string $billingCycle): string
    {
        $cached = DB::table('paypal_plan_cache')
            ->where('amount_eur', $totalAmountCents)
            ->where('billing_cycle', $billingCycle)
            ->first();

        if ($cached) {
            return $cached->paypal_plan_id;
        }

        $productId = $this->getOrCreateProductId();
        $planId    = $this->createPlanForAmount($productId, $totalAmountCents, $billingCycle);

        DB::table('paypal_plan_cache')->insert([
            'id'                => (string) Str::uuid(),
            'amount_eur'        => $totalAmountCents,
            'billing_cycle'     => $billingCycle,
            'paypal_product_id' => $productId,
            'paypal_plan_id'    => $planId,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        return $planId;
    }

    private function createPlanForAmount(string $productId, int $amountCents, string $billingCycle): string
    {
        $intervalUnit  = $billingCycle === 'yearly' ? 'YEAR' : 'MONTH';
        $amountEur     = number_format($amountCents / 100, 2, '.', '');

        $response = $this->http()->post('/v1/billing/plans', [
            'product_id'          => $productId,
            'name'                => "ELChat — {$amountEur}€/" . ($billingCycle === 'yearly' ? 'an' : 'mois'),
            'status'              => 'ACTIVE',
            'billing_cycles'      => [[
                'frequency'      => ['interval_unit' => $intervalUnit, 'interval_count' => 1],
                'tenure_type'    => 'REGULAR',
                'sequence'       => 1,
                'total_cycles'   => 0,
                'pricing_scheme' => [
                    'fixed_price' => ['value' => $amountEur, 'currency_code' => 'EUR'],
                ],
            ]],
            'payment_preferences' => [
                'auto_bill_outstanding'     => true,
                'setup_fee_failure_action'  => 'CONTINUE',
                'payment_failure_threshold' => 3,
            ],
        ]);

        if (!$response->successful()) {
            throw new \RuntimeException('PayPal plan creation failed: ' . $response->body());
        }

        return $response->json('id');
    }

    // ─── Customer ─────────────────────────────────────────────────────────────

    private function resolveCustomerId(Account $account, Subscription $subscription): ?string
    {
        // PayPal n'a pas de vrai "Customer object" pré-créé comme Stripe —
        // le payer est résolu à l'approbation. On garde provider_customer_id
        // nullable jusqu'à la 1ère souscription réussie (rempli via webhook).
        return $subscription->provider_customer_id;
    }

    // ─── Implémentation du contrat ───────────────────────────────────────────────

    public function createSubscription(Account $account, array $lineItems, string $billingCycle): SubscriptionSnapshot
    {
        $total  = array_sum(array_map(fn (ModuleLineItem $i) => $i->unitPriceCents, $lineItems));
        $planId = $this->resolvePlanForAmount($total, $billingCycle);

        $owner = $account->owner;

        $response = $this->http()->post('/v1/billing/subscriptions', [
            'plan_id'    => $planId,
            'subscriber' => [
                'name'          => [
                    'given_name' => $owner->firstname ?? '',
                    'surname'    => $owner->lastname ?? '',
                ],
                'email_address' => $owner->email ?? $account->email,
            ],
            'application_context' => [
                'brand_name'          => 'ELChat',
                'locale'              => 'fr-FR',
                'shipping_preference' => 'NO_SHIPPING',
                'user_action'         => 'SUBSCRIBE_NOW',
                // 🆕 Popup PayPal → retour sur nos routes web dédiées (postMessage)
                'return_url'          => route('paypal.checkout.return', ['account_id' => $account->id]),
                'cancel_url'          => route('paypal.checkout.cancel', ['account_id' => $account->id]),
            ],
            'custom_id' => $account->id,
        ]);

        if (!$response->successful()) {
            throw new \RuntimeException('PayPal createSubscription failed: ' . $response->body());
        }

        $data = $response->json();

        // 🆕 Extraction de l'URL d'approbation (rel=approve)
        $approvalUrl = collect($data['links'] ?? [])->firstWhere('rel', 'approve')['href'] ?? null;

        return new SubscriptionSnapshot(
            providerSubscriptionId: $data['id'],
            providerCustomerId: null,
            status: 'incomplete',
            totalAmountCents: $total,
            currency: 'EUR',
            billingCycle: $billingCycle,
            approvalUrl: $approvalUrl,   // 🆕
            raw: $data,
        );
    }

    public function updateSubscription(Subscription $subscription, array $lineItems): SubscriptionSnapshot
    {
        $total  = array_sum(array_map(fn (ModuleLineItem $i) => $i->unitPriceCents, $lineItems));
        $planId = $this->resolvePlanForAmount($total, $subscription->billing_cycle);

        if (!$subscription->provider_subscription_id) {
            throw new \RuntimeException('Cannot update: no PayPal subscription id on record.');
        }

        // PayPal : révision de plan
        $response = $this->http()->post(
            "/v1/billing/subscriptions/{$subscription->provider_subscription_id}/revise",
            ['plan_id' => $planId]
        );

        if (!$response->successful()) {
            throw new \RuntimeException('PayPal revise failed: ' . $response->body());
        }

        $data = $response->json();

        return new SubscriptionSnapshot(
            providerSubscriptionId: $subscription->provider_subscription_id,
            providerCustomerId: $subscription->provider_customer_id,
            status: $subscription->status, // le statut réel sera confirmé par webhook
            totalAmountCents: $total,
            currency: 'EUR',
            billingCycle: $subscription->billing_cycle,
            raw: $data,
        );
    }

    public function cancelSubscription(Subscription $subscription): void
    {
        if (!$subscription->provider_subscription_id) return;

        $this->http()->post(
            "/v1/billing/subscriptions/{$subscription->provider_subscription_id}/cancel",
            ['reason' => 'Résiliation ELChat']
        );
    }

    public function addModule(Subscription $subscription, array $currentLineItems, ModuleLineItem $newItem): SubscriptionSnapshot
    {
        $allItems = [...$currentLineItems, $newItem];
        return $this->updateSubscription($subscription, $allItems);
    }

    public function removeModule(Subscription $subscription, array $remainingLineItems): SubscriptionSnapshot
    {
        return $this->updateSubscription($subscription, $remainingLineItems);
    }

    public function changeModuleTier(
        Subscription $subscription,
        array $currentLineItems,
        ModuleLineItem $updatedItem
    ): SubscriptionSnapshot {
        // Remplace la ligne du module concerné par sa version au nouveau tier
        $newItems = array_map(
            fn (ModuleLineItem $item) => $item->module->id === $updatedItem->module->id ? $updatedItem : $item,
            $currentLineItems
        );

        // NOTE proration : PayPal ne gère pas de vrai calcul au prorata lors d'une
        // révision de plan — le nouveau montant total s'applique au prochain cycle
        // de facturation (best-effort). Documenté dans subscription_item_events par
        // l'Orchestrator. Stripe (futur) gérera une vraie proration immédiate.
        return $this->updateSubscription($subscription, $newItems);
    }

    public function applyCoupon(Subscription $subscription, Coupon $coupon, array $lineItems): SubscriptionSnapshot
    {
        // Le montant net (après réduction) est déjà calculé en amont par CouponService
        // — ici lineItems reflète déjà les montants réduits si applicable.
        return $this->updateSubscription($subscription, $lineItems);
    }

    // ─── Webhook ──────────────────────────────────────────────────────────────

    public function handleWebhook(string $payload, array $headers): ?SubscriptionSnapshot
    {
        if (!$this->verifyWebhookSignature($payload, $headers)) {
            Log::warning('PaypalPaymentGateway: invalid webhook signature');
            return null;
        }

        $event    = json_decode($payload, true);
        $resource = $event['resource'] ?? [];

        if (!isset($resource['id'])) return null;

        $billingInfo = $resource['billing_info'] ?? [];

        return new SubscriptionSnapshot(
            providerSubscriptionId: $resource['id'],
            providerCustomerId: $resource['subscriber']['payer_id'] ?? null,
            status: $this->mapStatus($resource['status'] ?? ''),
            totalAmountCents: (int) round((float) ($billingInfo['last_payment']['amount']['value'] ?? 0) * 100),
            currency: 'EUR',
            billingCycle: 'monthly', // affiné par l'Orchestrator via subscription locale
            currentPeriodEnd: isset($billingInfo['next_billing_time'])
                ? \DateTimeImmutable::createFromFormat(DATE_ATOM, $billingInfo['next_billing_time']) ?: null
                : null,
            raw: $event,
        );
    }

    private function verifyWebhookSignature(string $payload, array $headers): bool
    {
        $webhookId = config("paypal.{$this->mode}.webhook_id");
        if (!$webhookId) {
            return config('app.env') !== 'production';
        }

        $response = $this->http()->post('/v1/notifications/verify-webhook-signature', [
            'auth_algo'         => $headers['paypal-auth-algo']         ?? '',
            'cert_url'          => $headers['paypal-cert-url']          ?? '',
            'transmission_id'   => $headers['paypal-transmission-id']   ?? '',
            'transmission_sig'  => $headers['paypal-transmission-sig']  ?? '',
            'transmission_time' => $headers['paypal-transmission-time'] ?? '',
            'webhook_id'        => $webhookId,
            'webhook_event'     => json_decode($payload, true),
        ]);

        return $response->successful() && $response->json('verification_status') === 'SUCCESS';
    }

    private function mapStatus(string $paypalStatus): string
    {
        return match (strtoupper($paypalStatus)) {
            'ACTIVE'           => 'active',
            'APPROVAL_PENDING', 'APPROVED' => 'incomplete',
            'SUSPENDED'         => 'past_due',
            'CANCELLED', 'EXPIRED' => 'canceled',
            default             => 'incomplete',
        };
    }

    public function retrieveSubscriptionStatus(string $providerSubscriptionId): SubscriptionSnapshot
    {
        $response = $this->http()->get("/v1/billing/subscriptions/{$providerSubscriptionId}");

        if (!$response->successful()) {
            throw new \RuntimeException('PayPal retrieveSubscriptionStatus failed: ' . $response->body());
        }

        $data        = $response->json();
        $billingInfo = $data['billing_info'] ?? [];

        return new SubscriptionSnapshot(
            providerSubscriptionId: $data['id'],
            providerCustomerId: $data['subscriber']['payer_id'] ?? null,
            status: $this->mapStatus($data['status'] ?? ''),
            totalAmountCents: (int) round((float) ($billingInfo['last_payment']['amount']['value'] ?? 0) * 100),
            currency: 'EUR',
            billingCycle: 'monthly',
            currentPeriodEnd: isset($billingInfo['next_billing_time'])
                ? \DateTimeImmutable::createFromFormat(DATE_ATOM, $billingInfo['next_billing_time']) ?: null
                : null,
            raw: $data,
        );
    }
}
