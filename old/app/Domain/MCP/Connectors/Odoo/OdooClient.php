<?php

namespace App\Domain\MCP\Connectors\Odoo;

use App\Domain\MCP\Exceptions\AuthExpiredException;
use App\Domain\MCP\Exceptions\ConnectorUnavailableException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Client JSON-RPC générique vers une instance Odoo. Partagé par tous les
 * modules — chacun ne connaît que les modèles/méthodes Odoo qui le
 * concernent, jamais le protocole de transport.
 */
final class OdooClient
{
    public function __construct(private readonly array $credentials)
    {
    }

    public function uid(): int
    {
        $cacheKey = 'mcp:odoo:uid:' . md5(($this->credentials['url'] ?? '') . ($this->credentials['db'] ?? '') . ($this->credentials['username'] ?? ''));

        return Cache::remember($cacheKey, now()->addHours(12), function () {
            $result = $this->rpc('common', 'login', [
                $this->credentials['db'], $this->credentials['username'], $this->credentials['api_key'],
            ]);

            if (!is_int($result) || $result === 0) {
                throw new AuthExpiredException('Authentification Odoo refusée : vérifiez base de données, utilisateur et clé API.');
            }

            return $result;
        });
    }

    public function call(string $model, string $method, array $args = [], array $kwargs = []): mixed
    {
        return $this->rpc('object', 'execute_kw', [
            $this->credentials['db'], $this->uid(), $this->credentials['api_key'], $model, $method, $args, $kwargs,
        ]);
    }

    public function searchRead(string $model, array $domain, array $fields = [], int $limit = 10): array
    {
        return (array) $this->call($model, 'search_read', [$domain], array_filter(['fields' => $fields, 'limit' => $limit]));
    }

    public function create(string $model, array $values): int
    {
        return (int) $this->call($model, 'create', [$values]);
    }

    public function write(string $model, int $id, array $values): bool
    {
        return (bool) $this->call($model, 'write', [[$id], $values]);
    }

    public function read(string $model, int $id, array $fields = []): ?array
    {
        $rows = (array) $this->call($model, 'read', [[$id]], array_filter(['fields' => $fields]));
        return $rows[0] ?? null;
    }

    private function rpc(string $service, string $method, array $args): mixed
    {
        $url = rtrim($this->credentials['url'] ?? '', '/') . '/jsonrpc';

        try {
            $response = Http::timeout(15)->post($url, [
                'jsonrpc' => '2.0', 'method' => 'call', 'id' => (string) Str::uuid(),
                'params' => ['service' => $service, 'method' => $method, 'args' => $args],
            ]);
        } catch (\Throwable $e) {
            throw new ConnectorUnavailableException('Odoo indisponible: ' . $e->getMessage());
        }

        $body = $response->json();

        if (isset($body['error'])) {
            $message = $body['error']['data']['message'] ?? $body['error']['message'] ?? 'Erreur Odoo inconnue.';
            Log::error('MCP Odoo: erreur JSON-RPC', ['error' => $body['error']]);
            throw new ConnectorUnavailableException("Odoo a refusé la requête : {$message}");
        }

        if (!$response->successful()) {
            throw new ConnectorUnavailableException('Odoo indisponible: HTTP ' . $response->status());
        }

        return $body['result'] ?? null;
    }
}
