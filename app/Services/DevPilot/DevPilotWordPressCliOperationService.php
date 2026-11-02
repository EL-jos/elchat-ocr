<?php

namespace App\Services\DevPilot;

use RuntimeException;

/** Server-built WP-CLI argv operations. No model-controlled executable, shell string, or path is accepted. */
final class DevPilotWordPressCliOperationService
{
    private const PROTOCOL_VERSION = 1;

    public function prepare(array $input, array $runtime): array
    {
        $summary = mb_strcut(trim((string) ($input['summary'] ?? '')), 0, 500);
        if ($summary === '') throw new RuntimeException('Un résumé lisible pour approbation est obligatoire.');
        $capabilities = is_array($runtime['capabilities'] ?? null) ? $runtime['capabilities'] : [];
        $commands = is_array($capabilities['commands_detected'] ?? null) ? $capabilities['commands_detected'] : [];
        $phpCli = in_array('php', $commands, true);
        if (($capabilities['shell'] ?? false) !== true || ($runtime['command_mode'] ?? 'disabled') === 'disabled') {
            throw new RuntimeException('Le profil WordPress ne possède pas de canal SSH avec exécution de commandes autorisée.');
        }
        if (in_array('wp', $commands, true)) {
            $prefix = ['wp'];
        } elseif ($phpCli && ($capabilities['wp_cli_phar'] ?? null) === 'present'
            && is_string($runtime['wp_cli_phar_path'] ?? null) && $runtime['wp_cli_phar_path'] !== '') {
            $prefix = ['php', $runtime['wp_cli_phar_path']];
        } else {
            throw new RuntimeException('WP-CLI est absent. L’installation de wp-cli.phar n’est pas automatique; elle exige un design et une validation distincts.');
        }

        $subcommand = (string) ($input['subcommand'] ?? '');
        $risk = 'diagnostic';
        $warnings = [];
        $displayDiff = [];
        $backupPath = null;
        $cacheCommand = null;
        $wpArguments = match ($subcommand) {
            'core_version' => ['core', 'version'],
            'option_get' => ['option', 'get', $this->optionName($input['option_name'] ?? null)],
            'post_get' => ['post', 'get', (string) $this->positiveId($input['post_id'] ?? null), '--format=json'],
            'plugin_list' => ['plugin', 'list', '--format=json'],
            'theme_list' => ['theme', 'list', '--format=json'],
            'plugin_state' => $this->extensionStateArguments('plugin', $input),
            'theme_state' => $this->extensionStateArguments('theme', $input),
            'search_replace' => $this->searchReplaceArguments($input, $runtime, $warnings, $displayDiff, $backupPath, $risk),
            'search_replace_check' => $this->searchReplaceCheckArguments($input, $warnings, $displayDiff, $risk),
            'plugin_action' => $this->extensionActionArguments('plugin', $input, $risk, $displayDiff),
            'theme_action' => $this->extensionActionArguments('theme', $input, $risk, $displayDiff),
            'cache_purge' => $this->cachePurgeArguments($input, $capabilities, $cacheCommand, $risk),
            default => throw new RuntimeException('Sous-commande WP-CLI non prise en charge.'),
        };
        $argv = [...$prefix, ...$wpArguments];
        $classification = DevPilotCommandPolicy::classifyWordPressCliArgv($argv);
        if ($classification === null || ($classification['risk'] ?? null) !== $risk) {
            throw new RuntimeException('Le tuple argv demandé ne correspond à aucune opération WP-CLI autorisée.');
        }
        $allowedCommands = is_array($runtime['allowed_commands'] ?? null) ? $runtime['allowed_commands'] : [];
        $decision = DevPilotCommandPolicy::decideWordPressCliArgv($argv, (string) $runtime['command_mode'], $allowedCommands);
        if (($decision['decision'] ?? 'blocked') === 'blocked') throw new RuntimeException((string) $decision['reason']);

        $workingDirectory = (string) ($runtime['working_directory'] ?? '');
        if (! str_starts_with($workingDirectory, '/') || strlen($workingDirectory) > 2048
            || preg_match('/[\x00-\x1F\x7F]/', $workingDirectory) === 1 || in_array('..', explode('/', $workingDirectory), true)) {
            throw new RuntimeException('La racine distante WordPress n’est pas sûre pour WP-CLI.');
        }

        return [
            'type' => 'wordpress_cli', 'channel' => 'ssh', 'protocol_version' => self::PROTOCOL_VERSION,
            'required_capabilities' => ['commands'], 'approval_required' => true,
            'critical_confirmation_required' => $risk === 'critical',
            'subcommand' => $subcommand, 'argv' => $argv,
            'argv_display' => DevPilotCommandBuilder::displayArgv($argv),
            'working_directory' => $workingDirectory, 'risk' => $risk,
            'authorization_reason' => $decision['reason'] ?? '',
            'summary' => $summary, 'warnings' => $warnings, 'display_diff' => $displayDiff,
            ...array_intersect_key($input, array_flip(['option_name', 'post_id', 'plugin_slug', 'plugin_action', 'theme_slug', 'theme_action', 'state_action', 'cache_command'])),
            'backup_path' => $backupPath, 'cache_command' => $cacheCommand,
            'execution_decision' => $decision['decision'] ?? 'approval',
        ];
    }

