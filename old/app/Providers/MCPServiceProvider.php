<?php

namespace App\Providers;

use App\Domain\MCP\Orchestration\OpenRouterToolClient;
use App\Domain\MCP\Registry\ConnectorRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * À ENREGISTRER dans bootstrap/providers.php (Laravel 11/12) en ajoutant :
 *   App\Providers\MCPServiceProvider::class,
 * dans le tableau retourné par ce fichier.
 */
class MCPServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/mcp.php', 'mcp');

        $this->app->singleton(ConnectorRegistry::class, function ($app) {
            $registry = new ConnectorRegistry();

            foreach (config('mcp.connectors', []) as $slug => $definition) {
                $connector = $app->make($definition['class']);
                $registry->register($connector);
            }

            return $registry;
        });

        // Même fournisseur (OpenRouter) et même clé d'API que
        // App\Services\ia\ChatService::callLLM — pas de second fournisseur
        // LLM introduit par MCP.
        $this->app->singleton(OpenRouterToolClient::class, function ($app) {
            return new OpenRouterToolClient(
                apiKey: config('mcp.llm.api_key'),
                model: config('mcp.llm.model'),
            );
        });

        // Les autres services (PermissionEngine, CredentialVault, AuditLogger,
        // MCPActionGateService, RAGToolAdapter) n'ont pas besoin de binding
        // explicite : aucune dépendance non résolvable par auto-wiring.
    }

    public function boot(): void
    {
        //
    }
}
