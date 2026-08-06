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
 * Connecteur Buffer (API v1), lecture + programmation de publications.
 * credentials attendus (déchiffrés) : { "access_token" }
 *
 * Particularité : contrairement à Google/Meta/Hootsuite, les jetons d'accès
 * Buffer (API v1, OAuth2) n'expirent pas et Buffer ne documente pas de
 * refresh_token — authenticate() ne fait donc que vérifier la présence du
 * jeton, jamais de rafraîchissement. Une révocation (déconnexion côté
 * Buffer par le client) se traduit directement par un 401, traité comme
 * n'importe quelle expiration.
 *
 * Mêmes garde-fous éditoriaux que Hootsuite : programmation uniquement
 * (jamais de publication immédiate exposée), écriture toujours 'confirm' +
 * confirm_actor 'admin'.
 */
class BufferConnector extends AbstractConnector
{
    private const API_BASE = 'https://api.bufferapp.com/1/';

    public function slug(): string
    {
        return 'buffer';
    }

    public function authenticate(array $credentials): array
    {
        if (empty($credentials['access_token'])) {
            throw new AuthExpiredException('Jeton Buffer manquant, reconnexion requise.');
        }
        return $credentials;
    }

    public function listTools(): array
    {
        return [
            new ToolSchema('buffer', 'list_profiles',
                "Liste les profils de réseaux sociaux connectés à Buffer avec leur identifiant et leur service (Facebook, Instagram, X/Twitter, LinkedIn...). Utiliser pour retrouver l'identifiant d'un profil avant de programmer une publication. Ne jamais inventer un identifiant de profil non retourné par cet outil.",
                ['type' => 'object', 'properties' => []], defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('buffer', 'list_pending_updates',
                "Liste les publications en file d'attente (pas encore envoyées) pour un profil donné. Utiliser pour vérifier ce qui est déjà planifié, ou pour retrouver l'identifiant d'une publication à supprimer.",
                ['type' => 'object', 'properties' => ['profile_id' => ['type' => 'string']], 'required' => ['profile_id']],
                defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('buffer', 'schedule_update',
                "Ajoute une publication à la file d'attente Buffer d'un ou plusieurs profils, envoyée automatiquement au prochain créneau programmé (ou à une heure précise si fournie). Toujours vérifier le texte avec l'utilisateur avant appel — la correction n'est possible qu'avant l'envoi effectif, via delete_update.",
                ['type' => 'object', 'properties' => [
                    'profile_ids' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'un ou plusieurs identifiants de profils, voir list_profiles'],
                    'text' => ['type' => 'string'],
                    'scheduled_at' => ['type' => 'string', 'description' => "optionnel, ISO 8601 dans le futur ; si absent, utilise le prochain créneau programmé de Buffer"],
                ], 'required' => ['profile_ids', 'text']],
                isWriteAction: true, defaultActorScope: 'admin', defaultMode: 'confirm', defaultConfirmActor: 'admin', capability: 'social.schedule_post'),

            new ToolSchema('buffer', 'get_update_analytics',
                "Retourne les statistiques d'engagement (likes, partages, commentaires, clics selon le réseau) d'une publication déjà envoyée. Utiliser uniquement pour une publication déjà publiée, jamais une publication encore en file d'attente.",
                ['type' => 'object', 'properties' => ['update_id' => ['type' => 'string']], 'required' => ['update_id']],
                defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('buffer', 'delete_update',
                "Supprime une publication de la file d'attente Buffer avant son envoi effectif, identifiée de manière unique. Si l'identifiant est inconnu, utiliser list_pending_updates au préalable.",
                ['type' => 'object', 'properties' => ['update_id' => ['type' => 'string']], 'required' => ['update_id']],
                isWriteAction: true, defaultActorScope: 'admin', defaultMode: 'confirm', defaultConfirmActor: 'admin', capability: 'social.schedule_post'),
        ];
    }

    public function callTool(string $toolName, array $params, array $credentials, array $context = []): ToolResult
    {
        return match ($toolName) {
            'list_profiles' => $this->listProfiles($credentials),
            'list_pending_updates' => $this->listPendingUpdates($params, $credentials),
            'schedule_update' => $this->scheduleUpdate($params, $credentials),
            'get_update_analytics' => $this->updateAnalytics($params, $credentials),
            'delete_update' => $this->deleteUpdate($params, $credentials),
            default => throw new ToolNotFoundException("Outil '{$toolName}' inconnu pour buffer."),
        };
    }

    // ── Implémentation ──────────────────────────────────────────────

    private function listProfiles(array $c): ToolResult
    {
        try {
            $response = $this->client($c)->get('profiles.json');
        } catch (RequestException $e) {
            $this->handleApiError($e);
        }
        $this->recordSuccess();

        $profiles = collect($response->json())->map(fn ($p) => [
            'id' => $p['id'] ?? null, 'service' => $p['service'] ?? null,
            'username' => $p['formatted_username'] ?? $p['service_username'] ?? null,
        ])->all();

        if (empty($profiles)) {
            return ToolResult::fail('not_found', 'Aucun profil social connecté à ce compte Buffer.');
        }
        return ToolResult::ok(['profiles' => $profiles], count($profiles) . ' profil(s) trouvé(s).');
    }

    private function listPendingUpdates(array $p, array $c): ToolResult
    {
        try {
            $response = $this->client($c)->get("profiles/{$p['profile_id']}/updates/pending.json");
        } catch (RequestException $e) {
            if ($e->response?->status() === 400) {
                return ToolResult::fail('not_found', 'Profil introuvable.');
            }
            $this->handleApiError($e);
        }
        $this->recordSuccess();

        $updates = collect($response->json('updates', []))->map(fn ($u) => [
            'id' => $u['id'] ?? null, 'text' => $u['text'] ?? null, 'scheduled_at' => $u['scheduled_at'] ?? null,
        ])->all();

        if (empty($updates)) {
            return ToolResult::fail('not_found', 'Aucune publication en attente pour ce profil.');
        }
        return ToolResult::ok(['pending_updates' => $updates], count($updates) . ' publication(s) en attente.');
    }

    private function scheduleUpdate(array $p, array $c): ToolResult
    {
        $body = ['text' => $p['text'], 'profile_ids' => $p['profile_ids']];

        if (!empty($p['scheduled_at'])) {
            try {
                $sendTime = new \DateTimeImmutable($p['scheduled_at']);
            } catch (\Exception) {
                return ToolResult::fail('invalid_date', "Format de date invalide pour scheduled_at (attendu: ISO 8601).");
            }
            if ($sendTime <= new \DateTimeImmutable('now')) {
                return ToolResult::fail('invalid_date', "La date de programmation doit être dans le futur.");
            }
            $body['scheduled_at'] = $sendTime->getTimestamp();
        } else {
            $body['top'] = 'false'; // ajoute au prochain créneau disponible, pas en tête de file
        }

        try {
            $response = $this->client($c)->asForm()->post('updates/create.json', $body);
        } catch (RequestException $e) {
            if ($e->response?->status() === 400) {
                return ToolResult::fail('invalid_request', "Publication refusée par Buffer — vérifiez les identifiants de profils et la longueur du texte.");
            }
            $this->handleApiError($e);
        }
        $this->recordSuccess();
        $update = $response->json('updates.0', []);

        return ToolResult::ok(
            ['update_id' => $update['id'] ?? null, 'scheduled_at' => $update['scheduled_at'] ?? null],
            'Publication ajoutée à la file Buffer.'
        );
    }

    private function updateAnalytics(array $p, array $c): ToolResult
    {
        try {
            $response = $this->client($c)->get("updates/{$p['update_id']}.json");
        } catch (RequestException $e) {
            if ($e->response?->status() === 400) {
                return ToolResult::fail('not_found', 'Publication introuvable.');
            }
            $this->handleApiError($e);
        }
        $this->recordSuccess();
        $r = $response->json();

        if (($r['status'] ?? null) !== 'sent') {
            return ToolResult::fail('not_sent', "Cette publication n'a pas encore été envoyée, aucune statistique disponible.");
        }

        $stats = $r['statistics'] ?? [];
        return ToolResult::ok([
            'update_id' => $p['update_id'],
            'clicks' => $stats['clicks'] ?? 0, 'likes' => $stats['favorites'] ?? 0,
            'shares' => $stats['reach'] ?? 0, 'comments' => $stats['comments'] ?? 0,
        ], 'Statistiques de publication récupérées.');
    }

    private function deleteUpdate(array $p, array $c): ToolResult
    {
        try {
            $this->client($c)->post("updates/{$p['update_id']}/destroy.json");
        } catch (RequestException $e) {
            if ($e->response?->status() === 400) {
                return ToolResult::fail('not_found', 'Publication introuvable (peut-être déjà publiée ou déjà supprimée).');
            }
            $this->handleApiError($e);
        }
        $this->recordSuccess();

        return ToolResult::ok(['update_id' => $p['update_id']], 'Publication supprimée de la file.');
    }

    // ── Utilitaires ──────────────────────────────────────────────────

    private function client(array $credentials)
    {
        return $this->http(self::API_BASE)->withToken($credentials['access_token']);
    }

    private function handleApiError(RequestException $e): never
    {
        $status = $e->response?->status();
        $body = $e->response?->body();
        Log::error('MCP Buffer: appel API échoué', ['status' => $status, 'body' => $body]);

        if (in_array($status, [401, 403])) {
            throw new AuthExpiredException('Accès Buffer refusé ou expiré, reconnexion requise.');
        }
        throw new ConnectorUnavailableException('Buffer indisponible: ' . $e->getMessage());
    }
}
