<?php

namespace App\Services\WebsiteSecurityAuditor;

use App\Domain\MCP\Connectors\WebsiteSecuritySourcesConnector;
use App\Domain\MCP\Security\CredentialVault;
use App\Models\Mcp\McpConnector;
use App\Models\Mcp\McpSiteConnector;
use App\Models\Site;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\Pool;
use Illuminate\Validation\ValidationException;
use PDO;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP;
use Throwable;

/**
 * Owner-configured, tenant-scoped, read-only source access for the auditor.
 * Secrets are stored only through the existing encrypted MCP CredentialVault.
 */
final class WebsiteSecuritySourceAccessService
{
    public const CONNECTOR_SLUG = 'website_security_sources';

    private const MAX_SOURCE_FILES = 80;

    private const MAX_SOURCE_DIRECTORIES = 40;

    private const MAX_SOURCE_ENTRIES = 500;

    private const MAX_SOURCE_SCAN_SECONDS = 60;

    private const MAX_SOURCE_BYTES = 4_194_304;

    private const MAX_FILE_BYTES = 131_072;

    public function __construct(private readonly CredentialVault $vault) {}

    /** @return array<string, array<string, mixed>> */
    public function statuses(Site $site): array
    {
        $record = $this->siteConnector($site);
        $metadata = is_array($record?->settings) ? ($record->settings['source_status'] ?? []) : [];
        $connections = $this->connections($site);
        $result = [];
        foreach (['repository', 'server', 'database'] as $type) {
            $saved = is_array($connections[$type] ?? null);
            $result[$type] = [
                'connected' => $saved && ($record?->status === 'connected'),
                'status' => $saved ? 'connected' : 'disconnected',
                'details' => $saved && is_array($metadata[$type] ?? null) ? $metadata[$type] : [],
                'last_connected_at' => $saved && is_string($metadata[$type]['connected_at'] ?? null) ? $metadata[$type]['connected_at'] : null,
            ];
        }

        return $result;
    }

    /** @return array<string, mixed> */
    public function connectRepository(Site $site, array $input): array
    {
        $provider = (string) ($input['provider'] ?? '');
        $repository = trim((string) ($input['repository'] ?? ''));
        $token = (string) ($input['access_token'] ?? '');
        $branch = trim((string) ($input['branch'] ?? ''));
        if (! in_array($provider, ['github', 'gitlab'], true) || ! $this->validRepositoryPath($repository, $provider) || strlen($token) < 8 || strlen($token) > 4096) {
            throw ValidationException::withMessages(['source' => 'Fournissez un fournisseur pris en charge, un chemin de dépôt valide et un jeton en lecture seule.']);
        }
        $repository = preg_replace('/\.git$/i', '', $repository) ?: $repository;
        try {
            if ($provider === 'github') {
                $response = $this->github($token)->get('https://api.github.com/repos/'.$repository);
                $metadata = $response->json();
                $defaultBranch = (string) ($metadata['default_branch'] ?? '');
            } else {
                $response = $this->gitlab($token)->get('https://gitlab.com/api/v4/projects/'.rawurlencode($repository));
                $metadata = $response->json();
                $defaultBranch = (string) ($metadata['default_branch'] ?? '');
            }
        } catch (Throwable) {
            throw ValidationException::withMessages(['source' => 'Connexion au dépôt impossible. Vérifiez le jeton et l’accès réseau; les identifiants n’ont pas été enregistrés.']);
        }
        if (! $response->successful() || ! is_array($metadata) || $defaultBranch === '') {
            throw ValidationException::withMessages(['source' => 'Le dépôt est inaccessible ou le jeton ne permet pas sa lecture. Les identifiants n’ont pas été enregistrés.']);
        }
        $branch = $branch !== '' ? $branch : $defaultBranch;
        if (strlen($branch) > 255 || preg_match('/[\x00-\x20]/', $branch)) {
            throw ValidationException::withMessages(['branch' => 'Branche invalide.']);
        }

        $connections = $this->connections($site);
        $connections['repository'] = compact('provider', 'repository', 'token', 'branch');
        $this->persist($site, $connections, [
            'provider' => $provider, 'repository' => $repository, 'branch' => $branch,
            'read_only' => true, 'connected_at' => now()->toISOString(),
        ]);

        return $this->statuses($site)['repository'];
    }

