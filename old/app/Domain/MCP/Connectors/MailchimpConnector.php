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
 * Connecteur Mailchimp (Marketing API v3), lecture + inscription à une liste.
 * credentials attendus (déchiffrés) : { "api_key": "xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx-usXX" }
 * Le "datacenter" (usXX) fait partie intégrante de la clé (suffixe après le
 * dernier tiret) : c'est lui qui détermine l'URL de base, PAS un réglage
 * séparé — une clé Mailchimp mal collée par l'utilisateur produit un
 * datacenter invalide et donc un échec réseau explicite, jamais silencieux.
 * settings attendus (optionnel) : { "default_list_id": "..." } — utilisé si
 * l'utilisateur ne précise pas explicitement quelle audience cibler.
 *
 * Une seule action d'écriture : add_subscriber. Statut par défaut 'pending'
 * (double opt-in) et jamais 'subscribed' directement — ELChat ne doit
 * jamais inscrire quelqu'un de force à une liste marketing sans
 * confirmation explicite du destinataire lui-même (obligation légale
 * RGPD/CAN-SPAM, pas seulement une précaution produit).
 */
class MailchimpConnector extends AbstractConnector
{
    public function slug(): string
    {
        return 'mailchimp';
    }

    public function authenticate(array $credentials): array
    {
        if (empty($credentials['api_key']) || !str_contains($credentials['api_key'], '-')) {
            throw new AuthExpiredException("Clé API Mailchimp absente ou mal formée (doit se terminer par -usXX).");
        }
        return $credentials; // pas de rafraîchissement : clé API statique
    }

