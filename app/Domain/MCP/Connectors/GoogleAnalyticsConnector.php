<?php

namespace App\Domain\MCP\Connectors;

use App\Domain\MCP\Connectors\Concerns\RefreshesOAuthToken;
use App\Domain\MCP\Contracts\ToolResult;
use App\Domain\MCP\Contracts\ToolSchema;
use App\Domain\MCP\Exceptions\AuthExpiredException;
use App\Domain\MCP\Exceptions\ConnectorUnavailableException;
use App\Domain\MCP\Exceptions\ToolNotFoundException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Connecteur Google Analytics 4 (Data API), lecture seule.
 * credentials attendus (déchiffrés) : { "access_token", "refresh_token", "expires_at" }
 * settings attendus : { "property_id": "123456789" } — ID numérique de la propriété
 * GA4 (Admin > Paramètres de la propriété), à saisir après la connexion OAuth via
 * PUT /sites/{site}/mcp/connectors/google_analytics/settings.
 *
 * Aucune action d'écriture : Analytics est un outil de reporting, jamais de
 * pilotage. Tous les outils sont donc en defaultMode 'auto'.
 */
class GoogleAnalyticsConnector extends AbstractConnector
{
    use RefreshesOAuthToken;

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const API_BASE = 'https://analyticsdata.googleapis.com/v1beta/';

    public function slug(): string
    {
        return 'google_analytics';
    }

    public function authenticate(array $credentials): array
    {
        $expiresAt = $credentials['expires_at'] ?? null;

        if ($expiresAt && now()->timestamp < $expiresAt - 60) {
            return $credentials;
        }

        if (empty($credentials['refresh_token'])) {
            throw new AuthExpiredException('Refresh token Google Analytics absent, reconnexion OAuth requise.');
        }

        return $this->refreshOAuthToken(self::TOKEN_URL, [
            'client_id' => config('mcp.connectors.google_analytics.client_id'),
            'client_secret' => config('mcp.connectors.google_analytics.client_secret'),
            'refresh_token' => $credentials['refresh_token'],
            'grant_type' => 'refresh_token',
        ], $credentials);
    }

    public function listTools(): array
    {
        return [
            new ToolSchema('google_analytics', 'get_traffic_overview',
                "Fournit une synthèse des principaux indicateurs GA4 (sessions, utilisateurs actifs, pages vues, taux de rebond et durée moyenne de session) sur une période donnée. Utilise cet outil lorsqu'une vue d'ensemble du comportement des visiteurs est demandée ou avant une analyse plus détaillée. Ne l'utilise pas pour analyser une page précise, une source d'acquisition ou les performances SEO. Les données reflètent uniquement l'activité enregistrée dans Google Analytics pour la période demandée. Ne complète jamais les métriques retournées et ne déduis pas automatiquement les causes des variations observées.",
                ['type' => 'object', 'properties' => [
                    'date_from' => ['type' => 'string', 'description' => 'YYYY-MM-DD, défaut: -28 jours'],
                    'date_to' => ['type' => 'string', 'description' => 'YYYY-MM-DD, défaut: hier'],
                    'compare_previous_period' => ['type' => 'boolean', 'description' => 'Comparer à la période équivalente précédente'],
                ]], defaultMode: 'auto', capability: 'analytics.traffic_overview'),

            new ToolSchema('google_analytics', 'get_top_pages',
                "Retourne les pages ayant enregistré le plus de vues et d'utilisateurs sur la période sélectionnée. Utilise cet outil lorsqu'un utilisateur souhaite identifier les contenus les plus consultés ou comparer leur fréquentation. Ne le confonds jamais avec les performances SEO des pages, qui relèvent de Google Search Console. Les résultats représentent uniquement l'activité enregistrée dans Google Analytics.",
                ['type' => 'object', 'properties' => [
                    'date_from' => ['type' => 'string'], 'date_to' => ['type' => 'string'],
                    'limit' => ['type' => 'integer', 'description' => 'défaut 10, max 50'],
                ]], defaultMode: 'auto'),

            new ToolSchema('google_analytics', 'get_traffic_sources',
                "Répartit les sessions par canal d'acquisition (organique, direct, référent, réseaux sociaux, email, payant, etc.). Utilise cet outil pour comprendre l'origine du trafic. Ne l'utilise pas pour mesurer les conversions, les performances SEO ou les campagnes individuelles. Les canaux correspondent au regroupement par défaut de Google Analytics et ne doivent pas être réinterprétés.",
                ['type' => 'object', 'properties' => [
                    'date_from' => ['type' => 'string'], 'date_to' => ['type' => 'string'],
                ]], defaultMode: 'auto'),

            new ToolSchema('google_analytics', 'get_conversions',
                "Retourne les événements marqués comme conversions dans la propriété GA4 ainsi que leur volume et leur taux de conversion. Utilise cet outil lorsqu'un utilisateur souhaite mesurer la réalisation d'objectifs configurés (achat, formulaire, inscription, etc.). Ne suppose jamais qu'un événement représente une vente ou un objectif métier sans sa configuration explicite. Si aucun événement de conversion n'est configuré, indique simplement qu'aucune donnée n'est disponible.",
                ['type' => 'object', 'properties' => [
                    'date_from' => ['type' => 'string'], 'date_to' => ['type' => 'string'],
                    'event_name' => ['type' => 'string', 'description' => "Nom d'événement GA4 précis (optionnel) ; si absent, toutes les conversions configurées sont retournées"],
                ]], defaultMode: 'auto'),

            new ToolSchema('google_analytics', 'get_realtime_users',
                "Retourne le nombre approximatif d'utilisateurs actuellement actifs ainsi que les principales pages consultées. Utilise uniquement lorsqu'une activité en temps réel est explicitement demandée. Ne compare pas automatiquement ces données avec des périodes historiques. Les résultats représentent un instantané susceptible d'évoluer rapidement.",
                ['type' => 'object', 'properties' => []], defaultMode: 'auto'),

            new ToolSchema('google_analytics', 'get_audience_demographics',
                "Fournit la répartition des utilisateurs par pays et par catégorie d'appareil sur la période demandée. Utilise cet outil pour décrire la composition de l'audience. Ne l'utilise pas pour mesurer les performances SEO, les conversions ou les campagnes marketing. Les résultats reflètent uniquement les utilisateurs enregistrés dans Google Analytics.",
                ['type' => 'object', 'properties' => [
                    'date_from' => ['type' => 'string'], 'date_to' => ['type' => 'string'],
                ]], defaultMode: 'auto'),
        ];
    }

