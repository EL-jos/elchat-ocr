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
 * Connecteur Klaviyo (API v2024-10-15, format JSON:API), lecture +
 * inscription à une liste.
 * credentials attendus (déchiffrés) : { "api_key": "pk_..." } — clé API
 * privée Klaviyo (Settings > API Keys), jamais la clé publique "site".
 * settings attendus (optionnel) : { "default_list_id": "..." }
 *
 * Comme pour Mailchimp, une seule écriture : subscribe_profile, toujours
 * avec consent explicite ('SUBSCRIBED' n'est demandé qu'après action
 * volontaire de la personne ; Klaviyo applique lui-même son propre
 * processus de double opt-in selon la configuration de la liste).
 */
class KlaviyoConnector extends AbstractConnector
{
    private const API_BASE = 'https://a.klaviyo.com/api/';
    private const REVISION = '2024-10-15';

    public function slug(): string
    {
        return 'klaviyo';
    }

    public function authenticate(array $credentials): array
    {
        if (empty($credentials['api_key'])) {
            throw new AuthExpiredException('Clé API Klaviyo manquante.');
        }
        return $credentials;
    }

    public function listTools(): array
    {
        return [
            new ToolSchema('klaviyo', 'list_lists',
                "Liste les listes de contacts du compte Klaviyo. Utiliser pour retrouver l'identifiant d'une liste nommée avant d'y inscrire quelqu'un.",
                ['type' => 'object', 'properties' => []], defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('klaviyo', 'get_profile',
                "Recherche un profil Klaviyo par adresse email et retourne ses informations et son statut d'abonnement email. Utiliser pour vérifier si une personne est déjà connue avant de l'inscrire.",
                ['type' => 'object', 'properties' => ['email' => ['type' => 'string']], 'required' => ['email']],
                defaultMode: 'auto'),

            new ToolSchema('klaviyo', 'subscribe_profile',
                "Inscrit une adresse email à une liste Klaviyo avec consentement marketing email. Ne jamais appeler sans que la personne ait explicitement exprimé le souhait de s'inscrire.",
                ['type' => 'object', 'properties' => [
                    'email' => ['type' => 'string'], 'list_id' => ['type' => 'string', 'description' => 'défaut: liste configurée par défaut'],
                    'first_name' => ['type' => 'string'], 'last_name' => ['type' => 'string'],
                ], 'required' => ['email']],
                isWriteAction: true, defaultActorScope: 'visitor', defaultMode: 'confirm', defaultConfirmActor: 'visitor', capability: 'marketing.subscribe'),

            new ToolSchema('klaviyo', 'list_campaigns',
                "Liste les campagnes email récentes avec leur statut. Utiliser pour retrouver l'identifiant d'une campagne avant de consulter ses statistiques.",
                ['type' => 'object', 'properties' => ['limit' => ['type' => 'integer', 'description' => 'défaut 10, max 50']]],
                defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('klaviyo', 'get_campaign_performance',
                "Retourne les statistiques agrégées d'une campagne email : taux d'ouverture, taux de clic, désabonnements. Utiliser uniquement pour une campagne déjà envoyée.",
                ['type' => 'object', 'properties' => ['campaign_id' => ['type' => 'string']], 'required' => ['campaign_id']],
                defaultActorScope: 'admin', defaultMode: 'auto'),
        ];
    }

    public function callTool(string $toolName, array $params, array $credentials, array $context = []): ToolResult
    {
        return match ($toolName) {
            'list_lists' => $this->listLists($credentials),
            'get_profile' => $this->getProfile($params, $credentials),
            'subscribe_profile' => $this->subscribeProfile($params, $credentials),
            'list_campaigns' => $this->listCampaigns($params, $credentials),
            'get_campaign_performance' => $this->campaignPerformance($params, $credentials),
            default => throw new ToolNotFoundException("Outil '{$toolName}' inconnu pour klaviyo."),
        };
    }

    // ── Implémentation ──────────────────────────────────────────────

    private function listLists(array $c): ToolResult
    {
        try {
            $response = $this->client($c)->get('lists');
        } catch (RequestException $e) {
            $this->handleApiError($e);
        }
        $this->recordSuccess();

        $lists = collect($response->json('data', []))->map(fn ($l) => [
            'id' => $l['id'] ?? null, 'name' => $l['attributes']['name'] ?? null,
        ])->all();

        if (empty($lists)) {
            return ToolResult::fail('not_found', 'Aucune liste trouvée sur ce compte Klaviyo.');
        }
        return ToolResult::ok(['lists' => $lists], count($lists) . ' liste(s) trouvée(s).');
    }

    private function getProfile(array $p, array $c): ToolResult
    {
        try {
            $response = $this->client($c)->get('profiles', ['filter' => 'equals(email,"' . $p['email'] . '")']);
        } catch (RequestException $e) {
            $this->handleApiError($e);
        }
        $this->recordSuccess();
        $profile = $response->json('data.0');

        if (!$profile) {
            return ToolResult::fail('not_found', "Aucun profil trouvé pour {$p['email']}.");
        }

        return ToolResult::ok([
            'email' => $profile['attributes']['email'] ?? $p['email'],
            'first_name' => $profile['attributes']['first_name'] ?? null,
            'subscription_status' => $profile['attributes']['subscriptions']['email']['marketing']['consent'] ?? 'unknown',
        ], 'Profil trouvé.');
    }

    private function subscribeProfile(array $p, array $c): ToolResult
    {
        $listId = $this->listId($p, $c);
        if (!$listId) {
            return ToolResult::fail('not_configured', 'Aucune liste précisée et aucune liste par défaut configurée pour ce site.');
        }

        $attributes = ['email' => $p['email']];
        if (!empty($p['first_name']) || !empty($p['last_name'])) {
            $attributes['profile'] = ['data' => ['type' => 'profile', 'attributes' => array_filter([
                'email' => $p['email'], 'first_name' => $p['first_name'] ?? null, 'last_name' => $p['last_name'] ?? null,
            ])]];
        }

        try {
            $this->client($c)->post('profile-subscription-bulk-create-jobs', [
                'data' => [
                    'type' => 'profile-subscription-bulk-create-job',
                    'attributes' => [
                        'profiles' => ['data' => [[
                            'type' => 'profile',
                            'attributes' => array_filter([
                                'email' => $p['email'],
                                'subscriptions' => ['email' => ['marketing' => ['consent' => 'SUBSCRIBED']]],
                                'first_name' => $p['first_name'] ?? null, 'last_name' => $p['last_name'] ?? null,
                            ]),
                        ]]],
                    ],
                    'relationships' => ['list' => ['data' => ['type' => 'list', 'id' => $listId]]],
                ],
            ]);
        } catch (RequestException $e) {
            $this->handleApiError($e);
        }
        $this->recordSuccess();

        return ToolResult::ok(['email' => $p['email'], 'list_id' => $listId], "Inscription de {$p['email']} envoyée à Klaviyo.");
    }

    private function listCampaigns(array $p, array $c): ToolResult
    {
        $limit = max(1, min(50, (int) ($p['limit'] ?? 10)));

        try {
            $response = $this->client($c)->get('campaigns', [
                'filter' => 'equals(messages.channel,"email")', 'page[size]' => $limit, 'sort' => '-created_at',
            ]);
        } catch (RequestException $e) {
            $this->handleApiError($e);
        }
        $this->recordSuccess();

        $campaigns = collect($response->json('data', []))->map(fn ($cp) => [
            'id' => $cp['id'] ?? null, 'name' => $cp['attributes']['name'] ?? null, 'status' => $cp['attributes']['status'] ?? null,
        ])->all();

        if (empty($campaigns)) {
            return ToolResult::fail('not_found', 'Aucune campagne trouvée.');
        }
        return ToolResult::ok(['campaigns' => $campaigns], count($campaigns) . ' campagne(s) trouvée(s).');
    }

    private function campaignPerformance(array $p, array $c): ToolResult
    {
        try {
            $response = $this->client($c)->post('campaign-values-reports', [
                'data' => [
                    'type' => 'campaign-values-report',
                    'attributes' => [
                        'timeframe' => ['key' => 'all_time'],
                        'conversion_metric_id' => null,
                        'filter' => "equals(campaign_id,\"{$p['campaign_id']}\")",
                        'statistics' => ['opens', 'open_rate', 'clicks', 'click_rate', 'unsubscribes'],
                    ],
                ],
            ]);
        } catch (RequestException $e) {
            if ($e->response?->status() === 404) {
                return ToolResult::fail('not_found', 'Aucun rapport disponible pour cette campagne.');
            }
            $this->handleApiError($e);
        }
        $this->recordSuccess();
        $stats = $response->json('data.attributes.results.0.statistics', []);

        if (empty($stats)) {
            return ToolResult::fail('not_found', 'Aucune donnée de performance pour cette campagne.');
        }

        return ToolResult::ok([
            'campaign_id' => $p['campaign_id'],
            'opens' => $stats['opens'] ?? 0, 'open_rate' => round(($stats['open_rate'] ?? 0) * 100, 2) . '%',
            'clicks' => $stats['clicks'] ?? 0, 'click_rate' => round(($stats['click_rate'] ?? 0) * 100, 2) . '%',
            'unsubscribes' => $stats['unsubscribes'] ?? 0,
        ], 'Performance de campagne récupérée.');
    }

    // ── Utilitaires ──────────────────────────────────────────────────

    private function listId(array $p, array $c): ?string
    {
        return $p['list_id'] ?? $c['default_list_id'] ?? null;
    }

    private function client(array $credentials)
    {
        return $this->http(self::API_BASE)->withHeaders([
            'Authorization' => 'Klaviyo-API-Key ' . $credentials['api_key'],
            'revision' => self::REVISION,
            'Content-Type' => 'application/json',
        ]);
    }

    private function handleApiError(RequestException $e): never
    {
        $status = $e->response?->status();
        $body = $e->response?->body();
        Log::error('MCP Klaviyo: appel API échoué', ['status' => $status, 'body' => $body]);

        if (in_array($status, [401, 403])) {
            throw new AuthExpiredException('Clé API Klaviyo invalide ou révoquée.');
        }
        throw new ConnectorUnavailableException('Klaviyo indisponible: ' . $e->getMessage());
    }
}
