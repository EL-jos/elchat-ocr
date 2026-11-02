<?php

namespace App\Services\DevPilot;

use App\Domain\MCP\Security\CredentialVault;
use App\Models\DevPilot\DevPilotConnection;
use App\Models\Site;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** One-time, tenant-bound pairing flow for the ELChat-DevPilot WordPress plugin. */
final class DevPilotWordPressBridgePairingService
{
    private const ALLOWED_CAPABILITIES = [
        'content.read', 'content.search', 'content.create', 'content.update',
        'content.update.title', 'content.update.slug', 'content.update.excerpt', 'content.update.body',
        'content.status.change', 'content.publish', 'content.featured_media', 'content.trash', 'media.upload', 'media.read',
        'content.featured_media.remove', 'content.restore', 'content.taxonomy.assign', 'content.taxonomy.create',
        'content.schedule', 'content.search.unpublished', 'media.search', 'media.update_metadata',
        'content.visibility.private', 'content.password', 'content.author.change', 'content.discussion.manage',
        'content.page.structure', 'content.seo.manage', 'content.revisions.read', 'content.revisions.restore',
        'plugins.read', 'plugins.updates.read', 'plugins.directory.read', 'plugins.integrity.read', 'site.health.read',
        'plugins.activate', 'plugins.deactivate', 'plugins.network_toggle', 'plugins.install_directory', 'plugins.update',
        'plugins.update_bulk', 'plugins.rollback', 'plugins.autoupdate', 'plugins.delete', 'plugins.install_zip',
        'plugins.settings.read', 'plugins.settings.write', 'plugins.maintenance', 'plugins.snapshot.create', 'plugins.snapshot.restore',
    ];

    public function __construct(
        private readonly CredentialVault $vault,
        private readonly DevPilotWordPressClient $wordpress,
        private readonly DevPilotWordPressBridgeChannel $bridge,
    ) {}