    public function callTool(string $toolName, array $params, array $credentials, array $context = []): ToolResult
    {
        return match ($toolName) {
            'get_traffic_overview' => $this->trafficOverview($params, $credentials),
            'get_top_pages' => $this->topPages($params, $credentials),
            'get_traffic_sources' => $this->trafficSources($params, $credentials),
            'get_conversions' => $this->conversions($params, $credentials),
            'get_realtime_users' => $this->realtimeUsers($credentials),
            'get_audience_demographics' => $this->audienceDemographics($params, $credentials),
            default => throw new ToolNotFoundException("Outil '{$toolName}' inconnu pour google_analytics."),
        };
    }

    // ── Implémentation ──────────────────────────────────────────────

    private function trafficOverview(array $p, array $c): ToolResult
    {
        [$from, $to] = $this->resolveRange($p);
        $propertyId = $this->propertyId($c);
        if (!$propertyId) {
            return ToolResult::fail('not_configured', "Aucune propriété GA4 configurée pour ce site. Renseignez l'ID de propriété dans les réglages du connecteur Google Analytics.");
        }

        $metrics = ['sessions', 'activeUsers', 'screenPageViews', 'bounceRate', 'averageSessionDuration'];

        try {
            $report = $this->runReport($c, $propertyId, [
                'dateRanges' => [['startDate' => $from, 'endDate' => $to]],
                'metrics' => array_map(fn ($m) => ['name' => $m], $metrics),
            ]);

            if (!empty($p['compare_previous_period'])) {
                $span = Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1;
                $prevFrom = Carbon::parse($from)->subDays($span)->toDateString();
                $prevTo = Carbon::parse($from)->subDay()->toDateString();
                $prevReport = $this->runReport($c, $propertyId, [
                    'dateRanges' => [['startDate' => $prevFrom, 'endDate' => $prevTo]],
                    'metrics' => array_map(fn ($m) => ['name' => $m], $metrics),
                ]);
            }
        } catch (RequestException $e) {
            $this->handleApiError($e);
        }
        $this->recordSuccess();

        $current = $this->extractRow($report, $metrics);
        $data = ['period' => "{$from} → {$to}", 'metrics' => $current];

        if (isset($prevReport)) {
            $data['previous_period'] = ['period' => "{$prevFrom} → {$prevTo}", 'metrics' => $this->extractRow($prevReport, $metrics)];
        }

        return ToolResult::ok($data, "Vue d'ensemble du trafic du {$from} au {$to}.");
    }

