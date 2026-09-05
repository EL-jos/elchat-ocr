<?php

namespace App\Domain\MCP\Connectors;

use App\Domain\MCP\Contracts\MCPConnectorInterface;
use App\Domain\MCP\Exceptions\ConnectorUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Base commune à tous les connecteurs : retry avec backoff, circuit breaker
 * (on arrête de taper une API qui échoue en boucle), et timeout uniforme.
 * Les connecteurs concrets héritent de ça et n'ont à écrire que leur logique
 * métier — la robustesse réseau est déjà gérée.
 */
abstract class AbstractConnector implements MCPConnectorInterface
{
    protected int $maxRetries = 2;
    protected int $circuitBreakerThreshold = 5;   // échecs consécutifs avant ouverture du circuit
    protected int $circuitBreakerCooldown = 60;   // secondes avant nouvelle tentative

    public function defaultTimeout(): int
    {
        return 8;
    }

    /**
     * Wrapper HTTP à utiliser par tous les connecteurs concrets pour leurs
     * appels sortants. Applique timeout, retry, et circuit breaker.
     */
    protected function http(?string $baseUrl = null)
    {
        if ($this->isCircuitOpen()) {
            throw new ConnectorUnavailableException(
                "Circuit ouvert pour {$this->slug()} : trop d'échecs récents, nouvelle tentative dans quelques instants."
            );
        }

        $site = Http::timeout($this->defaultTimeout())
            ->connectTimeout(3)
            ->retry($this->maxRetries, 300, function (Throwable $e) {
                // Ne retry que sur erreurs transitoires (réseau, 5xx, 429), jamais sur 4xx métier
                return $e instanceof ConnectionException
                    || ($e instanceof RequestException
                        && in_array($e->response?->status(), [429, 500, 502, 503, 504], true));
            })
            ->throw(function ($response, $e) {
                $this->recordFailure();
            });

        return $baseUrl ? $site->baseUrl($baseUrl) : $site;
    }

    protected function recordFailure(): void
    {
        $key = "mcp:circuit:{$this->slug()}:failures";
        $failures = Cache::increment($key);
        Cache::put($key, $failures, now()->addMinutes(5));

        if ($failures >= $this->circuitBreakerThreshold) {
            Cache::put("mcp:circuit:{$this->slug()}:open", true, now()->addSeconds($this->circuitBreakerCooldown));
            Log::warning("MCP circuit breaker ouvert pour {$this->slug()} après {$failures} échecs consécutifs.");
        }
    }

    protected function recordSuccess(): void
    {
        Cache::forget("mcp:circuit:{$this->slug()}:failures");
    }

    protected function isCircuitOpen(): bool
    {
        return (bool) Cache::get("mcp:circuit:{$this->slug()}:open", false);
    }
}
