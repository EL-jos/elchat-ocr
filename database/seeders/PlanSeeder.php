<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'id'                          => Str::uuid(),
                'name'                        => 'Starter',
                'slug'                        => 'starter',
                'description'                 => 'Pour les petites entreprises qui testent l\'automatisation de leurs conversations avec l\'IA.',
                'stripe_price_monthly'        => null, // À remplir après création dans Stripe
                'stripe_price_annual'         => null,
                'price_monthly_eur'           => 3400, // 29 + 5 = 34€/mois (mensuel)
                'price_annual_eur'            => 2900, // 29€/mois (annuel)
                'max_sites'                   => 1,
                'max_social_networks_per_site'=> 1,
                'max_messages_per_month'      => 50,
                'max_chunks'                  => 10000,
                'max_tokens'                  => 1000000,
                'has_sla'                     => false,
                'has_white_label'             => false,
                'is_enterprise'               => false,
                'is_active'                   => true,
                'sort_order'                  => 1,
                'created_at'                  => now(),
                'updated_at'                  => now(),
            ],
            [
                'id'                          => Str::uuid(),
                'name'                        => 'Business',
                'slug'                        => 'business',
                'description'                 => 'Pour les indépendants et petites équipes qui veulent une automatisation plus sérieuse.',
                'stripe_price_monthly'        => null,
                'stripe_price_annual'         => null,
                'price_monthly_eur'           => 8900, // 79 + 10 = 89€/mois (mensuel)
                'price_annual_eur'            => 7900, // 79€/mois (annuel)
                'max_sites'                   => 1,
                'max_social_networks_per_site'=> 3,
                'max_messages_per_month'      => 150,
                'max_chunks'                  => 55000,
                'max_tokens'                  => 3000000,
                'has_sla'                     => false,
                'has_white_label'             => false,
                'is_enterprise'               => false,
                'is_active'                   => true,
                'sort_order'                  => 2,
                'created_at'                  => now(),
                'updated_at'                  => now(),
            ],
            [
                'id'                          => Str::uuid(),
                'name'                        => 'Pro',
                'slug'                        => 'pro',
                'description'                 => 'Pour les entreprises en croissance qui automatisent plusieurs sites et canaux à grande échelle.',
                'stripe_price_monthly'        => null,
                'stripe_price_annual'         => null,
                'price_monthly_eur'           => 22400, // 199 + 25 = 224€/mois (mensuel)
                'price_annual_eur'            => 19900, // 199€/mois (annuel)
                'max_sites'                   => 3,
                'max_social_networks_per_site'=> 3,
                'max_messages_per_month'      => 300,
                'max_chunks'                  => 100000,
                'max_tokens'                  => 20000000,
                'has_sla'                     => false,
                'has_white_label'             => false,
                'is_enterprise'               => false,
                'is_active'                   => true,
                'sort_order'                  => 3,
                'created_at'                  => now(),
                'updated_at'                  => now(),
            ],
            [
                'id'                          => Str::uuid(),
                'name'                        => 'Enterprise',
                'slug'                        => 'enterprise',
                'description'                 => 'Une offre sur mesure entièrement personnalisable, pensée pour les entreprises et agences.',
                'stripe_price_monthly'        => null,
                'stripe_price_annual'         => null,
                'price_monthly_eur'           => 54900, // 499 + 50 = 549€/mois (mensuel)
                'price_annual_eur'            => 49900, // 499€/mois (annuel)
                'max_sites'                   => 999,   // Illimité pratiquement
                'max_social_networks_per_site'=> 5,
                'max_messages_per_month'      => 900,
                'max_chunks'                  => 200000,
                'max_tokens'                  => 100000000,
                'has_sla'                     => true,
                'has_white_label'             => true,
                'is_enterprise'               => true,  // Redirige vers contact
                'is_active'                   => true,
                'sort_order'                  => 4,
                'created_at'                  => now(),
                'updated_at'                  => now(),
            ],
        ];

        foreach ($plans as $plan) {
            DB::table('plans')->updateOrInsert(
                ['slug' => $plan['slug']],
                $plan
            );
        }

        $this->command->info('✅ Plans créés avec succès.');
        $this->command->warn('⚠️  N\'oubliez pas de remplir les stripe_price_monthly et stripe_price_annual après avoir créé les produits dans Stripe Dashboard.');
    }
}
