<?php

namespace App\Domain\MCP\Connectors;

use App\Domain\MCP\Contracts\ToolResult;
use App\Domain\MCP\Contracts\ToolSchema;
use App\Domain\MCP\Exceptions\AuthExpiredException;
use App\Domain\MCP\Exceptions\ConnectorUnavailableException;
use App\Domain\MCP\Exceptions\PermissionDeniedException;
use App\Domain\MCP\Exceptions\ToolNotFoundException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Connecteur Jira Cloud via OAuth 2.0 (3LO) et REST API v3.
 *
 * credentials attendus (déchiffrés) :
 * { access_token, refresh_token, expires_at, cloud_id, granted_scopes }
 *
 * Le cloud_id est découvert après l'autorisation OAuth via
 * /oauth/token/accessible-resources. Les appels Jira 3LO doivent passer par
 * api.atlassian.com/ex/jira/{cloud_id}, pas directement par le domaine Jira.
 */
class JiraConnector extends AbstractConnector
{
    private const API_BASE = 'https://api.atlassian.com/ex/jira/';
    private const TOKEN_ENDPOINT = 'https://auth.atlassian.com/oauth/token';

    public function slug(): string
    {
        return 'jira';
    }

    public function authenticate(array $credentials): array
    {
        if (empty($credentials['access_token']) || empty($credentials['cloud_id'])) {
            throw new AuthExpiredException('Connexion Jira incomplète, reconnexion requise.');
        }

        $expiresAt = (int) ($credentials['expires_at'] ?? 0);
        if ($expiresAt === 0 || now()->timestamp < $expiresAt - 60) {
            return $credentials;
        }

        if (empty($credentials['refresh_token'])) {
            throw new AuthExpiredException('Refresh token Jira absent, reconnexion OAuth requise.');
        }

        try {
            // Atlassian documente cet échange en JSON et fait tourner les
            // refresh tokens : il faut conserver le nouveau refresh token.
            $response = Http::asJson()->post(self::TOKEN_ENDPOINT, [
                'grant_type' => 'refresh_token',
                'client_id' => config('mcp.connectors.jira.client_id'),
                'client_secret' => config('mcp.connectors.jira.client_secret'),
                'refresh_token' => $credentials['refresh_token'],
            ])->throw();
        } catch (RequestException $e) {
            Log::warning('MCP Jira: refresh token refusé', ['status' => $e->response?->status()]);
            throw new AuthExpiredException('Token Jira invalide ou expiré, reconnexion requise.');
        }

        $data = $response->json();
        if (empty($data['access_token'])) {
            throw new AuthExpiredException('Jira n’a pas retourné de token valide, reconnexion requise.');
        }

        $fresh = array_merge($credentials, [
            'access_token' => $data['access_token'],
            'expires_at' => now()->addSeconds((int) ($data['expires_in'] ?? 3600))->timestamp,
        ]);
        if (!empty($data['refresh_token'])) {
            $fresh['refresh_token'] = $data['refresh_token'];
        }
        if (!empty($data['scope'])) {
            $fresh['granted_scopes'] = $this->scopes($data['scope']);
        }

        return $fresh;
    }

