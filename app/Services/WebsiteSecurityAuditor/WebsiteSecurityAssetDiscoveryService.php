<?php

namespace App\Services\WebsiteSecurityAuditor;

use App\Models\Site;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Non-intrusive asset discovery. TCP checks are made only for the exact hosts
 * and ports explicitly authorized by the site owner; no service probes or
 * payloads are sent after a connection is established.
 */
final class WebsiteSecurityAssetDiscoveryService
{
    private const MAX_CT_NAMES = 80;

    private const MAX_TCP_PROBES = 64;

    private const CONNECT_TIMEOUT_SECONDS = 0.7;

    /** @return array{inventory: array<int, array<string, mixed>>, findings: array<int, array<string, mixed>>, report: array<string, mixed>} */
    public function discover(Site $site, array $configuration, array $observedWebInventory = []): array
    {
        $checks = is_array($configuration['checks'] ?? null) ? $configuration['checks'] : [];
        $scope = is_array($configuration['authorized_scope'] ?? null) ? $configuration['authorized_scope'] : [];
        $hosts = $this->normalizeHosts($scope['hosts'] ?? []);
        $ports = $this->normalizePorts($scope['ports'] ?? []);
        $confirmed = ($scope['authorization_confirmed'] ?? false) === true;
        $dnsEnabled = ! empty($checks['dns_discovery']);
        $portsEnabled = ! empty($checks['network_surface_scan']);

        if ((! $dnsEnabled && ! $portsEnabled) || ! $confirmed || $hosts === []) {
            return ['inventory' => [], 'findings' => [], 'attack_paths' => [], 'report' => [
                'status' => 'not_requested', 'hosts_in_scope' => [], 'ports_in_scope' => [],
                'dns_records_observed' => 0, 'tcp_probes' => 0, 'limitations' => [],
            ]];
        }

        $inventory = [];
        $findings = [];
        $attackPaths = [];
        $dnsCount = 0;
        $ctStatus = $dnsEnabled ? 'unavailable' : 'not_requested';
        $dnsStatus = $dnsEnabled ? 'available' : 'not_requested';
        $resolvedByHost = [];

        if ($dnsEnabled) {
            $hostsToResolve = $hosts;
            $ctNames = $this->certificateTransparencyNames($this->siteRoot($site, $hosts), $ctStatus);
            foreach ($ctNames as $name) {
                if ($this->isInAuthorizedDomain($name, $hosts)) {
                    $hostsToResolve[] = $name;
                }
            }
            $hostsToResolve = array_slice(array_values(array_unique($hostsToResolve)), 0, self::MAX_CT_NAMES + count($hosts));

            foreach ($hostsToResolve as $host) {
                $records = $this->dnsRecords($host);
                if ($records === null) {
                    $dnsStatus = $dnsStatus === 'available' ? 'partial' : $dnsStatus;

                    continue;
                }
                $dnsCount += count($records);
                $resolvedByHost[$host] = $this->publicAddresses($records);
                $inventory[] = [
                    'type' => 'dns_asset', 'host' => $host,
                    'record_types' => array_values(array_unique(array_column($records, 'type'))),
                    'records' => $records, 'source' => 'dns_resolution', 'observed_at' => now()->toISOString(),
                ];
            }
            if ($dnsCount === 0 && $dnsStatus === 'available') {
                $dnsStatus = 'partial';
            }
        }

        $probes = 0;
        $networkStatus = $portsEnabled ? 'partial' : 'not_requested';
        $limitations = [];
        if ($portsEnabled && $ports === []) {
            $limitations[] = 'Aucun port explicite n’a été autorisé; aucun test TCP n’a été exécuté.';
            $networkStatus = 'not_requested';
        } elseif ($portsEnabled) {
            foreach ($hosts as $host) {
                $addresses = $resolvedByHost[$host] ?? $this->resolvePublicAddresses($host);
                if ($addresses === []) {
                    $limitations[] = "Aucune adresse IP publique résolue pour {$host}; aucun test TCP exécuté.";

                    continue;
                }
                foreach ($addresses as $ip) {
                    foreach ($ports as $port) {
                        if ($probes >= self::MAX_TCP_PROBES) {
                            $limitations[] = 'Limite de 64 connexions TCP non intrusives atteinte.';
                            break 3;
                        }
                        $probes++;
                        $state = $this->tcpState($ip, $port);
                        $itemId = 'tcp:'.substr(hash('sha256', $host.'|'.$ip.'|'.$port), 0, 24);
                        $inventory[] = [
                            'type' => 'tcp_service_observation', 'id' => $itemId,
                            'host' => $host, 'ip' => $ip, 'port' => $port,
                            'service_hint' => $this->serviceHint($port), 'state' => $state,
                            'source' => 'authorized_tcp_connect', 'observed_at' => now()->toISOString(),
                        ];
                        if ($state === 'open') {
                            $findings[] = [
                                'id' => $itemId, 'fingerprint' => hash('sha256', 'tcp|'.$host.'|'.$port),
                                'type' => 'authorized_service_exposure', 'category' => 'exposure',
                                'title' => 'Service TCP accessible sur '.$host.':'.$port,
                                'description' => 'Une connexion TCP a été acceptée sur ce port autorisé. Aucun protocole applicatif ni exploit n’a été testé; cette observation ne prouve pas une vulnérabilité.',
                                'severity' => 'informational', 'status' => 'open', 'detection_status' => 'detected',
                                'validation_state' => 'confirmed', 'ground_truth' => 'not_determinable',
                                'asset' => $host.':'.$port, 'reachability' => 'reachable', 'exposure' => 'public',
                                'exploitability' => 'unknown', 'confidence' => 'high',
                                'remediation' => ['recommendation' => 'Vérifier que ce service doit être publiquement accessible et appliquer les contrôles d’accès réseau adaptés.', 'verification_plan' => 'Confirmer l’exposition attendue depuis le réseau public après toute modification.'],
                                'evidence' => [[
                                    'id' => $itemId, 'source' => 'authorized_tcp_connect', 'type' => 'tcp_connection_observed',
                                    'data' => ['host' => $host, 'ip' => $ip, 'port' => $port, 'service_hint' => $this->serviceHint($port), 'payload_sent' => false],
                                ]],
                            ];
                            foreach ($observedWebInventory as $endpoint) {
                                if (! is_array($endpoint) || strtolower((string) ($endpoint['host'] ?? '')) !== $host) {
                                    continue;
                                }
                                $endpointEvidenceId = 'web_endpoint:'.substr(hash('sha256', (string) ($endpoint['url'] ?? $host)), 0, 24);
                                $attackPaths[] = [
                                    'id' => 'path:'.substr(hash('sha256', $endpointEvidenceId.'|'.$itemId), 0, 24),
                                    'type' => 'candidate_co_exposure', 'status' => 'candidate', 'confidence' => 'low',
                                    'title' => 'Candidat de co-exposition web et service TCP sur '.$host,
                                    'entry_point' => ['type' => 'internet', 'evidence_id' => $endpointEvidenceId],
                                    'steps' => [
                                        ['type' => 'public_web_endpoint', 'asset' => $endpoint['url'] ?? $host, 'evidence_id' => $endpointEvidenceId],
                                        ['type' => 'tcp_service_reachable', 'asset' => $host.':'.$port, 'evidence_id' => $itemId],
                                    ],
                                    'finding_ids' => [$itemId], 'conditions' => ['Les observations partagent un hôte; aucun flux exploitable entre les services n’a été démontré.'],
                                    'privileges' => 'unknown', 'data_access' => 'unknown', 'impact' => 'unknown',
                                    'recommendation' => 'Vérifier si le service doit être exposé et s’il est isolé de l’application web.',
                                    'evidence_ids' => [$endpointEvidenceId, $itemId],
                                    'disclaimer' => 'Candidat de co-exposition seulement; ce résultat ne démontre ni vulnérabilité ni chemin d’exploitation.',
                                ];
                                $last = array_key_last($findings);
                                if ($last !== null && ($findings[$last]['id'] ?? null) === $itemId) {
                                    $findings[$last]['related_attack_paths'][] = end($attackPaths)['id'];
                                }
                            }
                        }
                    }
                }
            }
            $networkStatus = $probes > 0 ? 'available' : 'partial';
        }

        return [
            'inventory' => $inventory,
            'findings' => $findings,
            'attack_paths' => $attackPaths,
            'report' => [
                'status' => ($dnsStatus === 'available' || $networkStatus === 'available') ? 'available' : 'partial',
                'hosts_in_scope' => $hosts, 'ports_in_scope' => $ports,
                'dns_discovery' => ['status' => $dnsStatus, 'certificate_transparency' => $ctStatus, 'records_observed' => $dnsCount],
                'network_surface' => ['status' => $networkStatus, 'tcp_probes' => $probes, 'maximum_tcp_probes' => self::MAX_TCP_PROBES, 'payloads_sent' => 0],
                'attack_path_analysis' => ['status' => $attackPaths === [] ? 'no_candidate_observed' : 'candidate_only', 'candidate_paths' => count($attackPaths), 'validated_paths' => 0],
                'limitations' => array_values(array_unique($limitations)),
            ],
        ];
    }

