<?php

namespace App\Services\DevPilot;

use App\Models\DevPilot\DevPilotConnection;
use App\Models\DevPilot\DevPilotProject;
use App\Models\DevPilot\DevPilotTask;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;

/** Typed content-only operations over the ELChat-DevPilot HMAC bridge. */
final class DevPilotWordPressBridgeOperationService
{
    private const CONTENT_FIELDS = ['title', 'excerpt', 'content', 'slug', 'status', 'date_gmt', 'taxonomies', 'create_terms', 'password', 'author', 'comment_status', 'ping_status', 'sticky', 'parent', 'menu_order', 'template', 'seo'];
    private const PLUGIN_LIST_FIELDS = ['plugin_file', 'slug', 'name', 'version', 'description', 'author', 'plugin_uri', 'requires', 'requires_php', 'requires_plugins', 'active', 'network_active', 'auto_update', 'protected', 'auto_update_enabled', 'state_hash'];
    private const PLUGIN_LIST_DEFAULT_FIELDS = ['plugin_file', 'name', 'slug', 'version', 'active', 'network_active', 'protected', 'state_hash'];
    private const MAX_TEXT_BYTES = 120 * 1024;

    public function __construct(
        private readonly DevPilotWordPressBridgeChannel $channel,
        private readonly DevPilotWordPressClient $wordpress,
    ) {}

    public function inspect(DevPilotConnection $connection, array $credentials, string $tool, array $arguments, array $createdObjects = []): array
    {
        $binding = $this->channel->inspectBinding($connection, $credentials);
        if (str_starts_with($tool, 'devpilot_wp_') && str_contains($tool, 'plugin') || $tool === 'devpilot_wp_site_health') {
            return $this->inspectPluginTool($connection, $credentials, $binding, $tool, $arguments);
        }
        $required = match ($tool) {
            'devpilot_wp_list_types' => ['content.read'],
            'devpilot_wp_list_content' => ['content.search'],
            'devpilot_wp_read_content' => ['content.read'],
            'devpilot_wp_check_duplicate' => ['content.search'],
            'devpilot_wp_search_media' => ['media.search'],
            'devpilot_wp_read_media' => ['media.read'],
            'devpilot_wp_list_terms' => ['content.taxonomy.assign'],
            'devpilot_wp_list_revisions' => ['content.read', 'content.revisions.read'],
            'devpilot_wp_list_page_templates' => ['content.read', 'content.page.structure'],
            default => throw new RuntimeException('Cette inspection WordPress n’est pas disponible sur le canal du plugin.'),
        };
        $this->assertLiveCapabilities($binding['capability_schemas'], $required);

        if ($tool === 'devpilot_wp_list_types') {
            $types = $this->safeTypes($binding['capabilities']['content_types']);
            return $this->inspectionResult('wordpress_types', $types, true, ['entry_hashes' => array_map(static fn (array $type): string => hash('sha256', $type['id']), $types), 'count' => count($types)]);
        }

        if ($tool === 'devpilot_wp_search_media') {
            $page = $this->boundedInt($arguments['page'] ?? 1, 1, 50, 'page');
            $perPage = $this->boundedInt($arguments['per_page'] ?? 10, 1, 10, 'nombre de médias');
            $query = ['page' => $page, 'per_page' => $perPage];
            $search = $arguments['search'] ?? null;
            if ($search !== null && (! is_string($search) || strlen($search) > 120 || preg_match('/[\x00-\x1f\x7f]/', $search) === 1)) throw new RuntimeException('Le filtre de recherche média est invalide.');
            if (is_string($search) && trim($search) !== '') $query['search'] = trim($search);
            $response = $this->channel->request($connection, $credentials, 'GET', '/elchat-devpilot/v1/media', $query)['data'];
            if (! is_array($response['items'] ?? null) || ! is_int($response['total_pages'] ?? null)) throw new RuntimeException('La recherche de la médiathèque ne respecte pas le schéma attendu.');
            $items = [];
            foreach (array_slice($response['items'], 0, $perPage) as $media) {
                if (! is_array($media) || ! is_int($media['id'] ?? null) || $media['id'] < 1) continue;
                $items[] = array_intersect_key($media, array_flip(['id', 'title', 'caption', 'alt', 'mime_type', 'url', 'modified_gmt']));
            }
            $complete = ($response['complete'] ?? false) === true && $page >= min(1000, $response['total_pages']);
            return $this->inspectionResult('wordpress_media_list', ['records' => $items, 'pagination' => ['page' => $page, 'per_page' => $perPage, 'total_items' => $response['total'] ?? null, 'total_pages' => min(1000, $response['total_pages'])]], $complete, ['count' => count($items)], ! $complete && $page >= min(1000, $response['total_pages']));
        }

        if ($tool === 'devpilot_wp_read_media') {
            $id = $this->positiveId($arguments['id'] ?? null);
            $media = $this->channel->request($connection, $credentials, 'GET', '/elchat-devpilot/v1/media/'.$id)['data'];
            if (($media['id'] ?? null) !== $id || ! is_string($media['state_hash'] ?? null)) throw new RuntimeException('La relecture de l’image WordPress ne respecte pas le schéma attendu.');
            $safe = array_intersect_key($media, array_flip(['id', 'title', 'caption', 'alt', 'mime_type', 'url', 'modified_gmt', 'state_hash']));
            return $this->inspectionResult('wordpress_media_read', $safe, true, ['status' => 'read', 'sha256' => (string) $media['state_hash']]);
        }

        $type = $this->identifier($arguments['type'] ?? null, 'type de contenu');
        $this->resolveType($binding['capabilities']['content_types'], $type);
        if ($tool === 'devpilot_wp_list_revisions') {
            $id = $this->positiveId($arguments['id'] ?? null);
            $page = $this->boundedInt($arguments['page'] ?? 1, 1, 50, 'page');
            $perPage = $this->boundedInt($arguments['per_page'] ?? 10, 1, 20, 'nombre de révisions');
            $response = $this->channel->request($connection, $credentials, 'GET', '/elchat-devpilot/v1/content/'.$type.'/'.$id.'/revisions', ['page' => $page, 'per_page' => $perPage])['data'];
            if (! is_array($response['items'] ?? null) || ! is_int($response['total_pages'] ?? null) || ! is_int($response['total'] ?? null)) throw new RuntimeException('La liste des révisions WordPress ne respecte pas le schéma attendu.');
            $items = [];
            foreach (array_slice($response['items'], 0, $perPage) as $revision) {
                if (! is_array($revision) || ! is_int($revision['id'] ?? null) || ($revision['content_id'] ?? null) !== $id) continue;
                $items[] = array_intersect_key($revision, array_flip(['id', 'content_id', 'modified_gmt', 'author_id', 'author', 'title', 'fields_hash']));
            }
            $totalPages = min(1000, $response['total_pages']);
            $complete = $page >= $totalPages;
            return $this->inspectionResult('wordpress_revisions_list', ['content_id' => $id, 'records' => $items, 'pagination' => ['page' => $page, 'per_page' => $perPage, 'total_items' => $response['total'], 'total_pages' => $totalPages]], $complete, ['count' => count($items), 'content_id' => $id]);
        }
        if ($tool === 'devpilot_wp_list_terms') {
            $taxonomy = $this->identifier($arguments['taxonomy'] ?? null, 'taxonomie');
            $page = $this->boundedInt($arguments['page'] ?? 1, 1, 50, 'page');
            $perPage = $this->boundedInt($arguments['per_page'] ?? 10, 1, 10, 'nombre de termes');
            $query = ['taxonomy' => $taxonomy, 'page' => $page, 'per_page' => $perPage];
            $search = $arguments['search'] ?? null;
            if ($search !== null && (! is_string($search) || strlen($search) > 120 || preg_match('/[\x00-\x1f\x7f]/', $search) === 1)) throw new RuntimeException('Le filtre de termes WordPress est invalide.');
            if (is_string($search) && trim($search) !== '') $query['search'] = trim($search);
            $response = $this->channel->request($connection, $credentials, 'GET', '/elchat-devpilot/v1/content/'.$type.'/terms', $query)['data'];
            if (! is_array($response['items'] ?? null) || ! is_int($response['total_pages'] ?? null)) throw new RuntimeException('La liste de termes WordPress ne respecte pas le schéma attendu.');
            $items = [];
            foreach (array_slice($response['items'], 0, $perPage) as $term) {
                if (! is_array($term) || ! is_int($term['id'] ?? null) || ! is_string($term['name'] ?? null)) continue;
                $items[] = ['id' => $term['id'], 'name' => $this->safeText($term['name'], 200), 'slug' => $this->safeText($term['slug'] ?? '', 200), 'parent' => (int) ($term['parent'] ?? 0)];
            }
            $complete = ($response['complete'] ?? false) === true && $page >= min(1000, $response['total_pages']);
            return $this->inspectionResult('wordpress_terms_list', ['taxonomy' => $taxonomy, 'records' => $items, 'pagination' => ['page' => $page, 'per_page' => $perPage, 'total_items' => $response['total'] ?? null, 'total_pages' => min(1000, $response['total_pages'])]], $complete, ['count' => count($items)], ! $complete && $page >= min(1000, $response['total_pages']));
        }
        if ($tool === 'devpilot_wp_list_page_templates') {
            if ($type !== 'page') throw new RuntimeException('Les modèles de page ne peuvent être lus que pour le type page.');
            $response = $this->channel->request($connection, $credentials, 'GET', '/elchat-devpilot/v1/content/page/templates')['data'];
            if (! is_array($response['items'] ?? null) || ($response['complete'] ?? null) !== true) throw new RuntimeException('La liste des modèles de page ne respecte pas le schéma attendu.');
            $items = [];
            foreach (array_slice($response['items'], 0, 100) as $template) {
                if (! is_array($template) || ! is_string($template['slug'] ?? null) || ! is_string($template['label'] ?? null)) continue;
                $items[] = ['slug' => $this->safeText($template['slug'], 200), 'label' => $this->safeText($template['label'], 160)];
            }
            return $this->inspectionResult('wordpress_page_templates_list', ['records' => $items], true, ['count' => count($items)]);
        }
        if ($tool === 'devpilot_wp_check_duplicate') {
            if (! isset($binding['capability_schemas']['content.search'])) {
                throw new RuntimeException('La recherche de contenu n’est pas autorisée sur le pont WordPress.');
            }
            $title = $arguments['title'] ?? null;
            $slug = $arguments['slug'] ?? null;
            if (! is_string($title) || trim($title) === '' || strlen($title) > 300
                || ! is_string($slug) || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug) !== 1) {
                throw new RuntimeException('Le titre et le slug de la vérification anti-doublon sont invalides.');
            }
            $duplicate = $this->duplicateLookup($connection, $credentials, $type, ['title' => trim($title), 'slug' => $slug]);
            $known = is_bool($duplicate['absent'] ?? null);
            $exists = ($duplicate['absent'] ?? null) === false;
            [$matches, $hiddenMatches] = $this->safeDuplicateMatches($duplicate['matches'] ?? []);
            $createdIds = [];
            foreach (is_array($createdObjects[$type] ?? null) ? $createdObjects[$type] : [] as $createdId) {
                if (is_int($createdId) && $createdId > 0) $createdIds[] = $createdId;
            }
            // Only an exact, fully visible match with objects created and verified by this very task is its own result.
            $createdByThisTask = $exists && $hiddenMatches === 0 && $matches !== []
                && array_diff(array_column($matches, 'id'), $createdIds) === [];
            return $this->inspectionResult('wordpress_content_list', [
                'exists' => $exists,
                'absent' => ($duplicate['absent'] ?? null) === true,
                'matches' => $matches,
                'hidden_matches' => $hiddenMatches,
                'created_by_this_task' => $createdByThisTask,
            ], $known, [
                'count' => $exists ? max(1, count($matches) + $hiddenMatches) : 0,
                'created_by_this_task' => $createdByThisTask,
                'match_ids' => array_values(array_map(static fn (array $match): int => $match['id'], $matches)),
            ]);
        }
        if ($tool === 'devpilot_wp_list_content') {
            $page = $this->boundedInt($arguments['page'] ?? 1, 1, 50, 'page');
            $perPage = $this->boundedInt($arguments['per_page'] ?? 10, 1, 10, 'nombre d’éléments');
            $search = $arguments['search'] ?? null;
            if ($search !== null && (! is_string($search) || strlen($search) > 120 || preg_match('/[\x00-\x1f\x7f]/', $search) === 1)) {
                throw new RuntimeException('Le filtre de recherche WordPress est invalide.');
            }
            $status = $arguments['status'] ?? 'publish';
            if (! is_string($status) || ! in_array($status, ['publish', 'draft', 'pending', 'private', 'future'], true)) throw new RuntimeException('Le statut de recherche WordPress est invalide.');
            if ($status !== 'publish') $this->assertLiveCapabilities($binding['capability_schemas'], ['content.search.unpublished']);
            $query = ['page' => $page, 'per_page' => $perPage, 'status' => $status];
            if ($search !== null && trim($search) !== '') $query['search'] = trim($search);
            $response = $this->channel->request($connection, $credentials, 'GET', '/elchat-devpilot/v1/content/'.$type, $query)['data'];
            if (! is_array($response['items'] ?? null) || ! is_int($response['page'] ?? null)
                || ! is_int($response['total_pages'] ?? null) || ! is_bool($response['complete'] ?? null)) {
                throw new RuntimeException('La liste WordPress du pont ne respecte pas le schéma attendu.');
            }
            $truncated = false;
            $records = [];
            foreach (array_slice($response['items'], 0, $perPage) as $item) {
                if (! is_array($item)) { $truncated = true; continue; }
                $records[] = $this->safeItem($item, $truncated, false);
            }
            $total = is_int($response['total'] ?? null) ? min(1000000000, $response['total']) : null;
            $totalPages = min(1000, $response['total_pages']);
            $complete = $response['complete'] && $page >= $totalPages && ! $truncated;
            return $this->inspectionResult('wordpress_content_list', [
                'records' => $records,
                'pagination' => ['page' => $page, 'per_page' => $perPage, 'total_items' => $total, 'total_pages' => $totalPages, 'has_more' => $page < $totalPages],
            ], $complete, ['entry_hashes' => array_map(static fn (array $item): string => hash('sha256', $type.':'.$item['id']), $records), 'count' => count($records)], $truncated);
        }

