<?php

namespace App\Services\DevPilot;

use Closure;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\Response;
use RuntimeException;
use Throwable;

/** Fixed-route, read-only WordPress connection tester. No caller-supplied HTTP method or route. */
final class DevPilotWordPressClient
{
    private readonly DevPilotWordPressTransport $transport;

    private readonly ?Closure $resolver;

    public function __construct(?DevPilotWordPressTransport $transport = null, ?callable $resolver = null)
    {
        $this->transport = $transport ?? new LaravelDevPilotWordPressTransport();
        $this->resolver = $resolver !== null ? Closure::fromCallable($resolver) : null;
    }

    /** @return array{connected:bool,protocol:string,capabilities:array<string,mixed>} */
    public function testConnection(array $connection, array $credentials): array
    {
        $normalized = DevPilotWordPressUrl::normalize((string) ($connection['site_url'] ?? ''), allowHttpProbe: true);
        if ($normalized['scheme'] === 'http') {
            $this->probeHttpUpgrade($normalized, (bool) ($connection['private_network_authorized'] ?? false));
        }
        $password = self::normalizeApplicationPassword((string) ($credentials['application_password'] ?? ''));
        $username = trim((string) ($connection['username'] ?? ''));
        if ($username === '' || $password === '') {
            throw new RuntimeException('Un utilisateur WordPress et un mot de passe d’application sont obligatoires.');
        }
        if (isset($connection['host']) && (string) $connection['host'] !== ''
            && strtolower(trim((string) $connection['host'], '[]')) !== $normalized['host']) {
            throw new RuntimeException('L’hôte ne correspond pas à l’URL canonique de la connexion WordPress.');
        }

        $privateNetworkAuthorized = (bool) ($connection['private_network_authorized'] ?? false);
        $pinnedIp = $this->resolvePinnedAddress($normalized['host'], $privateNetworkAuthorized);
        $pathIndexUrl = rtrim($normalized['site_url'], '/').'/wp-json/';
        $this->assertSameOrigin($normalized['site_url'], $pathIndexUrl);

        $index = $this->requestIndex($pathIndexUrl, $normalized['site_url'], $username, $password, $pinnedIp);
        $rootForm = 'wp-json';

        if ($index === null) {
            $queryIndexUrl = rtrim($normalized['site_url'], '/').'/?rest_route=/';
            $this->assertSameOrigin($normalized['site_url'], $queryIndexUrl);
            $index = $this->requestIndex($queryIndexUrl, $normalized['site_url'], $username, $password, $pinnedIp);
            $rootForm = 'rest_route';
        }

        if (! is_array($index)) {
            throw new RuntimeException('L’API REST WordPress est introuvable ou bloquée. Vérifiez les permaliens et les règles de sécurité du site.');
        }

        $namespaces = $this->normalizedNamespaces($index['namespaces'] ?? []);
        $authenticationUrl = $rootForm === 'wp-json'
            ? rtrim($normalized['site_url'], '/').'/wp-json/wp/v2/users/me?context=edit'
            : rtrim($normalized['site_url'], '/').'/?rest_route=/wp/v2/users/me&context=edit';
        $this->assertSameOrigin($normalized['site_url'], $authenticationUrl);
        $authenticated = $this->sendGet($authenticationUrl, $username, $password, $pinnedIp);
        $this->assertNotRedirect($authenticated, $authenticationUrl, $normalized['site_url']);
        $this->assertNotRateLimited($authenticated);

        if ($authenticated->status() === 401) {
            throw new RuntimeException('Identifiants WordPress refusés. Vérifiez l’utilisateur et le mot de passe d’application; l’hébergement peut aussi retirer l’en-tête Authorization.');
        }
        if ($authenticated->status() === 403) {
            throw new RuntimeException('La REST API ou le compte WordPress refuse le test authentifié. Un plugin de sécurité peut bloquer l’accès.');
        }
        if ($authenticated->status() < 200 || $authenticated->status() >= 300) {
            throw new RuntimeException('La REST API WordPress répond, mais le test authentifié a échoué. Vérifiez les identifiants et les règles d’accès du site.');
        }

        return [
            'connected' => true,
            'protocol' => 'wordpress',
            'capabilities' => [
                'rest_api' => true,
                'rest_root_form' => $rootForm,
                'namespaces' => $namespaces,
                'application_password_auth' => true,
            ],
        ];
    }