    /** @return array<int, array{type: string, value: string}>|null */
    private function dnsRecords(string $host): ?array
    {
        try {
            $raw = @dns_get_record($host, DNS_A | DNS_AAAA | DNS_CNAME | DNS_MX | DNS_NS | DNS_TXT);
        } catch (Throwable) {
            return null;
        }
        if (! is_array($raw)) {
            return null;
        }
        $records = [];
        foreach ($raw as $record) {
            $type = strtoupper((string) ($record['type'] ?? ''));
            $value = match ($type) {
                'A', 'AAAA' => $record['ip'] ?? $record['ipv6'] ?? null,
                'CNAME' => $record['target'] ?? null,
                'MX' => $record['target'] ?? null,
                'NS' => $record['target'] ?? null,
                'TXT' => isset($record['txt']) ? '[redacted sha256:'.substr(hash('sha256', (string) $record['txt']), 0, 16).']' : null,
                default => null,
            };
            if (is_string($value) && $value !== '') {
                $records[] = ['type' => $type, 'value' => $value];
            }
        }

        return $records;
    }

    /** @return array<int, string> */
    private function certificateTransparencyNames(string $root, string &$status): array
    {
        if ($root === '') {
            return [];
        }
        try {
            $response = Http::acceptJson()->timeout(4)->connectTimeout(2)->get('https://crt.sh/', ['q' => '%.'.$root, 'output' => 'json']);
            if (! $response->successful()) {
                return [];
            }
            $payload = $response->json();
            if (! is_array($payload)) {
                return [];
            }
            $names = [];
            foreach (array_slice($payload, 0, 1500) as $entry) {
                foreach (preg_split('/\s+/', (string) ($entry['name_value'] ?? '')) ?: [] as $name) {
                    $name = strtolower(ltrim(trim($name), '*.'));
                    if ($this->validHost($name) && $this->isInAuthorizedDomain($name, [$root])) {
                        $names[$name] = true;
                    }
                }
                if (count($names) >= self::MAX_CT_NAMES) {
                    break;
                }
            }
            $status = 'available';

            return array_keys($names);
        } catch (Throwable) {
            $status = 'unavailable';

            return [];
        }
    }

