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
 * Connecteur monday.com via son API GraphQL v2.
 *
 * credentials attendus (déchiffrés) :
 * { access_token, refresh_token?, expires_at?, granted_scopes? }
 *
 * Le flux OAuth historique monday fournit un token sans expiration. Le
 * connecteur accepte également les credentials du nouveau flux OAuth 2.1
 * (refresh token + expires_at) lorsque celui-ci est activé côté application.
 */
class MondayConnector extends AbstractConnector
{
    private const API_BASE = 'https://api.monday.com/v2';

    public function slug(): string
    {
        return 'monday';
    }

    public function authenticate(array $credentials): array
    {
        if (empty($credentials['access_token'])) {
            throw new AuthExpiredException('Jeton monday.com manquant, reconnexion requise.');
        }

        $expiresAt = (int) ($credentials['expires_at'] ?? 0);
        if ($expiresAt === 0 || now()->timestamp < $expiresAt - 60) {
            return $credentials;
        }

        if (empty($credentials['refresh_token'])) {
            throw new AuthExpiredException('Session monday.com expirée, reconnexion requise.');
        }

        try {
            $response = Http::asJson()->post(config('mcp.connectors.monday.token_endpoint'), [
                'grant_type' => 'refresh_token',
                'client_id' => config('mcp.connectors.monday.client_id'),
                'client_secret' => config('mcp.connectors.monday.client_secret'),
                'refresh_token' => $credentials['refresh_token'],
            ])->throw();
        } catch (RequestException $e) {
            Log::warning('MCP monday: refresh token refusé', ['status' => $e->response?->status()]);
            throw new AuthExpiredException('Token monday.com invalide ou expiré, reconnexion requise.');
        }

        $data = $response->json();
        if (empty($data['access_token'])) {
            throw new AuthExpiredException('monday.com n’a pas retourné de token valide, reconnexion requise.');
        }

        $fresh = array_merge($credentials, [
            'access_token' => $data['access_token'],
            'expires_at' => now()->addSeconds((int) ($data['expires_in'] ?? 3600))->timestamp,
        ]);
        if (!empty($data['refresh_token'])) $fresh['refresh_token'] = $data['refresh_token'];
        if (!empty($data['scope'])) $fresh['granted_scopes'] = $this->scopes($data['scope']);

        return $fresh;
    }

