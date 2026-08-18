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
 * Connecteur Google Ads (REST API v17), lecture + écriture restreinte.
 * credentials attendus (déchiffrés) : { "access_token", "refresh_token", "expires_at" }
 * settings attendus : {
 *   "customer_id": "1234567890"       // ID du compte Ads cible, SANS tirets
 *   "login_customer_id": "9876543210" // optionnel : ID du compte manager (MCC) si le
 *                                        compte cible est géré via un MCC — requis par
 *                                        l'API dans ce cas, sinon 403 USER_PERMISSION_DENIED
 * }
 * Le developer token est une clé applicative globale ELChat (voir
 * config('mcp.connectors.google_ads.developer_token')), jamais propre à un site :
 * c'est le jeton attribué au Manager Account ELChat par Google, pas au compte
 * Ads du client.
 *
 * ⚠️ Toute action d'écriture (pause/activation de campagne, budget) est en
 * defaultMode 'confirm' + defaultConfirmActor 'admin' : jamais exécutable par
 * un simple visiteur du widget, impact financier direct. La création de
 * nouvelles campagnes n'est délibérément pas exposée en v1 (surface de
 * risque trop large pour un usage conversationnel — décision produit).
 */
class GoogleAdsConnector extends AbstractConnector
{
    use RefreshesOAuthToken;

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const API_BASE = 'https://googleads.googleapis.com/v17/';

    public function slug(): string
    {
        return 'google_ads';
    }

    public function authenticate(array $credentials): array
    {
        $expiresAt = $credentials['expires_at'] ?? null;

        if ($expiresAt && now()->timestamp < $expiresAt - 60) {
            return $credentials;
        }

        if (empty($credentials['refresh_token'])) {
            throw new AuthExpiredException('Refresh token Google Ads absent, reconnexion OAuth requise.');
        }

        return $this->refreshOAuthToken(self::TOKEN_URL, [
            'client_id' => config('mcp.connectors.google_ads.client_id'),
            'client_secret' => config('mcp.connectors.google_ads.client_secret'),
            'refresh_token' => $credentials['refresh_token'],
            'grant_type' => 'refresh_token',
        ], $credentials);
    }

