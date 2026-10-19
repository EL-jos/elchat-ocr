<?php

namespace App\Services\WebsiteSecurityAuditor;

use App\Models\Site;
use App\Models\WebsiteSecurityAudit;
use App\Models\WebsiteSecurityBaseline;
use App\Models\WebsiteSecurityManifest;
use App\Services\WebsiteGrowthAdvisor\WebsiteGrowthAdvisorLiveCrawler;

final class WebsiteSecurityAuditorService
{
    public function __construct(
        private readonly WebsiteGrowthAdvisorLiveCrawler $crawler,
        private readonly WebsiteSecurityFindingAnalyzer $findings,
        private readonly WebsiteSecurityAiAnalysisService $aiAnalysis,
        private readonly WebsiteSecuritySbomComparisonService $sbomComparison,
        private readonly WebsiteSecurityVulnerabilityIntelligenceService $vulnerabilityIntelligence,
        private readonly WebsiteSecurityFindingCoverageService $findingCoverage,
        private readonly WebsiteSecurityAssetDiscoveryService $assetDiscovery,
        private readonly WebsiteSecuritySourceAccessService $sourceAccess,
        private readonly WebsiteSecuritySourceCodeAnalysisService $sourceCodeAnalysis,
        private readonly WebsiteSecurityRiskEngine $riskEngine,
        private readonly WebsiteSecurityManifestImportService $manifestImporter,
    ) {}