    /** @return array<string, mixed> */
    public function connectServer(Site $site, array $input): array
    {
        $host = strtolower(trim((string) ($input['host'] ?? '')));
        $port = (int) ($input['port'] ?? 22);
        $username = trim((string) ($input['username'] ?? ''));
        $password = (string) ($input['password'] ?? '');
        $privateKey = (string) ($input['private_key'] ?? '');
        $passphrase = (string) ($input['private_key_passphrase'] ?? '');
        $expectedFingerprint = trim((string) ($input['host_key_sha256'] ?? ''));
        $rootPath = trim((string) ($input['root_path'] ?? '/')) ?: '/';
        $privateNetworkAuthorized = (bool) ($input['private_network_authorized'] ?? false);

        if (! $this->validHost($host) || $port < 1 || $port > 65535 || $username === '' || strlen($username) > 128) {
            throw ValidationException::withMessages(['source' => 'Hôte, port ou utilisateur SFTP invalide.']);
        }
        if (($password === '') === ($privateKey === '')) {
            throw ValidationException::withMessages(['source' => 'Fournissez soit un mot de passe, soit une clé privée SSH.']);
        }
        if (! preg_match('/^SHA256:[A-Za-z0-9+\/]{43}$/', $expectedFingerprint)) {
            throw ValidationException::withMessages(['host_key_sha256' => 'L’empreinte SHA-256 OpenSSH du serveur est obligatoire (format SHA256:…).']);
        }
        if (! str_starts_with($rootPath, '/') || $rootPath === '/' || str_contains($rootPath, "\0") || preg_match('#(?:^|/)\.\.(?:/|$)#', $rootPath)
            || in_array(rtrim($rootPath, '/'), ['/etc', '/var', '/home', '/root', '/tmp', '/usr', '/'], true)) {
            throw ValidationException::withMessages(['root_path' => 'Indiquez la racine précise du projet (par exemple /var/www/app), sans traversée ni racine système générale.']);
        }
        $ip = $this->resolveAuthorizedAddress($host, $privateNetworkAuthorized);
        try {
            $sftp = new SFTP($ip, $port, 5);
            $publicKey = $sftp->getServerPublicHostKey();
            $actualFingerprint = $this->sshFingerprint(is_string($publicKey) ? $publicKey : '');
            if (! hash_equals($expectedFingerprint, $actualFingerprint)) {
                throw ValidationException::withMessages(['host_key_sha256' => 'L’empreinte SSH observée ne correspond pas à celle que vous avez confirmée.']);
            }
            $auth = $privateKey !== ''
                ? $sftp->login($username, PublicKeyLoader::load($privateKey, $passphrase !== '' ? $passphrase : null))
                : $sftp->login($username, $password);
            if (! $auth || ! $sftp->is_dir($rootPath)) {
                throw ValidationException::withMessages(['source' => 'Authentification SFTP refusée ou répertoire racine inaccessible.']);
            }
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw ValidationException::withMessages(['source' => 'Connexion SFTP impossible. Vérifiez le réseau, la clé d’hôte et les identifiants; rien n’a été enregistré.']);
        }

        $connection = [
            'host' => $host, 'port' => $port, 'username' => $username,
            'password' => $password, 'private_key' => $privateKey, 'private_key_passphrase' => $passphrase,
            'host_key_sha256' => $expectedFingerprint, 'root_path' => rtrim($rootPath, '/') ?: '/',
            'private_network_authorized' => $privateNetworkAuthorized,
        ];
        $connections = $this->connections($site);
        $connections['server'] = $connection;
        $this->persist($site, $connections, [
            'host' => $host, 'port' => $port, 'username' => $username,
            'host_key_sha256' => $actualFingerprint, 'root_path' => $connection['root_path'],
            'read_only' => true, 'connected_at' => now()->toISOString(),
        ]);

        return $this->statuses($site)['server'];
    }

    /** @return array<string, mixed> */
    public function connectDatabase(Site $site, array $input): array
    {
        $driver = (string) ($input['driver'] ?? '');
        $host = strtolower(trim((string) ($input['host'] ?? '')));
        $port = (int) ($input['port'] ?? ($driver === 'pgsql' ? 5432 : 3306));
        $database = trim((string) ($input['database'] ?? ''));
        $username = trim((string) ($input['username'] ?? ''));
        $password = (string) ($input['password'] ?? '');
        $privateNetworkAuthorized = (bool) ($input['private_network_authorized'] ?? false);
        if (! in_array($driver, ['mysql', 'pgsql'], true) || ! $this->validHost($host) || $port < 1 || $port > 65535
            || ! preg_match('/^[A-Za-z0-9_$.-]{1,128}$/', $database) || $username === '' || strlen($username) > 128
            || strlen($password) > 4096 || empty($input['read_only_confirmed']) || empty($input['tls_verify'])) {
            throw ValidationException::withMessages(['source' => 'Utilisez MySQL ou PostgreSQL, confirmez un compte en lecture seule et exigez TLS avec vérification du certificat.']);
        }
        $resolvedAddress = $this->resolveAuthorizedAddress($host, $privateNetworkAuthorized);
        $connection = compact('driver', 'host', 'port', 'database', 'username', 'password', 'privateNetworkAuthorized');
        $connection['resolved_address'] = $resolvedAddress;
        try {
            $facts = $this->inspectDatabase($connection);
        } catch (Throwable) {
            throw ValidationException::withMessages(['source' => 'Connexion DB sécurisée impossible. Vérifiez l’accès en lecture seule, le certificat TLS et les paramètres; les identifiants n’ont pas été enregistrés.']);
        }
        $connections = $this->connections($site);
        $connections['database'] = $connection;
        $this->persist($site, $connections, [
            'driver' => $driver, 'host' => $host, 'port' => $port, 'database' => $database,
            'read_only' => true, 'tls_verified' => $facts['tls_verified'], 'connected_at' => now()->toISOString(),
            'server_version' => $facts['server_version'], 'observed_schema_count' => $facts['schema_count'],
        ]);

        return $this->statuses($site)['database'];
    }

    public function disconnect(Site $site, string $source): void
    {
        if (! in_array($source, ['repository', 'server', 'database'], true)) {
            abort(404);
        }
        $connections = $this->connections($site);
        unset($connections[$source]);
        $record = $this->siteConnector($site);
        $metadata = is_array($record?->settings) ? ($record->settings['source_status'] ?? []) : [];
        unset($metadata[$source]);
        if ($connections !== []) {
            $this->persist($site, $connections, $metadata);
        } else {
            $this->ensureConnector();
            $this->vault->revoke($site, self::CONNECTOR_SLUG);
            $this->siteConnector($site)?->update(['settings' => ['source_status' => $metadata]]);
        }
    }

