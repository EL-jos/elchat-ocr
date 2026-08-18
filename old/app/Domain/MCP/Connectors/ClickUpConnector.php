<?php

namespace App\Domain\MCP\Connectors;

use App\Domain\MCP\Contracts\ToolResult;
use App\Domain\MCP\Contracts\ToolSchema;
use App\Domain\MCP\Exceptions\AuthExpiredException;
use App\Domain\MCP\Exceptions\ConnectorUnavailableException;
use App\Domain\MCP\Exceptions\ToolNotFoundException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;

/**
 * Connecteur ClickUp (API v2), gestion de tâches.
 * credentials attendus (déchiffrés) : { "api_key": "pk_..." } — jeton API
 * personnel (Paramètres > Apps > API Token). Particularité ClickUp : le
 * jeton se passe TEL QUEL dans l'en-tête Authorization, sans préfixe
 * "Bearer" (contrairement à la quasi-totalité des autres API REST déjà
 * connectées) — ne pas "corriger" ça, c'est le comportement attendu.
 * settings attendus (optionnel) : { "default_list_id": "..." } — liste
 * ClickUp ciblée par défaut si aucune n'est précisée dans la conversation.
 *
 * Toutes les écritures restent en defaultMode 'auto' (cohérent avec les
 * autres outils de gestion de projet déjà connectés type Notion/Asana) :
 * créer/modifier une tâche interne n'a pas l'impact financier ou
 * réputationnel des connecteurs Ads/réseaux sociaux qui justifient
 * 'confirm'. Toujours actor_scope 'admin' : outil de pilotage interne, pas
 * une action à exposer à un visiteur du widget.
 */
class ClickUpConnector extends AbstractConnector
{
    private const API_BASE = 'https://api.clickup.com/api/v2/';

    public function slug(): string
    {
        return 'clickup';
    }

    public function authenticate(array $credentials): array
    {
        if (empty($credentials['api_key'])) {
            throw new AuthExpiredException('Jeton API ClickUp manquant.');
        }
        return $credentials; // pas de rafraîchissement : jeton statique
    }