    /** @param callable(array,string,bool):array $run Server-side argv executor, private umask optional as fourth argument. */
    public function apply(array $operation, callable $run, callable $remoteFileMetadata): array
    {
        $argv = $operation['argv'] ?? null;
        $workingDirectory = (string) ($operation['working_directory'] ?? '');
        if (! is_array($argv) || DevPilotCommandPolicy::classifyWordPressCliArgv($argv) === null
            || ($operation['channel'] ?? null) !== 'ssh' || ($operation['protocol_version'] ?? null) !== self::PROTOCOL_VERSION) {
            throw new RuntimeException('La proposition WP-CLI a changé ou n’est plus autorisée.');
        }
        $classification = DevPilotCommandPolicy::classifyWordPressCliArgv($argv);
        $subcommand = (string) ($operation['subcommand'] ?? '');
        $kindMatches = match ($subcommand) {
            'core_version' => ($classification['kind'] ?? null) === 'core_version',
            'option_get' => ($classification['kind'] ?? null) === 'option_get',
            'post_get' => ($classification['kind'] ?? null) === 'post_get',
            'plugin_list' => ($classification['kind'] ?? null) === 'plugin_list',
            'theme_list' => ($classification['kind'] ?? null) === 'theme_list',
            'plugin_state' => ($classification['kind'] ?? null) === 'plugin_state_read',
            'theme_state' => ($classification['kind'] ?? null) === 'theme_state_read',
            'search_replace' => ($classification['kind'] ?? null) === 'search_replace',
            'search_replace_check' => ($classification['kind'] ?? null) === 'search_replace_dry_run',
            'plugin_action' => ($classification['kind'] ?? null) === 'plugin_'.(string) ($operation['plugin_action'] ?? '')
                && hash_equals((string) ($operation['plugin_slug'] ?? ''), (string) end($argv)),
            'theme_action' => ($classification['kind'] ?? null) === 'theme_'.(string) ($operation['theme_action'] ?? '')
                && hash_equals((string) ($operation['theme_slug'] ?? ''), (string) end($argv)),
            'cache_purge' => ($classification['kind'] ?? null) === 'cache_flush',
            default => false,
        };
        if (! $kindMatches || ($operation['required_capabilities'] ?? null) !== ['commands']) {
            throw new RuntimeException('Le type ou les capacités de l’opération WP-CLI ne correspondent plus au tuple approuvé.');
        }
        if (in_array($operation['subcommand'], ['search_replace', 'search_replace_check'], true)) {
            if ($operation['subcommand'] === 'search_replace_check') return $this->applySearchReplaceCheck($operation, $run);
            return $this->applySearchReplace($operation, $run, $remoteFileMetadata);
        }
        if (in_array($operation['subcommand'], ['plugin_state', 'theme_state'], true)) {
            return $this->applyExtensionStateRead($operation, $run);
        }
        if (in_array($operation['subcommand'], ['plugin_action', 'theme_action'], true)) {
            return $this->applyExtensionAction($operation, $run);
        }
        if ($operation['subcommand'] === 'cache_purge') {
            $result = $run($argv, $workingDirectory, true, false);
            $this->assertMutationCompleted($result, 'La purge WP-CLI a été interrompue; son résultat est incertain.');
            return $this->result($operation, false, 0, 'Commande de purge cache détectée et exécutée, mais l’état du cache n’est pas vérifiable par WP-CLI.', [
                'cache_purge_command_completed' => true, 'cache_purge_verified' => false,
            ]);
        }

        $result = $run($argv, $workingDirectory, true, false);
        if (($result['execution_state'] ?? null) !== 'completed' || ($result['exit_code'] ?? null) !== 0) {
            throw new RuntimeException('La commande de lecture WP-CLI a échoué ou son résultat est incertain.');
        }
        $output = is_string($result['stdout'] ?? null) ? mb_strcut($result['stdout'], 0, 8000) : '';
        $truncated = (bool) ($result['output_truncated'] ?? false);
        $complete = ! $truncated;
        return [
            ...$this->result($operation, $complete, 0,
                $complete ? 'Lecture WP-CLI terminée; la sortie est une donnée distante non fiable.' : 'La lecture WP-CLI est tronquée et ne peut pas servir de preuve complète.', [
                'read_output_complete' => $complete, 'sha256' => hash('sha256', $output),
            ]),
            'stdout' => $output,
            'output_truncated' => $truncated,
        ];
    }

