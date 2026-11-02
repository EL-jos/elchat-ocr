<?php

namespace App\Services\DevPilot;

use Closure;

/**
 * Adapter registry for safe continuation after a known no-change item failure.
 * Unknown operation types fail closed; domain adapters can register their own
 * target resolver without changing the common batch runner.
 */
final class DevPilotBatchExecutionPolicy
{
    /** @var array<string, Closure(array):?array> */
    private array $targetResolvers = [];

    public function __construct()
    {
        $this->registerCoreResolvers();
    }

    /**
     * Resolver result: ['signature' => homogeneous group key, 'target' => unique item key,
     * 'unique' => ['any' => 'additional unique key']]. Return null to reject continuation.
     */
    public function registerAdapter(string $operationType, callable $resolver): void
    {
        if (preg_match('/^[a-z][a-z0-9_]{2,79}$/D', $operationType) !== 1) {
            throw new \InvalidArgumentException('Invalid DevPilot batch adapter type.');
        }
        $this->targetResolvers[$operationType] = Closure::fromCallable($resolver);
    }

    public function mayContinueFileBatch(array $changes, int $failedIndex): bool
    {
        if (count($changes) < 2 || ! array_key_exists($failedIndex, $changes)) return false;

        $paths = [];
        foreach ($changes as $change) {
            if (! is_array($change) || ! in_array($change['action'] ?? null, ['write', 'delete'], true)
                || ! is_string($change['path'] ?? null) || trim($change['path']) === '') return false;
            $path = strtolower(str_replace('\\', '/', trim($change['path'])));
            if (isset($paths[$path])) return false;
            foreach (array_keys($paths) as $existing) {
                if (str_starts_with($path, rtrim($existing, '/').'/') || str_starts_with($existing, rtrim($path, '/').'/')) return false;
            }
            $paths[$path] = true;
        }

        return true;
    }

    public function mayContinueOperationBatch(array $operations, int $failedIndex): bool
    {
        if (count($operations) < 2 || ! array_key_exists($failedIndex, $operations)) return false;

        $signature = null;
        $targets = [];
        $additionalUnique = [];
        foreach ($operations as $operation) {
            if (! is_array($operation)) return false;
            $type = $operation['type'] ?? null;
            $resolver = is_string($type) ? ($this->targetResolvers[$type] ?? null) : null;
            if (! $resolver) return false;
            $identity = $resolver($operation);
            if (! is_array($identity) || ! is_string($identity['signature'] ?? null)
                || ! is_string($identity['target'] ?? null) || $identity['target'] === '') return false;
            if ($signature !== null && $signature !== $identity['signature']) return false;
            $signature = $identity['signature'];
            if (isset($targets[$identity['target']])) return false;
            $targets[$identity['target']] = true;
            foreach (is_array($identity['unique'] ?? null) ? $identity['unique'] : [] as $key => $value) {
                if (! is_string($key) || ! is_string($value) || $value === '') continue;
                if (isset($additionalUnique[$key][$value])) return false;
                $additionalUnique[$key][$value] = true;
            }
        }

        return true;
    }

    private function registerCoreResolvers(): void
    {
        $this->registerAdapter('wordpress_manage_plugin', static function (array $operation): ?array {
            $action = $operation['plugin_action'] ?? null;
            $slug = is_string($operation['plugin_slug'] ?? null) ? strtolower(trim($operation['plugin_slug'])) : '';
            $file = is_string($operation['plugin_file'] ?? null) ? strtolower(str_replace('\\', '/', trim($operation['plugin_file']))) : '';
            if (! is_string($action) || $action === 'update_bulk' || ($slug === '' && $file === '')) return null;
            return ['signature' => 'wordpress_manage_plugin:'.$action, 'target' => $file !== '' ? $file : $slug, 'unique' => ['slug' => $slug, 'file' => $file]];
        });
        $this->registerAdapter('wordpress_manage_theme', static function (array $operation): ?array {
            $action = $operation['theme_action'] ?? null;
            $slug = is_string($operation['theme_slug'] ?? null) ? strtolower(trim($operation['theme_slug'])) : '';
            return is_string($action) && $slug !== '' ? ['signature' => 'wordpress_manage_theme:'.$action, 'target' => $slug] : null;
        });
        $this->registerAdapter('wordpress_create_content', static function (array $operation): ?array {
            $contentType = is_string($operation['wordpress_type'] ?? null) ? strtolower(trim($operation['wordpress_type'])) : '';
            $slug = is_string($operation['slug'] ?? null) ? strtolower(trim($operation['slug'])) : '';
            return $contentType !== '' && $slug !== '' ? ['signature' => 'wordpress_create_content:'.$contentType, 'target' => $slug] : null;
        });

        foreach (['wordpress_update_content', 'wordpress_trash_content', 'wordpress_restore_content'] as $type) {
            $this->registerAdapter($type, static function (array $operation) use ($type): ?array {
                $contentType = is_string($operation['wordpress_type'] ?? null) ? strtolower(trim($operation['wordpress_type'])) : '';
                $id = is_numeric($operation['id'] ?? null) ? (int) $operation['id'] : 0;
                return $contentType !== '' && $id > 0 ? ['signature' => $type.':'.$contentType, 'target' => (string) $id] : null;
            });
        }
        $this->registerAdapter('wordpress_restore_revision', static function (array $operation): ?array {
            $contentType = is_string($operation['wordpress_type'] ?? null) ? strtolower(trim($operation['wordpress_type'])) : '';
            $postId = is_numeric($operation['id'] ?? null) ? (int) $operation['id'] : 0;
            $revisionId = is_numeric($operation['revision_id'] ?? null) ? (int) $operation['revision_id'] : 0;
            return $contentType !== '' && $postId > 0 && $revisionId > 0
                ? ['signature' => 'wordpress_restore_revision:'.$contentType, 'target' => $postId.':'.$revisionId] : null;
        });
        $this->registerAdapter('wordpress_update_media_metadata', static function (array $operation): ?array {
            $id = is_numeric($operation['id'] ?? null) ? (int) $operation['id'] : 0;
            return $id > 0 ? ['signature' => 'wordpress_update_media_metadata', 'target' => (string) $id] : null;
        });
        $this->registerAdapter('wordpress_update_setting', static function (array $operation): ?array {
            $name = is_string($operation['setting_name'] ?? null) ? strtolower(trim($operation['setting_name'])) : '';
            return $name !== '' ? ['signature' => 'wordpress_update_setting', 'target' => $name] : null;
        });
        $this->registerAdapter('create_database', static function (array $operation): ?array {
            $name = is_string($operation['database_name'] ?? null) ? strtolower(trim($operation['database_name'])) : '';
            return $name !== '' ? ['signature' => 'create_database', 'target' => $name] : null;
        });
    }
}
