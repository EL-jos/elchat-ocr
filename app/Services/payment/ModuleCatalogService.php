<?php

namespace App\Services\payment;

use App\Models\Account;
use App\Models\Payment\Module;
use App\Models\Payment\Subscription;

class ModuleCatalogService
{
    /**
     * Catalogue complet formaté pour l'écran "App Store" Angular,
     * avec le statut de chaque module pour ce compte précis (actif, installable...).
     */
    public function catalogForAccount(Account $account, string $billingCycle = 'monthly'): array
    {
        $subscription = $account->subscription;
        $modules      = Module::active()->with('tiers.prices')->get();

        return [
            'trial_active' => $subscription?->isTrialing() ?? false,   // 🆕
            'modules'       => $modules->map(function (Module $module) use ($subscription, $billingCycle) {
                $item = $subscription?->itemForModule($module->slug);

                return [
                    'id'                     => $module->id,
                    'slug'                   => $module->slug,
                    'name'                   => $module->name,
                    'marketing_description'  => $module->marketing_description,
                    'is_core'                => $module->is_core,
                    'billing_type'           => $module->billing_type,
                    'requires_tier'          => $module->requires_tier,
                    'included_in_trial'      => $module->included_in_trial,   // 🆕
                    'status'                 => $this->resolveModuleStatus($module, $item),
                    'current_tier_slug'      => $item?->moduleTier?->slug,
                    'tiers'                  => $module->tiers->map(fn ($tier) => [
                        'slug'  => $tier->slug,
                        'name'  => $tier->name,
                        'price' => $tier->priceFor($billingCycle)?->price_eur,
                    ]),
                ];
            })->all(),
        ];
    }

    private function resolveModuleStatus(Module $module, $item): string
    {
        if ($module->billing_type === 'contact_sales') {
            return 'contact_sales';
        }

        if (!$item) {
            return 'installable';
        }

        return match ($item->status) {
            'trialing'              => 'trialing',
            'active'                => 'active',
            'pending_cancellation'  => 'pending_cancellation', // reste accessible jusqu'à access_ends_at
            default                 => 'installable',
        };
    }

    /**
     * Résumé "Core 29€ + Community 19€ = 48€/mois" pour l'écran de confirmation.
     */
    public function subscriptionSummary(Subscription $subscription): array
    {
        $items = $subscription->activeItems()->with('module')->get();

        return [
            'lines' => $items->map(fn ($item) => [
                'module_name' => $item->module->name,
                'tier_slug'   => $item->moduleTier?->slug,
                'price_cents' => $item->unit_price_eur,
            ])->all(),
            'total_cents'   => $subscription->currentTotalCents(),
            'billing_cycle' => $subscription->billing_cycle,
        ];
    }
}
