<?php

namespace App\Services\DevPilot;

use App\Models\DevPilot\DevPilotProject;

/** Default write boundary for the WordPress SFTP/FTP file channel. */
final class DevPilotWordPressFilePolicy
{
    private const ALLOWED_ROOTS = [
        'wp-content/themes',
        'wp-content/plugins',
        'wp-content/mu-plugins',
    ];

    public static function allows(DevPilotProject $project, string $relativePath): bool
    {
        if ($project->wordpress_profile_id === null) return true;

        $combined = self::combinedPath((string) $project->root_path, $relativePath);
        if ($combined === null) return false;
        $lower = strtolower($combined);
        $segments = explode('/', $lower);

        if (in_array('wp-admin', $segments, true)
            || in_array('wp-includes', $segments, true)
            || in_array('wp-config.php', $segments, true)) {
            return false;
        }
        if (str_starts_with($lower, 'wp-content/uploads/')) {
            // Uploads are outside the default writable roots; PHP is rejected explicitly as well.
            if (preg_match('/\.php(?:[0-9]+)?$/i', $lower) === 1) return false;
            return false;
        }

        foreach (self::ALLOWED_ROOTS as $root) {
            if ($lower === $root || str_starts_with($lower, $root.'/')) return true;
        }

        return false;
    }

    public static function isPluginInstallTarget(DevPilotProject $project, string $relativePath, string $action): bool
    {
        if ($project->wordpress_profile_id === null) return false;
        $combined = self::combinedPath((string) $project->root_path, $relativePath);
        if ($combined === null) return false;
        $lower = strtolower($combined);

        return ($lower === 'wp-content/plugins' || str_starts_with($lower, 'wp-content/plugins/'))
            && in_array($action, ['write', 'mkdir', 'delete'], true);
    }

    private static function combinedPath(string $projectRoot, string $relativePath): ?string
    {
        $projectRoot = str_replace('\\', '/', trim($projectRoot));
        $relativePath = str_replace('\\', '/', trim($relativePath));
        if ($relativePath === '' || str_starts_with($relativePath, '/')
            || str_contains($relativePath, "\0") || preg_match('/^[A-Za-z]:/', $relativePath) === 1) {
            return null;
        }

        $segments = [];
        foreach ([$projectRoot === '.' ? '' : $projectRoot, $relativePath] as $part) {
            if ($part === '') continue;
            foreach (explode('/', trim($part, '/')) as $segment) {
                if ($segment === '' || $segment === '.' || $segment === '..'
                    || preg_match('/[\x00-\x1F\x7F]/', $segment) === 1) {
                    return null;
                }
                $segments[] = $segment;
            }
        }

        return strtolower(implode('/', $segments));
    }
}
