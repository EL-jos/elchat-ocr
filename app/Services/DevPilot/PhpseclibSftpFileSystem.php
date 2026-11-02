<?php

namespace App\Services\DevPilot;

use App\Models\DevPilot\DevPilotTask;
use App\Models\DevPilot\DevPilotTerminalSession;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP;
use phpseclib3\Net\SSH2;
use RuntimeException;
use Throwable;

final class PhpseclibSftpFileSystem implements RemoteFileSystem
{
    private const SHELL_AND_TIMEOUT_PROBE = <<<'SH'
printf 'ELCHAT_SHELL=present\n'
if command -v timeout >/dev/null 2>&1; then printf 'TIMEOUT=present\n'; else printf 'TIMEOUT=absent\n'; fi
SH;

    private const TOOL_SCAN_PROBE = <<<'SH'
for c in timeout php composer node npm pnpm yarn python3 pip java mvn gradle dotnet go rustc cargo git wp systemctl php-fpm nginx apache2 httpd; do
    if command -v "$c" >/dev/null 2>&1; then printf 'BIN=%s\n' "$c"; fi
done
if [ -n "$HOME" ] && [ -f "$HOME/wp-cli.phar" ]; then printf 'WP_CLI_PHAR=present\n'; else printf 'WP_CLI_PHAR=absent\n'; fi
printf 'TOOLS_COMPLETE=1\n'
SH;

    private const EXTENDED_ENVIRONMENT_PROBE = <<<'SH'
uid=$(id -u 2>/dev/null); printf 'UID=%s\n' "$uid"
user=$(id -un 2>/dev/null); printf 'USER=%s\n' "$user"
kernel=$(uname -s 2>/dev/null); printf 'KERNEL=%s\n' "$kernel"
if test -r /etc/os-release; then
    printf 'OS_RELEASE=readable\n'
    while IFS= read -r line; do
        case "$line" in
            ID=*) printf 'ID=%s\n' "${line#ID=}" ;;
            VERSION_ID=*) printf 'VERSION_ID=%s\n' "${line#VERSION_ID=}" ;;
        esac
    done < /etc/os-release
else
    printf 'OS_RELEASE=unreadable\n'
fi
for c in timeout php composer node npm pnpm yarn python3 pip java mvn gradle dotnet go rustc cargo git wp systemctl php-fpm nginx apache2 httpd; do
    if command -v "$c" >/dev/null 2>&1; then printf 'BIN=%s\n' "$c"; fi
done
if [ -n "$HOME" ] && [ -f "$HOME/wp-cli.phar" ]; then printf 'WP_CLI_PHAR=present\n'; else printf 'WP_CLI_PHAR=absent\n'; fi
printf 'TOOLS_COMPLETE=1\n'
if test -e /usr/local/cpanel; then printf 'PANEL_cpanel=present\n'; elif test -r /usr/local && test -x /usr/local; then printf 'PANEL_cpanel=absent\n'; else printf 'PANEL_cpanel=unknown\n'; fi
if test -e /usr/local/psa; then printf 'PANEL_plesk=present\n'; elif test -r /usr/local && test -x /usr/local; then printf 'PANEL_plesk=absent\n'; else printf 'PANEL_plesk=unknown\n'; fi
if test -e /usr/local/directadmin; then printf 'PANEL_directadmin=present\n'; elif test -r /usr/local && test -x /usr/local; then printf 'PANEL_directadmin=absent\n'; else printf 'PANEL_directadmin=unknown\n'; fi
if test -e /usr/local/hestia; then printf 'PANEL_hestia=present\n'; elif test -r /usr/local && test -x /usr/local; then printf 'PANEL_hestia=absent\n'; else printf 'PANEL_hestia=unknown\n'; fi
if test -e /usr/local/ispconfig; then printf 'PANEL_ispconfig=present\n'; elif test -r /usr/local && test -x /usr/local; then printf 'PANEL_ispconfig=absent\n'; else printf 'PANEL_ispconfig=unknown\n'; fi
if test -e /usr/local/CyberCP; then printf 'PANEL_cyberpanel=present\n'; elif test -r /usr/local && test -x /usr/local; then printf 'PANEL_cyberpanel=absent\n'; else printf 'PANEL_cyberpanel=unknown\n'; fi
if test -e /usr/share/webmin; then printf 'PANEL_webmin=present\n'; elif test -r /usr/share && test -x /usr/share; then printf 'PANEL_webmin=absent\n'; else printf 'PANEL_webmin=unknown\n'; fi
if test -e /www/server/panel; then printf 'PANEL_aapanel=present\n'; elif test -r /www/server && test -x /www/server; then printf 'PANEL_aapanel=absent\n'; else printf 'PANEL_aapanel=unknown\n'; fi
printf 'SUDO=unknown\n'
printf 'ENV_COMPLETE=1\n'
SH;

    private const EXTENDED_ENVIRONMENT_PROBE_WITH_SUDO = <<<'SH'
uid=$(id -u 2>/dev/null); printf 'UID=%s\n' "$uid"
user=$(id -un 2>/dev/null); printf 'USER=%s\n' "$user"
kernel=$(uname -s 2>/dev/null); printf 'KERNEL=%s\n' "$kernel"
if test -r /etc/os-release; then
    printf 'OS_RELEASE=readable\n'
    while IFS= read -r line; do
        case "$line" in
            ID=*) printf 'ID=%s\n' "${line#ID=}" ;;
            VERSION_ID=*) printf 'VERSION_ID=%s\n' "${line#VERSION_ID=}" ;;
        esac
    done < /etc/os-release
else
    printf 'OS_RELEASE=unreadable\n'
fi
for c in timeout php composer node npm pnpm yarn python3 pip java mvn gradle dotnet go rustc cargo git wp systemctl php-fpm nginx apache2 httpd; do
    if command -v "$c" >/dev/null 2>&1; then printf 'BIN=%s\n' "$c"; fi
