<?php

namespace Database\Seeders;

use App\Models\Mcp\McpCapabilityActionPlaybook;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Capacité manquante pour CrmColdContactSource : recherche de contacts par
 * critères (pas un lookup par identifiant comme crm_find_contact).
 * ⚠️ hubspot__search_contacts confirmé dans l'inventaire ; aucun équivalent
 * Odoo confirmé — capacité mono-fournisseur pour l'instant, honnêtement
 * documentée plutôt que de deviner un nom d'outil Odoo.
 *
 * Lancer: php artisan db:seed --class=McpSalesHunterSupportSeeder
 */
class McpSalesHunterSupportSeeder extends Seeder
{
    public function run(): void
    {
        McpCapabilityActionPlaybook::updateOrCreate(
            ['key' => 'crm-search-contacts'],
            [
                'id' => (string) Str::uuid(), 'is_active' => true,
                'label' => 'Rechercher des contacts CRM par critères',
                'value_pitch' => "Retrouvez des contacts correspondant à des critères précis (secteur, statut...), plutôt qu'un contact déjà identifié.",
                'applicable_type_sites' => [],
                'tool_names' => ['hubspot__search_contacts'], // ⚠️ pas d'équivalent Odoo confirmé
                'priority_tier' => 2,
            ],
        );
    }
}
