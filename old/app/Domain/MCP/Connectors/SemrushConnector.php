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
 * Connecteur Semrush (SEO/SEM competitive intelligence), lecture seule.
 * credentials attendus (déchiffrés) : { "api_key": "..." }
 * settings attendus : { "domain": "exemple.com", "database": "fr" } — domaine
 * de référence pour les analyses "à domicile" (sans domain explicite dans
 * les params) et base de données géographique Semrush par défaut
 * (fr, us, uk, de...), voir liste complète dans la doc Semrush.
 *
 * Particularité technique: l'API Semrush ne répond PAS en JSON mais en texte
 * délimité par ";" (une ligne d'en-têtes puis une ligne par résultat), et le
 * jeton d'authentification est passé en paramètre de requête ("key"), jamais
 * en en-tête Authorization. parseCsv() centralise la conversion en tableau
 * associatif exploitable par le LLM.
 *
 * Aucune écriture : Semrush est une source de données tierce en lecture
 * seule, il n'existe pas d'action de modification pertinente.
 */
class SemrushConnector extends AbstractConnector
{
    private const API_BASE = 'https://api.semrush.com/';

    public function slug(): string
    {
        return 'semrush';
    }

    public function authenticate(array $credentials): array
    {
        if (empty($credentials['api_key'])) {
            throw new AuthExpiredException('Clé API Semrush manquante.');
        }
        return $credentials; // pas de rafraîchissement : clé API statique
    }

    public function listTools(): array
    {
        return [
            new ToolSchema('semrush', 'get_domain_overview',
                "Récupère une synthèse SEO d'un domaine (trafic organique estimé, mots-clés positionnés, coût du trafic, score d'autorité). Utilise cet outil lorsqu'une vision globale d'un domaine est demandée ou avant d'approfondir une analyse SEO. N'utilise pas cet outil pour obtenir la liste des mots-clés, des backlinks ou des concurrents. Si aucun domaine n'est fourni, utilise le domaine configuré uniquement s'il représente clairement le site analysé. Ne déduis jamais des informations absentes du résultat.",
                ['type' => 'object', 'properties' => [
                    'domain' => ['type' => 'string', 'description' => 'défaut: domaine configuré du site'],
                    'database' => ['type' => 'string', 'description' => 'code base Semrush (fr, us, uk...), défaut: celle configurée'],
                ]], defaultMode: 'auto', capability: 'seo.competitive_overview'),

            new ToolSchema('semrush', 'get_organic_keywords',
                "Retourne les principaux mots-clés organiques d'un domaine avec leurs indicateurs SEO. Utilise cet outil uniquement lorsqu'une analyse des requêtes sur lesquelles un domaine est positionné est demandée. Ne l'utilise pas pour estimer les performances globales du domaine ni pour analyser un mot-clé individuel. Les résultats sont limités au nombre demandé et ne représentent pas l'ensemble du positionnement SEO du domaine.",
                ['type' => 'object', 'properties' => [
                    'domain' => ['type' => 'string', 'description' => 'défaut: domaine configuré du site'],
                    'database' => ['type' => 'string'],
                    'limit' => ['type' => 'integer', 'description' => 'défaut 20, max 100'],
                ]], defaultMode: 'auto'),

            new ToolSchema('semrush', 'get_keyword_overview',
                "Analyse un mot-clé unique. Utilise cet outil lorsqu'un utilisateur souhaite connaître le potentiel SEO ou publicitaire d'une requête précise. Ne l'utilise jamais pour analyser un domaine. Si plusieurs mots-clés sont mentionnés, traite uniquement ceux explicitement demandés ou demande une précision avant plusieurs appels.",
                ['type' => 'object', 'properties' => [
                    'keyword' => ['type' => 'string'], 'database' => ['type' => 'string'],
                ], 'required' => ['keyword']], defaultMode: 'auto'),

            new ToolSchema('semrush', 'get_backlinks_overview',
                "Fournit une synthèse du profil de backlinks d'un domaine afin d'évaluer son autorité et sa popularité. Utilise cet outil lorsque la demande concerne les liens entrants ou l'autorité SEO. Ne l'utilise pas pour mesurer le trafic, le positionnement ou les mots-clés. Les valeurs retournées sont des indicateurs de l'API Semrush et ne doivent pas être extrapolées.",
                ['type' => 'object', 'properties' => [
                    'domain' => ['type' => 'string', 'description' => 'défaut: domaine configuré du site'],
                ]], defaultMode: 'auto'),

            new ToolSchema('semrush', 'get_competitors',
                "Identifie les principaux concurrents organiques d'un domaine à partir des mots-clés communs observés par Semrush. Utilise cet outil uniquement lorsque les concurrents ne sont pas déjà connus. Si l'utilisateur demande de comparer deux domaines précis, appelle plutôt get_domain_overview pour chacun d'eux. Les concurrents retournés correspondent à une similarité SEO observée par Semrush et ne constituent pas nécessairement des concurrents commerciaux directs.",
                ['type' => 'object', 'properties' => [
                    'domain' => ['type' => 'string', 'description' => 'défaut: domaine configuré du site'],
                    'database' => ['type' => 'string'], 'limit' => ['type' => 'integer', 'description' => 'défaut 10, max 50'],
                ]], defaultMode: 'auto'),

            new ToolSchema('semrush', 'suggest_keyword_opportunities',
                "Identifie des opportunités de mots-clés susceptibles d'améliorer le référencement organique d'un domaine.

Utilise cet outil lorsqu'un utilisateur demande quels mots-clés cibler, quels contenus créer, comment développer sa visibilité SEO ou quelles opportunités de positionnement explorer.

Ne l'utilise pas pour analyser un mot-clé individuel, obtenir une vue d'ensemble d'un domaine ou récupérer simplement ses mots-clés actuels. Utilise respectivement get_keyword_overview, get_domain_overview ou get_organic_keywords.

Si aucun seed_keyword n'est fourni, l'outil dérive automatiquement des thèmes à partir des principaux mots-clés organiques du domaine. Si competitor_domain est fourni, il ajoute les opportunités issues de l'écart de positionnement observé avec ce concurrent.

Les recommandations, leurs métriques et leurs justifications proviennent exclusivement des données calculées par l'outil. Restitue-les fidèlement sans les compléter, modifier ou extrapoler. Ne promets jamais une amélioration de classement, de trafic ou de performance SEO. Présente les résultats comme des opportunités potentielles fondées sur les données disponibles, et non comme des résultats garantis.",
                ['type' => 'object', 'properties' => [
                    'seed_keyword' => ['type' => 'string', 'description' => "mot-clé de départ ; si absent, dérivé automatiquement des meilleurs mots-clés actuels du domaine"],
                    'competitor_domain' => ['type' => 'string', 'description' => 'optionnel : domaine concurrent pour une analyse de gap'],
                    'domain' => ['type' => 'string', 'description' => 'défaut: domaine configuré du site'],
                    'database' => ['type' => 'string'],
                    'limit' => ['type' => 'integer', 'description' => 'défaut 15, max 30'],
                ]], defaultMode: 'auto', capability: 'seo.keyword_suggestions'),
        ];
    }

    public function callTool(string $toolName, array $params, array $credentials, array $context = []): ToolResult
    {
        return match ($toolName) {
            'get_domain_overview' => $this->domainOverview($params, $credentials),
            'get_organic_keywords' => $this->organicKeywords($params, $credentials),
            'get_keyword_overview' => $this->keywordOverview($params, $credentials),
            'get_backlinks_overview' => $this->backlinksOverview($params, $credentials),
            'get_competitors' => $this->competitors($params, $credentials),
            'suggest_keyword_opportunities' => $this->suggestKeywordOpportunities($params, $credentials),
            default => throw new ToolNotFoundException("Outil '{$toolName}' inconnu pour semrush."),
        };
    }

    // ── Implémentation ──────────────────────────────────────────────

    private function domainOverview(array $p, array $c): ToolResult
    {
        $domain = $this->resolveDomain($p, $c);
        if (!$domain) {
            return ToolResult::fail('not_configured', 'Aucun domaine précisé et aucun domaine par défaut configuré pour ce site.');
        }

        $rows = $this->query($c, [
            'type' => 'domain_ranks',
            'domain' => $domain,
            'database' => $this->database($p, $c),
            'export_columns' => 'Dn,Rk,Or,Ot,Oc,At',
        ]);

        if (empty($rows)) {
            return ToolResult::fail('not_found', "Aucune donnée Semrush disponible pour {$domain} sur cette base.");
        }
        $row = $rows[0];

        return ToolResult::ok([
            'domain' => $row['Domain'] ?? $domain,
            'rank' => $row['Rank'] ?? null,
            'organic_keywords' => (int) ($row['Organic Keywords'] ?? 0),
            'organic_traffic' => (int) ($row['Organic Traffic'] ?? 0),
            'organic_cost' => round((float) ($row['Organic Cost'] ?? 0), 2),
            'authority_score' => $row['Authority Score'] ?? null,
        ], "Vue d'ensemble SEO récupérée pour {$domain}.");
    }

    private function organicKeywords(array $p, array $c): ToolResult
    {
        $domain = $this->resolveDomain($p, $c);
        if (!$domain) {
            return ToolResult::fail('not_configured', 'Aucun domaine précisé et aucun domaine par défaut configuré pour ce site.');
        }
        $limit = max(1, min(100, (int) ($p['limit'] ?? 20)));

        $rows = $this->query($c, [
            'type' => 'domain_organic',
            'domain' => $domain,
            'database' => $this->database($p, $c),
            'display_limit' => $limit,
            'export_columns' => 'Ph,Po,Nq,Ur,Tr',
        ]);

        if (empty($rows)) {
            return ToolResult::fail('not_found', "Aucun mot-clé organique trouvé pour {$domain}.");
        }

        $keywords = array_map(fn ($r) => [
            'keyword' => $r['Keyword'] ?? null,
            'position' => isset($r['Position']) ? (int) $r['Position'] : null,
            'search_volume' => isset($r['Search Volume']) ? (int) $r['Search Volume'] : null,
            'url' => $r['Url'] ?? null,
            'traffic_percent' => $r['Traffic (%)'] ?? null,
        ], $rows);

        return ToolResult::ok(['domain' => $domain, 'keywords' => $keywords], count($keywords) . ' mot(s)-clé(s) organique(s) trouvé(s).');
    }

    private function keywordOverview(array $p, array $c): ToolResult
    {
        $rows = $this->query($c, [
            'type' => 'phrase_this',
            'phrase' => $p['keyword'],
            'database' => $this->database($p, $c),
            'export_columns' => 'Ph,Nq,Cp,Kd',
        ]);

        if (empty($rows)) {
            return ToolResult::fail('not_found', "Aucune donnée Semrush disponible pour le mot-clé « {$p['keyword']} ».");
        }
        $row = $rows[0];

        return ToolResult::ok([
            'keyword' => $row['Keyword'] ?? $p['keyword'],
            'search_volume' => isset($row['Search Volume']) ? (int) $row['Search Volume'] : null,
            'cpc' => isset($row['CPC']) ? round((float) $row['CPC'], 2) : null,
            'keyword_difficulty' => $row['Keyword Difficulty Index'] ?? null,
        ], "Données récupérées pour « {$p['keyword']} ».");
    }

    private function backlinksOverview(array $p, array $c): ToolResult
    {
        $domain = $this->resolveDomain($p, $c);
        if (!$domain) {
            return ToolResult::fail('not_configured', 'Aucun domaine précisé et aucun domaine par défaut configuré pour ce site.');
        }

        $rows = $this->query($c, [
            'type' => 'backlinks_overview',
            'target' => $domain,
            'target_type' => 'root_domain',
            'export_columns' => 'ascore,total,domains_num',
        ]);

        if (empty($rows)) {
            return ToolResult::fail('not_found', "Aucune donnée de backlinks disponible pour {$domain}.");
        }
        $row = $rows[0];

        return ToolResult::ok([
            'domain' => $domain,
            'authority_score' => $row['Ascore'] ?? null,
            'total_backlinks' => isset($row['Total']) ? (int) $row['Total'] : null,
            'referring_domains' => isset($row['Referring Domains']) ? (int) $row['Referring Domains'] : null,
        ], "Synthèse des backlinks récupérée pour {$domain}.");
    }

    private function competitors(array $p, array $c): ToolResult
    {
        $domain = $this->resolveDomain($p, $c);
        if (!$domain) {
            return ToolResult::fail('not_configured', 'Aucun domaine précisé et aucun domaine par défaut configuré pour ce site.');
        }
        $limit = max(1, min(50, (int) ($p['limit'] ?? 10)));

        $rows = $this->query($c, [
            'type' => 'domain_organic_organic',
            'domain' => $domain,
            'database' => $this->database($p, $c),
            'display_limit' => $limit,
            'export_columns' => 'Dn,Cr,Np,Or',
        ]);

        if (empty($rows)) {
            return ToolResult::fail('not_found', "Aucun concurrent organique trouvé pour {$domain}.");
        }

        $competitors = array_map(fn ($r) => [
            'domain' => $r['Domain'] ?? null,
            'competitor_relevance' => $r['Competitor Relevance'] ?? null,
            'common_keywords' => isset($r['Common Keywords']) ? (int) $r['Common Keywords'] : null,
            'organic_traffic' => isset($r['Organic Traffic']) ? (int) $r['Organic Traffic'] : null,
        ], $rows);

        return ToolResult::ok(['domain' => $domain, 'competitors' => $competitors], count($competitors) . ' concurrent(s) trouvé(s).');
    }

    private function suggestKeywordOpportunities(array $p, array $c): ToolResult
    {
        $domain = $this->resolveDomain($p, $c);
        if (!$domain) {
            return ToolResult::fail('not_configured', 'Aucun domaine précisé et aucun domaine par défaut configuré pour ce site.');
        }
        $database = $this->database($p, $c);
        $limit = max(1, min(30, (int) ($p['limit'] ?? 15)));

        // Positions actuelles du domaine, pour ne pas ré-suggérer ce qui est déjà bien classé.
        $currentRows = $this->query($c, [
            'type' => 'domain_organic', 'domain' => $domain, 'database' => $database,
            'display_limit' => 200, 'export_columns' => 'Ph,Po',
        ]);
        $currentPositions = [];
        foreach ($currentRows as $row) {
            if (!empty($row['Keyword'])) {
                $currentPositions[strtolower($row['Keyword'])] = (int) ($row['Position'] ?? 0);
            }
        }

        // Seeds : fournis par l'utilisateur, sinon dérivés des mots-clés qui
        // apportent déjà le plus de trafic au domaine (signal de pertinence
        // thématique le plus fiable qu'on puisse déduire automatiquement).
        $seeds = [];
        if (!empty($p['seed_keyword'])) {
            $seeds = [$p['seed_keyword']];
        } else {
            $topRows = $this->query($c, [
                'type' => 'domain_organic', 'domain' => $domain, 'database' => $database,
                'display_limit' => 3, 'display_sort' => 'tr_desc', 'export_columns' => 'Ph',
            ]);
            $seeds = array_filter(array_column($topRows, 'Keyword'));
        }
        if (empty($seeds)) {
            return ToolResult::fail('not_found', "Impossible de déduire des mots-clés de départ : le domaine {$domain} n'a pas encore de mots-clés positionnés. Fournissez un seed_keyword explicite.");
        }

        // Mots-clés proches de chaque seed, dédupliqués.
        $candidates = [];
        foreach ($seeds as $seed) {
            $related = $this->query($c, [
                'type' => 'phrase_related', 'phrase' => $seed, 'database' => $database,
                'display_limit' => 30, 'export_columns' => 'Ph,Nq,Cp,Kd,Co',
            ]);
            foreach ($related as $row) {
                $kw = strtolower($row['Keyword'] ?? '');
                if ($kw === '' || isset($candidates[$kw])) {
                    continue;
                }
                $candidates[$kw] = [
                    'keyword' => $row['Keyword'],
                    'search_volume' => (int) ($row['Search Volume'] ?? 0),
                    'cpc' => round((float) ($row['CPC'] ?? 0), 2),
                    'keyword_difficulty' => $row['Keyword Difficulty Index'] ?? null,
                    'competition' => isset($row['Competition']) ? round((float) $row['Competition'], 2) : null,
                    'current_position' => $currentPositions[$kw] ?? null,
                    'source' => 'related',
                ];
            }
        }

        // Écart concurrentiel optionnel : mots-clés où le concurrent se
        // positionne mais pas le domaine (report "Domain vs. Domain").
        if (!empty($p['competitor_domain'])) {
            $domainsExpr = '*|or|' . $p['competitor_domain'] . '|-|or|' . $domain;
            $gapRows = $this->query($c, [
                'type' => 'domain_domains', 'domains' => $domainsExpr, 'database' => $database,
                'display_limit' => 30, 'export_columns' => 'Ph,Nq,Kd,Cp',
            ]);
            foreach ($gapRows as $row) {
                $kw = strtolower($row['Keyword'] ?? '');
                if ($kw === '') {
                    continue;
                }
                $candidates[$kw] = [
                    'keyword' => $row['Keyword'],
                    'search_volume' => (int) ($row['Search Volume'] ?? 0),
                    'cpc' => round((float) ($row['CPC'] ?? 0), 2),
                    'keyword_difficulty' => $row['Keyword Difficulty Index'] ?? null,
                    'competition' => null,
                    'current_position' => $currentPositions[$kw] ?? null,
                    'source' => 'competitor_gap',
                    'competitor_domain' => $p['competitor_domain'],
                ];
            }
        }

        if (empty($candidates)) {
            return ToolResult::fail('not_found', "Aucune opportunité de mot-clé trouvée à partir de " . implode(', ', $seeds) . '.');
        }

        // Position 1-10 = déjà bien classé : pas une opportunité à proposer.
        $opportunities = array_filter($candidates, fn ($c) => $c['current_position'] === null || $c['current_position'] > 10);

        usort($opportunities, fn ($a, $b) => $b['search_volume'] <=> $a['search_volume']);
        $opportunities = array_slice(array_values($opportunities), 0, $limit);

        foreach ($opportunities as &$opp) {
            $opp['reason'] = $this->buildKeywordReason($opp);
        }

        return ToolResult::ok([
            'domain' => $domain,
            'seed_keywords' => array_values($seeds),
            'opportunities' => $opportunities,
        ], count($opportunities) . ' opportunité(s) de mot-clé identifiée(s).');
    }

    /**
     * Justification lisible construite côté serveur à partir de données
     * réelles — jamais laissée à l'invention du LLM.
     */
    private function buildKeywordReason(array $opp): string
    {
        if (($opp['source'] ?? null) === 'competitor_gap') {
            return "{$opp['competitor_domain']} se positionne sur ce mot-clé, pas vous — volume {$opp['search_volume']}/mois.";
        }

        if ($opp['current_position']) {
            return "Déjà positionné en position {$opp['current_position']} (hors du top 10) — du contenu additionnel pourrait l'y faire monter. Volume {$opp['search_volume']}/mois.";
        }

        $difficulty = $opp['keyword_difficulty'] ?? null;
        $diffLabel = is_numeric($difficulty)
            ? ($difficulty < 30 ? 'difficulté faible' : ($difficulty < 60 ? 'difficulté modérée' : 'difficulté élevée'))
            : 'difficulté inconnue';

        return "Non positionné actuellement — volume {$opp['search_volume']}/mois, {$diffLabel}" . ($difficulty !== null ? " ({$difficulty}/100)" : '') . '.';
    }

    // ── Utilitaires ──────────────────────────────────────────────────

    /**
     * Effectue l'appel API et retourne le résultat déjà parsé en tableau
     * associatif. Centralise gestion d'erreur + parsing CSV pour tous les
     * outils de lecture.
     */
    private function query(array $c, array $params): array
    {
        try {
            $response = $this->http(self::API_BASE)->get('', array_merge($params, ['key' => $c['api_key']]));
        } catch (RequestException $e) {
            $this->handleApiError($e);
        }

        $body = trim($response->body());

        // Semrush retourne un corps texte même en cas d'erreur métier
        // (ex: "ERROR :: NOTHING FOUND", clé invalide, quota dépassé) — ce
        // n'est jamais un statut HTTP d'erreur, il faut donc l'inspecter.
        if (str_starts_with($body, 'ERROR')) {
            if (str_contains($body, 'KEY') || str_contains($body, 'AUTHENTICATION')) {
                throw new AuthExpiredException('Clé API Semrush invalide ou rejetée: ' . $body);
            }
            Log::warning('MCP Semrush: réponse en erreur métier', ['body' => $body]);
            return [];
        }

        $this->recordSuccess();
        return $this->parseCsv($body);
    }

    private function parseCsv(string $body): array
    {
        $lines = array_filter(explode("\n", trim($body)), fn ($l) => trim($l) !== '');
        if (count($lines) < 2) {
            return [];
        }

        $lines = array_values($lines);
        $headers = str_getcsv($lines[0], ';');
        $rows = [];

        foreach (array_slice($lines, 1) as $line) {
            $values = str_getcsv($line, ';');
            $rows[] = array_combine($headers, array_pad($values, count($headers), null));
        }

        return $rows;
    }

    private function resolveDomain(array $p, array $c): ?string
    {
        return $p['domain'] ?? $c['domain'] ?? null;
    }

    private function database(array $p, array $c): string
    {
        return $p['database'] ?? $c['database'] ?? 'us';
    }

    private function handleApiError(RequestException $e): never
    {
        $status = $e->response?->status();
        $body = $e->response?->body();
        Log::error('MCP Semrush: appel API échoué', ['status' => $status, 'body' => $body]);

        // Semrush répond en 401/403 réel pour une clé invalide/révoquée
        // (ex: "ERROR 120 :: WRONG KEY - ID PAIR"), contrairement à d'autres
        // erreurs métier renvoyées en 200 avec un corps "ERROR" (voir query()).
        if (in_array($status, [401, 403])) {
            throw new AuthExpiredException("Clé API Semrush invalide ou rejetée: " . trim((string) $body));
        }

        throw new ConnectorUnavailableException('Semrush indisponible: ' . $e->getMessage());
    }
}