    public function pair(Site $site, array $input): DevPilotConnection
    {
        $name = trim((string) ($input['name'] ?? ''));
        $username = trim((string) ($input['username'] ?? ''));
        $password = DevPilotWordPressClient::normalizeApplicationPassword((string) ($input['application_password'] ?? ''));
        $pairingCode = trim((string) ($input['pairing_code'] ?? ''));
        $requested = $input['capabilities'] ?? null;
        if ($name === '' || $username === '' || $password === '' || $pairingCode === ''
            || strlen($password) > 4096 || strlen($pairingCode) > 256 || ! is_array($requested) || $requested === []) {
            throw new RuntimeException('Le nom, l’utilisateur, le mot de passe d’application, le code temporaire et les permissions WordPress sont obligatoires.');
        }
        $requested = $this->validateCapabilities($requested);
        $canonical = DevPilotWordPressUrl::normalize((string) ($input['site_url'] ?? ''));
        $privateNetworkAuthorized = (bool) ($input['private_network_authorized'] ?? false);
        $attributes = [
            'name' => mb_strcut($name, 0, 120), 'site_id' => (string) $site->id, 'protocol' => 'wordpress',
            'site_url' => $canonical['site_url'], 'host' => $canonical['host'], 'port' => $canonical['port'],
            'username' => mb_strcut($username, 0, 128), 'auth_method' => 'devpilot_bridge_hmac',
            'private_network_authorized' => $privateNetworkAuthorized,
            'can_read' => (bool) array_intersect($requested, ['content.read', 'content.search', 'content.search.unpublished', 'media.read', 'media.search', 'plugins.read', 'plugins.updates.read', 'plugins.directory.read', 'plugins.integrity.read', 'plugins.settings.read', 'plugins.snapshot.create', 'site.health.read']),
            'can_write' => (bool) array_intersect($requested, ['content.create', 'content.update', 'content.update.title', 'content.update.slug', 'content.update.excerpt', 'content.update.body', 'content.status.change', 'content.publish', 'content.visibility.private', 'content.password', 'content.author.change', 'content.discussion.manage', 'content.page.structure', 'content.seo.manage', 'content.featured_media', 'content.featured_media.remove', 'content.trash', 'content.restore', 'content.taxonomy.assign', 'content.taxonomy.create', 'content.schedule', 'content.revisions.restore', 'media.upload', 'media.update_metadata', 'plugins.activate', 'plugins.deactivate', 'plugins.network_toggle', 'plugins.install_directory', 'plugins.update', 'plugins.update_bulk', 'plugins.rollback', 'plugins.autoupdate', 'plugins.delete', 'plugins.install_zip', 'plugins.settings.write', 'plugins.maintenance', 'plugins.snapshot.create', 'plugins.snapshot.restore']),
            'can_delete' => in_array('content.trash', $requested, true) || in_array('plugins.delete', $requested, true),
            'can_run_commands' => false, 'command_execution_mode' => 'disabled', 'can_manage_databases' => false,
            'capabilities' => [],
        ];

        // Validate URL, TLS, DNS pinning and the dedicated App Password before consuming the one-time code.
        $probe = $this->wordpress->testConnection($attributes, ['application_password' => $password]);
        $rootForm = (string) ($probe['capabilities']['rest_root_form'] ?? '');
        if (! in_array($rootForm, ['wp-json', 'rest_route'], true)) {
            throw new RuntimeException('La racine REST WordPress n’a pas été détectée; aucun code de jumelage n’a été consommé.');
        }
        $attributes['capabilities'] = [
            'rest_api' => true, 'rest_root_form' => $rootForm,
            'namespaces' => ['elchat-devpilot/v1'], 'application_password_auth' => false,
            'bridge' => ['status' => 'pairing'],
        ];

        $connectionId = (string) Str::uuid();
        $keyId = strtolower((string) Str::uuid());
        $rawSecret = random_bytes(32);
        $encodedSecret = self::base64Url($rawSecret);
        $credentials = [
            'bridge_key_id' => $keyId,
            'bridge_key_secret' => $encodedSecret,
        ];
        $connection = new DevPilotConnection([
            ...$attributes,
            'id' => $connectionId,
            'site_id' => (string) $site->id,
            'status' => 'pairing',
            'credentials_encrypted' => $this->vault->seal(DevPilotConnectionCredentials::bind((string) $site->id, $attributes, $credentials)),
        ]);
        $connection->save();

        $pairAccepted = false;
        try {
            $pairRequest = [
                'pairing_code' => $pairingCode,
                'protocol_min' => ['major' => 1, 'minor' => 0],
                'protocol_max' => ['major' => 1, 'minor' => 0],
                'key_id' => $keyId,
                'key_secret' => $encodedSecret,
                'capabilities' => $requested,
            ];
            $response = $this->bridge->pair($connection, $pairRequest, $password);
            if ($response->status() < 200 || $response->status() >= 300) {
                if ($response->status() >= 500 || $response->status() === 408) {
                    return $this->markUncertain(
                        $connection,
                        'http_error_response',
                        $response->status(),
                        null,
                        $this->safeRemotePairingErrorCode($response->json()),
                    );
                }
                $failure = $this->pairFailure($response->status(), $response->json());
                $connection->forceFill([
                    'status' => 'error', 'credentials_encrypted' => null,
                    'last_test_result' => ['connected' => false, 'protocol' => 'wordpress', 'error' => $failure],
                    'last_tested_at' => now(),
                ])->save();
                throw new RuntimeException($failure);
            }

            $pairAccepted = true;
            $data = $response->json();
            $identity = $this->validatePairResponse($data, $keyId, $canonical['site_url']);
            $pairSchemas = DevPilotWordPressBridgeChannel::validateCapabilityDescriptors($data['capabilities']);
            foreach ($requested as $capability) {
                if (! isset($pairSchemas[$capability])) throw new RuntimeException('Le plugin n’a pas confirmé toutes les permissions demandées.');
            }
            $bridgeSnapshot = [
                'protocol' => DevPilotWordPressBridgeChannel::PROTOCOL,
                'installation_id' => $identity['installation_id'],
                'blog_id' => $identity['blog_id'],
                'network_id' => $identity['network_id'],
                'canonical_site_url' => $identity['canonical_site_url'],
                'plugin_version' => mb_strcut((string) ($data['plugin_version'] ?? ''), 0, 32),
                'key_id' => $keyId,
                'key_fingerprint' => hash('sha256', $rawSecret),
                'capability_schemas' => $pairSchemas,
                'requested_capabilities' => $requested,
            ];
            $connection->capabilities = [...$attributes['capabilities'], 'bridge' => $bridgeSnapshot];
            $credentials['bridge_blog_id'] = (string) $identity['blog_id'];
            $credentials['bridge_installation_id'] = $identity['installation_id'];
            $credentials['bridge_network_id'] = $identity['network_id'];
            $connection->credentials_encrypted = $this->vault->seal(DevPilotConnectionCredentials::bind((string) $site->id, $attributes, $credentials));
            $connection->save();

            $live = $this->bridge->inspectBinding($connection, $credentials);
            foreach ($requested as $capability) {
                if (! isset($live['capability_schemas'][$capability])) {
                    throw new RuntimeException('Le plugin a accepté le jumelage, mais une permission demandée n’est plus disponible.');
                }
            }
            $connection->capabilities = [
                ...$attributes['capabilities'],
                'bridge' => [...$bridgeSnapshot, 'capability_schemas' => $live['capability_schemas']],
                'content_types' => $this->safeContentTypes($live['capabilities']['content_types']),
                'bridge_limits' => $this->safeLimits($live['capabilities']['limits'] ?? []),
            ];
            $connection->status = 'connected';
            $connection->last_test_result = ['connected' => true, 'protocol' => 'wordpress', 'channel' => 'bridge', 'capabilities' => $connection->capabilities];
            $connection->last_tested_at = now();
            $connection->save();

            return $connection->fresh();
        } catch (DevPilotOperationUncertainException $exception) {
            return $this->markUncertain($connection, 'transport_response_uncertain', null, $exception->getMessage());
        } catch (Throwable $exception) {
            if ($connection->status === 'pairing') {
                // A successful /pair followed by failed signed confirmation cannot be retried blindly.
                if ($pairAccepted || isset($credentials['bridge_blog_id'])) {
                    return $this->markUncertain($connection, 'pair_response_confirmation_failed', null, $exception->getMessage());
                }
                $connection->forceFill([
                    'status' => 'error', 'last_test_result' => ['connected' => false, 'protocol' => 'wordpress', 'error' => 'Le jumelage n’a pas été confirmé. Vérifiez le code et l’état du pont dans WordPress avant une nouvelle tentative.'],
                    'last_tested_at' => now(),
                ])->save();
            }
            if ($exception instanceof RuntimeException) throw $exception;
            throw new RuntimeException('Le jumelage WordPress a échoué. Vérifiez le réseau et l’état de la liaison côté WordPress.');
        } finally {
            unset($password, $pairingCode, $encodedSecret, $credentials);
            if (function_exists('sodium_memzero')) sodium_memzero($rawSecret);
        }
    }

