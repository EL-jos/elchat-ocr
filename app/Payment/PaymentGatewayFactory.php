<?php

namespace App\Payment;

use App\Models\Payment\Subscription;
use App\Payment\Contracts\PaymentGatewayInterface;
use App\Payment\Gateways\PaypalPaymentGateway;

/**
 * Résout l'implémentation PaymentGatewayInterface active pour un abonnement donné.
 * C'est le SEUL endroit qui sait instancier un Gateway concret — tout le reste
 * du système (Orchestrator, Controllers) passe par cette factory.
 */
class PaymentGatewayFactory
{
    public function make(string $provider): PaymentGatewayInterface
    {
        return match ($provider) {
            'paypal' => app(PaypalPaymentGateway::class),
            'stripe' => throw new \RuntimeException('StripePaymentGateway non implémenté pour le moment.'),
            default  => throw new \InvalidArgumentException("Provider de paiement inconnu: {$provider}"),
        };
    }

    public function forSubscription(Subscription $subscription): PaymentGatewayInterface
    {
        return $this->make($subscription->payment_provider);
    }

    /**
     * Provider par défaut pour toute nouvelle souscription (config-driven).
     */
    public function default(): PaymentGatewayInterface
    {
        return $this->make(config('subscription.default_provider', 'paypal'));
    }
}
