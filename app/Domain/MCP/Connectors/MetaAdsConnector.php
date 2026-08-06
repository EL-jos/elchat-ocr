<?php

namespace App\Domain\MCP\Connectors;

use App\Domain\MCP\Contracts\ToolResult;
use App\Domain\MCP\Contracts\ToolSchema;
use App\Domain\MCP\Exceptions\AuthExpiredException;
use App\Domain\MCP\Exceptions\ConnectorUnavailableException;
use App\Domain\MCP\Exceptions\ToolNotFoundException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Connecteur Meta Ads (Facebook Marketing API, Graph v19.0), lecture +
 * écriture restreinte.
 * credentials attendus (déchiffrés) : { "access_token", "expires_at" } — un
 * jeton utilisateur longue durée (~60 jours). Meta n'a PAS de refresh_token
 * classique : on ré-échange le jeton encore valide contre un nouveau juste
 * avant expiration (fb_exchange_token), d'où une implémentation
 * d'authenticate() spécifique, sans le trait RefreshesOAuthToken conçu pour
 * le grant_type=refresh_token standard.
 * settings attendus : { "ad_account_id": "act_1234567890" } — avec le
 * préfixe "act_" exigé par l'API, saisi après connexion OAuth.
 *
 * ⚠️ Comme pour Google Ads : toute écriture est 'confirm' + confirm_actor
 * 'admin'. Pas de création de campagne exposée en v1.
 */
class MetaAdsConnector extends AbstractConnector
{
    private const API_BASE = 'https://graph.facebook.com/v19.0/';
    private const TOKEN_ENDPOINT = 'https://graph.facebook.com/v19.0/oauth/access_token';

    public function slug(): string
    {
        return 'meta_ads';
    }

    public function authenticate(array $credentials): array
    {
        $expiresAt = $credentials['expires_at'] ?? null;

        // Jeton encore valide plus de 5 jours : pas besoin de le ré-échanger
        // à chaque appel (limite les échanges inutiles côté Meta).
        if ($expiresAt && now()->timestamp < $expiresAt - (5 * 86400)) {
            return $credentials;
        }

        if (empty($credentials['access_token'])) {
            throw new AuthExpiredException('Jeton Meta Ads absent, reconnexion requise.');
        }

        try {
            $response = Http::asForm()->get(self::TOKEN_ENDPOINT, [
                'grant_type' => 'fb_exchange_token',
                'client_id' => config('mcp.connectors.meta_ads.app_id'),
                'client_secret' => config('mcp.connectors.meta_ads.app_secret'),
                'fb_exchange_token' => $credentials['access_token'],
            ]);
        } catch (RequestException $e) {
            Log::error('MCP Meta Ads: échec du ré-échange de jeton', ['body' => $e->response?->body()]);
            throw new AuthExpiredException('Impossible de renouveler le jeton Meta, reconnexion requise.');
        }

        if ($response->failed()) {
            Log::error('MCP Meta Ads: ré-échange de jeton refusé', ['status' => $response->status(), 'body' => $response->body()]);
            // Le jeton actuel peut encore être valide quelques jours : on ne
            // bloque pas immédiatement, on retente au prochain appel.
            if ($expiresAt && now()->timestamp < $expiresAt) {
                return $credentials;
            }
            throw new AuthExpiredException('Jeton Meta invalide ou expiré, reconnexion requise.');
        }

        $data = $response->json();

        return array_merge($credentials, [
            'access_token' => $data['access_token'],
            'expires_at' => now()->addSeconds($data['expires_in'] ?? 5184000)->timestamp, // défaut 60 jours
        ]);
    }

