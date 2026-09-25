<?php

namespace App\Domain\Sales;

use App\Models\Mcp\McpAgent;
use App\Models\Mcp\McpAgentTemplate;
use App\Models\Mcp\McpWorkflow;
use App\Models\Mcp\McpPermission;
use App\Models\Mcp\McpConnector;
use App\Models\Mcp\McpSiteConnector;
use App\Models\Sales\ProspectingConfig;
use App\Models\Site;
use App\Services\WebsiteGrowthAdvisor\WebsiteGrowthAdvisorConfigurationService;
use App\Services\mcp\WorkflowProvisioningService;
use Illuminate\Support\Facades\DB;
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
    public function __construct(
        private readonly WorkflowProvisioningService $provisioning,
        private readonly WebsiteGrowthAdvisorConfigurationService $growthAdvisorConfigurations,
    ) {}

    public function install(Site $site, McpAgentTemplate $template): McpAgent
    {
        return DB::transaction(function () use ($site, $template) {
            $config = $template->default_config;

            $agent = McpAgent::create([
                'id' => (string) Str::uuid(), 'site_id' => $site->id,
                'template_key' => $template->key, 'agent_type' => $template->key,
                'name' => $template->name, 'objective' => $config['objective'] ?? null,
                'tone' => $config['tone'] ?? 'professional', 'skills' => $config['skills'] ?? [],
                'workflow_ids' => [], 'is_active' => false,
            ]);

            foreach ($template->bootstrap_workflow_slugs ?? [] as $slug) {
                $workflow = McpWorkflow::whereNull('site_id')->where('slug', $slug)->first();
                if ($workflow) {
                    $this->provisioning->install($site, $workflow);
                }
            }

            $normalizedTemplateKey = str_replace('-', '_', strtolower((string) $template->key));

            if ($normalizedTemplateKey === 'sales_hunter') {
                ProspectingConfig::create([
                    'id' => (string) Str::uuid(), 'site_id' => $site->id, 'agent_id' => $agent->id,
                    'icp' => [], 'sources' => ['openstreetmap'], 'objective' => $config['objective'] ?? 'generate_meetings',
                    'limits' => [
                        'max_prospects_per_campaign' => 50, 'max_prospects_per_run' => 50,
                        'max_new_prospects_per_day' => 20, 'max_outbound_actions_per_day' => 20,
                        'max_sources_per_run' => 3, 'max_pages_per_prospect' => 3,
                        'max_requests_per_source' => 20, 'max_concurrent_jobs' => 5,
                    ],
                    'discovery_settings' => ['web_seed_urls' => []], 'minimum_score' => 70,
                    'autonomy_mode' => 'suggestion',
                    'is_active' => false,
                ]);
            }

            if ($normalizedTemplateKey === 'website_growth_advisor') {
                $this->growthAdvisorConfigurations->forAgent($site, $agent);

                // ELChat Platform is an internal, credential-less connector.
                // Make the installed agent immediately usable while preserving
                // an explicit prior revoke by the tenant.
                $platform = McpConnector::where('slug', 'elchat_platform')->first();
                if ($platform) {
                    McpSiteConnector::firstOrCreate(
                        ['site_id' => $site->id, 'mcp_connector_id' => $platform->id],
                        ['status' => 'connected', 'connected_at' => now()],
                    );
                }

                // The background pipeline uses these same read-only MCP
                // permissions. Missing external connectors remain unavailable
                // in the result instead of being guessed or auto-connected.
                foreach ([
                    ['elchat_platform', 'website_growth_snapshot'],
                    ['google_analytics', 'get_traffic_overview'],
                    ['google_analytics', 'get_top_pages'],
                    ['google_analytics', 'get_traffic_sources'],
                    ['google_analytics', 'get_conversions'],
                    ['google_search_console', 'get_search_analytics'],
                    ['google_search_console', 'list_sitemaps'],
                ] as [$connectorSlug, $toolName]) {
                    McpPermission::firstOrCreate(
                        ['site_id' => $site->id, 'connector_slug' => $connectorSlug, 'tool_name' => $toolName],
                        ['mode' => 'auto', 'actor_scope' => 'admin', 'confirm_actor' => 'admin'],
                    );
                }
            }

            return $agent;
        });
    }
}
