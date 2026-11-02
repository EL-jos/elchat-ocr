<?php

namespace App\Services\DevPilot;

/**
 * Applies hard command safety constraints before strings reach the remote shell.
 * The connection's execution mode separately decides whether exact allowlisting
 * and/or per-command human approval is required.
 */
final class DevPilotCommandPolicy
{
    private const EXECUTION_MODES = ['unrestricted', 'approval', 'allowlist', 'disabled'];

    private const SAFE_CHARACTERS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_./:=+-@^~% ';

    private const BLOCKED_TOKENS = [
        'sudo', 'su', 'rm', 'rmdir',
        'mkfs', 'dd', 'curl', 'wget', 'bash', 'sh', 'zsh', 'eval', 'exec', 'source',
        'nc', 'netcat', 'scp', 'sftp', 'ssh', 'passwd', 'env', 'printenv',
    ];

    private const SERVER_ADMIN_TOKENS = [
        'systemctl', 'service', 'apt', 'apt-get', 'yum', 'dnf', 'apk', 'ufw', 'iptables',
        'docker', 'podman', 'kubectl', 'mount', 'umount', 'visudo', 'userdel', 'groupdel',
        'mysql', 'mariadb', 'psql', 'createdb', 'dropdb', 'migrate', 'deploy', 'restart',
    ];

    private const PROJECT_CHANGE_TOKENS = [
        'npm', 'npx', 'pnpm', 'yarn', 'composer', 'pip', 'pip3', 'git', 'make', 'cargo',
        'go', 'dotnet', 'mvn', 'gradle', 'php', 'python', 'python3', 'node',
    ];

    private const SENSITIVE_TOKENS = [
        'reset', 'clean', 'delete', 'drop', 'truncate', 'format', 'remove', 'uninstall',
        'restore', 'erase', 'destroy', 'purge', 'wipe', 'prune', 'force', 'chmod', 'chown',
        'kill', 'reboot', 'shutdown', 'restart', 'crontab', 'useradd', 'groupadd', 'passwd',
        'userdel', 'groupdel', 'visudo',
    ];

    private const DIAGNOSTIC_TOKENS = [
        'pwd', 'ls', 'dir', 'cat', 'head', 'tail', 'grep', 'find', 'file', 'stat', 'du', 'df',
        'id', 'whoami', 'uname', 'which', 'whereis', 'printenv', 'env', 'date', 'uptime',
        'ps', 'top', 'free', 'lscpu', 'php', 'composer', 'node', 'npm', 'python', 'python3',
        'java', 'git', 'curl', 'wget', 'ping', 'dig', 'nslookup', 'openssl', 'timeout',
    ];

    /** Exact, argument-bounded commands that are safe enough for unattended execution. */
    private const AUTOMATIC_DIAGNOSTIC_COMMANDS = [
        'pwd', 'whoami', 'id', 'uname -s', 'uname -a',
        'git status --short', 'git rev-parse --show-toplevel',
    ];

    /** Resolve legacy connection rows and fail closed for unknown mode values. */
    public static function executionMode(?string $mode, bool $enabled): string
    {
        if (! $enabled) return 'disabled';
        if ($mode === null || $mode === '') return 'allowlist';
        return in_array($mode, self::EXECUTION_MODES, true) ? $mode : 'disabled';
    }