    public function listTools(): array
    {
        return [
            new ToolSchema('clickup', 'list_workspaces',
                "Liste les espaces de travail (workspaces/teams) ClickUp accessibles avec ce jeton. Utiliser en premier lieu si aucun espace n'est encore connu, avant list_spaces.",
                ['type' => 'object', 'properties' => []], defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('clickup', 'list_lists',
                "Liste les listes de tâches d'un espace ClickUp (space_id) ou d'un dossier (folder_id) — fournir l'un des deux. Utiliser pour retrouver l'identifiant d'une liste nommée avant de créer ou consulter des tâches. Ne jamais inventer un identifiant de liste non retourné par cet outil.",
                ['type' => 'object', 'properties' => [
                    'space_id' => ['type' => 'string', 'description' => 'listes sans dossier de cet espace'],
                    'folder_id' => ['type' => 'string', 'description' => "listes de ce dossier — fournir space_id OU folder_id, pas les deux"],
                ]], defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('clickup', 'list_tasks',
                "Liste les tâches d'une liste ClickUp, avec filtre optionnel par statut. Utiliser pour retrouver l'identifiant d'une tâche nommée, ou pour un état des lieux d'une liste.",
                ['type' => 'object', 'properties' => [
                    'list_id' => ['type' => 'string', 'description' => 'défaut: liste configurée par défaut'],
                    'status' => ['type' => 'string', 'description' => "nom exact du statut ClickUp (varie selon la liste), optionnel"],
                    'include_closed' => ['type' => 'boolean', 'description' => 'défaut false : exclut les tâches terminées/fermées'],
                ]], defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('clickup', 'get_task',
                "Récupère le détail d'une tâche ClickUp identifiée de manière unique : statut, description, assigné(s), échéance, priorité. Si l'identifiant est inconnu, utiliser list_tasks au préalable. Ne jamais inventer un identifiant de tâche.",
                ['type' => 'object', 'properties' => ['task_id' => ['type' => 'string']], 'required' => ['task_id']],
                defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('clickup', 'create_task',
                "Crée une nouvelle tâche dans une liste ClickUp. Vérifier qu'un nom clair est fourni avant création ; ne jamais créer une tâche à partir d'une supposition sur son contenu. Si une tâche très similaire semble déjà exister, utiliser list_tasks avant de créer un doublon.",
                ['type' => 'object', 'properties' => [
                    'list_id' => ['type' => 'string', 'description' => 'défaut: liste configurée par défaut'],
                    'name' => ['type' => 'string'], 'description' => ['type' => 'string'],
                    'due_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD, optionnel'],
                    'priority' => ['type' => 'integer', 'description' => '1=urgent, 2=élevée, 3=normale, 4=basse, optionnel'],
                ], 'required' => ['name']],
                isWriteAction: true, defaultActorScope: 'admin', defaultMode: 'auto', capability: 'tasks.create'),

            new ToolSchema('clickup', 'update_task_status',
                "Change le statut d'une tâche ClickUp existante identifiée de manière unique (ex: passer 'à faire' à 'en cours' ou 'terminé'). Le nom du statut doit correspondre exactement à un statut existant de la liste — si incertain, consulter get_task d'abord pour voir les statuts déjà utilisés dans cette liste. Ne jamais deviner un nom de statut.",
                ['type' => 'object', 'properties' => ['task_id' => ['type' => 'string'], 'status' => ['type' => 'string']], 'required' => ['task_id', 'status']],
                isWriteAction: true, defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('clickup', 'add_comment',
                "Ajoute un commentaire à une tâche ClickUp existante identifiée de manière unique. Utiliser pour laisser une note ou une mise à jour sans modifier les champs structurés de la tâche.",
                ['type' => 'object', 'properties' => ['task_id' => ['type' => 'string'], 'comment' => ['type' => 'string']], 'required' => ['task_id', 'comment']],
                isWriteAction: true, defaultActorScope: 'admin', defaultMode: 'auto'),
        ];
    }

    public function callTool(string $toolName, array $params, array $credentials, array $context = []): ToolResult
    {
        return match ($toolName) {
            'list_workspaces' => $this->listWorkspaces($credentials),
            'list_lists' => $this->listLists($params, $credentials),
            'list_tasks' => $this->listTasks($params, $credentials),
            'get_task' => $this->getTask($params, $credentials),
            'create_task' => $this->createTask($params, $credentials),
            'update_task_status' => $this->updateTaskStatus($params, $credentials),
            'add_comment' => $this->addComment($params, $credentials),
            default => throw new ToolNotFoundException("Outil '{$toolName}' inconnu pour clickup."),
        };
    }

    // ── Implémentation ──────────────────────────────────────────────

    private function listWorkspaces(array $c): ToolResult
    {
        try {
            $response = $this->client($c)->get('team');
        } catch (RequestException $e) {
            $this->handleApiError($e);
        }
        $this->recordSuccess();

        $teams = collect($response->json('teams', []))->map(fn ($t) => ['id' => $t['id'] ?? null, 'name' => $t['name'] ?? null])->all();

        if (empty($teams)) {
            return ToolResult::fail('not_found', 'Aucun espace de travail accessible avec ce jeton.');
        }
        return ToolResult::ok(['workspaces' => $teams], count($teams) . ' espace(s) de travail trouvé(s).');
    }

    private function listLists(array $p, array $c): ToolResult
    {
        if (empty($p['space_id']) && empty($p['folder_id'])) {
            return ToolResult::fail('invalid_request', "Il faut préciser soit space_id, soit folder_id.");
        }
        $endpoint = !empty($p['folder_id']) ? "folder/{$p['folder_id']}/list" : "space/{$p['space_id']}/list";

        try {
            $response = $this->client($c)->get($endpoint);
        } catch (RequestException $e) {
            if ($e->response?->status() === 404) {
                return ToolResult::fail('not_found', "Espace ou dossier introuvable.");
            }
            $this->handleApiError($e);
        }
        $this->recordSuccess();

        $lists = collect($response->json('lists', []))->map(fn ($l) => [
            'id' => $l['id'] ?? null, 'name' => $l['name'] ?? null, 'task_count' => $l['task_count'] ?? null,
        ])->all();

        if (empty($lists)) {
            return ToolResult::fail('not_found', 'Aucune liste trouvée.');
        }
        return ToolResult::ok(['lists' => $lists], count($lists) . ' liste(s) trouvée(s).');
    }

    private function listTasks(array $p, array $c): ToolResult
    {
        $listId = $this->listId($p, $c);
        if (!$listId) {
            return ToolResult::fail('not_configured', 'Aucune liste précisée et aucune liste par défaut configurée pour ce site.');
        }

        $query = ['include_closed' => !empty($p['include_closed']) ? 'true' : 'false'];
        if (!empty($p['status'])) {
            $query['statuses[]'] = $p['status'];
        }

        try {
            $response = $this->client($c)->get("list/{$listId}/task", $query);
        } catch (RequestException $e) {
            if ($e->response?->status() === 404) {
                return ToolResult::fail('not_found', 'Liste introuvable.');
            }
            $this->handleApiError($e);
        }
        $this->recordSuccess();

        $tasks = collect($response->json('tasks', []))->map(fn ($t) => [
            'id' => $t['id'] ?? null, 'name' => $t['name'] ?? null,
            'status' => $t['status']['status'] ?? null, 'due_date' => $t['due_date'] ?? null,
        ])->all();

        if (empty($tasks)) {
            return ToolResult::fail('not_found', 'Aucune tâche trouvée dans cette liste.');
        }
        return ToolResult::ok(['tasks' => $tasks], count($tasks) . ' tâche(s) trouvée(s).');
    }

    private function getTask(array $p, array $c): ToolResult
    {
        try {
            $response = $this->client($c)->get("task/{$p['task_id']}");
        } catch (RequestException $e) {
            if ($e->response?->status() === 404) {
                return ToolResult::fail('not_found', 'Tâche introuvable.');
            }
            $this->handleApiError($e);
        }
        $this->recordSuccess();
        $t = $response->json();

        return ToolResult::ok([
            'id' => $t['id'] ?? null, 'name' => $t['name'] ?? null, 'description' => $t['text_content'] ?? null,
            'status' => $t['status']['status'] ?? null, 'priority' => $t['priority']['priority'] ?? null,
            'due_date' => $t['due_date'] ?? null,
            'assignees' => collect($t['assignees'] ?? [])->pluck('username')->all(),
        ], 'Tâche récupérée.');
    }

    private function createTask(array $p, array $c): ToolResult
    {
        $listId = $this->listId($p, $c);
        if (!$listId) {
            return ToolResult::fail('not_configured', 'Aucune liste précisée et aucune liste par défaut configurée pour ce site.');
        }

        $body = array_filter([
            'name' => $p['name'], 'description' => $p['description'] ?? null,
            'priority' => isset($p['priority']) ? (int) $p['priority'] : null,
        ], fn ($v) => $v !== null);

        if (!empty($p['due_date'])) {
            try {
                $body['due_date'] = (new \DateTimeImmutable($p['due_date']))->getTimestamp() * 1000; // ClickUp attend des ms
            } catch (\Exception) {
                return ToolResult::fail('invalid_date', "Format de date invalide pour due_date (attendu: YYYY-MM-DD).");
            }
        }

        try {
            $response = $this->client($c)->post("list/{$listId}/task", $body);
        } catch (RequestException $e) {
            if ($e->response?->status() === 404) {
                return ToolResult::fail('not_found', 'Liste introuvable.');
            }
            $this->handleApiError($e);
        }
        $this->recordSuccess();
        $taskId = $response->json('id');

        return ToolResult::ok(['task_id' => $taskId, 'name' => $p['name']], "Tâche « {$p['name']} » créée.");
    }

    private function updateTaskStatus(array $p, array $c): ToolResult
    {
        try {
            $this->client($c)->put("task/{$p['task_id']}", ['status' => $p['status']]);
        } catch (RequestException $e) {
            if ($e->response?->status() === 404) {
                return ToolResult::fail('not_found', 'Tâche introuvable.');
            }
            if ($e->response?->status() === 400) {
                return ToolResult::fail('invalid_status', "Le statut « {$p['status']} » n'existe pas dans la liste de cette tâche.");
            }
            $this->handleApiError($e);
        }
        $this->recordSuccess();

        return ToolResult::ok(['task_id' => $p['task_id'], 'status' => $p['status']], "Statut mis à jour : {$p['status']}.");
    }

    private function addComment(array $p, array $c): ToolResult
    {
        try {
            $this->client($c)->post("task/{$p['task_id']}/comment", ['comment_text' => $p['comment']]);
        } catch (RequestException $e) {
            if ($e->response?->status() === 404) {
                return ToolResult::fail('not_found', 'Tâche introuvable.');
            }
            $this->handleApiError($e);
        }
        $this->recordSuccess();

        return ToolResult::ok(['task_id' => $p['task_id']], 'Commentaire ajouté.');
    }

    // ── Utilitaires ──────────────────────────────────────────────────

    private function listId(array $p, array $c): ?string
    {
        return $p['list_id'] ?? $c['default_list_id'] ?? null;
    }

    private function client(array $credentials)
    {
        // Pas de préfixe "Bearer" : ClickUp attend le jeton brut.
        return $this->http(self::API_BASE)->withHeaders(['Authorization' => $credentials['api_key']]);
    }

    private function handleApiError(RequestException $e): never
    {
        $status = $e->response?->status();
        $body = $e->response?->body();
        Log::error('MCP ClickUp: appel API échoué', ['status' => $status, 'body' => $body]);

        if (in_array($status, [401, 403])) {
            throw new AuthExpiredException('Jeton API ClickUp invalide ou révoqué.');
        }
        throw new ConnectorUnavailableException('ClickUp indisponible: ' . $e->getMessage());
    }
}