    public function listTools(): array
    {
        return [
            new ToolSchema('jira', 'list_projects',
                "Liste les projets Jira Cloud accessibles par l'utilisateur connecté. Utiliser cet outil avant de créer une issue si la clé du projet n'est pas connue. Ne jamais inventer une clé de projet.",
                ['type' => 'object', 'properties' => []], defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('jira', 'search_issues',
                "Recherche des issues Jira avec une requête JQL fournie par l'utilisateur. Utiliser pour retrouver une issue avant de la modifier ou de la commenter. Si plusieurs issues correspondent, demander une clarification avant toute écriture.",
                ['type' => 'object', 'properties' => [
                    'jql' => ['type' => 'string', 'description' => 'requête JQL Jira, par exemple project = DEMO ORDER BY updated DESC'],
                    'limit' => ['type' => 'integer', 'description' => 'nombre maximal de résultats, de 1 à 50'],
                ], 'required' => ['jql']], defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('jira', 'get_issue',
                "Récupère le détail d'une issue Jira identifiée de manière unique par sa clé (ex: DEMO-123). Ne jamais inventer une clé d'issue.",
                ['type' => 'object', 'properties' => ['issue_key' => ['type' => 'string']], 'required' => ['issue_key']],
                defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('jira', 'create_issue',
                "Crée une issue Jira dans un projet identifié de manière unique. Vérifier le projet et le type d'issue avant création et utiliser search_issues si un doublon est possible. Ne jamais créer une issue à partir d'informations supposées.",
                ['type' => 'object', 'properties' => [
                    'project_key' => ['type' => 'string', 'description' => 'clé du projet Jira, ex: DEMO ; défaut: projet configuré pour ce site'],
                    'summary' => ['type' => 'string'],
                    'issue_type' => ['type' => 'string', 'description' => 'nom exact du type, ex: Task, Bug ou Story'],
                    'description' => ['type' => 'string'],
                    'labels' => ['type' => 'array', 'items' => ['type' => 'string']],
                ], 'required' => ['summary']],
                isWriteAction: true, defaultActorScope: 'admin', defaultMode: 'auto', capability: 'tasks.create'),

            new ToolSchema('jira', 'update_issue',
                "Met à jour les champs explicitement fournis d'une issue Jira existante identifiée de manière unique. Ne modifie jamais un champ absent de la demande.",
                ['type' => 'object', 'properties' => [
                    'issue_key' => ['type' => 'string'],
                    'summary' => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                    'labels' => ['type' => 'array', 'items' => ['type' => 'string']],
                ], 'required' => ['issue_key']],
                isWriteAction: true, defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('jira', 'list_transitions',
                "Liste les transitions disponibles pour une issue Jira. Utiliser avant de changer son statut afin de ne jamais deviner un transition_id.",
                ['type' => 'object', 'properties' => ['issue_key' => ['type' => 'string']], 'required' => ['issue_key']],
                defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('jira', 'transition_issue',
                "Change le statut d'une issue Jira existante en utilisant un transition_id retourné par list_transitions. Ne jamais inventer un transition_id.",
                ['type' => 'object', 'properties' => [
                    'issue_key' => ['type' => 'string'], 'transition_id' => ['type' => 'string'],
                ], 'required' => ['issue_key', 'transition_id']],
                isWriteAction: true, defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('jira', 'add_comment',
                "Ajoute un commentaire à une issue Jira existante identifiée de manière unique. Ne pas commenter une issue ambiguë.",
                ['type' => 'object', 'properties' => [
                    'issue_key' => ['type' => 'string'], 'comment' => ['type' => 'string'],
                ], 'required' => ['issue_key', 'comment']],
                isWriteAction: true, defaultActorScope: 'admin', defaultMode: 'auto'),
        ];
    }

    public function callTool(string $toolName, array $params, array $credentials, array $context = []): ToolResult
    {
        return match ($toolName) {
            'list_projects' => $this->listProjects($credentials),
            'search_issues' => $this->searchIssues($params, $credentials),
            'get_issue' => $this->getIssue($params, $credentials),
            'create_issue' => $this->createIssue($params, $credentials),
            'update_issue' => $this->updateIssue($params, $credentials),
            'list_transitions' => $this->listTransitions($params, $credentials),
            'transition_issue' => $this->transitionIssue($params, $credentials),
            'add_comment' => $this->addComment($params, $credentials),
            default => throw new ToolNotFoundException("Outil '{$toolName}' inconnu pour jira."),
        };
    }

    private function listProjects(array $c): ToolResult
    {
        try {
            $response = $this->client($c)->get('project/search', ['maxResults' => 50]);
        } catch (RequestException $e) {
            $this->handleApiError($e);
        }
        $this->recordSuccess();

        $projects = collect($response->json('values', $response->json()))->map(fn ($project) => [
            'id' => $project['id'] ?? null,
            'key' => $project['key'] ?? null,
            'name' => $project['name'] ?? null,
            'url' => $project['self'] ?? null,
        ])->values()->all();

        return empty($projects)
            ? ToolResult::fail('not_found', 'Aucun projet Jira accessible.')
            : ToolResult::ok(['projects' => $projects], count($projects) . ' projet(s) Jira trouvé(s).');
    }

    private function searchIssues(array $p, array $c): ToolResult
    {
        $jql = trim((string) ($p['jql'] ?? ''));
        if ($jql === '') {
            return ToolResult::fail('invalid_request', 'La requête JQL ne peut pas être vide.');
        }

        try {
            $response = $this->client($c)->post('search/jql', [
                'jql' => $jql,
                'maxResults' => min(50, max(1, (int) ($p['limit'] ?? 20))),
                'fields' => ['summary', 'status', 'project', 'assignee', 'priority', 'issuetype', 'updated'],
            ]);
            $response->throw();
        } catch (RequestException $e) {
            if ($e->response?->status() === 400) {
                return ToolResult::fail('invalid_jql', 'La requête JQL est invalide : ' . $this->apiMessage($e->response));
            }
            $this->handleApiError($e);
        }
        $this->recordSuccess();

        $issues = $this->mapIssues($response->json('issues', []));
        return empty($issues)
            ? ToolResult::fail('not_found', 'Aucune issue ne correspond à cette requête JQL.')
            : ToolResult::ok(['issues' => $issues], count($issues) . ' issue(s) Jira trouvée(s).');
    }

    private function getIssue(array $p, array $c): ToolResult
    {
        try {
            $response = $this->client($c)->get('issue/' . rawurlencode($p['issue_key']), [
                'fields' => 'summary,description,status,project,assignee,priority,issuetype,labels,created,updated',
            ]);
            $response->throw();
        } catch (RequestException $e) {
            if ($e->response?->status() === 404) return ToolResult::fail('not_found', 'Issue Jira introuvable.');
            $this->handleApiError($e);
        }
        $this->recordSuccess();

        $issue = $response->json();
        return ToolResult::ok($this->mapIssue($issue), 'Issue Jira récupérée.');
    }

    private function createIssue(array $p, array $c): ToolResult
    {
        $projectKey = trim((string) ($p['project_key'] ?? $c['default_project_key'] ?? ''));
        $summary = trim((string) ($p['summary'] ?? ''));
        if ($projectKey === '' || $summary === '') {
            return ToolResult::fail('invalid_request', 'project_key et summary sont obligatoires pour créer une issue Jira.');
        }

        $fields = [
            'project' => ['key' => $projectKey],
            'summary' => $summary,
            'issuetype' => ['name' => $p['issue_type'] ?? 'Task'],
        ];
        if (!empty($p['description'])) $fields['description'] = $this->adf($p['description']);
        if (array_key_exists('labels', $p)) $fields['labels'] = array_values(array_filter($p['labels'], 'is_string'));

        try {
            $response = $this->client($c)->post('issue', ['fields' => $fields]);
            $response->throw();
        } catch (RequestException $e) {
            if ($e->response?->status() === 400) return ToolResult::fail('invalid_request', 'Création Jira refusée : ' . $this->apiMessage($e->response));
            $this->handleApiError($e);
        }
        $this->recordSuccess();

        return ToolResult::ok([
            'issue_key' => $response->json('key'),
            'issue_id' => $response->json('id'),
            'url' => $this->issueUrl($c, (string) $response->json('key')),
        ], "Issue Jira « {$summary} » créée.");
    }

    private function updateIssue(array $p, array $c): ToolResult
    {
        $fields = [];
        foreach (['summary', 'description', 'labels'] as $field) {
            if (!array_key_exists($field, $p)) continue;
            $fields[$field] = $field === 'description' ? $this->adf((string) $p[$field]) : $p[$field];
        }
        if (empty($fields)) return ToolResult::fail('invalid_request', 'Aucun champ à mettre à jour n’a été fourni.');

        try {
            $this->client($c)->put('issue/' . rawurlencode($p['issue_key']), ['fields' => $fields])->throw();
        } catch (RequestException $e) {
            if ($e->response?->status() === 404) return ToolResult::fail('not_found', 'Issue Jira introuvable.');
            if ($e->response?->status() === 400) return ToolResult::fail('invalid_request', 'Mise à jour Jira refusée : ' . $this->apiMessage($e->response));
            $this->handleApiError($e);
        }
        $this->recordSuccess();
        return ToolResult::ok(['issue_key' => $p['issue_key']], 'Issue Jira mise à jour.');
    }

    private function listTransitions(array $p, array $c): ToolResult
    {
        try {
            $response = $this->client($c)->get('issue/' . rawurlencode($p['issue_key']) . '/transitions');
            $response->throw();
        } catch (RequestException $e) {
            if ($e->response?->status() === 404) return ToolResult::fail('not_found', 'Issue Jira introuvable.');
            $this->handleApiError($e);
        }
        $this->recordSuccess();

        $transitions = collect($response->json('transitions', []))->map(fn ($transition) => [
            'id' => $transition['id'] ?? null,
            'name' => $transition['name'] ?? null,
            'to_status' => $transition['to']['name'] ?? null,
        ])->all();

        return ToolResult::ok(['transitions' => $transitions], count($transitions) . ' transition(s) disponible(s).');
    }

    private function transitionIssue(array $p, array $c): ToolResult
    {
        try {
            $this->client($c)->post('issue/' . rawurlencode($p['issue_key']) . '/transitions', [
                'transition' => ['id' => (string) $p['transition_id']],
            ])->throw();
        } catch (RequestException $e) {
            if ($e->response?->status() === 404) return ToolResult::fail('not_found', 'Issue ou transition Jira introuvable.');
            if ($e->response?->status() === 400) return ToolResult::fail('invalid_transition', 'Transition Jira refusée : ' . $this->apiMessage($e->response));
            $this->handleApiError($e);
        }
        $this->recordSuccess();
        return ToolResult::ok(['issue_key' => $p['issue_key'], 'transition_id' => $p['transition_id']], 'Statut Jira mis à jour.');
    }

    private function addComment(array $p, array $c): ToolResult
    {
        try {
            $this->client($c)->post('issue/' . rawurlencode($p['issue_key']) . '/comment', [
                'body' => $this->adf((string) $p['comment']),
            ])->throw();
        } catch (RequestException $e) {
            if ($e->response?->status() === 404) return ToolResult::fail('not_found', 'Issue Jira introuvable.');
            $this->handleApiError($e);
        }
        $this->recordSuccess();
        return ToolResult::ok(['issue_key' => $p['issue_key']], 'Commentaire Jira ajouté.');
    }

    private function client(array $credentials)
    {
        return $this->http(self::API_BASE . rawurlencode((string) $credentials['cloud_id']) . '/rest/api/3/')
            ->withToken($credentials['access_token'])
            ->acceptJson();
    }

    private function mapIssues(array $issues): array
    {
        return collect($issues)->map(fn ($issue) => $this->mapIssue($issue))->all();
    }

    private function mapIssue(array $issue): array
    {
        $fields = $issue['fields'] ?? [];
        return [
            'id' => $issue['id'] ?? null,
            'key' => $issue['key'] ?? null,
            'summary' => $fields['summary'] ?? null,
            'description' => $this->adfText($fields['description'] ?? null),
            'status' => $fields['status']['name'] ?? null,
            'project' => $fields['project']['key'] ?? null,
            'issue_type' => $fields['issuetype']['name'] ?? null,
            'assignee' => $fields['assignee']['displayName'] ?? null,
            'priority' => $fields['priority']['name'] ?? null,
            'labels' => $fields['labels'] ?? [],
            'created' => $fields['created'] ?? null,
            'updated' => $fields['updated'] ?? null,
        ];
    }

    private function adf(string $text): array
    {
        return [
            'type' => 'doc', 'version' => 1,
            'content' => [[
                'type' => 'paragraph',
                'content' => [['type' => 'text', 'text' => $text]],
            ]],
        ];
    }

    private function adfText(mixed $value): ?string
    {
        if (is_string($value)) return $value;
        if (!is_array($value)) return null;
        return collect($value['content'] ?? [])->map(fn ($node) =>
            ($node['type'] ?? null) === 'text'
                ? ($node['text'] ?? '')
                : $this->adfText($node)
        )->filter(fn ($text) => is_string($text) && $text !== '')->implode("\n") ?: null;
    }

    private function scopes(mixed $scope): array
    {
        return collect(is_array($scope) ? $scope : (preg_split('/[\s,]+/', trim((string) $scope)) ?: []))
            ->filter()->values()->all();
    }

    private function issueUrl(array $c, string $key): ?string
    {
        return !empty($c['site_url']) ? rtrim($c['site_url'], '/') . '/browse/' . rawurlencode($key) : null;
    }

    private function apiMessage($response): string
    {
        $json = $response->json();
        return collect($json['errorMessages'] ?? [])->filter()->implode(' ') ?: 'les paramètres sont invalides.';
    }

    private function handleApiError(RequestException $e): never
    {
        $status = $e->response?->status();
        Log::error('MCP Jira: appel API échoué', ['status' => $status]);

        if ($status === 401) throw new AuthExpiredException('Session Jira expirée, reconnexion requise.');
        if ($status === 403) throw new PermissionDeniedException('L’utilisateur Jira n’a pas les droits nécessaires pour cette action.');
        throw new ConnectorUnavailableException('Jira est momentanément indisponible.');
    }
}