    public function listTools(): array
    {
        return [
            new ToolSchema('mailchimp', 'list_audiences',
                "Liste les audiences (listes de contacts) du compte Mailchimp avec leur nombre d'abonnés. Utiliser pour retrouver l'identifiant d'une audience nommée avant d'inscrire quelqu'un, ou pour une vue d'ensemble. Ne jamais inventer un identifiant d'audience non retourné par cet outil.",
                ['type' => 'object', 'properties' => []], defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('mailchimp', 'list_campaigns',
                "Liste les campagnes email envoyées ou programmées, avec leur statut et sujet. Utiliser pour retrouver l'identifiant d'une campagne avant de consulter ses statistiques.",
                ['type' => 'object', 'properties' => [
                    'status' => ['type' => 'string', 'enum' => ['sent', 'scheduled', 'draft'], 'description' => 'filtrer par statut, optionnel'],
                    'limit' => ['type' => 'integer', 'description' => 'défaut 10, max 50'],
                ]], defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('mailchimp', 'get_campaign_performance',
                "Retourne les statistiques d'une campagne email envoyée : taux d'ouverture, taux de clic, désabonnements, taux de rebond. Utiliser uniquement pour une campagne déjà envoyée (statut 'sent'), jamais un brouillon.",
                ['type' => 'object', 'properties' => ['campaign_id' => ['type' => 'string']], 'required' => ['campaign_id']],
                defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('mailchimp', 'get_subscriber',
                "Vérifie si une adresse email est déjà inscrite à une audience donnée et retourne son statut d'abonnement. Utiliser avant add_subscriber si l'on veut éviter une double inscription, ou pour répondre à une question sur le statut d'inscription de quelqu'un.",
                ['type' => 'object', 'properties' => [
                    'email' => ['type' => 'string'], 'list_id' => ['type' => 'string', 'description' => 'défaut: audience configurée par défaut'],
                ], 'required' => ['email']], defaultMode: 'auto'),

            new ToolSchema('mailchimp', 'add_subscriber',
                "Inscrit une adresse email à une audience Mailchimp. L'inscription est créée au statut 'en attente de confirmation' (double opt-in) : la personne recevra un email de Mailchimp pour confirmer, elle n'est jamais abonnée de force. Ne jamais appeler cet outil sans que la personne ait explicitement exprimé le souhait de s'inscrire.",
                ['type' => 'object', 'properties' => [
                    'email' => ['type' => 'string'], 'list_id' => ['type' => 'string', 'description' => 'défaut: audience configurée par défaut'],
                    'first_name' => ['type' => 'string'], 'last_name' => ['type' => 'string'],
                ], 'required' => ['email']],
                isWriteAction: true, defaultActorScope: 'visitor', defaultMode: 'confirm', defaultConfirmActor: 'visitor', capability: 'marketing.subscribe'),
        ];
    }

    public function callTool(string $toolName, array $params, array $credentials, array $context = []): ToolResult
    {
        return match ($toolName) {
            'list_audiences' => $this->listAudiences($credentials),
            'list_campaigns' => $this->listCampaigns($params, $credentials),
            'get_campaign_performance' => $this->campaignPerformance($params, $credentials),
            'get_subscriber' => $this->getSubscriber($params, $credentials),
            'add_subscriber' => $this->addSubscriber($params, $credentials),
            default => throw new ToolNotFoundException("Outil '{$toolName}' inconnu pour mailchimp."),
        };
    }

    // ── Implémentation ──────────────────────────────────────────────

    private function listAudiences(array $c): ToolResult
    {
        try {
            $response = $this->client($c)->get('lists', ['count' => 50]);
        } catch (RequestException $e) {
            $this->handleApiError($e);
        }
        $this->recordSuccess();

        $audiences = collect($response->json('lists', []))->map(fn ($l) => [
            'id' => $l['id'] ?? null, 'name' => $l['name'] ?? null,
            'member_count' => $l['stats']['member_count'] ?? 0,
        ])->all();

        if (empty($audiences)) {
            return ToolResult::fail('not_found', 'Aucune audience trouvée sur ce compte Mailchimp.');
        }
        return ToolResult::ok(['audiences' => $audiences], count($audiences) . ' audience(s) trouvée(s).');
    }

    private function listCampaigns(array $p, array $c): ToolResult
    {
        $query = ['count' => max(1, min(50, (int) ($p['limit'] ?? 10))), 'sort_field' => 'send_time', 'sort_dir' => 'DESC'];
        if (!empty($p['status'])) {
            $query['status'] = $p['status'];
        }

        try {
            $response = $this->client($c)->get('campaigns', $query);
        } catch (RequestException $e) {
            $this->handleApiError($e);
        }
        $this->recordSuccess();

        $campaigns = collect($response->json('campaigns', []))->map(fn ($cp) => [
            'id' => $cp['id'] ?? null, 'subject' => $cp['settings']['subject_line'] ?? null,
            'status' => $cp['status'] ?? null, 'send_time' => $cp['send_time'] ?? null,
        ])->all();

        if (empty($campaigns)) {
            return ToolResult::fail('not_found', 'Aucune campagne trouvée.');
        }
        return ToolResult::ok(['campaigns' => $campaigns], count($campaigns) . ' campagne(s) trouvée(s).');
    }

    private function campaignPerformance(array $p, array $c): ToolResult
    {
        try {
            $response = $this->client($c)->get("reports/{$p['campaign_id']}");
        } catch (RequestException $e) {
            if ($e->response?->status() === 404) {
                return ToolResult::fail('not_found', "Aucun rapport disponible pour cette campagne (peut-être pas encore envoyée).");
            }
            $this->handleApiError($e);
        }
        $this->recordSuccess();
        $r = $response->json();

        return ToolResult::ok([
            'campaign_id' => $p['campaign_id'],
            'emails_sent' => $r['emails_sent'] ?? 0,
            'open_rate' => round((($r['opens']['open_rate'] ?? 0) * 100), 2) . '%',
            'click_rate' => round((($r['clicks']['click_rate'] ?? 0) * 100), 2) . '%',
            'unsubscribed' => $r['unsubscribed'] ?? 0,
            'bounces' => ($r['bounces']['hard_bounces'] ?? 0) + ($r['bounces']['soft_bounces'] ?? 0),
        ], 'Performance de campagne récupérée.');
    }

    private function getSubscriber(array $p, array $c): ToolResult
    {
        $listId = $this->listId($p, $c);
        if (!$listId) {
            return ToolResult::fail('not_configured', 'Aucune audience précisée et aucune audience par défaut configurée pour ce site.');
        }
        $hash = md5(strtolower(trim($p['email'])));

        try {
            $response = $this->client($c)->get("lists/{$listId}/members/{$hash}");
        } catch (RequestException $e) {
            if ($e->response?->status() === 404) {
                return ToolResult::fail('not_found', "Cette adresse n'est pas inscrite à cette audience.");
            }
            $this->handleApiError($e);
        }
        $this->recordSuccess();
        $m = $response->json();

        return ToolResult::ok([
            'email' => $m['email_address'] ?? $p['email'], 'status' => $m['status'] ?? null,
            'subscribed_since' => $m['timestamp_opt'] ?? null,
        ], "Statut d'inscription récupéré.");
    }

    private function addSubscriber(array $p, array $c): ToolResult
    {
        $listId = $this->listId($p, $c);
        if (!$listId) {
            return ToolResult::fail('not_configured', 'Aucune audience précisée et aucune audience par défaut configurée pour ce site.');
        }
        $hash = md5(strtolower(trim($p['email'])));

        $mergeFields = array_filter([
            'FNAME' => $p['first_name'] ?? null, 'LNAME' => $p['last_name'] ?? null,
        ]);

        try {
            $response = $this->client($c)->put("lists/{$listId}/members/{$hash}", [
                'email_address' => $p['email'],
                'status_if_new' => 'pending', // jamais 'subscribed' : double opt-in obligatoire
                'merge_fields' => (object) $mergeFields,
            ]);
        } catch (RequestException $e) {
            if ($e->response?->status() === 400 && str_contains((string) $e->response?->body(), 'Invalid Resource')) {
                return ToolResult::fail('invalid_email', "L'adresse email fournie n'est pas valide ou est signalée comme problématique par Mailchimp.");
            }
            $this->handleApiError($e);
        }
        $this->recordSuccess();
        $status = $response->json('status');

        return ToolResult::ok(
            ['email' => $p['email'], 'status' => $status],
            $status === 'subscribed'
                ? "{$p['email']} était déjà inscrit(e) à cette audience."
                : "Un email de confirmation d'inscription a été envoyé à {$p['email']}."
        );
    }

    // ── Utilitaires ──────────────────────────────────────────────────

    private function listId(array $p, array $c): ?string
    {
        return $p['list_id'] ?? $c['default_list_id'] ?? null;
    }

    private function client(array $credentials)
    {
        $datacenter = substr($credentials['api_key'], strrpos($credentials['api_key'], '-') + 1);
        return $this->http("https://{$datacenter}.api.mailchimp.com/3.0/")->withBasicAuth('elchat', $credentials['api_key']);
    }

    private function handleApiError(RequestException $e): never
    {
        $status = $e->response?->status();
        $body = $e->response?->body();
        Log::error('MCP Mailchimp: appel API échoué', ['status' => $status, 'body' => $body]);

        if (in_array($status, [401, 403])) {
            throw new AuthExpiredException('Clé API Mailchimp invalide ou révoquée.');
        }
        throw new ConnectorUnavailableException('Mailchimp indisponible: ' . $e->getMessage());
    }
}
