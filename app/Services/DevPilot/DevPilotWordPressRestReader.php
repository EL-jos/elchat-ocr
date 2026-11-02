<?php

namespace App\Services\DevPilot;

use App\Models\DevPilot\DevPilotConnection;
use RuntimeException;

/** Typed, read-only WordPress REST inspections. No caller-controlled URL or HTTP verb. */
final class DevPilotWordPressRestReader
{
    private const MAX_PAGE = 50;
    private const MAX_PER_PAGE = 10;
    private const MAX_TYPES = 100;
    private const MAX_LIST_FIELD_BYTES = 1200;
    private const MAX_READ_FIELD_BYTES = 12000;

    public function __construct(
        private readonly DevPilotWordPressClient $client,
        private readonly DevPilotSecretRedactor $redactor,
    ) {}

    public function inspect(
        DevPilotConnection $connection,
        array $credentials,
        string $rootForm,
        string $tool,
        array $arguments,
    ): array {
        return match ($tool) {
            'devpilot_wp_list_types' => $this->listTypes($connection, $credentials, $rootForm),
            'devpilot_wp_list_content' => $this->listContent($connection, $credentials, $rootForm, $arguments),
            'devpilot_wp_read_content' => $this->readContent($connection, $credentials, $rootForm, $arguments),
            'devpilot_wp_list_media' => $this->listMedia($connection, $credentials, $rootForm, $arguments),
            'devpilot_wp_read_settings' => $this->readSettings($connection, $credentials, $rootForm),
            'devpilot_wp_list_plugins' => $this->listPlugins($connection, $credentials, $rootForm, $arguments),
            'devpilot_wp_list_themes' => $this->listThemes($connection, $credentials, $rootForm, $arguments),
            default => throw new RuntimeException('Cette inspection WordPress n’est pas disponible.'),
        };
    }

    private function listTypes(DevPilotConnection $connection, array $credentials, string $rootForm): array
    {
        $response = $this->client->readResource($connection->toArray(), $credentials, $rootForm, '/wp/v2/types', [
            'context' => 'edit', '_fields' => 'name,slug,rest_base,rest_namespace,viewable',
        ]);
        $types = [];
        $hashes = [];
        $truncated = count($response['data']) > self::MAX_TYPES;
        foreach (array_slice($response['data'], 0, self::MAX_TYPES, true) as $slug => $type) {
            if (! is_array($type)) { $truncated = true; continue; }
            $slug = $this->identifier((string) ($type['slug'] ?? $slug));
            if ($slug === null) { $truncated = true; continue; }
            $types[] = [
                'slug' => $slug,
                'name' => $this->safeText($type['name'] ?? '', 200, $truncated),
                'rest_base' => $this->identifier((string) ($type['rest_base'] ?? $slug)),
                'rest_namespace' => $this->namespace((string) ($type['rest_namespace'] ?? 'wp/v2')),
                'viewable' => is_bool($type['viewable'] ?? null) ? $type['viewable'] : null,
            ];
            $hashes[] = hash('sha256', $slug);
        }
        usort($types, static fn (array $a, array $b): int => strcmp($a['slug'], $b['slug']));

        return $this->result('wordpress_types', $types, ! $truncated, ['entry_hashes' => $hashes, 'count' => count($types)], $truncated);
    }