    public function listTools(): array
    {
        return [
            // ── Lecture ──
            new ToolSchema('google_ads', 'list_campaigns',
                "Liste les campagnes du compte avec leur statut (active, en pause, supprimée), leur type et leur budget quotidien. Utiliser pour obtenir une vue d'ensemble ou retrouver l'identifiant d'une campagne nommée avant une autre action. Ne jamais inventer un identifiant de campagne non retourné par cet outil.",
                ['type' => 'object', 'properties' => [
                    'status' => ['type' => 'string', 'enum' => ['ENABLED', 'PAUSED', 'REMOVED'], 'description' => 'filtrer par statut, optionnel'],
                ]], defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('google_ads', 'get_campaign_performance',
                "Retourne les indicateurs de performance (impressions, clics, coût, conversions, CTR, CPC moyen) d'une ou plusieurs campagnes sur une période. Si aucune campagne n'est précisée, agrège sur l'ensemble du compte. Utiliser pour toute question sur les résultats publicitaires, jamais pour du trafic organique (utiliser google_analytics dans ce cas).",
                ['type' => 'object', 'properties' => [
                    'campaign_id' => ['type' => 'string', 'description' => 'optionnel : limiter à une campagne précise'],
                    'date_from' => ['type' => 'string', 'description' => 'YYYY-MM-DD, défaut: -30 jours'],
                    'date_to' => ['type' => 'string', 'description' => 'YYYY-MM-DD, défaut: hier'],
                ]], defaultActorScope: 'admin', defaultMode: 'auto', capability: 'ads.performance'),

            new ToolSchema('google_ads', 'get_keyword_performance',
                "Retourne les mots-clés d'une campagne de recherche avec leurs performances (impressions, clics, coût, position moyenne) sur une période. Utiliser uniquement pour des campagnes de type Recherche ; les autres types de campagne n'ont pas de mots-clés exploitables de la même façon.",
                ['type' => 'object', 'properties' => [
                    'campaign_id' => ['type' => 'string'], 'date_from' => ['type' => 'string'], 'date_to' => ['type' => 'string'],
                    'limit' => ['type' => 'integer', 'description' => 'défaut 20, max 100'],
                ], 'required' => ['campaign_id']], defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('google_ads', 'get_account_summary',
                "Retourne une synthèse du compte : dépense du jour, dépense du mois en cours, nombre de campagnes actives, conversions du mois. Utiliser pour une question générale sur l'état du compte publicitaire.",
                ['type' => 'object', 'properties' => []], defaultActorScope: 'admin', defaultMode: 'auto'),

            // ── Écriture (impact financier — confirmation admin obligatoire) ──
            new ToolSchema('google_ads', 'pause_campaign',
                "Met en pause une campagne existante identifiée de manière unique. La campagne cesse immédiatement de diffuser des annonces et de dépenser du budget. Ne jamais mettre en pause une campagne sur la base d'une supposition ; si l'identifiant est inconnu, utiliser list_campaigns au préalable.",
                ['type' => 'object', 'properties' => ['campaign_id' => ['type' => 'string']], 'required' => ['campaign_id']],
                isWriteAction: true, defaultActorScope: 'admin', defaultMode: 'confirm', defaultConfirmActor: 'admin', capability: 'ads.manage_campaign'),

            new ToolSchema('google_ads', 'enable_campaign',
                "Réactive une campagne actuellement en pause identifiée de manière unique. La diffusion et la dépense reprennent immédiatement selon le budget configuré. Vérifier que la campagne est bien celle voulue avant réactivation.",
                ['type' => 'object', 'properties' => ['campaign_id' => ['type' => 'string']], 'required' => ['campaign_id']],
                isWriteAction: true, defaultActorScope: 'admin', defaultMode: 'confirm', defaultConfirmActor: 'admin', capability: 'ads.manage_campaign'),

            new ToolSchema('google_ads', 'update_campaign_budget',
                "Modifie le budget quotidien d'une campagne existante identifiée de manière unique. Le nouveau montant est en euros (ou devise du compte), converti automatiquement en micros pour l'API. Ne jamais deviner un montant ; le montant doit être explicitement fourni par l'utilisateur.",
                ['type' => 'object', 'properties' => [
                    'campaign_id' => ['type' => 'string'], 'daily_budget_amount' => ['type' => 'number', 'description' => 'nouveau budget quotidien dans la devise du compte, ex: 25.50'],
                ], 'required' => ['campaign_id', 'daily_budget_amount']],
                isWriteAction: true, defaultActorScope: 'admin', defaultMode: 'confirm', defaultConfirmActor: 'admin', capability: 'ads.manage_campaign'),
        ];
    }

    public function callTool(string $toolName, array $params, array $credentials, array $context = []): ToolResult
    {
        return match ($toolName) {
            'list_campaigns' => $this->listCampaigns($params, $credentials),
            'get_campaign_performance' => $this->campaignPerformance($params, $credentials),
            'get_keyword_performance' => $this->keywordPerformance($params, $credentials),
            'get_account_summary' => $this->accountSummary($credentials),
            'pause_campaign' => $this->setCampaignStatus($params, $credentials, 'PAUSED'),
            'enable_campaign' => $this->setCampaignStatus($params, $credentials, 'ENABLED'),
            'update_campaign_budget' => $this->updateCampaignBudget($params, $credentials),
            default => throw new ToolNotFoundException("Outil '{$toolName}' inconnu pour google_ads."),
        };
    }

    // ── Lecture ──────────────────────────────────────────────────────

    private function listCampaigns(array $p, array $c): ToolResult
    {
        $customerId = $this->customerId($c);
        if (!$customerId) {
            return ToolResult::fail('not_configured', 'Aucun compte Google Ads configuré pour ce site.');
        }

        $where = !empty($p['status']) ? " WHERE campaign.status = '{$p['status']}'" : '';
        $query = "SELECT campaign.id, campaign.name, campaign.status, campaign.advertising_channel_type, "
            . "campaign_budget.amount_micros FROM campaign{$where} ORDER BY campaign.name";

        $rows = $this->gaql($c, $customerId, $query);

        $campaigns = collect($rows)->map(fn ($r) => [
            'id' => $r['campaign']['id'] ?? null,
            'name' => $r['campaign']['name'] ?? null,
            'status' => $r['campaign']['status'] ?? null,
            'type' => $r['campaign']['advertisingChannelType'] ?? null,
            'daily_budget' => isset($r['campaignBudget']['amountMicros']) ? round($r['campaignBudget']['amountMicros'] / 1_000_000, 2) : null,
        ])->all();

        if (empty($campaigns)) {
            return ToolResult::fail('not_found', 'Aucune campagne trouvée.');
        }
        return ToolResult::ok(['campaigns' => $campaigns], count($campaigns) . ' campagne(s) trouvée(s).');
    }

    private function campaignPerformance(array $p, array $c): ToolResult
    {
        $customerId = $this->customerId($c);
        if (!$customerId) {
            return ToolResult::fail('not_configured', 'Aucun compte Google Ads configuré pour ce site.');
        }

        $from = $p['date_from'] ?? now()->subDays(30)->toDateString();
        $to = $p['date_to'] ?? now()->subDay()->toDateString();
        $campaignFilter = !empty($p['campaign_id']) ? " AND campaign.id = {$this->intId($p['campaign_id'])}" : '';

        $query = "SELECT campaign.id, campaign.name, metrics.impressions, metrics.clicks, metrics.cost_micros, "
            . "metrics.conversions, metrics.ctr, metrics.average_cpc FROM campaign "
            . "WHERE segments.date BETWEEN '{$from}' AND '{$to}'{$campaignFilter}";

        $rows = $this->gaql($c, $customerId, $query);

        $results = collect($rows)->map(fn ($r) => [
            'campaign_id' => $r['campaign']['id'] ?? null,
            'campaign_name' => $r['campaign']['name'] ?? null,
            'impressions' => (int) ($r['metrics']['impressions'] ?? 0),
            'clicks' => (int) ($r['metrics']['clicks'] ?? 0),
            'cost' => round(($r['metrics']['costMicros'] ?? 0) / 1_000_000, 2),
            'conversions' => round((float) ($r['metrics']['conversions'] ?? 0), 2),
            'ctr' => round((float) ($r['metrics']['ctr'] ?? 0) * 100, 2) . '%',
            'average_cpc' => round(($r['metrics']['averageCpc'] ?? 0) / 1_000_000, 2),
        ])->all();

        if (empty($results)) {
            return ToolResult::fail('not_found', "Aucune donnée de performance sur la période {$from} → {$to}.");
        }
        return ToolResult::ok(['period' => "{$from} → {$to}", 'campaigns' => $results], count($results) . ' campagne(s) avec données.');
    }

    private function keywordPerformance(array $p, array $c): ToolResult
    {
        $customerId = $this->customerId($c);
        if (!$customerId) {
            return ToolResult::fail('not_configured', 'Aucun compte Google Ads configuré pour ce site.');
        }

        $from = $p['date_from'] ?? now()->subDays(30)->toDateString();
        $to = $p['date_to'] ?? now()->subDay()->toDateString();
        $limit = max(1, min(100, (int) ($p['limit'] ?? 20)));

        $query = "SELECT ad_group_criterion.keyword.text, ad_group_criterion.keyword.match_type, "
            . "metrics.impressions, metrics.clicks, metrics.cost_micros, metrics.average_position "
            . "FROM keyword_view WHERE campaign.id = {$this->intId($p['campaign_id'])} "
            . "AND segments.date BETWEEN '{$from}' AND '{$to}' "
            . "ORDER BY metrics.clicks DESC LIMIT {$limit}";

        $rows = $this->gaql($c, $customerId, $query);

        $keywords = collect($rows)->map(fn ($r) => [
            'keyword' => $r['adGroupCriterion']['keyword']['text'] ?? null,
            'match_type' => $r['adGroupCriterion']['keyword']['matchType'] ?? null,
            'impressions' => (int) ($r['metrics']['impressions'] ?? 0),
            'clicks' => (int) ($r['metrics']['clicks'] ?? 0),
            'cost' => round(($r['metrics']['costMicros'] ?? 0) / 1_000_000, 2),
        ])->all();

        if (empty($keywords)) {
            return ToolResult::fail('not_found', 'Aucun mot-clé avec données sur cette période pour cette campagne.');
        }
        return ToolResult::ok(['keywords' => $keywords], count($keywords) . ' mot(s)-clé(s) trouvé(s).');
    }

    private function accountSummary(array $c): ToolResult
    {
        $customerId = $this->customerId($c);
        if (!$customerId) {
            return ToolResult::fail('not_configured', 'Aucun compte Google Ads configuré pour ce site.');
        }

        $todayQuery = "SELECT metrics.cost_micros FROM customer WHERE segments.date DURING TODAY";
        $monthQuery = "SELECT metrics.cost_micros, metrics.conversions FROM customer WHERE segments.date DURING THIS_MONTH";
        $activeCampaignsQuery = "SELECT campaign.id FROM campaign WHERE campaign.status = 'ENABLED'";

        $todayRows = $this->gaql($c, $customerId, $todayQuery);
        $monthRows = $this->gaql($c, $customerId, $monthQuery);
        $activeCampaigns = $this->gaql($c, $customerId, $activeCampaignsQuery);

        $spendToday = collect($todayRows)->sum(fn ($r) => $r['metrics']['costMicros'] ?? 0) / 1_000_000;
        $spendMonth = collect($monthRows)->sum(fn ($r) => $r['metrics']['costMicros'] ?? 0) / 1_000_000;
        $conversionsMonth = collect($monthRows)->sum(fn ($r) => $r['metrics']['conversions'] ?? 0);

        return ToolResult::ok([
            'spend_today' => round($spendToday, 2),
            'spend_this_month' => round($spendMonth, 2),
            'conversions_this_month' => round($conversionsMonth, 2),
            'active_campaigns' => count($activeCampaigns),
        ], 'Synthèse du compte Google Ads récupérée.');
    }

    // ── Écriture ─────────────────────────────────────────────────────

    private function setCampaignStatus(array $p, array $c, string $status): ToolResult
    {
        $customerId = $this->customerId($c);
        if (!$customerId) {
            return ToolResult::fail('not_configured', 'Aucun compte Google Ads configuré pour ce site.');
        }
        $campaignId = $this->intId($p['campaign_id']);

        try {
            $this->client($c)->post("customers/{$customerId}/campaigns:mutate", [
                'operations' => [[
                    'update' => ['resourceName' => "customers/{$customerId}/campaigns/{$campaignId}", 'status' => $status],
                    'updateMask' => 'status',
                ]],
            ]);
        } catch (RequestException $e) {
            if ($e->response?->status() === 404 || str_contains((string) $e->response?->body(), 'NOT_FOUND')) {
                return ToolResult::fail('not_found', 'Campagne introuvable.');
            }
            $this->handleApiError($e);
        }
        $this->recordSuccess();

        return ToolResult::ok(
            ['campaign_id' => $campaignId, 'status' => $status],
            $status === 'PAUSED' ? 'Campagne mise en pause.' : 'Campagne réactivée.'
        );
    }

    private function updateCampaignBudget(array $p, array $c): ToolResult
    {
        $customerId = $this->customerId($c);
        if (!$customerId) {
            return ToolResult::fail('not_configured', 'Aucun compte Google Ads configuré pour ce site.');
        }
        $campaignId = $this->intId($p['campaign_id']);
        $amountMicros = (int) round(((float) $p['daily_budget_amount']) * 1_000_000);

        if ($amountMicros <= 0) {
            return ToolResult::fail('invalid_amount', 'Le budget quotidien doit être un montant positif.');
        }

        try {
            // Le budget est une ressource distincte de la campagne : il faut
            // d'abord résoudre le resourceName du campaign_budget associé.
            $rows = $this->gaql($c, $customerId, "SELECT campaign_budget.resource_name FROM campaign WHERE campaign.id = {$campaignId}");
            $budgetResourceName = $rows[0]['campaignBudget']['resourceName'] ?? null;

            if (!$budgetResourceName) {
                return ToolResult::fail('not_found', 'Campagne introuvable ou budget associé introuvable.');
            }

            $this->client($c)->post("customers/{$customerId}/campaignBudgets:mutate", [
                'operations' => [[
                    'update' => ['resourceName' => $budgetResourceName, 'amountMicros' => (string) $amountMicros],
                    'updateMask' => 'amount_micros',
                ]],
            ]);
        } catch (RequestException $e) {
            $this->handleApiError($e);
        }
        $this->recordSuccess();

        return ToolResult::ok(
            ['campaign_id' => $campaignId, 'daily_budget_amount' => round($amountMicros / 1_000_000, 2)],
            "Budget quotidien mis à jour à " . round($amountMicros / 1_000_000, 2) . '.'
        );
    }

    // ── Utilitaires ──────────────────────────────────────────────────

    /**
     * Exécute une requête GAQL et retourne les résultats déjà décodés.
     * Centralise la gestion d'erreur pour tous les outils de lecture.
     */
    private function gaql(array $c, string $customerId, string $query): array
    {
        try {
            $response = $this->client($c)->post("customers/{$customerId}/googleAds:search", ['query' => $query]);
        } catch (RequestException $e) {
            $this->handleApiError($e);
        }
        $this->recordSuccess();
        return $response->json('results', []);
    }

    private function customerId(array $c): ?string
    {
        return isset($c['customer_id']) ? preg_replace('/[^0-9]/', '', $c['customer_id']) : null;
    }

    private function intId(string $id): string
    {
        return preg_replace('/[^0-9]/', '', $id);
    }

    private function client(array $credentials)
    {
        $developerToken = config('mcp.connectors.google_ads.developer_token');

        $client = $this->http(self::API_BASE)
            ->withToken($credentials['access_token'])
            ->withHeaders(['developer-token' => $developerToken]);

        if (!empty($credentials['login_customer_id'])) {
            $client = $client->withHeaders(['login-customer-id' => preg_replace('/[^0-9]/', '', $credentials['login_customer_id'])]);
        }

        return $client;
    }

    private function handleApiError(RequestException $e): never
    {
        $status = $e->response?->status();
        $body = $e->response?->body();
        Log::error('MCP Google Ads: appel API échoué', ['status' => $status, 'body' => $body]);

        if ($status === 401) {
            throw new AuthExpiredException('Accès Google Ads expiré, reconnexion requise.');
        }
        if ($status === 403) {
            throw new AuthExpiredException("Accès Google Ads refusé — vérifiez que login_customer_id est configuré si le compte est géré via un MCC.");
        }
        throw new ConnectorUnavailableException('Google Ads indisponible: ' . $e->getMessage());
    }
}