    public function listTools(): array
    {
        return [
            new ToolSchema('meta_ads', 'list_campaigns',
                "Liste les campagnes du compte publicitaire Meta (Facebook/Instagram) avec leur statut, objectif et budget quotidien. Utiliser pour obtenir une vue d'ensemble ou retrouver l'identifiant d'une campagne nommée avant une autre action. Ne jamais inventer un identifiant non retourné par cet outil.",
                ['type' => 'object', 'properties' => [
                    'status' => ['type' => 'string', 'enum' => ['ACTIVE', 'PAUSED', 'ARCHIVED'], 'description' => 'filtrer par statut, optionnel'],
                ]], defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('meta_ads', 'get_campaign_insights',
                "Retourne les indicateurs de performance (dépense, impressions, clics, CTR, CPC, portée) d'une campagne sur une période. Utiliser pour toute question sur les résultats publicitaires Meta, jamais pour du trafic organique.",
                ['type' => 'object', 'properties' => [
                    'campaign_id' => ['type' => 'string'],
                    'date_from' => ['type' => 'string', 'description' => 'YYYY-MM-DD, défaut: -30 jours'],
                    'date_to' => ['type' => 'string', 'description' => 'YYYY-MM-DD, défaut: hier'],
                ], 'required' => ['campaign_id']], defaultActorScope: 'admin', defaultMode: 'auto', capability: 'ads.performance'),

            new ToolSchema('meta_ads', 'get_ad_set_insights',
                "Retourne les performances détaillées par ensemble de publicités (ad set) au sein d'une campagne sur une période — utile pour identifier quel ciblage ou placement fonctionne le mieux.",
                ['type' => 'object', 'properties' => [
                    'campaign_id' => ['type' => 'string'], 'date_from' => ['type' => 'string'], 'date_to' => ['type' => 'string'],
                ], 'required' => ['campaign_id']], defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('meta_ads', 'get_account_summary',
                "Retourne une synthèse du compte publicitaire : dépense des 7 et 30 derniers jours, nombre de campagnes actives, devise du compte. Utiliser pour une question générale sur l'état du compte Meta Ads.",
                ['type' => 'object', 'properties' => []], defaultActorScope: 'admin', defaultMode: 'auto'),

            // ── Écriture (impact financier — confirmation admin obligatoire) ──
            new ToolSchema('meta_ads', 'pause_campaign',
                "Met en pause une campagne Meta Ads existante identifiée de manière unique. La diffusion cesse immédiatement. Si l'identifiant est inconnu, utiliser list_campaigns au préalable.",
                ['type' => 'object', 'properties' => ['campaign_id' => ['type' => 'string']], 'required' => ['campaign_id']],
                isWriteAction: true, defaultActorScope: 'admin', defaultMode: 'confirm', defaultConfirmActor: 'admin', capability: 'ads.manage_campaign'),

            new ToolSchema('meta_ads', 'resume_campaign',
                "Réactive une campagne Meta Ads actuellement en pause identifiée de manière unique. La diffusion reprend immédiatement selon le budget configuré.",
                ['type' => 'object', 'properties' => ['campaign_id' => ['type' => 'string']], 'required' => ['campaign_id']],
                isWriteAction: true, defaultActorScope: 'admin', defaultMode: 'confirm', defaultConfirmActor: 'admin', capability: 'ads.manage_campaign'),

            new ToolSchema('meta_ads', 'update_daily_budget',
                "Modifie le budget quotidien d'une campagne Meta Ads existante identifiée de manière unique. Montant dans la devise du compte (converti automatiquement en centimes pour l'API). Ne jamais deviner un montant.",
                ['type' => 'object', 'properties' => [
                    'campaign_id' => ['type' => 'string'], 'daily_budget_amount' => ['type' => 'number', 'description' => 'nouveau budget quotidien, ex: 25.50'],
                ], 'required' => ['campaign_id', 'daily_budget_amount']],
                isWriteAction: true, defaultActorScope: 'admin', defaultMode: 'confirm', defaultConfirmActor: 'admin', capability: 'ads.manage_campaign'),
        ];
    }

    public function callTool(string $toolName, array $params, array $credentials, array $context = []): ToolResult
    {
        return match ($toolName) {
            'list_campaigns' => $this->listCampaigns($params, $credentials),
            'get_campaign_insights' => $this->campaignInsights($params, $credentials),
            'get_ad_set_insights' => $this->adSetInsights($params, $credentials),
            'get_account_summary' => $this->accountSummary($credentials),
            'pause_campaign' => $this->setCampaignStatus($params, $credentials, 'PAUSED'),
            'resume_campaign' => $this->setCampaignStatus($params, $credentials, 'ACTIVE'),
            'update_daily_budget' => $this->updateDailyBudget($params, $credentials),
            default => throw new ToolNotFoundException("Outil '{$toolName}' inconnu pour meta_ads."),
        };
    }

    // ── Lecture ──────────────────────────────────────────────────────

    private function listCampaigns(array $p, array $c): ToolResult
    {
        $accountId = $this->adAccountId($c);
        if (!$accountId) {
            return ToolResult::fail('not_configured', 'Aucun compte publicitaire Meta configuré pour ce site.');
        }

        $query = ['fields' => 'id,name,status,objective,daily_budget', 'limit' => 100];
        if (!empty($p['status'])) {
            $query['filtering'] = json_encode([['field' => 'status', 'operator' => 'IN', 'value' => [$p['status']]]]);
        }

        try {
            $response = $this->client($c)->get("{$accountId}/campaigns", $query);
        } catch (RequestException $e) {
            $this->handleApiError($e);
        }
        $this->recordSuccess();

        $campaigns = collect($response->json('data', []))->map(fn ($r) => [
            'id' => $r['id'] ?? null, 'name' => $r['name'] ?? null, 'status' => $r['status'] ?? null,
            'objective' => $r['objective'] ?? null,
            'daily_budget' => isset($r['daily_budget']) ? round(((int) $r['daily_budget']) / 100, 2) : null,
        ])->all();

        if (empty($campaigns)) {
            return ToolResult::fail('not_found', 'Aucune campagne trouvée.');
        }
        return ToolResult::ok(['campaigns' => $campaigns], count($campaigns) . ' campagne(s) trouvée(s).');
    }

    private function campaignInsights(array $p, array $c): ToolResult
    {
        $accountId = $this->adAccountId($c);
        if (!$accountId) {
            return ToolResult::fail('not_configured', 'Aucun compte publicitaire Meta configuré pour ce site.');
        }

        $from = $p['date_from'] ?? now()->subDays(30)->toDateString();
        $to = $p['date_to'] ?? now()->subDay()->toDateString();
        $campaignId = $p['campaign_id'];

        try {
            $response = $this->client($c)->get("{$campaignId}/insights", [
                'fields' => 'impressions,clicks,spend,ctr,cpc,reach',
                'time_range' => json_encode(['since' => $from, 'until' => $to]),
            ]);
        } catch (RequestException $e) {
            if ($e->response?->status() === 400 && str_contains((string) $e->response?->body(), 'Unsupported get request')) {
                return ToolResult::fail('not_found', 'Campagne introuvable.');
            }
            $this->handleApiError($e);
        }
        $this->recordSuccess();
        $row = $response->json('data.0');

        if (!$row) {
            return ToolResult::fail('not_found', "Aucune donnée de performance pour cette campagne sur la période {$from} → {$to}.");
        }

        return ToolResult::ok([
            'period' => "{$from} → {$to}",
            'impressions' => (int) ($row['impressions'] ?? 0),
            'clicks' => (int) ($row['clicks'] ?? 0),
            'spend' => round((float) ($row['spend'] ?? 0), 2),
            'ctr' => round((float) ($row['ctr'] ?? 0), 2) . '%',
            'cpc' => round((float) ($row['cpc'] ?? 0), 2),
            'reach' => (int) ($row['reach'] ?? 0),
        ], 'Performance de campagne récupérée.');
    }

    private function adSetInsights(array $p, array $c): ToolResult
    {
        $campaignId = $p['campaign_id'];
        $from = $p['date_from'] ?? now()->subDays(30)->toDateString();
        $to = $p['date_to'] ?? now()->subDay()->toDateString();

        try {
            $response = $this->client($c)->get("{$campaignId}/adsets", [
                'fields' => "id,name,status,insights.time_range({\"since\":\"{$from}\",\"until\":\"{$to}\"}){impressions,clicks,spend,ctr}",
                'limit' => 100,
            ]);
        } catch (RequestException $e) {
            $this->handleApiError($e);
        }
        $this->recordSuccess();

        $adSets = collect($response->json('data', []))->map(function ($r) {
            $insights = $r['insights']['data'][0] ?? [];
            return [
                'id' => $r['id'] ?? null, 'name' => $r['name'] ?? null, 'status' => $r['status'] ?? null,
                'impressions' => (int) ($insights['impressions'] ?? 0), 'clicks' => (int) ($insights['clicks'] ?? 0),
                'spend' => round((float) ($insights['spend'] ?? 0), 2), 'ctr' => round((float) ($insights['ctr'] ?? 0), 2) . '%',
            ];
        })->all();

        if (empty($adSets)) {
            return ToolResult::fail('not_found', "Aucun ensemble de publicités trouvé pour cette campagne.");
        }
        return ToolResult::ok(['ad_sets' => $adSets], count($adSets) . " ensemble(s) de publicités trouvé(s).");
    }

    private function accountSummary(array $c): ToolResult
    {
        $accountId = $this->adAccountId($c);
        if (!$accountId) {
            return ToolResult::fail('not_configured', 'Aucun compte publicitaire Meta configuré pour ce site.');
        }

        try {
            $accountInfo = $this->client($c)->get($accountId, ['fields' => 'currency,name'])->json();
            $insights7d = $this->client($c)->get("{$accountId}/insights", ['fields' => 'spend', 'date_preset' => 'last_7d'])->json('data.0.spend', 0);
            $insights30d = $this->client($c)->get("{$accountId}/insights", ['fields' => 'spend', 'date_preset' => 'last_30d'])->json('data.0.spend', 0);
            $activeCampaigns = $this->client($c)->get("{$accountId}/campaigns", [
                'fields' => 'id', 'filtering' => json_encode([['field' => 'status', 'operator' => 'IN', 'value' => ['ACTIVE']]]), 'limit' => 500,
            ])->json('data', []);
        } catch (RequestException $e) {
            $this->handleApiError($e);
        }
        $this->recordSuccess();

        return ToolResult::ok([
            'account_name' => $accountInfo['name'] ?? null,
            'currency' => $accountInfo['currency'] ?? null,
            'spend_last_7_days' => round((float) $insights7d, 2),
            'spend_last_30_days' => round((float) $insights30d, 2),
            'active_campaigns' => count($activeCampaigns),
        ], 'Synthèse du compte Meta Ads récupérée.');
    }

    // ── Écriture ─────────────────────────────────────────────────────

    private function setCampaignStatus(array $p, array $c, string $status): ToolResult
    {
        $campaignId = $p['campaign_id'];

        try {
            $this->client($c)->post($campaignId, ['status' => $status]);
        } catch (RequestException $e) {
            if ($e->response?->status() === 400 && str_contains((string) $e->response?->body(), 'Unsupported')) {
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

    private function updateDailyBudget(array $p, array $c): ToolResult
    {
        $campaignId = $p['campaign_id'];
        $amountCents = (int) round(((float) $p['daily_budget_amount']) * 100);

        if ($amountCents <= 0) {
            return ToolResult::fail('invalid_amount', 'Le budget quotidien doit être un montant positif.');
        }

        try {
            $this->client($c)->post($campaignId, ['daily_budget' => $amountCents]);
        } catch (RequestException $e) {
            if ($e->response?->status() === 400 && str_contains((string) $e->response?->body(), 'minimum')) {
                return ToolResult::fail('budget_too_low', 'Le budget demandé est inférieur au minimum autorisé par Meta pour cette campagne.');
            }
            $this->handleApiError($e);
        }
        $this->recordSuccess();

        return ToolResult::ok(
            ['campaign_id' => $campaignId, 'daily_budget_amount' => round($amountCents / 100, 2)],
            "Budget quotidien mis à jour à " . round($amountCents / 100, 2) . '.'
        );
    }

    // ── Utilitaires ──────────────────────────────────────────────────

    private function adAccountId(array $c): ?string
    {
        $id = $c['ad_account_id'] ?? null;
        if (!$id) {
            return null;
        }
        return str_starts_with($id, 'act_') ? $id : "act_{$id}";
    }

    private function client(array $credentials)
    {
        return $this->http(self::API_BASE)->withToken($credentials['access_token']);
    }

    private function handleApiError(RequestException $e): never
    {
        $status = $e->response?->status();
        $body = $e->response?->body();
        Log::error('MCP Meta Ads: appel API échoué', ['status' => $status, 'body' => $body]);

        if (in_array($status, [401, 403])) {
            throw new AuthExpiredException('Accès Meta Ads refusé ou expiré, reconnexion requise.');
        }
        throw new ConnectorUnavailableException('Meta Ads indisponible: ' . $e->getMessage());
    }
}
