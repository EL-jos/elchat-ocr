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
 * Connecteur DataForSEO (API v3), lecture seule — SEO/SEM et données SERP.
 * credentials attendus (déchiffrés) : { "login": "...", "password": "..." }
 * Particularité : le "password" n'est JAMAIS le mot de passe du compte
 * DataForSEO, mais un mot de passe API généré séparément (Dashboard > API
 * Dashboard > API Access), authentifié en HTTP Basic Auth — comme Trello,
 * un deuxième connecteur à identifiants doubles plutôt qu'une clé unique.
 * settings attendus (optionnel) : {
 *   "default_domain": "exemple.com",
 *   "location_name": "France",   // défaut: "United States" si absent
 *   "language_name": "French"    // défaut: "English" si absent
 * }
 *
 * Particularité technique majeure : CHAQUE endpoint DataForSEO répond avec
 * la même enveloppe { status_code, tasks: [{ status_code, result }] }, y
 * compris en cas d'erreur MÉTIER (mot-clé introuvable, quota dépassé) — un
 * statut HTTP 200 ne signifie donc jamais un succès en soi. runTask()
 * centralise cette vérification pour tous les outils plutôt que de la
 * dupliquer, et distingue explicitement erreur d'authentification (401
 * HTTP, ou status_code 40100/40101 dans l'enveloppe), erreur métier
 * (ex: 40501 "not found"), et indisponibilité réseau.
 *
 * Périmètre volontairement limité au requêtage "live" (synchrone, résultat
 * immédiat) : l'API DataForSEO propose aussi un mode "task-based" asynchrone
 * (créer une tâche, interroger son statut plus tard) pour les gros volumes
 * ou l'On-Page API — hors scope d'un outil conversationnel qui doit
 * répondre dans le tour de parole en cours, pas polling en arrière-plan.
 */
class DataForSeoConnector extends AbstractConnector
{
    private const API_BASE = 'https://api.dataforseo.com/v3/';

    public function slug(): string
    {
        return 'dataforseo';
    }

    public function authenticate(array $credentials): array
    {
        if (empty($credentials['login']) || empty($credentials['password'])) {
            throw new AuthExpiredException('Identifiants API DataForSEO (login/password) manquants.');
        }
        return $credentials; // pas de rafraîchissement : identifiants statiques
    }

