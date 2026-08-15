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
 * Connecteur Trello (API REST v1), gestion de cartes.
 * credentials attendus (déchiffrés) : { "api_key": "...", "token": "..." }
 * Particularité Trello : contrairement à tous les autres connecteurs
 * api_key déjà en place (un seul secret), Trello exige DEUX valeurs
 * distinctes (clé API du compte développeur + jeton d'autorisation membre),
 * passées en paramètres de requête sur CHAQUE appel — jamais en en-tête
 * Authorization. Les deux sont générées depuis trello.com/app-key (la clé)
 * puis via le lien "Token" généré sur la même page (autorisation membre).
 * settings attendus (optionnel) : { "default_board_id": "..." }
 *
 * Mêmes conventions que ClickUp : outils de pilotage interne, toujours
 * actor_scope 'admin', écritures en defaultMode 'auto' (pas d'impact
 * financier/réputationnel justifiant une confirmation).
 */
class TrelloConnector extends AbstractConnector
{
    private const API_BASE = 'https://api.trello.com/1/';

    public function slug(): string
    {
        return 'trello';
    }

    public function authenticate(array $credentials): array
    {
        if (empty($credentials['api_key']) || empty($credentials['token'])) {
            throw new AuthExpiredException('Clé API ou jeton Trello manquant.');
        }
        return $credentials; // pas de rafraîchissement : identifiants statiques
    }

    public function listTools(): array
    {
        return [
            new ToolSchema('trello', 'list_boards',
                "Liste les tableaux Trello accessibles avec ces identifiants. Utiliser en premier lieu si aucun tableau n'est encore connu, avant list_lists.",
                ['type' => 'object', 'properties' => []], defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('trello', 'list_lists',
                "Liste les colonnes (listes) d'un tableau Trello identifié de manière unique. Utiliser pour retrouver l'identifiant d'une colonne (ex: « À faire », « En cours », « Terminé ») avant de créer ou déplacer une carte.",
                ['type' => 'object', 'properties' => ['board_id' => ['type' => 'string', 'description' => 'défaut: tableau configuré par défaut']]],
                defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('trello', 'list_cards',
                "Liste les cartes d'une colonne Trello (list_id) identifiée de manière unique. Utiliser pour retrouver l'identifiant d'une carte nommée, ou pour un état des lieux d'une colonne.",
                ['type' => 'object', 'properties' => ['list_id' => ['type' => 'string']], 'required' => ['list_id']],
                defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('trello', 'get_card',
                "Récupère le détail d'une carte Trello identifiée de manière unique : description, échéance, membres assignés, colonne actuelle. Si l'identifiant est inconnu, utiliser list_cards au préalable. Ne jamais inventer un identifiant de carte.",
                ['type' => 'object', 'properties' => ['card_id' => ['type' => 'string']], 'required' => ['card_id']],
                defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('trello', 'create_card',
                "Crée une nouvelle carte dans une colonne Trello identifiée de manière unique. Vérifier qu'un nom clair et la colonne cible sont fournis avant création. Si une carte très similaire semble déjà exister dans cette colonne, utiliser list_cards avant de créer un doublon.",
                ['type' => 'object', 'properties' => [
                    'list_id' => ['type' => 'string'], 'name' => ['type' => 'string'], 'description' => ['type' => 'string'],
                    'due_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD, optionnel'],
                ], 'required' => ['list_id', 'name']],
                isWriteAction: true, defaultActorScope: 'admin', defaultMode: 'auto', capability: 'tasks.create'),

            new ToolSchema('trello', 'move_card',
                "Déplace une carte Trello existante identifiée de manière unique vers une autre colonne (ex: de « En cours » à « Terminé »). Si l'identifiant de la colonne cible est inconnu, utiliser list_lists au préalable. Ne jamais deviner un identifiant de colonne.",
                ['type' => 'object', 'properties' => ['card_id' => ['type' => 'string'], 'list_id' => ['type' => 'string']], 'required' => ['card_id', 'list_id']],
                isWriteAction: true, defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('trello', 'add_comment',
                "Ajoute un commentaire à une carte Trello existante identifiée de manière unique. Utiliser pour laisser une note ou une mise à jour sans modifier les champs structurés de la carte.",
                ['type' => 'object', 'properties' => ['card_id' => ['type' => 'string'], 'comment' => ['type' => 'string']], 'required' => ['card_id', 'comment']],
                isWriteAction: true, defaultActorScope: 'admin', defaultMode: 'auto'),
        ];
    }

    public function callTool(string $toolName, array $params, array $credentials, array $context = []): ToolResult
    {
        return match ($toolName) {
            'list_boards' => $this->listBoards($credentials),
            'list_lists' => $this->listLists($params, $credentials),
            'list_cards' => $this->listCards($params, $credentials),
            'get_card' => $this->getCard($params, $credentials),
            'create_card' => $this->createCard($params, $credentials),
            'move_card' => $this->moveCard($params, $credentials),
            'add_comment' => $this->addComment($params, $credentials),
            default => throw new ToolNotFoundException("Outil '{$toolName}' inconnu pour trello."),
        };
    }

    // ── Implémentation ──────────────────────────────────────────────

    private function listBoards(array $c): ToolResult
    {
        try {
            $response = $this->client($c)->get('members/me/boards', ['fields' => 'id,name,closed']);
        } catch (RequestException $e) {
            $this->handleApiError($e);
        }
        $this->recordSuccess();

        $boards = collect($response->json())->reject(fn ($b) => $b['closed'] ?? false)
            ->map(fn ($b) => ['id' => $b['id'] ?? null, 'name' => $b['name'] ?? null])->values()->all();

        if (empty($boards)) {
            return ToolResult::fail('not_found', 'Aucun tableau accessible avec ces identifiants.');
        }
        return ToolResult::ok(['boards' => $boards], count($boards) . ' tableau(x) trouvé(s).');
    }

    private function listLists(array $p, array $c): ToolResult
    {
        $boardId = $this->boardId($p, $c);
        if (!$boardId) {
            return ToolResult::fail('not_configured', 'Aucun tableau précisé et aucun tableau par défaut configuré pour ce site.');
        }

        try {
            $response = $this->client($c)->get("boards/{$boardId}/lists", ['fields' => 'id,name']);
        } catch (RequestException $e) {
            if ($e->response?->status() === 404) {
                return ToolResult::fail('not_found', 'Tableau introuvable.');
            }
            $this->handleApiError($e);
        }
        $this->recordSuccess();

        $lists = collect($response->json())->map(fn ($l) => ['id' => $l['id'] ?? null, 'name' => $l['name'] ?? null])->all();

        if (empty($lists)) {
            return ToolResult::fail('not_found', 'Aucune colonne trouvée sur ce tableau.');
        }
        return ToolResult::ok(['lists' => $lists], count($lists) . ' colonne(s) trouvée(s).');
    }

    private function listCards(array $p, array $c): ToolResult
    {
        try {
            $response = $this->client($c)->get("lists/{$p['list_id']}/cards", ['fields' => 'id,name,due,dateLastActivity']);
        } catch (RequestException $e) {
            if ($e->response?->status() === 404) {
                return ToolResult::fail('not_found', 'Colonne introuvable.');
            }
            $this->handleApiError($e);
        }
        $this->recordSuccess();

        $cards = collect($response->json())->map(fn ($cd) => [
            'id' => $cd['id'] ?? null, 'name' => $cd['name'] ?? null, 'due' => $cd['due'] ?? null,
        ])->all();

        if (empty($cards)) {
            return ToolResult::fail('not_found', 'Aucune carte trouvée dans cette colonne.');
        }
        return ToolResult::ok(['cards' => $cards], count($cards) . ' carte(s) trouvée(s).');
    }

    private function getCard(array $p, array $c): ToolResult
    {
        try {
            $response = $this->client($c)->get("cards/{$p['card_id']}", [
                'fields' => 'id,name,desc,due,idList,closed', 'members' => 'true', 'member_fields' => 'fullName',
            ]);
        } catch (RequestException $e) {
            if ($e->response?->status() === 404) {
                return ToolResult::fail('not_found', 'Carte introuvable.');
            }
            $this->handleApiError($e);
        }
        $this->recordSuccess();
        $card = $response->json();

        return ToolResult::ok([
            'id' => $card['id'] ?? null, 'name' => $card['name'] ?? null, 'description' => $card['desc'] ?? null,
            'due' => $card['due'] ?? null, 'list_id' => $card['idList'] ?? null,
            'members' => collect($card['members'] ?? [])->pluck('fullName')->all(),
        ], 'Carte récupérée.');
    }

    private function createCard(array $p, array $c): ToolResult
    {
        $body = array_filter([
            'idList' => $p['list_id'], 'name' => $p['name'], 'desc' => $p['description'] ?? null,
        ], fn ($v) => $v !== null);

        if (!empty($p['due_date'])) {
            try {
                $body['due'] = (new \DateTimeImmutable($p['due_date']))->format(DATE_ATOM);
            } catch (\Exception) {
                return ToolResult::fail('invalid_date', "Format de date invalide pour due_date (attendu: YYYY-MM-DD).");
            }
        }

        try {
            $response = $this->client($c)->post('cards', $body);
        } catch (RequestException $e) {
            if ($e->response?->status() === 400) {
                return ToolResult::fail('not_found', "Colonne introuvable ou identifiant invalide.");
            }
            $this->handleApiError($e);
        }
        $this->recordSuccess();
        $cardId = $response->json('id');

        return ToolResult::ok(['card_id' => $cardId, 'name' => $p['name']], "Carte « {$p['name']} » créée.");
    }

    private function moveCard(array $p, array $c): ToolResult
    {
        try {
            $this->client($c)->put("cards/{$p['card_id']}", ['idList' => $p['list_id']]);
        } catch (RequestException $e) {
            if ($e->response?->status() === 404) {
                return ToolResult::fail('not_found', 'Carte ou colonne cible introuvable.');
            }
            $this->handleApiError($e);
        }
        $this->recordSuccess();

        return ToolResult::ok(['card_id' => $p['card_id'], 'list_id' => $p['list_id']], 'Carte déplacée.');
    }

    private function addComment(array $p, array $c): ToolResult
    {
        try {
            $this->client($c)->post("cards/{$p['card_id']}/actions/comments", ['text' => $p['comment']]);
        } catch (RequestException $e) {
            if ($e->response?->status() === 404) {
                return ToolResult::fail('not_found', 'Carte introuvable.');
            }
            $this->handleApiError($e);
        }
        $this->recordSuccess();

        return ToolResult::ok(['card_id' => $p['card_id']], 'Commentaire ajouté.');
    }

    // ── Utilitaires ──────────────────────────────────────────────────

    private function boardId(array $p, array $c): ?string
    {
        return $p['board_id'] ?? $c['default_board_id'] ?? null;
    }

    /**
     * Trello authentifie via des paramètres de requête (key/token), jamais
     * un en-tête — withQueryParameters les rattache à chaque appel suivant
     * (get/post/put), quels que soient les autres paramètres passés.
     */
    private function client(array $credentials)
    {
        return $this->http(self::API_BASE)->withQueryParameters([
            'key' => $credentials['api_key'], 'token' => $credentials['token'],
        ]);
    }

    private function handleApiError(RequestException $e): never
    {
        $status = $e->response?->status();
        $body = $e->response?->body();
        Log::error('MCP Trello: appel API échoué', ['status' => $status, 'body' => $body]);

        if (in_array($status, [401, 403])) {
            throw new AuthExpiredException('Clé API ou jeton Trello invalide ou révoqué.');
        }
        throw new ConnectorUnavailableException('Trello indisponible: ' . $e->getMessage());
    }
}
