<?php

namespace App\Domain\MCP\Connectors;

use App\Domain\MCP\Connectors\Concerns\RefreshesOAuthToken;
use App\Domain\MCP\Contracts\ToolResult;
use App\Domain\MCP\Contracts\ToolSchema;
use App\Domain\MCP\Exceptions\AuthExpiredException;
use App\Domain\MCP\Exceptions\ConnectorUnavailableException;
use App\Domain\MCP\Exceptions\ToolNotFoundException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;

/**
 * Connecteur Hootsuite (Member API v1), lecture + programmation de
 * publications sur les réseaux sociaux connectés au compte.
 * credentials attendus (déchiffrés) : { "access_token", "refresh_token", "expires_at" }
 *
 * ⚠️ schedule_message et delete_scheduled_message sont des actions
 * d'écriture à fort impact de réputation (publication publique au nom de la
 * marque) : toujours 'confirm' + confirm_actor 'admin', jamais un visiteur.
 * Contrairement à Google Ads/Meta Ads (impact financier), l'impact ici est
 * réputationnel/éditorial — même niveau de prudence, raison différente.
 * Publication immédiate non exposée volontairement : seule la
 * programmation (scheduled_send_time obligatoire) est permise, pour
 * garantir une fenêtre de relecture humaine même en cas d'erreur de
 * confirmation.
 */
class HootsuiteConnector extends AbstractConnector
{
    use RefreshesOAuthToken;

    private const TOKEN_URL = 'https://platform.hootsuite.com/oauth2/token';
    private const API_BASE = 'https://platform.hootsuite.com/v1/';

    public function slug(): string
    {
        return 'hootsuite';
    }

    public function authenticate(array $credentials): array
    {
        $expiresAt = $credentials['expires_at'] ?? null;

        if ($expiresAt && now()->timestamp < $expiresAt - 60) {
            return $credentials;
        }

        if (empty($credentials['refresh_token'])) {
            throw new AuthExpiredException('Refresh token Hootsuite absent, reconnexion OAuth requise.');
        }

        return $this->refreshOAuthToken(self::TOKEN_URL, [
            'client_id' => config('mcp.connectors.hootsuite.client_id'),
            'client_secret' => config('mcp.connectors.hootsuite.client_secret'),
            'refresh_token' => $credentials['refresh_token'],
            'grant_type' => 'refresh_token',
        ], $credentials);
    }

