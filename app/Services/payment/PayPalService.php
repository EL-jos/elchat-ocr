<?php

namespace App\Services\payment;

use App\Models\Account;
use App\Models\Payment\Plan;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PayPalService
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

    // ─── Auth ─────────────────────────────────────────────────────────────────

    /**
     * Obtenir un access token OAuth2 PayPal (mis en cache 8h).
     */
    public function getAccessToken(): string
    {
        $cacheKey = "paypal_access_token_{$this->mode}";
        $cacheTtl = config('paypal.token_cache_ttl', 28800);

        return Cache::remember($cacheKey, $cacheTtl, function () {
            $response = Http::withBasicAuth($this->clientId, $this->clientSecret)
                ->asForm()
                ->post("{$this->baseUrl}/v1/oauth2/token", [
                    'grant_type' => 'client_credentials',
                ]);

            if (!$response->successful()) {
                Log::error('PayPalService: Token request failed', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
                throw new \RuntimeException('PayPal authentication failed: ' . $response->body());
            }

            return $response->json('access_token');
        });
    }

    /**
     * Client HTTP authentifié — réutilisé partout.
     */
    private function http(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withToken($this->getAccessToken())
            ->withHeaders(['Content-Type' => 'application/json', 'Prefer' => 'return=representation'])
            ->baseUrl($this->baseUrl);
    }

    // ─── Products ─────────────────────────────────────────────────────────────

    /**
     * Créer un Product PayPal (catalogue produit — un seul pour ELChat).
     */
    public function createProduct(): array
    {
        $response = $this->http()->post('/v1/catalogs/products', [
            'name'        => 'ELChat',
            'description' => 'Automatisation intelligente des conversations avec l\'IA',
            'type'        => 'SERVICE',
            'category'    => 'SOFTWARE',
            'home_url'    => config('app.url'),
        ]);

        $this->assertSuccess($response, 'createProduct');
        return $response->json();
    }

    // ─── Plans ────────────────────────────────────────────────────────────────

    /**
     * Créer un Plan PayPal (abonnement récurrent).
     *
     * @param  string $productId  ID du Product PayPal
     * @param  Plan   $plan       Modèle Plan local
     * @param  string $cycle      'monthly' | 'annual'
     * @return array              Réponse PayPal avec l'ID du plan
     */
    public function createPlan(string $productId, Plan $plan, string $cycle): array
    {
        $isAnnual   = $cycle === 'annual';
        $amountEur  = $isAnnual
            ? number_format($plan->price_annual_eur / 100, 2, '.', '')    // Prix/mois × facturé mensuellement
            : number_format($plan->price_monthly_eur / 100, 2, '.', '');

        // PayPal facture mensuellement dans les deux cas
        // Pour l'annuel on facture 12× le prix annuel/mois = total annuel
        $intervalUnit  = 'MONTH';
        $intervalCount = 1;

        if ($isAnnual) {
            // Facturation annuelle = 1 paiement par an du montant total
            $amountEur     = number_format(($plan->price_annual_eur * 12) / 100, 2, '.', '');
            $intervalUnit  = 'YEAR';
            $intervalCount = 1;
        }

        $currency = strtoupper(config('paypal.currency', 'EUR'));

        $payload = [
            'product_id'          => $productId,
            'name'                => "ELChat {$plan->name} — " . ($isAnnual ? 'Annuel' : 'Mensuel'),
            'description'         => $plan->description,
            'status'              => 'ACTIVE',
            'billing_cycles'      => [
                [
                    'frequency'      => [
                        'interval_unit'  => $intervalUnit,
                        'interval_count' => $intervalCount,
                    ],
                    'tenure_type'    => 'REGULAR',
                    'sequence'       => 1,
                    'total_cycles'   => 0, // 0 = illimité
                    'pricing_scheme' => [
                        'fixed_price' => [
                            'value'         => $amountEur,
                            'currency_code' => $currency,
                        ],
                    ],
                ],
            ],
            'payment_preferences' => [
                'auto_bill_outstanding'     => true,
                'setup_fee'                 => ['value' => '0', 'currency_code' => $currency],
                'setup_fee_failure_action'  => 'CONTINUE',
                'payment_failure_threshold' => 3,
            ],
            'taxes' => [
                'percentage' => '0',
                'inclusive'  => false,
            ],
        ];

        $response = $this->http()->post('/v1/billing/plans', $payload);
        $this->assertSuccess($response, "createPlan:{$plan->slug}:{$cycle}");

        return $response->json();
    }

    // ─── Subscriptions ────────────────────────────────────────────────────────

    /**
     * Créer une Subscription PayPal et retourner l'URL d'approbation.
     */
    public function createSubscription(
        Account $account,
        Plan    $plan,
        string  $billingCycle
    ): array {
        $paypalPlanId = $plan->getPayPalPlanId($billingCycle);

        if (!$paypalPlanId) {
            throw new \RuntimeException(
                "PayPal Plan ID manquant pour '{$plan->slug}' ({$billingCycle}). " .
                "Exécutez: php artisan paypal:setup-plans"
            );
        }

        $user     = $account->owner;
        $currency = strtoupper(config('paypal.currency', 'EUR'));

        $payload = [
            'plan_id'             => $paypalPlanId,
            'subscriber'          => [
                'name'          => [
                    'given_name' => $user->firstname ?? '',
                    'surname'    => $user->lastname  ?? '',
                ],
                'email_address' => $user->email,
            ],
            'application_context' => [
                'brand_name'          => config('paypal.brand_name', 'ELChat'),
                'locale'              => 'fr-FR',
                'shipping_preference' => 'NO_SHIPPING',
                'user_action'         => 'SUBSCRIBE_NOW',
                'payment_method'      => [
                    'payer_selected'  => 'PAYPAL',
                    'payee_preferred' => 'IMMEDIATE_PAYMENT_REQUIRED',
                ],
                'return_url'          => config('paypal.return_url') .
                    "&account_id={$account->id}&plan_id={$plan->id}&cycle={$billingCycle}",
                'cancel_url'          => config('paypal.cancel_url'),
            ],
            'custom_id'           => json_encode([
                'account_id'    => $account->id,
                'plan_id'       => $plan->id,
                'billing_cycle' => $billingCycle,
            ]),
        ];

        $response = $this->http()->post('/v1/billing/subscriptions', $payload);
        $this->assertSuccess($response, 'createSubscription');

        $data        = $response->json();
        $approvalUrl = collect($data['links'] ?? [])
            ->firstWhere('rel', 'approve')['href'] ?? null;

        if (!$approvalUrl) {
            throw new \RuntimeException('PayPal approval URL not found in response.');
        }

        return [
            'subscription_id' => $data['id'],
            'approval_url'    => $approvalUrl,
            'data'            => $data,
        ];
    }

    /**
     * Récupérer une Subscription PayPal par son ID.
     */
    public function getSubscription(string $paypalSubscriptionId): array
    {
        $response = $this->http()->get("/v1/billing/subscriptions/{$paypalSubscriptionId}");
        $this->assertSuccess($response, "getSubscription:{$paypalSubscriptionId}");
        return $response->json();
    }

    /**
     * Annuler une Subscription PayPal.
     */
    public function cancelSubscription(string $paypalSubscriptionId, string $reason = 'Annulé par l\'utilisateur'): bool
    {
        $response = $this->http()->post(
            "/v1/billing/subscriptions/{$paypalSubscriptionId}/cancel",
            ['reason' => $reason]
        );

        // PayPal retourne 204 No Content en cas de succès
        return $response->status() === 204;
    }

    /**
     * Suspendre une Subscription PayPal.
     */
    public function suspendSubscription(string $paypalSubscriptionId, string $reason = 'Suspendu'): bool
    {
        $response = $this->http()->post(
            "/v1/billing/subscriptions/{$paypalSubscriptionId}/suspend",
            ['reason' => $reason]
        );
        return $response->status() === 204;
    }

    /**
     * Réactiver une Subscription PayPal suspendue.
     */
    public function activateSubscription(string $paypalSubscriptionId, string $reason = 'Réactivé'): bool
    {
        $response = $this->http()->post(
            "/v1/billing/subscriptions/{$paypalSubscriptionId}/activate",
            ['reason' => $reason]
        );
        return $response->status() === 204;
    }

    // ─── Webhooks ─────────────────────────────────────────────────────────────

    /**
     * Vérifier la signature d'un webhook PayPal.
     * PayPal envoie des headers spécifiques pour valider l'authenticité.
     */
    public function verifyWebhookSignature(
        string $body,
        array  $headers
    ): bool {
        $webhookId = config("paypal.{$this->mode}.webhook_id");

        if (!$webhookId) {
            Log::warning('PayPalService: webhook_id not configured — skipping verification');
            // En développement sans webhook_id, on laisse passer
            return config('app.env') !== 'production';
        }

        try {
            $response = $this->http()->post('/v1/notifications/verify-webhook-signature', [
                'auth_algo'         => $headers['paypal-auth-algo']         ?? '',
                'cert_url'          => $headers['paypal-cert-url']          ?? '',
                'transmission_id'   => $headers['paypal-transmission-id']   ?? '',
                'transmission_sig'  => $headers['paypal-transmission-sig']  ?? '',
                'transmission_time' => $headers['paypal-transmission-time'] ?? '',
                'webhook_id'        => $webhookId,
                'webhook_event'     => json_decode($body, true),
            ]);

            if (!$response->successful()) {
                Log::warning('PayPalService: Webhook verification API failed', [
                    'status' => $response->status(),
                ]);
                return false;
            }

            return $response->json('verification_status') === 'SUCCESS';

        } catch (\Exception $e) {
            Log::error('PayPalService: Webhook verification exception', ['error' => $e->getMessage()]);
            return false;
        }
    }

    // ─── Utilitaires ─────────────────────────────────────────────────────────

    /**
     * Mapper le statut PayPal vers le statut interne.
     */
    public function mapStatus(string $paypalStatus): string
    {
        return match (strtoupper($paypalStatus)) {
            'ACTIVE'             => 'active',
            'APPROVAL_PENDING'   => 'incomplete',
            'APPROVED'           => 'incomplete',
            'SUSPENDED'          => 'past_due',
            'CANCELLED'          => 'canceled',
            'EXPIRED'            => 'canceled',
            default              => 'incomplete',
        };
    }

    /**
     * Convertir un timestamp PayPal (ISO 8601) en Carbon.
     */
    public function parseDate(?string $date): ?\Carbon\Carbon
    {
        if (!$date) return null;
        try {
            return \Carbon\Carbon::parse($date);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Asserter qu'une réponse HTTP est un succès, sinon logger et lever une exception.
     */
    private function assertSuccess(Response $response, string $context): void
    {
        if (!$response->successful()) {
            $body = $response->json() ?? $response->body();
            Log::error("PayPalService: {$context} failed", [
                'status' => $response->status(),
                'body'   => $body,
            ]);
            $message = $body['message'] ?? $body['error_description'] ?? 'PayPal API error';
            throw new \RuntimeException("PayPal {$context}: {$message} (HTTP {$response->status()})");
        }
    }
}