    /** @param null|callable(array<string, mixed>): void $progress @return array{files: array<int, array{path: string, source: string, content: string}>, database: ?array, sources: array<string, mixed>} */
    public function collect(Site $site, array $checks, ?callable $progress = null): array
    {
        $connections = $this->connections($site);
        $files = [];
        $dependencyFiles = [];
        $database = null;
        $sources = [];
        $sourceNames = array_keys(array_filter([
            'repository' => ! empty($checks['repository_analysis']),
            'server' => ! empty($checks['server_configuration']),
            'database' => ! empty($checks['database_posture']),
        ]));
        $lastReportedProgress = 43;
        $reportProgress = static function (string $source, float $fraction, string $message) use ($progress, $sourceNames, &$lastReportedProgress): void {
            if (! $progress || $sourceNames === []) {
                return;
            }

            $index = array_search($source, $sourceNames, true);
            if ($index === false) {
                return;
            }
            $fraction = max(0, min(1, $fraction));
            $slotSize = 14 / count($sourceNames);
            $start = 43 + ($slotSize * $index);
            $value = (int) floor($start + ($slotSize * $fraction));
            if ($fraction > 0) {
                $value = max($value, (int) ceil($start));
            }
            $value = max(44, min(57, $value));
            if ($value <= $lastReportedProgress) {
                return;
            }
            $lastReportedProgress = $value;
            $progress([
                'progress' => $value,
                'phase' => 'source_'.$source,
                'message' => $message,
            ]);
        };
        if (! empty($checks['repository_analysis']) && isset($connections['repository'])) {
            $reportProgress('repository', 0.02, 'Connexion au dépôt Git autorisé.');
            try {
                $repoFiles = $this->repositoryFiles(
                    $connections['repository'],
                    fn (float $fraction) => $reportProgress('repository', 0.08 + (0.9 * $fraction), 'Lecture progressive du dépôt Git autorisé.'),
                );
                array_push($files, ...$repoFiles['files']);
                $dependencyFiles = $repoFiles['dependency_files'];
                $sources['repository'] = ['status' => 'available', 'files_read' => count($repoFiles['files']), 'dependency_files_read' => count($dependencyFiles), 'contents_sent_to_llm' => false];
            } catch (Throwable) {
                $sources['repository'] = ['status' => 'unavailable', 'files_read' => 0, 'reason' => 'repository_read_failed'];
            }
            $reportProgress('repository', 1, 'Lecture du dépôt Git terminée.');
        } elseif (! empty($checks['repository_analysis'])) {
            $sources['repository'] = ['status' => 'not_configured', 'files_read' => 0];
            $reportProgress('repository', 1, 'Dépôt Git non connecté; étape ignorée.');
        }
        if (! empty($checks['server_configuration']) && isset($connections['server'])) {
            $reportProgress('server', 0.02, 'Connexion au serveur SFTP autorisé.');
            try {
                $serverScan = $this->serverFiles(
                    $connections['server'],
                    fn (float $fraction) => $reportProgress('server', 0.08 + (0.9 * $fraction), 'Lecture progressive des fichiers SFTP autorisés.'),
                );
                array_push($files, ...$serverScan['files']);
                $sources['server'] = [
                    'status' => 'available', 'files_read' => count($serverScan['files']), 'commands_executed' => 0,
                    'contents_sent_to_llm' => false, 'scan_limited' => $serverScan['scan_limited'],
                    'limit_reason' => $serverScan['limit_reason'],
                ];
            } catch (Throwable) {
                $sources['server'] = ['status' => 'unavailable', 'files_read' => 0, 'reason' => 'sftp_read_failed', 'commands_executed' => 0];
            }
            $reportProgress('server', 1, ! empty($serverScan['scan_limited'])
                ? 'Lecture SFTP partielle; une limite de temps/périmètre ou un dossier inaccessible a été rencontré.'
                : 'Lecture SFTP terminée.');
        } elseif (! empty($checks['server_configuration'])) {
            $sources['server'] = ['status' => 'not_configured', 'files_read' => 0, 'commands_executed' => 0];
            $reportProgress('server', 1, 'Serveur SFTP non connecté; étape ignorée.');
        }
        if (! empty($checks['database_posture']) && isset($connections['database'])) {
            $reportProgress('database', 0.02, 'Inspection read-only des métadonnées de la base.');
            try {
                $database = $this->inspectDatabase($connections['database']);
                $sources['database'] = [
                    'status' => 'available', 'read_only_transaction' => true, 'rows_read' => 0,
                    'tls_verified' => $database['tls_verified'], 'tls_cipher' => $database['tls_cipher'],
                    'schema_objects_observed' => $database['object_count'],
                ];
            } catch (Throwable) {
                $sources['database'] = ['status' => 'unavailable', 'reason' => 'database_metadata_read_failed'];
            }
            $reportProgress('database', 1, 'Inspection des métadonnées DB terminée.');
        } elseif (! empty($checks['database_posture'])) {
            $sources['database'] = ['status' => 'not_configured'];
            $reportProgress('database', 1, 'Base de données non connectée; étape ignorée.');
        }

        return ['files' => array_slice($files, 0, self::MAX_SOURCE_FILES), 'dependency_files' => $dependencyFiles, 'database' => $database, 'sources' => $sources];
    }

