<?php

namespace App\Services\DevPilot;

use App\Models\DevPilot\DevPilotProject;

/** Server-side gates for proposals that can change execution behavior. */
final class DevPilotApprovalPolicy
{
    public const EXECUTION_TRIGGERING_PATH_PATTERNS = [
        '.github/workflows/*',
        '.gitlab-ci.yml',
        'Dockerfile*',
        'docker-compose*.yml',
        'Makefile',
        'package.json',
        'composer.json',
        '.htaccess',
        '*.sh',
        '.husky/*',
    ];

    public static function requiresReinforcedConfirmation(
        string $environment,
        array $changes,
        array $operations,
        array $automaticOperations,
        ?DevPilotProject $project = null,
    ): bool {
        if (strtolower(trim($environment)) === 'production'
            && ($changes !== [] || $operations !== [] || $automaticOperations !== [])) {
            return true;
        }

        foreach ($changes as $change) {
            if ($project && is_array($change)
                && DevPilotWordPressFilePolicy::isPluginInstallTarget(
                    $project,
                    (string) ($change['path'] ?? ''),
                    (string) ($change['action'] ?? ''),
                )) {
                return true;
            }
            if (! is_array($change) || ($change['action'] ?? null) !== 'write') {
                continue;
            }
            if (self::pathCanTriggerExecution((string) ($change['path'] ?? ''))) {
                return true;
            }
        }

        foreach ($operations as $operation) {
            if (is_array($operation) && in_array($operation['risk'] ?? null, ['critical', 'sensitive'], true)) return true;
        }

        return false;
    }

    public static function pathCanTriggerExecution(string $path): bool
    {
        $path = str_replace('\\', '/', trim($path));
        $path = preg_replace('#^(?:\./)+#', '', $path) ?? $path;
        $path = preg_replace('#/+#', '/', ltrim($path, '/')) ?? $path;
        $basename = basename($path);

        foreach (self::EXECUTION_TRIGGERING_PATH_PATTERNS as $pattern) {
            $candidate = str_contains($pattern, '/') ? $path : $basename;
            if (fnmatch($pattern, $candidate)) {
                return true;
            }
        }

        return false;
    }
}