    private function applySearchReplace(array $operation, callable $run, callable $remoteFileMetadata): array
    {
        $argv = $operation['argv'];
        $dryRunArgv = [...$argv, '--dry-run'];
        $dryRun = $run($dryRunArgv, (string) $operation['working_directory'], true, false);
        $this->assertReadOnlyCompleted($dryRun, 'Le dry-run de search-replace a échoué; aucune écriture n’a été lancée.');
        if (($dryRun['output_truncated'] ?? false) === true) throw new RuntimeException('Le résultat du dry-run est incomplet; aucune écriture n’a été lancée.');

        $backupPath = (string) ($operation['backup_path'] ?? '');
        if ($backupPath === '') throw new RuntimeException('Aucun chemin de sauvegarde privée n’est lié à la proposition.');
        $existingBackup = $remoteFileMetadata($backupPath);
        if (! is_array($existingBackup) || ($existingBackup['exists'] ?? false) !== false) {
            throw new RuntimeException('Le chemin de sauvegarde privée existe déjà ou ne peut pas être vérifié; search-replace n’a pas été lancé.');
        }
        $backupArgv = [...array_slice($argv, 0, count($argv) - 4), 'db', 'export', $backupPath];
        $backup = $run($backupArgv, (string) $operation['working_directory'], true, true);
        $this->assertMutationCompleted($backup, 'La sauvegarde préalable est incertaine; search-replace n’a pas été lancé.');
        $backupMetadata = $remoteFileMetadata($backupPath);
        if (! is_array($backupMetadata) || ($backupMetadata['exists'] ?? false) !== true || (int) ($backupMetadata['size'] ?? 0) <= 0) {
            throw new RuntimeException('WP-CLI n’a pas produit la sauvegarde privée attendue; search-replace n’a pas été lancé.');
        }

        $write = $run($argv, (string) $operation['working_directory'], true, false);
        $this->assertMutationCompleted($write, 'search-replace a été interrompu après son envoi; l’état est incertain et la sauvegarde est conservée.');
        $postflight = $run($dryRunArgv, (string) $operation['working_directory'], true, false);
        if (($postflight['execution_state'] ?? null) !== 'completed' || ($postflight['exit_code'] ?? null) !== 0
            || ($postflight['output_truncated'] ?? false) === true) {
            throw new DevPilotOperationUncertainException('search-replace a été envoyé, mais son dry-run final n’a pas pu relire l’état; la sauvegarde est conservée et aucune relance automatique ne sera faite.');
        }
        $postflightValid = ($postflight['execution_state'] ?? null) === 'completed' && ($postflight['exit_code'] ?? null) === 0
            && ($postflight['output_truncated'] ?? false) !== true
            && preg_match('/^\s*0\s*$/', (string) ($postflight['stdout'] ?? '')) === 1;

        return $this->result($operation, $postflightValid, 0,
            $postflightValid ? 'search-replace est terminé; un dry-run final ne trouve plus de remplacement correspondant.'
                : 'search-replace est terminé, mais la relecture finale ne confirme pas que toutes les correspondances ont disparu.',
            ['dry_run_completed' => true, 'backup_verified' => true, 'search_replace_completed' => true,
                'postflight_verified' => $postflightValid, 'backup_path' => $backupPath,
                'sha256' => hash('sha256', trim((string) ($postflight['stdout'] ?? '')))]);
    }

