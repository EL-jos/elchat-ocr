<?php

namespace App\Domain\MCP\Connectors;

use App\Domain\MCP\Contracts\MCPConnectorInterface;
use App\Domain\MCP\Contracts\ToolResult;
use App\Domain\MCP\Contracts\ToolSchema;
use App\Domain\MCP\Exceptions\ToolNotFoundException;
use App\Models\DevPilot\DevPilotProject;
use App\Models\Site;
use App\Services\DevPilot\DevPilotConnectionService;
use App\Services\DevPilot\DevPilotDatabaseService;
use App\Services\DevPilot\DevPilotInspectionPolicy;
use App\Services\DevPilot\DevPilotSecretRedactor;
use App\Services\DevPilot\RemoteFileSystemFactory;
use RuntimeException;

final class DevPilotConnector implements MCPConnectorInterface
{
    public function __construct(
        private readonly RemoteFileSystemFactory $fileSystems,
        private readonly DevPilotConnectionService $connections,
        private readonly DevPilotDatabaseService $databases,
        private readonly DevPilotSecretRedactor $redactor,
    ) {}

    public function slug(): string { return 'devpilot'; }

    public function defaultTimeout(): int { return max(35, min(360, (int) config('devpilot.llm_request_timeout', 180) + 30)); }

    public function authenticate(array $credentials): array { return $credentials; }

