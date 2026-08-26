<?php

namespace App\Domain\Sales\Sources;

use App\Domain\MCP\Capability\CapabilityResolver;
use App\Domain\Sales\Contracts\ProspectSourceInterface;
use App\Models\Conversation;
use App\Models\Site;
use App\Services\mcp\MCPActionGateService;
use Illuminate\Support\Collection;

/**
 * Source de compatibilité : mine les contacts DÉJÀ existants dans le CRM connecté
 * (HubSpot, voir capacité 'crm-search-contacts') correspondant à
 * l'ICP mais inactifs — zéro nouveau connecteur, zéro scraping, uniquement
 * des données déjà légitimement détenues par le tenant (§9, §13).
 */
class CrmColdContactSource implements ProspectSourceInterface
{
    public function __construct(
        private readonly CapabilityResolver $capabilities,
        private readonly MCPActionGateService $gate,
    ) {}

    public function key(): string
    {
        return 'crm_cold_contact';
    }

    public function discover(Site $site, Conversation $conversation, array $icp, int $limit, array $options = []): Collection
    {
        $toolName = $this->capabilities->resolveToolName($site, 'crm-search-contacts');
        if (! $toolName) {
            return collect(); // aucun CRM compatible connecté — continue sans cette source, jamais bloquant
        }

        // Le contrat de la capacité de recherche CRM est celui de HubSpot : un seul champ
        // `query` (recherche plein texte), pas des critères `sector`/`location`
        // séparés. Une ICP vide ne doit pas déclencher une recherche globale.
        $query = $this->buildQuery($icp);
        if ($query === null) {
            return collect();
        }

        $result = $this->gate->executeToolDirectly($site, $conversation, $toolName, [
            'query' => $query,
        ], systemActor: true);

        if (! $result->success) {
            return collect();
        }

        return collect($result->data['contacts'] ?? [])
            ->map(fn (array $contact) => $this->mapContact($contact, $toolName));
    }

    /**
     * Transforme l'ICP en recherche plein texte pour le contrat HubSpot.
     * Les valeurs imbriquées de custom_criteria sont aplaties sans inventer
     * de propriété CRM que le connecteur ne sait pas rechercher.
     */
    private function buildQuery(array $icp): ?string
    {
        $parts = collect([
            $icp['sector'] ?? null,
            $icp['company_type'] ?? null,
            $icp['location'] ?? null,
            $icp['company_size'] ?? null,
            $icp['custom_criteria'] ?? null,
        ])
            ->flatten()
            ->map(fn ($value) => $this->cleanString($value))
            ->filter()
            ->unique()
            ->values();

        return $parts->isEmpty() ? null : $parts->implode(' ');
    }

    /**
     * HubSpot renvoie `contact_id`, `firstname` et `lastname`, alors que le
     * moteur de découverte manipule le format canonique des prospects.
     */
    private function mapContact(array $contact, string $toolName): array
    {
        $website = $this->cleanString($contact['website'] ?? null);

        return [
            'name' => $this->contactName($contact),
            'company' => $this->cleanString($contact['company'] ?? null),
            'email' => $this->cleanString($contact['email'] ?? null),
            'phone' => $this->cleanString($contact['phone'] ?? null),
            'sector' => $this->cleanString($contact['sector'] ?? null),
            'location' => $this->cleanString($contact['location'] ?? null),
            'website' => $website,
            'domain' => $this->domainFromWebsite($website),
            'crm_ref' => [
                'connector_slug' => explode('__', $toolName)[0],
                'external_id' => $contact['contact_id'] ?? $contact['id'] ?? null,
            ],
        ];
    }

    private function contactName(array $contact): ?string
    {
        $explicitName = $this->cleanString($contact['name'] ?? null);
        if ($explicitName !== null) {
            return $explicitName;
        }

        return collect([
            $this->cleanString($contact['firstname'] ?? null),
            $this->cleanString($contact['lastname'] ?? null),
        ])->filter()->implode(' ') ?: null;
    }

    private function cleanString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function domainFromWebsite(?string $website): ?string
    {
        if (! $website) {
            return null;
        }

        return parse_url(str_starts_with($website, 'http') ? $website : "https://{$website}", PHP_URL_HOST);
    }
}
