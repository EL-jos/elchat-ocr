<?php

namespace App\Domain\Microsoft365;

use App\Domain\Microsoft365\Exceptions\MicrosoftGraphException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Petit client Graph commun aux outils Microsoft 365.
 *
 * Il centralise l'URL v1.0, le bearer token, les retries réseau/429/5xx et le
 * mapping d'erreurs. Les réponses ne sont jamais écrites dans les logs.
 */
final class MicrosoftGraphClient
{
    private const API_BASE = 'https://graph.microsoft.com/v1.0/';

    public function __construct(private readonly string $accessToken)
    {
    }

    public static function forToken(string $accessToken): self
    {
        return new self($accessToken);
    }

    /** @return array<string, mixed> */
    public function get(string $path, array $query = [], array $headers = []): array
    {
        return $this->json('GET', $path, $query, [], $headers);
    }

    /** @return array<string, mixed> */
    public function post(string $path, array $body = [], array $query = [], array $headers = []): array
    {
        return $this->json('POST', $path, $query, $body, $headers);
    }

    /** @return array<string, mixed> */
    public function postContent(string $path, string $content, string $contentType = 'text/html'): array
    {
        $response = $this->send('POST', $path, [], $content, ['Content-Type' => $contentType]);
        return $this->decode($response);
    }

    /** @return array<string, mixed> */
    public function put(string $path, array $body = [], array $query = [], array $headers = []): array
    {
        return $this->json('PUT', $path, $query, $body, $headers);
    }

    /** @return array<string, mixed> */
    public function patch(string $path, array $body = [], array $query = [], array $headers = []): array
    {
        return $this->json('PATCH', $path, $query, $body, $headers);
    }

    /** @return array<string, mixed> */
    public function delete(string $path, array $query = [], array $headers = []): array
    {
        return $this->json('DELETE', $path, $query, [], $headers);
    }

    public function putContent(string $path, string $content, string $contentType = 'application/octet-stream'): array
    {
        $response = $this->send('PUT', $path, [], $content, ['Content-Type' => $contentType]);
        return $this->decode($response);
    }

    public function download(string $path, array $query = []): string
    {
        $response = $this->send('GET', $path, $query, null, ['Accept' => '*/*'], followRedirects: true);
        return $response->body();
    }

    /**
     * Uploads a file through a Graph upload session. The upload URL returned
     * by Graph is pre-authenticated, so chunks deliberately do not carry the
     * ELChat bearer token.
     *
     * @return array<string, mixed>
     */
    public function uploadLarge(string $uploadUrl, string $content, string $contentType = 'application/octet-stream'): array
    {
        $total = strlen($content);
        $chunkSize = 10 * 1024 * 1024; // multiple of Graph's 320 KiB requirement
        $offset = 0;

        while ($offset < $total) {
            $end = min($total - 1, $offset + $chunkSize - 1);
            $chunk = substr($content, $offset, $end - $offset + 1);
            $response = $this->sendUploadChunk($uploadUrl, $chunk, $offset, $end, $total, $contentType);

            if (in_array($response->status(), [200, 201], true)) {
                return $this->decode($response);
            }

            if ($response->status() !== 202) {
                $json = $response->json();
                $error = is_array($json['error'] ?? null) ? $json['error'] : [];
                throw new MicrosoftGraphException(
                    (string) ($error['message'] ?? 'L’upload Microsoft 365 a échoué.'),
                    $response->status(),
                    isset($error['code']) ? (string) $error['code'] : null,
                );
            }

            $nextRanges = $response->json('@microsoft.graph.nextExpectedRanges');
            $nextOffset = is_array($nextRanges) && isset($nextRanges[0])
                && preg_match('/^(\d+)-?/', (string) $nextRanges[0], $matches)
                ? (int) $matches[1]
                : $end + 1;
            $offset = max($end + 1, $nextOffset);
        }

        return [];
    }

    /** @return array<string, mixed> */
    public function json(string $method, string $path, array $query = [], array $body = [], array $headers = []): array
    {
        $payload = $method === 'GET' || $method === 'DELETE' ? null : $body;
        return $this->decode($this->send($method, $path, $query, $payload, $headers));
    }