    public function listTools(): array
    {
        $schema = static fn (array $properties, array $required = []) => ['type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => false];
        $project = ['type' => 'string', 'description' => 'ID du projet DevPilot sélectionné dans ce tenant.'];
        $path = ['type' => 'string', 'description' => 'Chemin relatif à la racine autorisée du projet; jamais un chemin absolu.'];
        $nullableHash = ['type' => ['string', 'null'], 'description' => 'SHA-256 observé à la lecture; null uniquement pour créer un nouveau fichier.'];
        $admin = ['defaultActorScope' => 'admin'];

        return [
            new ToolSchema('devpilot', 'list_projects', 'Liste les projets DevPilot auxquels ce site a explicitement accès.', $schema([]), defaultActorScope: 'admin', defaultMode: 'auto'),
            new ToolSchema('devpilot', 'inspect_project', 'Teste à nouveau la connexion du projet, vérifie le répertoire autorisé et indique les capacités réelles du protocole.', $schema(['project_id' => $project], ['project_id']), defaultActorScope: 'admin', defaultMode: 'confirm'),
            new ToolSchema('devpilot', 'list_files', 'Parcourt les fichiers lisibles du projet. Les secrets, fichiers système et liens symboliques sont exclus.', $schema(['project_id' => $project, 'path' => $path], ['project_id']), defaultActorScope: 'admin', defaultMode: 'auto'),
            new ToolSchema('devpilot', 'read_file', 'Lit un fichier texte du projet après contrôle de la racine et masque les secrets reconnus.', $schema(['project_id' => $project, 'path' => $path], ['project_id', 'path']), defaultActorScope: 'admin', defaultMode: 'auto'),
            new ToolSchema('devpilot', 'search_files', 'Recherche un nom de fichier dans un périmètre borné du projet.', $schema(['project_id' => $project, 'path' => $path, 'query' => ['type' => 'string']], ['project_id', 'query']), defaultActorScope: 'admin', defaultMode: 'auto'),
            new ToolSchema('devpilot', 'database_schema', 'Inspecte les tables d’une base MySQL/PostgreSQL configurée avec une transaction en lecture seule; aucune ligne ni requête SQL libre n’est exposée.', $schema(['project_id' => $project], ['project_id']), defaultActorScope: 'admin', defaultMode: 'auto'),
        ];
    }

    public function callTool(string $toolName, array $params, array $credentials, array $context = []): ToolResult
    {
        if (in_array($toolName, ['write_file', 'delete_file', 'rename_file', 'create_directory', 'run_command'], true)) {
            return ToolResult::fail('tool_removed', 'Cet outil a été retiré. Les modifications et commandes passent par le flux DevPilot avec plan, approbation et contrôle de cible.');
        }
        if (empty($context['is_admin'])) return ToolResult::fail('admin_only', 'DevPilot est réservé aux membres autorisés du back-office.');
        $site = Site::query()->find($context['site_id'] ?? null);
        if (! $site) return ToolResult::fail('site_not_found', 'Tenant introuvable.');
        if ($toolName === 'list_projects') {
            $projects = DevPilotProject::query()->where('site_id', $site->id)->with('connection:id,protocol,status')->orderBy('name')->get();
            return ToolResult::ok(['projects' => $projects->map(fn ($item) => ['id' => (string) $item->id, 'name' => $item->name, 'environment' => $item->environment, 'connection_protocol' => $item->connection?->protocol, 'connection_status' => $item->connection?->status])->all()]);
        }

        $project = DevPilotProject::query()->where('site_id', $site->id)->with(['connection', 'databaseConnection'])->find($params['project_id'] ?? null);
        if (! $project) return ToolResult::fail('project_not_found', 'Le projet est introuvable dans ce tenant.');

        try {
            return match ($toolName) {
                'inspect_project' => ToolResult::ok($this->fileSystems->forProject($project)->test(), 'Connexion et capacités distantes vérifiées.'),
                'list_files' => $this->listFiles($project, (string) ($params['path'] ?? '.')),
                'read_file' => $this->read($project, (string) $params['path']),
                'search_files' => $this->searchFiles($project, (string) $params['query'], (string) ($params['path'] ?? '.')),
                'database_schema' => $this->schema($project),
                default => throw new ToolNotFoundException("Outil DevPilot '{$toolName}' inconnu."),
            };
        } catch (RuntimeException $exception) {
            return ToolResult::fail('devpilot_operation_failed', $exception->getMessage());
        }
    }

    private function read(DevPilotProject $project, string $path): ToolResult
    {
        $this->assertInspectionPath($path);
        $content = $this->fileSystems->forProject($project)->read($path);
        return ToolResult::ok(['project_id' => $project->id, 'path' => $path, 'sha256' => hash('sha256', $content), 'content' => $this->redactor->redact($content)], 'Fichier distant lu; les secrets reconnus ont été masqués.');
    }

    private function listFiles(DevPilotProject $project, string $path): ToolResult
    {
        $this->assertInspectionPath($path);
        $entries = DevPilotInspectionPolicy::filterEntries($this->fileSystems->forProject($project)->list($path));
        return ToolResult::ok(['project_id' => $project->id, 'path' => $path, 'entries' => $entries]);
    }

    private function searchFiles(DevPilotProject $project, string $query, string $path): ToolResult
    {
        $this->assertInspectionPath($path);
        $result = $this->fileSystems->forProject($project)->search($query, $path);
        $result['matches'] = DevPilotInspectionPolicy::filterMatches($result['matches'] ?? []);
        return ToolResult::ok($result);
    }

    private function assertInspectionPath(string $path): void
    {
        if (DevPilotInspectionPolicy::containsExcludedSegment($path)) {
            throw new RuntimeException('Ce chemin est exclu de l’inspection DevPilot.');
        }
    }

    private function schema(DevPilotProject $project): ToolResult
    {
        $connection = $project->databaseConnection;
        if (! $connection || $connection->site_id !== $project->site_id || ! in_array($connection->protocol, ['mysql', 'pgsql'], true) || $connection->status !== 'connected') {
            return ToolResult::fail('database_unavailable', 'Aucune connexion MySQL/PostgreSQL vérifiée n’est associée à ce projet.');
        }
        if (! $connection->can_read) return ToolResult::fail('database_read_denied', 'La permission de lecture du schéma est désactivée pour cette base.');
        return ToolResult::ok($this->databases->test($connection, $this->connections->credentials($connection)), 'Schéma interrogé dans une transaction en lecture seule.');
    }
}
