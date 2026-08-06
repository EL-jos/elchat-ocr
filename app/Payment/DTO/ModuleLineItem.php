<?php

namespace App\Payment\DTO;



use App\Models\Payment\Module;
use App\Models\Payment\ModuleTier;
use App\Models\Payment\SubscriptionItem;

/**
 * Représente une ligne de module à facturer, transmise au Gateway.
 * Neutre vis-à-vis du provider — construit par l'Orchestrator à partir
 * des subscription_items actifs.
 */
class ModuleLineItem
{
    public function __construct(
        public readonly Module     $module,
        public readonly ?ModuleTier $tier,
        public readonly int         $unitPriceCents,
        public readonly string      $billingCycle,
    ) {}

    public static function fromSubscriptionItem(SubscriptionItem $item): self
    {
        return new self(
            module: $item->module,
            tier: $item->moduleTier,
            unitPriceCents: $item->unit_price_eur,
            billingCycle: $item->billing_cycle,
        );
    }
}