    public function test(DevPilotConnection $connection, array $credentials): array
    {
        if ($connection->status === 'pairing_uncertain'
            && (! is_string($credentials['bridge_blog_id'] ?? null) || ! ctype_digit($credentials['bridge_blog_id']))) {
            throw new RuntimeException('Le jumelage reste incertain et ne peut pas être testé automatiquement, car WordPress n’a pas confirmé l’identité du blog. Vérifiez Réglages → ELChat DevPilot dans WordPress, puis révoquez le jumelage si l’installation y apparaît comme jumelée.');
        }

        $binding = $this->bridge->inspectBinding($connection, $credentials);
        $bridge = $connection->capabilities['bridge'] ?? [];
        $capabilities = [
            'rest_api' => true,
            'rest_root_form' => $connection->capabilities['rest_root_form'] ?? null,
            'namespaces' => ['elchat-devpilot/v1'],
            'application_password_auth' => false,
            'bridge' => [
                ...$bridge,
                'capability_schemas' => $binding['capability_schemas'],
                'live_capability_ids' => array_keys($binding['capability_schemas']),
            ],
            'content_types' => $this->safeContentTypes($binding['capabilities']['content_types']),
            'bridge_limits' => $this->safeLimits($binding['capabilities']['limits'] ?? []),
        ];
        return ['connected' => true, 'protocol' => 'wordpress', 'channel' => 'bridge', 'capabilities' => $capabilities];
    }