    /** @param null|callable(array<string, mixed>): void $progress */
    public function analyze(WebsiteSecurityAudit $audit, ?callable $progress = null): WebsiteSecurityAudit
    {
        $site = $audit->site()->firstOrFail();
        $configuration = is_array($audit->configuration_snapshot) ? $audit->configuration_snapshot : [];
        $checks = is_array($configuration['checks'] ?? null) ? $configuration['checks'] : [];
        $depth = (string) ($configuration['analysis_depth'] ?? 'standard');
        $investigation = ($configuration['audit_mode'] ?? 'audit') === 'investigation';
        $limits = match ($depth) {
            'quick' => ['max_pages' => 4, 'max_depth' => 1],
            'deep' => ['max_pages' => 20, 'max_depth' => 3],
            default => ['max_pages' => 8, 'max_depth' => 2],
        };
        $crawlOptions = [
            'scope' => ! empty($checks['discovered_surface']) ? 'site' : 'page',
            ...$limits,
        ];

        $progress && $progress(['progress' => 20, 'phase' => 'discovery', 'message' => 'Lecture du site public autorisé.']);
        $crawl = $this->crawler->crawl($site, $crawlOptions);
        $pages = is_array($crawl['pages'] ?? null) ? $crawl['pages'] : [];
        $progress && $progress(['progress' => 34, 'phase' => 'http_analysis', 'message' => count($pages).' page(s) HTTP observée(s); analyse des preuves.']);

        $inventory = [];
        $findings = [];
        foreach ($pages as $page) {
            if (! is_array($page)) {
                continue;
            }
            $observations = is_array($page['security_observations'] ?? null) ? $page['security_observations'] : [];
            $url = (string) ($page['url'] ?? '');
            if ($url === '') {
                continue;
            }
            $safeUrl = $this->safeEvidenceUrl($url);
            $inventory[] = [
                'id' => 'web_endpoint:'.substr(hash('sha256', $safeUrl), 0, 24),
                'type' => 'web_endpoint',
                'url' => $safeUrl,
                'host' => strtolower((string) parse_url($url, PHP_URL_HOST)),
                'status_code' => is_numeric($page['status_code'] ?? null) ? (int) $page['status_code'] : null,
                'transport' => ($observations['https'] ?? null) === true ? 'https' : (($observations['https'] ?? null) === false ? 'http' : 'unknown'),
                'observed_at' => $page['fetched_at'] ?? null,
                'source' => 'same_host_public_crawl',
            ];
            array_push($findings, ...$this->findings->analyzePage($page, $checks));
        }

        $assetDiscovery = $this->assetDiscovery->discover($site, $configuration, $inventory);
        array_push($inventory, ...$assetDiscovery['inventory']);
        array_push($findings, ...$assetDiscovery['findings']);
        $progress && $progress(['progress' => 40, 'phase' => 'asset_discovery', 'message' => 'Découverte DNS et réseau autorisée terminée.']);

        $sourceData = ['files' => [], 'dependency_files' => [], 'database' => null, 'sources' => []];
        if (! empty($checks['repository_analysis']) || ! empty($checks['server_configuration']) || ! empty($checks['database_posture'])) {
            $progress && $progress(['progress' => 43, 'phase' => 'authorized_sources', 'message' => 'Lecture limitée des sources explicitement connectées.']);
            $sourceData = $this->sourceAccess->collect($site, $checks, $progress);
            if (isset($sourceData['sources']['repository'])) {
                $sourceData['sources']['repository']['sbom_import'] = $this->importRepositoryManifests($site, (string) $audit->agent_id, $sourceData['dependency_files']);
            }
            $codeAnalysis = $this->sourceCodeAnalysis->analyze($sourceData['files']);
            array_push($findings, ...$codeAnalysis['findings']);
            if ($sourceData['database'] !== null) {
                $database = $sourceData['database'];
                $inventory[] = [
                    'type' => 'database_schema_observation', 'driver' => $database['driver'],
                    'host' => $database['host'], 'resolved_address' => $database['resolved_address'],
                    'port' => $database['port'], 'database' => $database['database'], 'server_version' => $database['server_version'],
                    'authenticated_role' => $database['authenticated_role'],
                    'schema_names' => $database['schema_names'], 'table_names' => $database['table_names'],
                    'object_count' => $database['object_count'], 'rows_read' => 0,
                    'tls_verified' => $database['tls_verified'], 'tls_cipher' => $database['tls_cipher'],
                    'source' => 'read_only_information_schema', 'observed_at' => now()->toISOString(),
                ];
                if ($database['write_privileges_observed']) {
                    $findingId = 'dbpriv:'.substr(hash('sha256', $site->id.'|'.$database['driver'].'|'.$database['database'].'|'.$database['authenticated_role']), 0, 24);
                    $findings[] = [
                        'id' => $findingId, 'fingerprint' => hash('sha256', 'database_write_privilege|'.$database['driver'].'|'.$database['database'].'|'.$database['authenticated_role']),
                        'type' => 'database_write_privilege_observed', 'category' => 'database_configuration',
                        'title' => 'Le compte DB connecté possède des privilèges d’écriture observés',
                        'description' => 'Les métadonnées de privilèges indiquent des droits d’écriture pour le compte utilisé par l’auditeur. Aucun changement de données n’a été effectué; vérifiez que ce compte est réellement dédié à la lecture seule.',
                        'severity' => 'medium', 'status' => 'review_required', 'detection_status' => 'detected',
                        'validation_state' => 'confirmed', 'ground_truth' => 'not_determinable',
                        'asset' => $database['database'], 'reachability' => 'unknown', 'exposure' => 'unknown',
                        'exploitability' => 'unknown', 'confidence' => 'medium',
                        'remediation' => ['recommendation' => 'Remplacer ce compte par un compte DB dédié disposant uniquement de SELECT sur les schémas nécessaires.', 'verification_plan' => 'Reconnecter avec le rôle en lecture seule et vérifier les privilèges observés.'],
                        'evidence' => [[
                            'id' => $findingId, 'source' => 'database_catalog', 'type' => 'write_privileges_observed',
                            'data' => ['driver' => $database['driver'], 'database' => $database['database'], 'role' => $database['authenticated_role'], 'table_rows_read' => 0],
                        ]],
                    ];
                }
            }
        } else {
            $codeAnalysis = ['files_scanned' => 0, 'parse_errors' => 0, 'bytes_scanned' => 0, 'findings' => []];
        }
        $progress && $progress(['progress' => 58, 'phase' => 'source_analysis', 'message' => 'Collecte des sources terminée; consolidation de l’inventaire.']);

        $manifests = WebsiteSecurityManifest::query()->where('account_id', $site->account_id)->where('site_id', $site->id)
            ->latest('created_at')->get()->unique('ecosystem')->values();
        $packageInventory = [];
        foreach ($manifests as $manifest) {
            foreach (is_array($manifest->components) ? $manifest->components : [] as $component) {
                if (! is_array($component)) {
                    continue;
                }
                $packageInventory[] = [
                    'type' => 'package',
                    ...$component,
                    'source' => 'tenant_uploaded_lockfile',
                    'lockfile' => $manifest->lockfile_name,
                    'manifest_id' => $manifest->id,
                    'imported_at' => $manifest->created_at?->toISOString(),
                    'vulnerability_assessment' => 'not_requested',
                    'vulnerability_checked' => false,
                    'vulnerabilities' => [],
                    'reachability' => 'unknown',
                ];
            }
        }

        $intelligence = ['status' => 'not_requested', 'source' => 'OSV', 'checked' => 0, 'total_unique' => 0, 'matches' => []];
        if (! empty($checks['vulnerability_intelligence'])) {
            if ($packageInventory === []) {
                $intelligence = ['status' => 'not_available', 'source' => 'OSV', 'checked' => 0, 'total_unique' => 0, 'matches' => [], 'reason' => 'no_sbom'];
            } else {
                $progress && $progress(['progress' => 60, 'phase' => 'vulnerability_intelligence', 'message' => 'Interrogation OSV sur les coordonnées du SBOM autorisées.']);
                $intelligence = $this->vulnerabilityIntelligence->lookup($packageInventory);
                $advisoryFindings = [];
                $checkedKeys = array_fill_keys($intelligence['checked_keys'] ?? [], true);
                foreach ($packageInventory as &$component) {
                    $key = strtolower((string) $component['ecosystem']).'|'.strtolower((string) $component['name']).'|'.strtolower((string) $component['version']);
                    $component['vulnerability_checked'] = isset($checkedKeys[$key]);
                    $component['vulnerabilities'] = $intelligence['matches'][$key] ?? [];
                    $component['vulnerability_assessment'] = $component['vulnerability_checked']
                        ? $intelligence['status']
                        : ($intelligence['status'] === 'unavailable' ? 'unavailable' : 'not_checked');
                    foreach ($component['vulnerabilities'] as $advisory) {
                        if (is_array($advisory)) {
                            $advisoryFindings[] = $this->advisoryFinding($component, $advisory);
                        }
                    }
                }
                unset($component);
                array_push($findings, ...$advisoryFindings);
            }
        }
        $progress && $progress(['progress' => 72, 'phase' => 'vulnerability_analysis', 'message' => 'Analyse de l’inventaire de dépendances terminée.']);
        $intelligenceActive = in_array($intelligence['status'] ?? null, ['available', 'partial'], true);
        $previous = WebsiteSecurityAudit::query()
            ->where('site_id', $audit->site_id)
            ->where('id', '!=', $audit->id)
            ->whereIn('status', ['completed', 'partial'])
            ->latest('created_at')
            ->first();
        $history = WebsiteSecurityAudit::query()
            ->where('site_id', $audit->site_id)
            ->where('id', '!=', $audit->id)
            ->whereIn('status', ['completed', 'partial'])
            ->latest('created_at')
            ->limit(100)
            ->get();
        $packageInventory = $this->preservePackageHistory($packageInventory, $previous, $history->all());
        array_push($inventory, ...$packageInventory);
        $findings = $this->addContextualRisk($findings);
        $findings = $this->preserveDetectionHistory($findings, $previous, $history->all());
        $comparison = $this->compareFindings($findings, $previous?->findings ?? null, $inventory, $checks);
        $comparison['sbom'] = $this->sbomComparison->compare(
            $this->packageComponents($inventory),
            $this->packageComponents($previous?->inventory ?? []),
        );
        $baseline = WebsiteSecurityBaseline::query()
            ->where('site_id', $audit->site_id)
            ->where('is_active', true)
            ->latest('created_at')
            ->first();
        $comparison['baseline'] = $baseline ? [
            ...$this->compareFingerprints($findings, $baseline->snapshot['findings'] ?? [], $inventory, $checks),
            'sbom' => $this->sbomComparison->compare(
                $this->packageComponents($inventory),
                $this->packageComponents($baseline->snapshot['inventory'] ?? []),
            ),
        ] : null;

        $progress && $progress(['progress' => 78, 'phase' => 'risk_consolidation', 'message' => 'Consolidation des risques et comparaison avec les audits précédents.']);
        $aiAnalysis = null;
        if (! empty($configuration['ai_interpretation'])) {
            $progress && $progress(['progress' => 82, 'phase' => 'ai_analysis', 'message' => 'Interprétation du jeu de preuves borné.']);
            $unknowns = [];
            if ($codeAnalysis['files_scanned'] === 0) {
                $unknowns[] = 'analyse du code source autorisé';
            }
            $unknowns[] = 'reachability complète et exploitabilité démontrée';
            $unknowns[] = 'attack paths validés';
            if (empty($sourceData['sources']['database']) || ($sourceData['sources']['database']['status'] ?? null) !== 'available') {
                $unknowns[] = 'analyse du schéma DB';
            }
            if (($assetDiscovery['report']['network_surface']['status'] ?? null) !== 'available') {
                $unknowns[] = 'ports et services';
            }
            if (! collect($findings)->contains(fn (array $finding): bool => is_numeric($finding['cvss'] ?? null))) {
                $unknowns[] = 'score CVSS numérique';
            }
            if (! in_array($intelligence['source_status']['EPSS'] ?? null, ['available'], true)) {
                $unknowns[] = 'EPSS';
            }
            if (! in_array($intelligence['source_status']['CISA_KEV'] ?? null, ['available'], true)) {
                $unknowns[] = 'statut KEV';
            }
            if ($packageInventory === []) {
                $unknowns[] = 'SBOM et dépendances';
            }
            if (! $intelligenceActive) {
                $unknowns[] = 'avis de vulnérabilité des dépendances';
            } else {
                $unknowns[] = 'avis vulnérabilités hors couverture OSV';
            }
            $progress && $progress(['progress' => 84, 'phase' => 'ai_analysis', 'message' => 'Interprétation IA en cours; chaque modèle est borné à 20 secondes.']);
            $aiAnalysis = $this->aiAnalysis->interpret($findings, $unknowns, $assetDiscovery['attack_paths']);
            $progress && $progress(['progress' => 90, 'phase' => 'ai_analysis', 'message' => 'Interprétation IA terminée; préparation du rapport.']);
        }
        $progress && $progress(['progress' => 92, 'phase' => 'report_preparation', 'message' => 'Préparation du rapport final.']);

        $warnings = array_values(array_unique(array_slice(array_map(
            fn (string $warning): string => $this->redactUrls($warning),
            array_filter($crawl['errors'] ?? [], 'is_string'),
        ), 0, 30)));
        if ($pages === []) {
            $warnings[] = 'Aucune page HTTP exploitable n’a pu être lue dans le périmètre autorisé.';
        }
        if (($configuration['audit_mode'] ?? 'audit') === 'investigation') {
            $warnings[] = 'Le mode investigation reste limité aux contrôles HTTP publics et read-only disponibles.';
        }
        if (! empty($sourceData['sources']['server']['scan_limited'])) {
            $warnings[] = 'La lecture SFTP a été bornée pour éviter une attente excessive; certains dossiers ou fichiers peuvent ne pas avoir été examinés.';
        }

        $available = ['public_http_headers', 'https_transport_observation', 'set_cookie_attribute_counts', 'same_host_link_discovery'];
        if (($assetDiscovery['report']['dns_discovery']['status'] ?? null) === 'available') {
            $available[] = 'dns_and_certificate_transparency_discovery';
        }
        if (($assetDiscovery['report']['network_surface']['status'] ?? null) === 'available') {
            $available[] = 'authorized_tcp_reachability';
        }
        if ($assetDiscovery['attack_paths'] !== []) {
            $available[] = 'candidate_co_exposure_paths';
        }
        if ($codeAnalysis['files_scanned'] > 0) {
            $available[] = 'bounded_static_source_code_signals';
        }
        if (($sourceData['sources']['database']['status'] ?? null) === 'available') {
            $available[] = 'read_only_database_schema_and_privilege_observation';
        }
        if ($packageInventory !== []) {
            array_push($available, 'sbom', 'dependency_inventory');
        }
        $notAvailable = ['authenticated_endpoints', 'full_data_flow_reachability', 'validated_attack_path_analysis'];
        if ($codeAnalysis['files_scanned'] === 0) {
            $notAvailable[] = 'source_code_analysis';
        }
        if (($sourceData['sources']['database']['status'] ?? null) !== 'available') {
            $notAvailable[] = 'database_schema_analysis';
        }
        if (($assetDiscovery['report']['network_surface']['status'] ?? null) !== 'available') {
            $notAvailable[] = 'ports_and_services';
        }
        if (($assetDiscovery['report']['dns_discovery']['status'] ?? null) !== 'available') {
            $notAvailable[] = 'dns_subdomain_enumeration';
        }
        if (collect($findings)->contains(fn (array $finding): bool => is_numeric($finding['cvss'] ?? null))) {
            $available[] = 'cvss_base_score_from_source_vector';
        } else {
            $notAvailable[] = 'cvss_numeric_score';
        }
        if ($packageInventory === []) {
            array_unshift($notAvailable, 'sbom', 'dependency_inventory');
        }
        if ($intelligenceActive) {
            array_push($available, 'osv_package_version_advisory_lookup', 'epss_lookup', 'cisa_kev_lookup');
        } else {
            $notAvailable[] = 'dependency_vulnerability_lookup';
        }
        $scopeReport = [
            'status' => $crawl['status'] ?? 'unavailable',
            'root_url' => $this->safeEvidenceUrl((string) ($crawl['root_url'] ?? $site->url ?? '')),
            'requested_urls' => array_map(fn (string $url): string => $this->safeEvidenceUrl($url), array_filter($crawl['requested_urls'] ?? [], 'is_string')),
            'pages_analyzed' => count(array_filter($inventory, fn (array $item): bool => ($item['type'] ?? null) === 'web_endpoint')),
            'limits' => $crawl['limits'] ?? [],
            'checks_performed' => array_values(array_keys(array_filter($checks))),
            'available' => $available,
            'not_available' => $notAvailable,
            'sbom' => [
                'status' => $packageInventory === [] ? 'not_available' : 'available',
                'component_count' => count($packageInventory),
                'ecosystems' => $manifests->pluck('ecosystem')->values()->all(),
                'format' => 'CycloneDX 1.5',
                'vulnerability_mapping' => $intelligenceActive ? 'osv_package_version_matches' : 'not_available',
                'reachability' => 'unknown',
            ],
            'asset_discovery' => $assetDiscovery['report'],
            'attack_paths' => $assetDiscovery['attack_paths'],
            'authorized_sources' => [
                'sources' => $sourceData['sources'],
                'source_files_scanned' => $codeAnalysis['files_scanned'],
                'source_bytes_scanned' => $codeAnalysis['bytes_scanned'],
                'php_parse_errors' => $codeAnalysis['parse_errors'],
                'source_content_sent_to_llm' => false,
                'database_rows_read' => 0,
            ],
            'vulnerability_intelligence' => [
                'status' => $intelligence['status'] ?? 'not_requested',
                'source' => $intelligence['source'] ?? 'OSV',
                'checked' => $intelligence['checked'] ?? 0,
                'total_unique' => $intelligence['total_unique'] ?? 0,
                'matched_package_count' => count($intelligence['matches'] ?? []),
                'source_status' => $intelligence['source_status'] ?? [],
                'checked_at' => $intelligence['checked_at'] ?? null,
                'truncated' => (bool) ($intelligence['truncated'] ?? false) || (bool) ($intelligence['advisory_details_truncated'] ?? false)
                    || (bool) ($intelligence['advisory_details_incomplete'] ?? false),
                'reason' => $intelligence['reason'] ?? null,
                'disclaimer' => $intelligenceActive ? 'Une correspondance package/version avec un avis n’établit pas la reachability ni l’exploitabilité de l’application.' : 'Aucune recherche externe de vulnérabilités n’a été effectuée.',
            ],
            'llm_analysis' => $aiAnalysis['status'] ?? 'disabled',
            'remediation' => [
                'proposal' => 'remediation_ticket_available',
                'approval' => 'admin_required',
                'execution_path' => 'existing_mcp_permission_gate_and_jira_connector',
                'external_fix' => 'not_performed',
                'verification' => 'rerun_security_audit_after_external_fix',
            ],
            'read_only' => true,
        ];
        $status = $pages === [] || ($crawl['status'] ?? null) === 'unavailable'
            ? 'partial'
            : (($crawl['status'] ?? null) === 'partial' ? 'partial' : 'completed');

        $audit->forceFill([
            'status' => $status,
            'progress' => 100,
            'phase' => $status,
            'progress_message' => $status === 'completed' ? 'Analyse terminée dans le périmètre disponible.' : 'Analyse partielle; consultez les limites et avertissements.',
            'scope_report' => $scopeReport,
            'inventory' => $inventory,
            'findings' => array_values($findings),
            'comparison' => $comparison,
            'ai_analysis' => $aiAnalysis,
            'warnings' => array_values(array_unique($warnings)),
            'completed_at' => now(),
        ])->save();

        $progress && $progress(['progress' => 100, 'phase' => $status, 'message' => $audit->progress_message]);

        return $audit->fresh();
    }