    /** @return array{files: array<int, array{path: string, source: string, content: string}>, dependency_files: array<int, array{path: string, source: string, content: string}>} */
    private function repositoryFiles(array $connection, ?callable $progress = null): array
    {
        $provider = $connection['provider'];
        $repository = $connection['repository'];
        $branch = $connection['branch'];
        $token = $connection['token'];
        $candidates = [];
        $dependencyCandidates = [];
        if ($provider === 'github') {
            $branchResponse = $this->github($token)->get('https://api.github.com/repos/'.$repository.'/branches/'.rawurlencode($branch));
            $sha = $branchResponse->json('commit.sha');
            if (! $branchResponse->successful() || ! is_string($sha)) {
                throw new \RuntimeException('Git branch unavailable');
            }
            $treeResponse = $this->github($token)->get('https://api.github.com/repos/'.$repository.'/git/trees/'.rawurlencode($sha), ['recursive' => '1']);
            if (strlen($treeResponse->body()) > 5_242_880) {
                throw new \RuntimeException('Git tree response exceeded its limit');
            }
            $tree = $treeResponse->json('tree');
            if (! $treeResponse->successful() || ! is_array($tree)) {
                throw new \RuntimeException('Git tree unavailable');
            }
            foreach ($tree as $item) {
                if (($item['type'] ?? null) === 'blob' && isset($item['path'])) {
                    $path = (string) $item['path'];
                    if ($this->isDependencyFile($path) && (int) ($item['size'] ?? 2_097_153) <= 2_097_152) {
                        $dependencyCandidates[] = $path;
                    } elseif ($this->eligiblePath($path) && (int) ($item['size'] ?? self::MAX_FILE_BYTES + 1) <= self::MAX_FILE_BYTES) {
                        $candidates[] = $path;
                    }
                }
                if (count($candidates) >= self::MAX_SOURCE_FILES) {
                    break;
                }
            }
            $read = $this->readRepositorySourceFiles($provider, $repository, $branch, $token, $candidates, $progress);

            $progress && $progress(0.82);
            $dependencyFiles = $this->readRepositoryDependencyFiles(
                $provider,
                $repository,
                $branch,
                $token,
                $dependencyCandidates,
                $progress ? fn (float $fraction) => $progress(0.82 + (0.18 * $fraction)) : null,
            );
            $progress && $progress(1);

            return ['files' => $read, 'dependency_files' => $dependencyFiles];
        }

        $projectId = rawurlencode($repository);
        $response = $this->gitlab($token)->get("https://gitlab.com/api/v4/projects/{$projectId}/repository/tree", [
            'recursive' => 'true', 'per_page' => '100', 'ref' => $branch,
        ]);
        $tree = $response->json();
        if (! $response->successful() || ! is_array($tree)) {
            throw new \RuntimeException('GitLab tree unavailable');
        }
        foreach ($tree as $item) {
            if (($item['type'] ?? null) === 'blob' && isset($item['path'])) {
                $path = (string) $item['path'];
                if ($this->isDependencyFile($path) && (int) ($item['size'] ?? 2_097_153) <= 2_097_152) {
                    $dependencyCandidates[] = $path;
                } elseif ($this->eligiblePath($path) && (int) ($item['size'] ?? self::MAX_FILE_BYTES + 1) <= self::MAX_FILE_BYTES) {
                    $candidates[] = $path;
                }
            }
            if (count($candidates) >= self::MAX_SOURCE_FILES) {
                break;
            }
        }
        $read = $this->readRepositorySourceFiles($provider, $repository, $branch, $token, $candidates, $progress);

        $progress && $progress(0.82);
        $dependencyFiles = $this->readRepositoryDependencyFiles(
            $provider,
            $repository,
            $branch,
            $token,
            $dependencyCandidates,
            $progress ? fn (float $fraction) => $progress(0.82 + (0.18 * $fraction)) : null,
        );
        $progress && $progress(1);

        return ['files' => $read, 'dependency_files' => $dependencyFiles];
    }

    /** @param array<int, string> $paths @return array<int, array{path: string, source: string, content: string}> */
    private function readRepositorySourceFiles(string $provider, string $repository, string $branch, string $token, array $paths, ?callable $progress = null): array
    {
        $paths = array_values($paths);
        $pathCount = count($paths);
        $files = [];
        $totalBytes = 0;
        $completed = 0;
        $limitReached = false;

        foreach (array_chunk($paths, 8) as $batch) {
            $responses = Http::pool(function (Pool $pool) use ($batch, $provider, $repository, $branch, $token): array {
                $requests = [];
                foreach ($batch as $index => $path) {
                    $key = 'source_'.$index;
                    if ($provider === 'github') {
                        $url = 'https://api.github.com/repos/'.$repository.'/contents/'.implode('/', array_map('rawurlencode', explode('/', $path)));
                        $requests[$key] = $pool->as($key)->withToken($token)->acceptJson()
                            ->withHeaders(['User-Agent' => 'ELChat-Website-Security-Auditor', 'X-GitHub-Api-Version' => '2022-11-28'])
                            ->timeout(5)->connectTimeout(2)->get($url, ['ref' => $branch]);
                    } else {
                        $projectId = rawurlencode($repository);
                        $fileId = rawurlencode($path);
                        $url = "https://gitlab.com/api/v4/projects/{$projectId}/repository/files/{$fileId}/raw";
                        $requests[$key] = $pool->as($key)->withHeaders([
                            'PRIVATE-TOKEN' => $token, 'User-Agent' => 'ELChat-Website-Security-Auditor',
                        ])->acceptJson()->timeout(5)->connectTimeout(2)->get($url, ['ref' => $branch]);
                    }
                }

                return $requests;
            });

            foreach ($batch as $index => $path) {
                $completed++;
                $progress && $progress(0.08 + (0.7 * ($completed / max(1, $pathCount))));
                $response = $responses['source_'.$index] ?? null;
                if (! $response || ! $response->successful()) {
                    continue;
                }

                if ($provider === 'github') {
                    if ((int) $response->json('size', self::MAX_FILE_BYTES + 1) > self::MAX_FILE_BYTES || $response->json('encoding') !== 'base64') {
                        continue;
                    }
                    $content = base64_decode((string) $response->json('content'), true);
                } else {
                    $content = $response->body();
                }

                if (! is_string($content) || ! $this->safeTextFile($path, $content)) {
                    continue;
                }
                if (strlen($content) > self::MAX_FILE_BYTES || $totalBytes + strlen($content) > self::MAX_SOURCE_BYTES) {
                    $limitReached = true;
                    break;
                }

                $files[] = ['path' => $path, 'source' => $provider.'_repository', 'content' => $content];
                $totalBytes += strlen($content);
            }

            if ($limitReached) {
                break;
            }
        }

        return $files;
    }

