<?php

namespace App\Domain\Microsoft365;

use App\Domain\Microsoft365\Exceptions\MicrosoftGraphException;
use App\Domain\MCP\Exceptions\AuthExpiredException;
use Illuminate\Support\Facades\Http;

final class Microsoft365OAuthService
{
    public function authorizeUrl(string $state, array $scopes, bool $forceConsent = false): string
    {
        $tenant = rawurlencode((string) config('mcp.connectors.microsoft_365.tenant', 'common'));
        $clientId = (string) config('mcp.connectors.microsoft_365.client_id');

        $query = [
            'client_id' => $clientId,
            'response_type' => 'code',
            'redirect_uri' => route('mcp.oauth.callback', ['slug' => 'microsoft_365']),
            'response_mode' => 'query',
            'scope' => implode(' ', $scopes),
            'state' => $state,
        ];

        // Microsoft peut conserver un consentement délégué déjà accordé
        // (par exemple User.Read) et ne pas reproposer les permissions
        // statiques nouvellement ajoutées. Cette option est activée seulement
        // par le bouton explicite « Actualiser les autorisations ».
        if ($forceConsent) {
            $query['prompt'] = 'consent';
        }

        return "https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/authorize?" . http_build_query($query);
    }

    /** @return array<string, mixed> */
    public function exchangeCode(string $code): array
    {
        return $this->tokenRequest([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => route('mcp.oauth.callback', ['slug' => 'microsoft_365']),
        ]);
    }

    /** @return array<string, mixed> */
    public function refresh(array $credentials): array
    {
        if (empty($credentials['refresh_token'])) {
            throw new AuthExpiredException('Refresh token Microsoft 365 absent, reconnexion requise.');
        }

        $data = $this->tokenRequest([
            'grant_type' => 'refresh_token',
            'refresh_token' => $credentials['refresh_token'],
        ]);

        return array_merge($credentials, $this->normalizeToken($data, $credentials));
    }

    /** @return array<string, mixed> */
    public function normalizeToken(array $token, array $previous = []): array
    {
        if (empty($token['access_token'])) {
            throw new AuthExpiredException('Microsoft Graph n’a pas retourné de jeton valide.');
        }

        $rawScopes = trim((string) ($token['scope'] ?? implode(' ', $previous['granted_scopes'] ?? [])));

        return [
            'access_token' => $token['access_token'],
            'refresh_token' => $token['refresh_token'] ?? ($previous['refresh_token'] ?? null),
            'expires_at' => now()->addSeconds((int) ($token['expires_in'] ?? 3600))->timestamp,
            'granted_scopes' => $rawScopes === '' ? [] : (preg_split('/\s+/', $rawScopes) ?: []),
        ];
    }

    /** @return array<string, mixed> */
    public function profile(string $accessToken): array
    {
        try {
            return MicrosoftGraphClient::forToken($accessToken)->get('/me', [
                '$select' => 'id,displayName,userPrincipalName,mail',
            ]);
        } catch (MicrosoftGraphException $exception) {
            if ($exception->isAuthFailure()) {
                throw new AuthExpiredException('La session Microsoft 365 n’est plus valide.');
            }

            throw $exception;
        }
    }

    public function tenantIdFromToken(array $token): ?string
    {
        $idToken = $token['id_token'] ?? null;
        if (!is_string($idToken)) {
            return null;
        }

        $parts = explode('.', $idToken);
        if (count($parts) < 2) {
            return null;
        }

        $encoded = strtr($parts[1], '-_', '+/');
        $encoded .= str_repeat('=', (4 - strlen($encoded) % 4) % 4);
        $payload = json_decode(base64_decode($encoded), true);
        return is_array($payload) && isset($payload['tid']) ? (string) $payload['tid'] : null;
    }

    /** @return array<string, mixed> */
    private function tokenRequest(array $grant): array
    {
        $tenant = rawurlencode((string) config('mcp.connectors.microsoft_365.tenant', 'common'));
        $url = "https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/token";

        $response = Http::asForm()->timeout(20)->connectTimeout(5)->post($url, array_merge([
            'client_id' => config('mcp.connectors.microsoft_365.client_id'),
            'client_secret' => config('mcp.connectors.microsoft_365.client_secret'),
        ], $grant));

        if ($response->failed()) {
            throw new AuthExpiredException('Microsoft 365 a refusé la connexion ou le renouvellement du jeton.');
        }

        $data = $response->json();
        if (!is_array($data)) {
            throw new AuthExpiredException('Réponse OAuth Microsoft 365 invalide.');
        }

        return $data;
    }
}
