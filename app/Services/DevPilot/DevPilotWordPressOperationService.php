<?php

namespace App\Services\DevPilot;

use App\Models\DevPilot\DevPilotConnection;
use App\Models\DevPilot\DevPilotProject;
use RuntimeException;

/** Server-built WordPress REST operations. Model supplied URLs, routes, and verbs are never used. */
final class DevPilotWordPressOperationService
{
    private const PROTOCOL_VERSION = 1;
    private const CONTENT_FIELDS = ['title', 'content', 'excerpt', 'slug', 'status'];
    private const WRITABLE_SETTINGS = [
        'title', 'description', 'url', 'email', 'timezone', 'date_format', 'time_format', 'start_of_week',
        'language', 'use_smilies', 'default_category', 'default_post_format', 'posts_per_page', 'show_on_front',
        'page_on_front', 'page_for_posts', 'default_ping_status', 'default_comment_status', 'site_logo', 'site_icon',
    ];

    public function __construct(private readonly DevPilotWordPressClient $client) {}

    /** Validate and bind a proposal to a read-before-write image before it can be approved. */
    public function prepare(
        DevPilotProject $project,
        DevPilotConnection $connection,
        array $credentials,
        string $rootForm,
        array $input,
    ): array {
        $type = (string) ($input['type'] ?? '');
        $summary = mb_strcut(trim((string) ($input['summary'] ?? '')), 0, 500);
        if ($summary === '') throw new RuntimeException('Un résumé lisible pour approbation est obligatoire.');

        return match ($type) {
            'wordpress_create_content' => $this->prepareCreate($connection, $credentials, $rootForm, $input, $summary),
            'wordpress_update_content' => $this->prepareUpdate($connection, $credentials, $rootForm, $input, $summary),
            'wordpress_trash_content' => $this->prepareTrash($connection, $credentials, $rootForm, $input, $summary),
            'wordpress_update_setting' => $this->prepareSetting($connection, $credentials, $rootForm, $input, $summary),
            'wordpress_manage_plugin' => $this->preparePlugin($connection, $credentials, $rootForm, $input, $summary),
            'wordpress_manage_theme' => throw new RuntimeException('Les routes REST WordPress exposées pour les thèmes sont en lecture seule; aucune modification de thème n’est proposée.'),
            default => throw new RuntimeException('Type d’opération WordPress non pris en charge.'),
        };
    }

    /** Revalidate capabilities and current state, perform one mutation, then verify server-side. */
    public function apply(
        DevPilotProject $project,
        DevPilotConnection $connection,
        array $credentials,
        string $rootForm,
        array $operation,
    ): array {
        $type = (string) ($operation['type'] ?? '');
        return match ($type) {
            'wordpress_create_content' => $this->applyCreate($connection, $credentials, $rootForm, $operation),
            'wordpress_update_content' => $this->applyUpdate($connection, $credentials, $rootForm, $operation),
            'wordpress_trash_content' => $this->applyTrash($connection, $credentials, $rootForm, $operation),
            'wordpress_update_setting' => $this->applySetting($connection, $credentials, $rootForm, $operation),
            'wordpress_manage_plugin' => $this->applyPlugin($connection, $credentials, $rootForm, $operation),
            default => throw new RuntimeException('Cette opération WordPress n’est plus autorisée.'),
        };
    }

    private function prepareCreate(DevPilotConnection $connection, array $credentials, string $root, array $input, string $summary): array
    {
        $type = $this->identifier($input['wordpress_type'] ?? null, 'type de contenu');
        $definition = $this->resolveType($connection, $credentials, $root, $type);
        $fields = $this->contentInput($input, true);
        if (! isset($fields['title'], $fields['slug'])) throw new RuntimeException('Une création exige un titre et un slug explicites afin de vérifier les doublons.');
        $this->assertNoDuplicate($connection, $credentials, $root, $definition, $fields);
        $content = (string) ($fields['content'] ?? '');
        $this->assertSafeContentChange('', $content);
        $active = $this->activeContentWarnings('', $content, (string) $connection->site_url);
        $status = (string) ($fields['status'] ?? 'draft');
        $public = $status === 'publish';
        $verification = $this->verificationConditions($input);
        return $this->operationEnvelope([
            'type' => 'wordpress_create_content', 'wordpress_type' => $type,
            'route' => '/'.$definition['namespace'].'/'.$definition['rest_base'], 'fields' => $fields,
            'before' => null, 'expected_modified' => null, 'expected_record_sha256' => null,
            'verification' => $verification, 'public_verification' => $public ? 'required' : 'not_applicable',
            'risk' => $active['risk'] ?? ($public ? 'sensitive' : 'project_change'),
            'warnings' => array_values(array_filter([
                ...($active['warnings'] ?? []),
                'Le contenu sera créé avec le slug « '.(string) $fields['slug'].' » et le statut « '.$status.' ».',
                $public ? 'Le contenu sera publié et vérifié sur sa page publique.' : 'Le contenu ne sera pas publié; la vérification publique est non applicable.',
            ])),
            'display_diff' => $this->fieldDiff([], $fields), 'summary' => $summary,
        ], ['write']);
    }