    private function siteRoot(Site $site, array $authorizedHosts): string
    {
        $siteHost = strtolower((string) parse_url((string) $site->url, PHP_URL_HOST));
        if (str_starts_with($siteHost, 'www.')) {
            $siteHost = substr($siteHost, 4);
        }

        return $this->validHost($siteHost) ? $siteHost : ($authorizedHosts[0] ?? '');
    }

    private function isInAuthorizedDomain(string $candidate, array $hosts): bool
    {
        foreach ($hosts as $host) {
            if ($candidate === $host || str_ends_with($candidate, '.'.$host)) {
                return true;
            }
            // A configured www host still permits certificate names on its apex.
            if (str_starts_with($host, 'www.') && $candidate === substr($host, 4)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, string> */
    private function publicAddresses(array $records): array
    {
        $ips = [];
        foreach ($records as $record) {
            if (! in_array($record['type'] ?? '', ['A', 'AAAA'], true)) {
                continue;
            }
            $ip = (string) ($record['value'] ?? '');
            if ($this->isPublicIp($ip)) {
                $ips[$ip] = true;
            }
        }

        return array_keys($ips);
    }

    /** @return array<int, string> */
    private function resolvePublicAddresses(string $host): array
    {
        return $this->publicAddresses($this->dnsRecords($host) ?? []);
    }

    private function isPublicIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    private function tcpState(string $ip, int $port): string
    {
        $errno = 0;
        $error = '';
        $host = str_contains($ip, ':') ? '['.$ip.']' : $ip;
        $socket = @stream_socket_client('tcp://'.$host.':'.$port, $errno, $error, self::CONNECT_TIMEOUT_SECONDS, STREAM_CLIENT_CONNECT);
        if (is_resource($socket)) {
            fclose($socket);

            return 'open';
        }

        return in_array($errno, [111, 10061], true) ? 'closed' : 'unknown';
    }

    private function serviceHint(int $port): string
    {
        return match ($port) {
            20, 21 => 'ftp', 22 => 'ssh', 25 => 'smtp', 53 => 'dns', 80 => 'http',
            110 => 'pop3', 143 => 'imap', 443 => 'https', 445 => 'smb', 465 => 'smtps',
            587 => 'submission', 993 => 'imaps', 1433 => 'mssql', 1521 => 'oracle',
            2049 => 'nfs', 3306 => 'mysql', 5432 => 'postgresql', 6379 => 'redis',
            8080 => 'http-alt', 8443 => 'https-alt', 9200 => 'elasticsearch', 27017 => 'mongodb',
            default => 'unknown',
        };
    }

    /** @return array<int, string> */
    private function normalizeHosts(mixed $hosts): array
    {
        if (! is_array($hosts)) {
            return [];
        }
        $valid = [];
        foreach ($hosts as $host) {
            $host = strtolower(rtrim(trim((string) $host), '.'));
            if ($this->validHost($host)) {
                $valid[$host] = true;
            }
        }

        return array_slice(array_keys($valid), 0, 20);
    }

    private function validHost(string $host): bool
    {
        return strlen($host) <= 253 && preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))*$/i', $host) === 1
            && filter_var($host, FILTER_VALIDATE_IP) === false;
    }

    /** @return array<int, int> */
    private function normalizePorts(mixed $ports): array
    {
        if (! is_array($ports)) {
            return [];
        }

        return array_slice(array_values(array_unique(array_filter(array_map('intval', $ports), fn (int $port): bool => $port >= 1 && $port <= 65535))), 0, 32);
    }
}