    /** @param array<int, array<string, mixed>> $findings @return array<string, mixed> */
    public function baselineSnapshot(array $findings, array $inventory, array $scopeReport): array
    {
        return ['findings' => $findings, 'inventory' => $inventory, 'scope_report' => $scopeReport];
    }

    /** @param array<int, array<string, mixed>> $findings @param array<int, array<string, mixed>>|null $previous @return array<string, mixed> */
    private function compareFindings(array $findings, ?array $previous, array $inventory, array $checks): array
    {
        if (! is_array($previous)) {
            return ['available' => false, 'basis' => 'previous_audit', 'new' => [], 'resolved' => [], 'not_reassessed' => [], 'changed' => [], 'unchanged' => []];
        }

        return ['available' => true, 'basis' => 'previous_audit', ...$this->compareFingerprints($findings, $previous, $inventory, $checks)];
    }

    /** @param array<int, array<string, mixed>> $inventory @return array<int, array<string, mixed>> */
    private function packageComponents(array $inventory): array
    {
        return array_values(array_filter($inventory, fn ($item): bool => is_array($item) && ($item['type'] ?? null) === 'package'));
    }

    /** Import supported Git lockfiles through the same CycloneDX pipeline as owner uploads. */
    private function importRepositoryManifests(Site $site, string $agentId, array $files): array
    {
        $byPath = [];
        foreach ($files as $file) {
            if (! is_array($file) || ! is_string($file['path'] ?? null) || ! is_string($file['content'] ?? null)) {
                continue;
            }
            $byPath[strtolower(str_replace('\\', '/', $file['path']))] = $file;
        }
        $imports = [];
        $seenEcosystems = [];
        foreach ($byPath as $path => $lock) {
            $base = strtolower(basename($path));
            if (! in_array($base, ['composer.lock', 'package-lock.json'], true)) {
                continue;
            }
            $ecosystem = $base === 'composer.lock' ? 'Packagist' : 'npm';
            if (isset($seenEcosystems[$ecosystem])) {
                continue;
            }
            $manifestBase = $base === 'composer.lock' ? 'composer.json' : 'package.json';
            $directory = trim(str_replace('\\', '/', dirname($path)), '.');
            $manifestPath = $directory !== '' ? $directory.'/'.$manifestBase : $manifestBase;
            $manifest = $byPath[strtolower($manifestPath)] ?? null;
            try {
                $result = $this->manifestImporter->import(
                    basename($path), $lock['content'],
                    is_array($manifest) ? basename($manifestPath) : null,
                    is_array($manifest) ? $manifest['content'] : null,
                );
                $lockName = mb_substr(str_replace('\\', '/', $path), 0, 191);
                $fingerprint = (string) $result['fingerprint'];
                $exists = WebsiteSecurityManifest::query()->where('site_id', $site->id)
                    ->where('lockfile_name', $lockName)->where('fingerprint', $fingerprint)->exists();
                if (! $exists) {
                    WebsiteSecurityManifest::query()->create([
                        'account_id' => $site->account_id, 'site_id' => $site->id, 'agent_id' => $agentId,
                        'ecosystem' => $result['ecosystem'], 'package_manager' => $result['package_manager'],
                        'lockfile_name' => $lockName,
                        'manifest_name' => is_array($manifest) ? mb_substr(str_replace('\\', '/', $manifestPath), 0, 191) : null,
                        'fingerprint' => $fingerprint, 'component_count' => $result['component_count'],
                        'components' => $result['components'], 'cyclonedx' => $result['cyclonedx'],
                    ]);
                }
                $seenEcosystems[$ecosystem] = true;
                $imports[] = ['path' => $lockName, 'ecosystem' => $result['ecosystem'], 'components' => $result['component_count'], 'status' => $exists ? 'unchanged' : 'imported'];
            } catch (Throwable) {
                $imports[] = ['path' => mb_substr(str_replace('\\', '/', $path), 0, 191), 'ecosystem' => $ecosystem, 'status' => 'invalid_or_unsupported'];
            }
        }

        return ['status' => $imports === [] ? 'not_found' : (collect($imports)->contains(fn (array $item): bool => in_array($item['status'], ['imported', 'unchanged'], true)) ? 'available' : 'unavailable'), 'lockfiles' => $imports];
    }