    private function listContent(DevPilotConnection $connection, array $credentials, string $rootForm, array $arguments): array
    {
        $type = $this->requiredIdentifier($arguments['type'] ?? null, 'type');
        $page = $this->page($arguments['page'] ?? 1);
        $perPage = $this->perPage($arguments['per_page'] ?? 10);
        $search = $this->search($arguments['search'] ?? null);
        $definition = $this->resolveType($connection, $credentials, $rootForm, $type);
        $route = '/'.$definition['namespace'].'/'.$definition['rest_base'];
        $query = [
            'context' => 'edit', 'page' => $page, 'per_page' => $perPage,
            '_fields' => 'id,date,modified,slug,status,type,title,excerpt,content,author,featured_media',
        ];
        if ($type === 'post') $query['status'] = 'publish';
        if ($search !== null) $query['search'] = $search;
        $response = $this->client->readResource($connection->toArray(), $credentials, $rootForm, $route, $query);
        $records = [];
        $hashes = [];
        $truncated = false;
        foreach (array_slice($response['data'], 0, $perPage) as $item) {
            if (! is_array($item) || ! is_numeric($item['id'] ?? null)) { $truncated = true; continue; }
            $record = [
                'id' => (int) $item['id'], 'type' => $this->safeText($item['type'] ?? $type, 80, $truncated),
                'date' => $this->safeText($item['date'] ?? '', 64, $truncated), 'modified' => $this->safeText($item['modified'] ?? '', 64, $truncated),
                'slug' => $this->safeText($item['slug'] ?? '', 160, $truncated), 'status' => $this->safeText($item['status'] ?? '', 48, $truncated),
                'title' => $this->contentField($item['title'] ?? '', self::MAX_LIST_FIELD_BYTES, $truncated),
                'excerpt' => $this->contentField($item['excerpt'] ?? '', self::MAX_LIST_FIELD_BYTES, $truncated),
                'content' => $this->contentField($item['content'] ?? '', self::MAX_LIST_FIELD_BYTES, $truncated),
                'author_id' => is_numeric($item['author'] ?? null) ? (int) $item['author'] : null,
                'featured_media_id' => is_numeric($item['featured_media'] ?? null) ? (int) $item['featured_media'] : null,
            ];
            $records[] = $record;
            $hashes[] = hash('sha256', $type.':'.$record['id']);
        }

        return $this->paginatedResult('wordpress_content_list', $records, $response, $page, $perPage, $hashes, $truncated);
    }

    private function readContent(DevPilotConnection $connection, array $credentials, string $rootForm, array $arguments): array
    {
        $type = $this->requiredIdentifier($arguments['type'] ?? null, 'type');
        $id = $arguments['id'] ?? null;
        if (! is_int($id) && !(is_string($id) && ctype_digit($id))) throw new RuntimeException('Un identifiant de contenu WordPress positif est requis.');
        $id = (int) $id;
        if ($id < 1 || $id > 2147483647) throw new RuntimeException('Un identifiant de contenu WordPress positif est requis.');
        $definition = $this->resolveType($connection, $credentials, $rootForm, $type);
        $route = '/'.$definition['namespace'].'/'.$definition['rest_base'].'/'.$id;
        $response = $this->client->readResource($connection->toArray(), $credentials, $rootForm, $route, [
            'context' => 'edit',
            '_fields' => 'id,date,modified,slug,status,type,title,excerpt,content,author,featured_media,parent,menu_order,link',
        ]);
        $item = $response['data'];
        if (! is_numeric($item['id'] ?? null)) throw new RuntimeException('La réponse WordPress ne contient pas un contenu valide.');
        $truncated = false;
        $record = [
            'id' => (int) $item['id'], 'type' => $this->safeText($item['type'] ?? $type, 80, $truncated),
            'date' => $this->safeText($item['date'] ?? '', 64, $truncated), 'modified' => $this->safeText($item['modified'] ?? '', 64, $truncated),
            'slug' => $this->safeText($item['slug'] ?? '', 160, $truncated), 'status' => $this->safeText($item['status'] ?? '', 48, $truncated),
            'title' => $this->contentField($item['title'] ?? '', 2000, $truncated),
            'excerpt' => $this->contentField($item['excerpt'] ?? '', 3000, $truncated),
            'content' => $this->contentField($item['content'] ?? '', self::MAX_READ_FIELD_BYTES, $truncated),
            'author_id' => is_numeric($item['author'] ?? null) ? (int) $item['author'] : null,
            'featured_media_id' => is_numeric($item['featured_media'] ?? null) ? (int) $item['featured_media'] : null,
            'parent_id' => is_numeric($item['parent'] ?? null) ? (int) $item['parent'] : null,
            'menu_order' => is_numeric($item['menu_order'] ?? null) ? (int) $item['menu_order'] : null,
        ];
        $evidenceContent = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: '';
        $result = $this->result('wordpress_content_read', $record, ! $truncated, [
            'sha256' => hash('sha256', $evidenceContent), 'size' => strlen($evidenceContent),
        ], $truncated);
        $publicUrl = $item['link'] ?? null;
        $result['_verification_url'] = is_string($publicUrl)
            && DevPilotWordPressUrl::sameOrigin((string) $connection->site_url, $publicUrl)
            && preg_match('/[\x00-\x20\x7f]/', $publicUrl) !== 1
            ? $publicUrl : null;

        return $result;
    }

