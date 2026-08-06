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
 * Connecteur Google Search Console, lecture seule.
 * credentials attendus (déchiffrés) : { "access_token", "refresh_token", "expires_at" }
 * settings attendus : { "site_url": "https://exemple.com/" } — propriété exacte
 * telle qu'enregistrée dans Search Console (avec le slash final pour une
 * propriété de type domaine préfixé par le protocole, ou "sc-domain:exemple.com"
 * pour une propriété de type domaine). À saisir après connexion OAuth.
 *
 * Une action d'écriture est exposée : request_indexing, via l'API Indexing
 * de Google (scope https://www.googleapis.com/auth/indexing, distinct du
 * scope webmasters.readonly — les deux sont demandés ensemble lors du
 * consentement OAuth, voir MCPConnectorController::oauthRedirect). ⚠️
 * Google ne garantit officiellement cette API que pour les pages
 * JobPosting/BroadcastEvent balisées en données structurées ; en pratique
 * elle déclenche une demande de recrawl pour n'importe quelle URL mais
 * sans garantie de prise en compte hors de ce périmètre — le résumé
 * retourné au LLM le précise pour éviter toute promesse à l'utilisateur.
 * Quota par défaut : 200 requêtes/jour par projet Google Cloud.
 */
class GoogleSearchConsoleConnector extends AbstractConnector
{
    use RefreshesOAuthToken;

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const SEARCH_ANALYTICS_BASE = 'https://www.googleapis.com/webmasters/v3/';
    private const INSPECTION_BASE = 'https://searchconsole.googleapis.com/v1/';
    private const INDEXING_BASE = 'https://indexing.googleapis.com/v3/';

    public function slug(): string
    {
        return 'google_search_console';
    }

    public function authenticate(array $credentials): array
    {
        $expiresAt = $credentials['expires_at'] ?? null;

        if ($expiresAt && now()->timestamp < $expiresAt - 60) {
            return $credentials;
        }

        if (empty($credentials['refresh_token'])) {
            throw new AuthExpiredException('Refresh token Search Console absent, reconnexion OAuth requise.');
        }

        return $this->refreshOAuthToken(self::TOKEN_URL, [
            'client_id' => config('mcp.connectors.google_search_console.client_id'),
            'client_secret' => config('mcp.connectors.google_search_console.client_secret'),
            'refresh_token' => $credentials['refresh_token'],
            'grant_type' => 'refresh_token',
        ], $credentials);
    }