    /** Keep observed severity separate from a conservative, explainable risk interpretation. */
    private function addContextualRisk(array $findings): array
    {
        foreach ($findings as &$finding) {
            if (! is_array($finding) || is_array($finding['contextual_risk'] ?? null)) {
                continue;
            }
            $reasons = [];
            $validation = (string) ($finding['validation_state'] ?? 'unknown');
            if ($validation === 'suspected' || $validation === 'likely') {
                $reasons[] = 'Signal statique à confirmer manuellement; le risque contextuel reste inconnu.';
            }
            if (($finding['reachability'] ?? 'unknown') === 'unknown') {
                $reasons[] = 'La reachability applicative n’a pas été établie.';
            }
            if (($finding['exploitability'] ?? 'unknown') === 'unknown') {
                $reasons[] = 'Aucune exploitabilité n’a été démontrée.';
            }
            if (($finding['exposure'] ?? 'unknown') === 'public') {
                $reasons[] = 'Une exposition réseau publique a été observée dans le périmètre déclaré.';
            }
            $riskSeverity = in_array($validation, ['suspected', 'likely'], true) ? 'unknown' : (string) ($finding['severity'] ?? 'unknown');
            $finding['contextual_risk'] = $this->riskEngine->assess($riskSeverity, $reasons);
        }
        unset($finding);

        return $findings;
    }