    private function listMedia(DevPilotConnection $connection, array $credentials, string $rootForm, array $arguments): array
    {
        $page = $this->page($arguments['page'] ?? 1);
        $perPage = $this->perPage($arguments['per_page'] ?? 10);
        $response = $this->client->readResource($connection->toArray(), $credentials, $rootForm, '/wp/v2/media', [
            'context' => 'edit', 'page' => $page, 'per_page' => $perPage,
            '_fields' => 'id,date,slug,mime_type,alt_text,media_details',
        ]);
        $records = [];
        $hashes = [];
        $truncated = false;
        foreach (array_slice($response['data'], 0, $perPage) as $item) {
            if (! is_array($item) || ! is_numeric($item['id'] ?? null)) { $truncated = true; continue; }
            $details = is_array($item['media_details'] ?? null) ? $item['media_details'] : [];
            $record = [
                'id' => (int) $item['id'], 'date' => $this->safeText($item['date'] ?? '', 64, $truncated),
                'slug' => $this->safeText($item['slug'] ?? '', 160, $truncated), 'mime_type' => $this->safeText($item['mime_type'] ?? '', 100, $truncated),
                'alt_text' => $this->contentField($item['alt_text'] ?? '', self::MAX_LIST_FIELD_BYTES, $truncated),
                'width' => is_numeric($details['width'] ?? null) ? max(0, min(100000, (int) $details['width'])) : null,
                'height' => is_numeric($details['height'] ?? null) ? max(0, min(100000, (int) $details['height'])) : null,
                'file_size' => is_numeric($details['filesize'] ?? null) ? max(0, min(1000000000, (int) $details['filesize'])) : null,
            ];
            $records[] = $record;
            $hashes[] = hash('sha256', 'media:'.$record['id']);
        }
        return $this->paginatedResult('wordpress_media_list', $records, $response, $page, $perPage, $hashes, $truncated);
    }

    private function readSettings(DevPilotConnection $connection, array $credentials, string $rootForm): array
    {
        $response = $this->client->readResource($connection->toArray(), $credentials, $rootForm, '/wp/v2/settings', [
            'context' => 'edit',
        ]);
        $allowed = [
            'title', 'description', 'url', 'email', 'timezone', 'date_format', 'time_format', 'start_of_week',
            'language', 'use_smilies', 'default_category', 'default_post_format', 'posts_per_page', 'show_on_front',
            'page_on_front', 'page_for_posts', 'default_ping_status', 'default_comment_status', 'site_logo', 'site_icon',
        ];
        $settings = [];
        $truncated = false;
        foreach ($allowed as $name) {
            if (! array_key_exists($name, $response['data'])) continue;
            $value = $response['data'][$name];
            if (is_scalar($value) || $value === null) $settings[$name] = is_string($value) ? $this->contentField($value, 1000, $truncated) : $value;
        }
        $encoded = json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: '';
        return $this->result('wordpress_settings_read', $settings, ! $truncated, ['sha256' => hash('sha256', $encoded), 'size' => strlen($encoded)], $truncated);
    }