    /** @return array<int, array<string, mixed>> */
    public function collectPages(string $path, array $query = [], array $headers = [], int $maxPages = 50): array
    {
        $items = [];
        $next = $path;
        $first = true;

        for ($page = 0; $page < $maxPages && $next; $page++) {
            $response = $first ? $this->get($next, $query, $headers) : $this->get($next, [], $headers);
            $first = false;
            $items = array_merge($items, $response['value'] ?? []);
            $next = $response['@odata.nextLink'] ?? null;
        }

        return $items;
    }

    private function send(string $method, string $path, array $query, mixed $body, array $headers, bool $followRedirects = false): Response
    {
        $url = str_starts_with($path, 'http://') || str_starts_with($path, 'https://')
            ? $path
            : self::API_BASE . ltrim($path, '/');

        $request = Http::withToken($this->accessToken)
            ->acceptJson()
            ->timeout(30)
            ->connectTimeout(5)
            ->withHeaders($headers)
            ->withOptions(['allow_redirects' => $followRedirects]);

        if (is_string($body)) {
            $request = $request->withBody($body, (string) ($headers['Content-Type'] ?? 'application/octet-stream'));
        }

        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $response = is_string($body)
                    ? $request->send(strtoupper($method), $url)
                    : match (strtoupper($method)) {
                        'GET' => $request->get($url, $query),
                        'POST' => $request->post($url, $body ?? []),
                        'PUT' => $request->put($url, $body ?? []),
                        'PATCH' => $request->patch($url, $body ?? []),
                        'DELETE' => $request->delete($url, $query),
                        default => throw new \InvalidArgumentException("Méthode Graph non supportée : {$method}"),
                    };

                if (in_array($response->status(), [429, 500, 502, 503, 504], true) && $attempt < 2) {
                    $retryAfter = (int) $response->header('Retry-After', 0);
                    usleep(max(100, min(5000, $retryAfter * 1000 ?: 500 * ($attempt + 1))) * 1000);
                    continue;
                }

                $response->throw();
                return $response;
            } catch (ConnectionException $exception) {
                if ($attempt < 2) {
                    usleep(500000 * ($attempt + 1));
                    continue;
                }
                throw new MicrosoftGraphException('Microsoft Graph est momentanément indisponible.', 503);
            } catch (RequestException $exception) {
                $response = $exception->response;
                $json = $response?->json();
                $error = is_array($json['error'] ?? null) ? $json['error'] : [];
                $message = (string) ($error['message'] ?? 'Microsoft Graph a refusé la requête.');

                throw new MicrosoftGraphException(
                    $message,
                    (int) ($response?->status() ?? 0),
                    isset($error['code']) ? (string) $error['code'] : null,
                );
            }
        }

        throw new MicrosoftGraphException('Microsoft Graph est momentanément indisponible.', 503);
    }

    private function sendUploadChunk(string $uploadUrl, string $chunk, int $start, int $end, int $total, string $contentType): Response
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $response = Http::timeout(120)
                    ->connectTimeout(10)
                    ->withHeaders([
                        'Content-Length' => (string) strlen($chunk),
                        'Content-Range' => "bytes {$start}-{$end}/{$total}",
                    ])
                    ->withBody($chunk, $contentType)
                    ->put($uploadUrl);

                if (in_array($response->status(), [429, 500, 502, 503, 504], true) && $attempt < 2) {
                    $retryAfter = (int) $response->header('Retry-After', 0);
                    usleep(max(100, min(5000, $retryAfter * 1000 ?: 500 * ($attempt + 1))) * 1000);
                    continue;
                }

                return $response;
            } catch (ConnectionException $exception) {
                if ($attempt < 2) {
                    usleep(500000 * ($attempt + 1));
                    continue;
                }

                throw new MicrosoftGraphException('Microsoft Graph est momentanément indisponible.', 503);
            }
        }

        throw new MicrosoftGraphException('Microsoft Graph est momentanément indisponible.', 503);
    }

    /** @return array<string, mixed> */
    private function decode(Response $response): array
    {
        if ($response->status() === 204 || trim($response->body()) === '') {
            return [];
        }

        $json = $response->json();
        return is_array($json) ? $json : [];
    }
}