    /** @param array<string, mixed> $component @param array<string, mixed> $advisory @return array<string, mixed> */
    private function advisoryFinding(array $component, array $advisory): array
    {
        $advisoryId = (string) ($advisory['id'] ?? 'unknown');
        $cveIds = is_array($advisory['cve_ids'] ?? null) ? $advisory['cve_ids'] : [];
        $cve = $cveIds[0] ?? null;
        $name = (string) ($component['name'] ?? 'unknown');
        $version = (string) ($component['version'] ?? 'unknown');
        $purl = (string) ($component['purl'] ?? '');
        $fingerprint = hash('sha256', implode('|', ['osv', $component['ecosystem'] ?? '', $name, $version, $advisoryId]));
        $evidenceId = 'osv_advisory:'.substr($fingerprint, 0, 24);
        $summary = is_string($advisory['summary'] ?? null) ? trim($advisory['summary']) : '';
        $description = 'La source OSV a retourné l’avis '.$advisoryId.' lors de la requête pour '.$name.'@'.$version.'. Cela atteste de l’association publiée par OSV entre l’avis et cette version; cela ne démontre ni l’utilisation du composant ni sa reachability dans l’application.';
        if ($summary !== '') {
            $description .= ' Résumé de l’avis : '.$summary;
        }
        $fixed = is_array($advisory['fixed_versions'] ?? null) ? $advisory['fixed_versions'] : [];
        $recommendation = $fixed !== []
            ? 'Évaluer une mise à niveau vers une version corrective indiquée par OSV ('.implode(', ', array_slice($fixed, 0, 10)).') dans un environnement de test.'
            : 'Consulter l’avis OSV associé et vérifier auprès du fournisseur si une version corrective est disponible.';
        $sourceSeverity = $advisory['source_severity'] ?? null;

        return [
            'id' => $evidenceId,
            'fingerprint' => $fingerprint,
            'type' => 'dependency_advisory_match',
            'category' => 'dependency',
            'title' => ($cve ?: $advisoryId).' correspond à '.$name.' '.$version,
            'description' => $description,
            'severity' => is_string($sourceSeverity) ? $sourceSeverity : 'unknown',
            'status' => 'open',
            'detection_status' => 'detected',
            'validation_state' => 'confirmed',
            'ground_truth' => 'not_determinable',
            'asset' => $name.'@'.$version,
            'component' => $name,
            'component_version' => $version,
            'ecosystem' => $component['ecosystem'] ?? null,
            'purl' => $purl,
            'cve' => $cve,
            'cve_ids' => $cveIds,
            'advisory_id' => $advisoryId,
            'cvss' => $advisory['cvss'] ?? null,
            'cvss_vector' => $advisory['cvss_vector'] ?? null,
            'cvss_status' => $advisory['cvss_status'] ?? 'not_available',
            'cvss_source' => $advisory['cvss_source'] ?? null,
            'cvss_metric_type' => $advisory['cvss_metric_type'] ?? null,
            'epss' => $advisory['epss'] ?? null,
            'epss_percentile' => $advisory['epss_percentile'] ?? null,
            'epss_as_of' => $advisory['epss_as_of'] ?? null,
            'epss_status' => $advisory['epss_status'] ?? 'unknown',
            'epss_source' => $advisory['epss_source'] ?? null,
            'kev' => $advisory['kev'] ?? 'unknown',
            'kev_source' => $advisory['kev_source'] ?? null,
            'kev_date_added' => $advisory['kev_date_added'] ?? null,
            'kev_due_date' => $advisory['kev_due_date'] ?? null,
            'kev_ransomware_campaign_use' => $advisory['kev_ransomware_campaign_use'] ?? null,
            'reachability' => 'unknown',
            'exposure' => 'unknown',
            'exploitability' => 'unknown',
            'confidence' => 'high',
            'evidence' => [[
                'id' => $evidenceId,
                'source' => 'osv_api',
                'type' => 'package_version_advisory_match',
                'data' => [
                    'provider' => 'OSV', 'advisory_id' => $advisoryId, 'cve_ids' => $cveIds,
                    'package' => $name, 'version' => $version, 'ecosystem' => $component['ecosystem'] ?? null,
                    'purl' => $purl, 'modified_at' => $advisory['modified_at'] ?? null,
                    'cvss' => $advisory['cvss'] ?? null, 'cvss_vector' => $advisory['cvss_vector'] ?? null,
                    'cvss_status' => $advisory['cvss_status'] ?? 'not_available', 'cvss_source' => $advisory['cvss_source'] ?? null,
                    'cvss_metric_type' => $advisory['cvss_metric_type'] ?? null,
                    'epss' => $advisory['epss'] ?? null, 'epss_percentile' => $advisory['epss_percentile'] ?? null,
                    'epss_as_of' => $advisory['epss_as_of'] ?? null, 'epss_source' => $advisory['epss_source'] ?? null,
                    'kev' => $advisory['kev'] ?? 'unknown', 'kev_date_added' => $advisory['kev_date_added'] ?? null,
                    'kev_source' => $advisory['kev_source'] ?? null,
                    'matched_by' => $advisory['matched_by'] ?? 'package_and_version_query',
                ],
                'observed_at' => $advisory['modified_at'] ?? now()->toISOString(),
            ]],
            'contextual_risk' => [
                'level' => 'unknown',
                'reasons' => array_values(array_filter([
                    is_string($sourceSeverity) ? 'Gravité source OSV : '.$sourceSeverity.'.' : null,
                    'La reachability, l’exposition réelle, la criticité de l’asset et l’exploitabilité n’ont pas été établies.',
                    is_numeric($advisory['cvss'] ?? null) ? 'CVSS Base Score : '.number_format((float) $advisory['cvss'], 1, ',', '').($advisory['cvss_source'] === 'FIRST CVSS v3.1 formula' ? ' calculé selon la formule FIRST depuis le vecteur v3.1.' : ' fourni par OSV.').' Ce score technique n’est pas le risque contextuel.' : (! empty($advisory['cvss_vector']) ? 'Vecteur CVSS conservé mais score non calculé car sa version est non prise en charge ou le vecteur est invalide.' : 'Score CVSS numérique non disponible.'),
                    is_numeric($advisory['epss'] ?? null) ? 'EPSS : '.number_format((float) $advisory['epss'] * 100, 2, ',', '').' % au '.($advisory['epss_as_of'] ?? 'dernier relevé').'.' : null,
                    ($advisory['kev'] ?? 'unknown') === 'listed' ? 'CVE présent dans le catalogue CISA KEV.' : (($advisory['kev'] ?? 'unknown') === 'not_listed' ? 'CVE absent du catalogue CISA KEV consulté.' : 'Statut KEV inconnu.'),
                ])),
                'model' => 'insufficient_context_no_aggregate_score',
            ],
            'remediation' => [
                'recommendation' => $recommendation,
                'verification_plan' => 'Vérifier le correctif et relancer une analyse OSV après mise à jour; analyser séparément l’usage du code vulnérable.',
                'execution' => 'not_performed',
            ],
            'references' => $advisory['references'] ?? [],
            'related_findings' => [],
            'related_attack_paths' => [],
        ];
    }