    private function listPlugins(DevPilotConnection $connection, array $credentials, string $rootForm, array $arguments): array
    {
        $page = $this->page($arguments['page'] ?? 1);
        $perPage = $this->perPage($arguments['per_page'] ?? 10);
        $response = $this->client->readResource($connection->toArray(), $credentials, $rootForm, '/wp/v2/plugins', [
            'context' => 'edit', 'page' => $page, 'per_page' => $perPage,
            '_fields' => 'plugin,status,name,version,description',
        ]);
        $records = [];
        $hashes = [];
        $truncated = false;
        foreach (array_slice($response['data'], 0, $perPage) as $item) {
            if (! is_array($item)) { $truncated = true; continue; }
            $slug = $this->pluginIdentifier((string) ($item['plugin'] ?? ''));
            if ($slug === null) { $truncated = true; continue; }
            $records[] = [
                'slug' => $slug, 'status' => $this->safeText($item['status'] ?? '', 48, $truncated),
                'name' => $this->contentField($item['name'] ?? '', self::MAX_LIST_FIELD_BYTES, $truncated),
                'version' => $this->safeText($item['version'] ?? '', 80, $truncated),
                'description' => $this->contentField($item['description'] ?? '', 500, $truncated),
            ];
            $hashes[] = hash('sha256', $slug);
        }
        return $this->paginatedResult('wordpress_plugins_list', $records, $response, $page, $perPage, $hashes, $truncated);
    }

    private function listThemes(DevPilotConnection $connection, array $credentials, string $rootForm, array $arguments): array
    {
        $page = $this->page($arguments['page'] ?? 1);
        $perPage = $this->perPage($arguments['per_page'] ?? 10);
        $response = $this->client->readResource($connection->toArray(), $credentials, $rootForm, '/wp/v2/themes', [
            'context' => 'edit', 'page' => $page, 'per_page' => $perPage,
            '_fields' => 'stylesheet,status,name,version,description',
        ]);
        $records = [];
        $hashes = [];
        $truncated = false;
        foreach (array_slice($response['data'], 0, $perPage) as $item) {
            if (! is_array($item)) { $truncated = true; continue; }
            $slug = $this->identifier((string) ($item['stylesheet'] ?? ''));
            if ($slug === null) { $truncated = true; continue; }
            $records[] = [
                'slug' => $slug, 'status' => $this->safeText($item['status'] ?? '', 48, $truncated),
                'name' => $this->contentField($item['name'] ?? '', self::MAX_LIST_FIELD_BYTES, $truncated),
                'version' => $this->safeText($item['version'] ?? '', 80, $truncated),
                'description' => $this->contentField($item['description'] ?? '', 500, $truncated),
            ];
            $hashes[] = hash('sha256', 'theme:'.$slug);
        }
        return $this->paginatedResult('wordpress_themes_list', $records, $response, $page, $perPage, $hashes, $truncated);
    }

    private function resolveType(DevPilotConnection $connection, array $credentials, string $rootForm, string $type): array
    {
        $response = $this->client->readResource($connection->toArray(), $credentials, $rootForm, '/wp/v2/types', [
            'context' => 'edit', '_fields' => 'slug,rest_base,rest_namespace',
        ]);
        $definition = $response['data'][$type] ?? null;
        if (! is_array($definition)) throw new RuntimeException('Ce type de contenu n’est pas exposé par l’API REST WordPress. Listez les types avant de demander un contenu.');
        $restBase = $this->identifier((string) ($definition['rest_base'] ?? $type));
        $namespace = $this->namespace((string) ($definition['rest_namespace'] ?? 'wp/v2'));
        if ($restBase === null || $namespace === null) throw new RuntimeException('La route du type de contenu WordPress ne peut pas être construite de façon sûre.');
        return ['rest_base' => $restBase, 'namespace' => $namespace];
    }

