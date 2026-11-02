<?php

namespace App\Services\DevPilot;

use App\Models\DevPilot\DevPilotConnection;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use RuntimeException;

/** HMAC v1 adapter for the ELChat-DevPilot WordPress plugin's fixed route set. */
final class DevPilotWordPressBridgeChannel
{
    public const PROTOCOL = '1.0';
    private const CAPABILITY_IDS = [
        'bridge.health', 'bridge.identity', 'bridge.capabilities', 'content.read', 'content.search',
        'content.create', 'content.update', 'content.update.title', 'content.update.slug', 'content.update.excerpt',
        'content.update.body', 'content.status.change', 'content.publish', 'content.featured_media', 'content.trash',
        'content.featured_media.remove', 'content.restore', 'content.taxonomy.assign', 'content.taxonomy.create',
        'content.schedule', 'content.search.unpublished', 'media.search', 'media.update_metadata',
        'content.visibility.private', 'content.password', 'content.author.change', 'content.discussion.manage',
        'content.page.structure', 'content.seo.manage', 'content.revisions.read', 'content.revisions.restore',
        'operation.status', 'operation.readback', 'media.upload', 'media.read',
        'plugins.read', 'plugins.updates.read', 'plugins.directory.read', 'plugins.integrity.read', 'site.health.read',
        'plugins.activate', 'plugins.deactivate', 'plugins.network_toggle', 'plugins.install_directory', 'plugins.update',
        'plugins.update_bulk', 'plugins.rollback', 'plugins.autoupdate', 'plugins.delete', 'plugins.install_zip',
        'plugins.settings.read', 'plugins.settings.write', 'plugins.maintenance', 'plugins.snapshot.create', 'plugins.snapshot.restore',
    ];
    private const MAX_CAPABILITY_DESCRIPTORS = 100;

    public function __construct(private readonly DevPilotWordPressClient $client) {}

    /** Pairing is the only unsigned plugin request and is authenticated with a one-time WordPress App Password. */
    public function pair(DevPilotConnection $connection, array $request, string $applicationPassword): Response
    {
        return $this->client->requestPluginRoute(
            $connection->toArray(), (string) ($connection->capabilities['rest_root_form'] ?? ''),
            'POST', '/elchat-devpilot/v1/pair', body: $request,
            headers: ['Accept' => 'application/json'], username: (string) $connection->username,
            password: DevPilotWordPressClient::normalizeApplicationPassword($applicationPassword),
        );
    }