    /** @param array<int, array<string, mixed>> $current @param array<int, array<string, mixed>> $older @return array<string, array<int, string>> */
    private function compareFingerprints(array $current, array $older, array $inventory, array $checks): array
    {
        $currentMap = collect($current)->filter(fn ($item) => is_array($item) && is_string($item['fingerprint'] ?? null))->keyBy('fingerprint');
        $olderMap = collect($older)->filter(fn ($item) => is_array($item) && is_string($item['fingerprint'] ?? null))->keyBy('fingerprint');
        $common = $currentMap->keys()->intersect($olderMap->keys());
        $changed = $common->filter(function (string $fingerprint) use ($currentMap, $olderMap): bool {
            $currentFinding = $currentMap->get($fingerprint);
            $olderFinding = $olderMap->get($fingerprint);

            return ($currentFinding['severity'] ?? null) !== ($olderFinding['severity'] ?? null)
                || data_get($currentFinding, 'contextual_risk.level') !== data_get($olderFinding, 'contextual_risk.level')
                || ($currentFinding['reachability'] ?? null) !== ($olderFinding['reachability'] ?? null);
        })->values();
        $missing = $olderMap->keys()->diff($currentMap->keys());
        $resolved = [];
        $notReassessed = [];
        foreach ($missing as $fingerprint) {
            if ($this->findingCoverage->reassessed($olderMap->get($fingerprint), $inventory, $checks)) {
                $resolved[] = $fingerprint;
            } else {
                $notReassessed[] = $fingerprint;
            }
        }

        return [
            'new' => $currentMap->keys()->diff($olderMap->keys())->values()->all(),
            'resolved' => $resolved,
            'not_reassessed' => $notReassessed,
            'changed' => $changed->all(),
            'unchanged' => $common->diff($changed)->values()->all(),
        ];
    }