    private function topPages(array $p, array $c): ToolResult
    {
        [$from, $to] = $this->resolveRange($p);
        $propertyId = $this->propertyId($c);
        if (!$propertyId) {
            return ToolResult::fail('not_configured', "Aucune propriété GA4 configurée pour ce site.");
        }
        $limit = max(1, min(50, (int) ($p['limit'] ?? 10)));

        try {
            $report = $this->runReport($c, $propertyId, [
                'dateRanges' => [['startDate' => $from, 'endDate' => $to]],
                'dimensions' => [['name' => 'pagePath'], ['name' => 'pageTitle']],
                'metrics' => [['name' => 'screenPageViews'], ['name' => 'activeUsers']],
                'orderBys' => [['metric' => ['metricName' => 'screenPageViews'], 'desc' => true]],
                'limit' => $limit,
            ]);
        } catch (RequestException $e) {
            $this->handleApiError($e);
        }
        $this->recordSuccess();

        $pages = collect($report['rows'] ?? [])->map(fn ($row) => [
            'path' => $row['dimensionValues'][0]['value'] ?? null,
            'title' => $row['dimensionValues'][1]['value'] ?? null,
            'views' => (int) ($row['metricValues'][0]['value'] ?? 0),
            'users' => (int) ($row['metricValues'][1]['value'] ?? 0),
        ])->all();

        if (empty($pages)) {
            return ToolResult::fail('not_found', 'Aucune donnée de page sur cette période.');
        }
        return ToolResult::ok(['pages' => $pages], count($pages) . ' page(s) les plus consultées.');
    }

    private function trafficSources(array $p, array $c): ToolResult
    {
        [$from, $to] = $this->resolveRange($p);
        $propertyId = $this->propertyId($c);
        if (!$propertyId) {
            return ToolResult::fail('not_configured', "Aucune propriété GA4 configurée pour ce site.");
        }

        try {
            $report = $this->runReport($c, $propertyId, [
                'dateRanges' => [['startDate' => $from, 'endDate' => $to]],
                'dimensions' => [['name' => 'sessionDefaultChannelGroup']],
                'metrics' => [['name' => 'sessions'], ['name' => 'activeUsers']],
                'orderBys' => [['metric' => ['metricName' => 'sessions'], 'desc' => true]],
            ]);
        } catch (RequestException $e) {
            $this->handleApiError($e);
        }
        $this->recordSuccess();

        $sources = collect($report['rows'] ?? [])->map(fn ($row) => [
            'channel' => $row['dimensionValues'][0]['value'] ?? 'Non attribué',
            'sessions' => (int) ($row['metricValues'][0]['value'] ?? 0),
            'users' => (int) ($row['metricValues'][1]['value'] ?? 0),
        ])->all();

        return ToolResult::ok(['sources' => $sources], count($sources) . ' canal(aux) d\'acquisition.');
    }

    private function conversions(array $p, array $c): ToolResult
    {
        [$from, $to] = $this->resolveRange($p);
        $propertyId = $this->propertyId($c);
        if (!$propertyId) {
            return ToolResult::fail('not_configured', "Aucune propriété GA4 configurée pour ce site.");
        }

        $body = [
            'dateRanges' => [['startDate' => $from, 'endDate' => $to]],
            'dimensions' => [['name' => 'eventName']],
            'metrics' => [['name' => 'conversions'], ['name' => 'sessionConversionRate']],
        ];
        if (!empty($p['event_name'])) {
            $body['dimensionFilter'] = [
                'filter' => ['fieldName' => 'eventName', 'stringFilter' => ['value' => $p['event_name'], 'matchType' => 'EXACT']],
            ];
        }

        try {
            $report = $this->runReport($c, $propertyId, $body);
        } catch (RequestException $e) {
            $this->handleApiError($e);
        }
        $this->recordSuccess();

        $conversions = collect($report['rows'] ?? [])->map(fn ($row) => [
            'event' => $row['dimensionValues'][0]['value'] ?? null,
            'conversions' => (int) ($row['metricValues'][0]['value'] ?? 0),
            'conversion_rate' => round((float) ($row['metricValues'][1]['value'] ?? 0) * 100, 2) . '%',
        ])->all();

        if (empty($conversions)) {
            return ToolResult::fail('not_found', 'Aucune conversion trouvée — vérifiez que des événements de conversion sont configurés dans GA4.');
        }
        return ToolResult::ok(['conversions' => $conversions], count($conversions) . ' type(s) de conversion trouvé(s).');
    }

