<?php

namespace App\Payment\Adapters;

use App\Models\Payment\Coupon;
use App\Models\Payment\Subscription;
use App\Payment\Contracts\CouponAdapterInterface;

/**
 * PayPal n'expose pas d'API de coupon universelle et fiable pour tous les comptes
 * marchands. La réduction est donc appliquée EN INTERNE : CouponService calcule
 * le montant net et c'est ce montant net qui est transmis à PaypalPaymentGateway
 * lors de la résolution du plan agrégé (resolvePlanForAmount).
 *
 * Cet adapter est un point d'extension : si un jour PayPal expose un vrai
 * mécanisme de coupon pour le compte marchand ELChat, l'implémentation
 * pourra basculer ici sans toucher à CouponService ni à l'Orchestrator.
 */
class PaypalCouponAdapter implements CouponAdapterInterface
{
    public function syncCoupon(Coupon $coupon): ?string
    {
        // Pas de synchronisation native PayPal — géré uniquement en interne.
        return null;
    }

    public function applyToProviderSubscription(Subscription $subscription, Coupon $coupon): bool
    {
        // false = signale à CouponService que la réduction doit être appliquée
        // via recalcul de montant côté ELChat (fallback interne), pas nativement.
        return false;
    }
}