        $id = $this->positiveId($arguments['id'] ?? null);
        $record = $this->readItem($connection, $credentials, $type, $id);
        $truncated = false;
        $safe = $this->safeItem($record, $truncated, true);
        return $this->inspectionResult('wordpress_content_read', $safe, ! $truncated, ['status' => 'read', 'sha256' => (string) ($record['state_hash'] ?? '')], $truncated);
    }

    private function inspectPluginTool(DevPilotConnection $connection, array $credentials, array $binding, string $tool, array $arguments): array
    {
        $map = [
            'devpilot_wp_list_plugins' => ['plugins.read', '/plugins', 'wordpress_plugins_list'],
            'devpilot_wp_list_plugin_updates' => ['plugins.updates.read', '/plugins/updates', 'wordpress_plugin_updates'],
            'devpilot_wp_search_plugin_directory' => ['plugins.directory.read', '/plugins/directory', 'wordpress_plugin_directory'],
            'devpilot_wp_read_plugin_directory_info' => [['plugins.directory.read', 'plugins.install_directory', 'plugins.updates.read'], '/plugins/directory', 'wordpress_plugin_metadata'],
            'devpilot_wp_check_plugin_integrity' => ['plugins.integrity.read', '/plugins/integrity', 'wordpress_plugin_integrity'],
            'devpilot_wp_read_plugin_settings' => ['plugins.settings.read', '/plugins/settings', 'wordpress_plugin_settings'],
            'devpilot_wp_list_plugin_snapshots' => ['plugins.snapshot.create', '/plugins/snapshots', 'wordpress_plugin_snapshots'],
            'devpilot_wp_site_health' => ['site.health.read', '/site-health', 'wordpress_site_health'],
        ];
        if (! isset($map[$tool])) throw new RuntimeException('Cette inspection des extensions n’est pas disponible.');
        [$required, $route, $kind] = $map[$tool];
        if (is_array($required)) {
            $granted = array_keys($binding['capability_schemas']);
            $required = current(array_intersect($required, $granted)) ?: '';
        }
        if (! is_string($required) || $required === '') throw new RuntimeException('La permission nécessaire pour lire la fiche d’extension est absente.');
        $this->assertLiveCapabilities($binding['capability_schemas'], [$required]);
        $query = [];
        if ($tool === 'devpilot_wp_list_plugins') {
            $query = ['page' => $this->boundedInt($arguments['page'] ?? 1, 1, 100, 'page'), 'per_page' => $this->boundedInt($arguments['per_page'] ?? 100, 1, 100, 'taille de page')];
        } elseif ($tool === 'devpilot_wp_search_plugin_directory') {
            $search = trim((string) ($arguments['search'] ?? ''));
            if ($search === '' || strlen($search) > 120 || preg_match('/[\x00-\x1f\x7f]/', $search)) throw new RuntimeException('La recherche dans le répertoire WordPress.org est invalide.');
            $query['search'] = $search;
        } elseif ($tool === 'devpilot_wp_read_plugin_directory_info') {
            $slug = $this->pluginSlug($arguments['slug'] ?? '');
            $version = trim((string) ($arguments['version'] ?? ''));
            if ($version !== '' && preg_match('/^[0-9A-Za-z.+_-]{1,64}$/D', $version) !== 1) throw new RuntimeException('La version WordPress.org demandée est invalide.');
            $route .= '/'.$slug;
            if ($version !== '') $query['version'] = $version;
        } elseif (in_array($tool, ['devpilot_wp_check_plugin_integrity', 'devpilot_wp_read_plugin_settings'], true)) {
            $slug = $this->pluginSlug($arguments['slug'] ?? '');
            if ($slug !== '') $query['slug'] = $slug;
        } elseif ($tool === 'devpilot_wp_list_plugin_snapshots') {
            if (is_string($arguments['plugin_file'] ?? null) && trim($arguments['plugin_file']) !== '') {
                $query['plugin_file'] = $this->pluginFile($arguments['plugin_file']);
            } elseif (is_string($arguments['slug'] ?? null) && trim($arguments['slug']) !== '') {
                $query['slug'] = $this->pluginSlug($arguments['slug']);
            }
        }
        $response = $this->channel->request($connection, $credentials, 'GET', '/elchat-devpilot/v1'.$route, $query)['data'];
        if ($tool === 'devpilot_wp_list_plugins') {
            if (! is_array($response['items'] ?? null) || ! is_int($response['total_pages'] ?? null) || ! is_int($response['page'] ?? null)) throw new RuntimeException('L’inventaire des extensions ne respecte pas le schéma attendu.');
            $requestedFields = array_values(array_filter(is_array($arguments['fields'] ?? null) ? $arguments['fields'] : [],
                static fn ($field): bool => is_string($field) && in_array($field, self::PLUGIN_LIST_FIELDS, true)));
            $fields = array_values(array_unique([...self::PLUGIN_LIST_DEFAULT_FIELDS, ...$requestedFields]));
            $items = [];
            foreach (array_slice($response['items'], 0, 100) as $item) if (is_array($item)) $items[] = array_intersect_key($item, array_flip($fields));
            $totalPages = min(100, max(1, $response['total_pages']));
            $complete = ($response['complete'] ?? false) === true;
            return $this->inspectionResult($kind, ['records' => $items, 'pagination' => ['page' => $response['page'], 'per_page' => $response['per_page'] ?? 50, 'total_items' => $response['total'] ?? null, 'total_pages' => $totalPages]], $complete, ['count' => count($items)], ! $complete);
        }
        $safe = match ($tool) {
            'devpilot_wp_list_plugin_updates' => $this->safePluginCollection($response, ['plugin_file', 'slug', 'name', 'current_version', 'target_version', 'requires', 'tested', 'requires_php', 'wordpress_compatible', 'php_compatible', 'dependencies', 'dependencies_satisfied', 'upgrade_notice', 'source']),
            'devpilot_wp_search_plugin_directory' => $this->safePluginCollection($response, ['slug', 'name', 'version', 'description', 'author', 'requires', 'tested', 'requires_php', 'rating', 'active_installs', 'source']),
            'devpilot_wp_read_plugin_directory_info' => array_intersect_key($response, array_flip(['slug', 'name', 'latest_version', 'target_version', 'target_version_available', 'versions', 'requires', 'tested', 'requires_php', 'wordpress_compatible', 'php_compatible', 'dependencies', 'dependencies_satisfied', 'source', 'complete'])),
            'devpilot_wp_check_plugin_integrity' => array_intersect_key($response, array_flip(['slug', 'version', 'source', 'checked', 'modified_files', 'missing_files', 'complete', 'integrity'])),
            'devpilot_wp_read_plugin_settings' => $this->safePluginSettings($response),
            'devpilot_wp_list_plugin_snapshots' => $this->safePluginCollection($response, ['id', 'plugin_slug', 'plugin_file', 'plugin_version', 'archive_sha256', 'created_at']),
            default => array_intersect_key($response, array_flip(['site_url', 'wordpress_version', 'tests', 'counts', 'complete'])),
        };
        $complete = ($response['complete'] ?? false) === true;
        $count = is_array($safe['items'] ?? null) ? count($safe['items']) : (is_array($safe['tests'] ?? null) ? count($safe['tests']) : 1);
        return $this->inspectionResult($kind, $safe, $complete, ['count' => $count], ! $complete);
    }

    private function safePluginCollection(array $response, array $fields): array
    {
        $items = [];
        foreach (is_array($response['items'] ?? null) ? array_slice($response['items'], 0, 100) : [] as $item) {
            if (! is_array($item)) continue;
            $safe = array_intersect_key($item, array_flip($fields));
            foreach ($safe as $key => $value) {
                if (is_string($value)) $safe[$key] = $this->safeText($value, 1000);
                elseif ($key === 'dependencies' && is_array($value)) $safe[$key] = $this->safePluginDependencies($value);
                elseif (is_array($value) && $key === 'requires_plugins') $safe[$key] = array_slice(array_map(fn ($entry) => $this->safeText((string) $entry, 100), $value), 0, 100);
            }
            $items[] = $safe;
        }
        return ['items' => $items, 'count' => count($items), 'total' => $response['total'] ?? count($items), 'complete' => ($response['complete'] ?? false) === true];
    }

    private function safePluginSettings(array $response): array
    {
        $items = [];
        foreach (is_array($response['items'] ?? null) ? array_slice($response['items'], 0, 100) : [] as $item) {
            if (! is_array($item) || ! is_string($item['name'] ?? null) || ! is_string($item['state_hash'] ?? null)) continue;
            $items[] = ['name' => $this->safeText($item['name'], 191), 'type' => $item['type'] ?? 'unknown', 'secret' => ($item['secret'] ?? false) === true,
                'value' => ($item['secret'] ?? false) === true ? '[masqué]' : $item['value'], 'state_hash' => $item['state_hash']];
        }
        return ['slug' => $this->pluginSlug($response['slug'] ?? ''), 'items' => $items, 'notice' => is_string($response['notice'] ?? null) ? $this->safeText($response['notice'], 400) : null, 'complete' => ($response['complete'] ?? false) === true];
    }

    private function pluginSlug(mixed $value): string
    {
        if (! is_string($value) || preg_match('/^[a-z0-9][a-z0-9-]{0,99}$/D', $value) !== 1) throw new RuntimeException('Le slug d’extension est invalide.');
        return $value;
    }

    private function safePluginDependencies(array $dependencies): array
    {
        $safe = [];
        foreach (array_slice($dependencies, 0, 20) as $dependency) {
            if (! is_array($dependency) || ! is_string($dependency['slug'] ?? null)
                || preg_match('/^[a-z0-9][a-z0-9-]{0,99}$/D', $dependency['slug']) !== 1) continue;
            $safe[] = [
                'slug' => $dependency['slug'],
                'installed' => ($dependency['installed'] ?? false) === true,
                'active' => ($dependency['active'] ?? false) === true,
            ];
        }
        return $safe;
    }

    private function pluginDependencySummary(mixed $dependencies): string
    {
        if (! is_array($dependencies) || $dependencies === []) return 'Aucune dépendance déclarée.';
        $items = [];
        foreach (array_slice($dependencies, 0, 20) as $dependency) {
            if (! is_array($dependency) || ! is_string($dependency['slug'] ?? null)) continue;
            $state = ($dependency['active'] ?? false) === true ? 'active' : ((($dependency['installed'] ?? false) === true) ? 'installée mais inactive' : 'absente');
            $items[] = $this->safeText($dependency['slug'], 100).' ('.$state.')';
        }
        return $items === [] ? 'Aucune dépendance déclarée.' : implode(', ', $items);
    }

    public function prepare(DevPilotProject $project, DevPilotConnection $connection, array $credentials, array $input, ?DevPilotTask $task = null): array
    {
        $type = (string) ($input['type'] ?? '');
        if ($type === 'wordpress_manage_plugin') return $this->preparePluginOperation($project, $connection, $credentials, $input, $task);
        if (! in_array($type, ['wordpress_create_content', 'wordpress_update_content', 'wordpress_trash_content', 'wordpress_restore_content', 'wordpress_restore_revision', 'wordpress_update_media_metadata'], true)) {
            throw new RuntimeException('Le pont ELChat-DevPilot prend uniquement en charge les opérations typées de contenu. Les réglages, plugins et thèmes ne sont pas disponibles sur ce canal.');
        }
        $summary = mb_strcut(trim((string) ($input['summary'] ?? '')), 0, 500);
        if ($summary === '') throw new RuntimeException('Un résumé lisible pour approbation est obligatoire.');
        if (is_string($input['password'] ?? null) && $input['password'] !== '') $summary = str_replace($input['password'], '[mot de passe masqué]', $summary);
        $binding = $this->channel->inspectBinding($connection, $credentials);
        if ($type === 'wordpress_update_media_metadata') return $this->prepareMediaMetadata($connection, $credentials, $binding, $input, $summary);
        if ($type === 'wordpress_restore_revision') return $this->prepareRevisionRestore($connection, $credentials, $binding, $input, $summary);
        $contentType = $this->identifier($input['wordpress_type'] ?? null, 'type de contenu');
        $definition = $this->resolveType($binding['capabilities']['content_types'], $contentType);
        $attachments = in_array($type, ['wordpress_trash_content', 'wordpress_restore_content'], true) ? ['content_source' => null, 'featured_image' => null, 'media_uploads' => []]
            : $this->resolveAttachmentInputs($task, $input);
        if ($type === 'wordpress_create_content' && ($attachments['content_source']['mode'] ?? 'replace') !== 'replace') {
            throw new RuntimeException('Un nouveau contenu doit utiliser le document joint en remplacement; le mode append est réservé à une modification.');
        }
        $fields = in_array($type, ['wordpress_trash_content', 'wordpress_restore_content'], true) ? [] : $this->contentInput($input, $type === 'wordpress_create_content', $attachments['content_source'] !== null || $attachments['featured_image'] !== null);
        if (($contentType !== 'page' && array_intersect(array_keys($fields), ['parent', 'menu_order', 'template']) !== [])
            || ($contentType !== 'post' && array_key_exists('sticky', $fields))) {
            throw new RuntimeException('La structure parent/ordre/modèle ne s’applique qu’aux pages, et l’épinglage aux articles.');
        }
        if (($input['remove_featured_image'] ?? false) === true && $attachments['featured_image'] !== null) {
            throw new RuntimeException('Une seule action d’image mise en avant peut être proposée à la fois.');
        }
        if (array_key_exists('featured_media', $input) && $attachments['featured_image'] !== null) throw new RuntimeException('Choisissez une image de la médiathèque ou une pièce jointe, pas les deux.');
        if ($attachments['content_source'] !== null) $fields['content'] = (string) $attachments['content_source']['html'];
        foreach (['title' => 'title', 'content' => 'editor', 'excerpt' => 'excerpt'] as $field => $support) {
            if (array_key_exists($field, $fields) && ! in_array($support, $definition['supports'], true)) {
                throw new RuntimeException('Le type de contenu WordPress sélectionné ne prend pas en charge le champ « '.$field.' ».');
            }
        }
        if ($attachments['featured_image'] !== null && ! in_array('thumbnail', $definition['supports'], true)) {
            throw new RuntimeException('Le type de contenu WordPress ne prend pas en charge l’image mise en avant.');
        }
        $required = match ($type) {
            'wordpress_create_content' => ['write'],
            'wordpress_update_content' => ['read', 'write'],
            'wordpress_restore_content' => ['read', 'write'],
            default => ['read', 'delete'],
        };
        $route = '/elchat-devpilot/v1/content/'.$contentType;
        $before = null;
        $active = [];
        $verification = ['must_contain' => [], 'must_not_contain' => []];
        if ($type === 'wordpress_create_content') {
            if (! isset($fields['title'], $fields['slug'], $fields['content'])) {
                throw new RuntimeException('La création via le pont exige un titre, un slug et un contenu explicites.');
            }
            $duplicate = $this->duplicateCheck($connection, $credentials, $contentType, $fields);
            if (($duplicate['absent'] ?? false) !== true || ! is_string($duplicate['duplicate_check_token'] ?? null)) {
                throw new RuntimeException('Le pont n’a pas pu confirmer l’absence d’un doublon par titre ou slug; la création est bloquée.');
            }
            $active = $this->activeContentWarnings('', (string) $fields['content'], (string) $connection->site_url);
            $status = (string) ($fields['status'] ?? 'draft');
            $verification = $this->verificationConditions($input);
            $before = [];
        } else {
            $id = $this->positiveId($input['id'] ?? null);
            $route .= '/'.$id;
            $before = $this->readItem($connection, $credentials, $contentType, $id);
            $this->assertUntruncatedState($before);
            if ($type === 'wordpress_update_content') {
                if ($attachments['content_source'] !== null && $attachments['content_source']['mode'] === 'append') {
                    $existingContent = (string) ($before['content'] ?? '');
                    $sourceHtml = (string) $attachments['content_source']['html'];
                    $fields['content'] = trim($existingContent) === '' ? $sourceHtml : rtrim($existingContent)."\n\n".$sourceHtml;
                }
                if (strlen((string) ($fields['content'] ?? '')) > self::MAX_TEXT_BYTES) {
                    throw new RuntimeException('Le contenu assemblé dépasse 120 Ko; aucune modification WordPress n’est proposée.');
                }
                $prior = $this->recordFields($before);
                foreach ($fields as $key => $value) if (($prior[$key] ?? null) === $value) unset($fields[$key]);
                if ($fields === [] && $attachments['featured_image'] === null) throw new RuntimeException('La proposition ne change aucun champ WordPress.');
                if (array_key_exists('content', $fields)) $active = $this->activeContentWarnings((string) ($before['content'] ?? ''), (string) $fields['content'], (string) $connection->site_url);
                $verification = $this->verificationConditions($input);
            } elseif ($type === 'wordpress_restore_content') {
                if (($before['status'] ?? null) !== 'trash') throw new RuntimeException('La restauration exige que le contenu soit actuellement dans la corbeille.');
                $restoreStatus = (string) ($before['trashed_from_status'] ?? 'draft');
                if (! in_array($restoreStatus, ['draft', 'pending', 'private', 'future', 'publish'], true)) $restoreStatus = 'draft';
                $input['restore_status'] = $restoreStatus;
                $verification = $this->verificationConditions($input);
            }
        }

        $requiredBridge = $this->requiredBridgeCapabilities(
            $type,
            $type === 'wordpress_restore_content' ? ['restore_status' => $input['restore_status'] ?? 'draft'] : $fields,
            $binding['capability_schemas'],
            $attachments['featured_image'] !== null,
            $attachments['media_uploads'] !== [],
        );
        $this->assertLiveCapabilities($binding['capability_schemas'], $requiredBridge);

        $idempotencyKey = strtolower((string) Str::uuid());
        $schemaVersions = [];
        foreach ($requiredBridge as $capability) $schemaVersions[$capability] = 1;
        $warnings = $active['warnings'] ?? [];
        if ($type === 'wordpress_create_content') {
            $warnings[] = 'Le slug « '.$fields['slug'].' » sera utilisé; une vérification de doublon sera refaite juste avant l’écriture.';
            $warnings[] = ($fields['status'] ?? 'draft') === 'publish'
                ? 'Le contenu sera publié; les conditions de vérification publique sont affichées ci-dessous.'
                : 'Le contenu sera enregistré comme brouillon; la vérification publique est non applicable.';
        } elseif ($type === 'wordpress_trash_content') {
            $warnings[] = 'Le contenu sera déplacé dans la corbeille WordPress; aucune purge définitive n’est possible par le pont.';
        } elseif ($type === 'wordpress_restore_content') {
            $warnings[] = 'Le contenu sera restauré depuis la corbeille avec le statut « '.($input['restore_status'] ?? 'draft').' ».';
        }
        if ($active !== []) $warnings[] = 'Le contenu actif nécessite une confirmation renforcée.';
        foreach ($attachments['media_uploads'] as $media) {
            $warnings[] = 'Le média « '.$media['name'].' » ('.number_format((int) $media['size_bytes'] / 1048576, 2, ',', ' ').' Mo, SHA-256 '.$media['sha256'].') sera téléversé après approbation.';
            if ($media['secret_detected'] ?? false) $warnings[] = 'Le média contient un élément détecté comme potentiellement sensible; vérifiez son aperçu avant approbation.';
        }
        if ($attachments['content_source'] !== null) {
            $warnings[] = 'Le contenu HTML sera extrait côté serveur depuis « '.$attachments['content_source']['name'].' »; les marqueurs d’images seront remplacés par les URL des médias téléversés.';
            if ($attachments['content_source']['secret_detected'] ?? false) $warnings[] = 'Le document contient du texte détecté comme potentiellement sensible; vérifiez le contenu avant approbation.';
        }
        $after = $type === 'wordpress_update_content' ? array_replace($this->recordFields($before), $fields) : ($type === 'wordpress_restore_content' ? ['status' => $input['restore_status'] ?? 'draft'] : $fields);
        $diffBefore = $type === 'wordpress_update_content' ? $this->recordFields($before) : ($type === 'wordpress_restore_content' ? ['status' => 'trash'] : []);
        $diffAfter = $type === 'wordpress_restore_content' ? ['status' => $input['restore_status'] ?? 'draft'] : $fields;
        $publicRequired = $type !== 'wordpress_trash_content' && ($after['status'] ?? 'draft') === 'publish';
        $risk = match (true) {
            in_array($type, ['wordpress_trash_content', 'wordpress_restore_content'], true) => 'critical',
            ($active['risk'] ?? null) === 'critical' => 'critical',
            $publicRequired || ($type === 'wordpress_update_content' && (isset($fields['status']) || isset($fields['slug']))) => 'sensitive',
            default => 'project_change',
        };

        return [
            'type' => $type,
            'channel' => 'bridge',
            'protocol_version' => DevPilotWordPressBridgeChannel::PROTOCOL,
            'wordpress_type' => $contentType,
            'id' => $before['id'] ?? null,
            'restore_status' => $type === 'wordpress_restore_content' ? ($input['restore_status'] ?? 'draft') : null,
            'fields' => $fields,
            'content_source' => $attachments['content_source'] === null ? null : array_intersect_key($attachments['content_source'], array_flip(['attachment_id', 'name', 'mode', 'embedded_media_ids'])),
            'featured_image' => $attachments['featured_image'] === null ? null : array_intersect_key($attachments['featured_image'], array_flip(['attachment_id', 'name', 'alt'])),
            'media_uploads' => $attachments['media_uploads'],
            'before' => $before === [] ? null : array_intersect_key($before, array_flip([...array_keys($fields), 'featured_media', 'modified_gmt', 'status', 'slug', 'link', 'state_hash'])),
            'expected_state_hash' => is_string($before['state_hash'] ?? null) ? $before['state_hash'] : null,
            'idempotency_key' => $idempotencyKey,
            'target_installation_id' => $binding['identity']['installation_id'],
            'target_blog_id' => (int) $binding['identity']['blog_id'],
            'required_capabilities' => $required,
            'required_bridge_capabilities' => $requiredBridge,
            'required_bridge_schema_versions' => $schemaVersions,
            'operation_schema_version' => 1,
            'verification' => $verification,
            'public_verification' => $publicRequired ? 'required' : 'not_applicable',
            'risk' => $risk,
            'warnings' => array_values(array_unique($warnings)),
            'display_fields' => [
                ['label' => 'Canal', 'value' => 'Plugin ELChat-DevPilot · HMAC '.DevPilotWordPressBridgeChannel::PROTOCOL],
                ['label' => 'Type de contenu', 'value' => mb_strcut((string) $definition['label'], 0, 120).' ('.$contentType.')'],
                ['label' => 'Action', 'value' => match ($type) { 'wordpress_create_content' => 'Créer', 'wordpress_update_content' => 'Modifier', 'wordpress_restore_content' => 'Restaurer', default => 'Mettre à la corbeille' }],
            ],
            'display_diff' => $this->fieldDiff($diffBefore, $diffAfter),
            'media_previews' => array_map(static fn (array $media): array => array_intersect_key($media, array_flip(['attachment_id', 'name', 'mime_type', 'size_bytes', 'sha256', 'alt', 'preview'])), $attachments['media_uploads']),
            'summary' => $summary,
            'approval_required' => true,
            'critical_confirmation_required' => in_array($risk, ['critical', 'sensitive'], true),
        ];
    }

    private function preparePluginOperation(DevPilotProject $project, DevPilotConnection $connection, array $credentials, array $input, ?DevPilotTask $task): array
    {
        $action = (string) ($input['plugin_action'] ?? '');
        if ($action === 'install') $action = 'install_directory';
        $allowedActions = ['activate', 'deactivate', 'network_activate', 'network_deactivate', 'install_directory', 'update', 'update_bulk', 'rollback', 'autoupdate', 'delete', 'install_zip', 'settings_write', 'maintenance', 'snapshot_create', 'snapshot_restore'];
        if (! in_array($action, $allowedActions, true)) throw new RuntimeException('L’action d’extension demandée n’est pas prise en charge.');
        $summary = mb_strcut(trim((string) ($input['summary'] ?? '')), 0, 500);
        if ($summary === '') throw new RuntimeException('Un résumé lisible de l’opération d’extension est obligatoire.');
        $binding = $this->channel->inspectBinding($connection, $credentials);
        $snapshotId = '';
        $requiredBridge = ['plugins.read', match ($action) {
            'activate' => 'plugins.activate', 'deactivate' => 'plugins.deactivate',
            'network_activate', 'network_deactivate' => 'plugins.network_toggle',
            'install_directory' => 'plugins.install_directory', 'update' => 'plugins.update', 'update_bulk' => 'plugins.update_bulk',
            'rollback' => 'plugins.rollback', 'autoupdate' => 'plugins.autoupdate', 'delete' => 'plugins.delete',
            'install_zip' => 'plugins.install_zip', 'settings_write' => 'plugins.settings.write', 'maintenance' => 'plugins.maintenance',
            'snapshot_create' => 'plugins.snapshot.create', 'snapshot_restore' => 'plugins.snapshot.restore',
        }];
        if (in_array($action, ['update', 'update_bulk', 'rollback'], true)) $requiredBridge[] = 'plugins.updates.read';
        if (in_array($action, ['update', 'update_bulk', 'rollback', 'delete', 'snapshot_restore'], true)) $requiredBridge[] = 'plugins.snapshot.create';
        if ($action === 'settings_write') $requiredBridge[] = 'plugins.settings.read';
        $requiredBridge = array_values(array_unique($requiredBridge));
        $this->assertLiveCapabilities($binding['capability_schemas'], $requiredBridge);
        $schemaVersions = array_fill_keys($requiredBridge, 1);
        $inventory = $this->pluginInventory($connection, $credentials);
        $targetLabel = is_string($input['plugin_target'] ?? null) ? trim($input['plugin_target']) : trim((string) ($input['plugin_slug'] ?? ''));
        $slug = '';
        $pluginFile = '';
        $pluginTargetResolution = 'unresolved';
        $installedTargetActions = ['activate', 'deactivate', 'network_activate', 'network_deactivate', 'update', 'rollback', 'autoupdate', 'delete', 'settings_write', 'snapshot_create'];
        if (in_array($action, $installedTargetActions, true)) {
            $before = $this->resolvePluginTarget($inventory, $targetLabel, $input['plugin_file'] ?? null, $pluginTargetResolution);
            $slug = is_string($before['slug'] ?? null) ? strtolower($before['slug']) : '';
            $pluginFile = (string) $before['plugin_file'];
        } else {
            $before = null;
            if (in_array($action, ['install_directory', 'install_zip'], true)) $slug = $this->pluginSlug($input['plugin_slug'] ?? null);
        }
        if ($slug === 'elchat-devpilot') throw new RuntimeException('ELChat DevPilot est protégé contre les actions de gestion à distance.');
        if ($action === 'update' && $task !== null) {
            $resolvedUpdateSlugs = is_array(data_get($task->plan, 'tool_router.resolved_update_slugs'))
                ? array_values(array_filter(data_get($task->plan, 'tool_router.resolved_update_slugs'), 'is_string')) : [];
            if ($resolvedUpdateSlugs !== [] && ! in_array($slug, array_map('strtolower', $resolvedUpdateSlugs), true)) {
                throw new RuntimeException('Le plugin de l’opération ne correspond pas aux extensions que la demande de cette tâche a désignées.');
            }
        }
        if (in_array($action, ['network_activate', 'network_deactivate'], true) && ($binding['identity']['network_id'] ?? null) === null) {
            throw new RuntimeException('Les actions réseau ne sont disponibles que sur un multisite WordPress.');
        }
        if (defined('DISALLOW_FILE_MODS') && DISALLOW_FILE_MODS
            && in_array($action, ['install_directory', 'update', 'update_bulk', 'rollback', 'autoupdate', 'delete', 'install_zip', 'snapshot_restore'], true)) {
            throw new RuntimeException('WordPress interdit les modifications de fichiers avec DISALLOW_FILE_MODS.');
        }
        $targetVersion = trim((string) ($input['plugin_version'] ?? ''));
        $pluginFiles = [];
        $pluginSlugs = [];
        $pluginTargetResolutions = [];
        $expectedStates = [];
        $expectedTargets = [];
        $warnings = [];
        $displayDiff = [];
        $pluginApprovalFields = [];
        $risk = 'project_change';
        if (in_array($action, ['activate', 'deactivate', 'network_activate', 'network_deactivate', 'update', 'rollback', 'autoupdate', 'delete', 'settings_write', 'snapshot_create'], true) && ! is_array($before)) throw new RuntimeException('L’extension demandée n’a pas été trouvée dans l’inventaire WordPress.');
        if (is_array($before) && ($before['protected'] ?? false)) throw new RuntimeException('ELChat DevPilot et les extensions protégées ne peuvent pas être modifiés par cette opération.');
        $pluginRecoveryAction = '';
        $recoveryCapability = '';
        $recoveryBaselineHealthy = false;
        if ($action === 'activate') { $pluginRecoveryAction = 'deactivate'; $recoveryCapability = 'plugins.deactivate'; }
        elseif ($action === 'network_activate') { $pluginRecoveryAction = 'network_deactivate'; $recoveryCapability = 'plugins.network_toggle'; }
        elseif (in_array($action, ['update', 'rollback'], true) && is_array($before)) {
            if (($before['network_active'] ?? false) === true) { $pluginRecoveryAction = 'network_deactivate'; $recoveryCapability = 'plugins.network_toggle'; }
            elseif (($before['active'] ?? false) === true) { $pluginRecoveryAction = 'deactivate'; $recoveryCapability = 'plugins.deactivate'; }
        }
        if ($pluginRecoveryAction !== '' && isset($binding['capability_schemas'][$recoveryCapability])) {
            $baseline = $this->wordpress->verifyPublicPage($connection->toArray(), rtrim((string) $connection->site_url, '/').'/', [], []);
            $recoveryBaselineHealthy = ($baseline['checked'] ?? false) === true && ($baseline['verified'] ?? false) === true;
            if ($recoveryBaselineHealthy) {
                $this->assertLiveCapabilities($binding['capability_schemas'], [$recoveryCapability]);
                $requiredBridge[] = $recoveryCapability;
                $requiredBridge = array_values(array_unique($requiredBridge));
                $schemaVersions[$recoveryCapability] = 1;
                $recoveryScope = $pluginRecoveryAction === 'network_deactivate' ? 'sur tout le réseau' : 'sur ce site';
                $pluginApprovalFields[] = ['label' => 'Récupération approuvée', 'value' => 'Si les contrôles après l’opération confirment une erreur HTTP 5xx, DevPilot désactivera cette extension '.$recoveryScope.'. Le site répond actuellement normalement.'];
            } else {
                $warnings[] = 'Le site ne répond pas normalement au contrôle initial; aucune désactivation automatique ne sera déclenchée pour éviter d’attribuer une panne préexistante à cette extension.';
                $pluginRecoveryAction = '';
            }
        } elseif ($pluginRecoveryAction !== '') {
            $warnings[] = 'Aucune permission de désactivation correspondante n’est accordée; un échec HTTP 5xx sera signalé sans désactivation automatique.';
            $pluginRecoveryAction = '';
        }
        if (is_array($before)) {
            $pluginApprovalFields[] = ['label' => 'État actuel', 'value' => 'Version '.(string) ($before['version'] ?? 'inconnue').' · '.(($before['network_active'] ?? false) ? 'active sur le réseau' : (($before['active'] ?? false) ? 'active' : 'inactive')).' · mises à jour automatiques '.(($before['auto_update_enabled'] ?? false) ? 'activées' : 'désactivées')];
        }
        if (is_array($before) && is_string($before['state_hash'] ?? null)) $expectedStateHash = $before['state_hash']; else $expectedStateHash = '';
        if (in_array($action, ['update', 'rollback'], true)) {
            if ($slug === '') throw new RuntimeException('Cette extension ne possède pas de slug WordPress.org vérifiable; sa mise à jour officielle ne peut pas être préparée en sécurité.');
            $update = null;
            if ($action === 'update') {
                $updates = $this->channel->request($connection, $credentials, 'GET', '/elchat-devpilot/v1/plugins/updates')['data'];
                foreach ($updates['items'] ?? [] as $item) {
                    if (is_array($item) && is_string($item['plugin_file'] ?? null) && $item['plugin_file'] === $pluginFile) {
                        $update = $item;
                        break;
                    }
                }
                $availableTargetVersion = is_array($update) && is_string($update['target_version'] ?? null)
                    ? trim($update['target_version']) : '';
                if (preg_match('/^[0-9A-Za-z.+_-]{1,64}$/D', $availableTargetVersion) !== 1) {
                    throw new RuntimeException('WordPress ne fournit pas de version cible précise pour cette extension.');
                }
                // The signed WordPress update inspection is authoritative. If the
                // model omitted the version, freeze that exact value into the
                // approval; if it supplied another value, reject the proposal.
                if ($targetVersion === '') $targetVersion = $availableTargetVersion;
                if (preg_match('/^[0-9A-Za-z.+_-]{1,64}$/D', $targetVersion) !== 1) {
                    throw new RuntimeException('La version cible doit être précise et figée dans l’approbation.');
                }
                if ($availableTargetVersion !== $targetVersion) {
                    throw new RuntimeException('La version cible ne correspond pas à la mise à jour WordPress.org actuellement disponible.');
                }
                if (is_array($before) && is_string($before['version'] ?? null)
                    && is_string($update['current_version'] ?? null)
                    && $before['version'] !== $update['current_version']) {
                    throw new RuntimeException('La version installée a changé entre les lectures; relisez l’état du plugin avant de préparer une approbation.');
                }
            } else {
                if (preg_match('/^[0-9A-Za-z.+_-]{1,64}$/D', $targetVersion) !== 1) throw new RuntimeException('La version cible doit être précise et figée dans l’approbation.');
                if (! is_string($before['version'] ?? null) || version_compare($targetVersion, $before['version'], '>=')) throw new RuntimeException('Un retour arrière doit viser une version antérieure à la version installée.');
            }
            if ($action === 'update' && (! ($update['wordpress_compatible'] ?? false) || ! ($update['php_compatible'] ?? false) || ! ($update['dependencies_satisfied'] ?? false))) throw new RuntimeException('La mise à jour cible n’est pas compatible avec WordPress, PHP ou les dépendances actives du site.');
            if ($action === 'rollback') {
                $metadata = $this->channel->request($connection, $credentials, 'GET', '/elchat-devpilot/v1/plugins/directory/'.$slug, ['version' => $targetVersion])['data'];
                if (($metadata['target_version_available'] ?? false) !== true || ($metadata['wordpress_compatible'] ?? false) !== true
                    || ($metadata['php_compatible'] ?? false) !== true || ($metadata['dependencies_satisfied'] ?? false) !== true) throw new RuntimeException('La version de retour arrière n’est pas disponible ou ses exigences ne sont pas satisfaites.');
                $pluginApprovalFields[] = ['label' => 'Version', 'value' => (string) ($before['version'] ?? 'inconnue').' → '.$targetVersion.' · source WordPress.org'];
                $pluginApprovalFields[] = ['label' => 'Compatibilité', 'value' => 'WordPress et PHP compatibles; dépendances actives vérifiées.'];
                $pluginApprovalFields[] = ['label' => 'Dépendances', 'value' => $this->pluginDependencySummary($metadata['dependencies'] ?? [])];
            } elseif (is_array($update)) {
                $pluginApprovalFields[] = ['label' => 'Version', 'value' => (string) ($update['current_version'] ?? ($before['version'] ?? 'inconnue')).' → '.$targetVersion.' · source WordPress.org'];
                $pluginApprovalFields[] = ['label' => 'Compatibilité', 'value' => 'WordPress '.(($update['wordpress_compatible'] ?? false) ? 'compatible' : 'incompatible').' · PHP '.(($update['php_compatible'] ?? false) ? 'compatible' : 'incompatible')];
                $pluginApprovalFields[] = ['label' => 'Dépendances', 'value' => $this->pluginDependencySummary($update['dependencies'] ?? [])];
            }
            $warnings[] = 'Un point de restauration automatique sera créé avant la modification des fichiers.';
            $risk = 'critical';
        }
        if ($action === 'update_bulk') {
            $bulkTargets = $input['plugin_targets'] ?? ($input['plugin_files'] ?? null);
            if (! is_array($bulkTargets) || $bulkTargets === [] || count($bulkTargets) > 20) throw new RuntimeException('La mise à jour groupée exige une liste explicite de 1 à 20 extensions.');
            $updates = $this->channel->request($connection, $credentials, 'GET', '/elchat-devpilot/v1/plugins/updates')['data'];
            foreach (array_values(array_unique($bulkTargets)) as $target) {
                $exactFile = is_string($target) && isset($inventory[$target]) ? $target : null;
                $targetResolution = 'unresolved';
                $record = $this->resolvePluginTarget($inventory, $target, $exactFile, $targetResolution);
                $file = (string) $record['plugin_file']; $itemSlug = is_string($record['slug'] ?? null) ? strtolower($record['slug']) : '';
                if ($itemSlug === '' || ($record['protected'] ?? false) || ! is_string($record['state_hash'] ?? null)) throw new RuntimeException('Le lot contient une extension protégée ou sans identité WordPress.org vérifiable.');
                $update = null; foreach ($updates['items'] ?? [] as $candidate) if (is_array($candidate) && ($candidate['plugin_file'] ?? null) === $file) { $update = $candidate; break; }
                if (! is_array($update) || ! is_string($update['target_version'] ?? null) || $update['target_version'] === '') throw new RuntimeException('Chaque extension du lot doit disposer d’une version cible précise.');
                if (! ($update['wordpress_compatible'] ?? false) || ! ($update['php_compatible'] ?? false) || ! ($update['dependencies_satisfied'] ?? false)) throw new RuntimeException('Une extension du lot est incompatible ou requiert une dépendance inactive.');
                if (in_array($file, $pluginFiles, true)) throw new RuntimeException('Le lot contient deux fois la même extension.');
                $pluginFiles[] = $file; $pluginSlugs[$file] = $itemSlug; $pluginTargetResolutions[$file] = $targetResolution;
                $expectedStates[$file] = $record['state_hash']; $expectedTargets[$file] = $update['target_version'];
                $pluginApprovalFields[] = ['label' => $this->safeText($record['name'] ?: $itemSlug, 160), 'value' => (string) ($update['current_version'] ?? $record['version']).' → '.$update['target_version'].' · slug « '.$itemSlug.' » prioritaire · secours fichier « '.$file.' » · WordPress/PHP compatibles · dépendances : '.$this->pluginDependencySummary($update['dependencies'] ?? [])];
            }
            $warnings[] = 'Un point de restauration sera créé pour chaque extension avant la mise à jour.'; $risk = 'critical';
        }
        if ($action === 'install_directory') {
            if (preg_match('/^[0-9A-Za-z.+_-]{1,64}$/D', $targetVersion) !== 1) throw new RuntimeException('Le numéro de version WordPress.org doit être figé dans l’approbation.');
            if ($before !== null) throw new RuntimeException('L’extension est déjà installée; utilisez la mise à jour ciblée.');
            $metadata = $this->channel->request($connection, $credentials, 'GET', '/elchat-devpilot/v1/plugins/directory/'.$slug, ['version' => $targetVersion])['data'];
            if (($metadata['target_version_available'] ?? false) !== true || ($metadata['wordpress_compatible'] ?? false) !== true
                || ($metadata['php_compatible'] ?? false) !== true || ($metadata['dependencies_satisfied'] ?? false) !== true) throw new RuntimeException('La version WordPress.org demandée est absente, incompatible ou dépend d’une extension inactive.');
            $pluginApprovalFields[] = ['label' => 'Compatibilité', 'value' => 'WordPress et PHP compatibles; dépendances actives vérifiées.'];
            $pluginApprovalFields[] = ['label' => 'Dépendances requises', 'value' => $this->pluginDependencySummary($metadata['dependencies'] ?? [])];
            if (is_string($metadata['name'] ?? null) && $metadata['name'] !== '') $pluginApprovalFields[] = ['label' => 'Nom officiel', 'value' => $this->safeText($metadata['name'], 200)];
            $warnings[] = 'La source est fixée au répertoire officiel WordPress.org; l’extension sera installée à la version '.$targetVersion.'.'; $risk = 'sensitive';
        }
        if ($action === 'install_zip') {
            if (! $task || ! is_string($input['plugin_zip_attachment_id'] ?? null) || ! self::isUuid($input['plugin_zip_attachment_id'])) throw new RuntimeException('L’installation ZIP exige une pièce jointe ZIP de cette tâche.');
            $attachment = app(DevPilotTaskAttachmentService::class)->getForTask($task, $input['plugin_zip_attachment_id'], 'plugin_zip');
            $metadata = is_array($attachment->metadata) ? $attachment->metadata : [];
            if (($metadata['plugin_slug'] ?? null) !== $slug || (int) $attachment->size_bytes > DevPilotTaskAttachmentService::MAX_FILE_BYTES) throw new RuntimeException('Le ZIP doit correspondre au slug de l’extension et rester sous 10 Mo.');
            if ($before !== null) throw new RuntimeException('Cette extension est déjà installée; son remplacement par ZIP n’est pas disponible.');
            $zip = app(DevPilotTaskAttachmentService::class)->readBytes($attachment);
            $warnings[] = 'Le ZIP « '.$attachment->original_name.' » sera installé; taille '.number_format((int) $attachment->size_bytes / 1048576, 2, ',', ' ').' Mo, SHA-256 '.$attachment->sha256.'. Il contient du code exécutable et une confirmation renforcée est requise.';
            $warnings[] = 'Le serveur contrôlera le slug racine, les chemins, les liens symboliques et la taille décompressée avant l’installation.';
            $zipInfo = ['attachment_id' => (string) $attachment->id, 'name' => (string) $attachment->original_name, 'sha256' => (string) $attachment->sha256, 'size_bytes' => (int) $attachment->size_bytes, 'plugin_slug' => $slug];
            $risk = 'critical';
        } else $zipInfo = null;
        if ($action === 'delete') {
            if (($before['active'] ?? true) || ($before['network_active'] ?? false)) throw new RuntimeException('La suppression est autorisée uniquement après désactivation de l’extension.');
            $warnings[] = 'La suppression est définitive; un point de restauration automatique est créé juste avant l’opération.';
            $warnings[] = 'WordPress peut exécuter uninstall.php et supprimer les données de l’extension; la sauvegarde ne contient que ses fichiers et son état d’activation.'; $risk = 'critical';
        }
        if (in_array($action, ['activate', 'deactivate', 'network_activate', 'network_deactivate'], true)) {
            $desired = str_contains($action, 'activate') && ! str_contains($action, 'deactivate');
            $warnings[] = ($desired ? 'Activer' : 'Désactiver').' l’extension peut changer les pages du site.'; $risk = 'sensitive';
        }
        if ($action === 'snapshot_restore') {
            $snapshotId = strtolower((string) ($input['snapshot_id'] ?? ''));
            if (! self::isUuid($snapshotId)) throw new RuntimeException('Choisissez un point de restauration précis.');
            $snapshots = $this->channel->request($connection, $credentials, 'GET', '/elchat-devpilot/v1/plugins/snapshots')['data'];
            $snapshot = null; foreach ($snapshots['items'] ?? [] as $item) if (is_array($item) && ($item['id'] ?? null) === $snapshotId) { $snapshot = $item; break; }
            if (! is_array($snapshot) || ! is_string($snapshot['plugin_file'] ?? null)
                || ($snapshot['plugin_slug'] ?? '') === 'elchat-devpilot') throw new RuntimeException('Le point de restauration est absent ou protégé.');
            $pluginFile = $this->pluginFile($snapshot['plugin_file']);
            $slug = is_string($snapshot['plugin_slug'] ?? null) ? strtolower($snapshot['plugin_slug']) : '';
            $before = $inventory[$pluginFile] ?? null;
            $expectedStateHash = is_array($before) ? (string) ($before['state_hash'] ?? '') : hash('sha256', json_encode(['plugin' => null, 'active' => false, 'network_active' => false, 'auto_update' => false], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            $warnings[] = 'Un nouveau point de restauration sera créé avant le remplacement des fichiers par cette sauvegarde.'; $risk = 'critical';
        }
        if ($action === 'settings_write') {
            if ($slug === '') throw new RuntimeException('Les réglages de cette extension ne peuvent pas être identifiés dans la liste blanche.');
            $settingName = (string) ($input['setting_name'] ?? ''); $value = $input['setting_value'] ?? null;
            $settings = $this->channel->request($connection, $credentials, 'GET', '/elchat-devpilot/v1/plugins/settings', ['slug' => $slug])['data'];
            $current = null; foreach ($settings['items'] ?? [] as $item) if (is_array($item) && ($item['name'] ?? null) === $settingName) { $current = $item; break; }
            if (! is_array($current) || ! is_string($current['state_hash'] ?? null)) throw new RuntimeException('Ce réglage n’est pas exposé dans la liste blanche de cette extension.');
            if (! is_scalar($value) && ! is_array($value) || strlen(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '') > 4096) throw new RuntimeException('La valeur du réglage est invalide ou dépasse 4 Ko.');
            $expectedSettingHash = $current['state_hash']; $isSecret = ($current['secret'] ?? false) === true;
            $displayDiff['setting:'.$settingName] = ['before' => $isSecret ? '[masqué]' : (is_scalar($current['value'] ?? null) ? (string) $current['value'] : json_encode($current['value'] ?? null)), 'after' => $isSecret ? '[nouvelle valeur masquée]' : (is_scalar($value) ? (string) $value : json_encode($value))];
            $warnings[] = $isSecret ? 'La nouvelle valeur secrète ne sera jamais affichée ni relue en clair.' : 'Le serveur relira la valeur et vérifiera son empreinte après écriture.';
            $pluginSettingName = $settingName; $pluginSettingValue = $value; $risk = 'sensitive';
        } else { $pluginSettingName = ''; $pluginSettingValue = null; $expectedSettingHash = ''; }
        if ($action === 'maintenance') {
            $maintenanceAction = (string) ($input['maintenance_action'] ?? '');
            if (! in_array($maintenanceAction, ['flush_rewrite_rules', 'litespeed_purge_all'], true)) throw new RuntimeException('Action d’entretien inconnue ou non prise en charge.');
            $warnings[] = 'Seule une action d’entretien explicitement déclarée peut être lancée; aucune commande libre ne sera exécutée.'; $risk = 'sensitive';
        } else $maintenanceAction = '';
        if ($action === 'snapshot_create') $warnings[] = 'Le point de restauration contient les fichiers de l’extension et son état actif; au maximum 20 Mo et 5 000 fichiers.';
        if ($action === 'autoupdate') $displayDiff['mises_a_jour_automatiques'] = ['before' => ($before['auto_update_enabled'] ?? false) ? 'activées' : 'désactivées', 'after' => ($input['enabled'] ?? false) ? 'activées' : 'désactivées'];
        $required = $action === 'delete' ? ['read', 'delete'] : ['read', 'write'];
        $idempotencyKey = strtolower((string) Str::uuid());
        $displayFields = [
            ['label' => 'Canal', 'value' => 'Plugin ELChat-DevPilot · HMAC '.DevPilotWordPressBridgeChannel::PROTOCOL],
            ['label' => 'Opération', 'value' => $this->pluginActionLabel($action)],
            ['label' => 'Extension', 'value' => is_array($before) ? $this->safeText($before['name'] ?: ($slug ?: $pluginFile), 200) : ($action === 'update_bulk' ? implode(', ', array_map(static fn (string $file): string => (string) ($inventory[$file]['name'] ?? $file), $pluginFiles)) : ($action === 'maintenance' ? $maintenanceAction : $snapshotId ?? ''))],
        ];
        if (is_array($before)) $displayFields[] = ['label' => 'Identification', 'value' => 'Slug « '.$slug.' » prioritaire · secours fichier exact « '.$pluginFile.' »'];
        if ($targetVersion !== '') $displayFields[] = ['label' => 'Version cible', 'value' => $targetVersion.' · source WordPress.org'];
        $displayFields = [...$displayFields, ...$pluginApprovalFields];
        if ($zipInfo !== null) $displayFields[] = ['label' => 'ZIP approuvé', 'value' => $zipInfo['name'].' · SHA-256 '.$zipInfo['sha256']];
        $bridge = $binding['identity'];
        return [
            'type' => 'wordpress_manage_plugin', 'channel' => 'bridge', 'protocol_version' => DevPilotWordPressBridgeChannel::PROTOCOL,
            'operation_schema_version' => 1, 'plugin_action' => $action, 'plugin_slug' => $slug, 'plugin_file' => $pluginFile, 'plugin_version' => $targetVersion,
            'plugin_target_resolution' => $pluginTargetResolution, 'plugin_target_resolutions' => $pluginTargetResolutions,
            'plugin_current_version' => is_array($before) && is_string($before['version'] ?? null) ? $before['version'] : '',
            'plugin_files' => $pluginFiles, 'plugin_slugs' => $pluginSlugs, 'expected_states' => $expectedStates, 'expected_targets' => $expectedTargets,
            'expected_state_hash' => $expectedStateHash, 'snapshot_id' => $action === 'snapshot_restore' ? $snapshotId : '',
            'plugin_recovery_action' => $pluginRecoveryAction,
            'plugin_zip' => $zipInfo, 'setting_name' => $pluginSettingName, 'setting_value' => $pluginSettingValue,
            'expected_setting_hash' => $expectedSettingHash, 'enabled' => ($input['enabled'] ?? false) === true,
            'maintenance_action' => $maintenanceAction, 'idempotency_key' => $idempotencyKey,
            'target_installation_id' => $bridge['installation_id'], 'target_blog_id' => (int) $bridge['blog_id'],
            'required_capabilities' => $required, 'required_bridge_capabilities' => $requiredBridge,
            'required_bridge_schema_versions' => $schemaVersions, 'risk' => $risk, 'warnings' => array_values(array_unique($warnings)),
            'display_fields' => $displayFields, 'display_diff' => $displayDiff, 'summary' => $summary,
            'approval_required' => true, 'critical_confirmation_required' => in_array($risk, ['critical', 'sensitive'], true),
            'target' => 'plugin:'.($pluginFile !== '' ? $pluginFile : ($slug !== '' ? $slug : $action)),
        ];
    }

    private function pluginInventory(DevPilotConnection $connection, array $credentials): array
    {
        $items = []; $totalPages = null; $total = null;
        for ($page = 1; $page <= 100; $page++) {
            $response = $this->channel->request($connection, $credentials, 'GET', '/elchat-devpilot/v1/plugins', ['page' => $page, 'per_page' => 100])['data'];
            if (! is_array($response['items'] ?? null) || ! is_int($response['total_pages'] ?? null)
                || ! is_int($response['page'] ?? null) || $response['page'] !== $page
                || ! is_int($response['total'] ?? null) || ($response['complete'] ?? false) !== ($page >= $response['total_pages'])) {
                throw new RuntimeException('WordPress n’a pas retourné un inventaire paginé complet et cohérent des extensions.');
            }
            if (($totalPages !== null && $totalPages !== $response['total_pages']) || ($total !== null && $total !== $response['total'])) {
                throw new RuntimeException('La pagination de l’inventaire des extensions a changé pendant sa lecture.');
            }
            $totalPages = $response['total_pages']; $total = $response['total'];
            foreach ($response['items'] as $item) {
                if (! is_array($item) || ! is_string($item['plugin_file'] ?? null)) throw new RuntimeException('Une extension ne possède pas d’identité de fichier vérifiable.');
                $file = $this->pluginFile($item['plugin_file']);
                if (isset($items[$file])) throw new RuntimeException('L’inventaire contient plusieurs fois le même fichier d’extension.');
                if (! is_string($item['name'] ?? null) || ! is_string($item['version'] ?? null)
                    || ! is_bool($item['active'] ?? null) || ! is_bool($item['network_active'] ?? null)
                    || ! is_bool($item['protected'] ?? null) || ! is_string($item['state_hash'] ?? null)
                    || preg_match('/^[a-f0-9]{64}$/D', $item['state_hash']) !== 1) {
                    throw new RuntimeException('Les métadonnées signées d’une extension sont incomplètes.');
                }
                $items[$file] = $item;
            }
            if ($page >= $totalPages) break;
        }
        if ($totalPages === null || $total === null || $page !== $totalPages || count($items) !== $total) throw new RuntimeException('L’inventaire WordPress des extensions est incomplet.');
        return $items;
    }

    /** Resolve a user-facing name or a slug only against this site's signed inventory. */
    private function resolvePluginTarget(array $inventory, mixed $target, mixed $exactFile = null, ?string &$resolution = null): array
    {
        $resolution = 'unresolved';
        $file = is_string($exactFile) && trim($exactFile) !== '' ? $this->pluginFile($exactFile) : '';
        if ($file !== '' && ! isset($inventory[$file])) throw new RuntimeException('Le fichier d’extension approuvé n’existe plus dans l’inventaire signé du site.');
        if (! is_string($target) || trim($target) === '' || mb_strlen($target) > 255) throw new RuntimeException('Indiquez le nom de l’extension à gérer.');
        $target = trim($target);
        $matches = [];
        // Keep the approved fallback file immutable while scanning candidate labels.
        // Reusing `$file` as the foreach key overwrote it with the last inventory
        // entry and caused valid slug/file pairs to be rejected as mismatches.
        foreach ($inventory as $candidateFile => $record) {
            $slug = is_string($record['slug'] ?? null) ? $record['slug'] : '';
            $name = is_string($record['name'] ?? null) ? $record['name'] : '';
            $labels = [$candidateFile, basename($candidateFile, '.php')];
            if ($slug !== '') $labels[] = $slug;
            if ($name !== '') $labels[] = $name;
            foreach ($labels as $label) {
                if ($this->normalizePluginTarget($label) !== '' && $this->normalizePluginTarget($label) === $this->normalizePluginTarget($target)) {
                    $matches[$candidateFile] = $record;
                    break;
                }
            }
        }
        if (count($matches) === 1) {
            $matched = reset($matches);
            if ($file !== '' && ($matched['plugin_file'] ?? null) !== $file) {
                throw new RuntimeException('Le slug ou nom fourni et le fichier exact désignent deux extensions différentes. Aucune cible n’a été choisie.');
            }
            $resolution = 'slug';
            return $matched;
        }
        if (count($matches) > 1) {
            if ($file !== '' && isset($matches[$file])) {
                $resolution = 'plugin_file_fallback';
                return $matches[$file];
            }
            throw new RuntimeException('Le nom « '.$this->safeText($target, 120).' » correspond à plusieurs extensions installées. Aucune cible exacte n’a été confirmée.');
        }
        if ($file !== '') {
            $resolution = 'plugin_file_fallback';
            return $inventory[$file];
        }
        throw new RuntimeException('Aucune extension installée ne correspond à « '.$this->safeText($target, 120).' » dans l’inventaire signé du site.');
    }

    /** Prefer the signed slug; fall back only to its exact approved file. */
    private function resolvePluginInventoryTarget(array $inventory, mixed $slug, mixed $pluginFile): ?array
    {
        $slug = is_string($slug) ? strtolower(trim($slug)) : '';
        $file = is_string($pluginFile) && $pluginFile !== '' ? $this->pluginFile($pluginFile) : '';
        if ($slug !== '') {
            $matches = array_values(array_filter($inventory, static fn ($record): bool => is_array($record)
                && is_string($record['slug'] ?? null) && strtolower($record['slug']) === $slug));
            if (count($matches) === 1) {
                return $file === '' || ($matches[0]['plugin_file'] ?? null) === $file ? $matches[0] : null;
            }
            if (count($matches) > 1) {
                if ($file !== '') foreach ($matches as $match) if (($match['plugin_file'] ?? null) === $file) return $match;
                return null;
            }
        } else {
            $matches = [];
        }
        if ($file !== '' && isset($inventory[$file]) && is_array($inventory[$file])) return $inventory[$file];
        return $file === '' && count($matches) === 1 ? $matches[0] : null;
    }

    private function normalizePluginTarget(string $value): string
    {
        return mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '', trim($value)) ?? '');
    }

    private function pluginFile(mixed $value): string
    {
        if (! is_string($value) || $value === '' || strlen($value) > 255 || ! mb_check_encoding($value, 'UTF-8')
            || preg_match('/[\x00-\x1f\x7f\\\\:]/u', $value) === 1 || str_starts_with($value, '/') || trim($value) !== $value) {
            throw new RuntimeException('Le chemin interne de l’extension est invalide.');
        }
        foreach (explode('/', $value) as $segment) if ($segment === '' || $segment === '.' || $segment === '..') throw new RuntimeException('Le chemin interne de l’extension est invalide.');
        return $value;
    }

    private function pluginActionLabel(string $action): string
    {
        return match ($action) {
            'activate' => 'Activer', 'deactivate' => 'Désactiver', 'network_activate' => 'Activer sur le réseau', 'network_deactivate' => 'Désactiver sur le réseau',
            'install_directory' => 'Installer depuis WordPress.org', 'update' => 'Mettre à jour', 'update_bulk' => 'Mettre à jour plusieurs extensions',
            'rollback' => 'Revenir à une version antérieure', 'autoupdate' => 'Modifier les mises à jour automatiques', 'delete' => 'Supprimer',
            'install_zip' => 'Installer le ZIP approuvé', 'settings_write' => 'Modifier un réglage autorisé', 'maintenance' => 'Lancer un entretien prédéfini',
            'snapshot_create' => 'Créer un point de restauration', 'snapshot_restore' => 'Restaurer un point de restauration', default => 'Gérer une extension',
        };
    }

    private function prepareMediaMetadata(DevPilotConnection $connection, array $credentials, array $binding, array $input, string $summary): array
    {
        $allowed = ['type', 'id', 'title', 'caption', 'alt', 'summary'];
        if (array_diff(array_keys($input), $allowed) !== []) throw new RuntimeException('La modification média contient des champs non autorisés.');
        $id = $this->positiveId($input['id'] ?? null);
        $fields = [];
        foreach (['title' => 1024, 'caption' => 4096, 'alt' => 512] as $field => $maximum) {
            if (! array_key_exists($field, $input)) continue;
            $value = $input[$field];
            if (! is_string($value) || strlen($value) > $maximum || ! mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0")) throw new RuntimeException("La métadonnée média {$field} est invalide ou trop longue.");
            $fields[$field] = $value;
        }
        if ($fields === []) throw new RuntimeException('Au moins une métadonnée d’image (titre, légende ou texte alternatif) doit être fournie.');
        $this->assertLiveCapabilities($binding['capability_schemas'], ['media.read', 'media.update_metadata', 'operation.status', 'operation.readback']);
        $before = $this->channel->request($connection, $credentials, 'GET', '/elchat-devpilot/v1/media/'.$id)['data'];
        if (($before['id'] ?? null) !== $id || ! is_string($before['state_hash'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $before['state_hash']) !== 1
            || ! in_array($before['mime_type'] ?? null, ['image/jpeg', 'image/png', 'image/webp'], true)) throw new RuntimeException('L’image WordPress n’est pas relue avec une empreinte et un format pris en charge.');
        $expectedBridge = ['media.read', 'media.update_metadata', 'operation.status', 'operation.readback'];
        $versions = array_fill_keys($expectedBridge, 1);
        return [
            'type' => 'wordpress_update_media_metadata', 'channel' => 'bridge', 'protocol_version' => DevPilotWordPressBridgeChannel::PROTOCOL,
            'wordpress_type' => null, 'id' => $id, 'fields' => $fields, 'before' => array_intersect_key($before, array_flip(['id', 'title', 'caption', 'alt', 'mime_type', 'url', 'state_hash'])),
            'expected_state_hash' => $before['state_hash'], 'idempotency_key' => strtolower((string) Str::uuid()),
            'target_installation_id' => $binding['identity']['installation_id'], 'target_blog_id' => (int) $binding['identity']['blog_id'],
            'required_capabilities' => ['read', 'write'], 'required_bridge_capabilities' => $expectedBridge, 'required_bridge_schema_versions' => $versions,
            'operation_schema_version' => 1, 'risk' => 'project_change', 'warnings' => ['Seules les métadonnées approuvées de cette image seront modifiées.'],
            'display_fields' => [
                ['label' => 'Canal', 'value' => 'Plugin ELChat-DevPilot · HMAC '.DevPilotWordPressBridgeChannel::PROTOCOL],
                ['label' => 'Action', 'value' => 'Modifier les métadonnées de l’image #'.$id],
                ['label' => 'Image', 'value' => mb_strcut((string) ($before['title'] ?? ''), 0, 200)],
            ],
            'display_diff' => $this->fieldDiff(array_intersect_key($before, array_flip(array_keys($fields))), $fields),
            'summary' => $summary, 'approval_required' => true, 'critical_confirmation_required' => false,
        ];
    }

    private function prepareRevisionRestore(DevPilotConnection $connection, array $credentials, array $binding, array $input, string $summary): array
    {
        $allowed = ['type', 'wordpress_type', 'id', 'revision_id', 'summary'];
        if (array_diff(array_keys($input), $allowed) !== []) throw new RuntimeException('La restauration de révision contient des champs non autorisés.');
        $type = $this->identifier($input['wordpress_type'] ?? null, 'type de contenu');
        $this->resolveType($binding['capabilities']['content_types'], $type);
        $id = $this->positiveId($input['id'] ?? null);
        $revisionId = $this->positiveId($input['revision_id'] ?? null);
        $before = $this->readItem($connection, $credentials, $type, $id);
        $this->assertUntruncatedState($before);
        if (($before['status'] ?? null) === 'trash') throw new RuntimeException('Restaurez d’abord le contenu depuis la corbeille avant de restaurer une révision.');
        $selected = null;
        for ($page = 1; $page <= 50 && $selected === null; $page++) {
            $revisions = $this->channel->request($connection, $credentials, 'GET', '/elchat-devpilot/v1/content/'.$type.'/'.$id.'/revisions', ['page' => $page, 'per_page' => 20])['data'];
            if (! is_array($revisions['items'] ?? null) || ! is_int($revisions['total_pages'] ?? null)) throw new RuntimeException('La liste des révisions n’est pas conforme au schéma attendu.');
            foreach ($revisions['items'] as $revision) if (is_array($revision) && ($revision['id'] ?? null) === $revisionId && ($revision['content_id'] ?? null) === $id) { $selected = $revision; break; }
            if ($page >= min(50, max(1, $revisions['total_pages']))) break;
        }
        if (! is_array($selected) || ! is_string($selected['fields_hash'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $selected['fields_hash']) !== 1) throw new RuntimeException('La révision demandée n’existe pas pour cet article ou ne peut pas être vérifiée.');
        $requiredBridge = ['content.read', 'content.revisions.read', 'content.revisions.restore', 'operation.status', 'operation.readback'];
        $this->assertLiveCapabilities($binding['capability_schemas'], $requiredBridge);
        return [
            'type' => 'wordpress_restore_revision', 'channel' => 'bridge', 'protocol_version' => DevPilotWordPressBridgeChannel::PROTOCOL,
            'wordpress_type' => $type, 'id' => $id, 'revision_id' => $revisionId, 'fields' => [],
            'before' => array_intersect_key($before, array_flip(['id', 'title', 'excerpt', 'content', 'status', 'slug', 'state_hash'])),
            'revision_preview' => array_intersect_key($selected, array_flip(['id', 'modified_gmt', 'author', 'author_id', 'title'])),
            'revision_fields_hash' => $selected['fields_hash'], 'expected_state_hash' => $before['state_hash'],
            'idempotency_key' => strtolower((string) Str::uuid()), 'target_installation_id' => $binding['identity']['installation_id'],
            'target_blog_id' => (int) $binding['identity']['blog_id'], 'required_capabilities' => ['read', 'write'],
            'required_bridge_capabilities' => $requiredBridge, 'required_bridge_schema_versions' => array_fill_keys($requiredBridge, 1),
            'operation_schema_version' => 1, 'risk' => 'critical', 'warnings' => ['Le contenu sera remplacé par les champs de la révision sélectionnée. La restauration exige une confirmation renforcée.'],
            'display_fields' => [
                ['label' => 'Canal', 'value' => 'Plugin ELChat-DevPilot · HMAC '.DevPilotWordPressBridgeChannel::PROTOCOL],
                ['label' => 'Action', 'value' => 'Restaurer la révision #'.$revisionId.' de l’article #'.$id],
                ['label' => 'Révision', 'value' => mb_strcut((string) ($selected['title'] ?? ''), 0, 200).' · '.mb_strcut((string) ($selected['modified_gmt'] ?? ''), 0, 32)],
            ],
            'display_diff' => [], 'summary' => $summary, 'approval_required' => true, 'critical_confirmation_required' => true,
        ];
    }

    public function apply(DevPilotProject $project, DevPilotConnection $connection, array $credentials, array $operation, ?DevPilotTask $task = null): array
    {
        if (($operation['channel'] ?? null) !== 'bridge'
            || ($operation['protocol_version'] ?? null) !== DevPilotWordPressBridgeChannel::PROTOCOL
            || ($operation['operation_schema_version'] ?? null) !== 1) {
            throw new DevPilotAuthorizationChangedException('Le canal ou le schéma du pont WordPress ne correspond plus à la proposition approuvée.');
        }
        $type = (string) ($operation['type'] ?? '');
        if ($type === 'wordpress_manage_plugin') return $this->applyPluginOperation($project, $connection, $credentials, $operation, $task);
        if (in_array($type, ['wordpress_update_media_metadata', 'wordpress_restore_revision'], true)) {
            return $this->applySpecializedOperation($project, $connection, $credentials, $operation, $type);
        }
        if (! in_array($type, ['wordpress_create_content', 'wordpress_update_content', 'wordpress_trash_content', 'wordpress_restore_content'], true)) {
            throw new DevPilotAuthorizationChangedException('Cette opération n’est pas prise en charge par le plugin WordPress jumelé.');
        }
        $mediaUploads = is_array($operation['media_uploads'] ?? null) ? $operation['media_uploads'] : [];
        $required = match ($type) {
            'wordpress_create_content' => ['write'],
            'wordpress_update_content' => ['read', 'write'],
            'wordpress_restore_content' => ['read', 'write'],
            default => ['read', 'delete'],
        };
        $this->assertExactList($operation['required_capabilities'] ?? null, $required, 'Les permissions du canal WordPress ont changé depuis l’approbation.');
        $resolver = new DevPilotChannelResolver();
        $resolved = $resolver->resolve($project, 'rest', $required);
        if ((string) $resolved->id !== (string) $connection->id
            || ! hash_equals((string) ($operation['target_installation_id'] ?? ''), (string) ($connection->capabilities['bridge']['installation_id'] ?? ''))
            || (int) ($operation['target_blog_id'] ?? 0) !== (int) ($connection->capabilities['bridge']['blog_id'] ?? 0)) {
            throw new DevPilotAuthorizationChangedException('L’installation ou le blog WordPress a changé depuis l’approbation.');
        }
        $idempotencyKey = strtolower((string) ($operation['idempotency_key'] ?? ''));
        if (! self::isUuid($idempotencyKey)) throw new DevPilotAuthorizationChangedException('La clé d’idempotence du pont est invalide.');
        $live = $this->channel->inspectBinding($connection, $credentials);
        $operationFields = is_array($operation['fields'] ?? null) ? $operation['fields'] : [];
        $requiredBridge = $this->requiredBridgeCapabilities(
            $type,
            $type === 'wordpress_restore_content' ? ['restore_status' => $operation['restore_status'] ?? 'draft'] : $operationFields,
            $live['capability_schemas'],
            is_array($operation['featured_image'] ?? null),
            $mediaUploads !== [],
        );
        $this->assertExactList($operation['required_bridge_capabilities'] ?? null, $requiredBridge, 'Les capacités HMAC liées à cette opération ont changé depuis son approbation.');
        $expectedSchemas = $operation['required_bridge_schema_versions'] ?? null;
        if (! is_array($expectedSchemas) || array_keys($expectedSchemas) !== $requiredBridge) {
            $actualKeys = is_array($expectedSchemas) ? array_keys($expectedSchemas) : [];
            sort($actualKeys, SORT_STRING); $sortedRequired = $requiredBridge; sort($sortedRequired, SORT_STRING);
            if ($actualKeys !== $sortedRequired) throw new DevPilotAuthorizationChangedException('Les schémas de capacité liés à cette opération sont invalides.');
        }
        $this->assertLiveCapabilities($live['capability_schemas'], $requiredBridge);
        foreach ($expectedSchemas as $id => $version) {
            if (! is_int($version) || ($live['capability_schemas'][$id] ?? null) !== $version) {
                throw new DevPilotAuthorizationChangedException('Une capacité ou un schéma requis a changé depuis l’approbation.');
            }
        }

        $contentType = $this->identifier($operation['wordpress_type'] ?? null, 'type de contenu');
        $this->resolveType($live['capabilities']['content_types'], $contentType);
        $route = '/elchat-devpilot/v1/content/'.$contentType;
        $fields = is_array($operation['fields'] ?? null) ? $operation['fields'] : [];
        if (in_array($type, ['wordpress_trash_content', 'wordpress_restore_content'], true) && $mediaUploads !== []) throw new DevPilotAuthorizationChangedException('Une opération de corbeille ou de restauration ne peut pas contenir de téléversement média.');
        if ($type === 'wordpress_create_content') {
            $this->duplicateCheck($connection, $credentials, $contentType, $fields);
            $uploaded = $mediaUploads === [] ? [] : $this->uploadMediaAssets($task, $connection, $credentials, $mediaUploads);
            $fields = $this->resolveAppliedMediaFields($operation, $fields, $uploaded);
            $duplicate = $this->duplicateCheck($connection, $credentials, $contentType, $fields);
            if (($duplicate['absent'] ?? false) !== true || ! is_string($duplicate['duplicate_check_token'] ?? null)) {
                throw new RuntimeException('La vérification actuelle détecte un doublon ou ne peut pas confirmer son absence; aucune création n’a été envoyée.');
            }
            $fields['duplicate_check_token'] = $duplicate['duplicate_check_token'];
            $apiFields = $this->toBridgeFields($fields);
            $write = $this->channel->request($connection, $credentials, 'POST', $route, body: $apiFields, idempotencyKey: $idempotencyKey);
        } else {
            $id = $this->positiveId($operation['id'] ?? null);
            $route .= '/'.$id;
            $current = $this->readItem($connection, $credentials, $contentType, $id);
            $this->assertUntruncatedState($current);
            if (! is_string($operation['expected_state_hash'] ?? null)
                || ! hash_equals($operation['expected_state_hash'], (string) ($current['state_hash'] ?? ''))) {
                throw new RuntimeException('Le contenu WordPress a changé depuis sa préparation; aucune écriture n’a été envoyée.');
            }
            if ($type === 'wordpress_update_content') {
                $uploaded = $mediaUploads === [] ? [] : $this->uploadMediaAssets($task, $connection, $credentials, $mediaUploads);
                $fields = $this->resolveAppliedMediaFields($operation, $fields, $uploaded);
                $apiFields = $this->toBridgeFields($fields);
                $write = $this->channel->request($connection, $credentials, 'POST', $route, body: ['expected_state_hash' => $operation['expected_state_hash'], ...$apiFields], idempotencyKey: $idempotencyKey);
            } elseif ($type === 'wordpress_restore_content') {
                if (($current['status'] ?? null) !== 'trash') throw new RuntimeException('Le contenu n’est plus dans la corbeille; aucune restauration n’a été envoyée.');
                $write = $this->channel->request($connection, $credentials, 'POST', $route.'/restore', body: ['expected_state_hash' => $operation['expected_state_hash']], idempotencyKey: $idempotencyKey);
            } else {
                $write = $this->channel->request($connection, $credentials, 'DELETE', $route, body: ['expected_state_hash' => $operation['expected_state_hash']], idempotencyKey: $idempotencyKey);
            }
        }
        $writeData = $write['data'];
        if (($writeData['state'] ?? null) !== 'complete' || ! is_string($writeData['result_hash'] ?? null)) {
            throw new DevPilotOperationUncertainException('Le plugin WordPress n’a pas confirmé l’achèvement de la mutation. Relisez son état avant toute suite.');
        }
        $status = $this->channel->request($connection, $credentials, 'GET', '/elchat-devpilot/v1/operations/'.$idempotencyKey)['data'];
        if (($status['state'] ?? null) === 'unknown' || ($status['state'] ?? null) === 'processing') {
            throw new DevPilotOperationUncertainException('Le plugin WordPress indique un résultat incertain. La mutation ne sera pas relancée; vérifiez son état.');
        }
        if (($status['state'] ?? null) !== 'complete') throw new RuntimeException('Le plugin WordPress n’a pas conservé un état complet pour cette opération.');
        $readback = $this->channel->request($connection, $credentials, 'GET', '/elchat-devpilot/v1/operations/'.$idempotencyKey.'/readback')['data'];
        if (($readback['state'] ?? null) !== 'complete' || ! is_array($readback['item'] ?? null)
            || ! is_string($readback['result_hash'] ?? null) || ! hash_equals((string) $writeData['result_hash'], (string) $readback['result_hash'])
            || ! hash_equals((string) $readback['result_hash'], (string) ($readback['item']['state_hash'] ?? ''))) {
            throw new DevPilotOperationUncertainException('La relecture signée du contenu ne correspond pas au résultat de l’opération. Aucune relance automatique ne sera faite.');
        }

        $item = $readback['item'];
        $expectedFields = match ($type) {
            'wordpress_trash_content' => ['status' => 'trash'],
            'wordpress_restore_content' => ['status' => (string) ($operation['restore_status'] ?? 'draft')],
            default => $this->expectedReadbackFields($fields),
        };
        unset($expectedFields['duplicate_check_token'], $expectedFields['expected_state_hash']);
        $fieldsMatch = $this->matchesFields($item, $expectedFields);
        $public = ($operation['public_verification'] ?? null) === 'required'
            ? $this->publicCheck($connection, (string) ($item['link'] ?? ''), is_array($operation['verification'] ?? null) ? $operation['verification'] : [])
            : ['status' => 'not_applicable', 'verified' => false, 'conditions' => []];
        $restHash = hash('sha256', json_encode(['state_hash' => $readback['result_hash'], 'fields' => $this->rawFields($item, array_keys($expectedFields))], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $expectedHash = hash('sha256', json_encode(['fields' => $expectedFields], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $readbackMatches = $fieldsMatch && hash_equals((string) $writeData['result_hash'], (string) $readback['result_hash']);
        $verified = $readbackMatches && (($public['status'] ?? '') !== 'failed');
        return [
            'type' => $type, 'status' => $verified ? 'verified' : 'verification_failed', 'verified' => $verified,
            'execution_state' => 'completed', 'risk' => $operation['risk'] ?? 'project_change',
            'target' => 'content:'.$contentType.':'.(string) ($item['id'] ?? $operation['id'] ?? $item['slug'] ?? ''),
            'summary' => $operation['summary'] ?? '', 'rest_readback_hash' => $restHash,
            'expected_readback_hash' => $expectedHash, 'rest_readback_matches' => $readbackMatches,
            'public_verification' => $public['status'], 'public_verified' => $public['verified'],
            'public_conditions' => $public['conditions'], 'cache_stale_suspected' => $public['cache_stale_suspected'] ?? false,
        ];
    }

    private function applyPluginOperation(DevPilotProject $project, DevPilotConnection $connection, array $credentials, array $operation, ?DevPilotTask $task): array
    {
        $action = (string) ($operation['plugin_action'] ?? '');
        $required = $operation['required_capabilities'] ?? null;
        if (! is_array($required)) throw new DevPilotAuthorizationChangedException('Les droits de canal de l’opération d’extension sont invalides.');
        $resolved = (new DevPilotChannelResolver())->resolve($project, 'rest', $required);
        if ((string) $resolved->id !== (string) $connection->id
            || ! hash_equals((string) ($operation['target_installation_id'] ?? ''), (string) ($connection->capabilities['bridge']['installation_id'] ?? ''))
            || (int) ($operation['target_blog_id'] ?? 0) !== (int) ($connection->capabilities['bridge']['blog_id'] ?? 0)) throw new DevPilotAuthorizationChangedException('L’installation ou le blog WordPress a changé depuis l’approbation.');
        $live = $this->channel->inspectBinding($connection, $credentials);
        $requiredBridge = $operation['required_bridge_capabilities'] ?? null;
        if (! is_array($requiredBridge) || $requiredBridge === []) throw new DevPilotAuthorizationChangedException('Les permissions de gestion des extensions sont invalides.');
        $this->assertLiveCapabilities($live['capability_schemas'], $requiredBridge);
        $versions = $operation['required_bridge_schema_versions'] ?? null;
        if (! is_array($versions)) throw new DevPilotAuthorizationChangedException('Les versions de schéma des extensions sont invalides.');
        foreach ($requiredBridge as $capability) if (($versions[$capability] ?? null) !== 1 || ($live['capability_schemas'][$capability] ?? null) !== 1) throw new DevPilotAuthorizationChangedException('Une permission d’extension a changé depuis l’approbation.');
        $key = strtolower((string) ($operation['idempotency_key'] ?? ''));
        if (! self::isUuid($key)) throw new DevPilotAuthorizationChangedException('La clé idempotente de l’opération d’extension est invalide.');
        $slug = (string) ($operation['plugin_slug'] ?? '');
        $pluginFile = is_string($operation['plugin_file'] ?? null) && $operation['plugin_file'] !== '' ? $this->pluginFile($operation['plugin_file']) : '';
        $requiresPlugin = ! in_array($action, ['update_bulk', 'maintenance', 'snapshot_restore'], true);
        if ($requiresPlugin && $pluginFile === '' && $slug !== '') $slug = $this->pluginSlug($slug);
        $inventory = $this->pluginInventory($connection, $credentials);
        $before = $pluginFile !== '' ? $this->resolvePluginInventoryTarget($inventory, $slug, $pluginFile) : null;
        if ($before === null && $requiresPlugin && $pluginFile === '' && $slug !== '') {
            $before = $this->resolvePluginTarget($inventory, $slug);
            $pluginFile = (string) $before['plugin_file'];
        }
        if (in_array($action, ['activate', 'deactivate', 'network_activate', 'network_deactivate', 'update', 'rollback', 'autoupdate', 'delete', 'settings_write', 'snapshot_create', 'snapshot_restore'], true)) {
            $currentStateHash = is_array($before) ? (string) ($before['state_hash'] ?? '') : hash('sha256', json_encode(['plugin' => null, 'active' => false, 'network_active' => false, 'auto_update' => false], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            if (! is_string($operation['expected_state_hash'] ?? null)
                || ! hash_equals((string) ($operation['expected_state_hash'] ?? ''), $currentStateHash)) {
                throw new RuntimeException('L’état actuel de l’extension diffère de celui présenté dans l’approbation. Aucune opération n’a été envoyée.');
            }
        }
        $recoveryAction = (string) ($operation['plugin_recovery_action'] ?? '');
        if (in_array($recoveryAction, ['deactivate', 'network_deactivate'], true)) {
            $recoveryCapability = $recoveryAction === 'network_deactivate' ? 'plugins.network_toggle' : 'plugins.deactivate';
            if (! in_array($recoveryCapability, $requiredBridge, true)) throw new DevPilotAuthorizationChangedException('La récupération affichée dans l’approbation ne possède plus son autorisation.');
            $baseline = $this->wordpress->verifyPublicPage($connection->toArray(), rtrim((string) $connection->site_url, '/').'/', [], []);
            if (($baseline['checked'] ?? false) !== true || ($baseline['verified'] ?? false) !== true) {
                throw new RuntimeException('L’état public du site a changé depuis l’approbation. Aucune opération d’extension n’a été envoyée.');
            }
        }
        $body = [
            'action' => $action, 'plugin_slug' => $slug, 'plugin_file' => $pluginFile,
            'plugin_files' => is_array($operation['plugin_files'] ?? null) ? array_values($operation['plugin_files']) : [],
            'plugin_slugs' => is_array($operation['plugin_slugs'] ?? null) ? $operation['plugin_slugs'] : [],
            'expected_states' => is_array($operation['expected_states'] ?? null) ? $operation['expected_states'] : [],
            'expected_targets' => is_array($operation['expected_targets'] ?? null) ? $operation['expected_targets'] : [],
            'target_version' => (string) ($operation['plugin_version'] ?? ''), 'expected_state_hash' => (string) ($operation['expected_state_hash'] ?? ''),
            'enabled' => ($operation['enabled'] ?? false) === true, 'snapshot_id' => (string) ($operation['snapshot_id'] ?? ''),
            'setting_name' => (string) ($operation['setting_name'] ?? ''), 'setting_value' => $operation['setting_value'] ?? null,
            'expected_setting_hash' => (string) ($operation['expected_setting_hash'] ?? ''),
            'maintenance_action' => (string) ($operation['maintenance_action'] ?? ''),
        ];
        $route = '/elchat-devpilot/v1/plugins/operations'; $rawZip = null; $query = [];
        if ($action === 'install_zip') {
            $zipInfo = $operation['plugin_zip'] ?? null;
            if (! $task || ! is_array($zipInfo) || ! is_string($zipInfo['attachment_id'] ?? null) || ! self::isUuid($zipInfo['attachment_id'])) throw new DevPilotAuthorizationChangedException('La pièce jointe ZIP approuvée est absente.');
            $attachment = app(DevPilotTaskAttachmentService::class)->getForTask($task, $zipInfo['attachment_id'], 'plugin_zip');
            if ((int) $attachment->size_bytes > DevPilotTaskAttachmentService::MAX_FILE_BYTES || ! hash_equals((string) ($zipInfo['sha256'] ?? ''), (string) $attachment->sha256)
                || (string) (($attachment->metadata['plugin_slug'] ?? '') ?: '') !== $slug) throw new DevPilotAuthorizationChangedException('Le ZIP ou son empreinte a changé depuis l’approbation.');
            $rawZip = app(DevPilotTaskAttachmentService::class)->readBytes($attachment);
            $route = '/elchat-devpilot/v1/plugins/install-zip'; $query = ['slug' => $slug, 'sha256' => (string) $attachment->sha256];
        }
        $statusRoute = '/elchat-devpilot/v1/operations/'.$key;
        $status = $this->channel->request($connection, $credentials, 'GET', $statusRoute)['data'];
        if (($status['known'] ?? null) !== false) {
            if (! in_array(($status['state'] ?? null), ['complete', 'partial'], true)) {
                if (in_array(($status['state'] ?? null), ['queued', 'processing'], true)) {
                    throw new DevPilotOperationPendingException($key, 'L’opération WordPress est déjà en cours.');
                }
                throw new DevPilotOperationUncertainException('Cette opération d’extension a déjà été réservée; DevPilot ne la renverra pas. Relisez son état WordPress.');
            }
        } else {
            if (($status['state'] ?? null) !== 'unknown') throw new DevPilotOperationUncertainException('WordPress n’a pas confirmé que la clé de l’opération est vierge. Aucune relance n’est autorisée.');
            $this->channel->request($connection, $credentials, 'POST', $route, query: $query, body: $rawZip === null ? $body : null, idempotencyKey: $key, rawBody: $rawZip);
        }
        $status = null;
        $pollWindow = max(1, min(60, (int) config('devpilot.wordpress_bridge.operation_poll_window_seconds', 12)));
        $pollDeadline = microtime(true) + $pollWindow;
        do {
            $status = $this->channel->request($connection, $credentials, 'GET', $statusRoute)['data'];
            if (in_array(($status['state'] ?? null), ['complete', 'partial'], true) && ($status['known'] ?? true) === true) break;
            if (in_array($status['state'] ?? null, ['uncertain', 'failed'], true)) throw new DevPilotOperationUncertainException('WordPress n’a pas pu confirmer la fin de la gestion d’extension; aucune relance ne sera faite.');
            if (! in_array($status['state'] ?? null, ['queued', 'processing'], true)) {
                throw new DevPilotOperationUncertainException('Le pont a renvoyé un état terminal ou inconnu non reconnu; aucune relance n’est autorisée.');
            }
            if (microtime(true) >= $pollDeadline) break;
            usleep(750000);
        } while (microtime(true) < $pollDeadline);
        if (is_array($status) && in_array($status['state'] ?? null, ['queued', 'processing'], true)) {
            throw new DevPilotOperationPendingException($key, 'Le traitement WordPress est encore en attente.');
        }
        if (! is_array($status) || ! in_array(($status['state'] ?? null), ['complete', 'partial'], true) || ! is_string($status['result_hash'] ?? null)) {
            throw new DevPilotOperationUncertainException('Le traitement asynchrone WordPress a un résultat indéterminé; aucune nouvelle écriture ne sera lancée.');
        }
        $readback = $this->channel->request($connection, $credentials, 'GET', $statusRoute.'/readback')['data'];
        $item = $readback['item'] ?? null;
        $terminalState = (string) ($status['state'] ?? '');
        if (($readback['state'] ?? null) !== $terminalState || ! is_array($item) || ! is_string($readback['result_hash'] ?? null)
            || ! hash_equals((string) $status['result_hash'], $readback['result_hash']) || ! hash_equals($readback['result_hash'], (string) ($item['state_hash'] ?? ''))
            || ($terminalState === 'complete' && ($item['verified'] ?? false) !== true)) throw new DevPilotOperationUncertainException('La relecture signée de l’opération d’extension est incohérente.');
        $bridgeHealthChecked = false;
        $bridgeReady = false;
        try {
            $health = $this->channel->request($connection, $credentials, 'GET', '/elchat-devpilot/v1/bridge/health')['data'];
            $bridgeHealthChecked = true;
            $bridgeReady = ($health['status'] ?? null) === 'ready';
        } catch (Throwable) {
            $bridgeReady = false;
        }
        $terminalPartial = $terminalState === 'partial';
        $verified = ! $terminalPartial && $this->verifyPluginReadback($connection, $credentials, $operation, $item);
        $homeUrl = rtrim((string) $connection->site_url, '/').'/';
        $homeCheck = $this->wordpress->verifyPublicPage($connection->toArray(), $homeUrl, [], []);
        $homeChecked = ($homeCheck['checked'] ?? false) === true;
        $homeHealthy = $homeChecked && ($homeCheck['verified'] ?? false) === true;
        $httpStatuses = is_array($homeCheck['http_statuses'] ?? null) ? array_values($homeCheck['http_statuses']) : [];
        $confirmedServerError = count($httpStatuses) === 2 && count(array_filter($httpStatuses, static fn ($status): bool => is_int($status) && $status >= 500)) === 2;
        $recovery = ['status' => 'not_needed', 'verified' => false];
        $postRecoveryHomeCheck = null;
        $recoveryAction = (string) ($operation['plugin_recovery_action'] ?? '');
        $recoveryCapability = $recoveryAction === 'network_deactivate' ? 'plugins.network_toggle' : 'plugins.deactivate';
        if ($verified && $confirmedServerError && in_array($recoveryAction, ['deactivate', 'network_deactivate'], true)
            && in_array($recoveryCapability, $requiredBridge, true)) {
            try {
                $recovery = $this->deactivatePluginAfterHealthFailure($connection, $credentials, (string) ($operation['plugin_slug'] ?? ''), $recoveryAction, $pluginFile);
                if (($recovery['verified'] ?? false) === true) {
                    $postRecoveryHomeCheck = $this->wordpress->verifyPublicPage($connection->toArray(), $homeUrl, [], []);
                }
            } catch (Throwable) {
                $recovery = ['status' => 'uncertain', 'verified' => false];
            }
        }
        $postRecoveryHealthy = is_array($postRecoveryHomeCheck)
            && ($postRecoveryHomeCheck['checked'] ?? false) === true && ($postRecoveryHomeCheck['verified'] ?? false) === true;
        $postOperationWarnings = [];
        if (! $bridgeReady) $postOperationWarnings[] = 'Le contrôle de santé signé du pont n’a pas confirmé l’état prêt après l’opération.';
        if ($confirmedServerError && ($recovery['verified'] ?? false) === true && ($recovery['status'] ?? '') === 'deactivated') {
            $postOperationWarnings[] = $postRecoveryHealthy
                ? 'La page d’accueil a répondu HTTP 5xx après l’opération. L’extension a été désactivée selon la récupération affichée dans l’approbation, puis la page a répondu normalement.'
                : 'La page d’accueil a répondu HTTP 5xx après l’opération. L’extension a été désactivée selon l’approbation, mais l’erreur HTTP 5xx persiste.';
        } elseif ($confirmedServerError && ($recovery['verified'] ?? false) === true) {
            $postOperationWarnings[] = $postRecoveryHealthy
                ? 'La page d’accueil a répondu HTTP 5xx après l’opération; l’extension était déjà inactive et la page répond maintenant normalement.'
                : 'La page d’accueil a répondu HTTP 5xx après l’opération; l’extension était déjà inactive et l’erreur persiste.';
        } elseif ($confirmedServerError && ! $verified) {
            $postOperationWarnings[] = 'La page d’accueil a répondu HTTP 5xx, mais la relecture de l’opération n’est pas conforme; aucune désactivation automatique n’a été lancée.';
        } elseif ($confirmedServerError && in_array($recoveryAction, ['deactivate', 'network_deactivate'], true)) {
            $postOperationWarnings[] = 'La page d’accueil a répondu HTTP 5xx; la récupération approuvée n’a pas pu être confirmée. Aucune relance ne sera faite.';
        } elseif ($confirmedServerError) {
            $postOperationWarnings[] = 'La page d’accueil a répondu HTTP 5xx après l’opération; aucune récupération automatique n’était autorisée ou approuvée pour cette extension.';
        } elseif (! $homeHealthy) {
            $postOperationWarnings[] = 'Le contrôle public de la page d’accueil n’a pas confirmé un état sain. La relecture signée de l’extension reste disponible; aucune désactivation n’a été déclenchée sans erreur HTTP 5xx confirmée.';
        }
        $restHash = hash('sha256', json_encode(['state_hash' => $readback['result_hash'], 'verified' => $verified], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        return ['type' => 'wordpress_manage_plugin', 'status' => $terminalPartial ? 'partial' : ($verified ? 'verified' : 'verification_failed'), 'verified' => $verified,
            'terminal_partial' => $terminalPartial, 'execution_state' => 'completed', 'risk' => $operation['risk'] ?? 'critical', 'target' => (string) ($operation['target'] ?? 'plugin:'.$slug),
            'summary' => $operation['summary'] ?? '', 'rest_readback_hash' => $restHash,
            'expected_readback_hash' => hash('sha256', json_encode(['action' => $action, 'slug' => $slug, 'version' => $operation['plugin_version'] ?? ''], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'rest_readback_matches' => $verified, 'public_verification' => 'not_applicable', 'public_verified' => false, 'public_conditions' => [],
            'post_operation_health' => ['bridge_checked' => $bridgeHealthChecked, 'bridge_ready' => $bridgeReady, 'homepage_checked' => $homeChecked, 'homepage_healthy' => $homeHealthy,
                'http_5xx_confirmed' => $confirmedServerError, 'recovery' => $recovery['status'] ?? 'unknown', 'homepage_healthy_after_recovery' => $postRecoveryHomeCheck === null ? null : $postRecoveryHealthy],
            'warnings' => $postOperationWarnings,
            'plugin_readback' => array_intersect_key($item, array_flip(['action', 'plugin_slug', 'resolved_plugin_slug', 'plugin_file', 'target_resolution', 'plugins', 'installed', 'version', 'active', 'network_active', 'snapshot_id', 'safety_snapshot_id', 'verified']))];
    }

    private function deactivatePluginAfterHealthFailure(DevPilotConnection $connection, array $credentials, string $slug, string $action, string $pluginFile = ''): array
    {
        if (! in_array($action, ['deactivate', 'network_deactivate'], true) || $slug === 'elchat-devpilot' || $pluginFile === '') throw new RuntimeException('L’action de récupération est refusée pour cette extension.');
        $pluginFile = $this->pluginFile($pluginFile);
        $inventory = $this->pluginInventory($connection, $credentials);
        $current = $this->resolvePluginInventoryTarget($inventory, $slug, $pluginFile);
        if (! is_array($current) || ($current['protected'] ?? false) || ($current['slug'] ?? '') === 'elchat-devpilot') return ['status' => 'not_available', 'verified' => false];
        $active = ($current['active'] ?? false) === true;
        $networkActive = ($current['network_active'] ?? false) === true;
        if (($action === 'network_deactivate' && ! $networkActive) || ($action === 'deactivate' && ! $active)) return ['status' => 'already_inactive', 'verified' => true];
        $key = strtolower((string) Str::uuid());
        $this->channel->request($connection, $credentials, 'POST', '/elchat-devpilot/v1/plugins/operations', body: [
            'action' => $action, 'plugin_slug' => $slug, 'plugin_file' => $pluginFile, 'expected_state_hash' => (string) ($current['state_hash'] ?? ''),
        ], idempotencyKey: $key);
        $statusRoute = '/elchat-devpilot/v1/operations/'.$key;
        $status = null;
        for ($attempt = 0; $attempt < 40; $attempt++) {
            $status = $this->channel->request($connection, $credentials, 'GET', $statusRoute)['data'];
            if (($status['state'] ?? null) === 'complete' && ($status['known'] ?? true) === true) break;
            if (in_array($status['state'] ?? null, ['uncertain', 'failed'], true)) return ['status' => 'uncertain', 'verified' => false];
            usleep(750000);
        }
        if (! is_array($status) || ($status['state'] ?? null) !== 'complete' || ! is_string($status['result_hash'] ?? null)) return ['status' => 'timeout', 'verified' => false];
        $readback = $this->channel->request($connection, $credentials, 'GET', $statusRoute.'/readback')['data'];
        $item = $readback['item'] ?? null;
        $verified = ($readback['state'] ?? null) === 'complete' && is_array($item)
            && ($item['verified'] ?? false) === true && ($item['action'] ?? null) === $action
            && ($item['plugin_file'] ?? null) === $pluginFile && ($item['active'] ?? true) === false && ($item['network_active'] ?? true) === false
            && is_string($readback['result_hash'] ?? null) && hash_equals((string) $status['result_hash'], $readback['result_hash'])
            && hash_equals($readback['result_hash'], (string) ($item['state_hash'] ?? ''));
        return ['status' => $verified ? 'deactivated' : 'verification_failed', 'verified' => $verified];
    }

    private function verifyPluginReadback(DevPilotConnection $connection, array $credentials, array $operation, array $item): bool
    {
        $action = (string) ($operation['plugin_action'] ?? ''); $slug = (string) ($operation['plugin_slug'] ?? '');
        if (($item['action'] ?? null) !== $action || ($item['verified'] ?? false) !== true) return false;
        if ($action === 'maintenance') return true;
        $pluginFile = is_string($operation['plugin_file'] ?? null) ? $operation['plugin_file'] : '';
        if ($action === 'snapshot_create') {
            $id = (string) ($item['snapshot_id'] ?? ''); if (! self::isUuid($id)) return false;
            $snapshots = $this->channel->request($connection, $credentials, 'GET', '/elchat-devpilot/v1/plugins/snapshots', ['plugin_file' => $pluginFile])['data'];
            foreach ($snapshots['items'] ?? [] as $snapshot) {
                if (is_array($snapshot) && ($snapshot['id'] ?? null) === $id && ($snapshot['plugin_file'] ?? null) === $pluginFile
                    && is_string($item['snapshot_sha256'] ?? null) && hash_equals((string) ($snapshot['archive_sha256'] ?? ''), $item['snapshot_sha256'])) return true;
            }
            return false;
        }
        if ($action === 'snapshot_restore') {
            $id = (string) ($operation['snapshot_id'] ?? '');
            if (! self::isUuid($id) || ($item['installed'] ?? false) !== true || ($item['plugin_file'] ?? null) !== $pluginFile) return false;
            $snapshots = $this->channel->request($connection, $credentials, 'GET', '/elchat-devpilot/v1/plugins/snapshots', ['plugin_file' => $pluginFile])['data'];
            foreach ($snapshots['items'] ?? [] as $snapshot) {
                if (is_array($snapshot) && ($snapshot['id'] ?? null) === $id && ($snapshot['plugin_file'] ?? null) === $pluginFile) {
                    return is_string($snapshot['plugin_version'] ?? null) && hash_equals($snapshot['plugin_version'], (string) ($item['version'] ?? ''));
                }
            }
            return false;
        }
        if ($action === 'settings_write') {
            $settings = $this->channel->request($connection, $credentials, 'GET', '/elchat-devpilot/v1/plugins/settings', ['slug' => $slug])['data'];
            foreach ($settings['items'] ?? [] as $setting) if (is_array($setting) && ($setting['name'] ?? null) === ($operation['setting_name'] ?? null)) return is_string($setting['state_hash'] ?? null) && hash_equals((string) ($item['setting_state_hash'] ?? ''), $setting['state_hash']);
            return false;
        }
        if ($action === 'update_bulk') {
            $seen = [];
            foreach (is_array($item['plugins'] ?? null) ? $item['plugins'] : [] as $plugin) {
                $file = is_array($plugin) && is_string($plugin['plugin_file'] ?? null) ? $plugin['plugin_file'] : '';
                if ($file === '' || isset($seen[$file]) || ! in_array($file, $operation['plugin_files'] ?? [], true)
                    || ($operation['expected_targets'][$file] ?? null) !== ($plugin['version'] ?? null)) return false;
                $seen[$file] = true;
            }
            return count($seen) === count($operation['plugin_files'] ?? []);
        }
        if ($action === 'delete') return ($item['installed'] ?? true) === false && ($item['plugin_file'] ?? null) === $pluginFile;
        if (in_array($action, ['install_zip', 'install_directory'], true)) return ($item['installed'] ?? false) === true
            && ($item['plugin_slug'] ?? null) === $slug
            && ($operation['plugin_version'] === '' || ($item['version'] ?? null) === $operation['plugin_version']);
        $inventory = $this->pluginInventory($connection, $credentials); $current = $pluginFile !== '' ? ($inventory[$pluginFile] ?? null) : null;
        if (! is_array($current)) return false;
        if (($item['plugin_file'] ?? null) !== $pluginFile) return false;
        if ($action === 'activate') return ($current['active'] ?? false) === true;
        if ($action === 'deactivate') return ($current['active'] ?? false) === false && ($current['network_active'] ?? false) === false;
        if ($action === 'network_activate') return ($current['network_active'] ?? false) === true;
        if ($action === 'network_deactivate') return ($current['network_active'] ?? false) === false;
        if (in_array($action, ['update', 'rollback'], true)) return ($item['plugin_file'] ?? null) === $pluginFile
            && ($current['version'] ?? null) === ($operation['plugin_version'] ?? null);
        if ($action === 'autoupdate') return ($current['auto_update_enabled'] ?? false) === (($operation['enabled'] ?? false) === true);
        return false;
    }

    private function applySpecializedOperation(DevPilotProject $project, DevPilotConnection $connection, array $credentials, array $operation, string $type): array
    {
        $required = ['read', 'write'];
        $this->assertExactList($operation['required_capabilities'] ?? null, $required, 'Les permissions WordPress ont changé depuis l’approbation.');
        $resolved = (new DevPilotChannelResolver())->resolve($project, 'rest', $required);
        if ((string) $resolved->id !== (string) $connection->id
            || ! hash_equals((string) ($operation['target_installation_id'] ?? ''), (string) ($connection->capabilities['bridge']['installation_id'] ?? ''))
            || (int) ($operation['target_blog_id'] ?? 0) !== (int) ($connection->capabilities['bridge']['blog_id'] ?? 0)) {
            throw new DevPilotAuthorizationChangedException('L’installation ou le blog WordPress a changé depuis l’approbation.');
        }
        $idempotencyKey = strtolower((string) ($operation['idempotency_key'] ?? ''));
        if (! self::isUuid($idempotencyKey)) throw new DevPilotAuthorizationChangedException('La clé d’idempotence du pont est invalide.');
        $live = $this->channel->inspectBinding($connection, $credentials);
        $bridgeCaps = $type === 'wordpress_update_media_metadata'
            ? ['media.read', 'media.update_metadata', 'operation.status', 'operation.readback']
            : ['content.read', 'content.revisions.read', 'content.revisions.restore', 'operation.status', 'operation.readback'];
        $this->assertExactList($operation['required_bridge_capabilities'] ?? null, $bridgeCaps, 'Les capacités HMAC de cette opération ont changé depuis son approbation.');
        $versions = $operation['required_bridge_schema_versions'] ?? null;
        if (! is_array($versions)) throw new DevPilotAuthorizationChangedException('Les versions de schéma HMAC de l’opération sont invalides.');
        $this->assertExactList(array_keys($versions), $bridgeCaps, 'Les clés de schéma HMAC de l’opération sont invalides.');
        foreach ($bridgeCaps as $cap) if (($live['capability_schemas'][$cap] ?? null) !== 1 || ($versions[$cap] ?? null) !== 1) throw new DevPilotAuthorizationChangedException('Une capacité HMAC ou son schéma a changé depuis l’approbation.');

        if ($type === 'wordpress_update_media_metadata') {
            $id = $this->positiveId($operation['id'] ?? null);
            $fields = is_array($operation['fields'] ?? null) ? $operation['fields'] : [];
            if ($fields === [] || array_diff(array_keys($fields), ['title', 'caption', 'alt']) !== []) throw new DevPilotAuthorizationChangedException('Les champs média approuvés sont invalides.');
            $current = $this->channel->request($connection, $credentials, 'GET', '/elchat-devpilot/v1/media/'.$id)['data'];
            if (! is_string($operation['expected_state_hash'] ?? null) || ! hash_equals((string) $operation['expected_state_hash'], (string) ($current['state_hash'] ?? ''))) throw new RuntimeException('Les métadonnées de l’image ont changé; aucune écriture n’a été envoyée.');
            $write = $this->channel->request($connection, $credentials, 'POST', '/elchat-devpilot/v1/media/'.$id, body: ['expected_state_hash' => $operation['expected_state_hash'], ...$fields], idempotencyKey: $idempotencyKey)['data'];
            if (($write['state'] ?? null) !== 'complete' || ! is_string($write['result_hash'] ?? null)) throw new DevPilotOperationUncertainException('WordPress n’a pas confirmé la mise à jour média. Relisez son état avant toute nouvelle action.');
            $item = $this->readMutationBack($connection, $credentials, $idempotencyKey, $write['result_hash']);
            $verified = ($item['id'] ?? null) === $id && $this->matchesFields($item, $fields);
            return [
                'type' => $type, 'status' => $verified ? 'verified' : 'verification_failed', 'verified' => $verified,
                'execution_state' => 'completed', 'risk' => $operation['risk'] ?? 'project_change', 'target' => 'media:'.$id,
                'summary' => $operation['summary'] ?? '', 'rest_readback_hash' => hash('sha256', json_encode(['state_hash' => $item['state_hash'], 'fields' => $this->rawFields($item, array_keys($fields))], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
                'expected_readback_hash' => hash('sha256', json_encode(['fields' => $fields], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
                'rest_readback_matches' => $verified, 'public_verification' => 'not_applicable', 'public_verified' => false, 'public_conditions' => [],
            ];
        }

        $contentType = $this->identifier($operation['wordpress_type'] ?? null, 'type de contenu');
        $this->resolveType($live['capabilities']['content_types'], $contentType);
        $id = $this->positiveId($operation['id'] ?? null);
        $revisionId = $this->positiveId($operation['revision_id'] ?? null);
        $current = $this->readItem($connection, $credentials, $contentType, $id);
        $this->assertUntruncatedState($current);
        if (! is_string($operation['expected_state_hash'] ?? null) || ! hash_equals($operation['expected_state_hash'], (string) $current['state_hash'])) throw new RuntimeException('Le contenu a changé depuis la préparation; aucune restauration de révision n’a été envoyée.');
        $revisionExists = false;
        for ($page = 1; $page <= 50 && ! $revisionExists; $page++) {
            $revisions = $this->channel->request($connection, $credentials, 'GET', '/elchat-devpilot/v1/content/'.$contentType.'/'.$id.'/revisions', ['page' => $page, 'per_page' => 20])['data'];
            if (! is_array($revisions['items'] ?? null) || ! is_int($revisions['total_pages'] ?? null)) throw new RuntimeException('La liste des révisions a changé ou est invalide.');
            foreach ($revisions['items'] as $revision) {
                if (is_array($revision) && ($revision['id'] ?? null) === $revisionId && ($revision['content_id'] ?? null) === $id
                    && is_string($revision['fields_hash'] ?? null) && is_string($operation['revision_fields_hash'] ?? null)
                    && hash_equals($operation['revision_fields_hash'], $revision['fields_hash'])) { $revisionExists = true; break; }
            }
            if ($page >= min(50, max(1, $revisions['total_pages']))) break;
        }
        if (! $revisionExists) throw new RuntimeException('La révision approuvée n’est plus disponible ou son contenu a changé; aucune restauration n’a été envoyée.');
        $route = '/elchat-devpilot/v1/content/'.$contentType.'/'.$id.'/revisions/'.$revisionId.'/restore';
        $write = $this->channel->request($connection, $credentials, 'POST', $route, body: ['expected_state_hash' => $operation['expected_state_hash']], idempotencyKey: $idempotencyKey)['data'];
        if (($write['state'] ?? null) !== 'complete' || ! is_string($write['result_hash'] ?? null)) throw new DevPilotOperationUncertainException('WordPress n’a pas confirmé la restauration de révision. Relisez l’article avant toute nouvelle action.');
        $item = $this->readMutationBack($connection, $credentials, $idempotencyKey, $write['result_hash']);
        $verified = ($item['id'] ?? null) === $id && is_string($item['revision_fields_hash'] ?? null)
            && hash_equals((string) $operation['revision_fields_hash'], $item['revision_fields_hash']);
        return [
            'type' => $type, 'status' => $verified ? 'verified' : 'verification_failed', 'verified' => $verified,
            'execution_state' => 'completed', 'risk' => $operation['risk'] ?? 'critical', 'target' => 'content:'.$contentType.':'.$id,
            'summary' => $operation['summary'] ?? '', 'rest_readback_hash' => hash('sha256', json_encode(['state_hash' => $item['state_hash'], 'revision_fields_hash' => $item['revision_fields_hash']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
            'expected_readback_hash' => hash('sha256', json_encode(['revision_fields_hash' => $operation['revision_fields_hash']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
            'rest_readback_matches' => $verified, 'public_verification' => 'not_applicable', 'public_verified' => false, 'public_conditions' => [],
        ];
    }

    private function readMutationBack(DevPilotConnection $connection, array $credentials, string $idempotencyKey, string $expectedHash): array
    {
        $status = $this->channel->request($connection, $credentials, 'GET', '/elchat-devpilot/v1/operations/'.$idempotencyKey)['data'];
        if (in_array($status['state'] ?? null, ['unknown', 'processing'], true)) throw new DevPilotOperationUncertainException('WordPress indique un résultat incertain; la mutation ne sera pas relancée.');
        if (($status['state'] ?? null) !== 'complete') throw new RuntimeException('WordPress n’a pas conservé un état complet pour cette opération.');
        $readback = $this->channel->request($connection, $credentials, 'GET', '/elchat-devpilot/v1/operations/'.$idempotencyKey.'/readback')['data'];
        $item = $readback['item'] ?? null;
        if (($readback['state'] ?? null) !== 'complete' || ! is_array($item) || ! is_string($readback['result_hash'] ?? null)
            || ! hash_equals($expectedHash, $readback['result_hash']) || ! hash_equals($expectedHash, (string) ($item['state_hash'] ?? ''))) {
            throw new DevPilotOperationUncertainException('La relecture signée ne correspond pas au résultat de l’opération. Vérifiez l’état avant toute nouvelle action.');
        }
        return $item;
    }

    /** Build the least-privilege grant list for exactly the fields in a typed operation. */
    private function requiredBridgeCapabilities(string $type, array $fields, array $availableSchemas, bool $hasFeaturedMedia, bool $hasMediaUploads): array
    {
        $required = match ($type) {
            'wordpress_create_content' => ['content.create', 'content.search'],
            'wordpress_update_content' => ['content.read'],
            'wordpress_trash_content' => ['content.read', 'content.trash'],
            'wordpress_restore_content' => ['content.read', 'content.restore'],
            default => throw new DevPilotAuthorizationChangedException('Cette opération WordPress n’a pas de matrice de permissions connue.'),
        };

        if ($type === 'wordpress_update_content') {
            foreach ([
                'title' => 'content.update.title',
                'slug' => 'content.update.slug',
                'excerpt' => 'content.update.excerpt',
                'content' => 'content.update.body',
            ] as $field => $capability) {
                if (array_key_exists($field, $fields)) $required[] = $capability;
            }
        }

        if (array_key_exists('status', $fields) || array_key_exists('restore_status', $fields)) {
            $status = (string) ($fields['status'] ?? $fields['restore_status']);
            if ($status === 'publish') $required[] = 'content.publish';
            elseif ($status === 'private') $required[] = 'content.visibility.private';
            elseif ($status === 'future') $required[] = 'content.schedule';
            elseif ($status === 'pending' || $type === 'wordpress_update_content') $required[] = 'content.status.change';
        }
        foreach ([
            'password_encrypted' => 'content.password', 'author' => 'content.author.change',
            'comment_status' => 'content.discussion.manage', 'ping_status' => 'content.discussion.manage',
            'sticky' => 'content.discussion.manage', 'parent' => 'content.page.structure',
            'menu_order' => 'content.page.structure', 'template' => 'content.page.structure',
            'seo' => 'content.seo.manage',
        ] as $field => $capability) if (array_key_exists($field, $fields)) $required[] = $capability;
        if ($hasFeaturedMedia || (array_key_exists('featured_media', $fields) && $fields['featured_media'] !== null)) $required[] = 'content.featured_media';
        if (array_key_exists('featured_media', $fields) && $fields['featured_media'] === null) $required[] = 'content.featured_media.remove';
        if (array_key_exists('taxonomies', $fields) || (array_key_exists('create_terms', $fields) && $fields['create_terms'] !== [])) $required[] = 'content.taxonomy.assign';
        if (array_key_exists('create_terms', $fields) && $fields['create_terms'] !== []) $required[] = 'content.taxonomy.create';
        if ($hasMediaUploads) {
            $required[] = 'media.upload';
            $required[] = 'media.read';
        }
        $required[] = 'operation.status';
        $required[] = 'operation.readback';

        // Existing 1.1.x bindings retain their broad content.update grant. Prefer field-level
        // grants whenever they exist, and use the old grant only as a compatibility fallback.
        $resolved = [];
        foreach ($required as $capability) {
            if (isset($availableSchemas[$capability])) {
                $resolved[] = $capability;
                continue;
            }
            $legacyCompatible = in_array($capability, [
                'content.update.title', 'content.update.slug', 'content.update.excerpt',
                'content.update.body', 'content.status.change', 'content.featured_media',
            ], true);
            if ($legacyCompatible && isset($availableSchemas['content.update'])) {
                $resolved[] = 'content.update';
                continue;
            }
            $resolved[] = $capability;
        }

        return array_values(array_unique($resolved));
    }

    private function duplicateLookup(DevPilotConnection $connection, array $credentials, string $type, array $fields): array
    {
        $response = $this->channel->request($connection, $credentials, 'GET', '/elchat-devpilot/v1/content/'.$type.'/duplicate-check', [
            'title' => (string) ($fields['title'] ?? ''), 'slug' => (string) ($fields['slug'] ?? ''),
        ])['data'];
        if (! is_bool($response['absent'] ?? null)) throw new RuntimeException('La réponse de vérification de doublon WordPress est invalide.');
        return $response;
    }

    /** Write guard: any existing title or slug stops a creation. */
    private function duplicateCheck(DevPilotConnection $connection, array $credentials, string $type, array $fields): array
    {
        $response = $this->duplicateLookup($connection, $credentials, $type, $fields);
        if (! $response['absent']) throw new RuntimeException('Un contenu existant possède déjà ce titre ou ce slug; aucun doublon ne sera créé.');
        return $response;
    }

    /** @return array{0: array<int, array{id:int,status:string,slug:string}>, 1: int} bounded visible matches and the count of hidden ones */
    private function safeDuplicateMatches(mixed $matches): array
    {
        $safe = [];
        $hidden = 0;
        foreach (is_array($matches) ? array_slice($matches, 0, 20) : [] as $match) {
            if (! is_array($match) || ($match['hidden'] ?? false) === true) { $hidden++; continue; }
            $id = $match['id'] ?? null;
            if (! is_int($id) || $id < 1) { $hidden++; continue; }
            if (count($safe) >= 5) continue;
            $status = is_string($match['status'] ?? null) && preg_match('/^[a-z0-9_-]{1,20}$/D', $match['status']) === 1 ? $match['status'] : 'unknown';
            $slug = is_string($match['slug'] ?? null) && preg_match('/^[a-z0-9-]{1,200}$/D', $match['slug']) === 1 ? $match['slug'] : '';
            $safe[] = ['id' => $id, 'status' => $status, 'slug' => $slug];
        }
        return [$safe, $hidden];
    }

    /** Uploads use their own signed idempotency keys and are never replayed after an uncertain result. */
    private function uploadMediaAssets(?DevPilotTask $task, DevPilotConnection $connection, array $credentials, array $mediaUploads): array
    {
        if (! $task || count($mediaUploads) > 32) throw new DevPilotAuthorizationChangedException('La tâche ou le nombre de pièces jointes média est invalide.');
        $service = app(DevPilotTaskAttachmentService::class);
        $uploaded = [];
        foreach ($mediaUploads as $media) {
            if (! is_array($media) || ! is_string($media['attachment_id'] ?? null) || ! self::isUuid(strtolower($media['attachment_id']))
                || ! is_string($media['idempotency_key'] ?? null) || ! self::isUuid(strtolower($media['idempotency_key']))
                || ! is_string($media['sha256'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $media['sha256']) !== 1) {
                throw new DevPilotAuthorizationChangedException('Une référence de téléversement média approuvée est invalide.');
            }
            $attachment = $service->getForTask($task, $media['attachment_id']);
            if (! in_array($attachment->kind, ['image', 'embedded_image'], true)
                || (int) $attachment->size_bytes > DevPilotTaskAttachmentService::MAX_IMAGE_BYTES
                || ! hash_equals((string) $media['sha256'], (string) $attachment->sha256)
                || (string) ($media['mime_type'] ?? '') !== (string) $attachment->mime_type) {
                throw new DevPilotAuthorizationChangedException('Le média joint a changé depuis son approbation.');
            }
            $statusRoute = '/elchat-devpilot/v1/operations/'.$media['idempotency_key'];
            $status = $this->channel->request($connection, $credentials, 'GET', $statusRoute)['data'];
            if (($status['known'] ?? null) === true) {
                if (($status['state'] ?? null) !== 'complete') throw new DevPilotOperationUncertainException('Le téléversement média a déjà été commencé; DevPilot ne le rejouera pas. Relisez son état WordPress.');
                $readback = $this->channel->request($connection, $credentials, 'GET', $statusRoute.'/readback')['data'];
                if (($readback['state'] ?? null) !== 'complete' || ! is_array($readback['item'] ?? null)
                    || ! is_string($readback['result_hash'] ?? null) || ! hash_equals((string) ($status['result_hash'] ?? ''), $readback['result_hash'])
                    || ! hash_equals($readback['result_hash'], (string) ($readback['item']['state_hash'] ?? ''))) {
                    throw new DevPilotOperationUncertainException('La relecture signée du téléversement média est incohérente; aucun nouvel envoi ne sera fait.');
                }
                $mediaId = $this->positiveId($readback['item']['id'] ?? null);
                $remote = $this->channel->request($connection, $credentials, 'GET', '/elchat-devpilot/v1/media/'.$mediaId)['data'];
                $uploaded[(string) $attachment->id] = $this->assertUploadedMedia($remote, $media, $mediaId);
                continue;
            }
            if (($status['known'] ?? null) !== false || ($status['state'] ?? null) !== 'unknown') {
                throw new DevPilotOperationUncertainException('WordPress n’a pas pu confirmer que la clé de téléversement est vierge; aucun média ne sera renvoyé.');
            }

            $bytes = $service->readBytes($attachment);
            $query = ['name' => (string) $attachment->original_name, 'alt' => (string) ($media['alt'] ?? '')];
            $this->channel->request($connection, $credentials, 'POST', '/elchat-devpilot/v1/media', query: $query,
                idempotencyKey: $media['idempotency_key'], rawBody: $bytes);
            $status = $this->channel->request($connection, $credentials, 'GET', $statusRoute)['data'];
            if (($status['known'] ?? null) !== true || ($status['state'] ?? null) !== 'complete') {
                throw new DevPilotOperationUncertainException('WordPress n’a pas confirmé la fin du téléversement média; DevPilot ne le relancera pas.');
            }
            $readback = $this->channel->request($connection, $credentials, 'GET', $statusRoute.'/readback')['data'];
            if (($readback['state'] ?? null) !== 'complete' || ! is_array($readback['item'] ?? null)
                || ! is_string($readback['result_hash'] ?? null) || ! hash_equals((string) ($status['result_hash'] ?? ''), $readback['result_hash'])
                || ! hash_equals($readback['result_hash'], (string) ($readback['item']['state_hash'] ?? ''))) {
                throw new DevPilotOperationUncertainException('WordPress n’a pas pu relire le média téléversé; aucun nouvel envoi ne sera fait.');
            }
            $mediaId = $this->positiveId($readback['item']['id'] ?? null);
            $remote = $this->channel->request($connection, $credentials, 'GET', '/elchat-devpilot/v1/media/'.$mediaId)['data'];
            $uploaded[(string) $attachment->id] = $this->assertUploadedMedia($remote, $media, $mediaId);
        }
        return $uploaded;
    }

    private function assertUploadedMedia(array $remote, array $expected, int $mediaId): array
    {
        if ((int) ($remote['id'] ?? 0) !== $mediaId || ! is_string($remote['url'] ?? null)
            || ! is_string($remote['sha256'] ?? null) || ! hash_equals((string) $expected['sha256'], $remote['sha256'])
            || ! is_string($remote['state_hash'] ?? null)) {
            throw new DevPilotOperationUncertainException('L’empreinte ou les métadonnées du média WordPress ne correspondent pas au fichier approuvé.');
        }
        return ['id' => $mediaId, 'url' => $remote['url'], 'sha256' => $remote['sha256']];
    }

    private function resolveAppliedMediaFields(array $operation, array $fields, array $uploaded): array
    {
        if (isset($fields['content']) && is_string($fields['content'])) {
            $fields['content'] = preg_replace_callback('/\{\{media:([0-9a-f-]{36})\}\}/', static function (array $match) use ($uploaded, $operation): string {
                $attachmentId = strtolower($match[1]);
                $media = $uploaded[$attachmentId] ?? null;
                if (! is_array($media) || ! is_string($media['url'] ?? null)) throw new RuntimeException('Le document référence une image qui n’a pas été téléversée.');
                $alt = '';
                foreach (is_array($operation['media_uploads'] ?? null) ? $operation['media_uploads'] : [] as $item) {
                    if (is_array($item) && strtolower((string) ($item['attachment_id'] ?? '')) === $attachmentId) { $alt = (string) ($item['alt'] ?? ''); break; }
                }
                return '<img src="'.htmlspecialchars($media['url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'" alt="'.htmlspecialchars($alt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'">';
            }, $fields['content']);
            if (! is_string($fields['content']) || preg_match('/\{\{media:/', $fields['content']) === 1 || strlen($fields['content']) > 120 * 1024) {
                throw new RuntimeException('Le contenu assemblé dépasse la limite ou contient une image introuvable; aucune écriture du contenu n’a été envoyée.');
            }
        }
        $featured = $operation['featured_image'] ?? null;
        if ($featured !== null) {
            $id = strtolower((string) ($featured['attachment_id'] ?? ''));
            if (! isset($uploaded[$id]['id']) || ! is_int($uploaded[$id]['id'])) throw new RuntimeException('L’image mise en avant n’a pas été téléversée.');
            $fields['featured_media'] = $uploaded[$id]['id'];
        }
        return $fields;
    }

    private function readItem(DevPilotConnection $connection, array $credentials, string $type, int $id): array
    {
        $data = $this->channel->request($connection, $credentials, 'GET', '/elchat-devpilot/v1/content/'.$type.'/'.$id)['data'];
        if (! is_array($data['item'] ?? null)) throw new RuntimeException('La relecture WordPress n’a pas retourné le contenu demandé.');
        return $data['item'];
    }

    /** Resolve attachment IDs against this task; document bytes never come from the model. */
    private function resolveAttachmentInputs(?DevPilotTask $task, array $input): array
    {
        $service = app(DevPilotTaskAttachmentService::class);
        $contentSource = null;
        $featuredImage = null;
        $uploads = [];

        if (isset($input['content_source'])) {
            $source = $input['content_source'];
            if (! is_array($source) || ! is_string($source['attachment_id'] ?? null)
                || ! self::isUuid(strtolower($source['attachment_id']))
                || ! in_array($source['mode'] ?? null, ['replace', 'append'], true) || ! $task) {
                throw new RuntimeException('La source de contenu jointe est invalide ou n’appartient pas à cette tâche.');
            }
            $document = $service->getForTask($task, $source['attachment_id'], 'document');
            $html = (string) ($document->extracted_content ?? '');
            if ($html === '' || strlen($html) > DevPilotTaskAttachmentService::MAX_EXTRACTED_BYTES) {
                throw new RuntimeException('Le document joint ne contient pas de contenu extrait exploitable.');
            }
            $metadata = is_array($document->metadata) ? $document->metadata : [];
            $embeddedIds = array_values(array_filter($metadata['embedded_media_ids'] ?? [], 'is_string'));
            $usedIds = [];
            foreach ($embeddedIds as $embeddedId) {
                if (str_contains($html, '{{media:'.$embeddedId.'}}')) $usedIds[] = $embeddedId;
            }
            $contentSource = [
                'attachment_id' => (string) $document->id,
                'name' => (string) $document->original_name,
                'mode' => $source['mode'],
                'html' => $html,
                'embedded_media_ids' => $usedIds,
                'secret_detected' => (bool) ($metadata['secret_detected'] ?? false),
            ];
            foreach ($usedIds as $embeddedId) {
                $embedded = $service->getForTask($task, $embeddedId, 'embedded_image');
                if ((string) $embedded->parent_attachment_id !== (string) $document->id) {
                    throw new RuntimeException('Une image intégrée ne correspond pas au document sélectionné.');
                }
                $uploads[(string) $embedded->id] = $this->mediaUploadDescription($embedded, is_array($embedded->metadata) ? (string) ($embedded->metadata['alt'] ?? '') : '', $service);
            }
        }

        if (isset($input['featured_image'])) {
            $image = $input['featured_image'];
            if (! is_array($image) || ! is_string($image['attachment_id'] ?? null)
                || ! self::isUuid(strtolower($image['attachment_id']))
                || (array_key_exists('alt', $image) && (! is_string($image['alt']) || strlen($image['alt']) > 512 || ! mb_check_encoding($image['alt'], 'UTF-8'))) || ! $task) {
                throw new RuntimeException('La référence de l’image mise en avant est invalide.');
            }
            $attachment = $service->getForTask($task, $image['attachment_id'], 'image');
            $alt = trim(strip_tags((string) ($image['alt'] ?? '')));
            if ($alt === '') {
                $alt = preg_replace('/[_-]+/u', ' ', pathinfo((string) $attachment->original_name, PATHINFO_FILENAME)) ?: 'Image mise en avant';
                $alt = trim($alt);
            }
            $featuredImage = ['attachment_id' => (string) $attachment->id, 'name' => (string) $attachment->original_name, 'alt' => $alt];
            $uploads[(string) $attachment->id] = $this->mediaUploadDescription($attachment, $alt, $service);
        }

        return ['content_source' => $contentSource, 'featured_image' => $featuredImage, 'media_uploads' => array_values($uploads)];
    }

    private function mediaUploadDescription(\App\Models\DevPilot\DevPilotTaskAttachment $attachment, string $alt, DevPilotTaskAttachmentService $service): array
    {
        return [
            'attachment_id' => (string) $attachment->id,
            'name' => (string) $attachment->original_name,
            'mime_type' => (string) $attachment->mime_type,
            'size_bytes' => (int) $attachment->size_bytes,
            'sha256' => (string) $attachment->sha256,
            'alt' => mb_strcut($alt, 0, 512, 'UTF-8'),
            'secret_detected' => (bool) (is_array($attachment->metadata) ? ($attachment->metadata['secret_detected'] ?? false) : false),
            'preview' => $service->previewImage($attachment),
            'idempotency_key' => strtolower((string) Str::uuid()),
        ];
    }

    private function contentInput(array $input, bool $create, bool $allowMediaOnly = false): array
    {
        $fields = [];
        foreach (self::CONTENT_FIELDS as $field) {
            if (! array_key_exists($field, $input)) continue;
            $value = $input[$field];
            if (in_array($field, ['taxonomies', 'create_terms'], true)) {
                if (! is_array($value) || count($value) > 12) throw new RuntimeException('La sélection de taxonomies WordPress est invalide.');
                $normalized = [];
                foreach ($value as $taxonomy => $items) {
                    if (! is_string($taxonomy) || preg_match('/^[a-z0-9_-]{1,32}$/D', $taxonomy) !== 1 || ! is_array($items) || count($items) > 100) {
                        throw new RuntimeException('Une taxonomie ou sa liste de termes est invalide.');
                    }
                    foreach ($items as $item) {
                        if ($field === 'taxonomies') {
                            if ((! is_int($item) && !(is_string($item) && ctype_digit($item))) || (int) $item < 1) throw new RuntimeException('Un identifiant de terme WordPress est invalide.');
                            $normalized[$taxonomy][] = (int) $item;
                        } else {
                            if (! is_string($item) || trim(strip_tags($item)) === '' || strlen($item) > 200) throw new RuntimeException('Un nouveau nom de terme WordPress est invalide.');
                            $normalized[$taxonomy][] = trim(strip_tags($item));
                        }
                    }
                    $normalized[$taxonomy] = array_values(array_unique($normalized[$taxonomy] ?? []));
                }
                $fields[$field] = $normalized;
                continue;
            }
            if (in_array($field, ['author', 'parent', 'menu_order'], true)) {
                $minimum = $field === 'parent' ? 0 : ($field === 'menu_order' ? -100000 : 1);
                $maximum = $field === 'menu_order' ? 100000 : 2147483647;
                if (! is_int($value) || $value < $minimum || $value > $maximum) throw new RuntimeException("Le champ WordPress {$field} doit être un entier dans la plage autorisée.");
                $fields[$field] = $value;
                continue;
            }
            if ($field === 'sticky') {
                if (! is_bool($value)) throw new RuntimeException('Le champ sticky doit être booléen.');
                $fields[$field] = $value;
                continue;
            }
            if ($field === 'seo') {
                $allowedSeo = ['yoast_title', 'yoast_description', 'rank_math_title', 'rank_math_description'];
                if (! is_array($value) || $value === [] || count($value) > 4 || array_diff(array_keys($value), $allowedSeo) !== []) throw new RuntimeException('Les champs SEO ne respectent pas la liste blanche.');
                foreach ($value as $key => $text) {
                    $max = str_ends_with((string) $key, 'description') ? 500 : 255;
                    if (! is_string($text) || strlen($text) > $max || ! mb_check_encoding($text, 'UTF-8') || str_contains($text, "\0")) throw new RuntimeException('Une valeur SEO est invalide ou trop longue.');
                    $value[$key] = trim($text);
                }
                $fields[$field] = $value;
                continue;
            }
            $maximum = match ($field) { 'title' => 1024, 'excerpt' => 4096, 'password' => 255, 'template' => 200, 'comment_status', 'ping_status' => 6, default => self::MAX_TEXT_BYTES };
            if (! is_string($value) || strlen($value) > $maximum || ! mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0")) {
                throw new RuntimeException("Le champ WordPress {$field} est invalide ou trop long.");
            }
            if ($field === 'password') {
                if (preg_match('/[\x00-\x1f\x7f]/', $value) === 1) throw new RuntimeException('Le mot de passe de protection contient des caractères interdits.');
                try { $fields['password_encrypted'] = Crypt::encryptString($value); }
                catch (\Throwable) { throw new RuntimeException('Le chiffrement du mot de passe WordPress est indisponible; aucune opération n’est proposée.'); }
                continue;
            }
            if ($field === 'title' && trim($value) === '') throw new RuntimeException('Le titre WordPress ne peut pas être vide.');
            if ($field === 'slug' && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $value) !== 1) throw new RuntimeException('Le slug WordPress doit être en minuscules et utiliser des lettres, chiffres et tirets.');
            if ($field === 'status' && ! in_array($value, ['draft', 'pending', 'publish', 'future', 'private'], true)) throw new RuntimeException('Le pont autorise uniquement les statuts draft, pending, publish, future et private.');
            if (in_array($field, ['comment_status', 'ping_status'], true) && ! in_array($value, ['open', 'closed'], true)) throw new RuntimeException('Les états des commentaires et pings doivent être open ou closed.');
            if ($field === 'template' && $value !== '' && ! preg_match('/^[A-Za-z0-9_\/-]{1,200}$/D', $value)) throw new RuntimeException('Le modèle de page est invalide.');
            if ($field === 'date_gmt' && (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $value) !== 1 || strtotime($value) === false)) throw new RuntimeException('La date de planification doit être un instant UTC ISO-8601 explicite.');
            $fields[$field] = $value;
        }
        if (array_key_exists('featured_media', $input)) {
            if ((! is_int($input['featured_media']) && !(is_string($input['featured_media']) && ctype_digit($input['featured_media']))) || (int) $input['featured_media'] < 1) throw new RuntimeException('L’identifiant du média WordPress existant est invalide.');
            $fields['featured_media'] = (int) $input['featured_media'];
        }
        if (($input['remove_featured_image'] ?? false) === true) $fields['featured_media'] = null;
        elseif (array_key_exists('remove_featured_image', $input) && $input['remove_featured_image'] !== false) throw new RuntimeException('L’indicateur de retrait de l’image mise en avant doit être booléen.');
        if (($input['remove_featured_image'] ?? false) === true && array_key_exists('featured_media', $input)) throw new RuntimeException('L’image mise en avant ne peut pas être définie et retirée dans la même opération.');
        if (($fields['status'] ?? null) === 'future' && ! isset($fields['date_gmt'])) throw new RuntimeException('La publication planifiée exige une date date_gmt explicite.');
        if (isset($fields['date_gmt']) && ($fields['status'] ?? null) !== 'future') throw new RuntimeException('date_gmt ne peut être fourni que pour une publication planifiée.');
        if ($create && ! isset($fields['status'])) $fields['status'] = 'draft';
        if ($fields === [] && ! $allowMediaOnly) throw new RuntimeException('Au moins un champ de contenu autorisé est requis.');
        return $fields;
    }

    /** Decrypt secrets only immediately before sending the signed write request. */
    private function toBridgeFields(array $fields): array
    {
        if (array_key_exists('password_encrypted', $fields)) {
            try { $fields['password'] = Crypt::decryptString((string) $fields['password_encrypted']); }
            catch (\Throwable) { throw new DevPilotAuthorizationChangedException('Le mot de passe approuvé ne peut pas être déchiffré; aucune écriture n’a été envoyée.'); }
            unset($fields['password_encrypted']);
        }
        return $fields;
    }

    /** Convert write-only secrets to the safe, observable value that WordPress returns. */
    private function expectedReadbackFields(array $fields): array
    {
        if (array_key_exists('password_encrypted', $fields)) {
            try { $password = Crypt::decryptString((string) $fields['password_encrypted']); }
            catch (\Throwable) { throw new DevPilotAuthorizationChangedException('Le mot de passe approuvé ne peut pas être déchiffré pour sa vérification.'); }
            unset($fields['password_encrypted']);
            $fields['password_protected'] = $password !== '';
        }
        return $fields;
    }

    private function verificationConditions(array $input): array
    {
        $result = [];
        foreach (['must_contain', 'must_not_contain'] as $key) {
            $values = $input[$key] ?? [];
            if (! is_array($values) || count($values) > 8) throw new RuntimeException('Chaque liste de vérification accepte au plus huit conditions.');
            foreach ($values as $value) {
                if (! is_string($value) || trim($value) === '' || strlen($value) > 256 || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) throw new RuntimeException('Une condition de vérification publique est invalide.');
                $result[$key][] = trim($value);
            }
            $result[$key] ??= [];
        }
        return $result;
    }

    private function activeContentWarnings(string $before, string $after, string $siteUrl): array
    {
        $beforeDecoded = html_entity_decode($before, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $afterDecoded = html_entity_decode($after, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $patterns = [
            'script' => '/<\s*script\b[^>]*>/i',
            'iframe' => '/<\s*iframe\b[^>]*>/i',
            'event_handler' => '/\bon[a-z][a-z0-9_-]*\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i',
            'active_url' => '/\b(?:javascript|data)\s*:\s*(?:"[^"]*"|\'[^\']*\'|[^\s"\'<>]*)/i',
        ];
        $warnings = [];
        $critical = false;
        foreach ($patterns as $label => $pattern) {
            preg_match_all($pattern, $beforeDecoded, $beforeMatches);
            preg_match_all($pattern, $afterDecoded, $afterMatches);
            if ($this->hasAddedMatch($beforeMatches[0] ?? [], $afterMatches[0] ?? [])) {
                $critical = true;
                $warnings[] = 'Le diff ajoute du contenu actif ('.$label.'); confirmation renforcée requise.';
            }
        }
        $oldDomains = $this->linkDomains($beforeDecoded);
        $siteHost = strtolower((string) parse_url($siteUrl, PHP_URL_HOST));
        $added = array_values(array_diff($this->linkDomains($afterDecoded), $oldDomains, [$siteHost]));
        if ($added !== []) $warnings[] = 'Nouveaux domaines de lien : '.implode(', ', $added).'.';
        return $warnings === [] ? [] : ['risk' => $critical ? 'critical' : 'project_change', 'warnings' => $warnings];
    }

    private function hasAddedMatch(array $before, array $after): bool
    {
        $counts = [];
        foreach ($before as $match) {
            if (! is_string($match)) continue;
            $key = hash('sha256', mb_strtolower(preg_replace('/\s+/u', ' ', trim($match)) ?? trim($match)));
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        foreach ($after as $match) {
            if (! is_string($match)) continue;
            $key = hash('sha256', mb_strtolower(preg_replace('/\s+/u', ' ', trim($match)) ?? trim($match)));
            if (($counts[$key] ?? 0) > 0) $counts[$key]--;
            else return true;
        }
        return false;
    }

    private function linkDomains(string $content): array
    {
        preg_match_all('/\b(?:href|src)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'`=<>]+))/i', $content, $matches, PREG_SET_ORDER);
        $domains = [];
        foreach ($matches as $match) {
            $url = (string) (($match[1] ?? '') !== '' ? $match[1] : ((($match[2] ?? '') !== '') ? $match[2] : ($match[3] ?? '')));
            if (preg_match('/^https?:\/\//i', $url) !== 1) continue;
            $host = strtolower(rtrim((string) parse_url($url, PHP_URL_HOST), '.'));
            if ($host !== '' && function_exists('idn_to_ascii')) {
                $asciiHost = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
                if (is_string($asciiHost) && $asciiHost !== '') $host = strtolower(rtrim($asciiHost, '.'));
            }
            if ($host !== '' && preg_match('/^[a-z0-9.-]{1,253}$/D', $host) === 1) $domains[$host] = true;
        }
        $result = array_keys($domains); sort($result, SORT_STRING); return $result;
    }

    private function publicCheck(DevPilotConnection $connection, string $url, array $conditions): array
    {
        $result = $this->wordpress->verifyPublicPage($connection->toArray(), $url, $conditions['must_contain'] ?? [], $conditions['must_not_contain'] ?? []);
        $checked = ($result['checked'] ?? false) === true;
        return [
            'status' => $checked ? (($result['verified'] ?? false) ? 'verified' : 'failed') : 'failed',
            'verified' => ($result['verified'] ?? false) === true,
            'conditions' => ['must_contain' => $result['must_contain'] ?? [], 'must_not_contain' => $result['must_not_contain'] ?? []],
            'cache_stale_suspected' => ($result['cache_stale_suspected'] ?? false) === true,
        ];
    }

    private function safeTypes(array $types): array
    {
        $safe = [];
        foreach (array_slice($types, 0, 100) as $type) {
            if (! is_array($type) || ! is_string($type['id'] ?? null) || preg_match('/^[a-z0-9_-]{1,32}$/D', $type['id']) !== 1) continue;
            $taxonomies = [];
            foreach (is_array($type['taxonomies'] ?? null) ? array_slice($type['taxonomies'], 0, 12) : [] as $taxonomy) {
                if (! is_array($taxonomy) || ! is_string($taxonomy['id'] ?? null) || preg_match('/^[a-z0-9_-]{1,32}$/D', $taxonomy['id']) !== 1) continue;
                $taxonomies[] = ['id' => $taxonomy['id'], 'label' => $this->safeText($taxonomy['label'] ?? '', 120)];
            }
            $safe[] = ['id' => $type['id'], 'label' => $this->safeText($type['label'] ?? '', 256), 'supports' => array_values(array_intersect(['title', 'editor', 'excerpt', 'thumbnail'], is_array($type['supports'] ?? null) ? $type['supports'] : [])), 'taxonomies' => $taxonomies];
        }
        return $safe;
    }

    private function resolveType(array $types, string $type): array
    {
        foreach ($types as $definition) {
            if (! is_array($definition) || ($definition['id'] ?? null) !== $type) continue;
            if (! is_string($definition['rest_base'] ?? null) || preg_match('/^[a-z0-9_-]{1,32}$/D', $definition['rest_base']) !== 1) break;
            return ['id' => $type, 'rest_base' => $definition['rest_base'], 'label' => $this->safeText($definition['label'] ?? $type, 256), 'supports' => is_array($definition['supports'] ?? null) ? $definition['supports'] : [], 'taxonomies' => is_array($definition['taxonomies'] ?? null) ? $definition['taxonomies'] : []];
        }
        throw new RuntimeException('Ce type de contenu n’est pas exposé par le plugin WordPress ou n’est plus modifiable.');
    }

    private function assertLiveCapabilities(array $live, array $required): void
    {
        foreach ($required as $id) {
            if (($live[$id] ?? null) !== 1) throw new DevPilotAuthorizationChangedException('La capacité WordPress requise « '.$id.' » est absente ou utilise un schéma inconnu. Aucune écriture n’a été envoyée.');
        }
    }

    private function assertUntruncatedState(array $item): void
    {
        if (($item['truncated'] ?? true) !== false || ! is_string($item['state_hash'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $item['state_hash']) !== 1) {
            throw new RuntimeException('La relecture WordPress est tronquée ou ne possède pas d’empreinte d’état; aucune modification n’est proposée.');
        }
    }

    private function safeItem(array $item, bool &$truncated, bool $includeStateHash): array
    {
        if (! is_numeric($item['id'] ?? null) || (int) $item['id'] < 1 || ! is_string($item['type'] ?? null)) {
            $truncated = true; return [];
        }
        $safe = [
            'id' => (int) $item['id'], 'type' => $this->safeText($item['type'], 80, $truncated),
            'modified' => $this->safeText($item['modified_gmt'] ?? '', 64, $truncated),
            'slug' => $this->safeText($item['slug'] ?? '', 200, $truncated),
            'status' => $this->safeText($item['status'] ?? '', 32, $truncated),
            'title' => $this->safeText($item['title'] ?? '', self::MAX_TEXT_BYTES, $truncated),
            'excerpt' => $this->safeText($item['excerpt'] ?? '', self::MAX_TEXT_BYTES, $truncated),
            'content' => $this->safeText($item['content'] ?? '', self::MAX_TEXT_BYTES, $truncated),
            'featured_media' => is_numeric($item['featured_media'] ?? null) ? (int) $item['featured_media'] : 0,
            'date_gmt' => $this->safeText($item['date_gmt'] ?? '', 32, $truncated),
            'taxonomies' => is_array($item['taxonomies'] ?? null) ? $item['taxonomies'] : [],
            'taxonomy_terms' => is_array($item['taxonomy_terms'] ?? null) ? $item['taxonomy_terms'] : [],
            'trashed_from_status' => is_string($item['trashed_from_status'] ?? null) ? $item['trashed_from_status'] : null,
            'author' => is_numeric($item['author'] ?? null) ? (int) $item['author'] : 0,
            'comment_status' => $this->safeText($item['comment_status'] ?? '', 16, $truncated),
            'ping_status' => $this->safeText($item['ping_status'] ?? '', 16, $truncated),
            'sticky' => ($item['sticky'] ?? false) === true,
            'parent' => is_numeric($item['parent'] ?? null) ? (int) $item['parent'] : 0,
            'menu_order' => is_numeric($item['menu_order'] ?? null) ? (int) $item['menu_order'] : 0,
            'template' => $this->safeText($item['template'] ?? '', 200, $truncated),
            'seo' => is_array($item['seo'] ?? null) ? $item['seo'] : [],
            'password_protected' => ($item['password_protected'] ?? false) === true,
        ];
        if ($includeStateHash && is_string($item['state_hash'] ?? null)) $safe['state_hash'] = $item['state_hash'];
        if (is_string($item['link'] ?? null)) $safe['link'] = mb_strcut($item['link'], 0, 2048);
        if (($item['truncated'] ?? false) === true) $truncated = true;
        return $safe;
    }

    private function safeText(mixed $value, int $maxBytes, ?bool &$truncated = null): string
    {
        if (! is_string($value) && ! is_numeric($value)) return '';
        $value = (string) $value;
        if (strlen($value) > $maxBytes) {
            if ($truncated !== null) $truncated = true;
            $value = mb_strcut($value, 0, $maxBytes, 'UTF-8');
        }
        return $value;
    }

    private function recordFields(array $item): array
    {
        return [
            'id' => (int) ($item['id'] ?? 0), 'modified_gmt' => $item['modified_gmt'] ?? null,
            'slug' => $item['slug'] ?? '', 'status' => $item['status'] ?? '', 'title' => $item['title'] ?? '',
            'excerpt' => $item['excerpt'] ?? '', 'content' => $item['content'] ?? '', 'link' => $item['link'] ?? null,
            'featured_media' => is_numeric($item['featured_media'] ?? null) ? (int) $item['featured_media'] : 0,
            'date_gmt' => $item['date_gmt'] ?? '', 'taxonomies' => is_array($item['taxonomies'] ?? null) ? $item['taxonomies'] : [],
            'taxonomy_terms' => is_array($item['taxonomy_terms'] ?? null) ? $item['taxonomy_terms'] : [],
            'trashed_from_status' => $item['trashed_from_status'] ?? null,
            'author' => is_numeric($item['author'] ?? null) ? (int) $item['author'] : 0,
            'comment_status' => $item['comment_status'] ?? '', 'ping_status' => $item['ping_status'] ?? '',
            'sticky' => ($item['sticky'] ?? false) === true,
            'parent' => is_numeric($item['parent'] ?? null) ? (int) $item['parent'] : 0,
            'menu_order' => is_numeric($item['menu_order'] ?? null) ? (int) $item['menu_order'] : 0,
            'template' => $item['template'] ?? '', 'seo' => is_array($item['seo'] ?? null) ? $item['seo'] : [],
            'password_protected' => ($item['password_protected'] ?? false) === true,
        ];
    }

    private function rawFields(array $item, array $keys): array
    {
        $fields = [];
        foreach ($keys as $key) $fields[$key] = $item[$key] ?? null;
        return $fields;
    }

    private function matchesFields(array $actual, array $expected): bool
    {
        foreach ($expected as $key => $value) {
            $actualValue = $actual[$key] ?? null;
            if ($key === 'create_terms' && is_array($value)) {
                $actualTerms = is_array($actual['taxonomy_terms'] ?? null) ? $actual['taxonomy_terms'] : [];
                foreach ($value as $taxonomy => $names) {
                    $actualNames = array_map(static fn ($term): string => is_array($term) && is_string($term['name'] ?? null) ? $term['name'] : '', is_array($actualTerms[$taxonomy] ?? null) ? $actualTerms[$taxonomy] : []);
                    foreach (is_array($names) ? $names : [] as $name) if (! in_array($name, $actualNames, true)) return false;
                }
                continue;
            }
            if ($key === 'taxonomies' && is_array($value)) {
                $actualTaxonomies = is_array($actual['taxonomies'] ?? null) ? $actual['taxonomies'] : [];
                foreach ($value as $taxonomy => $ids) {
                    $actualIds = array_map('intval', is_array($actualTaxonomies[$taxonomy] ?? null) ? $actualTaxonomies[$taxonomy] : []);
                    $expectedIds = array_map('intval', is_array($ids) ? $ids : []);
                    sort($actualIds, SORT_NUMERIC); sort($expectedIds, SORT_NUMERIC);
                    if ($actualIds !== $expectedIds) return false;
                }
                continue;
            }
            if ($key === 'seo' && is_array($value)) {
                $actualSeo = is_array($actual['seo'] ?? null) ? $actual['seo'] : [];
                foreach ($value as $seoKey => $seoValue) if (($actualSeo[$seoKey] ?? '') !== $seoValue) return false;
                continue;
            }
            if ($key === 'date_gmt' && is_string($value) && strtotime($value) !== false) $value = gmdate('Y-m-d H:i:s', strtotime($value));
            if ($key === 'taxonomies' && is_array($actualValue) && is_array($value)) {
                foreach ($value as &$ids) { if (is_array($ids)) { $ids = array_map('intval', $ids); sort($ids, SORT_NUMERIC); } }
                unset($ids);
                foreach ($actualValue as &$ids) { if (is_array($ids)) { $ids = array_map('intval', $ids); sort($ids, SORT_NUMERIC); } }
                unset($ids);
            }
            if ($actualValue !== $value) return false;
        }
        return true;
    }

    private function fieldDiff(array $before, array $after): array
    {
        $diff = [];
        foreach ($after as $key => $value) {
            $old = $before[$key] ?? '';
            if ($key === 'password_encrypted') {
                try { $password = Crypt::decryptString((string) $value); }
                catch (\Throwable) { $password = null; }
                $diff['password'] = [
                    'before' => ($before['password_protected'] ?? false) ? 'protégé (valeur masquée)' : 'aucun',
                    'after' => $password === '' ? 'protection retirée' : 'protection activée (valeur masquée)',
                ];
                continue;
            }
            $oldText = is_scalar($old) ? (string) $old : (json_encode($old, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
            $newText = is_scalar($value) ? (string) $value : (json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
            $diff[$key] = ['before' => $oldText, 'after' => $newText];
        }
        return $diff;
    }

    private function inspectionResult(string $kind, array $data, bool $complete, array $facts = [], bool $truncated = false): array
    {
        return ['source_trust' => 'untrusted_remote_site_data', 'kind' => $kind, 'data' => $data, 'complete' => $complete, 'truncated' => $truncated,
            '_evidence_facts' => $facts + ['status' => str_ends_with($kind, '_read') ? 'read' : 'listed', 'complete' => $complete]];
    }

    private function identifier(mixed $value, string $label): string
    {
        if (! is_string($value) || preg_match('/^[a-z0-9_-]{1,32}$/D', $value) !== 1) throw new RuntimeException('Identifiant de '.$label.' invalide.');
        return $value;
    }

    private function positiveId(mixed $value): int
    {
        if ((! is_int($value) && !(is_string($value) && ctype_digit($value))) || (int) $value < 1 || (int) $value > 2147483647) throw new RuntimeException('Identifiant WordPress positif requis.');
        return (int) $value;
    }

    private function boundedInt(mixed $value, int $minimum, int $maximum, string $label): int
    {
        if ((! is_int($value) && !(is_string($value) && ctype_digit($value))) || (int) $value < $minimum || (int) $value > $maximum) throw new RuntimeException('Valeur de '.$label.' hors limites.');
        return (int) $value;
    }

    private function assertExactList(mixed $actual, array $expected, string $message): void
    {
        if (! is_array($actual) || ! array_is_list($actual)) throw new DevPilotAuthorizationChangedException($message);
        $sorted = $actual; sort($sorted, SORT_STRING); $required = $expected; sort($required, SORT_STRING);
        if ($sorted !== $required) throw new DevPilotAuthorizationChangedException($message);
    }

    private static function isUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value) === 1;
    }
}