    public function listTools(): array
    {
        return [
            new ToolSchema('monday', 'list_workspaces',
                "Liste les espaces de travail monday.com accessibles par l'utilisateur connecté.",
                ['type' => 'object', 'properties' => []], defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('monday', 'list_boards',
                "Liste les tableaux monday.com accessibles. Utiliser avant toute action ciblant un tableau dont l'identifiant n'est pas connu. Ne jamais inventer un board_id.",
                ['type' => 'object', 'properties' => [
                    'limit' => ['type' => 'integer', 'description' => 'nombre maximal de tableaux, de 1 à 100'],
                ]], defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('monday', 'list_items',
                "Liste les éléments d'un tableau monday.com identifié de manière unique. Utiliser pour retrouver un item avant de le modifier ou de le commenter.",
                ['type' => 'object', 'properties' => [
                    'board_id' => ['type' => 'string', 'description' => 'défaut: tableau configuré pour ce site'],
                    'limit' => ['type' => 'integer', 'description' => 'nombre maximal d’éléments, de 1 à 100'],
                ]], defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('monday', 'get_item',
                "Récupère le détail d'un élément monday.com identifié de manière unique par son ID. Ne jamais inventer un item_id.",
                ['type' => 'object', 'properties' => ['item_id' => ['type' => 'string']], 'required' => ['item_id']],
                defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('monday', 'create_item',
                "Crée un élément dans un tableau monday.com. Vérifier le board_id et éviter les doublons en consultant list_items si nécessaire. Les column_values doivent respecter les IDs et formats du tableau.",
                ['type' => 'object', 'properties' => [
                    'board_id' => ['type' => 'string', 'description' => 'défaut: tableau configuré pour ce site'],
                    'item_name' => ['type' => 'string'],
                    'group_id' => ['type' => 'string', 'description' => 'identifiant du groupe cible, optionnel'],
                    'column_values' => ['type' => 'object', 'description' => 'objet JSON indexé par les IDs de colonnes, optionnel'],
                ], 'required' => ['item_name']],
                isWriteAction: true, defaultActorScope: 'admin', defaultMode: 'auto', capability: 'tasks.create'),

            new ToolSchema('monday', 'update_item',
                "Met à jour les valeurs de colonnes d'un élément monday.com existant. Les IDs de colonnes et les valeurs doivent être connus, notamment après consultation des informations du tableau.",
                ['type' => 'object', 'properties' => [
                    'board_id' => ['type' => 'string', 'description' => 'défaut: tableau configuré pour ce site'],
                    'item_id' => ['type' => 'string'],
                    'column_values' => ['type' => 'object', 'description' => 'objet JSON indexé par les IDs de colonnes'],
                ], 'required' => ['item_id', 'column_values']],
                isWriteAction: true, defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('monday', 'add_update',
                "Ajoute une mise à jour (commentaire) à un élément monday.com identifié de manière unique.",
                ['type' => 'object', 'properties' => [
                    'item_id' => ['type' => 'string'], 'body' => ['type' => 'string'],
                ], 'required' => ['item_id', 'body']],
                isWriteAction: true, defaultActorScope: 'admin', defaultMode: 'auto'),
        ];
    }

    public function callTool(string $toolName, array $params, array $credentials, array $context = []): ToolResult
    {
        return match ($toolName) {
            'list_workspaces' => $this->listWorkspaces($credentials),
            'list_boards' => $this->listBoards($params, $credentials),
            'list_items' => $this->listItems($params, $credentials),
            'get_item' => $this->getItem($params, $credentials),
            'create_item' => $this->createItem($params, $credentials),
            'update_item' => $this->updateItem($params, $credentials),
            'add_update' => $this->addUpdate($params, $credentials),
            default => throw new ToolNotFoundException("Outil '{$toolName}' inconnu pour monday."),
        };
    }

    private function listWorkspaces(array $c): ToolResult
    {
        $result = $this->graphql('query ($limit: Int!) { workspaces(limit: $limit) { id name kind } }', ['limit' => 100], $c);
        $workspaces = $result['workspaces'] ?? [];

        return empty($workspaces)
            ? ToolResult::fail('not_found', 'Aucun espace de travail monday.com accessible.')
            : ToolResult::ok(['workspaces' => $workspaces], count($workspaces) . ' espace(s) monday.com trouvé(s).');
    }

    private function listBoards(array $p, array $c): ToolResult
    {
        $result = $this->graphql('query ($limit: Int!) { boards(limit: $limit) { id name state } }', [
            'limit' => min(100, max(1, (int) ($p['limit'] ?? 50))),
        ], $c);
        $boards = $result['boards'] ?? [];

        return empty($boards)
            ? ToolResult::fail('not_found', 'Aucun tableau monday.com accessible.')
            : ToolResult::ok(['boards' => $boards], count($boards) . ' tableau(x) monday.com trouvé(s).');
    }

    private function listItems(array $p, array $c): ToolResult
    {
        $boardId = (string) ($p['board_id'] ?? $c['default_board_id'] ?? '');
        if ($boardId === '') return ToolResult::fail('not_configured', 'Aucun board_id fourni et aucun tableau monday.com par défaut configuré.');

        $result = $this->graphql(<<<'GRAPHQL'
query ($boardId: ID!, $limit: Int!) {
  boards(ids: [$boardId]) {
    id
    name
    items_page(limit: $limit) {
      cursor
      items { id name url group { id title } column_values { id text } }
    }
  }
}
GRAPHQL, ['boardId' => $boardId, 'limit' => min(100, max(1, (int) ($p['limit'] ?? 50)))], $c);

        $board = $result['boards'][0] ?? null;
        if (!$board) return ToolResult::fail('not_found', 'Tableau monday.com introuvable.');
        $items = $board['items_page']['items'] ?? [];

        return empty($items)
            ? ToolResult::fail('not_found', 'Aucun élément dans ce tableau monday.com.')
            : ToolResult::ok(['board' => ['id' => $board['id'], 'name' => $board['name']], 'items' => $items], count($items) . ' élément(s) trouvé(s).');
    }

    private function getItem(array $p, array $c): ToolResult
    {
        $result = $this->graphql(<<<'GRAPHQL'
query ($itemId: ID!) {
  items(ids: [$itemId]) {
    id
    name
    url
    board { id name }
    group { id title }
    column_values { id text value }
  }
}
GRAPHQL, ['itemId' => (string) $p['item_id']], $c);

        $item = $result['items'][0] ?? null;
        return !$item
            ? ToolResult::fail('not_found', 'Élément monday.com introuvable.')
            : ToolResult::ok(['item' => $item], 'Élément monday.com récupéré.');
    }

    private function createItem(array $p, array $c): ToolResult
    {
        $boardId = (string) ($p['board_id'] ?? $c['default_board_id'] ?? '');
        $name = trim((string) ($p['item_name'] ?? ''));
        if ($boardId === '' || $name === '') return ToolResult::fail('invalid_request', 'board_id et item_name sont obligatoires pour créer un élément monday.com.');

        try {
            $columnValues = array_key_exists('column_values', $p)
                ? $this->columnValues($p['column_values'])
                : null;
        } catch (\JsonException) {
            return ToolResult::fail('invalid_request', 'column_values doit être un objet JSON valide.');
        }

        $variables = [
            'boardId' => $boardId,
            'itemName' => $name,
            'groupId' => $p['group_id'] ?? null,
            'columnValues' => $columnValues,
        ];
        try {
            $columnValues = $this->columnValues($p['column_values']);
        } catch (\JsonException) {
            return ToolResult::fail('invalid_request', 'column_values doit être un objet JSON valide.');
        }

        $result = $this->graphql(<<<'GRAPHQL'
mutation ($boardId: ID!, $itemName: String!, $groupId: String, $columnValues: JSON) {
  create_item(board_id: $boardId, item_name: $itemName, group_id: $groupId, column_values: $columnValues) {
    id
    name
    url
  }
}
GRAPHQL, $variables, $c);

        $item = $result['create_item'] ?? null;
        return !$item
            ? ToolResult::fail('invalid_request', 'monday.com n’a pas créé l’élément.')
            : ToolResult::ok(['item' => $item], "Élément monday.com « {$name} » créé.");
    }

    private function updateItem(array $p, array $c): ToolResult
    {
        $boardId = (string) ($p['board_id'] ?? $c['default_board_id'] ?? '');
        if ($boardId === '') return ToolResult::fail('not_configured', 'Aucun board_id fourni et aucun tableau monday.com par défaut configuré.');

        $result = $this->graphql(<<<'GRAPHQL'
mutation ($boardId: ID!, $itemId: ID!, $columnValues: JSON!) {
  change_multiple_column_values(board_id: $boardId, item_id: $itemId, column_values: $columnValues) {
    id
    name
  }
}
GRAPHQL, [
            'boardId' => $boardId,
            'itemId' => (string) $p['item_id'],
            'columnValues' => $columnValues,
        ], $c);

        $item = $result['change_multiple_column_values'] ?? null;
        return !$item
            ? ToolResult::fail('not_found', 'Élément monday.com introuvable ou mise à jour refusée.')
            : ToolResult::ok(['item' => $item], 'Élément monday.com mis à jour.');
    }

    private function addUpdate(array $p, array $c): ToolResult
    {
        $result = $this->graphql(<<<'GRAPHQL'
mutation ($itemId: ID!, $body: String!) {
  create_update(item_id: $itemId, body: $body) { id body }
}
GRAPHQL, ['itemId' => (string) $p['item_id'], 'body' => (string) $p['body']], $c);

        $update = $result['create_update'] ?? null;
        return !$update
            ? ToolResult::fail('not_found', 'Élément monday.com introuvable ou commentaire refusé.')
            : ToolResult::ok(['update_id' => $update['id'] ?? null, 'item_id' => $p['item_id']], 'Mise à jour monday.com ajoutée.');
    }

    private function graphql(string $query, array $variables, array $credentials): array
    {
        try {
            $response = $this->http()->withToken($credentials['access_token'])->acceptJson()->post(self::API_BASE, [
                'query' => $query,
                'variables' => $variables,
            ]);
            $response->throw();
        } catch (RequestException $e) {
            $this->handleApiError($e);
        }

        $payload = $response->json();
        if (!empty($payload['errors'])) {
            $message = collect($payload['errors'])->pluck('message')->filter()->implode(' ');
            throw new ConnectorUnavailableException('monday.com a refusé la requête : ' . ($message ?: 'erreur GraphQL.'));
        }

        $this->recordSuccess();
        return $payload['data'] ?? [];
    }

    private function columnValues(mixed $values): string
    {
        if (is_string($values)) {
            json_decode($values, true, 512, JSON_THROW_ON_ERROR);
            return $values;
        }

        return json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    private function scopes(mixed $scope): array
    {
        return collect(is_array($scope) ? $scope : (preg_split('/[\s,]+/', trim((string) $scope)) ?: []))
            ->filter()->values()->all();
    }

    private function handleApiError(RequestException $e): never
    {
        $status = $e->response?->status();
        Log::error('MCP monday: appel API échoué', ['status' => $status]);

        if ($status === 401) throw new AuthExpiredException('Session monday.com expirée, reconnexion requise.');
        if ($status === 403) throw new PermissionDeniedException('L’utilisateur monday.com n’a pas les droits nécessaires.');
        throw new ConnectorUnavailableException('monday.com est momentanément indisponible.');
    }
}
