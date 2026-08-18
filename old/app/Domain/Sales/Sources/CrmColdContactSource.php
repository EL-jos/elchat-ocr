<?php

namespace App\Domain\Sales\Sources;

use App\Domain\MCP\Capability\CapabilityResolver;
use App\Domain\Sales\Contracts\ProspectSourceInterface;
use App\Models\Conversation;
use App\Models\Site;
use App\Services\mcp\MCPActionGateService;
use Illuminate\Support\Collection;

/**
 * Source V1 unique : mine les contacts DÉJÀ existants dans le CRM connecté
 * (HubSpot en V1, voir capacité 'crm-search-contacts') correspondant à
 * l'ICP mais inactifs — zéro nouveau connecteur, zéro scraping, uniquement
 * des données déjà légitimement détenues par le tenant (§9, §13).
 */
class CrmColdContactSource implements ProspectSourceInterface
{
    public function __construct(
        private readonly CapabilityResolver $capabilities,
        private readonly MCPActionGateService $gate,
    ) {
    }

    public function key(): string
    {
        return 'crm_cold_contact';
    }

    public function discover(Site $site, Conversation $conversation, array $icp, int $limit): Collection
    {
        $toolName = $this->capabilities->resolveToolName($site, 'crm-search-contacts');
        if (!$toolName) {
            return collect(); // aucun CRM compatible connecté — continue sans cette source, jamais bloquant
        }

        $result = $this->gate->executeToolDirectly($site, $conversation, $toolName, [
            'sector' => $icp['sector'] ?? null,
            'location' => $icp['location'] ?? null,
            'limit' => $limit,
        ]);

        if (!$result->success) {
            return collect();
        }

        return collect($result->data['contacts'] ?? [])->map(fn ($c) => [
            'name' => $c['name'] ?? null, 'company' => $c['company'] ?? null,
            'email' => $c['email'] ?? null, 'phone' => $c['phone'] ?? null,
            'sector' => $c['sector'] ?? null, 'location' => $c['location'] ?? null,
            'website' => $c['website'] ?? null, 'domain' => $this->domainFromWebsite($c['website'] ?? null),
            'crm_ref' => ['connector_slug' => explode('__', $toolName)[0], 'external_id' => $c['id'] ?? null],
        ]);
    }

    private function domainFromWebsite(?string $website): ?string
    {
        if (!$website) return null;
        return parse_url(str_starts_with($website, 'http') ? $website : "https://{$website}", PHP_URL_HOST);
    }
}