    private function prepareUpdate(DevPilotConnection $connection, array $credentials, string $root, array $input, string $summary): array
    {
        $type = $this->identifier($input['wordpress_type'] ?? null, 'type de contenu');
        $id = $this->positiveId($input['id'] ?? null);
        $definition = $this->resolveType($connection, $credentials, $root, $type);
        $route = '/'.$definition['namespace'].'/'.$definition['rest_base'].'/'.$id;
        $current = $this->read($connection, $credentials, $root, $route);
        $before = $this->contentRecord($current);
        $fields = $this->contentInput($input, false);
        $fields = $this->removeExactNoOps($fields, $before);
        if ($fields === []) throw new RuntimeException('La proposition ne change aucun champ; le contenu actuel sera conservé à l’identique.');
        if (array_key_exists('content', $fields)) {
            $this->assertSafeContentChange((string) ($before['content'] ?? ''), (string) $fields['content']);
            $active = $this->activeContentWarnings((string) ($before['content'] ?? ''), (string) $fields['content'], (string) $connection->site_url);
        } else {
            $active = [];
        }
        $verification = $this->verificationConditions($input);
        $after = array_replace($before, $fields);
        $warnings = $active['warnings'] ?? [];
        if (($fields['status'] ?? null) === 'publish') $warnings[] = 'Le statut passera à publié et la page publique sera vérifiée.';
        if (array_key_exists('slug', $fields) && ($fields['slug'] ?? null) !== ($before['slug'] ?? null)) $warnings[] = 'Le slug public du contenu va changer.';
        return $this->operationEnvelope([
            'type' => 'wordpress_update_content', 'wordpress_type' => $type, 'id' => $id, 'route' => $route,
            'fields' => $fields, 'before' => array_intersect_key($before, array_flip([...array_keys($fields), 'modified', 'status', 'slug', 'link'])),
            'expected_modified' => $before['modified'], 'expected_record_sha256' => $this->recordHash($before, array_keys($fields)),
            'verification' => $verification, 'public_verification' => ($after['status'] ?? '') === 'publish' ? 'required' : 'not_applicable',
            'risk' => $active['risk'] ?? ((isset($fields['status']) || isset($fields['slug'])) ? 'sensitive' : 'project_change'),
            'warnings' => $warnings, 'display_diff' => $this->fieldDiff($before, $fields), 'summary' => $summary,
        ], ['read', 'write']);
    }

    private function prepareTrash(DevPilotConnection $connection, array $credentials, string $root, array $input, string $summary): array
    {
        $type = $this->identifier($input['wordpress_type'] ?? null, 'type de contenu');
        $id = $this->positiveId($input['id'] ?? null);
        $definition = $this->resolveType($connection, $credentials, $root, $type);
        $route = '/'.$definition['namespace'].'/'.$definition['rest_base'].'/'.$id;
        $before = $this->contentRecord($this->read($connection, $credentials, $root, $route));
        return $this->operationEnvelope([
            'type' => 'wordpress_trash_content', 'wordpress_type' => $type, 'id' => $id, 'route' => $route,
            'fields' => [], 'before' => $before, 'expected_modified' => $before['modified'],
            'expected_record_sha256' => $this->recordHash($before, ['title', 'excerpt', 'content', 'slug', 'status']),
            'verification' => ['must_contain' => [], 'must_not_contain' => []], 'public_verification' => 'not_applicable',
            'risk' => 'critical', 'warnings' => ['Le contenu sera déplacé dans la corbeille WordPress.'],
            'display_diff' => $this->fieldDiff($before, []), 'summary' => $summary,
        ], ['read', 'delete']);
    }