    public static function allows(string $command): bool
    {
        if ($command === '' || strlen($command) > 200 || trim($command) !== $command) return false;
        // This exact grammar invokes only the shell builtin and never executes the named binary.
        if (self::binaryPresenceIdentifier($command) !== null) return true;
        if (preg_match('/^command(?:\s|$)/', $command) === 1) return false;
        if (preg_match('/(?:^|[\/ ])\.\.(?:[\/ ]|$)/', $command) === 1) return false;
        $targetsAuthorizedKeys = preg_match('#(?:^|/)\.ssh/authorized_keys(?:$|[\s/])#i', str_replace('\\', '/', $command)) === 1;
        preg_match_all('/[A-Za-z0-9_.\/-]+/', $command, $pathLikeValues);
        foreach ($pathLikeValues[0] ?? [] as $value) {
            foreach (explode('/', $value) as $segment) {
                if ($targetsAuthorizedKeys && in_array(strtolower($segment), ['.ssh', 'authorized_keys'], true)) continue;
                if ($segment !== '' && PhpseclibSftpFileSystem::isSensitiveName($segment)) return false;
            }
        }

        for ($index = 0, $length = strlen($command); $index < $length; $index++) {
            if (! str_contains(self::SAFE_CHARACTERS, $command[$index])) return false;
        }

        // Match executable arguments, not path components: `/etc/ssh/sshd_config`
        // is a target path, while `ssh` or `/usr/bin/ssh` as an argument is a binary.
        foreach (preg_split('/\s+/', $command, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $argument) {
            $executable = strtolower(basename($argument));
            if (in_array($executable, self::BLOCKED_TOKENS, true)) return false;
        }

        return true;
    }

    /** A conservative risk label used for review flows that require human approval. */
    public static function riskLevel(string $command): string
    {
        if (self::binaryPresenceIdentifier($command) !== null || self::diagnosticVersionBinary($command) !== null) return 'diagnostic';
        preg_match_all('/[A-Za-z0-9_]+/', strtolower($command), $matches);
        $tokens = $matches[0] ?? [];
        if (array_intersect($tokens, self::SENSITIVE_TOKENS) !== []) return 'sensitive';
        if (($tokens[0] ?? null) === 'git' && array_intersect($tokens, ['add', 'commit', 'push', 'remote', 'config']) !== []) return 'sensitive';
        if (array_intersect($tokens, self::SERVER_ADMIN_TOKENS) !== [] || in_array('global', $tokens, true) || in_array('g', $tokens, true)) return 'server_admin';
        if (array_intersect($tokens, self::PROJECT_CHANGE_TOKENS) !== []) return 'project_change';
        if (preg_match('/(?:^|\s)\/[^\s]+/', $command) === 1) return 'server_admin';
        return isset($tokens[0]) && in_array($tokens[0], self::DIAGNOSTIC_TOKENS, true) ? 'diagnostic' : 'server_admin';
    }

    /**
     * Deterministic server-side authorization. A connection mode can narrow access,
     * but cannot expand the small unattended rule set.
     *
     * @return array{decision:string,reason:string,risk:string,critical_confirmation_required:bool,auto_rule:?string}
     */
    public static function decide(string $command, string $mode, array $allowedCommands = []): array
    {
        $mode = in_array($mode, self::EXECUTION_MODES, true) ? $mode : 'disabled';
        $risk = self::riskLevel($command);
        $critical = self::requiresCriticalConfirmation($command, $risk);

        if ($mode === 'disabled') return self::decision('blocked', 'L’exécution est désactivée pour cette connexion.', $risk, $critical);
        if (! self::allows($command)) return self::decision('blocked', 'La commande contient une syntaxe ou une opération bloquée.', $risk, $critical);
        if ($mode === 'allowlist' && ! in_array($command, $allowedCommands, true)) {
            return self::decision('blocked', 'La commande ne correspond pas exactement à une commande autorisée de la connexion.', $risk, $critical);
        }

        if (in_array($mode, ['unrestricted', 'allowlist'], true)
            && in_array($command, self::AUTOMATIC_DIAGNOSTIC_COMMANDS, true)
            && ! $critical) {
            return self::decision('automatic', 'Correspond à une règle exacte de diagnostic sans mutation.', 'diagnostic', false, 'read_only_exact_command');
        }

        if (in_array($mode, ['unrestricted', 'allowlist'], true)
            && self::binaryPresenceIdentifier($command) !== null
            && ! $critical) {
            return self::decision('automatic', 'Vérifie la présence d’un unique nom de binaire sans l’exécuter.', 'diagnostic', false, 'binary_presence_check');
        }

        if (in_array($mode, ['unrestricted', 'allowlist'], true)
            && self::diagnosticVersionBinary($command) !== null
            && ! $critical) {
            return self::decision('automatic', 'Correspond au catalogue serveur des commandes de version diagnostiques.', 'diagnostic', false, 'diagnostic_version_catalog');
        }

        $reason = $mode === 'approval'
            ? 'Le mode de cette connexion exige une approbation humaine pour chaque commande.'
            : 'Cette commande ne correspond pas à une règle étroite d’exécution automatique.';

        return self::decision('approval', $reason, $risk, $critical);
    }

    public static function requiresCriticalConfirmation(string $command, ?string $risk = null): bool
    {
        $risk ??= self::riskLevel($command);
        if (in_array($risk, ['server_admin', 'sensitive'], true)) return true;

        return preg_match('/(?:^|[\s\/])(?:sshd_config|authorized_keys)(?:$|[\s\/])/i', $command) === 1
            || preg_match('/(?:^|\s)(?:useradd|usermod|userdel|groupadd|groupdel|passwd|visudo|ufw|iptables|reboot|shutdown)(?:\s|$)/i', $command) === 1;
    }

    public static function binaryPresenceIdentifier(string $command): ?string
    {
        if (preg_match('/^command -v ([A-Za-z0-9][A-Za-z0-9._+-]{0,63})$/D', $command, $matches) !== 1) return null;
        return $matches[1];
    }

    public static function isNonMutatingAutomaticRule(?string $rule): bool
    {
        return in_array($rule, ['read_only_exact_command', 'binary_presence_check', 'diagnostic_version_catalog'], true);
    }

    public static function isKnownReadOnlyDiagnosticCommand(string $command): bool
    {
        return in_array($command, self::AUTOMATIC_DIAGNOSTIC_COMMANDS, true)
            || self::binaryPresenceIdentifier($command) !== null
            || self::diagnosticVersionBinary($command) !== null;
    }

    /** Classify only the bounded WP-CLI command shapes built by the server. */
    public static function classifyWordPressCliArgv(array $argv): ?array
    {
        if (! array_is_list($argv) || $argv === [] || count($argv) > 16) return null;
        foreach ($argv as $argument) {
            if (! is_string($argument) || strlen($argument) > 4096 || str_contains($argument, "\0")
                || preg_match('/[\x01-\x1F\x7F]/', $argument) === 1 || ! mb_check_encoding($argument, 'UTF-8')) return null;
        }
        $wordpressArguments = [];
        if (($argv[0] ?? null) === 'wp') {
            $wordpressArguments = array_slice($argv, 1);
        } elseif (($argv[0] ?? null) === 'php' && is_string($argv[1] ?? null)
            && preg_match('#^/(?:[^/]+/)*wp-cli\.phar$#iD', $argv[1]) === 1
            && ! in_array('..', explode('/', $argv[1]), true)) {
            $wordpressArguments = array_slice($argv, 2);
        } else {
            return null;
        }
        if ($wordpressArguments === []) return null;

        $risk = 'diagnostic';
        $readOnly = false;
        $kind = '';
        $verb = $wordpressArguments[0] ?? '';
        $slugPattern = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,79}$/D';
        if ($wordpressArguments === ['core', 'version']) {
            $kind = 'core_version';
            $readOnly = true;
        } elseif ($wordpressArguments === ['plugin', 'list', '--format=json']) {
            $kind = 'plugin_list';
            $readOnly = true;
        } elseif ($wordpressArguments === ['theme', 'list', '--format=json']) {
            $kind = 'theme_list';
            $readOnly = true;
        } elseif ($wordpressArguments === ['cli', 'cmd-dump', '--format=json']
            || $wordpressArguments === ['cli', 'has-command', 'cache', 'flush']) {
            $kind = 'command_discovery';
            $readOnly = true;
        } elseif (count($wordpressArguments) === 3 && $wordpressArguments[0] === 'option'
            && $wordpressArguments[1] === 'get'
            && preg_match('/^[A-Za-z0-9_.:-]{1,128}$/D', $wordpressArguments[2]) === 1) {
            $kind = 'option_get';
            $readOnly = true;
        } elseif ((count($wordpressArguments) === 3 || (count($wordpressArguments) === 4 && end($wordpressArguments) === '--format=json'))
            && $wordpressArguments[0] === 'post' && $wordpressArguments[1] === 'get'
            && preg_match('/^[1-9][0-9]{0,9}$/D', $wordpressArguments[2]) === 1) {
            $kind = 'post_get';
            $readOnly = true;
        } elseif (count($wordpressArguments) === 3 && in_array($wordpressArguments[0], ['plugin', 'theme'], true)
            && in_array($wordpressArguments[1], ['is-installed', 'is-active'], true)
            && preg_match($slugPattern, $wordpressArguments[2]) === 1) {
            $kind = $wordpressArguments[0].'_state_read';
            $readOnly = true;
        } elseif (count($wordpressArguments) === 3 && $wordpressArguments[0] === 'search-replace'
            && self::validSearchReplaceValue($wordpressArguments[1]) && self::validSearchReplaceValue($wordpressArguments[2], true)) {
            $kind = 'search_replace';
            $risk = 'critical';
        } elseif (count($wordpressArguments) === 4 && $wordpressArguments[0] === 'search-replace'
            && self::validSearchReplaceValue($wordpressArguments[1]) && self::validSearchReplaceValue($wordpressArguments[2], true)
            && $wordpressArguments[3] === '--format=count') {
            $kind = 'search_replace';
            $risk = 'critical';
        } elseif (count($wordpressArguments) === 4 && $wordpressArguments[0] === 'search-replace'
            && self::validSearchReplaceValue($wordpressArguments[1]) && self::validSearchReplaceValue($wordpressArguments[2], true)
            && in_array($wordpressArguments[3], ['--dry-run', '--format=count'], true)) {
            $kind = 'search_replace_dry_run';
            $risk = 'critical';
            $readOnly = true;
        } elseif (count($wordpressArguments) === 5 && $wordpressArguments[0] === 'search-replace'
            && self::validSearchReplaceValue($wordpressArguments[1]) && self::validSearchReplaceValue($wordpressArguments[2], true)
            && $wordpressArguments[3] === '--format=count' && $wordpressArguments[4] === '--dry-run') {
            $kind = 'search_replace_dry_run';
            $risk = 'critical';
            $readOnly = true;
        } elseif (count($wordpressArguments) === 3 && $wordpressArguments[0] === 'db' && $wordpressArguments[1] === 'export'
            && is_string($wordpressArguments[2]) && str_starts_with($wordpressArguments[2], '/')
            && strlen($wordpressArguments[2]) <= 2048 && ! in_array('..', explode('/', $wordpressArguments[2]), true)) {
            $kind = 'database_export';
            $risk = 'critical';
        } elseif (count($wordpressArguments) === 3 && in_array($wordpressArguments[0], ['plugin', 'theme'], true)
            && in_array($wordpressArguments[1], ['install', 'activate', 'deactivate', 'update', 'delete'], true)
            && preg_match($slugPattern, $wordpressArguments[2]) === 1) {
            $kind = $wordpressArguments[0].'_'. $wordpressArguments[1];
            $risk = 'critical';
        } elseif (count($wordpressArguments) >= 2 && count($wordpressArguments) <= 5
            && array_filter($wordpressArguments, static fn (string $token): bool => preg_match('/^[a-z0-9][a-z0-9_-]{0,40}$/D', $token) !== 1) === []
            && in_array(end($wordpressArguments), ['flush', 'purge', 'clear', 'clean', 'invalidate'], true)
            && ! in_array($wordpressArguments[0], ['cli', 'db', 'plugin', 'theme', 'user', 'post', 'option', 'core'], true)) {
            $kind = 'cache_flush';
            $risk = 'critical';
        } else {
            // In particular, eval, eval-file, shell, arbitrary flags and unknown
            // plugin commands are never accepted as a WP-CLI operation.
            return null;
        }

        return ['kind' => $kind, 'risk' => $risk, 'read_only' => $readOnly];
    }

