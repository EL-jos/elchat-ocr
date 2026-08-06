<?php

namespace App\Services\payment;


use App\Models\Payment\Coupon;
use App\Models\Payment\ModuleTier;
use App\Payment\DTO\ModuleLineItem;

class PricingCalculator
{
    /**
     * Total brut (centimes) pour une liste de ModuleLineItem.
     *
     * @param ModuleLineItem[] $lineItems
     */
    public function totalCents(array $lineItems): int
    {
        return array_sum(array_map(fn (ModuleLineItem $i) => $i->unitPriceCents, $lineItems));
    }

    /**
     * Total net après application d'un coupon éventuel (centimes).
     *
     * @param ModuleLineItem[] $lineItems
     */
    public function netTotalCents(array $lineItems, ?Coupon $coupon = null): int
    {
        $total = $this->totalCents($lineItems);

        if (!$coupon || !$coupon->isValid()) {
            return $total;
        }

        // Si le coupon est restreint à certains modules, on ne réduit que la portion concernée
        $eligibleAmount = array_sum(array_map(
            fn (ModuleLineItem $i) => $coupon->appliesToModule($i->module->slug) ? $i->unitPriceCents : 0,
            $lineItems
        ));

        $discount = $coupon->discountFor($eligibleAmount);

        return max(0, $total - $discount);
    }

    public function resolvePriceForTier(ModuleTier $tier, string $billingCycle): int
    {
        $price = $tier->priceFor($billingCycle);

        if (!$price) {
            throw new \RuntimeException(
                "Aucun prix actif pour le tier '{$tier->slug}' du module '{$tier->module->slug}' en cycle '{$billingCycle}'."
            );
        }

        return $price->price_eur;
    }
}