    private function paginatedResult(string $kind, array $records, array $response, int $page, int $perPage, array $hashes, bool $truncated): array
    {
        $totalPages = $response['total_pages'];
        $hasMore = $totalPages === null ? count($records) >= $perPage : $page < $totalPages;
        $emptyCollection = $response['total'] === 0 && $totalPages === 0;
        $totalReceived = $response['total'] === null || count($records) >= $response['total'];
        $complete = $page === 1 && ($totalPages === 1 || $emptyCollection) && $totalReceived && ! $truncated;
        return $this->result($kind, [
            'records' => $records,
            'pagination' => [
                'page' => $page, 'per_page' => $perPage, 'total_items' => $response['total'],
                'total_pages' => $totalPages, 'has_more' => $hasMore,
            ],
        ], $complete, ['entry_hashes' => $hashes, 'count' => count($records)], $truncated);
    }

    private function result(string $kind, array $data, bool $complete, array $evidenceFacts = [], bool $truncated = false): array
    {
        return [
            'source_trust' => 'untrusted_remote_site_data',
            'kind' => $kind,
            'data' => $data,
            'complete' => $complete,
            'truncated' => $truncated,
            '_evidence_facts' => $evidenceFacts + ['status' => str_ends_with($kind, '_read') ? 'read' : 'listed', 'complete' => $complete],
        ];
    }

    private function contentField(mixed $value, int $maximumBytes, ?bool &$truncated = null): string
    {
        if (is_array($value)) $value = $value['raw'] ?? $value['rendered'] ?? '';
        if (! is_string($value) && ! is_numeric($value)) return '';
        $text = $this->redactor->redact((string) $value);
        if (strlen($text) > $maximumBytes) {
            $truncated = true;
            $text = mb_strcut($text, 0, $maximumBytes, 'UTF-8');
        }
        return $text;
    }

    private function safeText(mixed $value, int $maximumBytes, ?bool &$truncated = null): string
    {
        if (is_array($value)) $value = $value['raw'] ?? $value['rendered'] ?? '';
        if (! is_string($value) && ! is_numeric($value)) return '';
        $text = $this->redactor->redact((string) $value);
        if (strlen($text) > $maximumBytes) {
            $truncated = true;
            $text = mb_strcut($text, 0, $maximumBytes, 'UTF-8');
        }
        return $text;
    }

    private function requiredIdentifier(mixed $value, string $name): string
    {
        $identifier = is_string($value) ? $this->identifier($value) : null;
        if ($identifier === null) throw new RuntimeException("Un identifiant WordPress {$name} valide est requis.");
        return $identifier;
    }

    private function identifier(string $value): ?string
    {
        $value = trim($value);
        return preg_match('/^[A-Za-z0-9_-]{1,80}$/D', $value) === 1 ? $value : null;
    }

    private function pluginIdentifier(string $value): ?string
    {
        $value = trim($value);
        return preg_match('#^[A-Za-z0-9_.-]{1,100}(?:/[A-Za-z0-9_.-]{1,100})?$#D', $value) === 1 ? $value : null;
    }

    private function namespace(string $value): ?string
    {
        $value = trim($value, '/');
        return preg_match('#^[A-Za-z0-9_-]{1,80}/[A-Za-z0-9_-]{1,80}$#D', $value) === 1 ? $value : null;
    }

    private function page(mixed $value): int
    {
        if (! is_int($value) && !(is_string($value) && ctype_digit($value))) throw new RuntimeException('Le numéro de page REST doit être un entier positif.');
        $page = (int) $value;
        if ($page < 1 || $page > self::MAX_PAGE) throw new RuntimeException('La pagination REST est limitée aux 50 premières pages.');
        return $page;
    }

    private function perPage(mixed $value): int
    {
        if (! is_int($value) && !(is_string($value) && ctype_digit($value))) throw new RuntimeException('La taille de page REST doit être un entier positif.');
        $perPage = (int) $value;
        if ($perPage < 1 || $perPage > self::MAX_PER_PAGE) throw new RuntimeException('Une page REST est limitée à 10 éléments.');
        return $perPage;
    }

    private function search(mixed $value): ?string
    {
        if ($value === null || $value === '') return null;
        if (! is_string($value) || strlen($value) > 120 || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
            throw new RuntimeException('Le filtre de recherche REST est invalide ou trop long.');
        }
        return trim($value) !== '' ? trim($value) : null;
    }
}