    public function listTools(): array
    {
        return [
            new ToolSchema('google_search_console', 'get_search_analytics',
                "Récupère les performances observées dans les résultats Google sur une période donnée (clics, impressions, CTR et position moyenne), agrégées selon la dimension demandée. Utilise cet outil lorsqu'une analyse globale des performances SEO est demandée ou avant d'approfondir une analyse par requête ou par page. Ne l'utilise pas pour inspecter une URL unique ni pour mesurer le trafic réel du site. Les données proviennent de Google Search Console, peuvent présenter un délai de disponibilité et ne doivent jamais être complétées ou extrapolées.",
                ['type' => 'object', 'properties' => [
                    'date_from' => ['type' => 'string', 'description' => 'YYYY-MM-DD, défaut: -28 jours'],
                    'date_to' => ['type' => 'string', 'description' => 'YYYY-MM-DD, défaut: -3 jours (délai de disponibilité des données Search Console)'],
                    'dimension' => ['type' => 'string', 'enum' => ['query', 'page', 'country', 'device', 'date'], 'description' => 'défaut: query'],
                    'limit' => ['type' => 'integer', 'description' => 'défaut 20, max 100'],
                ]], defaultMode: 'auto', capability: 'seo.search_analytics'),

            new ToolSchema('google_search_console', 'get_top_queries',
                "Retourne les principales requêtes ayant généré des impressions ou des clics dans les résultats Google sur la période demandée. Utilise cet outil lorsqu'un utilisateur souhaite savoir sur quels termes son site apparaît ou performe le mieux. Ne l'utilise pas pour analyser un mot-clé spécifique ni pour obtenir des opportunités SEO : utilise alors les outils appropriés. Les requêtes retournées correspondent uniquement aux données observées dans Search Console pendant la période sélectionnée.",
                ['type' => 'object', 'properties' => [
                    'date_from' => ['type' => 'string'], 'date_to' => ['type' => 'string'],
                    'sort_by' => ['type' => 'string', 'enum' => ['clicks', 'impressions'], 'description' => 'défaut: clicks'],
                    'limit' => ['type' => 'integer', 'description' => 'défaut 20, max 100'],
                ]], defaultMode: 'auto'),

            new ToolSchema('google_search_console', 'get_top_pages',
                "Retourne les pages ayant obtenu le plus de visibilité dans les résultats Google. Utilise cet outil pour identifier les contenus générant le plus de clics ou d'impressions depuis la recherche Google. Ne confonds jamais ces données avec les pages les plus visitées dans Google Analytics. Les résultats reflètent uniquement les performances SEO observées.",
                ['type' => 'object', 'properties' => [
                    'date_from' => ['type' => 'string'], 'date_to' => ['type' => 'string'],
                    'sort_by' => ['type' => 'string', 'enum' => ['clicks', 'impressions'], 'description' => 'défaut: clicks'],
                    'limit' => ['type' => 'integer', 'description' => 'défaut 20, max 100'],
                ]], defaultMode: 'auto'),

            new ToolSchema('google_search_console', 'list_sitemaps',
                "Retourne les sitemaps connus de Google pour la propriété ainsi que leur état de traitement. Utilise cet outil lorsqu'un problème d'indexation ou de découverte des URLs est suspecté. Ne l'utilise pas pour vérifier le statut d'une page individuelle. L'absence d'un sitemap ne permet pas de conclure que les pages correspondantes ne sont pas indexées.",
                ['type' => 'object', 'properties' => []], defaultMode: 'auto'),

            new ToolSchema('google_search_console', 'inspect_url',
                "Utilise uniquement pour une URL unique clairement identifiée. Ne l'utilise jamais pour diagnostiquer l'ensemble d'un site. Les informations retournées concernent exclusivement cette URL et ne doivent pas être généralisées aux autres pages.",
                ['type' => 'object', 'properties' => ['url' => ['type' => 'string', 'description' => 'URL complète à inspecter']], 'required' => ['url']],
                defaultMode: 'auto'),

            new ToolSchema('google_search_console', 'request_indexing',
                "Envoie à Google une demande de nouvelle exploration pour une URL précise après une création ou une modification significative. Utilise cet outil uniquement lorsque l'utilisateur souhaite accélérer la prise en compte d'une page par Google. Cette demande ne garantit ni une exploration rapide, ni une indexation, ni une amélioration du classement. Google reste seul décisionnaire. Vérifie de préférence le statut actuel avec inspect_url avant la demande lorsque cela est pertinent. N'utilise jamais cet outil de manière répétée ou en boucle sur un grand nombre d'URLs en raison des quotas de l'API.",
                ['type' => 'object', 'properties' => ['url' => ['type' => 'string', 'description' => 'URL complète pour laquelle demander une nouvelle exploration']], 'required' => ['url']],
                isWriteAction: true, defaultActorScope: 'admin', defaultMode: 'confirm', defaultConfirmActor: 'admin', capability: 'seo.request_indexing'),
        ];
    }

    public function callTool(string $toolName, array $params, array $credentials, array $context = []): ToolResult
    {
        return match ($toolName) {
            'get_search_analytics' => $this->searchAnalytics($params, $credentials),
            'get_top_queries' => $this->searchAnalytics(array_merge($params, ['dimension' => 'query']), $credentials, sortKey: $params['sort_by'] ?? 'clicks'),
            'get_top_pages' => $this->searchAnalytics(array_merge($params, ['dimension' => 'page']), $credentials, sortKey: $params['sort_by'] ?? 'clicks'),
            'list_sitemaps' => $this->listSitemaps($credentials),
            'inspect_url' => $this->inspectUrl($params, $credentials),
            'request_indexing' => $this->requestIndexing($params, $credentials),
            default => throw new ToolNotFoundException("Outil '{$toolName}' inconnu pour google_search_console."),
        };
    }

    // ── Implémentation ──────────────────────────────────────────────

    private function searchAnalytics(array $p, array $c, string $sortKey = 'clicks'): ToolResult
    {
        $siteUrl = $this->siteUrl($c);
        if (!$siteUrl) {
            return ToolResult::fail('not_configured', "Aucune propriété Search Console configurée pour ce site.");
        }

        $from = $p['date_from'] ?? now()->subDays(28)->toDateString();
        $to = $p['date_to'] ?? now()->subDays(3)->toDateString();
        $dimension = $p['dimension'] ?? 'query';
        $limit = max(1, min(100, (int) ($p['limit'] ?? 20)));

        try {
            $response = $this->client($c, self::SEARCH_ANALYTICS_BASE)->post(
                'sites/' . rawurlencode($siteUrl) . '/searchAnalytics/query',
                [
                    'startDate' => $from, 'endDate' => $to,
                    'dimensions' => [$dimension], 'rowLimit' => $limit,
                ]
            );
        } catch (RequestException $e) {
            $this->handleApiError($e);
        }
        $this->recordSuccess();
        $rows = $response->json('rows', []);

        $result = collect($rows)->map(fn ($r) => [
            $dimension => $r['keys'][0] ?? null,
            'clicks' => (int) ($r['clicks'] ?? 0),
            'impressions' => (int) ($r['impressions'] ?? 0),
            'ctr' => round(($r['ctr'] ?? 0) * 100, 2) . '%',
            'position' => round($r['position'] ?? 0, 1),
        ])->when($sortKey, fn ($c) => $c->sortByDesc($sortKey))->values()->all();

        if (empty($result)) {
            return ToolResult::fail('not_found', "Aucune donnée de performance de recherche sur cette période pour {$siteUrl}.");
        }
        return ToolResult::ok(['period' => "{$from} → {$to}", 'dimension' => $dimension, 'results' => $result], count($result) . " ligne(s) de performance de recherche.");
    }

