<?php

namespace App\Domain\MCP\Connectors;

use App\Domain\MCP\Connectors\Odoo\{
    AccountingModule, AppointmentModule, CRMModule, HelpdeskModule, InventoryModule,
    KnowledgeModule, ManufacturingModule, OdooClient, ProjectModule, SalesModule
};
use App\Domain\MCP\Contracts\{ProvidesSiteScopedTools, ToolResult};
use App\Domain\MCP\Exceptions\{AuthExpiredException, ConnectorUnavailableException, ToolNotFoundException};
use Illuminate\Support\Facades\{Cache, Log};

/**
 * Dispatcher pur : ne connaît aucun modèle Odoo lui-même, délègue tout aux
 * modules. Ajouter un 10e module = une classe implémentant
 * OdooModuleInterface + une ligne dans $modules, rien d'autre.
 */
class OdooConnector extends AbstractConnector implements ProvidesSiteScopedTools
{
    /** @var \App\Domain\MCP\Connectors\Odoo\OdooModuleInterface[] */
    private array $modules;

    public function __construct()
    {
        $this->modules = [
            new CRMModule(),
            new SalesModule(),
            new InventoryModule(),
            new AccountingModule(),
            new HelpdeskModule(),
            new AppointmentModule(),
            new KnowledgeModule(),
            new ProjectModule(),
            new ManufacturingModule(),
        ];
    }

    public function slug(): string { return 'odoo'; }

    public function authenticate(array $credentials): array
    {
        if (empty($credentials['url']) || empty($credentials['db']) || empty($credentials['username']) || empty($credentials['api_key'])) {
            throw new AuthExpiredException('Configuration Odoo incomplète (URL, base de données, utilisateur, clé API).');
        }
        return $credentials;
    }

    /** Utilisée pour le seeding par défaut avant toute connexion réelle — expose tout, filtré ensuite via toolsAvailableFor(). */
    public function listTools(): array
    {
        return collect($this->modules)->flatMap(fn ($m) => $m->listTools())->all();
    }

    /** 🆕 Le vrai filtrage, utilisé par la boucle conversationnelle (voir ProvidesSiteScopedTools). */
    public function toolsAvailableFor(array $credentials): array
    {
        $installed = $this->installedModules($credentials);

        return collect($this->modules)
            ->filter(fn ($m) => in_array($m->technicalModuleName(), $installed, true))
            ->flatMap(fn ($m) => $m->listTools())
            ->all();
    }

    private function installedModules(array $credentials): array
    {
        $cacheKey = 'mcp:odoo:installed_modules:' . md5(($credentials['url'] ?? '') . ($credentials['db'] ?? ''));

        return Cache::remember($cacheKey, now()->addHours(6), function () use ($credentials) {
            try {
                $client = new OdooClient($credentials);
                $technicalNames = collect($this->modules)->map(fn ($m) => $m->technicalModuleName())->all();
                $rows = $client->searchRead('ir.module.module', [['name', 'in', $technicalNames], ['state', '=', 'installed']], ['name']);
                return collect($rows)->pluck('name')->all();
            } catch (\Throwable $e) {
                Log::warning('MCP Odoo: détection des modules installés échouée', ['error' => $e->getMessage()]);
                return []; // fail-closed
            }
        });
    }

    public function callTool(string $toolName, array $params, array $credentials, array $context = []): ToolResult
    {
        if ($this->isCircuitOpen()) {
            throw new ConnectorUnavailableException("Circuit ouvert pour odoo : trop d'échecs récents.");
        }

        $client = new OdooClient($credentials);

        foreach ($this->modules as $module) {
            if (!collect($module->listTools())->pluck('name')->contains($toolName)) continue;

            try {
                $result = $module->callTool($toolName, $params, $credentials, $context, $client);
                $this->recordSuccess();
                return $result;
            } catch (ConnectorUnavailableException $e) {
                $this->recordFailure();
                throw $e;
            }
        }

        throw new ToolNotFoundException("Outil '{$toolName}' inconnu pour odoo.");
    }
}
