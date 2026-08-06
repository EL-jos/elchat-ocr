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
 * Connecteur Brevo (ex-Sendinblue, API v3), lecture + ajout de contact.
 * credentials attendus (déchiffrés) : { "api_key": "xkeysib-..." }
 * settings attendus (optionnel) : { "default_list_id": "..." }
 *
 * Décision produit volontaire : aucun outil d'envoi d'email transactionnel
 * n'est exposé, alors que l'API Brevo le permettrait. Un chatbot capable de
 * déclencher l'envoi d'emails arbitraires est une surface d'abus (spam,
 * phishing via un compte compromis) disproportionnée par rapport à la
 * valeur conversationnelle — cohérent avec le choix déjà fait sur
 * Mailchimp/Klaviyo de rester sur reporting + gestion de liste.
 */
class BrevoConnector extends AbstractConnector
{
    private const API_BASE = 'https://api.brevo.com/v3/';

    public function slug(): string
    {
        return 'brevo';
    }

    public function authenticate(array $credentials): array
    {
        if (empty($credentials['api_key'])) {
            throw new AuthExpiredException('Clé API Brevo manquante.');
        }
        return $credentials;
    }

    public function listTools(): array
    {
        return [
            new ToolSchema('brevo', 'list_contact_lists',
                "Liste les listes de contacts du compte Brevo avec leur nombre de contacts. Utiliser pour retrouver l'identifiant d'une liste nommée avant d'y ajouter un contact.",
                ['type' => 'object', 'properties' => []], defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('brevo', 'get_contact',
                "Recherche un contact par adresse email et retourne ses attributs et les listes auxquelles il appartient. Utiliser pour vérifier si une personne est déjà connue avant de l'ajouter à une liste.",
                ['type' => 'object', 'properties' => ['email' => ['type' => 'string']], 'required' => ['email']],
                defaultMode: 'auto'),

            new ToolSchema('brevo', 'add_contact_to_list',
                "Ajoute (ou met à jour) un contact et l'inscrit à une liste Brevo. Ne jamais appeler sans que la personne ait explicitement exprimé le souhait de s'inscrire — Brevo n'impose pas de double opt-in par défaut contrairement à Mailchimp/Klaviyo, le consentement explicite avant appel est donc encore plus important ici.",
                ['type' => 'object', 'properties' => [
                    'email' => ['type' => 'string'], 'list_id' => ['type' => 'string', 'description' => 'défaut: liste configurée par défaut'],
                    'first_name' => ['type' => 'string'], 'last_name' => ['type' => 'string'],
                ], 'required' => ['email']],
                isWriteAction: true, defaultActorScope: 'visitor', defaultMode: 'confirm', defaultConfirmActor: 'visitor', capability: 'marketing.subscribe'),

            new ToolSchema('brevo', 'list_campaigns',
                "Liste les campagnes email récentes avec leur statut. Utiliser pour retrouver l'identifiant d'une campagne avant de consulter ses statistiques.",
                ['type' => 'object', 'properties' => ['limit' => ['type' => 'integer', 'description' => 'défaut 10, max 50']]],
                defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('brevo', 'get_campaign_stats',
                "Retourne les statistiques d'une campagne email envoyée : taux d'ouverture, taux de clic, désabonnements. Utiliser uniquement pour une campagne déjà envoyée.",
                ['type' => 'object', 'properties' => ['campaign_id' => ['type' => 'string']], 'required' => ['campaign_id']],
                defaultActorScope: 'admin', defaultMode: 'auto'),
        ];
    }

    public function callTool(string $toolName, array $params, array $credentials, array $context = []): ToolResult
    {
        return match ($toolName) {
            'list_contact_lists' => $this->listContactLists($credentials),
            'get_contact' => $this->getContact($params, $credentials),
            'add_contact_to_list' => $this->addContactToList($params, $credentials),
            'list_campaigns' => $this->listCampaigns($params, $credentials),
            'get_campaign_stats' => $this->campaignStats($params, $credentials),
            default => throw new ToolNotFoundException("Outil '{$toolName}' inconnu pour brevo."),
        };
    }

    // ── Implémentation ──────────────────────────────────────────────

    private function listContactLists(array $c): ToolResult
    {
        try {
            $response = $this->client($c)->get('contacts/lists', ['limit' => 50]);
        } catch (RequestException $e) {
            $this->handleApiError($e);
        }
        $this->recordSuccess();

        $lists = collect($response->json('lists', []))->map(fn ($l) => [
            'id' => $l['id'] ?? null, 'name' => $l['name'] ?? null, 'contact_count' => $l['totalSubscribers'] ?? 0,
        ])->all();

        if (empty($lists)) {
            return ToolResult::fail('not_found', 'Aucune liste trouvée sur ce compte Brevo.');
        }
        return ToolResult::ok(['lists' => $lists], count($lists) . ' liste(s) trouvée(s).');
    }

    private function getContact(array $p, array $c): ToolResult
    {
        try {
            $response = $this->client($c)->get('contacts/' . urlencode($p['email']));
        } catch (RequestException $e) {
            if ($e->response?->status() === 404) {
                return ToolResult::fail('not_found', "Aucun contact trouvé pour {$p['email']}.");
            }
            $this->handleApiError($e);
        }
        $this->recordSuccess();
        $r = $response->json();

        return ToolResult::ok([
            'email' => $r['email'] ?? $p['email'],
            'first_name' => $r['attributes']['FIRSTNAME'] ?? null,
            'list_ids' => $r['listIds'] ?? [],
            'email_blacklisted' => $r['emailBlacklisted'] ?? false,
        ], 'Contact trouvé.');
    }

    private function addContactToList(array $p, array $c): ToolResult
    {
        $listId = $this->listId($p, $c);
        if (!$listId) {
            return ToolResult::fail('not_configured', 'Aucune liste précisée et aucune liste par défaut configurée pour ce site.');
        }

        $attributes = array_filter(['FIRSTNAME' => $p['first_name'] ?? null, 'LASTNAME' => $p['last_name'] ?? null]);

        try {
            $this->client($c)->post('contacts', [
                'email' => $p['email'],
                'attributes' => (object) $attributes,
                'listIds' => [(int) $listId],
                'updateEnabled' => true,
            ]);
        } catch (RequestException $e) {
            if ($e->response?->status() === 400 && str_contains((string) $e->response?->body(), 'invalid_parameter')) {
                return ToolResult::fail('invalid_email', "L'adresse email fournie n'est pas valide.");
            }
            $this->handleApiError($e);
        }
        $this->recordSuccess();

        return ToolResult::ok(['email' => $p['email'], 'list_id' => $listId], "{$p['email']} a été ajouté(e) à la liste.");
    }

    private function listCampaigns(array $p, array $c): ToolResult
    {
        try {
            $response = $this->client($c)->get('emailCampaigns', ['limit' => max(1, min(50, (int) ($p['limit'] ?? 10))), 'sort' => 'desc']);
        } catch (RequestException $e) {
            $this->handleApiError($e);
        }
        $this->recordSuccess();

        $campaigns = collect($response->json('campaigns', []))->map(fn ($cp) => [
            'id' => $cp['id'] ?? null, 'name' => $cp['name'] ?? null, 'status' => $cp['status'] ?? null,
        ])->all();

        if (empty($campaigns)) {
            return ToolResult::fail('not_found', 'Aucune campagne trouvée.');
        }
        return ToolResult::ok(['campaigns' => $campaigns], count($campaigns) . ' campagne(s) trouvée(s).');
    }

    private function campaignStats(array $p, array $c): ToolResult
    {
        try {
            $response = $this->client($c)->get("emailCampaigns/{$p['campaign_id']}", ['statistics' => 'globalStats']);
        } catch (RequestException $e) {
            if ($e->response?->status() === 404) {
                return ToolResult::fail('not_found', 'Campagne introuvable.');
            }
            $this->handleApiError($e);
        }
        $this->recordSuccess();
        $stats = $response->json('statistics.globalStats', []);

        if (empty($stats)) {
            return ToolResult::fail('not_found', 'Aucune statistique disponible pour cette campagne (pas encore envoyée ?).');
        }

        return ToolResult::ok([
            'campaign_id' => $p['campaign_id'],
            'delivered' => $stats['delivered'] ?? 0,
            'open_rate' => isset($stats['uniqueViews'], $stats['delivered']) && $stats['delivered'] > 0
                ? round($stats['uniqueViews'] / $stats['delivered'] * 100, 2) . '%' : null,
            'click_rate' => isset($stats['uniqueClicks'], $stats['delivered']) && $stats['delivered'] > 0
                ? round($stats['uniqueClicks'] / $stats['delivered'] * 100, 2) . '%' : null,
            'unsubscriptions' => $stats['unsubscriptions'] ?? 0,
        ], 'Statistiques de campagne récupérées.');
    }

    // ── Utilitaires ──────────────────────────────────────────────────

    private function listId(array $p, array $c): ?string
    {
        return $p['list_id'] ?? $c['default_list_id'] ?? null;
    }

    private function client(array $credentials)
    {
        return $this->http(self::API_BASE)->withHeaders(['api-key' => $credentials['api_key']]);
    }

    private function handleApiError(RequestException $e): never
    {
        $status = $e->response?->status();
        $body = $e->response?->body();
        Log::error('MCP Brevo: appel API échoué', ['status' => $status, 'body' => $body]);

        if (in_array($status, [401, 403])) {
            throw new AuthExpiredException('Clé API Brevo invalide ou révoquée.');
        }
        throw new ConnectorUnavailableException('Brevo indisponible: ' . $e->getMessage());
    }
}
