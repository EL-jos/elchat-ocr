<?php

namespace App\Services\payment;

use App\Models\Account;
use App\Models\Payment\Plan;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

class StripeService
{
    private StripeClient $stripe;

    public function __construct()
    {
        $this->stripe = new StripeClient(config('stripe.secret'));
    }

    // ─── Customer ─────────────────────────────────────────────────────────────

    /**
     * Créer ou récupérer un Customer Stripe pour un Account.
     */
    public function getOrCreateCustomer(Account $account): string
    {
        $subscription = $account->subscription;

        // Déjà un customer Stripe
        if ($subscription && $subscription->stripe_customer_id) {
            return $subscription->stripe_customer_id;
        }

        // Créer un nouveau customer
        try {
            $customer = $this->stripe->customers->create([
                'email'    => $account->email ?? $account->owner->email,
                'name'     => $account->name,
                'metadata' => [
                    'account_id' => $account->id,
                    'user_id'    => $account->owner_user_id,
                ],
            ]);

            return $customer->id;

        } catch (ApiErrorException $e) {
            Log::error('StripeService: Failed to create customer', [
                'account_id' => $account->id,
                'error'      => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    // ─── Checkout ─────────────────────────────────────────────────────────────

    /**
     * Créer une session Stripe Checkout.
     */
    public function createCheckoutSession(
        Account $account,
        Plan    $plan,
        string  $billingCycle,
        string  $currency = 'eur'
    ): \Stripe\Checkout\Session {
        $customerId = $this->getOrCreateCustomer($account);
        $priceId    = $plan->getStripePriceId($billingCycle);

        if (!$priceId) {
            throw new \RuntimeException(
                "Stripe Price ID manquant pour le plan '{$plan->slug}' en mode '{$billingCycle}'."
            );
        }

        $params = [
            'customer'              => $customerId,
            'payment_method_types'  => ['card'],
            'mode'                  => 'subscription',
            'line_items'            => [[
                'price'    => $priceId,
                'quantity' => 1,
            ]],
            'success_url'           => config('stripe.success_url'),
            'cancel_url'            => config('stripe.cancel_url'),
            'allow_promotion_codes' => true,
            'billing_address_collection' => 'auto',
            'metadata'              => [
                'account_id'    => $account->id,
                'plan_id'       => $plan->id,
                'plan_slug'     => $plan->slug,
                'billing_cycle' => $billingCycle,
            ],
            'subscription_data'     => [
                'metadata' => [
                    'account_id'    => $account->id,
                    'plan_id'       => $plan->id,
                    'billing_cycle' => $billingCycle,
                ],
            ],
        ];

        // Pré-sélectionner la devise si c'est USD
        if (strtolower($currency) === 'usd') {
            $params['currency'] = 'usd';
        }

        try {
            return $this->stripe->checkout->sessions->create($params);
        } catch (ApiErrorException $e) {
            Log::error('StripeService: Checkout session failed', [
                'account_id' => $account->id,
                'plan'       => $plan->slug,
                'error'      => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    // ─── Customer Portal ──────────────────────────────────────────────────────

    /**
     * Créer une session vers le portail client Stripe (gestion abonnement).
     */
    public function createBillingPortalSession(Account $account): \Stripe\BillingPortal\Session
    {
        $subscription = $account->subscription;

        if (!$subscription || !$subscription->stripe_customer_id) {
            throw new \RuntimeException('Aucun customer Stripe associé à ce compte.');
        }

        return $this->stripe->billingPortal->sessions->create([
            'customer'   => $subscription->stripe_customer_id,
            'return_url' => url('/app/settings/billing'),
        ]);
    }

    // ─── Subscription retrieval ───────────────────────────────────────────────

    /**
     * Récupérer une subscription Stripe par son ID.
     */
    public function retrieveSubscription(string $stripeSubscriptionId): \Stripe\Subscription
    {
        return $this->stripe->subscriptions->retrieve($stripeSubscriptionId, [
            'expand' => ['latest_invoice', 'customer'],
        ]);
    }

    /**
     * Récupérer une session Checkout par son ID.
     */
    public function retrieveCheckoutSession(string $sessionId): \Stripe\Checkout\Session
    {
        return $this->stripe->checkout->sessions->retrieve($sessionId, [
            'expand' => ['subscription', 'customer'],
        ]);
    }

    // ─── Webhook ──────────────────────────────────────────────────────────────

    /**
     * Valider et construire l'événement Stripe depuis le webhook.
     */
    public function constructWebhookEvent(string $payload, string $signature): \Stripe\Event
    {
        return \Stripe\Webhook::constructEvent(
            $payload,
            $signature,
            config('stripe.webhook_secret')
        );
    }
}
