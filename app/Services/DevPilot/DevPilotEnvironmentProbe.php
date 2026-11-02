<?php

namespace App\Services\DevPilot;

/** Strict parsers and identifiers for bounded, non-mutating remote environment probes. */
final class DevPilotEnvironmentProbe
{
    public const MAX_OUTPUT_BYTES = 16384;
    public const MAX_TOTAL_SECONDS = 20;
    public const MAX_PROBE_SECONDS = 5;

    /** @var list<string> */
    public const TOOL_CANDIDATES = [
        'timeout', 'php', 'composer', 'node', 'npm', 'pnpm', 'yarn', 'python3', 'pip', 'java',
        'mvn', 'gradle', 'dotnet', 'go', 'rustc', 'cargo', 'git', 'wp', 'systemctl', 'php-fpm', 'nginx',
        'apache2', 'httpd',
    ];

    /** @var array<string,string> */
    public const PANEL_MARKERS = [
        'cpanel' => '/usr/local/cpanel',
        'plesk' => '/usr/local/psa',
        'directadmin' => '/usr/local/directadmin',
        'hestia' => '/usr/local/hestia',
        'ispconfig' => '/usr/local/ispconfig',
        'cyberpanel' => '/usr/local/CyberCP',
        'webmin' => '/usr/share/webmin',
        'aapanel' => '/www/server/panel',
    ];

    /** Remote identifiers are never treated as instructions or free-form text. */
    public static function normalizeIdentifier(mixed $value): ?string
    {
        if (! is_string($value)) return null;
        $value = preg_replace('/[\x00-\x1F\x7F]/', '', $value) ?? '';
        $value = strtolower(trim($value));
        if (strlen($value) > 32 || preg_match('/^[a-z0-9._-]{1,32}$/D', $value) !== 1) return null;
        return $value;
    }

    public static function normalizeVersionId(mixed $value): ?string
    {
        if (! is_string($value)) return null;
        $value = preg_replace('/[\x00-\x1F\x7F]/', '', $value) ?? '';
        $value = trim($value, " \t\n\r\0\x0B\"'");
        if (strlen($value) > 32 || preg_match('/^[A-Za-z0-9._+-]{1,32}$/D', $value) !== 1) return null;
        return $value;
    }