    public function listTools(): array
    {
        return [
            new ToolSchema('hootsuite', 'list_social_profiles',
                "Liste les profils de réseaux sociaux connectés à Hootsuite (Facebook, Instagram, X/Twitter, LinkedIn...) avec leur identifiant. Utiliser pour retrouver l'identifiant d'un profil avant de programmer une publication. Ne jamais inventer un identifiant de profil non retourné par cet outil.",
                ['type' => 'object', 'properties' => []], defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('hootsuite', 'list_scheduled_messages',
                "Liste les publications déjà programmées et pas encore publiées, avec leur date d'envoi prévue. Utiliser pour vérifier ce qui est déjà planifié avant d'en ajouter une nouvelle, ou pour retrouver l'identifiant d'une publication à annuler.",
                ['type' => 'object', 'properties' => ['limit' => ['type' => 'integer', 'description' => 'défaut 20, max 50']]],
                defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('hootsuite', 'schedule_message',
                "Programme une publication sur un ou plusieurs profils sociaux à une date/heure future précise. La publication n'est PAS immédiate : elle sera envoyée automatiquement à l'heure indiquée. Toujours vérifier le texte et l'heure avec l'utilisateur avant appel — aucune correction n'est possible après publication effective, seulement avant (via delete_scheduled_message).",
                ['type' => 'object', 'properties' => [
                    'social_profile_ids' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'un ou plusieurs identifiants de profils, voir list_social_profiles'],
                    'text' => ['type' => 'string'],
                    'scheduled_send_time' => ['type' => 'string', 'description' => 'ISO 8601, doit être dans le futur, ex: 2026-08-10T14:00:00Z'],
                ], 'required' => ['social_profile_ids', 'text', 'scheduled_send_time']],
                isWriteAction: true, defaultActorScope: 'admin', defaultMode: 'confirm', defaultConfirmActor: 'admin', capability: 'social.schedule_post'),

            new ToolSchema('hootsuite', 'delete_scheduled_message',
                "Annule une publication programmée identifiée de manière unique, avant son envoi effectif. Si l'identifiant est inconnu, utiliser list_scheduled_messages au préalable.",
                ['type' => 'object', 'properties' => ['message_id' => ['type' => 'string']], 'required' => ['message_id']],
                isWriteAction: true, defaultActorScope: 'admin', defaultMode: 'confirm', defaultConfirmActor: 'admin', capability: 'social.schedule_post'),
        ];
    }

    public function callTool(string $toolName, array $params, array $credentials, array $context = []): ToolResult
    {
        return match ($toolName) {
            'list_social_profiles' => $this->listSocialProfiles($credentials),
            'list_scheduled_messages' => $this->listScheduledMessages($params, $credentials),
            'schedule_message' => $this->scheduleMessage($params, $credentials),
            'delete_scheduled_message' => $this->deleteScheduledMessage($params, $credentials),
            default => throw new ToolNotFoundException("Outil '{$toolName}' inconnu pour hootsuite."),
        };
    }

    // ── Implémentation ──────────────────────────────────────────────

    private function listSocialProfiles(array $c): ToolResult
    {
        try {
            $response = $this->client($c)->get('socialProfiles');
        } catch (RequestException $e) {
            $this->handleApiError($e);
        }
        $this->recordSuccess();

        $profiles = collect($response->json('data', []))->map(fn ($p) => [
            'id' => $p['id'] ?? null, 'type' => $p['type'] ?? null, 'name' => $p['socialProfileUsername'] ?? ($p['id'] ?? null),
        ])->all();

        if (empty($profiles)) {
            return ToolResult::fail('not_found', 'Aucun profil social connecté à ce compte Hootsuite.');
        }
        return ToolResult::ok(['profiles' => $profiles], count($profiles) . ' profil(s) trouvé(s).');
    }

    private function listScheduledMessages(array $p, array $c): ToolResult
    {
        $limit = max(1, min(50, (int) ($p['limit'] ?? 20)));

        try {
            $response = $this->client($c)->get('messages', ['state' => 'SCHEDULED', 'limit' => $limit]);
        } catch (RequestException $e) {
            $this->handleApiError($e);
        }
        $this->recordSuccess();

        $messages = collect($response->json('data', []))->map(fn ($m) => [
            'id' => $m['id'] ?? null, 'text' => $m['text'] ?? null,
            'scheduled_send_time' => $m['scheduledSendTime'] ?? null,
            'social_profile_ids' => $m['socialProfileIds'] ?? [],
        ])->all();

        if (empty($messages)) {
            return ToolResult::fail('not_found', 'Aucune publication programmée actuellement.');
        }
        return ToolResult::ok(['scheduled_messages' => $messages], count($messages) . ' publication(s) programmée(s).');
    }

    private function scheduleMessage(array $p, array $c): ToolResult
    {
        try {
            $sendTime = new \DateTimeImmutable($p['scheduled_send_time']);
        } catch (\Exception) {
            return ToolResult::fail('invalid_date', "Format de date invalide pour scheduled_send_time (attendu: ISO 8601).");
        }
        if ($sendTime <= new \DateTimeImmutable('now')) {
            return ToolResult::fail('invalid_date', "La date de programmation doit être dans le futur.");
        }

        try {
            $response = $this->client($c)->post('messages', [
                'text' => $p['text'],
                'socialProfileIds' => $p['social_profile_ids'],
                'scheduledSendTime' => $sendTime->format(DATE_ATOM),
            ]);
        } catch (RequestException $e) {
            if ($e->response?->status() === 400) {
                return ToolResult::fail('invalid_request', "Publication refusée par Hootsuite — vérifiez les identifiants de profils et la longueur du texte pour le réseau ciblé.");
            }
            $this->handleApiError($e);
        }
        $this->recordSuccess();
        $messageId = $response->json('data.id');

        return ToolResult::ok(
            ['message_id' => $messageId, 'scheduled_send_time' => $sendTime->format(DATE_ATOM)],
            "Publication programmée pour le {$sendTime->format('d/m/Y à H:i')}."
        );
    }

    private function deleteScheduledMessage(array $p, array $c): ToolResult
    {
        try {
            $this->client($c)->delete("messages/{$p['message_id']}");
        } catch (RequestException $e) {
            if ($e->response?->status() === 404) {
                return ToolResult::fail('not_found', 'Publication programmée introuvable (peut-être déjà publiée ou déjà annulée).');
            }
            $this->handleApiError($e);
        }
        $this->recordSuccess();

        return ToolResult::ok(['message_id' => $p['message_id']], 'Publication programmée annulée.');
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
        Log::error('MCP Hootsuite: appel API échoué', ['status' => $status, 'body' => $body]);

        if (in_array($status, [401, 403])) {
            throw new AuthExpiredException('Accès Hootsuite refusé ou expiré, reconnexion requise.');
        }
        throw new ConnectorUnavailableException('Hootsuite indisponible: ' . $e->getMessage());
    }
}