    private function validateCapabilities(array $requested): array
    {
        if (count($requested) > count(self::ALLOWED_CAPABILITIES) || array_values($requested) !== $requested) {
            throw new RuntimeException('La liste de permissions du pont est invalide.');
        }
        $result = [];
        foreach ($requested as $capability) {
            if (! is_string($capability) || ! in_array($capability, self::ALLOWED_CAPABILITIES, true)) {
                throw new RuntimeException('Le pont WordPress n’autorise que les permissions de contenu explicitement prises en charge.');
            }
            if (! in_array($capability, $result, true)) $result[] = $capability;
        }
        if (in_array('content.create', $result, true) && ! in_array('content.search', $result, true)) {
            throw new RuntimeException('La création exige aussi content.search pour vérifier les doublons.');
        }
        if (array_intersect($result, ['content.create', 'content.update', 'content.update.title', 'content.update.slug', 'content.update.excerpt', 'content.update.body', 'content.status.change', 'content.publish', 'content.visibility.private', 'content.password', 'content.author.change', 'content.discussion.manage', 'content.page.structure', 'content.seo.manage', 'content.featured_media', 'content.featured_media.remove', 'content.trash', 'content.restore', 'content.taxonomy.assign', 'content.taxonomy.create', 'content.schedule', 'content.revisions.restore']) !== []
            && ! in_array('content.read', $result, true)) {
            throw new RuntimeException('La création, la modification, la publication et la corbeille exigent aussi content.read.');
        }
        if (in_array('content.taxonomy.create', $result, true) && ! in_array('content.taxonomy.assign', $result, true)) {
            throw new RuntimeException('La création de termes exige aussi l’assignation des catégories et étiquettes.');
        }
        if (in_array('content.search.unpublished', $result, true) && ! in_array('content.search', $result, true)) {
            throw new RuntimeException('La recherche de contenus non publiés exige aussi content.search.');
        }
        if (array_intersect($result, ['media.upload', 'media.update_metadata']) !== [] && ! in_array('media.read', $result, true)) {
            throw new RuntimeException('Le téléversement exige aussi media.read pour vérifier l’empreinte du fichier.');
        }
        if (in_array('content.revisions.restore', $result, true) && ! in_array('content.revisions.read', $result, true)) {
            throw new RuntimeException('La restauration d’une révision exige aussi content.revisions.read.');
        }
        $pluginMutations = ['plugins.activate', 'plugins.deactivate', 'plugins.network_toggle', 'plugins.install_directory', 'plugins.update', 'plugins.update_bulk', 'plugins.rollback', 'plugins.autoupdate', 'plugins.delete', 'plugins.install_zip', 'plugins.settings.read', 'plugins.settings.write', 'plugins.maintenance', 'plugins.snapshot.create', 'plugins.snapshot.restore'];
        if (array_intersect($result, $pluginMutations) !== [] && ! in_array('plugins.read', $result, true)) throw new RuntimeException('La gestion des extensions exige aussi plugins.read.');
        if (array_intersect($result, ['plugins.update', 'plugins.update_bulk', 'plugins.rollback']) !== []
            && (! in_array('plugins.updates.read', $result, true) || ! in_array('plugins.snapshot.create', $result, true))) {
            throw new RuntimeException('La mise à jour et le retour arrière exigent aussi plugins.updates.read et plugins.snapshot.create.');
        }
        if (in_array('plugins.settings.write', $result, true) && ! in_array('plugins.settings.read', $result, true)) throw new RuntimeException('La modification des réglages exige aussi plugins.settings.read.');
        if (array_intersect($result, ['plugins.delete', 'plugins.snapshot.restore']) !== [] && ! in_array('plugins.snapshot.create', $result, true)) {
            throw new RuntimeException('La suppression et la restauration exigent aussi plugins.snapshot.create.');
        }
        return $result;
    }

    private function validatePairResponse(mixed $data, string $keyId, string $canonicalSite): array
    {
        if (! is_array($data) || ($data['protocol']['major'] ?? null) !== 1 || ($data['protocol']['minor'] ?? null) !== 0
            || ! is_string($data['installation_id'] ?? null) || ! $this->isUuid($data['installation_id'])
            || ! is_numeric($data['blog_id'] ?? null) || (int) $data['blog_id'] < 1
            || ! is_string($data['key_id'] ?? null) || ! hash_equals($keyId, strtolower($data['key_id']))
            || ! is_string($data['canonical_site_url'] ?? null) || ! is_array($data['capabilities'] ?? null)) {
            throw new RuntimeException('La réponse de jumelage du plugin WordPress est invalide.');
        }
        $remote = DevPilotWordPressUrl::normalize($data['canonical_site_url'])['site_url'];
        if (! hash_equals($canonicalSite, $remote)) {
            throw new RuntimeException('L’URL canonique WordPress ne correspond pas à la connexion. Corrigez l’URL puis jumelez de nouveau.');
        }
        return [
            'installation_id' => strtolower($data['installation_id']),
            'blog_id' => (int) $data['blog_id'],
            'network_id' => is_numeric($data['network_id'] ?? null) ? (int) $data['network_id'] : null,
            'canonical_site_url' => $remote,
        ];
    }

    private function markUncertain(
        DevPilotConnection $connection,
        string $reason,
        ?int $httpStatus = null,
        ?string $detail = null,
        ?string $remoteErrorCode = null,
    ): DevPilotConnection
    {
        Log::warning('DevPilot WordPress bridge pairing outcome is uncertain.', [
            'connection_id' => (string) $connection->id,
            'site_id' => (string) $connection->site_id,
            'reason' => $reason,
            'http_status' => $httpStatus,
            'detail' => $detail === null ? null : mb_strcut($detail, 0, 300),
            'remote_error_code' => $remoteErrorCode,
        ]);
        $connection->forceFill([
            'status' => 'pairing_uncertain',
            'last_test_result' => [
                'connected' => false, 'protocol' => 'wordpress', 'channel' => 'bridge',
                'diagnostic_code' => $reason, 'http_status' => $httpStatus,
                'remote_error_code' => $remoteErrorCode,
                'error' => 'Le serveur n’a pas pu confirmer la fin du jumelage. Ne relancez pas le code : vérifiez ou révoquez la liaison dans WordPress.',
            ],
            'last_tested_at' => now(),
        ])->save();
        return $connection->fresh();
    }

