<?php

namespace App\Domain\MCP\Connectors;

use App\Domain\MCP\Contracts\ToolResult;
use App\Domain\MCP\Contracts\ToolSchema;
use App\Domain\MCP\Exceptions\AuthExpiredException;
use App\Domain\MCP\Exceptions\ConnectorUnavailableException;
use App\Domain\MCP\Exceptions\ToolNotFoundException;
use Illuminate\Http\Client\RequestException;

/** credentials attendus : { "access_token": "...", "workspace_gid": "...", "project_gid": "..." (optionnel) } */
class AsanaConnector extends AbstractConnector
{
    public function slug(): string { return 'asana'; }

    public function authenticate(array $credentials): array
    {
        if (empty($credentials['access_token'])) {
            throw new AuthExpiredException('Jeton d\'accès personnel Asana manquant.');
        }
        return $credentials;
    }

    public function listTools(): array
    {
        return [
            new ToolSchema('asana',
                'create_task',
                "Crée une nouvelle tâche Asana lorsqu'aucune tâche existante ne correspond à la demande de l'utilisateur. Utiliser uniquement pour une nouvelle action à planifier. Si l'utilisateur souhaite modifier, terminer ou commenter une tâche existante, utiliser l'outil approprié. Si la demande est ambiguë ou incomplète (nom, échéance, contexte), demander les informations manquantes avant de créer la tâche. Ne jamais créer de doublon volontairement.", [
                'type' => 'object', 'properties' => ['name' => ['type' => 'string'], 'notes' => ['type' => 'string'], 'due_date' => ['type' => 'string']], 'required' => ['name'],
            ], isWriteAction: true, defaultMode: 'auto', capability: 'crm.create_task'),

            new ToolSchema('asana',
                'update_task',
                "Met à jour une tâche Asana existante identifiée de manière unique par son task_gid. Si seul un nom ou une description est fourni, rechercher d'abord la tâche. En cas de plusieurs correspondances, demander une clarification avant toute modification. Modifier uniquement les champs explicitement demandés par l'utilisateur.", [
                'type' => 'object', 'properties' => ['task_gid' => ['type' => 'string'], 'name' => ['type' => 'string'], 'notes' => ['type' => 'string'], 'due_date' => ['type' => 'string']], 'required' => ['task_gid'],
            ], isWriteAction: true, defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('asana',
                'complete_task',
                "Marque une tâche Asana existante comme terminée. Utiliser uniquement lorsque l'utilisateur exprime clairement l'intention de clôturer ou terminer une tâche. Si la tâche n'est pas identifiée de manière unique, effectuer une recherche puis demander une clarification si nécessaire avant de la terminer.", [
                'type' => 'object', 'properties' => ['task_gid' => ['type' => 'string']], 'required' => ['task_gid'],
            ], isWriteAction: true, defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('asana',
                'list_tasks',
                "Retourne la liste des tâches du projet Asana configuré. Utiliser lorsque l'utilisateur souhaite consulter, parcourir ou résumer les tâches du projet sans appliquer de filtre spécifique. Pour une recherche ciblée, utiliser search_tasks.", ['type' => 'object', 'properties' => []],
                defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('asana',
                'search_tasks',
                "Recherche des tâches Asana correspondant à un texte libre fourni par l'utilisateur. Utiliser pour localiser une tâche avant une mise à jour, un commentaire ou une clôture, ou lorsque l'utilisateur ne connaît pas son identifiant. Ne pas supposer qu'un résultat unique existe ; demander une clarification si plusieurs tâches pertinentes sont trouvées.", [
                'type' => 'object', 'properties' => ['query' => ['type' => 'string']], 'required' => ['query'],
            ], defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('asana',
                'add_comment',
                "Ajoute un commentaire à une tâche Asana existante. Utiliser uniquement lorsque l'utilisateur souhaite ajouter une information sans modifier les propriétés de la tâche. Si la tâche n'est pas identifiée de manière unique, rechercher la tâche puis demander une clarification avant d'ajouter le commentaire.", [
                'type' => 'object', 'properties' => ['task_gid' => ['type' => 'string'], 'text' => ['type' => 'string']], 'required' => ['task_gid', 'text'],
            ], isWriteAction: true, defaultActorScope: 'admin', defaultMode: 'auto'),
        ];
    }

    public function callTool(string $toolName, array $params, array $credentials, array $context = []): ToolResult
    {
        return match ($toolName) {
            'create_task' => $this->createTask($params, $credentials),
            'update_task' => $this->updateTask($params, $credentials),
            'complete_task' => $this->completeTask($params, $credentials),
            'list_tasks' => $this->listTasks($credentials),
            'search_tasks' => $this->searchTasks($params, $credentials),
            'add_comment' => $this->addComment($params, $credentials),
            default => throw new ToolNotFoundException("Outil '{$toolName}' inconnu pour asana."),
        };
    }

    private function createTask(array $p, array $c): ToolResult
    {
        try {
            $task = $this->client($c)->post('tasks', ['data' => array_filter([
                'name' => $p['name'], 'notes' => $p['notes'] ?? null, 'due_on' => !empty($p['due_date']) ? substr($p['due_date'], 0, 10) : null,
                'projects' => !empty($c['project_gid']) ? [$c['project_gid']] : null, 'workspace' => $c['workspace_gid'] ?? null,
            ])])->json('data');
        } catch (RequestException $e) {
            throw new ConnectorUnavailableException('Asana indisponible: ' . $e->getMessage());
        }
        $this->recordSuccess();
        return ToolResult::ok(['task_gid' => $task['gid']], "Tâche « {$p['name']} » créée.");
    }

    private function updateTask(array $p, array $c): ToolResult
    {
        try {
            $this->client($c)->put("tasks/{$p['task_gid']}", ['data' => array_filter([
                'name' => $p['name'] ?? null, 'notes' => $p['notes'] ?? null, 'due_on' => !empty($p['due_date']) ? substr($p['due_date'], 0, 10) : null,
            ])]);
        } catch (RequestException $e) {
            if ($e->response?->status() === 404) return ToolResult::fail('not_found', 'Tâche introuvable.');
            throw new ConnectorUnavailableException('Asana indisponible: ' . $e->getMessage());
        }
        $this->recordSuccess();
        return ToolResult::ok(['task_gid' => $p['task_gid']], 'Tâche mise à jour.');
    }

    private function completeTask(array $p, array $c): ToolResult
    {
        try {
            $this->client($c)->put("tasks/{$p['task_gid']}", ['data' => ['completed' => true]]);
        } catch (RequestException $e) {
            if ($e->response?->status() === 404) return ToolResult::fail('not_found', 'Tâche introuvable.');
            throw new ConnectorUnavailableException('Asana indisponible: ' . $e->getMessage());
        }
        $this->recordSuccess();
        return ToolResult::ok(['task_gid' => $p['task_gid']], 'Tâche marquée comme terminée.');
    }

    private function listTasks(array $c): ToolResult
    {
        if (empty($c['project_gid'])) return ToolResult::fail('not_configured', "Aucun projet Asana par défaut configuré pour ce site.");
        try {
            $res = $this->client($c)->get("projects/{$c['project_gid']}/tasks", ['opt_fields' => 'name,due_on,completed']);
        } catch (RequestException $e) {
            throw new ConnectorUnavailableException('Asana indisponible: ' . $e->getMessage());
        }
        $this->recordSuccess();
        $tasks = $res->json('data', []);
        if (empty($tasks)) return ToolResult::fail('not_found', 'Aucune tâche trouvée.');
        return ToolResult::ok(['tasks' => $tasks], count($tasks) . ' tâche(s)');
    }

    private function searchTasks(array $p, array $c): ToolResult
    {
        if (empty($c['workspace_gid'])) return ToolResult::fail('not_configured', "Aucun workspace Asana configuré pour ce site.");
        try {
            $res = $this->client($c)->get("workspaces/{$c['workspace_gid']}/tasks/search", ['text' => $p['query'], 'opt_fields' => 'name,due_on,completed']);
        } catch (RequestException $e) {
            throw new ConnectorUnavailableException('Asana indisponible: ' . $e->getMessage());
        }
        $this->recordSuccess();
        $tasks = $res->json('data', []);
        if (empty($tasks)) return ToolResult::fail('not_found', 'Aucune tâche trouvée.');
        return ToolResult::ok(['tasks' => $tasks], count($tasks) . ' tâche(s) trouvée(s)');
    }

    private function addComment(array $p, array $c): ToolResult
    {
        try {
            $this->client($c)->post("tasks/{$p['task_gid']}/stories", ['data' => ['text' => $p['text']]]);
        } catch (RequestException $e) {
            if ($e->response?->status() === 404) return ToolResult::fail('not_found', 'Tâche introuvable.');
            throw new ConnectorUnavailableException('Asana indisponible: ' . $e->getMessage());
        }
        $this->recordSuccess();
        return ToolResult::ok(['task_gid' => $p['task_gid']], 'Commentaire ajouté.');
    }

    private function client(array $c)
    {
        return $this->http('https://app.asana.com/api/1.0/')->withToken($c['access_token']);
    }
}