    /**
     * Perform one authenticated GET to a server-built REST route. The route is
     * constrained to path identifiers and can never supply a host or HTTP verb.
     *
     * @return array{data:array, total:?int, total_pages:?int}
     */
    public function readResource(array $connection, array $credentials, string $rootForm, string $route, array $query = []): array
    {
        if (! in_array($rootForm, ['wp-json', 'rest_route'], true) || ! $this->validRoute($route)) {
            throw new RuntimeException('La ressource REST WordPress demandée est invalide.');
        }
        foreach ($query as $key => $value) {
            if (! in_array($key, ['context', 'page', 'per_page', 'search', 'slug', '_fields'], true)
                || (! is_string($value) && ! is_int($value))
                || (is_string($value) && (strlen($value) > 256 || preg_match('/[\x00-\x1f\x7f]/', $value) === 1))) {
                throw new RuntimeException('Les paramètres de lecture REST WordPress sont invalides.');
            }
        }

        $normalized = DevPilotWordPressUrl::normalize((string) ($connection['site_url'] ?? ''));
        if (isset($connection['host']) && (string) $connection['host'] !== ''
            && strtolower(trim((string) $connection['host'], '[]')) !== $normalized['host']) {
            throw new RuntimeException('L’hôte ne correspond pas à l’URL canonique de la connexion WordPress.');
        }
        $username = trim((string) ($connection['username'] ?? ''));
        $password = self::normalizeApplicationPassword((string) ($credentials['application_password'] ?? ''));
        if ($username === '' || $password === '') {
            throw new RuntimeException('Les identifiants de lecture WordPress ne sont pas disponibles.');
        }

        $pinnedIp = $this->resolvePinnedAddress($normalized['host'], (bool) ($connection['private_network_authorized'] ?? false));
        if ($rootForm === 'wp-json') {
            $url = rtrim($normalized['site_url'], '/').'/wp-json'.$route;
            if ($query !== []) $url .= '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        } else {
            $parameters = ['rest_route' => $route, ...$query];
            $url = rtrim($normalized['site_url'], '/').'/?'.http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
        }
        $this->assertSameOrigin($normalized['site_url'], $url);
        $response = $this->sendGet($url, $username, $password, $pinnedIp);
        $this->assertNotRedirect($response, $url, $normalized['site_url']);
        $this->assertNotRateLimited($response);
        if ($response->status() === 401) {
            throw new RuntimeException('Identifiants WordPress refusés ou en-tête Authorization non transmis par l’hébergeur.');
        }
        if ($response->status() === 403) {
            throw new RuntimeException('La lecture REST WordPress est bloquée ou le compte ne possède pas la permission de lecture requise.');
        }
        if ($response->status() === 404) throw new RuntimeException('Cette ressource n’est pas exposée par l’API REST WordPress.');
        if ($response->status() < 200 || $response->status() >= 300) {
            throw new RuntimeException('La lecture REST WordPress a échoué. Vérifiez les espaces, la ressource et les permissions du compte.');
        }

        $data = $response->json();
        if (! is_array($data)) throw new RuntimeException('La réponse REST WordPress n’est pas un objet ou une liste JSON valide.');
        $total = $this->boundedHeaderInteger($response->header('X-WP-Total'));
        $totalPages = $this->boundedHeaderInteger($response->header('X-WP-TotalPages'));

        return ['data' => $data, 'total' => $total, 'total_pages' => $totalPages];
    }

    /** Server-typed mutation. The model never supplies this method or route directly. */
    public function mutateResource(
        array $connection,
        array $credentials,
        string $rootForm,
        string $method,
        string $route,
        array $body = [],
        array $query = [],
    ): array {
        $method = strtoupper($method);
        if (! in_array($method, ['POST', 'DELETE'], true)
            || ! in_array($rootForm, ['wp-json', 'rest_route'], true)
            || ! $this->validRoute($route)) {
            throw new DevPilotOperationRejectedException('L’opération REST WordPress demandée n’est pas autorisée.');
        }
        foreach ($query as $key => $value) {
            if ($key !== 'force' || ! is_bool($value)) throw new DevPilotOperationRejectedException('Les paramètres de cette opération REST WordPress sont invalides.');
        }
        $encodedBody = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($encodedBody) > 262144) throw new DevPilotOperationRejectedException('Le contenu de l’opération dépasse la limite autorisée.');

        $normalized = DevPilotWordPressUrl::normalize((string) ($connection['site_url'] ?? ''));
        if (isset($connection['host']) && (string) $connection['host'] !== ''
            && strtolower(trim((string) $connection['host'], '[]')) !== $normalized['host']) {
            throw new DevPilotOperationRejectedException('L’hôte ne correspond pas à l’URL canonique de la connexion WordPress.');
        }
        $username = trim((string) ($connection['username'] ?? ''));
        $password = self::normalizeApplicationPassword((string) ($credentials['application_password'] ?? ''));
        if ($username === '' || $password === '') throw new DevPilotOperationRejectedException('Les identifiants WordPress ne sont pas disponibles.');

        if ($rootForm === 'wp-json') {
            $url = rtrim($normalized['site_url'], '/').'/wp-json'.$route;
            if ($query !== []) $url .= '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        } else {
            $parameters = ['rest_route' => $route, ...$query];
            $url = rtrim($normalized['site_url'], '/').'/?'.http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
        }
        $this->assertSameOrigin($normalized['site_url'], $url);
        $pinnedIp = $this->resolvePinnedAddress($normalized['host'], (bool) ($connection['private_network_authorized'] ?? false));
        $response = $this->sendMutation($method, $url, $username, $password, $pinnedIp, $body);
        if ($response->status() >= 300 && $response->status() < 400) {
            throw new DevPilotOperationUncertainException('WordPress a répondu par une redirection après une requête modificatrice; vérifiez l’état avant toute suite.');
        }
        if ($response->status() === 429) throw new RuntimeException('WordPress limite les requêtes (HTTP 429); aucune nouvelle tentative automatique ne sera faite.');
        if ($response->status() >= 500) throw new DevPilotOperationUncertainException('Le serveur WordPress a échoué après la requête; vérifiez l’état avant toute suite.');
        if ($response->status() === 401) throw new RuntimeException('Identifiants WordPress refusés ou en-tête Authorization non transmis par l’hébergeur.');
        if ($response->status() === 403) throw new RuntimeException('WordPress refuse cette opération avec les permissions de ce compte.');
        if ($response->status() < 200 || $response->status() >= 300) throw new RuntimeException('WordPress a refusé l’opération; aucune autre action n’a été lancée.');
        $data = $response->status() === 204 ? null : $response->json();
        if ($data !== null && ! is_array($data)) {
            throw new DevPilotOperationUncertainException('WordPress a répondu après une requête modificatrice avec un résultat illisible; vérifiez l’état avant toute suite.');
        }