    private function applySearchReplaceCheck(array $operation, callable $run): array
    {
        $result = $run($operation['argv'], (string) $operation['working_directory'], true, false);
        $this->assertReadOnlyCompleted($result, 'La relecture dry-run de search-replace a échoué.');
        if (($result['output_truncated'] ?? false) === true) throw new RuntimeException('Le résultat de la relecture search-replace est incomplet.');
        $output = trim((string) ($result['stdout'] ?? ''));
        if (preg_match('/^[0-9]{1,12}$/D', $output) !== 1) throw new RuntimeException('WP-CLI n’a pas renvoyé un compte dry-run exploitable.');

        return $this->result($operation, true, 0, 'Relecture dry-run terminée; le compte observé reste une donnée distante non fiable.', [
            'read_output_complete' => true, 'count' => (int) $output,
            'sha256' => hash('sha256', $output),
        ]);
    }

    private function applyExtensionStateRead(array $operation, callable $run): array
    {
        $result = $run($operation['argv'], (string) $operation['working_directory'], true, false);
        $exitCode = $result['exit_code'] ?? null;
        $complete = in_array($result['execution_state'] ?? null, ['completed', 'failed'], true)
            && in_array($exitCode, [0, 1], true) && ($result['output_truncated'] ?? false) !== true;
        if (! $complete) throw new RuntimeException('La lecture d’état plugin/thème a échoué ou reste incertaine.');
        $state = (string) ($operation['state_action'] ?? '').':'.(string) ($operation['plugin_slug'] ?? $operation['theme_slug'] ?? '').':'.(string) $exitCode;

        return $this->result($operation, true, 0,
            'État plugin/thème relu par WP-CLI : '.($exitCode === 0 ? 'présent ou actif selon la sonde demandée.' : 'absent ou inactif selon la sonde demandée.'),
            ['read_output_complete' => true, 'verified' => true, 'count' => $exitCode === 0 ? 1 : 0, 'sha256' => hash('sha256', $state)]);
    }

