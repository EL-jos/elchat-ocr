<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ModuleTierSeeder extends Seeder
{
    /**
     * Un tier "default" pour Core (tier unique implicite, requires_tier=false).
     * Basic/Pro pour Community, Business, Agentics.
     * Aucun tier pour Agency (billing_type=contact_sales, jamais facturé automatiquement).
     */
    public function run(): void
    {
        $tiersByModuleSlug = [
            'core'      => [
                ['slug' => 'default', 'name' => 'Standard', 'sort_order' => 1],
            ],
            'community' => [
                ['slug' => 'basic', 'name' => 'Basic', 'sort_order' => 1],
                ['slug' => 'pro',   'name' => 'Pro',   'sort_order' => 2],
            ],
            'business'  => [
                ['slug' => 'basic', 'name' => 'Basic', 'sort_order' => 1],
                ['slug' => 'pro',   'name' => 'Pro',   'sort_order' => 2],
            ],
            'agentics'  => [
                ['slug' => 'basic', 'name' => 'Basic', 'sort_order' => 1],
                ['slug' => 'pro',   'name' => 'Pro',   'sort_order' => 2],
            ],
            // 'agency' volontairement absent — pas de tier, pas de prix
        ];

        foreach ($tiersByModuleSlug as $moduleSlug => $tiers) {
            $moduleId = DB::table('modules')->where('slug', $moduleSlug)->value('id');

            if (!$moduleId) {
                $this->command->warn("⚠️  Module '{$moduleSlug}' introuvable — exécutez ModuleSeeder d'abord.");
                continue;
            }

            foreach ($tiers as $tier) {
                $existingId = DB::table('module_tiers')
                    ->where('module_id', $moduleId)
                    ->where('slug', $tier['slug'])
                    ->value('id');

                $data = [
                    'module_id'  => $moduleId,
                    'slug'       => $tier['slug'],
                    'name'       => $tier['name'],
                    'sort_order' => $tier['sort_order'],
                    'is_active'  => true,
                    'updated_at' => now(),
                ];

                if ($existingId) {
                    DB::table('module_tiers')->where('id', $existingId)->update($data);
                } else {
                    DB::table('module_tiers')->insert(array_merge($data, [
                        'id'         => (string) Str::uuid(),
                        'created_at' => now(),
                    ]));
                }
            }
        }

        $this->command->info('✅ Tiers de modules créés/mis à jour.');
    }
}