    private function prepareSetting(DevPilotConnection $connection, array $credentials, string $root, array $input, string $summary): array
    {
        $name = $this->identifier($input['setting_name'] ?? null, 'réglage');
        if (! in_array($name, self::WRITABLE_SETTINGS, true)) throw new RuntimeException('Seuls les réglages WordPress pris en charge et exposés par /wp/v2/settings peuvent être modifiés.');
        $settings = $this->read($connection, $credentials, $root, '/wp/v2/settings', ['context' => 'edit']);
        if (! array_key_exists($name, $settings)) throw new RuntimeException('Ce réglage n’est pas actuellement exposé par /wp/v2/settings.');
        $value = $input['setting_value'] ?? null;
        if (! is_scalar($value) && $value !== null) throw new RuntimeException('La valeur du réglage doit être scalaire ou nulle.');
        if (is_string($value) && (strlen($value) > 4000 || preg_match('/\x00/', $value))) throw new RuntimeException('La valeur du réglage est invalide ou trop longue.');
        if ($settings[$name] === $value) throw new RuntimeException('Le réglage possède déjà cette valeur.');
        $critical = in_array($name, ['url', 'email', 'show_on_front', 'page_on_front', 'page_for_posts', 'site_logo', 'site_icon'], true);
        return $this->operationEnvelope([
            'type' => 'wordpress_update_setting', 'setting_name' => $name,
            'fields' => [$name => $value], 'before' => [$name => $settings[$name]],
            'expected_record_sha256' => $this->recordHash([$name => $settings[$name]], [$name]),
            'risk' => $critical ? 'critical' : 'project_change', 'warnings' => [],
            'display_diff' => $this->fieldDiff([$name => $settings[$name]], [$name => $value]), 'summary' => $summary,
        ], ['read', 'write']);
    }

    private function preparePlugin(DevPilotConnection $connection, array $credentials, string $root, array $input, string $summary): array
    {
        $slug = $this->pluginSlug($input['plugin_slug'] ?? null);
        $action = (string) ($input['plugin_action'] ?? '');
        if (! in_array($action, ['install', 'activate', 'deactivate', 'delete'], true)) throw new RuntimeException('Action de plugin non prise en charge.');
        $installed = $this->readInstalledPlugin($connection, $credentials, $root, $slug);
        if ($action === 'install' && $installed !== null) throw new RuntimeException('Ce plugin est déjà installé; une installation en double est bloquée.');
        if ($action !== 'install' && $installed === null) throw new RuntimeException('Le plugin demandé n’est pas installé sur ce site.');
        if ($action === 'activate' && ($installed['status'] ?? null) === 'active') throw new RuntimeException('Le plugin est déjà actif.');
        if ($action === 'deactivate' && ($installed['status'] ?? null) !== 'active') throw new RuntimeException('Le plugin n’est pas actif.');
        $required = $action === 'delete' ? ['read', 'delete'] : ($action === 'install' ? ['read', 'write'] : ['read', 'write']);
        return $this->operationEnvelope([
            'type' => 'wordpress_manage_plugin', 'plugin_slug' => $slug, 'plugin_action' => $action,
            'route' => '/wp/v2/plugins', 'before' => $installed,
            'expected_record_sha256' => $installed === null ? null : $this->recordHash($installed, ['plugin', 'status', 'version']),
            'risk' => 'critical', 'warnings' => [$action === 'install'
                ? 'Installation critique du plugin WordPress.org « '.$slug.' »; aucune URL ni archive arbitraire ne sera utilisée.'
                : 'Gestion critique du plugin « '.$slug.' ».'],
            'display_diff' => $this->fieldDiff($installed ?? [], ['action' => $action, 'plugin_slug' => $slug]),
            'summary' => $summary,
        ], $required);
    }

    private function applyCreate(DevPilotConnection $connection, array $credentials, string $root, array $operation): array
    {
        $this->assertNoDuplicate($connection, $credentials, $root, $this->resolveType($connection, $credentials, $root, (string) $operation['wordpress_type']), $operation['fields']);
        $created = $this->client->mutateResource($connection->toArray(), $credentials, $root, 'POST', (string) $operation['route'], $operation['fields']);
        $data = is_array($created['data'] ?? null) ? $created['data'] : [];
        $id = $this->positiveId($data['id'] ?? null);
        $readback = $this->read($connection, $credentials, $root, (string) $operation['route'].'/'.$id);
        $verified = $this->matchesFields($readback, $operation['fields']);
        $public = ($operation['public_verification'] ?? null) === 'required'
            ? $this->publicCheck($connection, (string) ($readback['link'] ?? ''), $operation['verification'] ?? [])
            : ['status' => 'not_applicable'];
        return $this->result($operation, $verified && (($public['status'] ?? null) !== 'failed'), $readback, $public, $verified, $operation['fields']);
    }