    /** @return array<int, array{path: string, source: string, content: string}> */
    private function readRepositoryDependencyFiles(string $provider, string $repository, string $branch, string $token, array $paths, ?callable $progress = null): array
    {
        $files = [];
        $total = 0;
        $paths = array_slice(array_values(array_unique($paths)), 0, 12);
        $pathCount = max(1, count($paths));
        foreach ($paths as $index => $path) {
            $progress && $progress(($index + 1) / $pathCount);
            if ($provider === 'github') {
                $url = 'https://api.github.com/repos/'.$repository.'/contents/'.implode('/', array_map('rawurlencode', explode('/', $path)));
                $response = $this->github($token)->get($url, ['ref' => $branch]);
                if (! $response->successful() || (int) $response->json('size', 2_097_153) > 2_097_152 || $response->json('encoding') !== 'base64') {
                    continue;
                }
                $content = base64_decode((string) $response->json('content'), true);
            } else {
                $projectId = rawurlencode($repository);
                $response = $this->gitlab($token)->get("https://gitlab.com/api/v4/projects/{$projectId}/repository/files/".rawurlencode($path).'/raw', ['ref' => $branch]);
                if (! $response->successful()) {
                    continue;
                }
                $content = $response->body();
            }
            if (! is_string($content) || strlen($content) > 2_097_152 || str_contains($content, "\0")
                || (function_exists('mb_check_encoding') && ! mb_check_encoding($content, 'UTF-8'))) {
                continue;
            }
            if ($total + strlen($content) > 5_242_880) {
                break;
            }
            $files[] = ['path' => $path, 'source' => $provider.'_repository_lockfile', 'content' => $content];
            $total += strlen($content);
        }

        $progress && $progress(1);

        return $files;
    }

    /** @return array{files: array<int, array{path: string, source: string, content: string}>, scan_limited: bool, limit_reason: ?string} */
    private function serverFiles(array $connection, ?callable $progress = null): array
    {
        $ip = $this->resolveAuthorizedAddress($connection['host'], (bool) ($connection['private_network_authorized'] ?? false));
        $sftp = new SFTP($ip, (int) $connection['port'], 5);
        $serverKey = $sftp->getServerPublicHostKey();
        $fingerprint = $this->sshFingerprint(is_string($serverKey) ? $serverKey : '');
        if (! hash_equals((string) $connection['host_key_sha256'], $fingerprint)) {
            throw new \RuntimeException('Host key changed');
        }
        $authenticated = ! empty($connection['private_key'])
            ? $sftp->login($connection['username'], PublicKeyLoader::load($connection['private_key'], $connection['private_key_passphrase'] ?: null))
            : $sftp->login($connection['username'], $connection['password']);
        if (! $authenticated) {
            throw new \RuntimeException('SFTP authentication failed');
        }
        $sftp->setTimeout(5);
        $progress && $progress(0.1);

        $root = rtrim((string) $connection['root_path'], '/') ?: '/';
        $queue = [[$root, 0]];
        $read = [];
        $total = 0;
        $seen = [];
        $directoriesVisited = 0;
        $entriesVisited = 0;
        $deadline = microtime(true) + self::MAX_SOURCE_SCAN_SECONDS;
        $limitReason = null;
        $reportScanProgress = static function () use (&$directoriesVisited, &$entriesVisited, &$read, $progress): void {
            if (! $progress) {
                return;
            }
            $fraction = min(0.94,
                0.1
                + (0.4 * min(1, $directoriesVisited / self::MAX_SOURCE_DIRECTORIES))
                + (0.18 * min(1, $entriesVisited / self::MAX_SOURCE_ENTRIES))
                + (0.26 * min(1, count($read) / self::MAX_SOURCE_FILES)));
            $progress($fraction);
        };
        while ($queue !== [] && count($read) < self::MAX_SOURCE_FILES
            && $directoriesVisited < self::MAX_SOURCE_DIRECTORIES && microtime(true) < $deadline) {
            [$directory, $depth] = array_shift($queue);
            if ($depth > 5 || isset($seen[$directory])) {
                continue;
            }
            $seen[$directory] = true;
            $directoriesVisited++;
            $items = $sftp->nlist($directory);
            $reportScanProgress();
            if (! is_array($items)) {
                $limitReason ??= 'unreadable_directory';
                continue;
            }
            foreach ($items as $name) {
                if (microtime(true) >= $deadline) {
                    $limitReason = 'time_limit';
                    break 2;
                }
                if ($entriesVisited >= self::MAX_SOURCE_ENTRIES) {
                    $limitReason = 'entry_limit';
                    break 2;
                }
                $entriesVisited++;
                $reportScanProgress();
                if (! is_string($name) || $name === '.' || $name === '..' || str_contains($name, '/') || str_contains($name, "\0")) {
                    continue;
                }
                $path = rtrim($directory, '/').'/'.$name;
                if ($sftp->is_link($path)) {
                    continue;
                }
                if ($sftp->is_dir($path)) {
                    if ($depth < 5 && ! $this->excludedDirectory($name)) {
                        if (count($queue) < self::MAX_SOURCE_DIRECTORIES) {
                            $queue[] = [$path, $depth + 1];
                        } else {
                            $limitReason ??= 'directory_queue_limit';
                        }
                    }

                    continue;
                }
                $relative = ltrim(substr($path, strlen(rtrim($root, '/'))), '/');
                if (! $this->eligiblePath($relative)) {
                    continue;
                }
                $stat = $sftp->stat($path);
                if (! is_array($stat) || (int) ($stat['size'] ?? self::MAX_FILE_BYTES + 1) > self::MAX_FILE_BYTES) {
                    continue;
                }
                $content = $sftp->get($path);
                if (! is_string($content) || ! $this->safeTextFile($relative, $content)) {
                    continue;
                }
                if ($total + strlen($content) > self::MAX_SOURCE_BYTES) {
                    $limitReason = 'byte_limit';
                    break 2;
                }
                $read[] = ['path' => $relative, 'source' => 'sftp_server', 'content' => $content];
                $total += strlen($content);
                $reportScanProgress();
                if (count($read) >= self::MAX_SOURCE_FILES) {
                    $limitReason = 'file_limit';
                    break;
                }
            }
        }

        if ($limitReason === null && $queue !== []) {
            if (microtime(true) >= $deadline) {
                $limitReason = 'time_limit';
            } elseif ($directoriesVisited >= self::MAX_SOURCE_DIRECTORIES) {
                $limitReason = 'directory_limit';
            } elseif (count($read) >= self::MAX_SOURCE_FILES) {
                $limitReason = 'file_limit';
            }
        }

        $progress && $progress(1);

        return ['files' => $read, 'scan_limited' => $limitReason !== null, 'limit_reason' => $limitReason];
    }

