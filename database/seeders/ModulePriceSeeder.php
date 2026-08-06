<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ModulePriceSeeder extends Seeder
{
    /**
     * SOURCE DE VÉRITÉ UNIQUE DES PRIX.
     *
     * Pour changer un prix en production :
     *   1. Modifier la valeur ci-dessous (en EUROS, converti en centimes automatiquement)
     *   2. php artisan db:seed --class=ModulePriceSeeder
     *
     * Les abonnements déjà actifs gardent leur prix "snapshotté" dans subscription_items.unit_price_eur
     * — un changement ici n'affecte QUE les nouvelles activations, jamais rétroactivement.
     *
     * Prix annuels = tarif MENSUEL équivalent facturé annuellement (cohérence avec l'ancien système).
     * Exemple : Core 29€/mois en mensuel, 24€/mois si payé annuellement (soit 288€/an).
     */
    public function run(): void
    {
        $prices = [
            // module_slug => [tier_slug => ['monthly' => €, 'yearly' => €/mois équivalent]]
            'core' => [
                'default' => ['monthly' => 29, 'yearly' => 29],
            ],
            'community' => [
                'basic' => ['monthly' => 19, 'yearly' => 19],
                'pro'   => ['monthly' => 49, 'yearly' => 49],
            ],
            'business' => [
                'basic' => ['monthly' => 39, 'yearly' => 39],
                'pro'   => ['monthly' => 99, 'yearly' => 99],
            ],
            'agentics' => [
                'basic' => ['monthly' => 59, 'yearly' => 59],
                'pro'   => ['monthly' => 149, 'yearly' => 149],
            ],
            // 'agency' volontairement absent — sur devis, jamais de module_prices
        ];

        $created = 0;
        $updated = 0;

        foreach ($prices as $moduleSlug => $tiers) {
            $moduleId = DB::table('modules')->where('slug', $moduleSlug)->value('id');

            if (!$moduleId) {
                $this->command->warn("⚠️  Module '{$moduleSlug}' introuvable — exécutez ModuleSeeder d'abord.");
                continue;
            }

            foreach ($tiers as $tierSlug => $cycles) {
                $tierId = DB::table('module_tiers')
                    ->where('module_id', $moduleId)
                    ->where('slug', $tierSlug)
                    ->value('id');

                if (!$tierId) {
                    $this->command->warn("⚠️  Tier '{$tierSlug}' du module '{$moduleSlug}' introuvable — exécutez ModuleTierSeeder d'abord.");
                    continue;
                }

                foreach ($cycles as $cycle => $priceEuros) {
                    $priceCents = (int) round($priceEuros * 100);

                    $existingId = DB::table('module_prices')
                        ->where('module_tier_id', $tierId)
                        ->where('billing_cycle', $cycle)
                        ->value('id');

                    $data = [
                        'module_tier_id' => $tierId,
                        'billing_cycle'  => $cycle,
                        'price_eur'      => $priceCents,
                        'is_active'      => true,
                        'updated_at'     => now(),
                    ];

                    if ($existingId) {
                        DB::table('module_prices')->where('id', $existingId)->update($data);
                        $updated++;
                    } else {
                        DB::table('module_prices')->insert(array_merge($data, [
                            'id'         => (string) Str::uuid(),
                            'created_at' => now(),
                        ]));
                        $created++;
                    }
                }
            }
        }

        $this->command->info("✅ Prix des modules synchronisés : {$created} créés, {$updated} mis à jour.");
    }
}
