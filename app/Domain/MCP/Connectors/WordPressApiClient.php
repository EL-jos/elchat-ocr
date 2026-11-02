<?php

namespace App\Domain\MCP\Connectors;

use App\Services\DevPilot\DevPilotHostResolver;
use App\Services\DevPilot\DevPilotWordPressUrl;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/** Authenticated, origin-bound WordPress REST transport with DNS pinning and bounded responses. */
final class WordPressApiClient
{
    private readonly ?Closure $resolver;

    public function __construct(?callable $resolver = null)
    {
        $this->resolver = $resolver !== null ? Closure::fromCallable($resolver) : null;
    }

    /** @return array{site_url:string,username:string,application_password:string} */
    public function normalizeCredentials(array $credentials): array
    {
        $site = DevPilotWordPressUrl::normalize((string) ($credentials['site_url'] ?? ''));
        $username = trim((string) ($credentials['username'] ?? ''));
        $password = preg_replace('/\s+/u', '', (string) ($credentials['application_password'] ?? ''));
        if ($username === '' || ! is_string($password) || $password === '') {
            throw new RuntimeException('L’URL, le nom d’utilisateur et le mot de passe d’application WordPress sont obligatoires.');
        }

        return ['site_url' => $site['site_url'], 'username' => $username, 'application_password' => $password];
    }

    /** @param array<string, mixed> $query @param array<string, mixed> $body */
    public function request(
        array $credentials,
        string $method,
        string $route,
        array $query = [],
        array $body = [],
        ?string $rawBody = null,
        ?string $contentType = null,
        array $headers = [],
    ): Response {
        $credentials = $this->normalizeCredentials($credentials);
        $method = strtoupper($method);
        if (! in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            throw new RuntimeException('Méthode REST WordPress non prise en charge.');
        }
        $route = trim($route, '/');
        $decodedRoute = rawurldecode($route);
        if ($route !== '' && (preg_match('/[\x00-\x20\\\\?#]/', $decodedRoute) === 1 || preg_match('#^[A-Za-z0-9._~/-]+$#D', $decodedRoute) !== 1)) {
            throw new RuntimeException('La route REST WordPress demandée n’est pas valide.');
        }
        foreach (explode('/', $decodedRoute) as $segment) {
            if (in_array($segment, ['.', '..'], true)) {
                throw new RuntimeException('La route REST WordPress ne peut pas traverser un répertoire.');
            }
        }

        $attempt = 0;
        do {
            try {
                $response = $this->send($credentials, $method, $route, $query, $body, $rawBody, $contentType, $headers, false);
                if ($this->isRouteMissing($response)) {
                    $response = $this->send($credentials, $method, $route, $query, $body, $rawBody, $contentType, $headers, true);
                }
            } catch (ConnectionException $exception) {
                if ($method === 'GET' && $attempt < 2) {
                    usleep(150000 * (2 ** $attempt));
                    $attempt++;

                    continue;
                }
                throw $exception;
            }

            if ($method === 'GET' && in_array($response->status(), [429, 500, 502, 503, 504], true) && $attempt < 2) {
                usleep(150000 * (2 ** $attempt));
                $attempt++;

                continue;
            }

            return $response;
        } while (true);
    }

    /** @return array<string, mixed> */
    public function apiIndex(array $credentials): array
    {
        $response = $this->request($credentials, 'GET', '');
        if (! $response->successful() || ! is_array($response->json())) {
            throw new RuntimeException('L’index de l’API REST WordPress est indisponible.');
        }

        return $response->json();
    }

