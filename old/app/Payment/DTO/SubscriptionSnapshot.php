<?php

namespace App\Payment\DTO;

/**
 * Représentation NEUTRE d'un abonnement chez un provider, quel qu'il soit.
 * PayPal et Stripe retournent des structures différentes — chaque Gateway
 * doit mapper sa réponse native vers ce DTO. Le reste du système (Orchestrator,
 * Controllers) ne connaît QUE cette structure, jamais les objets natifs Stripe/PayPal.
 */
class SubscriptionSnapshot
{
    public function __construct(
        public readonly string  $providerSubscriptionId,
        public readonly ?string $providerCustomerId,
        public readonly string  $status,               // mappé vers nos enum internes
        public readonly int     $totalAmountCents,
        public readonly string  $currency,
        public readonly string  $billingCycle,
        public readonly ?\DateTimeImmutable $currentPeriodStart = null,
        public readonly ?\DateTimeImmutable $currentPeriodEnd   = null,
        public readonly ?\DateTimeImmutable $trialEndsAt        = null,
        public readonly ?string $approvalUrl = null,   // 🆕 URL popup d'approbation PayPal (null si non requise)
        public readonly array   $raw = [],              // payload brut du provider, pour debug/audit
    ) {}

    public function toArray(): array
    {
        return [
            'provider_subscription_id' => $this->providerSubscriptionId,
            'provider_customer_id'     => $this->providerCustomerId,
            'status'                   => $this->status,
            'total_amount_cents'       => $this->totalAmountCents,
            'currency'                 => $this->currency,
            'billing_cycle'            => $this->billingCycle,
            'current_period_start'     => $this->currentPeriodStart?->format(DATE_ATOM),
            'current_period_end'       => $this->currentPeriodEnd?->format(DATE_ATOM),
            'trial_ends_at'            => $this->trialEndsAt?->format(DATE_ATOM),
        ];
    }
}