    /** Database catalog only: no table rows or user-defined SQL are ever read. */
    private function inspectDatabase(array $connection): array
    {
        $plan = $this->databaseConnectionPlan($connection);
        $pdo = new PDO($plan['dsn'], $connection['username'], $connection['password'], $plan['options']);
        $tlsCipher = null;
        if ($connection['driver'] === 'mysql') {
            $tlsStatus = $pdo->query("SHOW SESSION STATUS LIKE 'Ssl_cipher'")->fetch(PDO::FETCH_NUM);
            if (! is_array($tlsStatus) || trim((string) ($tlsStatus[1] ?? '')) === '') {
                throw new \RuntimeException('MySQL did not negotiate a TLS connection.');
            }
            $tlsCipher = trim((string) $tlsStatus[1]);
            $pdo->exec('SET SESSION TRANSACTION READ ONLY');
            $pdo->beginTransaction();
            $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
            $user = (string) $pdo->query('SELECT CURRENT_USER()')->fetchColumn();
            $schemas = $pdo->prepare('SELECT schema_name FROM information_schema.schemata WHERE schema_name = :schema LIMIT 20');
            $schemas->execute(['schema' => $connection['database']]);
            $schemaNames = $schemas->fetchAll(PDO::FETCH_COLUMN);
            $tableStatement = $pdo->prepare('SELECT table_name FROM information_schema.tables WHERE table_schema = :schema ORDER BY table_name LIMIT 200');
            $tableStatement->execute(['schema' => $connection['database']]);
            $tables = $tableStatement->fetchAll(PDO::FETCH_COLUMN);
            $grants = $pdo->query('SHOW GRANTS FOR CURRENT_USER')->fetchAll(PDO::FETCH_COLUMN);
            $privilegeText = implode(' ', array_map('strval', $grants));
            $canWrite = preg_match('/\b(ALL PRIVILEGES|INSERT|UPDATE|DELETE|DROP|ALTER|CREATE)\b/i', $privilegeText) === 1;
            $pdo->rollBack();
        } else {
            $pdo->exec('SET default_transaction_read_only = on');
            $pdo->beginTransaction();
            $version = (string) $pdo->query('SELECT version()')->fetchColumn();
            $user = (string) $pdo->query('SELECT current_user')->fetchColumn();
            $schemas = $pdo->prepare('SELECT schema_name FROM information_schema.schemata WHERE schema_name NOT IN (\'pg_catalog\', \'information_schema\') ORDER BY schema_name LIMIT 20');
            $schemas->execute();
            $schemaNames = $schemas->fetchAll(PDO::FETCH_COLUMN);
            $tables = $pdo->query('SELECT table_schema || \'.\' || table_name FROM information_schema.tables WHERE table_type = \'BASE TABLE\' AND table_schema NOT IN (\'pg_catalog\', \'information_schema\') ORDER BY table_schema, table_name LIMIT 200')->fetchAll(PDO::FETCH_COLUMN);
            $tableWritePrivileges = (int) $pdo->query(<<<'SQL'
                SELECT COUNT(*)
                FROM information_schema.tables t
                WHERE t.table_schema NOT IN ('pg_catalog', 'information_schema')
                  AND (
                    has_table_privilege(current_user, format('%I.%I', t.table_schema, t.table_name), 'INSERT')
                    OR has_table_privilege(current_user, format('%I.%I', t.table_schema, t.table_name), 'UPDATE')
                    OR has_table_privilege(current_user, format('%I.%I', t.table_schema, t.table_name), 'DELETE')
                    OR has_table_privilege(current_user, format('%I.%I', t.table_schema, t.table_name), 'TRUNCATE')
                  )
                SQL)->fetchColumn();
            $sequenceWritePrivileges = (int) $pdo->query(<<<'SQL'
                SELECT COUNT(*)
                FROM information_schema.sequences s
                WHERE has_sequence_privilege(current_user, format('%I.%I', s.sequence_schema, s.sequence_name), 'UPDATE')
                SQL)->fetchColumn();
            $schemaCreatePrivileges = (int) $pdo->query(<<<'SQL'
                SELECT COUNT(*)
                FROM information_schema.schemata s
                WHERE s.schema_name NOT IN ('pg_catalog', 'information_schema')
                  AND has_schema_privilege(current_user, s.schema_name, 'CREATE')
                SQL)->fetchColumn();
            $databaseCreatePrivilege = (int) $pdo->query("SELECT CASE WHEN has_database_privilege(current_user, current_database(), 'CREATE') THEN 1 ELSE 0 END")->fetchColumn();
            $canWrite = $tableWritePrivileges > 0 || $sequenceWritePrivileges > 0
                || $schemaCreatePrivileges > 0 || $databaseCreatePrivilege > 0;
            $pdo->rollBack();
        }

        return [
            'driver' => $connection['driver'], 'database' => $connection['database'],
            'host' => $connection['host'], 'resolved_address' => $connection['resolved_address'], 'port' => $connection['port'],
            'server_version' => $version, 'authenticated_role' => $user,
            'tls_verified' => true, 'tls_cipher' => $tlsCipher,
            'schema_names' => array_values(array_map('strval', $schemaNames)), 'schema_count' => count($schemaNames),
            'table_names' => array_values(array_map('strval', $tables)), 'object_count' => count($tables),
            'write_privileges_observed' => $canWrite, 'privilege_assessment' => $canWrite ? 'write_privilege_observed' : 'no_write_privilege_observed_in_catalog',
            'read_only_transaction' => true, 'table_rows_read' => 0,
        ];
    }