    private function listSitemaps(array $c): ToolResult
    {
        $siteUrl = $this->siteUrl($c);
        if (!$siteUrl) {
            return ToolResult::fail('not_configured', "Aucune propriété Search Console configurée pour ce site.");
        }

        try {
            $response = $this->client($c, self::SEARCH_ANALYTICS_BASE)->get('sites/' . rawurlencode($siteUrl) . '/sitemaps');
        } catch (RequestException $e) {
            $this->handleApiError($e);
        }
        $this->recordSuccess();

        $sitemaps = collect($response->json('sitemap', []))->map(fn ($s) => [
            'path' => $s['path'] ?? null,
            'last_submitted' => $s['lastSubmitted'] ?? null,
            'is_pending' => $s['isPending'] ?? null,
            'errors' => $s['errors'] ?? 0,
            'warnings' => $s['warnings'] ?? 0,
        ])->all();

        if (empty($sitemaps)) {
            return ToolResult::fail('not_found', 'Aucun sitemap soumis pour cette propriété.');
        }
        return ToolResult::ok(['sitemaps' => $sitemaps], count($sitemaps) . ' sitemap(s) trouvé(s).');
    }

    private function inspectUrl(array $p, array $c): ToolResult
    {
        $siteUrl = $this->siteUrl($c);
        if (!$siteUrl) {
            return ToolResult::fail('not_configured', "Aucune propriété Search Console configurée pour ce site.");
        }

        try {
            $response = $this->client($c, self::INSPECTION_BASE)->post('urlInspection/index:inspect', [
                'inspectionUrl' => $p['url'], 'siteUrl' => $siteUrl,
            ]);
        } catch (RequestException $e) {
            if ($e->response?->status() === 400) {
                return ToolResult::fail('invalid_url', "L'URL fournie n'appartient pas à la propriété {$siteUrl} ou est mal formée.");
            }
            $this->handleApiError($e);
        }
        $this->recordSuccess();
        $result = $response->json('inspectionResult.indexStatusResult', []);

        return ToolResult::ok([
            'verdict' => $result['verdict'] ?? null,
            'coverage_state' => $result['coverageState'] ?? null,
            'is_indexed' => ($result['verdict'] ?? null) === 'PASS',
            'last_crawl' => $result['lastCrawlTime'] ?? null,
            'google_canonical' => $result['googleCanonical'] ?? null,
        ], "Statut d'indexation récupéré pour {$p['url']}.");
    }

    private function requestIndexing(array $p, array $c): ToolResult
    {
        try {
            $response = $this->client($c, self::INDEXING_BASE)->post('urlNotifications:publish', [
                'url' => $p['url'], 'type' => 'URL_UPDATED',
            ]);
        } catch (RequestException $e) {
            if ($e->response?->status() === 403) {
                return ToolResult::fail('quota_or_permission', "Demande d'indexation refusée par Google (quota quotidien atteint ou permission insuffisante sur cette propriété).");
            }
            if ($e->response?->status() === 400) {
                return ToolResult::fail('invalid_url', "URL invalide ou n'appartenant pas à une propriété vérifiée.");
            }
            $this->handleApiError($e);
        }
        $this->recordSuccess();
        $notifyTime = $response->json('urlNotificationMetadata.latestUpdate.notifyTime');

        return ToolResult::ok([
            'url' => $p['url'], 'submitted_at' => $notifyTime,
        ], "Demande de nouvelle exploration envoyée à Google pour {$p['url']}. Cela ne garantit ni un délai ni une indexation effective — Google reste seul décisionnaire.");
    }

    // ── Utilitaires ──────────────────────────────────────────────────

    private function siteUrl(array $c): ?string
    {
        return $c['site_url'] ?? null;
    }

    private function client(array $credentials, string $baseUrl)
    {
        return $this->http($baseUrl)->withToken($credentials['access_token']);
    }

    private function handleApiError(RequestException $e): never
    {
        $status = $e->response?->status();
        $body = $e->response?->body();
        Log::error('MCP Google Search Console: appel API échoué', ['status' => $status, 'body' => $body]);

        if (in_array($status, [401, 403])) {
            throw new AuthExpiredException('Accès Search Console refusé ou expiré, reconnexion requise.');
        }
        throw new ConnectorUnavailableException('Search Console indisponible: ' . $e->getMessage());
    }
}
