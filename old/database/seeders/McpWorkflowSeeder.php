<?php

namespace Database\Seeders;

use App\Models\Mcp\McpWorkflow;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class McpWorkflowSeeder extends Seeder
{
    public function run(): void
    {
        $this->upsert('demo_request', 'Demande de rendez-vous / démonstration',
            "Le visiteur souhaite planifier un rendez-vous, une démonstration, un appel ou une visite.",
            [
                ['capability' => 'scheduling.check_availability', 'label' => 'Vérifier les disponibilités', 'optional' => true],
                ['capability' => 'scheduling.create_event', 'label' => 'Créer le rendez-vous', 'optional' => false],
                ['capability' => 'crm.create_or_update_contact', 'label' => 'Créer/mettre à jour le contact', 'optional' => true],
                ['capability' => 'crm.create_opportunity', 'label' => 'Créer une opportunité liée', 'optional' => true],
            ]
        );

        $this->upsert('support_issue', 'Signalement de problème',
            "Le visiteur signale un problème, un dysfonctionnement, une réclamation.",
            [
                ['capability' => 'support.create_ticket', 'label' => 'Ouvrir un ticket de support', 'optional' => false],
                ['capability' => 'crm.log_activity', 'label' => "Journaliser l'échange dans le CRM", 'optional' => true],
            ]
        );

        $this->upsert('purchase_intent', "Intention d'achat",
            "Le visiteur exprime un intérêt d'achat clair pour un produit ou une offre.",
            [
                ['capability' => 'crm.create_or_update_contact', 'label' => 'Créer/mettre à jour le contact', 'optional' => true],
                ['capability' => 'crm.create_opportunity', 'label' => "Créer l'opportunité commerciale", 'optional' => false],
                ['capability' => 'crm.create_task', 'label' => 'Créer une tâche de suivi commercial', 'optional' => true],
            ]
        );
    }

    private function upsert(string $slug, string $name, string $trigger, array $steps): void
    {
        McpWorkflow::updateOrCreate(
            ['slug' => $slug, 'site_id' => null],
            ['id' => (string) Str::uuid(), 'name' => $name, 'trigger_description' => $trigger, 'steps' => $steps, 'is_active' => true]
        );
    }
}