    private function pairFailure(int $status, mixed $responseData): string
    {
        $wordpressCode = is_array($responseData) && is_string($responseData['code'] ?? null)
            ? $responseData['code'] : '';

        if ($status === 403) {
            return match ($wordpressCode) {
                'elchat_pairing_forbidden' => 'Le jumelage exige un mot de passe d’application valide associé à un administrateur WordPress disposant de manage_options. Vérifiez le compte et son rôle dans WordPress.',
                'elchat_pairing_code_invalid' => 'Le code temporaire WordPress est invalide, expiré ou a atteint sa limite d’essais. Générez un nouveau code et recommencez.',
                'elchat_capabilities_not_granted' => 'Le plugin WordPress n’a pas approuvé toutes les capacités demandées. Approuvez les mêmes capacités dans ses réglages, puis générez un nouveau code.',
                'elchat_https_required' => 'WordPress ne reconnaît pas la requête de jumelage comme HTTPS. Vérifiez la configuration HTTPS du proxy ou de l’hébergement WordPress.',
                default => 'WordPress a refusé le jumelage (HTTP 403). Vérifiez le rôle administrateur, le code temporaire et les règles de sécurité qui filtrent le POST du pont.',
            };
        }

        return match ($status) {
            401 => 'WordPress a refusé l’authentification du POST de jumelage (HTTP 401). Vérifiez le login, le mot de passe d’application actif et la transmission de l’en-tête Authorization pour les requêtes POST.',
            409 => 'Ce site est déjà jumelé. Révoquez l’ancienne liaison dans WordPress avant de créer un nouveau jumelage.',
            426 => 'La version du protocole du plugin WordPress n’est pas compatible avec ELChat.',
            429 => 'WordPress limite les tentatives de jumelage. Attendez avant de réessayer.',
            default => 'WordPress a refusé le jumelage. Vérifiez le code temporaire et les permissions approuvées dans le plugin.',
        };
    }

    private function safeRemotePairingErrorCode(mixed $responseData): ?string
    {
        $code = is_array($responseData) && is_string($responseData['code'] ?? null)
            ? $responseData['code'] : null;
        $safeCodes = [
            'elchat_storage_unavailable', 'elchat_pairing_storage_failed',
            'elchat_pairing_code_consumed', 'elchat_pairing_code_invalid',
            'elchat_capabilities_not_granted', 'elchat_pairing_forbidden',
            'rest_unknown_error', 'internal_server_error',
        ];

        return is_string($code) && in_array($code, $safeCodes, true) ? $code : null;
    }

    private function safeContentTypes(mixed $types): array
    {
        if (! is_array($types)) return [];
        $safe = [];
        foreach (array_slice($types, 0, 100) as $type) {
            if (! is_array($type) || ! is_string($type['id'] ?? null)
                || preg_match('/^[a-z0-9_-]{1,32}$/D', $type['id']) !== 1
                || ! is_string($type['rest_base'] ?? null)
                || preg_match('/^[a-z0-9_-]{1,32}$/D', $type['rest_base']) !== 1) continue;
            $safe[] = [
                'id' => $type['id'], 'rest_base' => $type['rest_base'],
                'label' => mb_strcut(strip_tags((string) ($type['label'] ?? '')), 0, 256),
                'supports' => array_values(array_intersect(['title', 'editor', 'excerpt', 'thumbnail'], is_array($type['supports'] ?? null) ? $type['supports'] : [])),
            ];
        }
        return $safe;
    }

    private function safeLimits(mixed $limits): array
    {
        if (! is_array($limits)) return [];
        $safe = [];
        foreach (['request_body_bytes', 'content_body_bytes', 'excerpt_bytes', 'max_page_size', 'request_rate_per_minute', 'upload_max_bytes', 'devpilot_media_max_bytes'] as $key) {
            if (is_int($limits[$key] ?? null) && $limits[$key] >= 0 && $limits[$key] <= 1073741824) $safe[$key] = $limits[$key];
        }
        return $safe;
    }

    private function isUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/Di', $value) === 1;
    }

    private static function base64Url(string $value): string { return rtrim(strtr(base64_encode($value), '+/', '-_'), '='); }
}