    public static function normalizeBinaryIdentifier(mixed $value): ?string
    {
        if (! is_string($value) || strlen($value) > 64) return null;
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9._+-]{0,63}$/D', $value) === 1 ? $value : null;
    }

    public static function matchesTargetFingerprint(?string $cachedFingerprint, string $currentFingerprint): bool
    {
        return is_string($cachedFingerprint)
            && preg_match('/^[a-f0-9]{64}$/i', $cachedFingerprint) === 1
            && preg_match('/^[a-f0-9]{64}$/i', $currentFingerprint) === 1
            && hash_equals(strtolower($currentFingerprint), strtolower($cachedFingerprint));
    }

    public static function shouldRefreshToolsAfterCommand(bool $completed, ?int $exitCode, string $command): bool
    {
        return $completed && $exitCode === 0 && ! DevPilotCommandPolicy::isKnownReadOnlyDiagnosticCommand($command);
    }

    /** Parse only the explicitly named keys; PRETTY_NAME and all other text are ignored. */
    public static function parseSystemOutput(string $output, bool $complete): array
    {
        $values = [];
        if ($complete) {
            foreach (preg_split('/\r?\n/', $output) ?: [] as $line) {
                if (! str_contains($line, '=')) continue;
                [$key, $value] = explode('=', $line, 2);
                if (in_array($key, ['UID', 'USER', 'KERNEL', 'OS_RELEASE', 'ID', 'VERSION_ID'], true)) {
                    $values[$key] = $value;
                }
            }
        }

        $user = self::normalizeIdentifier($values['USER'] ?? null);
        $kernel = self::normalizeIdentifier($values['KERNEL'] ?? null);
        $osReleaseReadable = ($values['OS_RELEASE'] ?? null) === 'readable';
        $idValue = isset($values['ID']) ? trim($values['ID'], " \t\n\r\0\x0B\"'") : null;
        $id = $osReleaseReadable ? self::normalizeIdentifier($idValue) : null;
        $version = $osReleaseReadable ? self::normalizeVersionId($values['VERSION_ID'] ?? null) : null;
        $uid = isset($values['UID']) && preg_match('/^[0-9]{1,10}$/D', $values['UID']) === 1 ? (int) $values['UID'] : null;

        return [
            'execution_user' => $user,
            'operating_system' => $kernel,
            'uid' => $uid,
            'distribution_id' => $id,
            'version_id' => $version,
            'observations' => [
                self::observation('environment:shell', isset($values['KERNEL']) && $kernel !== null ? 'present' : 'unknown', $complete),
                self::observation('environment:distribution-id'.($id !== null ? ':'.$id : ''), self::fieldStatus($complete, $osReleaseReadable, array_key_exists('ID', $values), $id), $complete),
                self::observation('environment:version-id'.($version !== null ? ':'.$version : ''), self::fieldStatus($complete, $osReleaseReadable, array_key_exists('VERSION_ID', $values), $version), $complete),
                self::observation('environment:execution-user'.($user !== null ? ':'.$user : ''), $complete && $user !== null ? 'present' : 'unknown', $complete),
                self::observation('environment:operating-system'.($kernel !== null ? ':'.$kernel : ''), $complete && $kernel !== null ? 'present' : 'unknown', $complete),
            ],
        ];
    }

    public static function parseToolOutput(string $output, bool $complete): array
    {
        $toolsComplete = $complete && str_contains($output, "TOOLS_COMPLETE=1\n");
        $tools = [];
        if ($toolsComplete) {
            foreach (preg_split('/\r?\n/', $output) ?: [] as $line) {
                if (! str_starts_with($line, 'BIN=')) continue;
                $binary = self::normalizeBinaryIdentifier(substr($line, 4));
                if ($binary !== null && in_array($binary, self::TOOL_CANDIDATES, true)) $tools[] = $binary;
            }
        }
        $tools = array_values(array_unique($tools));
        $wpCliPhar = 'unknown';
        if ($toolsComplete && preg_match('/^WP_CLI_PHAR=(present|absent)$/m', $output, $pharMatch) === 1) $wpCliPhar = $pharMatch[1];
        $observations = [];
        foreach (self::TOOL_CANDIDATES as $binary) {
            $observations[] = self::observation(
                'environment:tool:'.$binary,
                ! $toolsComplete ? 'unknown' : (in_array($binary, $tools, true) ? 'present' : 'absent'),
                $toolsComplete,
            );
        }
        $observations[] = self::observation('environment:tool:wp-cli-phar', $wpCliPhar, $toolsComplete && $wpCliPhar !== 'unknown');

        return ['commands_detected' => $tools, 'wp_cli_phar' => $wpCliPhar, 'complete' => $toolsComplete, 'observations' => $observations];
    }

    /** Extract only normalized cache-purge command identifiers from WP-CLI's JSON-lines inventory. */
    public static function parseWordPressCliCommandDump(string $output, bool $complete): array
    {
        $commands = [];
        if ($complete && strlen($output) <= self::MAX_OUTPUT_BYTES) {
            $decoded = json_decode($output, true);
            $entries = is_array($decoded)
                ? (array_is_list($decoded) ? $decoded : [$decoded])
                : array_map(static fn (string $line): mixed => json_decode($line, true), preg_split('/\r?\n/', $output) ?: []);
            foreach ($entries as $entry) {
                if (! is_array($entry)) continue;
                $name = is_string($entry['name'] ?? null) ? trim($entry['name']) : '';
                $description = is_string($entry['description'] ?? null) ? $entry['description'] : '';
                $tokens = preg_split('/\s+/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                if (($tokens[0] ?? null) === 'wp') array_shift($tokens);
                if (count($tokens) < 2 || count($tokens) > 5
                    || array_filter($tokens, static fn (string $token): bool => preg_match('/^[a-z0-9][a-z0-9_-]{0,40}$/D', $token) !== 1) !== []) continue;
                $last = end($tokens);
                if (! in_array($last, ['flush', 'purge', 'clear', 'clean', 'invalidate'], true)) continue;
                if (! preg_match('/\bcache(?:d)?\b/i', $name.' '.$description)) continue;
                if (in_array($tokens[0], ['cli', 'db', 'plugin', 'theme', 'user', 'post', 'option', 'core'], true)) continue;
                $commands[implode(' ', $tokens)] = true;
                if (count($commands) >= 32) break;
            }
        }
        $commands = array_keys($commands);
        sort($commands, SORT_STRING);
        $observations = [self::observation('environment:wp-cli-cache-command-inventory', $complete ? 'present' : 'unknown', $complete)];
        foreach (array_slice($commands, 0, 16) as $command) {
            $observations[] = self::observation('environment:wp-cli-cache-command:'.$command, 'present', $complete);
        }

        return ['cache_purge_commands' => $commands, 'complete' => $complete, 'observations' => $observations];
    }

    public static function parsePanelOutput(string $output, bool $complete): array
    {
        $statuses = [];
        if ($complete) {
            foreach (preg_split('/\r?\n/', $output) ?: [] as $line) {
                if (! preg_match('/^PANEL_([a-z0-9._-]{1,32})=(present|absent|unknown)$/D', $line, $matches)) continue;
                $panel = $matches[1];
                if (array_key_exists($panel, self::PANEL_MARKERS)) $statuses[$panel] = $matches[2];
            }
        }
        $panels = [];
        $observations = [];
        foreach (self::PANEL_MARKERS as $panel => $_path) {
            $status = $statuses[$panel] ?? 'unknown';
            $panels[$panel] = $status;
            $observations[] = self::observation('environment:panel:'.$panel, $status, $complete && isset($statuses[$panel]));
        }

        return ['panels' => $panels, 'observations' => $observations];
    }

    public static function parseSudoOutput(string $output, bool $complete): array
    {
        $status = 'unknown';
        if ($complete && preg_match('/^SUDO=(present|absent|unknown)$/m', $output, $matches)) $status = $matches[1];
        return [
            'status' => $status,
            'observation' => self::observation('environment:sudo-noninteractive', $status, $complete),
        ];
    }

    public static function parseDiskOutput(string $output, bool $complete): array
    {
        $availableKb = null;
        $usePercent = null;
        if ($complete) {
            foreach (preg_split('/\r?\n/', $output) ?: [] as $line) {
                if (preg_match('/^.*\s([0-9]{1,12})\s+[0-9]{1,12}\s+([0-9]{1,12})\s+([0-9]{1,3})%\s+.+$/D', trim($line), $matches) !== 1) continue;
                $availableKb = min(1000000000000, (int) $matches[2]);
                $candidatePercent = (int) $matches[3];
                if ($candidatePercent <= 100) $usePercent = $candidatePercent;
                if ($usePercent !== null) break;
                $availableKb = null;
            }
        }
        $known = $availableKb !== null && $usePercent !== null;
        return [
            'available_kb' => $known ? $availableKb : null,
            'use_percent' => $known ? $usePercent : null,
            'observation' => self::observation('environment:disk-space', $known ? 'present' : 'unknown', $complete),
        ];
    }

    /** @return array{kind:string,target:string,mutating:false,facts:array{status:string,complete:bool}} */
    public static function observation(string $target, string $status, bool $complete): array
    {
        if (! in_array($status, ['present', 'absent', 'unknown'], true)) $status = 'unknown';
        return [
            'kind' => 'environment_probe', 'target' => $target, 'mutating' => false,
            'facts' => ['status' => $status, 'complete' => $complete],
        ];
    }

    private static function fieldStatus(bool $complete, bool $readable, bool $found, ?string $value): string
    {
        if (! $complete || ! $readable || ($found && $value === null)) return 'unknown';
        return $found ? 'present' : 'absent';
    }
}