    /** Apply the existing execution-mode and exact allowlist gates to a server-built argv tuple. */
    public static function decideWordPressCliArgv(array $argv, string $mode, array $allowedCommands = []): array
    {
        $classification = self::classifyWordPressCliArgv($argv);
        if ($classification === null) return self::decision('blocked', 'Cette commande WP-CLI n’est pas prise en charge.', 'critical', true);
        $mode = in_array($mode, self::EXECUTION_MODES, true) ? $mode : 'disabled';
        $risk = (string) $classification['risk'];
        $critical = $risk === 'critical';
        if ($mode === 'disabled') return self::decision('blocked', 'L’exécution est désactivée pour cette connexion SSH.', $risk, $critical);

        $command = implode(' ', $argv);
        if ($mode === 'allowlist') {
            // The legacy allowlist format cannot encode a whitespace-containing
            // argument; fail closed instead of comparing a lossy representation.
            if (array_filter($argv, static fn (string $argument): bool => preg_match('/\s/', $argument) === 1) !== []) {
                return self::decision('blocked', 'Cette commande argv ne peut pas être représentée exactement par la liste autorisée existante.', $risk, $critical);
            }
            $authorized = false;
            foreach ($allowedCommands as $allowedCommand) {
                if (! is_string($allowedCommand) || trim($allowedCommand) !== $allowedCommand) continue;
                $allowedArgv = preg_split('/\s+/', $allowedCommand, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                if ($allowedArgv === $argv) { $authorized = true; break; }
            }
            if (! $authorized) return self::decision('blocked', 'La commande WP-CLI ne correspond pas exactement à la liste autorisée de cette connexion.', $risk, $critical);
        }

        return self::decision('approval', $mode === 'approval'
            ? 'Le mode de cette connexion exige une approbation humaine pour chaque commande WP-CLI.'
            : 'Cette commande WP-CLI ne correspond pas à une règle étroite de lecture automatique.', $risk, $critical);
    }

    private static function diagnosticVersionBinary(string $command): ?string
    {
        if (preg_match('/^([A-Za-z0-9][A-Za-z0-9._+-]{0,63}) (?:--version|-v)$/D', $command, $matches) !== 1) return null;
        $catalog = config('devpilot.diagnostic_version_binaries', []);
        if (! is_array($catalog)) return null;
        foreach (array_slice($catalog, 0, 64) as $binary) {
            if (is_string($binary) && preg_match('/^[A-Za-z0-9][A-Za-z0-9._+-]{0,63}$/D', $binary) === 1 && hash_equals($binary, $matches[1])) {
                return $binary;
            }
        }
        return null;
    }

    private static function validSearchReplaceValue(string $value, bool $allowEmpty = false): bool
    {
        return ($allowEmpty || $value !== '') && ! str_starts_with($value, '-') && strlen($value) <= 1024
            && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1 && mb_check_encoding($value, 'UTF-8');
    }

    private static function decision(string $decision, string $reason, string $risk, bool $critical, ?string $autoRule = null): array
    {
        return [
            'decision' => $decision,
            'reason' => $reason,
            'risk' => $risk,
            'critical_confirmation_required' => $critical,
            'auto_rule' => $autoRule,
        ];
    }
}