done
if [ -n "$HOME" ] && [ -f "$HOME/wp-cli.phar" ]; then printf 'WP_CLI_PHAR=present\n'; else printf 'WP_CLI_PHAR=absent\n'; fi
printf 'TOOLS_COMPLETE=1\n'
if test -e /usr/local/cpanel; then printf 'PANEL_cpanel=present\n'; elif test -r /usr/local && test -x /usr/local; then printf 'PANEL_cpanel=absent\n'; else printf 'PANEL_cpanel=unknown\n'; fi
if test -e /usr/local/psa; then printf 'PANEL_plesk=present\n'; elif test -r /usr/local && test -x /usr/local; then printf 'PANEL_plesk=absent\n'; else printf 'PANEL_plesk=unknown\n'; fi
if test -e /usr/local/directadmin; then printf 'PANEL_directadmin=present\n'; elif test -r /usr/local && test -x /usr/local; then printf 'PANEL_directadmin=absent\n'; else printf 'PANEL_directadmin=unknown\n'; fi
if test -e /usr/local/hestia; then printf 'PANEL_hestia=present\n'; elif test -r /usr/local && test -x /usr/local; then printf 'PANEL_hestia=absent\n'; else printf 'PANEL_hestia=unknown\n'; fi
if test -e /usr/local/ispconfig; then printf 'PANEL_ispconfig=present\n'; elif test -r /usr/local && test -x /usr/local; then printf 'PANEL_ispconfig=absent\n'; else printf 'PANEL_ispconfig=unknown\n'; fi
if test -e /usr/local/CyberCP; then printf 'PANEL_cyberpanel=present\n'; elif test -r /usr/local && test -x /usr/local; then printf 'PANEL_cyberpanel=absent\n'; else printf 'PANEL_cyberpanel=unknown\n'; fi
if test -e /usr/share/webmin; then printf 'PANEL_webmin=present\n'; elif test -r /usr/share && test -x /usr/share; then printf 'PANEL_webmin=absent\n'; else printf 'PANEL_webmin=unknown\n'; fi
if test -e /www/server/panel; then printf 'PANEL_aapanel=present\n'; elif test -r /www/server && test -x /www/server; then printf 'PANEL_aapanel=absent\n'; else printf 'PANEL_aapanel=unknown\n'; fi
if [ "$uid" = '0' ]; then
    printf 'SUDO=unknown\n'
elif command -v sudo >/dev/null 2>&1 && sudo -n true >/dev/null 2>&1; then
    printf 'SUDO=present\n'
else
    printf 'SUDO=absent\n'