        return ['data' => $data, 'status' => $response->status()];
    }

    /**
     * Send a server-constructed ELChat DevPilot plugin route through the same
     * pinned, TLS-verified, bounded transport used by the native REST channel.
     * Routes and verbs are restricted here as a second boundary beyond the adapter.
     */
    public function requestPluginRoute(
        array $connection,
        string $rootForm,
        string $method,
        string $route,
        array $query = [],
        ?array $body = null,
        array $headers = [],
        string $username = '',
        string $password = '',
        ?string $rawBody = null,
    ): Response {
        $method = strtoupper($method);
        if (! in_array($rootForm, ['wp-json', 'rest_route'], true) || ! $this->validPluginRoute($route)
            || ! $this->validPluginMethod($route, $method)
            || ($method === 'GET' && ($body !== null || $rawBody !== null))) {
            throw new DevPilotOperationRejectedException('La route du plugin WordPress demandée n’est pas autorisée.');
        }
        $binaryLimit = $route === '/elchat-devpilot/v1/plugins/install-zip'
            ? DevPilotTaskAttachmentService::MAX_FILE_BYTES : DevPilotTaskAttachmentService::MAX_IMAGE_BYTES;
        if ($rawBody !== null && (! in_array($route, ['/elchat-devpilot/v1/media', '/elchat-devpilot/v1/plugins/install-zip'], true) || $method !== 'POST' || $body !== null
            || strlen($rawBody) > $binaryLimit
            || strtolower((string) ($headers['Content-Type'] ?? '')) !== 'application/octet-stream')) {
            throw new DevPilotOperationRejectedException('Le téléversement binaire WordPress est invalide ou hors limites.');
        }
        foreach ($query as $key => $value) {
            if (! is_string($key) || ! $this->validPluginQuery($route, $key, $value)) {
                throw new DevPilotOperationRejectedException('Les paramètres de lecture du plugin WordPress sont invalides.');
            }
        }
        if ($route === '/elchat-devpilot/v1/plugins/snapshots'
            && isset($query['slug'], $query['plugin_file'])) {
            throw new DevPilotOperationRejectedException('La lecture des sauvegardes accepte un seul filtre : slug ou fichier exact.');
        }
        foreach ($headers as $key => $value) {
            if (! is_string($key) || ! is_string($value) || preg_match('/[\r\n\x00]/', $key.$value) === 1) {
                throw new DevPilotOperationRejectedException('Les en-têtes du plugin WordPress sont invalides.');
            }
        }

        $normalized = DevPilotWordPressUrl::normalize((string) ($connection['site_url'] ?? ''));
        if (isset($connection['host']) && (string) $connection['host'] !== ''
            && strtolower(trim((string) $connection['host'], '[]')) !== $normalized['host']) {
            throw new DevPilotOperationRejectedException('L’hôte ne correspond pas à l’URL canonique de la connexion WordPress.');
        }
        $urlQuery = $this->sortQuery($query);
        if ($rootForm === 'wp-json') {
            $url = rtrim($normalized['site_url'], '/').'/wp-json'.$route;
            if ($urlQuery !== []) $url .= '?'.http_build_query($urlQuery, '', '&', PHP_QUERY_RFC3986);
        } else {
            $url = rtrim($normalized['site_url'], '/').'/?'.http_build_query(
                $this->sortQuery(['rest_route' => $route, ...$urlQuery]), '', '&', PHP_QUERY_RFC3986,
            );
        }
        $this->assertSameOrigin($normalized['site_url'], $url);
        $pinnedIp = $this->resolvePinnedAddress($normalized['host'], (bool) ($connection['private_network_authorized'] ?? false));
        if ($method === 'GET') return $this->sendGet($url, $username, $password, $pinnedIp, $headers);
        return $this->sendMutation($method, $url, $username, $password, $pinnedIp, $body ?? [], $headers, $rawBody);
    }

    /** Public verification returns only booleans; response bodies remain in this method. */
    public function verifyPublicPage(array $connection, string $url, array $mustContain, array $mustNotContain): array
    {
        $mustContain = $this->boundedVerificationConditions($mustContain);
        $mustNotContain = $this->boundedVerificationConditions($mustNotContain);
        $falseContains = array_fill(0, count($mustContain), false);
        $falseExcludes = array_fill(0, count($mustNotContain), false);
        try {
            $canonical = DevPilotWordPressUrl::normalize((string) ($connection['site_url'] ?? ''));
            if (preg_match('/[\x00-\x20\x7f]/', $url) === 1 || str_contains($url, '#')) throw new RuntimeException('Invalid verification URL.');
            $this->assertSameOrigin($canonical['site_url'], $url);
            $separator = str_contains($url, '?') ? '&' : '?';
            $bypassUrl = $url.$separator.'_devpilot_verify='.bin2hex(random_bytes(12));
            $authorized = (bool) ($connection['private_network_authorized'] ?? false);
            $bypass = $this->fetchPublicWithRedirects($bypassUrl, $canonical['site_url'], $canonical['host'], $authorized);
            $normal = $this->fetchPublicWithRedirects($url, $canonical['site_url'], $canonical['host'], $authorized);
            $checked = $bypass['status'] >= 200 && $bypass['status'] < 300
                && $normal['status'] >= 200 && $normal['status'] < 300;
            $contains = $checked ? array_map(static fn (string $text): bool => str_contains($bypass['body'], $text), $mustContain) : $falseContains;
            $excludes = $checked ? array_map(static fn (string $text): bool => ! str_contains($bypass['body'], $text), $mustNotContain) : $falseExcludes;

            return [
                'checked' => $checked,
                'verified' => $checked && ! in_array(false, $contains, true) && ! in_array(false, $excludes, true),
                'must_contain' => array_values($contains),
                'must_not_contain' => array_values($excludes),
                'http_statuses' => ['cache_bypass' => (int) $bypass['status'], 'normal' => (int) $normal['status']],
                'cache_stale_suspected' => $checked && ! hash_equals(hash('sha256', $bypass['body']), hash('sha256', $normal['body'])),
                'redirects' => ['cache_bypass' => $bypass['redirects'], 'normal' => $normal['redirects']],
            ];
        } catch (Throwable) {
            return [
                'checked' => false, 'verified' => false,
                'must_contain' => $falseContains, 'must_not_contain' => $falseExcludes,
                'http_statuses' => ['cache_bypass' => null, 'normal' => null],
                'cache_stale_suspected' => false, 'redirects' => ['cache_bypass' => 0, 'normal' => 0],
            ];
        }
    }

    private function boundedHeaderInteger(mixed $value): ?int
    {
        if (! is_string($value) && ! is_numeric($value)) return null;
        $value = trim((string) $value);
        if ($value === '' || ! ctype_digit($value)) return null;
        return min(1000000000, (int) $value);
    }

    public static function normalizeApplicationPassword(string $password): string
    {
        $normalized = preg_replace('/\s+/u', '', $password);
        return is_string($normalized) ? $normalized : '';
    }

    private function resolvePinnedAddress(string $host, bool $privateNetworkAuthorized): string
    {
        try {
            $address = $this->resolver !== null
                ? ($this->resolver)($host, $privateNetworkAuthorized)
                : DevPilotHostResolver::resolve($host, $privateNetworkAuthorized);
        } catch (Throwable $exception) {
            throw new RuntimeException('Hôte refusé par la politique réseau DevPilot.');
        }

        if (! is_string($address) || filter_var($address, FILTER_VALIDATE_IP) === false) {
            throw new RuntimeException('Hôte refusé par la politique réseau DevPilot.');
        }

        return strtolower($address);
    }

    /** HTTP is used only to detect a canonical HTTPS redirect, never to send credentials. */
    private function probeHttpUpgrade(array $normalized, bool $privateNetworkAuthorized): never
    {
        $pinnedIp = $this->resolvePinnedAddress($normalized['host'], $privateNetworkAuthorized);
        $requestUrl = rtrim($normalized['site_url'], '/').'/wp-json/';
        $response = $this->sendGet($requestUrl, '', '', $pinnedIp);
        $this->assertNotRateLimited($response);
        if ($response->status() >= 300 && $response->status() < 400) {
            $this->assertNotRedirect($response, $requestUrl, $normalized['site_url']);
        }
        if ($response->status() >= 200 && $response->status() < 300) {
            throw new RuntimeException('Le site répond en HTTP sans redirection vers HTTPS. Utilisez une URL HTTPS; aucun identifiant n’a été envoyé.');
        }

        throw new RuntimeException('La sonde HTTP sans identifiants n’a pas obtenu de redirection HTTPS. Vérifiez l’URL; aucun identifiant n’a été envoyé.');
    }

    private function requestIndex(string $url, string $canonicalUrl, string $username, string $password, string $pinnedIp): ?array
    {
        $response = $this->sendGet($url, $username, $password, $pinnedIp);
        $this->assertNotRedirect($response, $url, $canonicalUrl);
        $this->assertNotRateLimited($response);

        if ($response->status() === 401) {
            throw new RuntimeException('Identifiants WordPress refusés. Vérifiez l’utilisateur et le mot de passe d’application; l’hébergement peut aussi retirer l’en-tête Authorization.');
        }
        if ($response->status() === 403) {
            throw new RuntimeException('La REST API WordPress est refusée. Un plugin de sécurité ou une règle d’hébergement peut bloquer l’accès.');
        }
        if (in_array($response->status(), [404, 405], true)) return null;
        if ($response->status() < 200 || $response->status() >= 300) {
            throw new RuntimeException('La REST API WordPress ne répond pas correctement. Vérifiez l’URL et les règles d’accès du site.');
        }

        $data = $response->json();
        if (! is_array($data) || (! is_array($data['namespaces'] ?? null) && ! is_array($data['routes'] ?? null))) {
            return null;
        }

        return $data;
    }

    private function sendGet(string $url, string $username, string $password, string $pinnedIp, array $headers = []): Response
    {
        if (! function_exists('curl_init') || ! defined('CURLOPT_RESOLVE') || ! defined('CURLOPT_NOPROGRESS')) {
            throw new RuntimeException('Le transport WordPress sécurisé exige l’extension cURL pour épingler l’adresse réseau.');
        }

        $parts = parse_url($url);
        if (! is_array($parts) || ! is_string($parts['host'] ?? null)) {
            throw new RuntimeException('La route REST WordPress construite par le serveur est invalide.');
        }
        $host = trim((string) $parts['host'], '[]');
        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $port = (int) ($parts['port'] ?? ($scheme === 'http' ? 80 : 443));
        $curlAddress = str_contains($pinnedIp, ':') ? '['.$pinnedIp.']' : $pinnedIp;
        $resolveHost = str_contains($host, ':') ? '['.$host.']' : $host;
        $resolve = $resolveHost.':'.$port.':'.$curlAddress;
        $maximumBytes = max(1, min(10 * 1024 * 1024, (int) config('devpilot.wordpress_response_limit_bytes', 4194304)));
        $limitExceeded = false;
        $progress = static function (int $downloadTotal, int $downloadedBytes, int $uploadTotal, int $uploadedBytes) use ($maximumBytes, &$limitExceeded): void {
            if ($downloadedBytes > $maximumBytes) {
                $limitExceeded = true;
                throw new RuntimeException('WordPress response exceeded configured maximum size.');
            }
        };
        $onHeaders = static function ($response) use ($maximumBytes, &$limitExceeded): void {
            $contentLength = trim((string) $response->getHeaderLine('Content-Length'));
            if ($contentLength !== '' && ctype_digit($contentLength) && (int) $contentLength > $maximumBytes) {
                $limitExceeded = true;
                throw new RuntimeException('WordPress response exceeded configured maximum size.');
            }
        };
        $options = [
            'allow_redirects' => false,
            'verify' => true,
            'proxy' => '',
            'decode_content' => false,
            'headers' => array_merge(['Accept-Encoding' => 'identity'], $headers),
            'connect_timeout' => max(1, min(5, (int) config('devpilot.wordpress_connect_timeout_seconds', 3))),
            'timeout' => max(1, min(15, (int) config('devpilot.wordpress_timeout_seconds', 10))),
            'curl' => [
                CURLOPT_RESOLVE => [$resolve],
                CURLOPT_NOPROGRESS => false,
            ],
            'progress' => $progress,
            'on_headers' => $onHeaders,
        ];

        try {
            $response = $this->transport->get($url, $username, $password, $options);
        } catch (Throwable $exception) {
            if ($limitExceeded) {
                throw new RuntimeException('La réponse WordPress dépasse la taille maximale autorisée.');
            }
            $detail = $this->safeTransportFailureDetail($exception);
            throw new RuntimeException('La connexion HTTPS WordPress a échoué ('.$detail.'). Vérifiez le certificat TLS, l’hôte et le réseau.', 0, $exception);
        }

        if ($limitExceeded || strlen($response->body()) > $maximumBytes) {
            throw new RuntimeException('La réponse WordPress dépasse la taille maximale autorisée.');
        }

        return $response;
    }

    private function safeTransportFailureDetail(Throwable $exception): string
    {
        for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
            if (! method_exists($cause, 'getHandlerContext')) continue;
            try {
                $context = $cause->getHandlerContext();
            } catch (Throwable) {
                continue;
            }
            if (! is_array($context) || ! is_numeric($context['errno'] ?? null)) continue;

            return match ((int) $context['errno']) {
                5, 6 => 'résolution DNS impossible',
                7 => 'connexion TCP impossible',
                28 => 'délai de connexion ou de réponse dépassé',
                35, 51, 58, 59, 60, 77 => 'négociation ou validation du certificat TLS échouée',
                52, 55, 56 => 'échange HTTP interrompu',
                default => 'échec réseau ou TLS (cURL '.(int) $context['errno'].')',
            };
        }

        return 'échec réseau ou TLS non classifié';
    }

    private function sendMutation(string $method, string $url, string $username, string $password, string $pinnedIp, array $body, array $headers = [], ?string $rawBody = null): Response
    {
        if (! function_exists('curl_init') || ! defined('CURLOPT_RESOLVE') || ! defined('CURLOPT_NOPROGRESS')) {
            throw new RuntimeException('Le transport WordPress sécurisé exige l’extension cURL pour épingler l’adresse réseau.');
        }
        $parts = parse_url($url);
        if (! is_array($parts) || ! is_string($parts['host'] ?? null)) throw new RuntimeException('La route REST WordPress construite par le serveur est invalide.');
        $host = trim((string) $parts['host'], '[]');
        $port = (int) ($parts['port'] ?? 443);
        $curlAddress = str_contains($pinnedIp, ':') ? '['.$pinnedIp.']' : $pinnedIp;
        $resolveHost = str_contains($host, ':') ? '['.$host.']' : $host;
        $maximumBytes = max(1, min(10 * 1024 * 1024, (int) config('devpilot.wordpress_response_limit_bytes', 4194304)));
        $limitExceeded = false;
        $progress = static function (int $downloadTotal, int $downloadedBytes, int $uploadTotal, int $uploadedBytes) use ($maximumBytes, &$limitExceeded): void {
            if ($downloadedBytes > $maximumBytes) {
                $limitExceeded = true;
                throw new RuntimeException('WordPress response exceeded configured maximum size.');
            }
        };
        $onHeaders = static function ($response) use ($maximumBytes, &$limitExceeded): void {
            $contentLength = trim((string) $response->getHeaderLine('Content-Length'));
            if ($contentLength !== '' && ctype_digit($contentLength) && (int) $contentLength > $maximumBytes) {
                $limitExceeded = true;
                throw new RuntimeException('WordPress response exceeded configured maximum size.');
            }
        };
        $options = [
            'allow_redirects' => false, 'verify' => true, 'proxy' => '', 'decode_content' => false,
            'headers' => array_merge(['Accept-Encoding' => 'identity'], $headers),
            'connect_timeout' => max(1, min(5, (int) config('devpilot.wordpress_connect_timeout_seconds', 3))),
            'timeout' => max(1, min(15, (int) config('devpilot.wordpress_timeout_seconds', 10))),
            'curl' => [CURLOPT_RESOLVE => [$resolveHost.':'.$port.':'.$curlAddress], CURLOPT_NOPROGRESS => false],
            'progress' => $progress, 'on_headers' => $onHeaders,
        ];
        if ($rawBody !== null) $options['body'] = $rawBody;
        try {
            $response = $this->transport->request($method, $url, $username, $password, $options, $rawBody === null ? $body : null);
        } catch (Throwable $exception) {
            $message = $limitExceeded
                ? 'La réponse WordPress dépasse la taille maximale après une requête modificatrice.'
                : 'La requête modificatrice WordPress a échoué au niveau '.$this->safeTransportFailureDetail($exception).'; son résultat reste incertain.';
            throw new DevPilotOperationUncertainException($message, $exception);
        }
        if ($limitExceeded || strlen($response->body()) > $maximumBytes) {
            throw new DevPilotOperationUncertainException('La réponse WordPress dépasse la taille maximale après une requête modificatrice.');
        }

        return $response;
    }

    private function validRoute(string $route): bool
    {
        if (preg_match('#^/(?:[A-Za-z0-9_.-]+/){2}[A-Za-z0-9_.-]+(?:/[A-Za-z0-9_.-]+)*(?:/[1-9][0-9]*)?$#D', $route) !== 1) return false;
        foreach (explode('/', trim($route, '/')) as $segment) if ($segment === '.' || $segment === '..') return false;
        return true;
    }

    private function validPluginRoute(string $route): bool
    {
        return preg_match('#^/elchat-devpilot/v1/(?:pair|bridge/(?:health|identity|capabilities)|content/types|content/[a-z0-9_-]{1,32}(?:/(?:duplicate-check|terms|templates|[1-9][0-9]*(?:/restore|/revisions(?:/[1-9][0-9]*/restore)?)?))?|operations/[0-9a-f-]{36}(?:/readback)?|media(?:/[1-9][0-9]*)?|plugins(?:/(?:updates|directory(?:/[a-z0-9-]{1,100})?|integrity|settings|snapshots|operations|install-zip))?|site-health)$#D', $route) === 1;
    }

    private function validPluginMethod(string $route, string $method): bool
    {
        if ($route === '/elchat-devpilot/v1/pair') return $method === 'POST';
        if ($route === '/elchat-devpilot/v1/media') return in_array($method, ['GET', 'POST'], true);
        if (in_array($route, ['/elchat-devpilot/v1/plugins', '/elchat-devpilot/v1/plugins/updates', '/elchat-devpilot/v1/plugins/directory', '/elchat-devpilot/v1/plugins/integrity', '/elchat-devpilot/v1/plugins/settings', '/elchat-devpilot/v1/plugins/snapshots', '/elchat-devpilot/v1/site-health'], true)
            || preg_match('#^/elchat-devpilot/v1/plugins/directory/[a-z0-9-]{1,100}$#D', $route) === 1) return $method === 'GET';
        if ($route === '/elchat-devpilot/v1/plugins/operations' || $route === '/elchat-devpilot/v1/plugins/install-zip') return $method === 'POST';
        if (preg_match('#^/elchat-devpilot/v1/media/[1-9][0-9]*$#D', $route) === 1) return in_array($method, ['GET', 'POST'], true);
        if (preg_match('#^/elchat-devpilot/v1/content/[a-z0-9_-]{1,32}/[1-9][0-9]*/revisions$#D', $route) === 1) return $method === 'GET';
        if (preg_match('#^/elchat-devpilot/v1/content/[a-z0-9_-]{1,32}/[1-9][0-9]*/revisions/[1-9][0-9]*/restore$#D', $route) === 1) return $method === 'POST';
        if (preg_match('#^/elchat-devpilot/v1/content/[a-z0-9_-]{1,32}/templates$#D', $route) === 1) return $method === 'GET';
        if (preg_match('#^/elchat-devpilot/v1/content/[a-z0-9_-]{1,32}/[1-9][0-9]*/restore$#D', $route) === 1) return $method === 'POST';
        if (preg_match('#^/elchat-devpilot/v1/content/[a-z0-9_-]{1,32}/terms$#D', $route) === 1) return $method === 'GET';
        if ($route !== '/elchat-devpilot/v1/content/types'
            && preg_match('#/content/[a-z0-9_-]{1,32}$#D', $route) === 1) {
            return in_array($method, ['GET', 'POST'], true);
        }
        if (str_contains($route, '/bridge/') || $route === '/elchat-devpilot/v1/content/types'
            || str_ends_with($route, '/duplicate-check') || str_contains($route, '/operations/')) return $method === 'GET';
        if (preg_match('#/content/[a-z0-9_-]{1,32}/[1-9][0-9]*$#D', $route) === 1) return in_array($method, ['GET', 'POST', 'DELETE'], true);
        return false;
    }

    private function validPluginQuery(string $route, string $key, mixed $value): bool
    {
        $allowed = [];
        if ($route === '/elchat-devpilot/v1/media') $allowed = ['name', 'alt', 'search', 'page', 'per_page'];
        elseif ($route === '/elchat-devpilot/v1/plugins') $allowed = ['page', 'per_page'];
        elseif ($route === '/elchat-devpilot/v1/plugins/directory') $allowed = ['search'];
        elseif (preg_match('#^/elchat-devpilot/v1/plugins/directory/[a-z0-9-]{1,100}$#D', $route) === 1) $allowed = ['version'];
        elseif ($route === '/elchat-devpilot/v1/plugins/install-zip') $allowed = ['slug', 'sha256'];
        elseif (in_array($route, ['/elchat-devpilot/v1/plugins/integrity', '/elchat-devpilot/v1/plugins/settings'], true)) $allowed = ['slug'];
        elseif ($route === '/elchat-devpilot/v1/plugins/snapshots') $allowed = ['slug', 'plugin_file'];
        elseif (str_ends_with($route, '/duplicate-check')) $allowed = ['title', 'slug'];
        elseif (str_ends_with($route, '/terms')) $allowed = ['taxonomy', 'search', 'page', 'per_page'];
        elseif (str_ends_with($route, '/revisions')) $allowed = ['page', 'per_page'];
        elseif (preg_match('#/content/[a-z0-9_-]{1,32}$#D', $route) === 1) $allowed = ['search', 'page', 'per_page', 'status'];
        if (! in_array($key, $allowed, true) || (! is_string($value) && ! is_int($value))) return false;
        if (is_string($value) && (strlen($value) > 512 || preg_match('/[\x00-\x1f\x7f]/', $value) === 1)) return false;
        if (in_array($key, ['page', 'per_page'], true)) return is_int($value) && $value > 0 && $value <= 1000;
        if ($key === 'status') return is_string($value) && in_array($value, ['publish', 'draft', 'pending', 'private', 'future'], true);
        if ($key === 'slug') return is_string($value) && preg_match('/^[a-z0-9][a-z0-9._-]{0,99}$/D', $value) === 1;
        if ($key === 'plugin_file') {
            if (! is_string($value) || $value === '' || strlen($value) > 255 || ! mb_check_encoding($value, 'UTF-8')
                || preg_match('/[\x00-\x1f\x7f\\\\:]/u', $value) === 1 || str_starts_with($value, '/') || trim($value) !== $value) return false;
            foreach (explode('/', $value) as $segment) if ($segment === '' || $segment === '.' || $segment === '..') return false;
            return true;
        }
        return true;
    }

    private function sortQuery(array $query): array
    {
        foreach ($query as $key => $value) if (is_array($value)) $query[$key] = $this->sortQuery($value);
        $keys = array_keys($query);
        if ($query !== [] && $keys !== range(0, count($keys) - 1)) ksort($query, SORT_STRING);
        return $query;
    }

    private function fetchPublicWithRedirects(string $url, string $canonicalUrl, string $canonicalHost, bool $privateNetworkAuthorized): array
    {
        $redirects = 0;
        $bypassQuery = parse_url($url, PHP_URL_QUERY);
        parse_str(is_string($bypassQuery) ? $bypassQuery : '', $bypassParameters);
        $bypassToken = is_string($bypassParameters['_devpilot_verify'] ?? null) ? $bypassParameters['_devpilot_verify'] : null;
        while (true) {
            $this->assertSameOrigin($canonicalUrl, $url);
            $pinnedIp = $this->resolvePinnedAddress($canonicalHost, $privateNetworkAuthorized);
            $response = $this->sendGet($url, '', '', $pinnedIp, [
                'Cache-Control' => 'no-cache, no-store', 'Pragma' => 'no-cache',
            ]);
            if ($response->status() < 300 || $response->status() >= 400) {
                return ['status' => $response->status(), 'body' => $response->body(), 'redirects' => $redirects];
            }
            if ($redirects >= 3) throw new RuntimeException('Too many public verification redirects.');
            $location = $response->header('Location');
            if (is_array($location)) $location = $location[0] ?? '';
            if (! is_string($location) || $location === '' || preg_match('/[\x00-\x20\x7f]/', $location) === 1) {
                throw new RuntimeException('Invalid public verification redirect.');
            }
            $url = (string) UriResolver::resolve(new Uri($url), new Uri($location));
            $this->assertSameOrigin($canonicalUrl, $url);
            if ($bypassToken !== null) {
                $parts = parse_url($url);
                $query = is_array($parts) ? (string) ($parts['query'] ?? '') : '';
                parse_str($query, $parameters);
                if (($parameters['_devpilot_verify'] ?? null) !== $bypassToken) {
                    $url = $url.(str_contains($url, '?') ? '&' : '?').'_devpilot_verify='.rawurlencode($bypassToken);
                }
            }
            $redirects++;
        }
    }

    /** @return list<string> */
    private function boundedVerificationConditions(array $conditions): array
    {
        if (count($conditions) > 8) throw new RuntimeException('Public verification accepts at most eight conditions per list.');
        $safe = [];
        foreach ($conditions as $condition) {
            if (! is_string($condition) || trim($condition) === '' || strlen($condition) > 256
                || preg_match('/[\x00-\x1f\x7f]/', $condition) === 1) {
                throw new RuntimeException('A public verification condition is invalid.');
            }
            $safe[] = trim($condition);
        }
        return $safe;
    }

    private function assertNotRedirect(Response $response, string $requestUrl, string $canonicalUrl): void
    {
        if ($response->status() < 300 || $response->status() >= 400) return;
        $location = $response->header('Location');
        if (is_array($location)) $location = $location[0] ?? '';
        $suggestion = is_string($location) ? $this->canonicalRedirectSuggestion($location, $requestUrl, $canonicalUrl) : null;

        if ($suggestion !== null) {
            throw new RuntimeException('Le site redirige la requête. L’URL canonique est '.$suggestion.'; mettez-la à jour puis retestez.');
        }

        throw new RuntimeException('Le site redirige la requête. Les redirections sont désactivées; vérifiez et mettez à jour l’URL HTTPS canonique.');
    }

    private function canonicalRedirectSuggestion(string $location, string $requestUrl, string $canonicalUrl): ?string
    {
        $location = trim($location);
        if ($location === '' || preg_match('/[\x00-\x20\x7f]/', $location) === 1) return null;
        $locationParts = parse_url($location);
        $requestParts = parse_url($requestUrl);
        $baseParts = parse_url($canonicalUrl);
        if (! is_array($locationParts) || ! is_array($requestParts) || ! is_array($baseParts)) return null;

        if (isset($locationParts['scheme']) || isset($locationParts['host'])) {
            if (strtolower((string) ($locationParts['scheme'] ?? '')) !== 'https'
                || ! is_string($locationParts['host'] ?? null)
                || isset($locationParts['user']) || isset($locationParts['pass'])) return null;
            $host = (string) $locationParts['host'];
            $port = isset($locationParts['port']) ? ':'.(int) $locationParts['port'] : '';
        } else {
            $host = (string) ($baseParts['host'] ?? '');
            $port = strtolower((string) ($baseParts['scheme'] ?? '')) === 'https'
                && isset($baseParts['port']) ? ':'.(int) $baseParts['port'] : '';
        }

        $path = (string) ($locationParts['path'] ?? '/');
        $path = preg_replace('#/wp-json/?$#', '/', $path) ?? $path;
        $query = (string) ($locationParts['query'] ?? '');
        if ($query !== '' && ! str_starts_with($query, 'rest_route=')) return null;

        try {
            return DevPilotWordPressUrl::normalize('https://'.$host.$port.($path !== '' ? $path : '/'))['site_url'];
        } catch (RuntimeException) {
            return null;
        }
    }

    private function assertNotRateLimited(Response $response): void
    {
        if ($response->status() === 429) {
            throw new RuntimeException('Le site WordPress limite les requêtes (HTTP 429). Réessayez plus tard; aucune nouvelle tentative automatique ne sera faite.');
        }
    }

    private function assertSameOrigin(string $canonicalUrl, string $candidateUrl): void
    {
        if (! DevPilotWordPressUrl::sameOrigin($canonicalUrl, $candidateUrl)) {
            throw new RuntimeException('La racine REST WordPress ne correspond pas à l’origine canonique du site.');
        }
    }

    /** @return list<string> */
    private function normalizedNamespaces(mixed $namespaces): array
    {
        if (! is_array($namespaces)) return [];
        $safe = [];
        foreach (array_slice($namespaces, 0, 64) as $namespace) {
            if (! is_string($namespace)) continue;
            $namespace = strtolower(trim($namespace));
            if (preg_match('#^[a-z0-9._-]{1,32}(?:/[a-z0-9._-]{1,32})?$#D', $namespace) === 1) {
                $safe[$namespace] = true;
            }
        }

        return array_keys($safe);
    }
}
