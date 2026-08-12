<?php

namespace App\Domain\Sales;

use App\Services\mcp\WorkflowProvisioningService;
use App\Models\Mcp\{McpAgent, McpAgentTemplate, McpWorkflow};
use App\Models\Sales\ProspectingConfig;
use App\Models\Site;
use Illuminate\Support\Str;

/**
 * Installe un template de la Banque d'Agents pour un site : crée l'agent
 * réel (mcp_agents) + provisionne les workflows recommandés (réutilise
 * WorkflowProvisioningService, rien de nouveau) + crée la configuration
 * de prospection par défaut si le template est de type 'sales_hunter'.
 *
 * ⚠️ AUCUNE vérification d'abonnement pour l'instant (gratuit pendant les
 * tests sur données réelles, sur consigne explicite) — `required_module_slug`
 * du template est ignoré ici, à réactiver plus tard.
 */
class AgentTemplateInstaller
{
    public function __construct(private readonly WorkflowProvisioningService $provisioning)
    {
    }

    public function install(Site $site, McpAgentTemplate $template): McpAgent
    {
        $config = $template->default_config;

        $agent = McpAgent::create([
            'id' => (string) Str::uuid(), 'site_id' => $site->id,
            'template_key' => $template->key, 'agent_type' => $template->key,
            'name' => $template->name, 'objective' => $config['objective'] ?? null,
            'tone' => $config['tone'] ?? 'professional', 'skills' => $config['skills'] ?? [],
            'workflow_ids' => [], 'is_active' => false, // reste inactif tant que la config §6 n'est pas complétée
        ]);

        foreach ($template->bootstrap_workflow_slugs ?? [] as $slug) {
            $workflow = McpWorkflow::whereNull('site_id')->where('slug', $slug)->first();
            if ($workflow) {
                $this->provisioning->install($site, $workflow);
            }
        }

        if ($template->key === 'sales_hunter') {
            ProspectingConfig::create([
                'id' => (string) Str::uuid(), 'site_id' => $site->id, 'agent_id' => $agent->id,
                'icp' => [], 'objective' => $config['objective'] ?? 'generate_meetings',
                'limits' => ['max_prospects_per_campaign' => 50, 'max_new_prospects_per_day' => 20, 'max_outbound_actions_per_day' => 20],
                'autonomy_mode' => 'suggestion', // posture la plus prudente par défaut — l'admin choisit ensuite
                'is_active' => false,
            ]);
        }

        return $agent;
    }
}
