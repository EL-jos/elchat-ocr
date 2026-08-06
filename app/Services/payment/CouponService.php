<?php

namespace App\Services\payment;


use App\Models\Payment\Coupon;
use App\Models\Payment\Subscription;
use App\Models\Payment\SubscriptionCoupon;
use App\Payment\Adapters\PaypalCouponAdapter;
use App\Payment\Contracts\CouponAdapterInterface;
use Illuminate\Support\Facades\DB;

class CouponService
{
    public function resolveAdapter(string $provider): CouponAdapterInterface
    {
        return match ($provider) {
            'paypal' => app(PaypalCouponAdapter::class),
            'stripe' => throw new \RuntimeException('StripeCouponAdapter non implémenté pour le moment.'),
            default  => throw new \InvalidArgumentException("Provider inconnu: {$provider}"),
        };
    }

    public function findValidCoupon(string $code): ?Coupon
    {
        $coupon = Coupon::where('code', $code)->first();

        if (!$coupon || !$coupon->isValid()) {
            return null;
        }

        return $coupon;
    }

    /**
     * Applique un coupon à un abonnement — enregistre la liaison et incrémente
     * le compteur d'utilisation. La logique de calcul du montant net est déléguée
     * à PricingCalculator, appelé par l'Orchestrator.
     */
    public function attachToSubscription(Subscription $subscription, Coupon $coupon): SubscriptionCoupon
    {
        return DB::transaction(function () use ($subscription, $coupon) {
            $expiresAt = null;

            if ($coupon->duration_type === 'repeating' && $coupon->duration_months) {
                $expiresAt = now()->addMonths($coupon->duration_months);
            }

            $link = SubscriptionCoupon::create([
                'subscription_id' => $subscription->id,
                'coupon_id'       => $coupon->id,
                'applied_at'      => now(),
                'expires_at'      => $coupon->duration_type === 'once' ? now() : $expiresAt,
            ]);

            $coupon->increment('redeemed_count');

            // Point d'extension : synchronisation native si le provider le permet
            $adapter        = $this->resolveAdapter($subscription->payment_provider);
            $providerRef    = $adapter->syncCoupon($coupon);

            if ($providerRef) {
                $link->update(['provider_coupon_ref' => $providerRef]);
            }

            return $link;
        });
    }

    /**
     * Coupon actif à date pour un abonnement (le plus récent valide).
     */
    public function activeCouponFor(Subscription $subscription): ?Coupon
    {
        $link = $subscription->coupons()
            ->with('coupon')
            ->latest('applied_at')
            ->get()
            ->first(fn (SubscriptionCoupon $sc) => $sc->isStillValid());

        return $link?->coupon;
    }
}
