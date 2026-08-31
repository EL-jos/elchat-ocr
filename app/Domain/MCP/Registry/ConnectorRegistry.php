<?php

namespace App\Domain\MCP\Registry;

use App\Domain\MCP\Contracts\MCPConnectorInterface;
use App\Domain\MCP\Contracts\ProvidesSiteScopedTools;
use App\Domain\MCP\Security\CredentialVault;
use App\Domain\MCP\Exceptions\ToolNotFoundException;
use App\Models\Site;
use Illuminate\Support\Collection;

/**
 * Point d'entrée unique vers tous les connecteurs. C'est LA pièce qui rend
 * le système scalable : ajouter un connecteur = l'enregistrer ici (via
 * config/mcp.php), rien d'autre à toucher dans l'orchestrateur.
 */
class ConnectorRegistry
{
    /** @var array<string, MCPConnectorInterface> */
    private array $connectors = [];

    public function register(MCPConnectorInterface $connector): void
    {
        $this->connectors[$connector->slug()] = $connector;
    }

    public function get(string $slug): MCPConnectorInterface
    {
        if (!isset($this->connectors[$slug])) {
            throw new ToolNotFoundException("Connecteur '{$slug}' non enregistré dans le registre.");
        }

        return $this->connectors[$slug];
    }

    public function has(string $slug): bool
    {
        return isset($this->connectors[$slug]);
    }

    public function all(): Collection
    {
        return collect($this->connectors);
    }

    /**
     * Construit la liste des tools disponibles pour CE site précis :
     * uniquement les connecteurs qu'il a activés ET qui sont sains
     * (statut 'connected'). C'est cette liste qui est envoyée au LLM.
     */
    public function toolsAvailableFor(Site $site): array
    {
        $activeConnectors = $site->mcpSiteConnectors()
            ->where('status', 'connected')
            ->with('mcpConnector')
            ->get()
            ->pluck('mcpConnector.slug');

        $availableTools = [];
        foreach ($activeConnectors as $slug) {
            if (!$this->has($slug)) {
                continue; // connecteur activé en base mais pas (encore) implémenté côté code -> ignoré silencieusement
            }
            $connector = $this->get($slug);
            $schemas = $connector instanceof ProvidesSiteScopedTools
                ? $connector->toolsAvailableFor(app(CredentialVault::class)->retrieve($site, $slug) ?? [])
                : $connector->listTools();
            foreach ($schemas as $tool) {
                $availableTools[] = $tool->toOpenAIFormat();
            }
        }

        return $availableTools;
    }
}
