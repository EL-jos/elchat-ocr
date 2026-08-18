<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ModuleSeeder extends Seeder
{
    public function run(): void
    {
        $modules = [
            [
                'slug'                  => 'core',
                'name'                  => 'Core',
                'description'           => 'Module obligatoire — moteur IA de base, RAG, widget conversationnel.',
                'marketing_description' => 'Le cerveau IA de votre entreprise.',
                'is_core'               => true,
                'requires_tier'         => false,
                'billing_type'          => 'subscription',
                'included_in_trial'     => true,
                'sort_order'            => 1,
                'is_active'             => true,
            ],
            [
                'slug'                  => 'community',
                'name'                  => 'Community',
                'description'           => 'Connecteurs omnicanal (Facebook, Instagram, WhatsApp, Messenger, Telegram, YouTube, Email).',
                'marketing_description' => "Centralisez toutes vos conversations clients dans une seule IA.",
                'is_core'               => false,
                'requires_tier'         => true,
                'billing_type'          => 'subscription',
                'included_in_trial'     => true,
                'sort_order'            => 2,
                'is_active'             => true,
            ],
            [
                'slug'                  => 'business',
                'name'                  => 'Business Automation',
                'description'           => 'Workflow engine, MCP connectors, automatisation métier (Odoo, HubSpot, Shopify, etc.).',
                'marketing_description' => "Automatisez vos processus métier et laissez l'IA agir dans vos outils.",
                'is_core'               => false,
                'requires_tier'         => true,
                'billing_type'          => 'subscription',
                'included_in_trial'     => true,
                'sort_order'            => 3,
                'is_active'             => true,
            ],
            [
                'slug'                  => 'agentics',
                'name'                  => 'Agentics',
                'description'           => 'Multi-agents IA collaboratifs et spécialisés.',
                'marketing_description' => "Créez une équipe d'employés IA spécialisés qui collaborent entre eux.",
                'is_core'               => false,
                'requires_tier'         => true,
                'billing_type'          => 'subscription',
                'included_in_trial'     => true,
                'sort_order'            => 4,
                'is_active'             => true,
            ],
            [
                'slug'                  => 'agency',
                'name'                  => 'Agency',
                'description'           => 'Multi-tenant, white-label, gestion multi-clients, templates, déploiement.',
                'marketing_description' => 'Déployez ELChat pour vos propres clients, en marque blanche.',
                'is_core'               => false,
                'requires_tier'         => false,
                'billing_type'          => 'contact_sales',
                'included_in_trial'     => false,
                'sort_order'            => 5,
                'is_active'             => true,
            ],
        ];

        foreach ($modules as $data) {
            $existingId = DB::table('modules')->where('slug', $data['slug'])->value('id');

            if ($existingId) {
                DB::table('modules')->where('id', $existingId)->update(array_merge($data, [
                    'updated_at' => now(),
                ]));
            } else {
                DB::table('modules')->insert(array_merge($data, [
                    'id'         => (string) Str::uuid(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
            }
        }

        $this->command->info('✅ Modules créés/mis à jour (' . count($modules) . ').');
    }
}