fi
printf 'ENV_COMPLETE=1\n'
SH;

    private array $connection;
    private array $secrets;
    private string $root;
    private ?DevPilotTask $task;
    private ?\Closure $cancellationCheck;
    private ?SFTP $sftp = null;

    public function __construct(array $options)
    {
        $this->connection = $options['connection'];
        $this->secrets = $options['secrets'];
        $this->root = $this->rootPath((string) ($this->connection['root_path'] ?? ''), (string) ($options['project_root'] ?? '.'));
        $this->task = $options['task'] ?? null;
        $this->cancellationCheck = $options['cancellation_check'] ?? null;
    }

    public function test(bool $probeCommands = true): array
    {
        $sftp = $this->client();
        if (! $sftp->is_dir($this->root)) {
            throw new RuntimeException('The configured project directory does not exist or is not readable.');
        }
        $listing = $this->safeList($sftp, $this->root);
        $commands = [];
        $shellAvailable = false;
        $executionUser = null;
        $operatingSystem = null;
        $environment = [];
        $probeObservations = [];
        $timeoutAvailable = false;
        $commandPermissionEnabled = ($this->connection['protocol'] ?? '') === 'ssh' && ! empty($this->connection['can_run_commands']);
        $executionMode = DevPilotCommandPolicy::executionMode($this->connection['command_execution_mode'] ?? null, $commandPermissionEnabled);
        $shellConfigured = $commandPermissionEnabled && $executionMode !== 'disabled';
        $probeShellAllowed = $shellConfigured || (($this->connection['protocol'] ?? '') === 'ssh' && ! empty($this->connection['probe_sudo']));
        if ($probeCommands && $probeShellAllowed) {
            $probeStartedAt = microtime(true);
            $probeDeadline = $probeStartedAt + DevPilotEnvironmentProbe::MAX_TOTAL_SECONDS;
            $totalOutputBytes = 0;
            try {
                $ssh = $this->shellClient();
                $shellProbe = $this->executeBoundedProbe($ssh, $this->probeCommand(self::SHELL_AND_TIMEOUT_PROBE, false), $probeDeadline, $totalOutputBytes);
                $shellAvailable = $shellProbe['complete'] && str_contains($shellProbe['output'], "ELCHAT_SHELL=present\n");
                $timeoutAvailable = $shellAvailable && str_contains($shellProbe['output'], "TIMEOUT=present\n");
                if ($shellAvailable) {
                    $script = ! empty($this->connection['probe_sudo'])
                        ? self::EXTENDED_ENVIRONMENT_PROBE_WITH_SUDO
                        : self::EXTENDED_ENVIRONMENT_PROBE;
                    $extended = $this->executeBoundedProbe($ssh, $this->probeCommand($script, $timeoutAvailable), $probeDeadline, $totalOutputBytes);
                    $complete = $extended['complete'] && str_contains($extended['output'], "ENV_COMPLETE=1\n");
                    $system = DevPilotEnvironmentProbe::parseSystemOutput($extended['output'], $complete);
                    $tools = DevPilotEnvironmentProbe::parseToolOutput($extended['output'], $complete);
                    $wpCliInventory = $this->probeWordPressCliCommands($ssh, $tools, $timeoutAvailable, $probeDeadline, $totalOutputBytes);
                    $panels = DevPilotEnvironmentProbe::parsePanelOutput($extended['output'], $complete);
                    $sudo = DevPilotEnvironmentProbe::parseSudoOutput($extended['output'], $complete);
                    if ($complete) {
                        $diskArguments = $timeoutAvailable ? ['timeout', '5s', 'df', '-Pk', '.'] : ['df', '-Pk', '.'];
                        $diskCommand = DevPilotCommandBuilder::forInteractivePty($this->root, $diskArguments);
                        $diskProbe = $this->executeBoundedProbe($ssh, $diskCommand, $probeDeadline, $totalOutputBytes);
                        $disk = DevPilotEnvironmentProbe::parseDiskOutput($diskProbe['output'], $diskProbe['complete']);
                    } else {
                        $disk = DevPilotEnvironmentProbe::parseDiskOutput('', false);
                    }
                    $executionUser = $system['execution_user'];
                    $operatingSystem = $system['operating_system'];
                    $commands = $tools['commands_detected'];
                    $environment = [
                        'distribution_id' => $system['distribution_id'],
                        'version_id' => $system['version_id'],
                        'panel_markers' => $panels['panels'],
                        'sudo_noninteractive' => $sudo['status'],
                        'sudo_probe_enabled' => ! empty($this->connection['probe_sudo']),
                        'tool_detection_complete' => $tools['complete'],
                        'wp_cli_phar' => $tools['wp_cli_phar'],
                        'wp_cli_cache_purge_commands' => $wpCliInventory['cache_purge_commands'],
                        'extended_probe_complete' => $complete,
                        'disk_space' => ['available_kb' => $disk['available_kb'], 'use_percent' => $disk['use_percent']],
                    ];
                    $probeObservations = [
                        ...$system['observations'], ...$tools['observations'],
                        ...$wpCliInventory['observations'],
                        ...$panels['observations'], $sudo['observation'], $disk['observation'],
                    ];
                } else {
                    $probeObservations = $this->unknownExtendedProbeObservations();
                    $environment = [
                        'distribution_id' => null, 'version_id' => null,
                        'panel_markers' => array_fill_keys(array_keys(DevPilotEnvironmentProbe::PANEL_MARKERS), 'unknown'),
                        'sudo_noninteractive' => 'unknown',
                        'sudo_probe_enabled' => ! empty($this->connection['probe_sudo']),
                        'tool_detection_complete' => false, 'wp_cli_phar' => 'unknown', 'wp_cli_cache_purge_commands' => [], 'extended_probe_complete' => false,
                        'disk_space' => ['available_kb' => null, 'use_percent' => null],
                    ];
                }
            } catch (Throwable) {
                $shellAvailable = false;
                $commands = [];
                $probeObservations = $this->unknownExtendedProbeObservations();
                $environment = [
                    'distribution_id' => null, 'version_id' => null,
                    'panel_markers' => array_fill_keys(array_keys(DevPilotEnvironmentProbe::PANEL_MARKERS), 'unknown'),
                    'sudo_noninteractive' => 'unknown',
                    'sudo_probe_enabled' => ! empty($this->connection['probe_sudo']),
                    'tool_detection_complete' => false, 'wp_cli_phar' => 'unknown', 'wp_cli_cache_purge_commands' => [], 'extended_probe_complete' => false,
                    'disk_space' => ['available_kb' => null, 'use_percent' => null],
                ];
            } finally {
                if (isset($ssh) && $ssh instanceof SSH2) $ssh->disconnect();
            }
        }

        return [
            'connected' => true,
            'protocol' => $this->connection['protocol'],
            'root_path' => $this->root,
            'server_fingerprint_sha256' => $this->connection['host_key_sha256'],
            'capabilities' => [
                'list_files' => true, 'read_files' => true,
                'write_files_configured' => (bool) ($this->connection['can_write'] ?? false),
                'delete_files_configured' => (bool) ($this->connection['can_delete'] ?? false),
                'command_execution_mode' => $executionMode,
                'shell' => $probeCommands ? $shellAvailable : null, 'shell_configured' => $shellConfigured,
                'shell_family' => $probeCommands && $shellAvailable ? 'posix' : null,
                'execution_user' => is_string($executionUser) && $executionUser !== '' ? mb_strcut($executionUser, 0, 128) : null,
                'operating_system' => is_string($operatingSystem) && $operatingSystem !== '' ? mb_strcut($operatingSystem, 0, 128) : null,
                'commands_detected' => $commands,
                'environment' => $environment,
                'shell_probe_skipped' => ! $probeCommands && ($this->connection['protocol'] ?? '') === 'ssh',
                'database' => false,
            ],
            'root_entries' => count($listing),
            'probe_observations' => $this->task ? $probeObservations : [],
            'restrictions' => ! $probeCommands || ($this->connection['protocol'] ?? '') !== 'ssh' ? []
                : (! $shellConfigured ? ['L’exécution de commandes est désactivée pour cette connexion.']
                    : ($shellAvailable ? [] : ['La connexion SSH est authentifiée, mais la sonde POSIX non destructive n’a pas confirmé un shell exécutable.'])),
        ];
    }

    public function refreshDetectedTools(bool $timeoutAvailable = false): array
    {
        try {
            $ssh = $this->shellClient();
            $totalOutputBytes = 0;
            $deadline = microtime(true) + DevPilotEnvironmentProbe::MAX_PROBE_SECONDS;
            $result = $this->executeBoundedProbe($ssh, $this->probeCommand(self::TOOL_SCAN_PROBE, $timeoutAvailable), $deadline, $totalOutputBytes);
            $parsed = DevPilotEnvironmentProbe::parseToolOutput($result['output'], $result['complete']);
            return $parsed;
        } catch (Throwable) {
            $parsed = DevPilotEnvironmentProbe::parseToolOutput('', false);
            return $parsed;
        } finally {
            if (isset($ssh) && $ssh instanceof SSH2) $ssh->disconnect();
        }
    }

    /** Resolve the remote account's home directory with a fixed, non-mutating probe. */
    public function userHomeDirectory(): string
    {
        if (($this->connection['protocol'] ?? '') !== 'ssh') throw new RuntimeException('A remote home directory requires SSH.');
        $ssh = $this->shellClient();
        try {
            $ssh->setTimeout(5);
            $home = $ssh->exec('printf \'%s\\n\' "$HOME"');
            if (! is_string($home) || $ssh->getExitStatus() !== 0 || strlen($home) > 1024) {
                throw new RuntimeException('The remote user home directory could not be resolved safely.');
            }
            $home = rtrim(trim($home), '/');
            if (! str_starts_with($home, '/') || $home === '' || preg_match('/[\x00-\x1F\x7F]/', $home) === 1
                || in_array('..', explode('/', $home), true)) {
                throw new RuntimeException('The remote user home directory has an invalid path.');
            }

            return $home;
        } finally {
            $ssh->disconnect();
        }
    }

    /** Return the user's non-symlink wp-cli.phar path without reading its contents. */
    public function wpCliPharPath(): ?string
    {
        $path = $this->userHomeDirectory().'/wp-cli.phar';
        $sftp = $this->client();
        if ($sftp->is_link($path) || ! $sftp->file_exists($path) || $sftp->is_dir($path)) return null;
        $stat = $sftp->stat($path);
        if (! is_array($stat) || (int) ($stat['size'] ?? 0) <= 0 || (int) ($stat['size'] ?? PHP_INT_MAX) > 104857600) return null;

        return $path;
    }

    /** Bounded metadata check used to confirm a private WP-CLI database export. */
    public function remoteFileMetadata(string $absolutePath): array
    {
        if (! str_starts_with($absolutePath, '/') || strlen($absolutePath) > 2048
            || preg_match('/[\x00-\x1F\x7F]/', $absolutePath) === 1 || in_array('..', explode('/', $absolutePath), true)) {
            throw new RuntimeException('The remote file path is invalid.');
        }
        $sftp = $this->client();
        if ($sftp->is_link($absolutePath)) return ['exists' => false, 'size' => null];
        $stat = $sftp->stat($absolutePath);
        return is_array($stat) && ! $sftp->is_dir($absolutePath)
            ? ['exists' => true, 'size' => max(0, (int) ($stat['size'] ?? 0))]
            : ['exists' => false, 'size' => null];
    }

    public function projectRootDirectory(): string
    {
        return $this->root;
    }

    private function probeWordPressCliCommands(SSH2 $ssh, array $tools, bool $timeoutAvailable, float $deadline, int &$totalOutputBytes): array
    {
        $unknown = DevPilotEnvironmentProbe::parseWordPressCliCommandDump('', false);
        if (($tools['complete'] ?? false) !== true) return $unknown;
        if (in_array('wp', $tools['commands_detected'] ?? [], true)) {
            $baseArguments = ['wp'];
        } elseif (in_array('php', $tools['commands_detected'] ?? [], true) && ($tools['wp_cli_phar'] ?? null) === 'present') {
            $homeProbe = $this->executeBoundedProbe($ssh, $this->probeCommand('printf \'%s\\n\' "$HOME"', $timeoutAvailable), $deadline, $totalOutputBytes);
            $home = is_string($homeProbe['output'] ?? null) ? trim($homeProbe['output']) : '';
            if (! ($homeProbe['complete'] ?? false) || ! str_starts_with($home, '/') || strlen($home) > 1024
                || preg_match('/[\x00-\x1F\x7F]/', $home) === 1 || in_array('..', explode('/', $home), true)) return $unknown;
            $baseArguments = ['php', rtrim($home, '/').'/wp-cli.phar'];
        } else {
            return $unknown;
        }

        $argv = [...$baseArguments, 'cli', 'cmd-dump'];
        try {
            $command = DevPilotCommandBuilder::forInteractivePty($this->root, $argv);
        } catch (Throwable) {
            return $unknown;
        }
        $result = $this->executeBoundedProbe($ssh, $command, $deadline, $totalOutputBytes);
        return DevPilotEnvironmentProbe::parseWordPressCliCommandDump($result['output'], $result['complete']);
    }

    private function probeCommand(string $script, bool $timeoutAvailable): string
    {
        $inner = 'sh -c '.self::quoteProbeString($script).' 2>&1';
        return $timeoutAvailable ? 'timeout 5s '.$inner : $inner;
    }

    /** @return array{output:string,complete:bool,timed_out:bool} */
    private function executeBoundedProbe(SSH2 $ssh, string $command, float $globalDeadline, int &$totalOutputBytes): array
    {
        $startedAt = microtime(true);
        $probeDeadline = min($globalDeadline, $startedAt + DevPilotEnvironmentProbe::MAX_PROBE_SECONDS);
        if ($probeDeadline <= $startedAt || $totalOutputBytes >= DevPilotEnvironmentProbe::MAX_OUTPUT_BYTES) {
            return ['output' => '', 'complete' => false, 'timed_out' => true];
        }

        $ssh->setTimeout(max(1, min(DevPilotEnvironmentProbe::MAX_PROBE_SECONDS, (int) ceil($probeDeadline - $startedAt))));
        $output = '';
        $timedOut = false;
        $outputLimited = false;
        try {
            $result = $ssh->exec($command, function (string $chunk) use (&$output, &$totalOutputBytes, &$timedOut, &$outputLimited, $probeDeadline): bool {
                if (microtime(true) >= $probeDeadline) {
                    $timedOut = true;
                    return true;
                }
                $remaining = DevPilotEnvironmentProbe::MAX_OUTPUT_BYTES - $totalOutputBytes - strlen($output);
                if ($remaining <= 0) {
                    $outputLimited = true;
                    return true;
                }
                $output .= substr($chunk, 0, $remaining);
                if (strlen($chunk) > $remaining) {
                    $outputLimited = true;
                    return true;
                }
                return false;
            });
            $timedOut = $timedOut || $ssh->isTimeout() || microtime(true) >= $probeDeadline;
            $complete = $result === true && ! $timedOut && ! $outputLimited;
        } catch (Throwable) {
            $complete = false;
            $timedOut = microtime(true) >= $probeDeadline;
        }
        $totalOutputBytes += strlen($output);

        return ['output' => $output, 'complete' => $complete, 'timed_out' => $timedOut];
    }

    private static function quoteProbeString(string $value): string
    {
        return "'".str_replace("'", "'\\''", $value)."'";
    }

    private function unknownExtendedProbeObservations(): array
    {
        return [
            ...DevPilotEnvironmentProbe::parseSystemOutput('', false)['observations'],
            ...DevPilotEnvironmentProbe::parseToolOutput('', false)['observations'],
            ...DevPilotEnvironmentProbe::parsePanelOutput('', false)['observations'],
            DevPilotEnvironmentProbe::parseSudoOutput('', false)['observation'],
            DevPilotEnvironmentProbe::parseDiskOutput('', false)['observation'],
        ];
    }

    public function list(string $path = '.'): array
    {
        $sftp = $this->client();
        $absolute = $this->safeRemotePath($sftp, $path, true);
        return $this->safeList($sftp, $absolute);
    }

    public function read(string $path): string
    {
        $this->checkCancelled();
        $sftp = $this->client();
        $absolute = $this->safeRemotePath($sftp, $path, false);
        $stat = $sftp->stat($absolute);
        if (! is_array($stat) || (int) ($stat['size'] ?? 0) > 262144) {
            throw new RuntimeException('File is missing, unreadable, or larger than the 256 KB limit.');
        }
        $content = $sftp->get($absolute);
        if (! is_string($content) || ! mb_check_encoding($content, 'UTF-8') || str_contains($content, "\0")) {
            throw new RuntimeException('Binary or non-UTF-8 files cannot be sent to the language model.');
        }
        return $content;
    }

    public function write(string $path, string $content, ?string $expectedSha256): array
    {
        $this->checkCancelled();
        if (empty($this->connection['can_write'])) throw new RuntimeException('Write permission is disabled for this connection.');
        if (strlen($content) > 262144 || ! mb_check_encoding($content, 'UTF-8') || str_contains($content, "\0")) {
            throw new RuntimeException('Only UTF-8 text files up to 256 KB can be written.');
        }
        $sftp = $this->client();
        $absolute = $this->safeRemotePath($sftp, $path, false, true);
        $this->requireDirectory($sftp, dirname($absolute));
        $actual = $sftp->is_file($absolute) ? $this->sha256($sftp, $absolute) : null;
        if ($actual !== $expectedSha256) throw new RuntimeException('File changed since the task read it; the patch was not applied.');
        if (! $sftp->put($absolute, $content, SFTP::SOURCE_STRING)) throw new RuntimeException('Remote write failed.');
        $savedHash = $this->sha256($sftp, $absolute);
        if (! hash_equals(hash('sha256', $content), $savedHash)) throw new RuntimeException('Remote write integrity check failed.');
        return ['path' => $path, 'sha256' => $savedHash, 'bytes' => strlen($content), 'verified' => true];
    }

    public function delete(string $path, ?string $expectedSha256): array
    {
        $this->checkCancelled();
        if (empty($this->connection['can_delete'])) throw new RuntimeException('Delete permission is disabled for this connection.');
        $sftp = $this->client();
        $absolute = $this->safeRemotePath($sftp, $path, false);
        if (! $sftp->is_file($absolute) || $this->sha256($sftp, $absolute) !== $expectedSha256) {
            throw new RuntimeException('File is missing or changed since it was inspected; it was not deleted.');
        }
        if (! $sftp->delete($absolute, false)) throw new RuntimeException('Remote delete failed.');
        return ['path' => $path, 'deleted' => ! $sftp->file_exists($absolute), 'verified' => ! $sftp->file_exists($absolute)];
    }

    public function rename(string $path, string $newPath, ?string $expectedSha256): array
    {
        $this->checkCancelled();
        if (empty($this->connection['can_write'])) throw new RuntimeException('Write permission is disabled for this connection.');
        $sftp = $this->client();
        $source = $this->safeRemotePath($sftp, $path, false);
        $target = $this->safeRemotePath($sftp, $newPath, false, true);
        $this->requireDirectory($sftp, dirname($target));
        if (! $sftp->is_file($source) || $this->sha256($sftp, $source) !== $expectedSha256 || $sftp->file_exists($target)) {
            throw new RuntimeException('Rename source changed or the destination already exists.');
        }
        if (! $sftp->rename($source, $target) || ! $sftp->is_file($target)) throw new RuntimeException('Remote rename failed.');
        return ['path' => $path, 'new_path' => $newPath, 'sha256' => $this->sha256($sftp, $target), 'verified' => true];
    }

    public function mkdir(string $path): array
    {
        $this->checkCancelled();
        if (empty($this->connection['can_write'])) throw new RuntimeException('Write permission is disabled for this connection.');
        $sftp = $this->client();
        $absolute = $this->safeRemotePath($sftp, $path, false, true);
        $this->requireDirectory($sftp, dirname($absolute));
        if ($sftp->file_exists($absolute)) throw new RuntimeException('Destination already exists.');
        if (! $sftp->mkdir($absolute, -1, false) || ! $sftp->is_dir($absolute)) throw new RuntimeException('Remote directory creation failed.');
        return ['path' => $path, 'created' => true, 'verified' => true];
    }

    public function search(string $query, string $path = '.'): array
    {
        $query = trim($query);
        if ($query === '' || mb_strlen($query) > 160) throw new RuntimeException('Search query must contain 1 to 160 characters.');
        $sftp = $this->client();
        $base = $this->safeRemotePath($sftp, $path, true);
        $queue = [[$base, 0]];
        $matches = [];
        $entries = 0;
        while ($queue && $entries < 500) {
            $this->checkCancelled();
            [$directory, $depth] = array_shift($queue);
            foreach ($this->safeList($sftp, $directory) as $entry) {
                if (++$entries > 500) break;
                if ($entry['type'] === 'directory' && $depth < 5) $queue[] = [$directory.'/'.$entry['name'], $depth + 1];
                if (stripos($entry['name'], $query) !== false) {
                    $relativeDirectory = ltrim(substr($directory, strlen($this->root)), '/');
                    $matches[] = ['path' => $relativeDirectory === '' ? $entry['name'] : $relativeDirectory.'/'.$entry['name'], 'type' => $entry['type']];
                }
                if (count($matches) >= 100) break 2;
            }
        }
        return ['matches' => $matches, 'entries_scanned' => $entries, 'limited' => $entries >= 500 || count($matches) >= 100];
    }

    public function runAllowedCommand(string $command, string $workingDirectory = '.', ?callable $heartbeat = null, bool $humanApproved = false): array
    {
        $executionMode = DevPilotCommandPolicy::executionMode($this->connection['command_execution_mode'] ?? null, (bool) ($this->connection['can_run_commands'] ?? false));
        $authorization = DevPilotCommandPolicy::decide($command, $executionMode, $this->connection['allowed_commands'] ?? []);
        $arguments = preg_split('/\s+/', trim($command), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return $this->executeAuthorizedArguments($command, $arguments, $workingDirectory, $heartbeat, $humanApproved, $authorization);
    }

    public function runAllowedArgv(array $arguments, string $workingDirectory = '.', ?callable $heartbeat = null, bool $humanApproved = false, bool $privateUmask = false): array
    {
        $executionMode = DevPilotCommandPolicy::executionMode($this->connection['command_execution_mode'] ?? null, (bool) ($this->connection['can_run_commands'] ?? false));
        $authorization = DevPilotCommandPolicy::decideWordPressCliArgv($arguments, $executionMode, $this->connection['allowed_commands'] ?? []);

        return $this->executeAuthorizedArguments(
            DevPilotCommandBuilder::displayArgv($arguments), $arguments, $workingDirectory, $heartbeat, $humanApproved, $authorization, $privateUmask,
        );
    }

    private function executeAuthorizedArguments(
        string $command,
        array $commandArguments,
        string $workingDirectory,
        ?callable $heartbeat,
        bool $humanApproved,
        array $authorization,
        bool $privateUmask = false,
    ): array {
        $this->checkCancelled();
        if (($this->connection['protocol'] ?? '') !== 'ssh'
            || DevPilotCommandPolicy::executionMode($this->connection['command_execution_mode'] ?? null, (bool) ($this->connection['can_run_commands'] ?? false)) === 'disabled') {
            throw new RuntimeException('Remote command execution is disabled or unavailable for this protocol.');
        }
        if (($authorization['decision'] ?? 'blocked') === 'blocked') throw new RuntimeException((string) ($authorization['reason'] ?? 'The command is blocked by policy.'));
        if (($authorization['decision'] ?? null) === 'approval' && ! $humanApproved) {
            throw new RuntimeException('This command requires a human-approved task proposal before remote execution.');
        }

        $sftp = $this->client();
        $absoluteWorkingDirectory = $this->safeRemotePath($sftp, $workingDirectory, true);
        if (! $sftp->is_dir($absoluteWorkingDirectory)) throw new RuntimeException('The requested command working directory is not an accessible project directory.');

        $outputLimit = max(1024, min(262144, (int) config('devpilot.command_output_limit_bytes', 65536)));
        $ssh = $this->shellClient($outputLimit);
        $ssh->setTimeout(8);
        $shellProbe = $ssh->exec('printf ELCHAT_DEVPILOT_SHELL_OK');
        if ($shellProbe !== 'ELCHAT_DEVPILOT_SHELL_OK') {
            $ssh->disconnect();
            throw new RuntimeException('The remote command shell could not be positively identified as POSIX; no configured command was run.');
        }
        $commandInProject = DevPilotCommandBuilder::forInteractivePty($absoluteWorkingDirectory, $commandArguments, $privateUmask);
        $outputBuffer = new DevPilotCommandOutputBuffer($outputLimit);
        $cancelled = false;
        $uncertain = false;
        $timedOut = false;
        $timeoutReason = null;
        $terminalInputs = [];
        $lastHeartbeatAt = microtime(true);
        $lastOutputPersistAt = 0.0;
        $session = null;
        $channel = null;
        $executionError = null;
        $commandMaximumSeconds = max(1, (int) config('devpilot.command_max_seconds', 1800));
        $commandIdleSeconds = max(1, (int) config('devpilot.command_idle_seconds', 600));
        $commandStartedAt = microtime(true);
        $deadline = new DevPilotCommandDeadline($commandStartedAt, $commandMaximumSeconds, $commandIdleSeconds);

        try {
            // A PTY keeps stdin attached to this approved process. Reads use a one-second
            // polling tick so heartbeats, cancellation, input, and both deadlines remain responsive.
            $ssh->enablePTY();
            if ($ssh->exec($commandInProject) !== true) {
                throw new RuntimeException('The remote terminal session could not be started.');
            }
            $channel = $ssh->getInteractiveChannelId();
            if (! $ssh->isPTYOpen() || $channel === 0) {
                throw new RuntimeException('The remote server did not keep the interactive SSH terminal open.');
            }
            if ($this->task) {
                $session = DevPilotTerminalSession::query()->create([
                    'site_id' => $this->task->site_id,
                    'project_id' => $this->task->project_id,
                    'task_id' => $this->task->id,
                    'status' => 'running',
                ]);
                $this->publishTerminalEvent(
                    'terminal_session_started', 'Session de terminal SSH interactive ouverte.',
                    ['terminal_session_id' => $session->id, 'terminal_state' => 'running'],
                );
            }

            $ssh->setKeepAlive(15);
            $ssh->setTimeout(1);
            while (true) {
                try {
                    $this->checkCancelled();
                } catch (Throwable) {
                    $cancelled = true;
                    break;
                }
                if ($deadline->totalExpired(microtime(true))) {
                    $timeoutReason = 'total';
                    $timedOut = $uncertain = true;
                    break;
                }
                if ($heartbeat !== null && microtime(true) - $lastHeartbeatAt >= 30) {
                    $lastHeartbeatAt = microtime(true);
                    try { $heartbeat(); } catch (Throwable) { /* A transient heartbeat write must not interrupt the approved command. */ }
                }

                if ($session) {
                    $input = $this->consumeTerminalInput($session->id);
                    if ($input !== null) {
                        $terminalInputs[] = $input;
                        $deadline->recordActivity(microtime(true));
                        $ssh->write($input."\n", $channel);
                        $this->publishTerminalEvent(
                            'terminal_input_sent', 'Réponse transmise au terminal distant.',
                            ['terminal_session_id' => $session->id, 'terminal_state' => 'running'],
                        );
                    }
                }

                $timeoutReason = $deadline->expiredReason(microtime(true));
                if ($timeoutReason !== null) {
                    $timedOut = $uncertain = true;
                    break;
                }

                try {
                    $chunk = $ssh->read('', SSH2::READ_NEXT, $channel);
                } catch (Throwable $exception) {
                    if ($ssh->isTimeout()) continue;
                    $executionError = $exception;
                    $uncertain = true;
                    break;
                }

                if ($chunk === true) {
                    if ($ssh->isTimeout()) continue;
                    break; // The remote process closed its channel normally.
                }
                if ($chunk === false) {
                    $uncertain = true;
                    break;
                }
                if (! is_string($chunk) || $chunk === '') continue;

                $deadline->recordActivity(microtime(true));
                $outputBuffer->append(
                    $chunk,
                    fn (string $candidate): string => $this->sanitizeTerminalOutput($candidate, $terminalInputs),
                );
                if ($session && microtime(true) - $lastOutputPersistAt >= 1.0) {
                    $lastOutputPersistAt = microtime(true);
                    $this->persistTerminalOutput($session, $outputBuffer->output(), $terminalInputs, $outputLimit);
                    $this->publishTerminalEvent(
                        'terminal_output', 'Sortie du terminal mise à jour.',
                        ['terminal_session_id' => $session->id, 'terminal_state' => 'running'],
                    );
                }
            }
        } catch (Throwable $exception) {
            $executionError = $exception;
            $uncertain = true;
        } finally {
            if ($timedOut && $channel !== null) {
                try {
                    $ssh->write("\x03", $channel);
                } catch (Throwable $exception) {
                    $executionError ??= $exception;
                }
                try {
                    $ssh->reset($channel);
                } catch (Throwable $exception) {
                    $executionError ??= $exception;
                }
            }
            $exitCode = $timedOut ? null : $ssh->getExitStatus();
            $terminalState = $cancelled ? 'cancelled' : ($uncertain || ! is_int($exitCode)
                ? 'uncertain' : ($exitCode === 0 ? 'completed' : 'failed'));
            if ($session) {
                try {
                    $this->persistTerminalOutput($session, $outputBuffer->output(), $terminalInputs, $outputLimit, $terminalState);
                } catch (Throwable $exception) {
                    $uncertain = true;
                    $executionError ??= $exception;
                }
                $message = $cancelled ? 'Session de terminal annulée.' : ($timedOut
                    ? 'La commande a atteint son délai maximal; la session a été interrompue et son résultat est incertain.'
                    : ($uncertain ? 'Session de terminal interrompue; son résultat est incertain.' : ($exitCode === 0 ? 'Session de terminal terminée.' : 'La commande du terminal s’est terminée avec une erreur.')));
                $this->publishTerminalEvent(
                    'terminal_session_finished', $message,
                    ['terminal_session_id' => $session->id, 'terminal_state' => $uncertain ? 'uncertain' : $terminalState, 'timed_out' => $timedOut, 'timeout_reason' => $timeoutReason],
                );
            }
        }
        $ssh->disconnect();

        $safeOutput = $this->sanitizeTerminalOutput($outputBuffer->output(), $terminalInputs);
        $redactedOutputExceededLimit = strlen($safeOutput) > $outputLimit;
        if ($redactedOutputExceededLimit) {
            $bounded = new DevPilotCommandOutputBuffer($outputLimit);
            $bounded->append($safeOutput, static fn (string $candidate): string => $candidate);
            $safeOutput = $bounded->output();
        }
        $outputTruncated = $outputBuffer->isTruncated() || $redactedOutputExceededLimit;
        if (str_contains($safeOutput, DevPilotCommandOutputBuffer::TRUNCATION_MARKER)) $outputTruncated = true;
        $timeoutMessage = $timeoutReason === 'idle'
            ? 'La commande a dépassé le délai sans sortie ni saisie utilisateur; la session a été interrompue et son résultat est incertain.'
            : ($timeoutReason === 'total'
                ? 'La commande a dépassé sa durée maximale; la session a été interrompue et son résultat est incertain.'
                : null);
        $state = $cancelled ? 'uncertain' : ($uncertain || ! is_int($exitCode)
            ? 'uncertain' : ($exitCode === 0 ? 'completed' : 'failed'));

        return [
            'command' => $command, 'working_directory' => $workingDirectory,
            'exit_code' => is_int($exitCode) && ! $uncertain ? $exitCode : null,
            'stdout' => $safeOutput, 'stderr' => '', 'output_streams_merged' => true,
            'timed_out' => $timedOut, 'timeout_reason' => $timeoutReason, 'cancelled' => $cancelled,
            'output_truncated' => $outputTruncated,
            'execution_state' => $state, 'stdin_mode' => 'interactive',
            'message' => $timeoutMessage ?? ($executionError ? mb_strcut(app(DevPilotSecretRedactor::class)->redactKnownSecrets($executionError->getMessage(), [$this->secrets]), 0, 1000) : null),
        ];
    }

    /** Atomically consumes one encrypted line without ever putting it in task messages or logs. */
    private function consumeTerminalInput(string $sessionId): ?string
    {
        return DB::transaction(function () use ($sessionId): ?string {
            $session = DevPilotTerminalSession::query()->lockForUpdate()->find($sessionId);
            if (! $session || $session->status !== 'running' || ! $session->pending_input_encrypted
                || $session->input_sequence <= $session->consumed_input_sequence) return null;

            try {
                $input = Crypt::decryptString($session->pending_input_encrypted);
            } catch (Throwable) {
                $session->forceFill(['status' => 'uncertain', 'pending_input_encrypted' => null])->save();
                throw new RuntimeException('Terminal input could not be decrypted; the command was stopped safely.');
            }
            $session->forceFill([
                'consumed_input_sequence' => $session->input_sequence,
                'pending_input_encrypted' => null,
            ])->save();

            return $input;
        }, 3);
    }

    private function persistTerminalOutput(DevPilotTerminalSession $session, string $output, array $terminalInputs, int $outputLimit, ?string $state = null): void
    {
        $safe = $this->sanitizeTerminalOutput($output, $terminalInputs);
        if (strlen($safe) > $outputLimit) {
            $bounded = new DevPilotCommandOutputBuffer($outputLimit);
            $bounded->append($safe, static fn (string $candidate): string => $candidate);
            $safe = $bounded->output();
        }
        $encryptedOutput = Crypt::encryptString($safe);
        DB::transaction(function () use ($session, $encryptedOutput, $state): void {
            $current = DevPilotTerminalSession::query()->lockForUpdate()->find($session->id);
            if (! $current || $current->status !== 'running') return;
            $values = ['output_encrypted' => $encryptedOutput, 'updated_at' => now()];
            if ($state !== null) {
                $values['status'] = $state;
                $values['pending_input_encrypted'] = null;
            }
            $current->forceFill($values)->save();
        }, 3);
        $session->forceFill(['status' => $state ?? 'running', 'output_encrypted' => $encryptedOutput]);
    }

    private function sanitizeTerminalOutput(string $output, array $terminalInputs): string
    {
        $output = preg_replace('/\x1B(?:\[[0-?]*[ -\/]*[@-~]|\][^\x07]*(?:\x07|\x1B\\\\)|[@-_])/', '', $output) ?? $output;
        $output = str_replace(["\r\n", "\r"], "\n", $output);
        foreach ($terminalInputs as $input) {
            if (! is_string($input) || $input === '') continue;
            $echoPosition = strrpos($output, $input);
            if ($echoPosition !== false) $output = substr_replace($output, '[RÉPONSE MASQUÉE]', $echoPosition, strlen($input));
        }

        return app(DevPilotSecretRedactor::class)->redactKnownSecrets($output, [$this->secrets]);
    }

    /** Live updates are best-effort; a Mercure outage must not abort an approved SSH process. */
    private function publishTerminalEvent(string $kind, string $message, array $details): void
    {
        if (! $this->task) return;
        try {
            app(DevPilotRealtimeService::class)->publish($this->task, $kind, $message, $details);
        } catch (Throwable) {
            // The authorized SSH command and encrypted session state remain authoritative.
        }
    }

    private function client(): SFTP
    {
        if ($this->sftp) return $this->sftp;
        $host = DevPilotHostResolver::resolve((string) ($this->connection['host'] ?? ''), (bool) ($this->connection['private_network_authorized'] ?? false));
        $port = (int) ($this->connection['port'] ?? 22);
        $sftp = new SFTP($host, $port, 8);
        $actual = self::fingerprint($sftp->getServerPublicHostKey() ?: '');
        $expected = (string) ($this->connection['host_key_sha256'] ?? '');
        if ($expected === '' || ! hash_equals($expected, $actual)) throw new RuntimeException('SSH server fingerprint changed or has not been confirmed.');
        if (! $this->authenticate($sftp)) throw new RuntimeException('SSH/SFTP authentication failed.');
        $sftp->setTimeout(8);
        $remoteRoot = $sftp->realpath((string) $this->connection['root_path']);
        if (! is_string($remoteRoot) || $remoteRoot !== rtrim((string) $this->connection['root_path'], '/')) {
            throw new RuntimeException('The authorized root is unavailable or resolves through a link.');
        }
        $projectRoot = $sftp->realpath($this->root);
        if (! is_string($projectRoot) || $projectRoot !== $this->root || ! $sftp->is_dir($projectRoot)) {
            throw new RuntimeException('The project root is unavailable or resolves through a link.');
        }
        $this->sftp = $sftp;
        return $sftp;
    }

    private function shellClient(?int $boundedStderrBytes = null): SSH2
    {
        $host = DevPilotHostResolver::resolve((string) $this->connection['host'], (bool) ($this->connection['private_network_authorized'] ?? false));
        $ssh = $boundedStderrBytes === null
            ? new SSH2($host, (int) ($this->connection['port'] ?? 22), 8)
            : new BoundedSsh2($host, (int) ($this->connection['port'] ?? 22), 8, $boundedStderrBytes);
        $actual = self::fingerprint($ssh->getServerPublicHostKey() ?: '');
        if (! hash_equals((string) ($this->connection['host_key_sha256'] ?? ''), $actual)) throw new RuntimeException('SSH server fingerprint changed.');
        if (! $this->authenticate($ssh)) throw new RuntimeException('SSH authentication failed.');
        $ssh->setTimeout(8);
        return $ssh;
    }

    private function authenticate(SFTP|SSH2 $client): bool
    {
        $username = (string) ($this->connection['username'] ?? '');
        if (($this->connection['auth_method'] ?? '') === 'private_key') {
            $key = PublicKeyLoader::load((string) ($this->secrets['private_key'] ?? ''), ($this->secrets['private_key_passphrase'] ?? '') ?: null);
            return $client->login($username, $key);
        }
        return $client->login($username, (string) ($this->secrets['password'] ?? ''));
    }

    private function safeRemotePath(SFTP $sftp, string $relative, bool $allowDot, bool $allowMissing = false): string
    {
        $relative = str_replace('\\', '/', trim($relative));
        if (str_starts_with($relative, '/') || str_contains($relative, "\0") || preg_match('/^[A-Za-z]:/', $relative)) throw new RuntimeException('Absolute or malformed paths are not allowed.');
        $segments = $relative === '' || $relative === '.' ? [] : explode('/', $relative);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || preg_match('/[\x00-\x1F]/', $segment)) throw new RuntimeException('Path traversal or malformed path detected.');
            if (self::isSensitiveName($segment)) throw new RuntimeException('Access to credential and system files is blocked.');
        }
        if (! $allowDot && $segments === []) throw new RuntimeException('A file path is required.');
        $absolute = rtrim($this->root, '/').($segments ? '/'.implode('/', $segments) : '');
        $current = rtrim($this->root, '/');
        foreach ($segments as $index => $segment) {
            $current .= '/'.$segment;
            if ($sftp->is_link($current)) throw new RuntimeException('Symbolic links are not followed inside the project root.');
            if (! $sftp->file_exists($current) && ! ($allowMissing && $index === array_key_last($segments))) throw new RuntimeException('Remote path does not exist.');
        }
        return $absolute;
    }

    private function safeList(SFTP $sftp, string $absolute): array
    {
        $names = $sftp->nlist($absolute);
        if (! is_array($names)) throw new RuntimeException('Remote directory listing failed.');
        $result = [];
        foreach ($names as $name) {
            if (! is_string($name) || in_array($name, ['.', '..'], true) || str_contains($name, '/') || self::isSensitiveName($name)) continue;
            $path = rtrim($absolute, '/').'/'.$name;
            if ($sftp->is_link($path)) continue;
            $stat = $sftp->stat($path);
            if (! is_array($stat)) continue;
            $result[] = ['name' => $name, 'type' => $sftp->is_dir($path) ? 'directory' : 'file', 'size' => (int) ($stat['size'] ?? 0), 'modified_at' => isset($stat['mtime']) ? gmdate(DATE_ATOM, (int) $stat['mtime']) : null];
        }
        return array_slice($result, 0, 500);
    }

    private function sha256(SFTP $sftp, string $path): string
    {
        $stat = $sftp->stat($path);
        if (! is_array($stat) || (int) ($stat['size'] ?? 262145) > 262144) throw new RuntimeException('Files larger than 256 KB cannot be compared or changed.');
        $content = $sftp->get($path);
        if (! is_string($content)) throw new RuntimeException('Unable to verify remote file content.');
        return hash('sha256', $content);
    }

    private function requireDirectory(SFTP $sftp, string $path): void
    {
        if (! $sftp->is_dir($path)) throw new RuntimeException('The parent directory must already exist.');
        if ($sftp->is_link($path)) throw new RuntimeException('Symbolic link parents are blocked.');
    }

    private function rootPath(string $connectionRoot, string $projectRoot): string
    {
        if (! str_starts_with($connectionRoot, '/') || str_contains($connectionRoot, "\0") || preg_match('#(?:^|/)\.\.(?:/|$)#', $connectionRoot)) throw new RuntimeException('Invalid authorized connection root.');
        $projectRoot = trim(str_replace('\\', '/', $projectRoot), '/');
        if ($projectRoot === '.' || $projectRoot === '') return rtrim($connectionRoot, '/') ?: '/';
        foreach (explode('/', $projectRoot) as $part) if ($part === '' || $part === '.' || $part === '..' || self::isSensitiveName($part)) throw new RuntimeException('Invalid project subdirectory.');
        return rtrim($connectionRoot, '/').'/'.$projectRoot;
    }

    public static function fingerprint(string $publicKey): string
    {
        $parts = preg_split('/\s+/', trim($publicKey)) ?: [];
        $blob = isset($parts[1]) ? base64_decode($parts[1], true) : false;
        return is_string($blob) ? 'SHA256:'.rtrim(base64_encode(hash('sha256', $blob, true)), '=') : '';
    }

    public static function isSensitiveName(string $name): bool
    {
        $name = strtolower($name);
        return $name === '.env' || str_starts_with($name, '.env.')
            || in_array($name, [
                '.ssh', '.aws', '.config', '.htpasswd', '.npmrc', '.netrc', '.pgpass', '.my.cnf', '.git-credentials',
                'wp-config.php', 'id_rsa', 'id_ed25519', 'id_ecdsa', 'id_dsa', 'authorized_keys', 'known_hosts',
                'credentials.json', 'secrets.yml', 'secrets.yaml',
            ], true)
            || str_ends_with($name, '.pem') || str_ends_with($name, '.key') || str_ends_with($name, '.p12') || str_ends_with($name, '.pfx')
            || str_ends_with($name, '.sqlite') || str_ends_with($name, '.sqlite3') || str_ends_with($name, '.kdbx')
            || str_ends_with($name, '.p8') || str_ends_with($name, '.jks') || str_ends_with($name, '.keystore');
    }

    private function checkCancelled(): void
    {
        if ($this->cancellationCheck) ($this->cancellationCheck)();
    }
}
