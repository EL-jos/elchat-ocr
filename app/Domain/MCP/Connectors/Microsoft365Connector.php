<?php

namespace App\Domain\MCP\Connectors;

use App\Domain\MCP\Connectors\Microsoft365\CalendarModule;
use App\Domain\MCP\Connectors\Microsoft365\ContactsModule;
use App\Domain\MCP\Connectors\Microsoft365\ExcelModule;
use App\Domain\MCP\Connectors\Microsoft365\FilesModule;
use App\Domain\MCP\Connectors\Microsoft365\ListsModule;
use App\Domain\MCP\Connectors\Microsoft365\Microsoft365ModuleInterface;
use App\Domain\MCP\Connectors\Microsoft365\OutlookModule;
use App\Domain\MCP\Connectors\Microsoft365\PowerPointModule;
use App\Domain\MCP\Connectors\Microsoft365\TeamsModule;
use App\Domain\MCP\Connectors\Microsoft365\ToDoModule;
use App\Domain\MCP\Connectors\Microsoft365\OneNoteModule;
use App\Domain\MCP\Connectors\Microsoft365\UnavailableMicrosoft365Module;
use App\Domain\MCP\Connectors\Microsoft365\WordModule;
use App\Domain\Microsoft365\Exceptions\MicrosoftGraphException;
use App\Domain\Microsoft365\Microsoft365OAuthService;
use App\Domain\Microsoft365\MicrosoftGraphClient;
use App\Domain\MCP\Contracts\ToolResult;
use App\Domain\MCP\Contracts\ToolSchema;
use App\Domain\MCP\Contracts\ProvidesSiteScopedTools;
use App\Domain\MCP\Exceptions\AuthExpiredException;
use App\Domain\MCP\Exceptions\ConnectorUnavailableException;
use App\Domain\MCP\Exceptions\ToolNotFoundException;

/**
 * Connecteur Microsoft 365 Cloud/Web.
 *
 * Le connecteur est volontairement un routeur : chaque application Microsoft
 * 365 possède son module, son catalogue d'outils et son exécution. Cela permet
 * d'ajouter ou de faire évoluer Excel, Word, PowerPoint, Outlook ou Teams sans
 * transformer ce point d'entrée en classe monolithique.
 */
final class Microsoft365Connector extends AbstractConnector implements ProvidesSiteScopedTools
{
    /** @var list<Microsoft365ModuleInterface> */
    private array $modules;

    public function __construct(private readonly Microsoft365OAuthService $oauth)
    {
        $this->modules = [
            new FilesModule(),
            new ExcelModule(),
            new WordModule(),
            new PowerPointModule(),
            new OutlookModule(),
            new CalendarModule(),
            new ContactsModule(),
            new ToDoModule(),
            new ListsModule(),
            new OneNoteModule(),
            new TeamsModule(),
            new UnavailableMicrosoft365Module('sway', 'Sway', 'Sway ne fournit pas d’API Microsoft Graph publique pour exposer des outils fiables dans ELChat.'),
            new UnavailableMicrosoft365Module('loop', 'Microsoft Loop', 'Microsoft Loop ne fournit pas d’API Microsoft Graph publique stable pour manipuler ses pages et composants.'),
            new UnavailableMicrosoft365Module('power_automate', 'Power Automate', 'La gestion des flux passe par le service Power Automate Management et une connexion Power Platform séparée du token Microsoft Graph.'),
            new UnavailableMicrosoft365Module('forms', 'Microsoft Forms', 'Microsoft Forms ne fournit pas d’API Microsoft Graph publique ; son intégration officielle passe par le connecteur Forms de Power Automate.'),
        ];
    }

    public function slug(): string
    {
        return 'microsoft_365';
    }

    public function authenticate(array $credentials): array
    {
        $expiresAt = (int) ($credentials['expires_at'] ?? 0);
        if ($expiresAt > now()->timestamp + 60) {
            return $credentials;
        }

        return $this->oauth->refresh($credentials);
    }