    /** @return array{dsn: string, options: array<int, mixed>} */
    private function databaseConnectionPlan(array $connection): array
    {
        $driver = (string) ($connection['driver'] ?? '');
        $host = (string) ($connection['host'] ?? '');
        $address = (string) ($connection['resolved_address'] ?? '');
        if (! in_array($driver, ['mysql', 'pgsql'], true) || filter_var($address, FILTER_VALIDATE_IP) === false) {
            throw new \RuntimeException('A validated database IP address is required.');
        }

        $port = (int) ($connection['port'] ?? 0);
        $database = (string) ($connection['database'] ?? '');
        $addressForMysqlDsn = str_contains($address, ':') ? '['.$address.']' : $address;
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 4,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        if ($driver === 'mysql') {
            if (! defined('PDO::MYSQL_ATTR_SSL_CA') || ! defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
                throw new \RuntimeException('PDO MySQL certificate verification is unavailable.');
            }
            $caFile = $this->mysqlSslCaFile();
            if ($caFile === null) {
                throw new \RuntimeException('A readable MySQL TLS CA bundle is required.');
            }
            $options[constant('PDO::MYSQL_ATTR_SSL_CA')] = $caFile;
            $options[constant('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')] = true;

            return [
                'dsn' => "mysql:host={$addressForMysqlDsn};port={$port};dbname={$database};charset=utf8mb4",
                'options' => $options,
            ];
        }

        return [
            'dsn' => "pgsql:host={$host};hostaddr={$address};port={$port};dbname={$database};sslmode=verify-full;connect_timeout=4",
            'options' => $options,
        ];
    }

    private function mysqlSslCaFile(): ?string
    {
        $candidate = null;
        if (defined('PDO::MYSQL_ATTR_SSL_CA')) {
            $options = config('database.connections.mysql.options', []);
            $candidate = is_array($options) ? ($options[constant('PDO::MYSQL_ATTR_SSL_CA')] ?? null) : null;
        }
        if (! is_string($candidate) || $candidate === '') {
            $locations = function_exists('openssl_get_cert_locations') ? openssl_get_cert_locations() : [];
            $candidate = is_array($locations) ? ($locations['default_cert_file'] ?? null) : null;
        }
        if (! is_string($candidate) || $candidate === '') {
            return null;
        }

        $path = realpath($candidate);

        return $path !== false && is_file($path) && is_readable($path) ? $path : null;
    }

    private function github(string $token)
    {
        return Http::withToken($token)->acceptJson()->withHeaders(['User-Agent' => 'ELChat-Website-Security-Auditor', 'X-GitHub-Api-Version' => '2022-11-28'])->timeout(5)->connectTimeout(2);
    }

    private function gitlab(string $token)
    {
        return Http::withHeaders(['PRIVATE-TOKEN' => $token, 'User-Agent' => 'ELChat-Website-Security-Auditor'])->acceptJson()->timeout(5)->connectTimeout(2);
    }

    private function validRepositoryPath(string $repository, string $provider): bool
    {
        if (str_contains($repository, '://') || str_contains($repository, '?') || str_contains($repository, '#') || str_contains($repository, '@')) {
            return false;
        }
        $pattern = $provider === 'github' ? '/^[A-Za-z0-9_.-]{1,100}\/[A-Za-z0-9_.-]{1,100}(?:\.git)?$/' : '/^[A-Za-z0-9_.-]{1,100}(?:\/[A-Za-z0-9_.-]{1,100}){1,8}(?:\.git)?$/';

        return preg_match($pattern, $repository) === 1 && ! str_contains($repository, '..');
    }