    private function applyUpdate(DevPilotConnection $connection, array $credentials, string $root, array $operation): array
    {
        $current = $this->read($connection, $credentials, $root, (string) $operation['route']);
        $before = $this->contentRecord($current);
        $hash = $this->recordHash($before, array_keys($operation['fields']));
        if (($before['modified'] ?? null) !== ($operation['expected_modified'] ?? null)
            || ! is_string($operation['expected_record_sha256'] ?? null)
            || ! hash_equals($operation['expected_record_sha256'], $hash)) {
            throw new RuntimeException('Le contenu WordPress a changé depuis sa préparation; aucune écriture n’a été envoyée.');
        }
        $this->client->mutateResource($connection->toArray(), $credentials, $root, 'POST', (string) $operation['route'], $operation['fields']);
        $readback = $this->read($connection, $credentials, $root, (string) $operation['route']);
        $verified = $this->matchesFields($readback, $operation['fields']);
        $public = ($operation['public_verification'] ?? null) === 'required'
            ? $this->publicCheck($connection, (string) ($readback['link'] ?? ''), $operation['verification'] ?? [])
            : ['status' => 'not_applicable'];
        return $this->result($operation, $verified && (($public['status'] ?? null) !== 'failed'), $readback, $public, $verified, $operation['fields']);
    }

    private function applyTrash(DevPilotConnection $connection, array $credentials, string $root, array $operation): array
    {
        $current = $this->contentRecord($this->read($connection, $credentials, $root, (string) $operation['route']));
        if (($current['modified'] ?? null) !== ($operation['expected_modified'] ?? null)
            || ! hash_equals((string) $operation['expected_record_sha256'], $this->recordHash($current, ['title', 'excerpt', 'content', 'slug', 'status']))) {
            throw new RuntimeException('Le contenu WordPress a changé depuis sa préparation; aucune suppression n’a été envoyée.');
        }
        $this->client->mutateResource($connection->toArray(), $credentials, $root, 'DELETE', (string) $operation['route'], [], ['force' => false]);
        $readback = $this->read($connection, $credentials, $root, (string) $operation['route']);
        $matches = ($readback['status'] ?? null) === 'trash';
        return $this->result($operation, $matches, $readback, ['status' => 'not_applicable'], $matches, ['status' => 'trash']);
    }

    private function applySetting(DevPilotConnection $connection, array $credentials, string $root, array $operation): array
    {
        $name = (string) $operation['setting_name'];
        $current = $this->read($connection, $credentials, $root, '/wp/v2/settings', ['context' => 'edit']);
        if (! array_key_exists($name, $current) || ! hash_equals((string) $operation['expected_record_sha256'], $this->recordHash([$name => $current[$name]], [$name]))) {
            throw new RuntimeException('Le réglage WordPress a changé ou n’est plus exposé; aucune écriture n’a été envoyée.');
        }
        $this->client->mutateResource($connection->toArray(), $credentials, $root, 'POST', '/wp/v2/settings', [$name => $operation['fields'][$name]]);
        $after = $this->read($connection, $credentials, $root, '/wp/v2/settings', ['context' => 'edit']);
        $verified = array_key_exists($name, $after) && $after[$name] === $operation['fields'][$name];
        return $this->result($operation, $verified, $after, ['status' => 'not_applicable'], $verified, $operation['fields']);
    }

    private function applyPlugin(DevPilotConnection $connection, array $credentials, string $root, array $operation): array
    {
        $slug = (string) $operation['plugin_slug'];
        $action = (string) $operation['plugin_action'];
        $installed = $this->readInstalledPlugin($connection, $credentials, $root, $slug);
        if ($action === 'install') {
            if ($installed !== null) throw new RuntimeException('Le plugin est déjà installé; aucune seconde installation n’a été envoyée.');
            $this->client->mutateResource($connection->toArray(), $credentials, $root, 'POST', '/wp/v2/plugins', ['slug' => $slug, 'status' => 'inactive']);
        } else {
            if ($installed === null || ! hash_equals((string) $operation['expected_record_sha256'], $this->recordHash($installed, ['plugin', 'status', 'version']))) {
                throw new RuntimeException('L’état du plugin a changé depuis sa préparation; aucune écriture n’a été envoyée.');
            }
            $route = '/wp/v2/plugins/'.(string) $installed['plugin'];
            if ($action === 'delete') $this->client->mutateResource($connection->toArray(), $credentials, $root, 'DELETE', $route);
            else $this->client->mutateResource($connection->toArray(), $credentials, $root, 'POST', $route, ['status' => $action === 'activate' ? 'active' : 'inactive']);
        }
        $after = $this->readInstalledPlugin($connection, $credentials, $root, $slug);
        $verified = match ($action) {
            'install' => $after !== null && ($after['status'] ?? null) !== 'active',
            'activate' => $after !== null && ($after['status'] ?? null) === 'active',
            'deactivate' => $after !== null && ($after['status'] ?? null) !== 'active',
            'delete' => $after === null,
        };
        $expectedReadback = match ($action) {
            'install', 'deactivate' => ['status' => 'inactive'],
            'activate' => ['status' => 'active'],
            default => [],
        };
        return $this->result($operation, $verified, $after ?? [], ['status' => 'not_applicable'], $verified, $expectedReadback);
    }