    private function send(
        array $credentials,
        string $method,
        string $route,
        array $query,
        array $body,
        ?string $rawBody,
        ?string $contentType,
        array $headers,
        bool $queryRoute,
    ): Response {
        if (! function_exists('curl_init') || ! defined('CURLOPT_RESOLVE') || ! defined('CURLOPT_NOPROGRESS')) {
            throw new RuntimeException('Le transport WordPress sécurisé exige l’extension cURL pour épingler l’adresse réseau.');
        }

        $site = DevPilotWordPressUrl::normalize($credentials['site_url']);
        try {
            $address = $this->resolver !== null
                ? ($this->resolver)($site['host'])
                : DevPilotHostResolver::resolve($site['host'], false);
        } catch (Throwable) {
            throw new RuntimeException('L’hôte WordPress est refusé par la politique réseau.');
        }
        if (! is_string($address) || filter_var($address, FILTER_VALIDATE_IP) === false) {
            throw new RuntimeException('L’hôte WordPress est refusé par la politique réseau.');
        }

        $host = str_contains($site['host'], ':') ? '['.$site['host'].']' : $site['host'];
        $ip = str_contains($address, ':') ? '['.$address.']' : $address;
        $resolve = $host.':'.$site['port'].':'.$ip;
        $maximumBytes = max(65536, min(4194304, (int) config('mcp.wordpress.max_response_bytes', 2097152)));
        $limitExceeded = false;
        $progress = static function (int $downloadTotal, int $downloadedBytes) use ($maximumBytes, &$limitExceeded): void {
            if ($downloadedBytes > $maximumBytes) {
                $limitExceeded = true;
                throw new RuntimeException('WordPress response exceeded configured maximum size.');
            }
        };
        $onHeaders = static function ($response) use ($maximumBytes, &$limitExceeded): void {
            $length = trim((string) $response->getHeaderLine('Content-Length'));
            if ($length !== '' && ctype_digit($length) && (int) $length > $maximumBytes) {
                $limitExceeded = true;
                throw new RuntimeException('WordPress response exceeded configured maximum size.');
            }
        };

        $url = $queryRoute
            ? $site['site_url']
            : rtrim($site['site_url'], '/').'/wp-json/'.($route === '' ? '' : $route);
        $options = [
            'allow_redirects' => false,
            'verify' => true,
            'proxy' => '',
            'decode_content' => false,
            'connect_timeout' => 5,
            'timeout' => max(5, min(20, (int) config('mcp.wordpress.timeout_seconds', 12))),
            'curl' => [CURLOPT_RESOLVE => [$resolve], CURLOPT_NOPROGRESS => false],
            'progress' => $progress,
            'on_headers' => $onHeaders,
        ];
        $requestHeaders = ['Accept-Encoding' => 'identity', ...$headers];
        if ($contentType !== null) {
            $requestHeaders['Content-Type'] = $contentType;
        }
        if ($queryRoute) {
            $query = ['rest_route' => '/'.($route === '' ? '' : $route), ...$query];
        }

        $request = Http::withOptions($options)
            ->withBasicAuth($credentials['username'], $credentials['application_password'])
            ->acceptJson()
            ->withHeaders($requestHeaders);
        $options = ['query' => $query];
        if ($rawBody !== null) {
            $request = $request->withBody($rawBody, $contentType ?? 'application/octet-stream');
        } elseif ($method !== 'GET' && $method !== 'DELETE') {
            $options['json'] = $body;
        } elseif ($method === 'DELETE' && $body !== []) {
            $options['json'] = $body;
        }

        try {
            $response = $request->send($method, $url, $options);
        } catch (Throwable $exception) {
            if ($limitExceeded) {
                throw new RuntimeException('La réponse WordPress dépasse la taille maximale autorisée.');
            }
            throw $exception;
        }
        if ($limitExceeded || strlen($response->body()) > $maximumBytes) {
            throw new RuntimeException('La réponse WordPress dépasse la taille maximale autorisée.');
        }

        return $response;
    }

    private function isRouteMissing(Response $response): bool
    {
        return in_array($response->status(), [404, 405], true)
            && in_array($response->json('code'), ['rest_no_route', 'rest_no_route_found'], true);
    }
}
