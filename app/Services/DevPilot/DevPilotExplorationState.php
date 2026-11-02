<?php

namespace App\Services\DevPilot;

/** Task-local, bounded memory for remote inspection calls and discoveries. */
final class DevPilotExplorationState
{
    private readonly DevPilotSecretRedactor $redactor;

    /** @var array<string, array{tool:string, arguments:array, summary:array}> */
    private array $operations = [];

    /** @var list<array<string, mixed>> */
    private array $observations = [];

    /** @var list<array<string, mixed>> */
    private array $wordpressResults = [];

    /** @var list<array{observation_id:string,tool:string,target:string,status:string,complete:bool,truncated:bool,output:string}> */
    private array $toolOutputs = [];

    private int $repeatedCalls = 0;
    private int $sequence;
    private readonly string $targetSnapshotHash;

    public function __construct(?DevPilotSecretRedactor $redactor = null, int $startingSequence = 0, string $targetSnapshotHash = '')
    {
        $this->redactor = $redactor ?? new DevPilotSecretRedactor();
        $this->sequence = max(0, $startingSequence);
        $this->targetSnapshotHash = $targetSnapshotHash;
    }

    /** Return a stable key for equivalent calls to the same read-only tool. */
    public function key(string $tool, array $arguments): string
    {
        $arguments = $this->normalizeArguments($tool, $arguments);
        return hash('sha256', json_encode([$tool, $arguments], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public function has(string $tool, array $arguments): bool
    {
        return isset($this->operations[$this->key($tool, $arguments)]);
    }

    /** Record bounded, redacted inspection output alongside compact evidence. */
    public function remember(string $tool, array $arguments, array $result, bool $includeWordPressArticleDetails = false, bool $forceNewObservation = false): array
    {
        $key = $this->key($tool, $arguments);
        if (! $forceNewObservation && isset($this->operations[$key])) return $this->operations[$key]['observation'];

        $observation = DevPilotEvidencePolicy::observation(
            ++$this->sequence,
            $this->evidenceKind($tool),
            $this->safeTarget($tool, $arguments),
            $this->targetSnapshotHash,
            $this->facts($tool, $result),
        );
        $this->observations[] = $observation;
        $wordpressResult = $this->wordpressDisplayResult($tool, $arguments, $result, $includeWordPressArticleDetails, $observation);
        if ($wordpressResult !== null) $this->wordpressResults[] = $wordpressResult;
        $this->toolOutputs[] = $this->displayToolOutput($tool, $arguments, $result, $observation);

        if (! array_key_exists('error', $result) && ($result['success'] ?? true) !== false) {
            $this->operations[$key] = [
                'tool' => $tool,
                'arguments' => $this->safeArguments($tool, $arguments),
                'summary' => $this->summarize($tool, $result),
                'observation' => $observation,
            ];
        }

        return $observation;
    }

    public function noteRepeatedCall(): void
    {
        $this->repeatedCalls++;
    }

    public function sequence(): int
    {
        return $this->sequence;
    }

    public function repeatedResult(string $tool, array $arguments): array
    {
        $operation = $this->operations[$this->key($tool, $arguments)] ?? null;
        return [
            'reused' => true,
            'message' => 'This exact inspection operation already ran for this task. Use the prior observation below and choose a different, narrower, or evidence-driven next step.',
            'previous_observation' => $operation['summary'] ?? [],
            'id' => $operation['observation']['id'] ?? null,
            'sequence' => $operation['observation']['sequence'] ?? null,
        ];
    }

    /** Compact evidence to steer the next model turn without replaying source text. */
    public function context(): array
    {
        return [
            'scope' => 'This memory belongs only to the current task and selected project.',
            'unique_operations' => count($this->operations),
            'repeated_calls_reused' => $this->repeatedCalls,
            'observations' => array_values(array_map(static fn (array $operation): array => [
                'id' => $operation['observation']['id'],
                'sequence' => $operation['observation']['sequence'],
                'kind' => $operation['observation']['kind'],
                'target' => $operation['observation']['target'],
                'facts' => array_diff_key($operation['observation']['facts'], array_flip(['entry_hashes', 'match_hashes'])),
                'summary' => $operation['summary'],
            ], array_slice($this->operations, -30))),
            'instruction' => 'Use these observations and the preceding tool results. Do not repeat an identical operation; choose a narrower or meaningfully different next step based on evidence.',
        ];
    }

    /** Safe, bounded inspection evidence persisted with the task report. */
    public function persistedHints(): array
    {
        $directories = [];
        $files = [];
        $searches = [];
        foreach ($this->operations as $operation) {
            $tool = $operation['tool'];
            $arguments = $operation['arguments'];
            $summary = $operation['summary'];
            if (isset($summary['error'])) continue;
            if ($tool === 'devpilot_list_files' && isset($arguments['path'])) $directories[] = $arguments['path'];
            if ($tool === 'devpilot_read_file' && isset($arguments['path'])) $files[] = $arguments['path'];
            if (in_array($tool, ['devpilot_search_files', 'devpilot_search_content'], true)) {
                $searches[] = [
                    'tool' => $tool,
                    'path' => $arguments['path'] ?? '.',
                    'query' => $arguments['query'] ?? '',
                    'matches' => array_map(static fn ($match) => array_intersect_key($match, array_flip(['path', 'line'])), array_slice($summary['matches'] ?? [], 0, 6)),
                ];
            }
        }

        return [
            'directories' => array_slice(array_values(array_unique($directories)), -16),
            'files_read' => array_slice(array_values(array_unique($files)), -16),
            'searches' => array_slice($searches, -8),
            'observations' => array_values(array_slice($this->observations, -DevPilotEvidencePolicy::MAX_INSPECTION_OBSERVATIONS)),
            'wordpress_results' => array_values(array_slice($this->wordpressResults, -64)),
            'tool_outputs' => array_values(array_slice($this->toolOutputs, -18)),
            'evidence_sequence' => $this->sequence,
        ];
    }

    private function displayToolOutput(string $tool, array $arguments, array $result, array $observation): array
    {
        $sensitiveKey = static fn (string $key): bool => preg_match('/(?:password|passwd|passphrase|secret|token|credential|authorization|cookie|api[_-]?key|private[_-]?key|access[_-]?key)/i', $key) === 1;
        $sanitize = function (mixed $value, int $depth = 0) use (&$sanitize, $sensitiveKey): mixed {
            if ($depth > 7) return '[profondeur limitée]';
            if (is_string($value)) return mb_strcut($this->redactor->redact($value), 0, 6000);
            if (is_null($value) || is_bool($value) || is_int($value) || is_float($value)) return $value;
            if (! is_array($value)) return '[type non affichable]';

            $safe = [];
            foreach (array_slice($value, 0, 100, true) as $key => $item) {
                $keyText = is_string($key) ? mb_strcut($key, 0, 100) : (string) $key;
                if (str_starts_with($keyText, '_evidence') || $keyText === '_verification_url') continue;
                $safe[$keyText] = $sensitiveKey($keyText) ? '[valeur masquée]' : $sanitize($item, $depth + 1);
            }
            if (count($value) > 100) $safe['_affichage_tronque'] = true;
            return $safe;
        };

        $error = isset($result['error']) || ($result['success'] ?? true) === false;
        $display = $sanitize(array_diff_key($result, array_flip(['_evidence_facts', '_verification_url'])));
        $encoded = json_encode($display, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRETTY_PRINT);
        if (! is_string($encoded)) $encoded = '{"error":"La sortie ne peut pas être encodée."}';
        $truncated = strlen($encoded) > 16000;
        if ($truncated) $encoded = mb_strcut($encoded, 0, 16000)."\n… sortie tronquée pour l’affichage";

        return [
            'observation_id' => (string) ($observation['id'] ?? ''),
            'tool' => mb_strcut($this->redactor->redact($tool), 0, 100),
            'target' => $this->safeTarget($tool, $arguments),
            'status' => $error ? 'error' : 'completed',
            'complete' => ($result['complete'] ?? false) === true,
            'truncated' => $truncated || ($result['truncated'] ?? false) === true,
            'output' => $encoded,
        ];
    }

    private function evidenceKind(string $tool): string
    {
        return match ($tool) {
            'devpilot_read_file' => 'file_read',
            'devpilot_list_files' => 'directory_listing',
            'devpilot_search_files' => 'search_files',
            'devpilot_search_content' => 'search_content',
            'devpilot_wp_list_types' => 'wordpress_types',
            'devpilot_wp_list_content' => 'wordpress_content_list',
            'devpilot_wp_read_content' => 'wordpress_content_read',
            'devpilot_wp_check_duplicate' => 'wordpress_content_list',
            'devpilot_wp_list_media' => 'wordpress_media_list',
            'devpilot_wp_search_media' => 'wordpress_media_list',
            'devpilot_wp_read_media' => 'wordpress_media_read',
            'devpilot_wp_list_terms' => 'wordpress_terms_list',
            'devpilot_wp_list_revisions' => 'wordpress_revisions_list',
            'devpilot_wp_list_page_templates' => 'wordpress_page_templates_list',
            'devpilot_wp_read_settings' => 'wordpress_settings_read',
            'devpilot_wp_list_plugins' => 'wordpress_plugins_list',
            'devpilot_wp_read_plugin_directory_info' => 'wordpress_plugin_metadata',
            'devpilot_wp_list_plugin_updates' => 'wordpress_plugin_updates',
            'devpilot_wp_search_plugin_directory' => 'wordpress_plugin_directory',
            'devpilot_wp_check_plugin_integrity' => 'wordpress_plugin_integrity',
            'devpilot_wp_read_plugin_settings' => 'wordpress_plugin_settings',
            'devpilot_wp_list_plugin_snapshots' => 'wordpress_plugin_snapshots',
            'devpilot_wp_site_health' => 'wordpress_site_health',
            'devpilot_wp_list_themes' => 'wordpress_themes_list',
            default => 'inspection_result',
        };
    }

    private function safeTarget(string $tool, array $arguments): string
    {
        if (str_starts_with($tool, 'devpilot_wp_')) {
            $type = is_string($arguments['type'] ?? null) && preg_match('/^[A-Za-z0-9_-]{1,80}$/D', $arguments['type']) === 1
                ? $arguments['type'] : null;
            $id = is_numeric($arguments['id'] ?? null) && (int) $arguments['id'] > 0 && (int) $arguments['id'] <= 2147483647
                ? (string) (int) $arguments['id'] : null;
            $target = match ($tool) {
                'devpilot_wp_list_types' => 'types',
                'devpilot_wp_list_content' => $type !== null ? 'content:'.$type : 'content',
                'devpilot_wp_list_terms' => $type !== null ? 'content:'.$type : 'content',
                'devpilot_wp_list_revisions' => $type !== null && $id !== null ? 'content:'.$type.':'.$id : 'content',
                'devpilot_wp_list_page_templates' => 'content:page',
                'devpilot_wp_search_media' => 'media',
                'devpilot_wp_read_media' => $id !== null ? 'media:'.$id : 'media',
                'devpilot_wp_read_content' => $type !== null && $id !== null ? 'content:'.$type.':'.$id : 'content',
                'devpilot_wp_check_duplicate' => $type !== null && is_string($arguments['slug'] ?? null)
                    ? 'content:'.$type.':'.strtolower($arguments['slug']) : 'content',
                'devpilot_wp_read_plugin_directory_info' => 'plugin:'.mb_strcut((string) ($arguments['slug'] ?? ''), 0, 100),
                'devpilot_wp_list_plugins', 'devpilot_wp_search_plugin_directory',
                'devpilot_wp_check_plugin_integrity', 'devpilot_wp_read_plugin_settings',
                'devpilot_wp_site_health' => 'plugins',
                'devpilot_wp_list_plugin_updates' => 'plugins:updates',
                'devpilot_wp_list_plugin_snapshots' => is_string($arguments['plugin_file'] ?? null)
                    ? 'plugins:snapshots:file:'.mb_strcut($arguments['plugin_file'], 0, 255)
                    : (is_string($arguments['slug'] ?? null) && preg_match('/^[a-z0-9][a-z0-9._-]{0,99}$/D', $arguments['slug']) === 1
                        ? 'plugins:snapshots:'.$arguments['slug'] : 'plugins:snapshots'),
                'devpilot_wp_list_themes' => 'themes',
                default => substr($tool, strlen('devpilot_wp_')),
            };
            return mb_strcut($this->redactor->redact($target), 0, 200);
        }
        $target = (string) ($arguments['path'] ?? '.');
        return mb_strcut($this->redactor->redact($target), 0, 700);
    }

    private function facts(string $tool, array $result): array
    {
        if (isset($result['error']) || ($result['success'] ?? true) === false) return ['status' => 'error', 'complete' => false];

        $facts = match ($tool) {
            'devpilot_read_file' => [
                'status' => 'read', 'sha256' => $result['sha256'] ?? null,
                'size' => $result['bytes'] ?? null, 'complete' => true,
            ],
            'devpilot_list_files' => [
                'status' => 'listed', 'complete' => $result['complete'] ?? false,
                'entry_hashes' => $result['_evidence_facts']['entry_hashes'] ?? [],
            ],
            'devpilot_search_files', 'devpilot_search_content' => [
                'status' => 'searched', 'complete' => $result['complete'] ?? false,
                'match_hashes' => $result['_evidence_facts']['match_hashes'] ?? [],
            ],
            'devpilot_wp_list_types', 'devpilot_wp_list_content', 'devpilot_wp_list_media', 'devpilot_wp_search_media', 'devpilot_wp_list_terms', 'devpilot_wp_list_revisions', 'devpilot_wp_list_page_templates', 'devpilot_wp_list_plugins', 'devpilot_wp_list_plugin_updates', 'devpilot_wp_search_plugin_directory', 'devpilot_wp_check_plugin_integrity', 'devpilot_wp_read_plugin_settings', 'devpilot_wp_list_plugin_snapshots', 'devpilot_wp_site_health', 'devpilot_wp_list_themes', 'devpilot_wp_read_plugin_directory_info', 'devpilot_wp_read_settings' => [
                'status' => 'listed', 'complete' => $result['complete'] ?? false,
                'count' => $result['_evidence_facts']['count'] ?? count($result['data']['records'] ?? $result['data'] ?? []),
                'page' => $result['data']['pagination']['page'] ?? 1,
                'total_pages' => $result['data']['pagination']['total_pages'] ?? null,
                'entry_hashes' => $result['_evidence_facts']['entry_hashes'] ?? [],
            ],
            'devpilot_wp_check_duplicate' => [
                'status' => 'listed', 'complete' => $result['complete'] ?? false,
                'count' => $result['_evidence_facts']['count'] ?? null,
                'created_by_this_task' => $result['_evidence_facts']['created_by_this_task'] ?? false,
                'match_ids' => $result['_evidence_facts']['match_ids'] ?? [],
            ],
            'devpilot_wp_read_content', 'devpilot_wp_read_media', 'devpilot_wp_read_settings' => [
                'status' => 'read', 'complete' => $result['complete'] ?? false,
                'sha256' => $result['_evidence_facts']['sha256'] ?? null,
                'size' => $result['_evidence_facts']['size'] ?? null,
            ],
            default => ['status' => 'completed', 'complete' => true],
        };

        return DevPilotEvidencePolicy::safeFacts($facts);
    }

    private function normalizeArguments(string $tool, array $arguments): array
    {
        if (str_starts_with($tool, 'devpilot_wp_')) {
            if (isset($arguments['type']) && is_string($arguments['type'])) $arguments['type'] = strtolower(trim($arguments['type']));
            if (isset($arguments['search']) && is_string($arguments['search'])) $arguments['search'] = mb_strtolower(preg_replace('/\s+/u', ' ', trim($arguments['search'])) ?? trim($arguments['search']));
            foreach (['page', 'per_page', 'id'] as $key) {
                if (isset($arguments[$key]) && is_numeric($arguments[$key])) $arguments[$key] = (int) $arguments[$key];
            }
            if ($tool === 'devpilot_wp_list_plugins' && is_array($arguments['fields'] ?? null)) {
                $arguments['fields'] = array_values(array_unique(array_filter(array_map(
                    static fn ($field): string => is_string($field) ? trim($field) : '',
                    array_slice($arguments['fields'], 0, 15),
                ), static fn (string $field): bool => preg_match('/^[a-z_]{1,32}$/D', $field) === 1)));
                sort($arguments['fields'], SORT_STRING);
            }
        }
        if (isset($arguments['path']) && is_string($arguments['path'])) {
            $path = trim(str_replace('\\', '/', $arguments['path']));
            $path = preg_replace('#/+#', '/', $path) ?? $path;
            while (str_starts_with($path, './')) $path = substr($path, 2);
            if ($path !== '/') $path = rtrim($path, '/');
            $arguments['path'] = $path === '' ? '.' : $path;
        }
        if (isset($arguments['query']) && is_string($arguments['query'])) {
            $arguments['query'] = mb_strtolower(preg_replace('/\s+/u', ' ', trim($arguments['query'])) ?? trim($arguments['query']));
        }
        if ($tool === 'devpilot_search_content') {
            $extensions = is_array($arguments['file_extensions'] ?? null) ? $arguments['file_extensions'] : [];
            $extensions = array_map(static function ($extension): string {
                $extension = strtolower(trim((string) $extension));
                return $extension !== '' && ! str_starts_with($extension, '.') ? '.'.$extension : $extension;
            }, $extensions);
            $extensions = array_values(array_unique($extensions));
            sort($extensions);
            $arguments['file_extensions'] = $extensions;
            $arguments['max_files'] = is_numeric($arguments['max_files'] ?? null) ? max(1, min(80, (int) $arguments['max_files'])) : 48;
        }
        ksort($arguments);
        return $arguments;
    }

    private function safeArguments(string $tool, array $arguments): array
    {
        $safe = [];
        $keys = str_starts_with($tool, 'devpilot_wp_')
            ? ['type', 'id', 'page', 'per_page', 'search', 'title', 'slug']
            : ['path', 'query', 'file_extensions', 'max_files'];
        if ($tool === 'devpilot_wp_list_plugins') $keys[] = 'fields';
        foreach ($keys as $key) {
            if (! array_key_exists($key, $arguments)) continue;
            if (! is_scalar($arguments[$key]) && ! is_array($arguments[$key])) continue;
            $safe[$key] = is_string($arguments[$key]) ? $this->redactor->redact($arguments[$key]) : $arguments[$key];
        }
        return $safe;
    }

    private function summarize(string $tool, array $result): array
    {
        if (isset($result['error'])) return ['error' => mb_strcut((string) $result['error'], 0, 240)];

        if (str_starts_with($tool, 'devpilot_wp_')) {
            $data = is_array($result['data'] ?? null) ? $result['data'] : [];
            $records = is_array($data['records'] ?? null) ? $data['records'] : (array_is_list($data) ? $data : []);
            if ($tool === 'devpilot_wp_list_types' && ! array_is_list($data)) {
                $records = array_map(static fn ($slug, $type): array => is_array($type) ? ['slug' => $type['slug'] ?? $slug] : [], array_keys($data), array_values($data));
            }
            if ($tool === 'devpilot_wp_read_content' && is_numeric($data['id'] ?? null)) $records = [['id' => (int) $data['id']]];
            $identifiers = array_slice(array_values(array_filter(array_map(static function ($record): ?string {
                if (! is_array($record)) return null;
                if (is_numeric($record['id'] ?? null)) return 'id:'.(int) $record['id'];
                if (is_string($record['slug'] ?? null) && preg_match('/^[A-Za-z0-9_.\/-]{1,100}$/D', $record['slug']) === 1) {
                    return 'slug:'.$record['slug'];
                }
                return null;
            }, $records))), 0, 20);
            return [
                'kind' => $result['kind'] ?? $this->evidenceKind($tool),
                'identifiers' => $identifiers,
                'pagination' => array_intersect_key(is_array($data['pagination'] ?? null) ? $data['pagination'] : [], array_flip(['page', 'per_page', 'total_items', 'total_pages', 'has_more'])),
                'complete' => ($result['complete'] ?? false) === true,
                'truncated' => ($result['truncated'] ?? false) === true,
                'note' => 'Les textes distants ne sont pas recopiés dans la mémoire de la tâche; la réponse d’inspection reste une donnée non fiable.',
            ];
        }

        if ($tool === 'devpilot_list_files') {
            return [
                'path' => $this->redactor->redact((string) ($result['path'] ?? '')),
                'entries' => array_slice(array_map(fn ($entry) => [
                    'name' => mb_strcut($this->redactor->redact((string) ($entry['name'] ?? '')), 0, 160),
                    'type' => (string) ($entry['type'] ?? 'unknown'),
                ], is_array($result['entries'] ?? null) ? $result['entries'] : []), 0, 40),
                'truncated' => (bool) ($result['truncated'] ?? false),
            ];
        }

        if ($tool === 'devpilot_search_files') {
            return [
                'matches' => array_slice(array_map(fn ($match) => [
                    'path' => mb_strcut($this->redactor->redact((string) ($match['path'] ?? '')), 0, 300),
                    'type' => (string) ($match['type'] ?? 'unknown'),
                ], is_array($result['matches'] ?? null) ? $result['matches'] : []), 0, 30),
                'entries_scanned' => (int) ($result['entries_scanned'] ?? 0),
                'limited' => (bool) ($result['limited'] ?? false),
            ];
        }

        if ($tool === 'devpilot_search_content') {
            return [
                'matches' => array_slice(array_map(fn ($match) => [
                    'path' => mb_strcut($this->redactor->redact((string) ($match['path'] ?? '')), 0, 300),
                    'line' => (int) ($match['line'] ?? 0),
                    'excerpt' => mb_strcut($this->redactor->redact((string) ($match['excerpt'] ?? '')), 0, 240),
                ], is_array($result['matches'] ?? null) ? $result['matches'] : []), 0, 12),
                'files_scanned' => (int) ($result['files_scanned'] ?? 0),
                'truncated' => (bool) ($result['truncated'] ?? false),
            ];
        }

        if ($tool === 'devpilot_read_file') {
            return [
                'path' => mb_strcut($this->redactor->redact((string) ($result['path'] ?? '')), 0, 300),
                'bytes' => (int) ($result['bytes'] ?? 0),
                'read' => isset($result['content']),
                'note' => 'The file content is available in the separate redacted tool-output panel; it is not copied into exploration context.',
            ];
        }

        return ['completed' => true];
    }

    /** Persist only fields needed to show a bounded WordPress inspection report. */
    private function wordpressDisplayResult(string $tool, array $arguments, array $result, bool $includeArticleDetails = false, ?array $observation = null): ?array
    {
        if (! in_array($tool, ['devpilot_wp_list_types', 'devpilot_wp_list_content', 'devpilot_wp_read_content', 'devpilot_wp_list_plugins', 'devpilot_wp_list_plugin_updates'], true)) return null;
        if ($tool === 'devpilot_wp_read_content' && ! $includeArticleDetails) return null;
        $fingerprint = hash('sha256', json_encode([$tool, $this->normalizeArguments($tool, $arguments)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $base = ['fingerprint' => $fingerprint, 'tool' => $tool];
        if (is_string($observation['id'] ?? null)) $base['observation_id'] = $observation['id'];
        if (isset($result['error']) || ($result['success'] ?? true) === false) {
            if (in_array($tool, ['devpilot_wp_list_content', 'devpilot_wp_read_content'], true)) {
                $type = $arguments['type'] ?? null;
                if (is_string($type) && preg_match('/^[a-z0-9_-]{1,80}$/D', $type) === 1) $base['type'] = $type;
            }
            if ($tool === 'devpilot_wp_read_content') {
                $id = $arguments['id'] ?? null;
                if (is_numeric($id) && (int) $id > 0) $base['requested_id'] = (int) $id;
            }
            return $base + ['status' => 'inaccessible', 'complete' => false];
        }

        $data = is_array($result['data'] ?? null) ? $result['data'] : [];
        if ($tool === 'devpilot_wp_list_plugins') {
            $records = [];
            $allowed = ['plugin_file', 'slug', 'name', 'version', 'description', 'author', 'plugin_uri', 'requires', 'requires_php', 'requires_plugins', 'active', 'network_active', 'auto_update', 'protected', 'auto_update_enabled', 'state_hash'];
            $booleanFields = ['active', 'network_active', 'auto_update', 'protected', 'auto_update_enabled'];
            foreach (array_slice(is_array($data['records'] ?? null) ? $data['records'] : [], 0, 100) as $record) {
                if (! is_array($record)) continue;
                $safe = [];
                foreach (array_intersect_key($record, array_flip($allowed)) as $field => $value) {
                    if (in_array($field, $booleanFields, true)) {
                        if (is_bool($value)) $safe[$field] = $value;
                    } elseif ($field === 'requires_plugins') {
                        $safe[$field] = array_values(array_filter(array_map(
                            fn ($slug): string => mb_strcut($this->redactor->redact((string) $slug), 0, 100),
                            is_array($value) ? array_slice($value, 0, 20) : [],
                        ), static fn (string $slug): bool => $slug !== ''));
                    } elseif (is_string($value) || is_numeric($value)) {
                        $limit = match ($field) {
                            'description' => 300,
                            'plugin_file' => 255,
                            'plugin_uri' => 300,
                            'slug' => 100,
                            'version', 'requires', 'requires_php' => 64,
                            default => 160,
                        };
                        $safe[$field] = mb_strcut($this->redactor->redact((string) $value), 0, $limit);
                    }
                }
                if ($safe !== []) $records[] = $safe;
            }
            $pagination = is_array($data['pagination'] ?? null) ? $data['pagination'] : [];
            return $base + [
                'status' => 'listed', 'records' => $records,
                'page' => is_numeric($pagination['page'] ?? null) ? max(1, (int) $pagination['page']) : 1,
                'per_page' => is_numeric($pagination['per_page'] ?? null) ? max(1, min(100, (int) $pagination['per_page'])) : 100,
                'total_items' => is_numeric($pagination['total_items'] ?? null) ? max(0, (int) $pagination['total_items']) : null,
                'total_pages' => is_numeric($pagination['total_pages'] ?? null) ? max(1, min(100, (int) $pagination['total_pages'])) : null,
                'complete' => ($result['complete'] ?? false) === true && ($result['truncated'] ?? false) !== true,
                'truncated' => ($result['truncated'] ?? false) === true,
            ];
        }
        if ($tool === 'devpilot_wp_list_plugin_updates') {
            $records = [];
            $allowed = ['plugin_file', 'slug', 'current_version', 'target_version', 'requires', 'tested', 'requires_php', 'wordpress_compatible', 'php_compatible', 'dependencies', 'dependencies_satisfied', 'upgrade_notice', 'source'];
            foreach (array_slice(is_array($data['items'] ?? null) ? $data['items'] : [], 0, 100) as $record) {
                if (! is_array($record)) continue;
                $safe = [];
                foreach (array_intersect_key($record, array_flip($allowed)) as $field => $value) {
                    if (in_array($field, ['wordpress_compatible', 'php_compatible', 'dependencies_satisfied'], true)) {
                        if (is_bool($value)) $safe[$field] = $value;
                    } elseif ($field === 'dependencies') {
                        $safeDependencies = [];
                        foreach (array_slice(is_array($value) ? $value : [], 0, 20) as $dependency) {
                            if (! is_array($dependency) || ! is_string($dependency['slug'] ?? null)) continue;
                            $safeDependencies[] = [
                                'slug' => mb_strcut($this->redactor->redact($dependency['slug']), 0, 100),
                                'installed' => ($dependency['installed'] ?? false) === true,
                                'active' => ($dependency['active'] ?? false) === true,
                            ];
                        }
                        $safe[$field] = $safeDependencies;
                    } elseif (is_string($value) || is_numeric($value)) {
                        $safe[$field] = mb_strcut($this->redactor->redact((string) $value), 0, in_array($field, ['plugin_file', 'upgrade_notice'], true) ? 300 : 100);
                    }
                }
                if ($safe !== []) $records[] = $safe;
            }
            return $base + [
                'status' => 'listed', 'records' => $records,
                'total_items' => is_numeric($data['total'] ?? null) ? max(0, (int) $data['total']) : count($records),
                'complete' => ($result['complete'] ?? false) === true && ($result['truncated'] ?? false) !== true && ($data['complete'] ?? false) === true,
                'truncated' => ($result['truncated'] ?? false) === true,
            ];
        }
        if ($tool === 'devpilot_wp_read_content') {
            $type = $arguments['type'] ?? null;
            $id = $data['id'] ?? null;
            if (! is_string($type) || preg_match('/^[a-z0-9_-]{1,80}$/D', $type) !== 1 || ! is_numeric($id) || (int) $id < 1) return null;
            $title = $this->wordpressPlainText($data['title'] ?? '', 300);
            $slug = $this->wordpressPlainText($data['slug'] ?? '', 200);
            $status = $this->wordpressPlainText($data['status'] ?? '', 32);
            $excerpt = is_string($data['excerpt'] ?? null) ? $data['excerpt'] : '';
            $content = is_string($data['content'] ?? null) ? $data['content'] : '';
            $summaryPoints = $this->wordpressSummaryPoints($excerpt, $content);
            $headings = $this->wordpressHeadings($content);

            return $base + [
                'type' => $type,
                'status' => 'read',
                'complete' => ($result['complete'] ?? false) === true && ($result['truncated'] ?? false) !== true,
                'id' => (int) $id,
                'requested_id' => is_numeric($arguments['id'] ?? null) ? (int) $arguments['id'] : null,
                'title' => $title,
                'slug' => $slug,
                'post_status' => $status,
                'summary_points' => $summaryPoints,
                'summary_kind' => 'extractive',
                'headings' => $headings,
            ];
        }

        if ($tool === 'devpilot_wp_list_types') {
            $sourceTypes = is_array($data['records'] ?? null) ? $data['records'] : (array_is_list($data) ? $data : []);
            $types = [];
            foreach (array_slice($sourceTypes, 0, 100, true) as $key => $type) {
                if (! is_array($type)) continue;
                $slug = $type['slug'] ?? $type['id'] ?? (is_string($key) ? $key : null);
                if (! is_string($slug) || preg_match('/^[A-Za-z0-9_-]{1,80}$/D', $slug) !== 1) continue;
                $label = $type['name'] ?? $type['label'] ?? $slug;
                if (! is_string($label) && ! is_numeric($label)) $label = $slug;
                $types[] = [
                    'slug' => $slug,
                    'label' => mb_strcut($this->redactor->redact(trim((string) $label)), 0, 200),
                ];
            }
            return $base + [
                'status' => 'available', 'types' => $types,
                'complete' => ($result['complete'] ?? false) === true,
                'truncated' => ($result['truncated'] ?? false) === true,
            ];
        }

        $type = $arguments['type'] ?? null;
        if (! is_string($type) || preg_match('/^[a-z0-9_-]{1,80}$/D', $type) !== 1) return null;
        $records = [];
        if ($type === 'post') {
            $sourceRecords = is_array($data['records'] ?? null) ? $data['records'] : [];
            foreach ($sourceRecords as $record) {
                if (! is_array($record) || ($record['status'] ?? null) !== 'publish') continue;
                $title = $record['title'] ?? '';
                if (is_array($title)) $title = $title['raw'] ?? $title['rendered'] ?? '';
                $slug = $record['slug'] ?? '';
                if ((! is_string($title) && ! is_numeric($title)) || ! is_string($slug)) continue;
                $records[] = [
                    'title' => mb_strcut($this->redactor->redact(trim((string) $title)), 0, 300),
                    'slug' => mb_strcut($this->redactor->redact(trim($slug)), 0, 200),
                ];
                if (count($records) >= 5) break;
            }
        }

        $pagination = is_array($data['pagination'] ?? null) ? $data['pagination'] : [];
        return $base + [
            'type' => $type, 'status' => 'accessible', 'records' => $records,
            'page' => is_numeric($pagination['page'] ?? null) ? max(1, (int) $pagination['page']) : 1,
            'has_more' => ($pagination['has_more'] ?? false) === true,
            'complete' => ($result['complete'] ?? false) === true,
        ];
    }

    /** Convert untrusted WordPress HTML to a short, plain-text value for escaped UI rendering. */
    private function wordpressPlainText(mixed $value, int $maximumBytes): string
    {
        if (is_array($value)) $value = $value['raw'] ?? $value['rendered'] ?? '';
        if (! is_string($value) && ! is_numeric($value)) return '';
        $html = preg_replace('~<(script|style|noscript)\b[^>]*>.*?</\1\s*>~is', ' ', (string) $value) ?? (string) $value;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);
        $text = trim($this->redactor->redact($text));
        return mb_strcut($text, 0, $maximumBytes, 'UTF-8');
    }

    /** Keep at most three bounded, extractive points; do not retain the article body in task memory. */
    private function wordpressSummaryPoints(string $excerpt, string $content): array
    {
        $paragraphs = $this->wordpressHtmlElements($content, '//p | //li', '~<(p|li)\b[^>]*>(.*?)</\1\s*>~is');
        if ($paragraphs === []) $paragraphs = $this->wordpressHtmlElements($excerpt, '//p | //li', '~<(p|li)\b[^>]*>(.*?)</\1\s*>~is');
        if ($paragraphs === []) {
            $plain = $this->wordpressPlainText($content !== '' ? $content : $excerpt, 6000);
            if ($plain !== '') $paragraphs = preg_split('/(?<=[.!?])\s+/u', $plain, 4, PREG_SPLIT_NO_EMPTY) ?: [$plain];
        }

        $points = [];
        foreach ($paragraphs as $paragraph) {
            $point = $this->wordpressPlainText($paragraph, 420);
            if ($point === '' || mb_strlen($point) < 35) continue;
            $points[] = $point;
            if (count($points) >= 3) break;
        }

        return array_values(array_unique($points));
    }

    /** Extract H2/H3 labels in document order, returning text only. */
    private function wordpressHeadings(string $content): array
    {
        $headings = [];
        $elements = $this->wordpressHtmlElements($content, '//h2 | //h3', '~<(h[23])\b[^>]*>(.*?)</\1\s*>~is', true);
        foreach ($elements as $element) {
            if (! is_array($element)) continue;
            $text = $this->wordpressPlainText($element['text'] ?? '', 240);
            if ($text === '') continue;
            $headings[] = ['level' => strtoupper((string) ($element['level'] ?? 'h2')), 'text' => $text];
            if (count($headings) >= 5) break;
        }
        return $headings;
    }

    /** @return list<string|array{level:string,text:string}> */
    private function wordpressHtmlElements(string $html, string $query, string $fallbackPattern, bool $withLevel = false): array
    {
        $elements = [];
        if (class_exists(\DOMDocument::class) && $html !== '') {
            $previousErrors = libxml_use_internal_errors(true);
            try {
                $document = new \DOMDocument('1.0', 'UTF-8');
                if (@$document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_COMPACT)) {
                    $xpath = new \DOMXPath($document);
                    $nodes = $xpath->query($query);
                    if ($nodes !== false) {
                        foreach ($nodes as $node) {
                            $text = $node->textContent ?? '';
                            $elements[] = $withLevel
                                ? ['level' => strtolower($node->nodeName), 'text' => $text]
                                : $text;
                        }
                    }
                }
            } catch (\Throwable) {
                $elements = [];
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previousErrors);
            }
        }

        if ($elements === [] && preg_match_all($fallbackPattern, $html, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as $match) {
                $elements[] = $withLevel
                    ? ['level' => strtolower((string) $match[1]), 'text' => (string) $match[2]]
                    : (string) end($match);
            }
        }

        return $elements;
    }
}