    private function result(array $operation, bool $verified, array $readback, array $public, bool $restReadbackMatches, array $expectedReadback): array
    {
        $actualFields = [];
        $expectedFields = [];
        foreach ($expectedReadback as $key => $expectedValue) {
            $actualFields[$key] = $this->raw($readback[$key] ?? null);
            $expectedFields[$key] = $this->raw($expectedValue);
        }
        $actualHashValue = $expectedFields === [] ? $readback : $actualFields;
        $expectedHashValue = $expectedFields;
        $restHash = hash('sha256', json_encode($actualHashValue, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $expectedHash = hash('sha256', json_encode($expectedHashValue, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $restReadbackMatches = $restReadbackMatches && hash_equals($expectedHash, $restHash);
        $verified = $verified && $restReadbackMatches;
        $publicStatus = $public['status'] ?? 'not_applicable';
        return [
            'type' => $operation['type'], 'status' => $verified ? 'verified' : 'verification_failed',
            'verified' => $verified, 'risk' => $operation['risk'] ?? 'project_change',
            'target' => match ($operation['type'] ?? '') {
                'wordpress_update_setting' => 'settings:'.(string) ($operation['setting_name'] ?? ''),
                'wordpress_manage_plugin' => 'plugins:'.(string) ($operation['plugin_slug'] ?? ''),
                default => 'content:'.(string) ($operation['wordpress_type'] ?? '').':'.(string) ($operation['id'] ?? $operation['fields']['slug'] ?? ''),
            },
            'summary' => $operation['summary'] ?? '', 'rest_readback_hash' => $restHash,
            'rest_readback_matches' => $restReadbackMatches, 'public_verification' => $publicStatus,
            'public_verified' => $public['verified'] ?? false,
            'public_conditions' => $public['conditions'] ?? [],
            'cache_stale_suspected' => $public['cache_stale_suspected'] ?? false,
        ];
    }

    private function publicCheck(DevPilotConnection $connection, string $url, array $verification): array
    {
        $result = $this->client->verifyPublicPage($connection->toArray(), $url,
            $verification['must_contain'] ?? [], $verification['must_not_contain'] ?? []);
        return [
            'status' => ($result['checked'] ?? false) ? (($result['verified'] ?? false) ? 'verified' : 'failed') : 'failed',
            'verified' => ($result['verified'] ?? false) === true,
            'conditions' => ['must_contain' => $result['must_contain'] ?? [], 'must_not_contain' => $result['must_not_contain'] ?? []],
            'cache_stale_suspected' => ($result['cache_stale_suspected'] ?? false) === true,
        ];
    }

    private function operationEnvelope(array $operation, array $required): array
    {
        return [
            ...$operation, 'channel' => 'rest', 'protocol_version' => self::PROTOCOL_VERSION,
            'required_capabilities' => $required, 'approval_required' => true,
            'critical_confirmation_required' => in_array($operation['risk'] ?? null, ['critical', 'sensitive'], true),
        ];
    }

    private function contentInput(array $input, bool $create): array
    {
        $fields = [];
        foreach (self::CONTENT_FIELDS as $field) {
            if (! array_key_exists($field, $input)) continue;
            $value = $input[$field];
            if (! is_string($value) || strlen($value) > 12000 || ! mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0")) {
                throw new RuntimeException("Le champ de contenu {$field} est invalide ou trop long.");
            }
            if ($field === 'slug' && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $value) !== 1) throw new RuntimeException('Le slug doit être en minuscules et composé de lettres, chiffres et tirets.');
            if ($field === 'status' && ! in_array($value, ['draft', 'pending', 'private', 'publish', 'future'], true)) throw new RuntimeException('Ce statut de contenu WordPress n’est pas autorisé.');
            $fields[$field] = $value;
        }
        if ($create && ! isset($fields['status'])) $fields['status'] = 'draft';
        if ($create && (! isset($fields['title']) || ! isset($fields['slug']))) throw new RuntimeException('Une création exige un titre et un slug explicites.');
        if ($fields === []) throw new RuntimeException('Au moins un champ de contenu autorisé est requis.');
        return $fields;
    }

    private function verificationConditions(array $input): array
    {
        $contains = $this->conditionList($input['must_contain'] ?? []);
        $excludes = $this->conditionList($input['must_not_contain'] ?? []);
        return ['must_contain' => $contains, 'must_not_contain' => $excludes];
    }

    private function conditionList(mixed $items): array
    {
        if (! is_array($items) || count($items) > 8) throw new RuntimeException('Chaque liste de vérification accepte au plus huit conditions.');
        $result = [];
        foreach ($items as $text) {
            if (! is_string($text) || trim($text) === '' || strlen($text) > 256 || preg_match('/[\x00-\x1f\x7f]/', $text) === 1) throw new RuntimeException('Une condition de vérification est invalide.');
            $result[] = trim($text);
        }
        return $result;
    }

    private function activeContentWarnings(string $before, string $after, string $siteUrl): array
    {
        $before = html_entity_decode($before, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $after = html_entity_decode($after, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $patterns = [
            'script' => '/<\s*script\b[^>]*>.*?(?:<\s*\/\s*script\s*>|$)/is',
            'iframe' => '/<\s*iframe\b[^>]*>.*?(?:<\s*\/\s*iframe\s*>|$)/is',
            'event_handler' => '/\bon[a-z][a-z0-9_-]*\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s"\'`=<>]+)/i',
            'active_url' => '/\b(?:javascript|data)\s*:[^\s"\'<>)]*/i',
        ];
        $warnings = [];
        foreach ($patterns as $label => $pattern) {
            if ($this->hasAddedPattern($before, $after, $pattern)) $warnings[] = 'Le diff ajoute ou modifie du contenu actif ('.$label.'); confirmation renforcée requise.';
        }
        $oldDomains = $this->linkDomains($before);
        $siteHost = strtolower((string) parse_url($siteUrl, PHP_URL_HOST));
        $newDomains = array_values(array_diff(array_filter($this->linkDomains($after), static fn (string $domain): bool => $domain !== $siteHost), $oldDomains));
        if ($newDomains !== []) $warnings[] = 'Nouveaux domaines de lien : '.implode(', ', $newDomains).'.';
        if ($warnings !== []) return ['risk' => 'sensitive', 'warnings' => $warnings];
        return [];
    }

    private function hasAddedPattern(string $before, string $after, string $pattern): bool
    {
        preg_match_all($pattern, $before, $beforeMatches);
        preg_match_all($pattern, $after, $afterMatches);
        $counts = [];
        foreach ($beforeMatches[0] ?? [] as $match) $counts[$match] = ($counts[$match] ?? 0) + 1;
        foreach ($afterMatches[0] ?? [] as $match) {
            if (($counts[$match] ?? 0) > 0) {
                $counts[$match]--;
            } else {
                return true;
            }
        }

        return false;
    }

    private function linkDomains(string $content): array
    {
        $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        preg_match_all('/\b(?:href|src)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'`=<>]+))/i', $content, $matches, PREG_SET_ORDER);
        $domains = [];
        foreach ($matches as $match) {
            $url = (string) (($match[1] ?? '') !== '' ? $match[1] : ((($match[2] ?? '') !== '') ? $match[2] : ($match[3] ?? '')));
            if (! is_string($url) || preg_match('/^https?:\/\//i', $url) !== 1) continue;
            $host = parse_url($url, PHP_URL_HOST);
            if (! is_string($host) || $host === '') continue;
            $host = strtolower(rtrim($host, '.'));
            if (function_exists('idn_to_ascii')) $host = strtolower((string) (idn_to_ascii($host) ?: $host));
            if (preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $host) === 1) $domains[$host] = true;
        }
        $result = array_keys($domains);
        sort($result, SORT_STRING);
        return $result;
    }

    private function assertGutenbergBalanced(string $content): void
    {
        // WordPress serializes core blocks as `wp:paragraph` (without the
        // internal `core/` namespace), while registered blocks may use
        // `wp:vendor/block`. Accept both forms and compare their delimiters
        // exactly so malformed or mismatched block trees fail closed.
        preg_match_all('/<!--\s*(\/?)wp:([a-z0-9_-]+(?:\/[a-z0-9_-]+)?)(.*?)-->/is', $content, $tokens, PREG_SET_ORDER);
        $stack = [];
        foreach ($tokens as $token) {
            $closing = ($token[1] ?? '') === '/';
            $name = strtolower((string) ($token[2] ?? ''));
            $tail = (string) ($token[3] ?? '');
            $selfClosing = ! $closing && preg_match('/\/\s*$/', $tail) === 1;
            if ($closing) {
                if (array_pop($stack) !== $name) throw new RuntimeException('Les délimiteurs Gutenberg ne sont pas équilibrés; la proposition est bloquée.');
            } elseif (! $selfClosing) {
                $stack[] = $name;
                if (count($stack) > 128) throw new RuntimeException('L’imbrication Gutenberg dépasse la limite autorisée.');
            }
        }
        if ($stack !== []) throw new RuntimeException('Les délimiteurs Gutenberg ne sont pas équilibrés; la proposition est bloquée.');
    }

    private function assertSafeContentChange(string $before, string $after): void
    {
        if ($before === $after) return;
        $builderMarkers = [
            '/\belementor\b/i', '/\bet_pb_[a-z0-9_]+/i',
            '/\[(?:vc|vc_row|vc_column|vc_column_text)[^\]]*\]/i',
            '/\b(?:fl-builder|beaverbuilder|brizy|oxygen)\b/i', '/\bwpb_[a-z0-9_-]+\b/i',
        ];
        foreach ($builderMarkers as $marker) {
            if (preg_match($marker, $before) === 1 || preg_match($marker, $after) === 1) {
                throw new RuntimeException('Le contenu appartient à un constructeur de pages non reconnu; sa modification REST est bloquée.');
            }
        }
        if (str_contains($before, '<!-- wp:') || str_contains($after, '<!-- wp:')) $this->assertGutenbergBalanced($after);
    }

    private function resolveType(DevPilotConnection $connection, array $credentials, string $root, string $type): array
    {
        if (in_array(strtolower($type), ['user', 'users', 'role', 'roles', 'application_password', 'application-passwords'], true)) {
            throw new RuntimeException('La création ou la gestion REST des utilisateurs, rôles et mots de passe d’application est bloquée.');
        }
        $types = $this->read($connection, $credentials, $root, '/wp/v2/types', ['context' => 'edit', '_fields' => 'slug,rest_base,rest_namespace,viewable']);
        $definition = $types[$type] ?? null;
        if (! is_array($definition) || ($definition['viewable'] ?? true) !== true) throw new RuntimeException('Ce type de contenu n’est pas exposé en REST ou n’est pas modifiable.');
        $base = (string) ($definition['rest_base'] ?? '');
        $namespace = (string) ($definition['rest_namespace'] ?? 'wp/v2');
        if (preg_match('/^[A-Za-z0-9_-]{1,80}$/D', $base) !== 1 || preg_match('#^[A-Za-z0-9_-]{1,80}/[A-Za-z0-9_-]{1,80}$#D', $namespace) !== 1) throw new RuntimeException('La route du type WordPress ne peut pas être construite de façon sûre.');
        return ['rest_base' => $base, 'namespace' => $namespace];
    }

    private function assertNoDuplicate(DevPilotConnection $connection, array $credentials, string $root, array $definition, array $fields): void
    {
        $route = '/'.$definition['namespace'].'/'.$definition['rest_base'];
        $queries = [['slug' => (string) $fields['slug']], ['search' => (string) ($fields['title'] ?? '')]];
        foreach ($queries as $query) {
            $totalPages = null;
            for ($page = 1; $page <= 50; $page++) {
                $result = $this->client->readResource($connection->toArray(), $credentials, $root, $route, $query + [
                    'context' => 'edit', 'page' => $page, 'per_page' => 10, '_fields' => 'id,slug,title',
                ]);
                $totalPages = $result['total_pages'] ?? $totalPages;
                if (is_int($totalPages) && $totalPages > 50) throw new RuntimeException('La recherche de doublons dépasse la limite de sécurité; création bloquée.');
                foreach ($result['data'] as $record) {
                    if (! is_array($record)) continue;
                    $sameSlug = strtolower((string) ($record['slug'] ?? '')) === strtolower((string) $fields['slug']);
                    $sameTitle = isset($fields['title']) && mb_strtolower((string) $this->raw($record['title'] ?? null)) === mb_strtolower((string) $fields['title']);
                    if ($sameSlug || $sameTitle) throw new RuntimeException('Un contenu existant possède déjà ce slug ou ce titre; aucun doublon ne sera créé.');
                }
                if (is_int($totalPages) ? $page >= $totalPages : count($result['data']) < 10) break;
                if ($page === 50) throw new RuntimeException('La recherche de doublons ne peut pas confirmer toute la collection; création bloquée.');
            }
        }
    }

    private function readInstalledPlugin(DevPilotConnection $connection, array $credentials, string $root, string $slug): ?array
    {
        $totalPages = null;
        for ($page = 1; $page <= 50; $page++) {
            $plugins = $this->client->readResource($connection->toArray(), $credentials, $root, '/wp/v2/plugins', [
                'context' => 'edit', 'page' => $page, 'per_page' => 10, '_fields' => 'plugin,status,version',
            ]);
            $totalPages = $plugins['total_pages'] ?? $totalPages;
            if (is_int($totalPages) && $totalPages > 50) throw new RuntimeException('La liste des plugins dépasse la limite de sécurité.');
            foreach ($plugins['data'] as $plugin) {
                if (! is_array($plugin) || ! is_string($plugin['plugin'] ?? null)) continue;
                $id = (string) $plugin['plugin'];
                if ($id === $slug || str_starts_with($id, $slug.'/')) return $plugin;
            }
            if (is_int($totalPages) ? $page >= $totalPages : count($plugins['data']) < 10) return null;
        }
        throw new RuntimeException('La liste des plugins ne peut pas être entièrement vérifiée dans la limite de sécurité.');
    }

    private function read(DevPilotConnection $connection, array $credentials, string $root, string $route, array $query = []): array
    {
        $query += str_ends_with($route, '/settings')
            ? ['context' => 'edit']
            : ['context' => 'edit', '_fields' => 'id,modified,slug,status,type,title,excerpt,content,link,author,featured_media,parent,menu_order,plugin,version'];
        $result = $this->client->readResource($connection->toArray(), $credentials, $root, $route, $query);
        return $result['data'];
    }

    private function contentRecord(array $record): array
    {
        return [
            'id' => is_numeric($record['id'] ?? null) ? (int) $record['id'] : null,
            'modified' => is_string($record['modified'] ?? null) ? $record['modified'] : null,
            'slug' => $this->raw($record['slug'] ?? null), 'status' => $this->raw($record['status'] ?? null),
            'title' => $this->raw($record['title'] ?? null), 'excerpt' => $this->raw($record['excerpt'] ?? null),
            'content' => $this->raw($record['content'] ?? null), 'link' => $record['link'] ?? null,
        ];
    }

    private function raw(mixed $value): mixed
    {
        return is_array($value) ? ($value['raw'] ?? $value['rendered'] ?? '') : $value;
    }

    private function removeExactNoOps(array $fields, array $before): array
    {
        foreach ($fields as $key => $value) {
            if (array_key_exists($key, $before) && $value === $before[$key]) unset($fields[$key]);
        }
        return $fields;
    }

    private function matchesFields(array $actual, array $expected): bool
    {
        foreach ($expected as $key => $value) if ($this->raw($actual[$key] ?? null) !== $value) return false;
        return true;
    }

    private function recordHash(array $record, array $fields): string
    {
        $normalized = [];
        foreach ($fields as $key) $normalized[$key] = $this->raw($record[$key] ?? null);
        return hash('sha256', json_encode([
            'modified' => $record['modified'] ?? null, 'fields' => $normalized,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function fieldDiff(array $before, array $after): array
    {
        $diffs = [];
        foreach ($after as $field => $value) {
            $old = $before[$field] ?? '';
            $oldText = is_scalar($old) ? (string) $old : (json_encode($old, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
            $newText = is_scalar($value) ? (string) $value : (json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
            $diffs[$field] = ['before' => $oldText, 'after' => $newText];
        }
        return $diffs;
    }

    private function identifier(mixed $value, string $label): string
    {
        if (! is_string($value) || preg_match('/^[A-Za-z0-9_-]{1,80}$/D', $value) !== 1) throw new RuntimeException('Identifiant de '.$label.' invalide.');
        return $value;
    }

    private function positiveId(mixed $value): int
    {
        if ((! is_int($value) && !(is_string($value) && ctype_digit($value))) || (int) $value < 1 || (int) $value > 2147483647) throw new RuntimeException('Identifiant WordPress positif requis.');
        return (int) $value;
    }

    private function pluginSlug(mixed $value): string
    {
        if (! is_string($value) || preg_match('/^[a-z0-9][a-z0-9-]{0,99}$/D', $value) !== 1) throw new RuntimeException('Le plugin doit être désigné par un slug WordPress.org valide, sans URL ni archive.');
        return $value;
    }
}