    private function applyExtensionAction(array $operation, callable $run): array
    {
        $argv = $operation['argv'];
        $mutation = $run($argv, (string) $operation['working_directory'], true, false);
        $this->assertMutationCompleted($mutation, 'La modification plugin/thème a été interrompue; son résultat est incertain.');
        $kind = str_starts_with((string) ($operation['subcommand'] ?? ''), 'plugin_') ? 'plugin' : 'theme';
        $parts = array_slice($argv, 0, count($argv) - 3);
        $slug = (string) ($operation[$kind.'_slug'] ?? '');
        $readback = $run([...$parts, $kind, 'list', '--format=json'], (string) $operation['working_directory'], true, false);
        if (($readback['execution_state'] ?? null) !== 'completed' || ($readback['exit_code'] ?? null) !== 0
            || ($readback['output_truncated'] ?? false) === true) {
            throw new DevPilotOperationUncertainException('La mutation plugin/thème a été envoyée, mais sa relecture WP-CLI est incertaine.');
        }
        $verified = false;
        $records = json_decode((string) ($readback['stdout'] ?? ''), true);
        if (! is_array($records)) throw new DevPilotOperationUncertainException('La mutation plugin/thème a été envoyée, mais la liste WP-CLI est illisible.');
        $matches = array_values(array_filter($records, static function ($record) use ($slug, $kind): bool {
            if (! is_array($record)) return false;
            $name = (string) ($record['name'] ?? '');
            $basename = basename($name, '.php');
            $directory = basename(dirname($name));
            return hash_equals($slug, $name) || hash_equals($slug, $basename)
                || ($kind === 'plugin' && hash_equals($slug, $directory));
        }));
        $action = (string) ($operation[$kind.'_action'] ?? '');
        $verified = match ($action) {
            'delete' => $matches === [],
            'install' => count($matches) === 1,
            'update' => count($matches) === 1 && in_array(strtolower((string) ($matches[0]['update'] ?? 'none')), ['', 'none'], true),
            'activate' => count($matches) === 1 && strtolower((string) ($matches[0]['status'] ?? '')) === 'active',
            'deactivate' => count($matches) === 1 && strtolower((string) ($matches[0]['status'] ?? '')) !== 'active',
            default => false,
        };

        $readbackHash = count($matches ?? []) === 1
            ? hash('sha256', json_encode($matches[0], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: '')
            : hash('sha256', 'absent:'.$slug);
        return $this->result($operation, $verified, 0,
            $verified ? 'La modification plugin/thème a été confirmée par la liste WP-CLI.'
                : 'WP-CLI a terminé la modification, mais sa relecture ne confirme pas l’état demandé.',
            ['readback_verified' => $verified, 'sha256' => $readbackHash]);
    }

    private function result(array $operation, bool $verified, int $exitCode, string $summary, array $facts): array
    {
        return [
            'type' => 'wordpress_cli', 'status' => $verified ? 'verified' : 'verification_failed',
            'execution_state' => 'completed', 'exit_code' => $exitCode, 'verified' => $verified,
            'subcommand' => (string) ($operation['subcommand'] ?? ''),
            'target' => $this->operationTarget($operation),
            'summary' => (string) ($operation['summary'] ?? ''), 'message' => $summary,
            'risk' => $operation['risk'] ?? 'diagnostic', 'argv_display' => $operation['argv_display'] ?? '',
            'working_directory' => $operation['working_directory'] ?? '', 'facts' => $facts,
        ];
    }

    private function operationTarget(array $operation): string
    {
        $subcommand = (string) ($operation['subcommand'] ?? 'operation');
        if (in_array($subcommand, ['plugin_action', 'plugin_state'], true)) return 'wp-cli:plugin:'.(string) ($operation['plugin_slug'] ?? '');
        if (in_array($subcommand, ['theme_action', 'theme_state'], true)) return 'wp-cli:theme:'.(string) ($operation['theme_slug'] ?? '');
        if (in_array($subcommand, ['search_replace', 'search_replace_check'], true)) {
            $argv = is_array($operation['argv'] ?? null) ? $operation['argv'] : [];
            $index = array_search('search-replace', $argv, true);
            $index = is_int($index) ? $index : 0;
            return 'wp-cli:search-replace:'.hash('sha256', json_encode([$argv[$index + 1] ?? '', $argv[$index + 2] ?? ''], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
        }
        return 'wp-cli:'.$subcommand;
    }

    private function assertReadOnlyCompleted(array $result, string $message): void
    {
        if (($result['execution_state'] ?? null) !== 'completed' || ($result['exit_code'] ?? null) !== 0) throw new RuntimeException($message);
    }

    private function assertMutationCompleted(array $result, string $message): void
    {
        if (($result['execution_state'] ?? null) !== 'completed' || ($result['exit_code'] ?? null) !== 0) {
            throw new DevPilotOperationUncertainException($message);
        }
    }

    private function searchReplaceArguments(array $input, array $runtime, array &$warnings, array &$displayDiff, ?string &$backupPath, string &$risk): array
    {
        $old = $this->searchValue($input['old_value'] ?? null);
        $new = $this->searchValue($input['new_value'] ?? null, allowEmpty: true);
        $home = (string) ($runtime['user_home'] ?? '');
        $root = rtrim((string) ($runtime['working_directory'] ?? ''), '/');
        if (! str_starts_with($home, '/') || $home === '/' || $home === $root || str_starts_with($home, $root.'/')) {
            throw new RuntimeException('Le dossier personnel SSH est dans la racine du site ou ne peut pas être vérifié; aucune sauvegarde privée sûre n’est disponible.');
        }
        $backupPath = $home.'/.elchat-devpilot-wp-'.bin2hex(random_bytes(16)).'.sql';
        $risk = 'critical';
        $warnings = [
            'search-replace est critique : le même argv sera exécuté d’abord avec --dry-run, puis après une sauvegarde SQL privée.',
            'Sauvegarde locale au compte SSH : '.$backupPath.'. Aucun retour arrière automatique ne sera exécuté.',
            'Sans option réseau explicite, WP-CLI cible les tables du site courant.',
        ];
        $displayDiff = ['search_replace' => ['before' => $old, 'after' => $new]];
        return ['search-replace', $old, $new, '--format=count'];
    }

    private function extensionActionArguments(string $kind, array $input, string &$risk, array &$displayDiff): array
    {
        $actionKey = $kind.'_action';
        $slugKey = $kind.'_slug';
        $action = (string) ($input[$actionKey] ?? '');
        $slug = (string) ($input[$slugKey] ?? '');
        if (! in_array($action, ['install', 'activate', 'deactivate', 'update', 'delete'], true)
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,79}$/D', $slug) !== 1) {
            throw new RuntimeException('Action ou slug WP-CLI de plugin/thème invalide. Les URL et archives arbitraires sont interdites.');
        }
        $risk = 'critical';
        $displayDiff = [$kind => ['before' => '', 'after' => $action.' '.$slug]];
        return [$kind, $action, $slug];
    }

    private function extensionStateArguments(string $kind, array $input): array
    {
        $slug = (string) ($input[$kind.'_slug'] ?? '');
        $action = (string) ($input['state_action'] ?? '');
        if (! in_array($action, ['is-installed', 'is-active'], true)
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,79}$/D', $slug) !== 1) {
            throw new RuntimeException('La lecture d’état WP-CLI exige un slug et une action prédéfinis.');
        }
        return [$kind, $action, $slug];
    }

    private function searchReplaceCheckArguments(array $input, array &$warnings, array &$displayDiff, string &$risk): array
    {
        $old = $this->searchValue($input['old_value'] ?? null);
        $new = $this->searchValue($input['new_value'] ?? null, allowEmpty: true);
        $risk = 'critical';
        $warnings = ['Relecture WP-CLI en dry-run uniquement : aucune écriture ni sauvegarde SQL ne sera lancée.'];
        $displayDiff = ['search_replace' => ['before' => $old, 'after' => $new]];
        return ['search-replace', $old, $new, '--format=count', '--dry-run'];
    }

    private function cachePurgeArguments(array $input, array $capabilities, ?string &$selected, string &$risk): array
    {
        $selected = $input['cache_command'] ?? null;
        $available = is_array($capabilities['wp_cli_cache_purge_commands'] ?? null) ? $capabilities['wp_cli_cache_purge_commands'] : [];
        if (! is_string($selected) || ! in_array($selected, $available, true)) {
            throw new RuntimeException($available === []
                ? 'Cache non purgé : aucune commande de purge cache détectée dans les commandes WP-CLI exposées.'
                : 'La commande de purge demandée ne fait pas partie des commandes WP-CLI détectées.');
        }
        $tokens = preg_split('/\s+/', trim($selected), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($tokens) < 2 || count($tokens) > 5) throw new RuntimeException('La commande de purge cache détectée est invalide.');
        $risk = 'critical';
        return $tokens;
    }

    private function optionName(mixed $name): string
    {
        if (! is_string($name) || preg_match('/^[A-Za-z0-9_.:-]{1,128}$/D', $name) !== 1) throw new RuntimeException('Nom d’option WP-CLI invalide.');
        return $name;
    }

    private function positiveId(mixed $id): int
    {
        if (! is_int($id) || $id < 1 || $id > 2147483647) throw new RuntimeException('Identifiant de contenu WP-CLI invalide.');
        return $id;
    }

    private function searchValue(mixed $value, bool $allowEmpty = false): string
    {
        if (! is_string($value) || (! $allowEmpty && $value === '') || strlen($value) > 1024
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1 || ! mb_check_encoding($value, 'UTF-8')) {
            throw new RuntimeException('Valeur search-replace vide, trop longue ou invalide.');
        }
        return $value;
    }
}
