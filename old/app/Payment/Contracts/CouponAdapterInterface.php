<?php

namespace App\Payment\Contracts;


use App\Models\Payment\Coupon;
use App\Models\Payment\Subscription;

/**
 * Point d'extension pour synchroniser un coupon avec le système natif d'un provider,
 * quand celui-ci en propose un (ex: Stripe Coupons/PromotionCodes).
 *
 * Pour PayPal (support natif limité selon compte marchand), l'implémentation
 * applique la réduction "en interne" — le montant net est déjà calculé par
 * CouponService avant transmission au PaymentGatewayInterface.
 */
interface CouponAdapterInterface
{
    /**
     * Synchronise le coupon côté provider si une API native existe.
     * Retourne une référence provider (nullable si géré uniquement en interne).
     */
    public function syncCoupon(Coupon $coupon): ?string;

    /**
     * Applique la réduction à un abonnement provider, si le provider a un mécanisme natif.
     * Retourne true si géré nativement, false si la réduction doit être appliquée
     * uniquement via le recalcul de montant côté ELChat (fallback interne).
     */
    public function applyToProviderSubscription(Subscription $subscription, Coupon $coupon): bool;
}