    /** @param array<int, array<string, mixed>> $findings @param array<int, array<string, mixed>>|null $previous @return array<int, array<string, mixed>> */
    private function preserveDetectionHistory(array $findings, ?WebsiteSecurityAudit $previous, array $history): array
    {
        $latest = collect($previous?->findings ?? [])->keyBy('fingerprint');
        $allHistory = collect($history)->flatMap(fn (WebsiteSecurityAudit $audit) => $audit->findings ?? [])->keyBy('fingerprint');
        foreach ($findings as &$finding) {
            $fingerprint = $finding['fingerprint'] ?? '';
            $historical = $allHistory->get($fingerprint);
            $finding['first_detected'] = $historical['first_detected'] ?? now()->toISOString();
            $finding['last_detected'] = now()->toISOString();
            $finding['reappeared'] = ! $latest->has($fingerprint) && $historical !== null;
            $finding['status'] = 'open';
        }
        unset($finding);

        return $findings;
    }

    /** @param array<int, array<string, mixed>> $components @param array<int, WebsiteSecurityAudit> $history @return array<int, array<string, mixed>> */
    private function preservePackageHistory(array $components, ?WebsiteSecurityAudit $previous, array $history): array
    {
        $keyOf = static fn (array $item): string => strtolower((string) ($item['ecosystem'] ?? '')).'|'
            .strtolower((string) ($item['package_manager'] ?? '')).'|'.strtolower((string) ($item['name'] ?? ''));
        $observedAudits = [];
        foreach ($history as $audit) {
            foreach (is_array($audit->inventory) ? $audit->inventory : [] as $item) {
                if (is_array($item) && ($item['type'] ?? null) === 'package' && $keyOf($item) !== '||') {
                    $observedAudits[$keyOf($item)][] = $audit;
                }
            }
        }
        $previousKeys = collect(is_array($previous?->inventory) ? $previous->inventory : [])
            ->filter(fn ($item) => is_array($item) && ($item['type'] ?? null) === 'package')
            ->mapWithKeys(fn (array $item) => [$keyOf($item) => true]);
        foreach ($components as &$component) {
            $key = $keyOf($component);
            $sightings = $observedAudits[$key] ?? [];
            $first = $sightings === [] ? null : end($sightings);
            $last = $sightings === [] ? null : reset($sightings);
            $component['first_seen_at'] = $first?->created_at?->toISOString() ?? now()->toISOString();
            $component['last_seen_at'] = now()->toISOString();
            $component['reappeared'] = ! $previousKeys->has($key) && $last !== null;
        }
        unset($component);

        return $components;
    }

    private function safeEvidenceUrl(string $url): string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['host'])) {
            return '[url unavailable]';
        }
        $safe = strtolower((string) ($parts['scheme'] ?? 'https')).'://'.strtolower((string) $parts['host']);
        if (isset($parts['port'])) {
            $safe .= ':'.(int) $parts['port'];
        }

        return $safe.(string) ($parts['path'] ?? '/');
    }

    private function redactUrls(string $text): string
    {
        return preg_replace_callback('/https?:\/\/[^\s<>"\']+/i', function (array $match): string {
            $url = rtrim($match[0], '.,;:)');

            return $this->safeEvidenceUrl($url).substr($match[0], strlen($url));
        }, $text) ?? '[warning redacted]';
    }
}