    private function realtimeUsers(array $c): ToolResult
    {
        $propertyId = $this->propertyId($c);
        if (!$propertyId) {
            return ToolResult::fail('not_configured', "Aucune propriété GA4 configurée pour ce site.");
        }

        try {
            $response = $this->client($c)->post("properties/{$propertyId}:runRealtimeReport", [
                'dimensions' => [['name' => 'unifiedScreenName']],
                'metrics' => [['name' => 'activeUsers']],
                'orderBys' => [['metric' => ['metricName' => 'activeUsers'], 'desc' => true]],
                'limit' => 10,
            ]);
        } catch (RequestException $e) {
            $this->handleApiError($e);
        }
        $this->recordSuccess();
        $report = $response->json();

        $total = collect($report['rows'] ?? [])->sum(fn ($r) => (int) ($r['metricValues'][0]['value'] ?? 0));
        $byPage = collect($report['rows'] ?? [])->map(fn ($r) => [
            'page' => $r['dimensionValues'][0]['value'] ?? null,
            'active_users' => (int) ($r['metricValues'][0]['value'] ?? 0),
        ])->all();

        return ToolResult::ok(['active_users_total' => $total, 'by_page' => $byPage], "{$total} utilisateur(s) actif(s) en ce moment.");
    }

    private function audienceDemographics(array $p, array $c): ToolResult
    {
        [$from, $to] = $this->resolveRange($p);
        $propertyId = $this->propertyId($c);
        if (!$propertyId) {
            return ToolResult::fail('not_configured', "Aucune propriété GA4 configurée pour ce site.");
        }

        try {
            $countryReport = $this->runReport($c, $propertyId, [
                'dateRanges' => [['startDate' => $from, 'endDate' => $to]],
                'dimensions' => [['name' => 'country']],
                'metrics' => [['name' => 'activeUsers']],
                'orderBys' => [['metric' => ['metricName' => 'activeUsers'], 'desc' => true]],
                'limit' => 10,
            ]);
            $deviceReport = $this->runReport($c, $propertyId, [
                'dateRanges' => [['startDate' => $from, 'endDate' => $to]],
                'dimensions' => [['name' => 'deviceCategory']],
                'metrics' => [['name' => 'activeUsers']],
            ]);
        } catch (RequestException $e) {
            $this->handleApiError($e);
        }
        $this->recordSuccess();

        $countries = collect($countryReport['rows'] ?? [])->map(fn ($r) => [
            'country' => $r['dimensionValues'][0]['value'] ?? null, 'users' => (int) ($r['metricValues'][0]['value'] ?? 0),
        ])->all();
        $devices = collect($deviceReport['rows'] ?? [])->map(fn ($r) => [
            'device' => $r['dimensionValues'][0]['value'] ?? null, 'users' => (int) ($r['metricValues'][0]['value'] ?? 0),
        ])->all();

        return ToolResult::ok(['top_countries' => $countries, 'devices' => $devices], 'Répartition démographique récupérée.');
    }

    // ── Utilitaires ──────────────────────────────────────────────────

    private function runReport(array $c, string $propertyId, array $body): array
    {
        return $this->client($c)->post("properties/{$propertyId}:runReport", $body)->json();
    }

    private function extractRow(array $report, array $metricNames): array
    {
        $values = $report['rows'][0]['metricValues'] ?? [];
        $result = [];
        foreach ($metricNames as $i => $name) {
            $raw = $values[$i]['value'] ?? 0;
            $result[$name] = str_contains($name, 'Rate') ? round((float) $raw * 100, 2) . '%' : (is_numeric($raw) ? (float) $raw : $raw);
        }
        return $result;
    }

    private function resolveRange(array $p): array
    {
        $from = $p['date_from'] ?? now()->subDays(28)->toDateString();
        $to = $p['date_to'] ?? now()->subDay()->toDateString();
        return [$from, $to];
    }

    /**
     * L'ID de propriété GA4 n'est jamais deviné : sans lui, aucun appel
     * n'est possible (l'API Data GA4 est scoped par propriété, pas par
     * compte). Configuré une seule fois par site via les settings.
     */
    private function propertyId(array $c): ?string
    {
        return $c['property_id'] ?? null;
    }

    private function client(array $credentials)
    {
        return $this->http(self::API_BASE)->withToken($credentials['access_token']);
    }

    /**
     * Centralise la traduction des erreurs HTTP GA4 en exceptions du
     * domaine, avec log complet du corps de réponse (jamais juste le
     * message tronqué) — voir convention §11 du journal d'architecture.
     */
    private function handleApiError(RequestException $e): never
    {
        $status = $e->response?->status();
        $body = $e->response?->body();
        Log::error('MCP Google Analytics: appel API échoué', ['status' => $status, 'body' => $body]);

        if (in_array($status, [401, 403])) {
            throw new AuthExpiredException('Accès Google Analytics refusé ou expiré, reconnexion requise.');
        }
        throw new ConnectorUnavailableException('Google Analytics indisponible: ' . $e->getMessage());
    }
}