    /** Make one authenticated plugin request; route and method are checked by the secure transport too. */
    public function request(
        DevPilotConnection $connection,
        array $credentials,
        string $method,
        string $route,
        array $query = [],
        ?array $body = null,
        ?string $idempotencyKey = null,
        ?string $rawBody = null,
    ): array {
        $method = strtoupper($method);
        $keyId = strtolower((string) ($credentials['bridge_key_id'] ?? ''));
        $blogId = (string) ($credentials['bridge_blog_id'] ?? '');
        $secret = self::decodeBase64Url((string) ($credentials['bridge_key_secret'] ?? ''));
        if (! self::isUuid($keyId) || ! ctype_digit($blogId) || (int) $blogId < 1 || strlen($secret) !== 32) {
            throw new RuntimeException('Les identifiants du canal HMAC WordPress sont invalides.');
        }
        if ($method === 'GET') {
            if ($idempotencyKey !== null || $body !== null || $rawBody !== null) throw new RuntimeException('La requête de lecture du pont est mal formée.');
        } elseif (! self::isUuid((string) $idempotencyKey) || (($body === null) === ($rawBody === null))) {
            throw new RuntimeException('La mutation du pont exige un corps et une clé d’idempotence UUID.');
        }

        $binaryUpload = $rawBody !== null;
        if ($binaryUpload && ($method !== 'POST' || ! in_array($route, ['/elchat-devpilot/v1/media', '/elchat-devpilot/v1/plugins/install-zip'], true))) {
            throw new DevPilotOperationRejectedException('Le corps binaire est autorisé uniquement pour les téléversements média ou ZIP explicitement prévus.');
        }
        $bodyBytes = $method === 'GET' ? '' : ($rawBody ?? json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $binaryLimit = $route === '/elchat-devpilot/v1/plugins/install-zip' ? DevPilotTaskAttachmentService::MAX_FILE_BYTES : DevPilotTaskAttachmentService::MAX_IMAGE_BYTES;
        if (strlen($bodyBytes) > ($binaryUpload ? $binaryLimit : 262144)) throw new RuntimeException('Le corps de l’opération dépasse la limite du pont.');
        $bodyHash = hash('sha256', $bodyBytes);
        $timestamp = (string) time();
        $nonce = self::base64Url(random_bytes(32));
        $requestId = strtolower((string) Str::uuid());
        $canonicalQuery = self::canonicalQuery($query);
        $canonical = implode("\n", [
            'ELCHAT-DEV-PILOT-REQUEST', self::PROTOCOL, $keyId, $blogId, $method, $route,
            $canonicalQuery, (string) ($idempotencyKey ?? ''), $bodyHash, $timestamp, $nonce, $requestId,
        ]);
        $headers = [
            'X-ELChat-Protocol' => self::PROTOCOL,
            'X-ELChat-Key-Id' => $keyId,
            'X-ELChat-Blog-Id' => $blogId,
            'X-ELChat-Timestamp' => $timestamp,
            'X-ELChat-Nonce' => $nonce,
            'X-ELChat-Request-Id' => $requestId,
            'X-ELChat-Body-SHA256' => $bodyHash,
            'X-ELChat-Signature' => self::base64Url(hash_hmac('sha256', $canonical, $secret, true)),
            'Accept' => 'application/json',
        ];
        if ($method !== 'GET') $headers['Idempotency-Key'] = (string) $idempotencyKey;

        $rootForm = (string) ($connection->capabilities['rest_root_form'] ?? '');
        try {
            if ($binaryUpload) $headers['Content-Type'] = 'application/octet-stream';
            $response = $this->client->requestPluginRoute(
                $connection->toArray(), $rootForm, $method, $route, $query, $binaryUpload ? null : $body, $headers,
                rawBody: $rawBody,
            );
        } catch (DevPilotOperationUncertainException $exception) {
            throw $exception;
        } catch (DevPilotOperationRejectedException $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            throw new RuntimeException('La requête au pont HMAC ELChat-DevPilot a échoué : '.$exception->getMessage(), 0, $exception);
        }

        $data = $this->verifyResponse($response, $requestId, $keyId, $blogId, $secret);
        if ($response->status() === 202 && ($data['state'] ?? null) === 'unknown') {
            throw new DevPilotOperationUncertainException('Le plugin WordPress a marqué cette mutation comme incertaine. DevPilot ne la relancera pas; relisez son état avant toute suite.');
        }
        if ($response->status() >= 400 && $response->status() < 500) {
            // A signed plugin 4xx is a deterministic rejection before the content mutation completes.
            throw new DevPilotOperationRejectedException($this->safeHttpFailure($response->status(), $data));
        }
        if ($response->status() >= 500) {
            throw new DevPilotOperationUncertainException($this->safeHttpFailure($response->status(), $data).' Vérifiez l’état avant toute nouvelle action.');
        }
        if ($response->status() < 200 || $response->status() >= 300) {
            throw new RuntimeException($this->safeHttpFailure($response->status(), $data));
        }

        return ['data' => $data, 'status' => $response->status(), 'request_id' => $requestId];
    }

    /** Signed, live identity and capability check used on connection tests and before every write. */
    public function inspectBinding(DevPilotConnection $connection, array $credentials): array
    {
        $health = $this->request($connection, $credentials, 'GET', '/elchat-devpilot/v1/bridge/health')['data'];
        $identity = $this->request($connection, $credentials, 'GET', '/elchat-devpilot/v1/bridge/identity')['data'];
        $capabilities = $this->request($connection, $credentials, 'GET', '/elchat-devpilot/v1/bridge/capabilities')['data'];
        $saved = is_array($connection->capabilities['bridge'] ?? null) ? $connection->capabilities['bridge'] : [];
        $rootForm = (string) ($connection->capabilities['rest_root_form'] ?? '');

        if (($health['status'] ?? null) !== 'ready'
            || ($health['protocol']['major'] ?? null) !== 1
            || ($health['protocol']['minor'] ?? null) !== 0
            || ($identity['binding_status'] ?? null) !== 'active'
            || ! is_string($identity['installation_id'] ?? null)
            || ! self::isUuid(strtolower($identity['installation_id']))
            || ! is_numeric($identity['blog_id'] ?? null)
            || (string) $identity['blog_id'] !== (string) ($credentials['bridge_blog_id'] ?? '')
            || ! is_string($saved['installation_id'] ?? null)
            || ! hash_equals((string) $saved['installation_id'], (string) $identity['installation_id'])
            || (string) ($health['installation_id'] ?? '') !== (string) $identity['installation_id']) {
            throw new RuntimeException('L’identité ou l’état de jumelage du site WordPress a changé; le canal est fermé.');
        }
        $canonicalSite = DevPilotWordPressUrl::normalize((string) ($connection->site_url ?? ''))['site_url'];
        $remoteCanonical = DevPilotWordPressUrl::normalize((string) ($identity['canonical_site_url'] ?? ''))['site_url'];
        if ($canonicalSite !== $remoteCanonical || ! in_array($rootForm, ['wp-json', 'rest_route'], true)) {
            throw new RuntimeException('L’URL canonique ou la racine REST du site a changé; mettez à jour le profil et jumelez-le à nouveau.');
        }
        if (! is_array($capabilities['protocol'] ?? null)
            || ($capabilities['protocol']['major'] ?? null) !== 1
            || ! is_int($capabilities['protocol']['minor'] ?? null)
            || $capabilities['protocol']['minor'] < 0
            || ! is_array($capabilities['capabilities'] ?? null)) {
            throw new RuntimeException('Le plugin ELChat-DevPilot a retourné une négociation ou un schéma de capacités invalide.');
        }
        $schemas = self::validateCapabilityDescriptors($capabilities['capabilities']);
        if (! is_array($capabilities['content_types'] ?? null) || count($capabilities['content_types']) > 100) {
            throw new RuntimeException('La liste de types de contenu du plugin est invalide.');
        }

        return [
            'health' => $health,
            'identity' => $identity,
            'capabilities' => $capabilities,
            'capability_schemas' => $schemas,
        ];
    }

    public static function validateCapabilityDescriptors(array $descriptors): array
    {
        // Keep negotiation forward-compatible with newer plugins while bounding its size.
        // Only IDs understood by this backend are returned and can authorize a tool.
        if (count($descriptors) > self::MAX_CAPABILITY_DESCRIPTORS) {
            throw new RuntimeException('Le plugin a annoncé plus de '.self::MAX_CAPABILITY_DESCRIPTORS.' capacités; la négociation est refusée.');
        }
        $result = [];
        $seen = [];
        foreach ($descriptors as $descriptor) {
            if (! is_array($descriptor) || ! is_string($descriptor['id'] ?? null)
                || preg_match('/^[a-z][a-z0-9_.-]{0,63}$/D', $descriptor['id']) !== 1
                || ! is_int($descriptor['schema_version'] ?? null) || isset($seen[$descriptor['id']])) {
                throw new RuntimeException('Le plugin a annoncé un descripteur de capacité inconnu ou mal formé.');
            }
            $seen[$descriptor['id']] = true;
            if (! in_array($descriptor['id'], self::CAPABILITY_IDS, true)) continue;
            $result[$descriptor['id']] = $descriptor['schema_version'];
        }
        return $result;
    }

    private function verifyResponse(Response $response, string $requestId, string $keyId, string $blogId, string $secret): array
    {
        $data = $response->json();
        if (! is_array($data)) {
            throw new RuntimeException('La réponse du plugin ELChat-DevPilot n’est pas un JSON valide (HTTP '.$response->status().'; Content-Type: '
                .$this->safeHeaderValue($response, 'Content-Type').'; en-têtes x-elchat reçus: '.$this->receivedElchatHeaders($response).').');
        }
        $bodyHash = strtolower((string) $response->header('X-ELChat-Response-SHA256'));
        $responseRequestId = strtolower((string) $response->header('X-ELChat-Response-Request-Id'));
        $responseKeyId = strtolower((string) $response->header('X-ELChat-Response-Key-Id'));
        $signature = (string) $response->header('X-ELChat-Response-Signature');
        if (preg_match('/^[a-f0-9]{64}$/D', $bodyHash) !== 1
            || ! hash_equals($requestId, $responseRequestId)
            || ! hash_equals($keyId, $responseKeyId)
            || $signature === '') {
            throw new RuntimeException($this->unsignedResponseMessage($response, $data, [
                'SHA256' => preg_match('/^[a-f0-9]{64}$/D', $bodyHash) === 1,
                'Request-Id' => hash_equals($requestId, $responseRequestId),
                'Key-Id' => hash_equals($keyId, $responseKeyId),
                'Signature' => $signature !== '',
            ]));
        }
        if (! hash_equals($bodyHash, hash('sha256', $response->body()))) {
            throw new RuntimeException('Le hash HMAC du corps WordPress ne correspond pas au JSON reçu; une couche REST, un cache ou un proxy a modifié la réponse après signature'
                .$this->signingPathSuffix($response).'.');
        }
        $canonical = implode("\n", [
            'ELCHAT-DEV-PILOT-RESPONSE', self::PROTOCOL, $keyId, $blogId,
            $requestId, (string) $response->status(), $bodyHash,
        ]);
        $expected = self::base64Url(hash_hmac('sha256', $canonical, $secret, true));
        if (! hash_equals($expected, $signature)) throw new RuntimeException('La signature de réponse du plugin ELChat-DevPilot est invalide.');
        return $data;
    }

    /**
     * Diagnostic only: describes an unsigned response without trusting it. Plugin refusals
     * (401/403/409/413/429/503) are unsigned by design, so the real reason must stay visible.
     * Only whitelisted, length-capped values are shown; nothing from the body is acted upon.
     *
     * @param array<string,bool> $headerChecks label => header present and consistent
     */
    private function unsignedResponseMessage(Response $response, array $data, array $headerChecks): string
    {
        $bad = array_keys(array_filter($headerChecks, static fn (bool $ok): bool => ! $ok));
        $parts = ['HTTP '.$response->status(), 'en-têtes absents ou incohérents : '.implode(', ', $bad)];
        $code = $data['code'] ?? null;
        if (is_string($code) && preg_match('/^[a-z0-9_]{1,64}$/D', $code) === 1) $parts[] = 'code '.$code;
        $path = $response->header('X-ELChat-Sign-Path');
        if ($path !== '' && preg_match('/^[a-z_]{1,32}$/D', $path) === 1) $parts[] = 'chemin de signature '.$path;
        foreach (['Server', 'Via', 'CF-Cache-Status', 'X-LiteSpeed-Cache', 'X-Cache', 'Age'] as $name) {
            $value = $this->safeHeaderValue($response, $name);
            if ($value !== '') $parts[] = $name.': '.$value;
        }
        $parts[] = 'en-têtes x-elchat reçus: '.$this->receivedElchatHeaders($response);
        $reason = $response->status() >= 400
            ? 'Réponse non signée : le plugin ou une couche WordPress a refusé la requête avant signature'
            : 'Réponse 2xx sans signature complète : une couche WordPress, un cache ou un proxy a retiré ou court-circuité les en-têtes HMAC';
        return $reason.' ('.implode('; ', $parts).').';
    }

    /** Lists header names only; values may contain credentials and are deliberately omitted. */
    private function receivedElchatHeaders(Response $response): string
    {
        $present = [];
        foreach ($response->headers() as $name => $_) {
            $normalized = strtolower((string) $name);
            if (str_starts_with($normalized, 'x-elchat-')) $present[] = $normalized;
        }
        $present = array_values(array_unique($present));
        sort($present, SORT_STRING);
        return $present === [] ? 'aucun' : implode(', ', $present);
    }

    /** Return a short printable response header value for diagnostics, never arbitrary raw data. */
    private function safeHeaderValue(Response $response, string $name): string
    {
        $value = trim((string) $response->header($name));
        return $value !== '' && preg_match('/^[\x20-\x7e]{1,60}$/D', $value) === 1 ? $value : '';
    }

    private function signingPathSuffix(Response $response): string
    {
        $path = $response->header('X-ELChat-Sign-Path');
        return ($path !== '' && preg_match('/^[a-z_]{1,32}$/D', $path) === 1) ? ' (chemin de signature '.$path.')' : '';
    }

    private function safeHttpFailure(int $status, array $data): string
    {
        $code = is_string($data['code'] ?? null) && preg_match('/^[a-z0-9_]{1,64}$/D', $data['code']) === 1
            ? $data['code'] : '';
        return match ($status) {
            401 => 'Le plugin ELChat-DevPilot a refusé la signature ou la clé est révoquée; vérifiez le jumelage.',
            403 => match ($code) {
                'elchat_publish_forbidden' => 'Le compte WordPress jumelé n’a pas le droit WordPress de publier ce type de contenu (publish_posts).',
                'elchat_capability_not_granted' => 'Une capacité DevPilot requise n’a pas été accordée dans le jumelage WordPress. Révoquez puis re-jumelez avec les autorisations nécessaires.',
                default => 'Le compte WordPress lié ou une capacité du pont ne permet pas cette opération.'.($code !== '' ? ' Code plugin : '.$code.'.' : ''),
            },
            404 => 'La route ou le contenu du pont WordPress est introuvable.',
            409 => 'L’état du contenu ou de l’opération a changé; relisez la cible avant de proposer une nouvelle action.',
            413 => 'Le contenu dépasse la limite définie par le plugin WordPress.',
            429 => 'Le plugin WordPress limite les requêtes. Aucune relance automatique ne sera faite.',
            default => 'Le plugin ELChat-DevPilot a refusé la requête; aucune autre écriture n’a été envoyée.',
        };
    }

    private static function canonicalQuery(array $query): string
    {
        unset($query['rest_route']);
        return http_build_query(self::sortQuery($query), '', '&', PHP_QUERY_RFC3986);
    }

    private static function sortQuery(array $query): array
    {
        foreach ($query as $key => $value) if (is_array($value)) $query[$key] = self::sortQuery($value);
        $keys = array_keys($query);
        if ($query !== [] && $keys !== range(0, count($keys) - 1)) ksort($query, SORT_STRING);
        return $query;
    }

    private static function decodeBase64Url(string $value): string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/D', $value) !== 1) return '';
        $decoded = base64_decode(strtr($value, '-_', '+/').str_repeat('=', (4 - strlen($value) % 4) % 4), true);
        return is_string($decoded) ? $decoded : '';
    }

    private static function base64Url(string $value): string { return rtrim(strtr(base64_encode($value), '+/', '-_'), '='); }
    private static function isUuid(string $value): bool { return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value) === 1; }
}
