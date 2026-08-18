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
    public function store(Site $site, string $connectorSlug, array $credentials, array $settings = []): McpSiteConnector
    {
        $connector = McpConnector::where('slug', $connectorSlug)->firstOrFail();

        return McpSiteConnector::updateOrCreate(
            ['site_id' => $site->id, 'mcp_connector_id' => $connector->id],
            [
                'credentials_encrypted' => Crypt::encryptString(json_encode($credentials)),
                'settings' => $settings,
                'status' => 'connected',
                'connected_at' => now(),
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
            $decrypted = json_decode(Crypt::decryptString($record->credentials_encrypted), true);
        } catch (\Exception $e) {
            Log::error("MCP: échec de déchiffrement des identifiants pour site {$site->id} / {$connectorSlug}");
            return null;
        }

        return array_merge($decrypted, $record->settings ?? []);
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
            'credentials_encrypted' => Crypt::encryptString(json_encode($newCredentials)),
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
            ]);
    }
}