    public function listTools(): array
    {
        return [
            new ToolSchema('dataforseo', 'get_domain_overview',
                "Retourne une vue d'ensemble SEO d'un domaine : nombre de mots-clés organiques positionnés, trafic organique estimé, valeur de trafic estimée, position moyenne. Utiliser pour évaluer la performance SEO globale d'un domaine (le sien ou celui d'un concurrent explicitement nommé). Si aucun domaine n'est précisé, utilise le domaine configuré par défaut pour ce site.",
                ['type' => 'object', 'properties' => [
                    'domain' => ['type' => 'string', 'description' => 'défaut: domaine configuré du site'],
                ]], defaultActorScope: 'admin', defaultMode: 'auto', capability: 'seo.competitive_overview'),

            new ToolSchema('dataforseo', 'get_organic_keywords',
                "Retourne les principaux mots-clés sur lesquels un domaine est positionné dans les résultats organiques Google, avec position, volume de recherche mensuel et URL classée. Utiliser pour analyser le positionnement SEO réel d'un domaine, le sien ou un concurrent.",
                ['type' => 'object', 'properties' => [
                    'domain' => ['type' => 'string', 'description' => 'défaut: domaine configuré du site'],
                    'limit' => ['type' => 'integer', 'description' => 'défaut 20, max 100'],
                ]], defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('dataforseo', 'get_keyword_overview',
                "Retourne, pour un mot-clé précis, son volume de recherche mensuel, sa difficulté SEO estimée, son CPC moyen et le niveau de concurrence publicitaire. Utiliser pour évaluer l'intérêt ou la faisabilité de cibler un mot-clé donné, jamais pour analyser un domaine dans son ensemble.",
                ['type' => 'object', 'properties' => ['keyword' => ['type' => 'string']], 'required' => ['keyword']],
                defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('dataforseo', 'get_backlinks_summary',
                "Retourne une synthèse du profil de liens entrants (backlinks) d'un domaine : nombre total de backlinks, domaines référents uniques, score de rang. Utiliser pour évaluer la notoriété/l'autorité d'un domaine, pas son positionnement mots-clés.",
                ['type' => 'object', 'properties' => [
                    'domain' => ['type' => 'string', 'description' => 'défaut: domaine configuré du site'],
                ]], defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('dataforseo', 'get_competitors',
                "Retourne les principaux concurrents organiques d'un domaine, c'est-à-dire les domaines qui se positionnent sur les mêmes mots-clés, classés par niveau de similarité et mots-clés communs. Utiliser pour identifier automatiquement des concurrents, jamais pour comparer deux domaines déjà connus (utiliser get_domain_overview sur chacun dans ce cas).",
                ['type' => 'object', 'properties' => [
                    'domain' => ['type' => 'string', 'description' => 'défaut: domaine configuré du site'],
                    'limit' => ['type' => 'integer', 'description' => 'défaut 10, max 50'],
                ]], defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('dataforseo', 'get_serp_results',
                "Effectue une recherche Google en direct pour un mot-clé et retourne les 10 premiers résultats organiques (titre, URL, position, snippet), ainsi que la position du domaine configuré s'il y apparaît. Utiliser pour voir ce qu'un visiteur voit réellement sur Google à l'instant T pour une requête donnée — plus précis que get_organic_keywords qui repose sur des données historiques agrégées.",
                ['type' => 'object', 'properties' => [
                    'keyword' => ['type' => 'string'], 'domain' => ['type' => 'string', 'description' => "domaine dont on veut connaître la position dans ces résultats, optionnel — défaut: domaine configuré du site"],
                ], 'required' => ['keyword']], defaultActorScope: 'admin', defaultMode: 'auto'),
        ];
    }

    public function callTool(string $toolName, array $params, array $credentials, array $context = []): ToolResult
    {
        return match ($toolName) {
            'get_domain_overview' => $this->domainOverview($params, $credentials),
            'get_organic_keywords' => $this->organicKeywords($params, $credentials),
            'get_keyword_overview' => $this->keywordOverview($params, $credentials),
            'get_backlinks_summary' => $this->backlinksSummary($params, $credentials),
            'get_competitors' => $this->competitors($params, $credentials),
            'get_serp_results' => $this->serpResults($params, $credentials),
            default => throw new ToolNotFoundException("Outil '{$toolName}' inconnu pour dataforseo."),
        };
    }

    // ── Implémentation ──────────────────────────────────────────────

    private function domainOverview(array $p, array $c): ToolResult
    {
        $domain = $this->resolveDomain($p, $c);
        if (!$domain) {
            return ToolResult::fail('not_configured', 'Aucun domaine précisé et aucun domaine par défaut configuré pour ce site.');
        }

        $result = $this->runTask($c, 'dataforseo_labs/google/domain_rank_overview/live', [[
            'target' => $domain,
            'location_name' => $this->locationName($c), 'language_name' => $this->languageName($c),
        ]]);

        $metrics = $result[0]['items'][0]['metrics']['organic'] ?? null;
        if (!$metrics) {
            return ToolResult::fail('not_found', "Aucune donnée disponible pour {$domain}.");
        }

        return ToolResult::ok([
            'domain' => $domain,
            'organic_keywords' => $metrics['count'] ?? 0,
            'estimated_traffic_value' => round($metrics['etv'] ?? 0, 2),
            'average_position' => $metrics['pos_1_10'] ?? null,
        ], "Vue d'ensemble SEO récupérée pour {$domain}.");
    }

    private function organicKeywords(array $p, array $c): ToolResult
    {
        $domain = $this->resolveDomain($p, $c);
        if (!$domain) {
            return ToolResult::fail('not_configured', 'Aucun domaine précisé et aucun domaine par défaut configuré pour ce site.');
        }
        $limit = max(1, min(100, (int) ($p['limit'] ?? 20)));

        $result = $this->runTask($c, 'dataforseo_labs/google/ranked_keywords/live', [[
            'target' => $domain, 'limit' => $limit,
            'location_name' => $this->locationName($c), 'language_name' => $this->languageName($c),
        ]]);

        $items = $result[0]['items'] ?? [];
        if (empty($items)) {
            return ToolResult::fail('not_found', "Aucun mot-clé organique trouvé pour {$domain}.");
        }

        $keywords = collect($items)->map(fn ($i) => [
            'keyword' => $i['keyword_data']['keyword'] ?? null,
            'position' => $i['ranked_serp_element']['serp_item']['rank_absolute'] ?? null,
            'search_volume' => $i['keyword_data']['keyword_info']['search_volume'] ?? null,
            'url' => $i['ranked_serp_element']['serp_item']['url'] ?? null,
        ])->all();

        return ToolResult::ok(['domain' => $domain, 'keywords' => $keywords], count($keywords) . ' mot(s)-clé(s) organique(s) trouvé(s).');
    }

    private function keywordOverview(array $p, array $c): ToolResult
    {
        $result = $this->runTask($c, 'dataforseo_labs/google/keyword_overview/live', [[
            'keywords' => [$p['keyword']],
            'location_name' => $this->locationName($c), 'language_name' => $this->languageName($c),
        ]]);

        $item = $result[0]['items'][0] ?? null;
        if (!$item) {
            return ToolResult::fail('not_found', "Aucune donnée disponible pour le mot-clé « {$p['keyword']} ».");
        }
        $info = $item['keyword_info'] ?? [];
        $difficulty = $item['keyword_properties']['keyword_difficulty'] ?? null;

        return ToolResult::ok([
            'keyword' => $item['keyword'] ?? $p['keyword'],
            'search_volume' => $info['search_volume'] ?? null,
            'cpc' => isset($info['cpc']) ? round($info['cpc'], 2) : null,
            'competition' => $info['competition_level'] ?? null,
            'keyword_difficulty' => $difficulty,
        ], "Données récupérées pour « {$p['keyword']} ».");
    }

    private function backlinksSummary(array $p, array $c): ToolResult
    {
        $domain = $this->resolveDomain($p, $c);
        if (!$domain) {
            return ToolResult::fail('not_configured', 'Aucun domaine précisé et aucun domaine par défaut configuré pour ce site.');
        }

        $result = $this->runTask($c, 'backlinks/summary/live', [[
            'target' => $domain, 'internal_list_limit' => 10,
        ]]);

        $item = $result[0] ?? null;
        if (!$item) {
            return ToolResult::fail('not_found', "Aucune donnée de backlinks disponible pour {$domain}.");
        }

        return ToolResult::ok([
            'domain' => $domain,
            'rank' => $item['rank'] ?? null,
            'total_backlinks' => $item['backlinks'] ?? 0,
            'referring_domains' => $item['referring_domains'] ?? 0,
            'referring_main_domains' => $item['referring_main_domains'] ?? 0,
        ], "Synthèse des backlinks récupérée pour {$domain}.");
    }

    private function competitors(array $p, array $c): ToolResult
    {
        $domain = $this->resolveDomain($p, $c);
        if (!$domain) {
            return ToolResult::fail('not_configured', 'Aucun domaine précisé et aucun domaine par défaut configuré pour ce site.');
        }
        $limit = max(1, min(50, (int) ($p['limit'] ?? 10)));

        $result = $this->runTask($c, 'dataforseo_labs/google/competitors_domain/live', [[
            'target' => $domain, 'limit' => $limit,
            'location_name' => $this->locationName($c), 'language_name' => $this->languageName($c),
        ]]);

        $items = $result[0]['items'] ?? [];
        if (empty($items)) {
            return ToolResult::fail('not_found', "Aucun concurrent organique trouvé pour {$domain}.");
        }

        $competitors = collect($items)->map(fn ($i) => [
            'domain' => $i['domain'] ?? null,
            'common_keywords' => $i['intersections'] ?? null,
            'organic_traffic' => $i['metrics']['organic']['etv'] ?? null,
            'organic_keywords' => $i['metrics']['organic']['count'] ?? null,
        ])->all();

        return ToolResult::ok(['domain' => $domain, 'competitors' => $competitors], count($competitors) . ' concurrent(s) trouvé(s).');
    }

    private function serpResults(array $p, array $c): ToolResult
    {
        $result = $this->runTask($c, 'serp/google/organic/live/regular', [[
            'keyword' => $p['keyword'],
            'location_name' => $this->locationName($c), 'language_name' => $this->languageName($c),
            'device' => 'desktop', 'depth' => 10,
        ]]);

        $items = collect($result[0]['items'] ?? [])->where('type', 'organic')->values();
        if ($items->isEmpty()) {
            return ToolResult::fail('not_found', "Aucun résultat organique trouvé pour « {$p['keyword']} ».");
        }

        $targetDomain = $this->resolveDomain($p, $c);
        $targetPosition = $targetDomain
            ? $items->first(fn ($i) => str_contains($i['domain'] ?? '', $targetDomain))['rank_absolute'] ?? null
            : null;

        $results = $items->take(10)->map(fn ($i) => [
            'position' => $i['rank_absolute'] ?? null, 'title' => $i['title'] ?? null,
            'url' => $i['url'] ?? null, 'domain' => $i['domain'] ?? null, 'snippet' => $i['description'] ?? null,
        ])->all();

        return ToolResult::ok([
            'keyword' => $p['keyword'], 'results' => $results,
            'target_domain' => $targetDomain, 'target_position' => $targetPosition,
        ], $targetDomain
            ? ($targetPosition ? "{$targetDomain} est en position {$targetPosition} sur « {$p['keyword']} »." : "{$targetDomain} n'apparaît pas dans le top 10 pour « {$p['keyword']} ».")
            : "Résultats Google récupérés pour « {$p['keyword']} ».");
    }

    // ── Utilitaires ──────────────────────────────────────────────────

    /**
     * Poste une tâche "live" DataForSEO et retourne son tableau `result`
     * déjà validé. Centralise la double vérification de statut (enveloppe
     * globale + tâche individuelle) commune à tous les endpoints — un 200
     * HTTP ne garantit jamais un succès métier avec cette API.
     */
    private function runTask(array $credentials, string $endpoint, array $payload): array
    {
        try {
            $response = $this->client($credentials)->post($endpoint, $payload);
        } catch (RequestException $e) {
            $this->handleApiError($e);
        }

        $body = $response->json();

        if (($body['status_code'] ?? null) !== 20000) {
            $this->handleEnvelopeError($body['status_code'] ?? null, $body['status_message'] ?? 'Erreur inconnue');
        }

        $task = $body['tasks'][0] ?? null;
        if (!$task || ($task['status_code'] ?? null) !== 20000) {
            $this->handleEnvelopeError($task['status_code'] ?? null, $task['status_message'] ?? 'Erreur inconnue');
        }

        $this->recordSuccess();
        return $task['result'] ?? [];
    }

    private function handleEnvelopeError(?int $statusCode, string $message): never
    {
        Log::warning('MCP DataForSEO: tâche en erreur', ['status_code' => $statusCode, 'message' => $message]);

        // 40100/40101/40102 = identifiants invalides ou insuffisants selon
        // la doc DataForSEO ; le reste (quota, mot-clé mal formé, etc.) est
        // traité comme une indisponibilité générique du connecteur.
        if (in_array($statusCode, [40100, 40101, 40102])) {
            throw new AuthExpiredException('Identifiants API DataForSEO invalides ou insuffisants: ' . $message);
        }
        throw new ConnectorUnavailableException('DataForSEO: ' . $message);
    }

    private function resolveDomain(array $p, array $c): ?string
    {
        return $p['domain'] ?? $c['default_domain'] ?? null;
    }

    private function locationName(array $c): string
    {
        return $c['location_name'] ?? 'United States';
    }

    private function languageName(array $c): string
    {
        return $c['language_name'] ?? 'English';
    }

    private function client(array $credentials)
    {
        return $this->http(self::API_BASE)->withBasicAuth($credentials['login'], $credentials['password']);
    }

    private function handleApiError(RequestException $e): never
    {
        $status = $e->response?->status();
        $body = $e->response?->body();
        Log::error('MCP DataForSEO: appel API échoué', ['status' => $status, 'body' => $body]);

        if (in_array($status, [401, 403])) {
            throw new AuthExpiredException('Identifiants API DataForSEO invalides ou révoqués.');
        }
        throw new ConnectorUnavailableException('DataForSEO indisponible: ' . $e->getMessage());
    }
}