    private function validHost(string $host): bool
    {
        return strlen($host) <= 253 && filter_var($host, FILTER_VALIDATE_IP) !== false
            || (strlen($host) <= 253 && preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))*$/i', $host) === 1);
    }

    private function resolveAuthorizedAddress(string $host, bool $privateNetworkAuthorized): string
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $addresses = [$host];
        } else {
            $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
            $addresses = [];
            foreach ($records as $record) {
                if (isset($record['ip'])) {
                    $addresses[] = $record['ip'];
                } elseif (isset($record['ipv6'])) {
                    $addresses[] = $record['ipv6'];
                }
            }
        }
        foreach (array_unique($addresses) as $ip) {
            if (in_array($ip, ['169.254.169.254', '169.254.170.2', '100.100.100.200', 'fd00:ec2::254'], true)) {
                continue;
            }
            $flags = FILTER_FLAG_NO_RES_RANGE | ($privateNetworkAuthorized ? 0 : FILTER_FLAG_NO_PRIV_RANGE);
            if (filter_var($ip, FILTER_VALIDATE_IP, $flags) !== false) {
                return $ip;
            }
        }
        throw ValidationException::withMessages(['host' => 'L’hôte ne résout pas vers une adresse autorisée. Les adresses réservées sont bloquées; un réseau privé exige une confirmation distincte.']);
    }

    private function sshFingerprint(string $publicKey): string
    {
        $parts = preg_split('/\s+/', trim($publicKey)) ?: [];
        $blob = isset($parts[1]) ? base64_decode($parts[1], true) : false;
        if (! is_string($blob)) {
            return '';
        }

        return 'SHA256:'.rtrim(base64_encode(hash('sha256', $blob, true)), '=');
    }

    private function eligiblePath(string $path): bool
    {
        $path = str_replace('\\', '/', ltrim($path, '/'));
        foreach (explode('/', strtolower($path)) as $segment) {
            if ($this->excludedDirectory($segment)) {
                return false;
            }
        }
        $base = strtolower(basename($path));
        if (in_array($base, ['.env', '.env.local', '.env.production', 'id_rsa', 'id_ed25519', 'authorized_keys', 'known_hosts', 'composer.lock', 'package-lock.json', 'yarn.lock', 'pnpm-lock.yaml'], true)) {
            return false;
        }
        if (in_array($base, ['.env.example', '.env.sample', '.env.template', 'dockerfile', 'makefile', 'nginx.conf', 'apache.conf', 'php.ini'], true)) {
            return true;
        }

        return preg_match('/\.(php|phtml|inc|js|jsx|ts|tsx|mjs|cjs|py|rb|go|java|cs|rs|conf|ini|ya?ml|toml|json)$/i', $path) === 1;
    }

    private function isDependencyFile(string $path): bool
    {
        return in_array(strtolower(basename($path)), ['composer.lock', 'composer.json', 'package-lock.json', 'package.json'], true);
    }

    private function excludedDirectory(string $name): bool
    {
        return in_array(strtolower($name), ['.git', '.svn', '.ssh', '.aws', '.config', '.docker', '.idea', '.vscode', 'vendor', 'node_modules', 'dist', 'build', 'coverage', 'cache', 'tmp', 'temp', 'logs', 'storage', 'uploads', 'backups', 'backup'], true);
    }

    private function safeTextFile(string $path, string $content): bool
    {
        if (strlen($content) > self::MAX_FILE_BYTES || str_contains(strtolower($path), '.pem') || str_contains(strtolower($path), '.key')) {
            return false;
        }

        return ! str_contains($content, "\0") && (! function_exists('mb_check_encoding') || mb_check_encoding($content, 'UTF-8'));
    }

    /** @return array<string, array<string, mixed>> */
    private function connections(Site $site): array
    {
        $saved = $this->vault->retrieve($site, self::CONNECTOR_SLUG) ?? [];

        return is_array($saved['connections'] ?? null) ? $saved['connections'] : [];
    }

    private function persist(Site $site, array $connections, array $sourceStatuses): void
    {
        $this->ensureConnector();
        $current = $this->siteConnector($site);
        $currentStatuses = is_array($current?->settings) ? ($current->settings['source_status'] ?? []) : [];
        $currentStatuses = array_intersect_key(is_array($currentStatuses) ? $currentStatuses : [], array_flip(array_keys($connections)));
        $this->vault->store($site, self::CONNECTOR_SLUG, ['connections' => $connections], [
            'source_status' => array_replace(is_array($currentStatuses) ? $currentStatuses : [], $sourceStatuses),
        ]);
    }

    private function ensureConnector(): void
    {
        McpConnector::query()->firstOrCreate(['slug' => self::CONNECTOR_SLUG], [
            'name' => 'Website Security Auditor sources', 'category' => 'security',
            'auth_type' => 'internal', 'adapter_class' => WebsiteSecuritySourcesConnector::class,
            'available_tools' => [], 'is_active' => false,
            'description' => 'Coffre interne chiffré pour les sources read-only de Website Security Auditor. Aucun outil conversationnel.',
        ]);
    }

    private function siteConnector(Site $site): ?McpSiteConnector
    {
        return $site->mcpSiteConnectors()->whereHas('mcpConnector', fn ($query) => $query->where('slug', self::CONNECTOR_SLUG))->first();
    }
}
