<?php

namespace App\Domain\MCP\Connectors;

use App\Domain\MCP\Connectors\Concerns\RefreshesOAuthToken;
use App\Domain\MCP\Contracts\ToolResult;
use App\Domain\MCP\Contracts\ToolSchema;
use App\Domain\MCP\Exceptions\AuthExpiredException;
use App\Domain\MCP\Exceptions\ConnectorUnavailableException;
use App\Domain\MCP\Exceptions\ToolNotFoundException;
use Illuminate\Http\Client\RequestException;

/**
 * Microsoft Graph. credentials attendus : { access_token, refresh_token, expires_at }
 */
class OneDriveConnector extends AbstractConnector
{
    use RefreshesOAuthToken;

    private const API_BASE = 'https://graph.microsoft.com/v1.0/';

    public function slug(): string { return 'onedrive'; }

    public function authenticate(array $credentials): array
    {
        $expiresAt = $credentials['expires_at'] ?? null;
        if ($expiresAt && now()->timestamp < $expiresAt - 60) return $credentials;

        if (empty($credentials['refresh_token'])) {
            throw new AuthExpiredException('Refresh token OneDrive absent, reconnexion requise.');
        }

        $tenant = config('mcp.connectors.onedrive.tenant', 'common');
        return $this->refreshOAuthToken("https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/token", [
            'client_id' => config('mcp.connectors.onedrive.client_id'),
            'client_secret' => config('mcp.connectors.onedrive.client_secret'),
            'refresh_token' => $credentials['refresh_token'], 'grant_type' => 'refresh_token',
            'scope' => 'Files.ReadWrite offline_access',
        ], $credentials);
    }

    public function listTools(): array
    {
        return [
            new ToolSchema('onedrive', 'search_files', "Recherche des fichiers par nom.", [
                'type' => 'object', 'properties' => ['query' => ['type' => 'string']], 'required' => ['query'],
            ], defaultActorScope: 'admin', defaultMode: 'auto', capability: 'storage.search_file'),

            new ToolSchema('onedrive', 'get_file', "Détails et lien d'un fichier.", [
                'type' => 'object', 'properties' => ['file_id' => ['type' => 'string']], 'required' => ['file_id'],
            ], defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('onedrive', 'list_recent_files', "Liste les fichiers récemment modifiés.", [
                'type' => 'object', 'properties' => [],
            ], defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('onedrive', 'upload_file', "Crée un fichier texte dans OneDrive.", [
                'type' => 'object', 'properties' => ['name' => ['type' => 'string'], 'content' => ['type' => 'string']], 'required' => ['name', 'content'],
            ], isWriteAction: true, defaultActorScope: 'admin', defaultMode: 'auto', capability: 'storage.upload_file'),

            new ToolSchema('onedrive', 'share_file', "Partage un fichier avec une adresse email.", [
                'type' => 'object', 'properties' => ['file_id' => ['type' => 'string'], 'email' => ['type' => 'string']], 'required' => ['file_id', 'email'],
            ], isWriteAction: true, defaultActorScope: 'admin', defaultMode: 'confirm', defaultConfirmActor: 'admin', capability: 'storage.share_file'),
        ];
    }

    public function callTool(string $toolName, array $params, array $credentials, array $context = []): ToolResult
    {
        return match ($toolName) {
            'search_files' => $this->searchFiles($params, $credentials),
            'get_file' => $this->getFile($params, $credentials),
            'list_recent_files' => $this->listRecentFiles($credentials),
            'upload_file' => $this->uploadFile($params, $credentials),
            'share_file' => $this->shareFile($params, $credentials),
            default => throw new ToolNotFoundException("Outil '{$toolName}' inconnu pour onedrive."),
        };
    }

    private function searchFiles(array $p, array $c): ToolResult
    {
        try {
            $res = $this->client($c)->get("me/drive/root/search(q='" . urlencode($p['query']) . "')");
        } catch (RequestException $e) {
            throw new ConnectorUnavailableException('OneDrive indisponible: ' . $e->getMessage());
        }
        $this->recordSuccess();
        $files = collect($res->json('value', []))->map(fn ($f) => ['id' => $f['id'], 'name' => $f['name'], 'webUrl' => $f['webUrl']])->all();
        if (empty($files)) return ToolResult::fail('not_found', 'Aucun fichier trouvé.');
        return ToolResult::ok(['files' => $files], count($files) . ' fichier(s) trouvé(s)');
    }

    private function getFile(array $p, array $c): ToolResult
    {
        try {
            $file = $this->client($c)->get("me/drive/items/{$p['file_id']}")->json();
        } catch (RequestException $e) {
            if ($e->response?->status() === 404) return ToolResult::fail('not_found', 'Fichier introuvable.');
            throw new ConnectorUnavailableException('OneDrive indisponible: ' . $e->getMessage());
        }
        $this->recordSuccess();
        return ToolResult::ok(['id' => $file['id'], 'name' => $file['name'], 'webUrl' => $file['webUrl']], "Fichier : {$file['name']}");
    }

    private function listRecentFiles(array $c): ToolResult
    {
        try {
            $res = $this->client($c)->get('me/drive/recent');
        } catch (RequestException $e) {
            throw new ConnectorUnavailableException('OneDrive indisponible: ' . $e->getMessage());
        }
        $this->recordSuccess();
        return ToolResult::ok(['files' => $res->json('value', [])], 'Fichiers récents récupérés.');
    }

    private function uploadFile(array $p, array $c): ToolResult
    {
        try {
            $file = $this->http('https://graph.microsoft.com/v1.0/')->withToken($c['access_token'])
                ->withBody($p['content'], 'text/plain')
                ->put("me/drive/root:/{$p['name']}:/content")->json();
        } catch (RequestException $e) {
            throw new ConnectorUnavailableException('OneDrive indisponible: ' . $e->getMessage());
        }
        $this->recordSuccess();
        return ToolResult::ok(['file_id' => $file['id']], "Fichier « {$p['name']} » créé dans OneDrive.");
    }

    private function shareFile(array $p, array $c): ToolResult
    {
        try {
            $this->client($c)->post("me/drive/items/{$p['file_id']}/invite", [
                'recipients' => [['email' => $p['email']]], 'requireSignIn' => true, 'roles' => ['read'],
            ]);
        } catch (RequestException $e) {
            if ($e->response?->status() === 404) return ToolResult::fail('not_found', 'Fichier introuvable.');
            throw new ConnectorUnavailableException('OneDrive indisponible: ' . $e->getMessage());
        }
        $this->recordSuccess();
        return ToolResult::ok(['file_id' => $p['file_id']], "Fichier partagé avec {$p['email']}.");
    }

    private function client(array $c)
    {
        return $this->http(self::API_BASE)->withToken($c['access_token']);
    }
}
