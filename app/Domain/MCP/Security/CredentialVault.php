<?php

namespace App\Domain\MCP\Security;

use App\Models\Mcp\McpConnector;
use App\Models\Mcp\McpSiteConnector;
use App\Models\Site;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * Seul point d'accès aux identifiants tiers. Chiffre/déchiffre avec la clé
 * applicative Laravel (APP_KEY). Aucun composant du système, y compris les
 * logs, ne doit jamais afficher des credentials en clair.
 */
class CredentialVault
{
    /** Encrypts credentials for domain records that need multiple named connections per site. */
    public function seal(array $credentials): string
    {
        return Crypt::encryptString(json_encode($credentials, JSON_THROW_ON_ERROR));
    }

    /** Decrypts an encrypted credential payload. Invalid ciphertext fails closed. */
    public function open(string $encrypted): ?array
    {
        try {
            $decoded = json_decode(Crypt::decryptString($encrypted), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    public function store(Site $site, string $connectorSlug, array $credentials, array $settings = [], array $metadata = []): McpSiteConnector
    {
        $connector = McpConnector::where('slug', $connectorSlug)->firstOrFail();

        return McpSiteConnector::updateOrCreate(
            ['site_id' => $site->id, 'mcp_connector_id' => $connector->id],
            [
                'credentials_encrypted' => $this->seal($credentials),
                'settings' => $settings,
                'status' => 'connected',
                'connected_at' => now(),
                'provider_tenant_id' => $metadata['provider_tenant_id'] ?? null,
                'provider_principal_id' => $metadata['provider_principal_id'] ?? null,
                'provider_principal_upn' => $metadata['provider_principal_upn'] ?? null,
                'granted_scopes' => $metadata['granted_scopes'] ?? ($credentials['granted_scopes'] ?? null),
            ]
        );
    }

    public function retrieve(Site $site, string $connectorSlug): ?array
    {
        $record = $site->mcpSiteConnectors()
            ->whereHas('mcpConnector', fn ($q) => $q->where('slug', $connectorSlug))
            ->first();

        if (!$record || !$record->credentials_encrypted) {
            return null;
        }

        try {
            $decrypted = $this->open($record->credentials_encrypted);
        } catch (\Exception $e) {
            Log::error("MCP: échec de déchiffrement des identifiants pour site {$site->id} / {$connectorSlug}");
            return null;
        }

        if (!is_array($decrypted)) {
            Log::error("MCP: identifiants invalides pour site {$site->id} / {$connectorSlug}");
            return null;
        }

        // Settings are non-secret connector options; encrypted credentials always win on key collisions.
        $credentials = array_merge($record->settings ?? [], $decrypted);
        if (!array_key_exists('granted_scopes', $credentials) && $record->granted_scopes !== null) {
            $credentials['granted_scopes'] = $record->granted_scopes;
        }

        return $credentials;
    }

    /**
     * Réécrit les identifiants après un refresh de token (ex: nouveau
     * access_token OAuth) sans toucher au reste de l'enregistrement.
     */
    public function refresh(Site $site, string $connectorSlug, array $newCredentials): void
    {
        $record = $site->mcpSiteConnectors()
            ->whereHas('mcpConnector', fn ($q) => $q->where('slug', $connectorSlug))
            ->firstOrFail();

        $record->update([
            'credentials_encrypted' => $this->seal($newCredentials),
            'status' => 'connected',
            'last_used_at' => now(),
        ]);
    }

    public function markAuthExpired(Site $site, string $connectorSlug, string $reason): void
    {
        $site->mcpSiteConnectors()
            ->whereHas('mcpConnector', fn ($q) => $q->where('slug', $connectorSlug))
            ->update([
                'status' => 'auth_expired',
                'last_error_at' => now(),
                'last_error_message' => $reason,
            ]);
    }

    public function revoke(Site $site, string $connectorSlug): void
    {
        $site->mcpSiteConnectors()
            ->whereHas('mcpConnector', fn ($q) => $q->where('slug', $connectorSlug))
            ->update([
                'status' => 'revoked',
                'credentials_encrypted' => null,
                'provider_tenant_id' => null,
                'provider_principal_id' => null,
                'provider_principal_upn' => null,
                'granted_scopes' => null,
            ]);
    }
}
