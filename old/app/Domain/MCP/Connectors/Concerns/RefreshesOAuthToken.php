<?php

namespace App\Domain\MCP\Connectors\Concerns;

use App\Domain\MCP\Exceptions\AuthExpiredException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Factorise le rafraîchissement de token OAuth2, identique pour Google
 * Drive et OneDrive (et déjà pour Google Calendar, qui pourra migrer vers
 * ce trait sans changement de comportement). Évite de dupliquer 3 fois le
 * même bloc try/catch + gestion d'erreur.
 */
trait RefreshesOAuthToken
{
    protected function refreshOAuthToken(string $tokenUrl, array $params, array $credentials): array
    {
        try {
            $response = Http::asForm()->post($tokenUrl, $params);
        } catch (RequestException $e) {
            Log::error("MCP {$this->slug()}: échec du refresh token", ['status' => $e->response?->status()]);
            throw new AuthExpiredException('Impossible de rafraîchir le token, reconnexion requise.');
        }

        if ($response->failed()) {
            Log::error("MCP {$this->slug()}: refresh token refusé", ['status' => $response->status()]);
            throw new AuthExpiredException('Token invalide ou révoqué, reconnexion requise.');
        }

        $data = $response->json();

        // Préserve toutes les clés déjà présentes (settings propres au site) —
        // même correctif que celui appliqué à GoogleCalendarConnector.
        return array_merge($credentials, [
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? $credentials['refresh_token'],
            'expires_at' => now()->addSeconds($data['expires_in'])->timestamp,
        ]);
    }
}