    /** @return ToolSchema[] */
    public function listTools(): array
    {
        return collect($this->modules)
            ->flatMap(static fn (Microsoft365ModuleInterface $module): array => $module->listTools())
            ->values()
            ->all();
    }

    /** @return ToolSchema[] */
    public function toolsAvailableFor(array $credentials): array
    {
        return collect($this->modules)
            ->flatMap(static fn (Microsoft365ModuleInterface $module) => $module->toolsAvailableFor($credentials))
            ->values()
            ->all();
    }

    /**
     * Métadonnées destinées au catalogue de capacités, afin que l'interface
     * regroupe les outils par application et non sous un seul bloc générique.
     */
    public function moduleMetadataForTool(string $toolName): ?array
    {
        foreach ($this->modules as $module) {
            if (collect($module->listTools())->contains(fn (ToolSchema $tool): bool => $tool->name === $toolName)) {
                return [
                    'key' => $module->key(),
                    'label' => $module->label(),
                    'icon_url' => $module->iconUrl(),
                ];
            }
        }

        return null;
    }

    /** @return list<array{key:string,label:string,icon_url:?string,supports_tools:bool,availability_message:?string,tool_count:int}> */
    public function modulesMetadata(): array
    {
        return array_map(static fn (Microsoft365ModuleInterface $module): array => [
            'key' => $module->key(), 'label' => $module->label(), 'icon_url' => $module->iconUrl(),
            'supports_tools' => $module->supportsTools(), 'availability_message' => $module->availabilityMessage(),
            'tool_count' => count($module->listTools()),
        ], $this->modules);
    }

    public function callTool(string $toolName, array $params, array $credentials, array $context = []): ToolResult
    {
        if (empty($credentials['access_token'])) {
            throw new AuthExpiredException('Session Microsoft 365 absente, reconnexion requise.');
        }

        // Le contrôle est conservé au moment de l'exécution pour protéger les
        // appels déterministes si les autorisations ont changé après le cache.
        if (!collect($this->toolsAvailableFor($credentials))->contains(fn (ToolSchema $tool): bool => $tool->name === $toolName)) {
            return ToolResult::fail('insufficient_scope', 'Cette opération Microsoft 365 ne fait pas partie des autorisations accordées à cette connexion.');
        }

        $module = $this->moduleForTool($toolName);
        if ($module === null) {
            throw new ToolNotFoundException("Outil '{$toolName}' inconnu pour Microsoft 365.");
        }

        try {
            return $module->callTool($toolName, $params, $credentials, $context, MicrosoftGraphClient::forToken($credentials['access_token']));
        } catch (MicrosoftGraphException $exception) {
            if ($exception->isAuthFailure()) {
                throw new AuthExpiredException('La session Microsoft 365 a expiré, reconnexion requise.');
            }

            if ($exception->isNotFound()) {
                return ToolResult::fail('not_found', 'La ressource Microsoft 365 demandée est introuvable ou inaccessible.');
            }

            if (in_array($exception->status, [403, 429], true)) {
                return ToolResult::fail($exception->status === 403 ? 'forbidden' : 'rate_limited', 'Microsoft 365 a refusé cette opération avec les autorisations actuellement accordées.');
            }

            if ($exception->status >= 500 || $exception->status === 503) {
                throw new ConnectorUnavailableException('Microsoft Graph est momentanément indisponible.');
            }

            return ToolResult::fail('graph_error', 'Microsoft Graph n’a pas pu traiter cette opération.');
        }
    }

    private function moduleForTool(string $toolName): ?Microsoft365ModuleInterface
    {
        foreach ($this->modules as $module) {
            if (collect($module->listTools())->contains(fn (ToolSchema $tool): bool => $tool->name === $toolName)) {
                return $module;
            }
        }

        return null;
    }
}
