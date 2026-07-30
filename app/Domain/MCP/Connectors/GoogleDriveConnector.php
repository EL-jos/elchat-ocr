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
 * credentials attendus : { access_token, refresh_token, expires_at }
 */
class GoogleDriveConnector extends AbstractConnector
{
    use RefreshesOAuthToken;

    private const API_BASE = 'https://www.googleapis.com/drive/v3/';

    public function slug(): string { return 'google_drive'; }

    public function authenticate(array $credentials): array
    {
        $expiresAt = $credentials['expires_at'] ?? null;
        if ($expiresAt && now()->timestamp < $expiresAt - 60) return $credentials;

        if (empty($credentials['refresh_token'])) {
            throw new AuthExpiredException('Refresh token Google Drive absent, reconnexion requise.');
        }

        return $this->refreshOAuthToken('https://oauth2.googleapis.com/token', [
            'client_id' => config('mcp.connectors.google_drive.client_id'),
            'client_secret' => config('mcp.connectors.google_drive.client_secret'),
            'refresh_token' => $credentials['refresh_token'], 'grant_type' => 'refresh_token',
        ], $credentials);
    }

    public function listTools(): array
    {
        return [
            new ToolSchema('google_drive', 'search_files', "Recherche des fichiers par nom.", [
                'type' => 'object', 'properties' => ['query' => ['type' => 'string']], 'required' => ['query'],
            ], defaultActorScope: 'admin', defaultMode: 'auto', capability: 'storage.search_file'),

            new ToolSchema('google_drive', 'get_file', "Détails et lien d'un fichier.", [
                'type' => 'object', 'properties' => ['file_id' => ['type' => 'string']], 'required' => ['file_id'],
            ], defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('google_drive', 'list_recent_files', "Liste les fichiers récemment modifiés.", [
                'type' => 'object', 'properties' => ['limit' => ['type' => 'integer']],
            ], defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('google_drive', 'upload_file', "Crée un fichier texte dans Drive (ex: compte-rendu, note).", [
                'type' => 'object', 'properties' => ['name' => ['type' => 'string'], 'content' => ['type' => 'string']], 'required' => ['name', 'content'],
            ], isWriteAction: true, defaultActorScope: 'admin', defaultMode: 'auto', capability: 'storage.upload_file'),

            new ToolSchema('google_drive', 'share_file', "Partage un fichier avec une adresse email.", [
                'type' => 'object', 'properties' => ['file_id' => ['type' => 'string'], 'email' => ['type' => 'string'], 'role' => ['type' => 'string', 'enum' => ['reader', 'writer']]], 'required' => ['file_id', 'email'],
            ], isWriteAction: true, defaultActorScope: 'admin', defaultMode: 'confirm', defaultConfirmActor: 'admin', capability: 'storage.share_file'),
        ];
    }

    public function callTool(string $toolName, array $params, array $credentials, array $context = []): ToolResult
    {
        return match ($toolName) {
            'search_files' => $this->searchFiles($params, $credentials),
            'get_file' => $this->getFile($params, $credentials),
            'list_recent_files' => $this->listRecentFiles($params, $credentials),
            'upload_file' => $this->uploadFile($params, $credentials),
            'share_file' => $this->shareFile($params, $credentials),
            default => throw new ToolNotFoundException("Outil '{$toolName}' inconnu pour google_drive."),
        };
    }

    private function searchFiles(array $p, array $c): ToolResult
    {
        try {
            $res = $this->client($c)->get('files', [
                'q' => "name contains '" . addslashes($p['query']) . "' and trashed = false",
                'fields' => 'files(id,name,webViewLink,mimeType)', 'pageSize' => 10,
            ]);
        } catch (RequestException $e) {
            throw new ConnectorUnavailableException('Google Drive indisponible: ' . $e->getMessage());
        }
        $this->recordSuccess();
        $files = $res->json('files', []);
        if (empty($files)) return ToolResult::fail('not_found', 'Aucun fichier trouvé.');
        return ToolResult::ok(['files' => $files], count($files) . ' fichier(s) trouvé(s)');
    }

    private function getFile(array $p, array $c): ToolResult
    {
        try {
            $file = $this->client($c)->get("files/{$p['file_id']}", ['fields' => 'id,name,webViewLink,mimeType,modifiedTime'])->json();
        } catch (RequestException $e) {
            if ($e->response?->status() === 404) return ToolResult::fail('not_found', 'Fichier introuvable.');
            throw new ConnectorUnavailableException('Google Drive indisponible: ' . $e->getMessage());
        }
        $this->recordSuccess();
        return ToolResult::ok($file, "Fichier : {$file['name']}");
    }

    private function listRecentFiles(array $p, array $c): ToolResult
    {
        try {
            $res = $this->client($c)->get('files', [
                'orderBy' => 'modifiedTime desc', 'pageSize' => min(20, (int) ($p['limit'] ?? 10)),
                'fields' => 'files(id,name,webViewLink,modifiedTime)', 'q' => 'trashed = false',
            ]);
        } catch (RequestException $e) {
            throw new ConnectorUnavailableException('Google Drive indisponible: ' . $e->getMessage());
        }
        $this->recordSuccess();
        return ToolResult::ok(['files' => $res->json('files', [])], 'Fichiers récents récupérés.');
    }

    private function uploadFile(array $p, array $c): ToolResult
    {
        try {
            $meta = $this->client($c)->post('files', ['name' => $p['name'], 'mimeType' => 'text/plain'])->json();
            $this->http('https://www.googleapis.com/upload/drive/v3/')->withToken($c['access_token'])
                ->withBody($p['content'], 'text/plain')->patch("files/{$meta['id']}?uploadType=media");
        } catch (RequestException $e) {
            throw new ConnectorUnavailableException('Google Drive indisponible: ' . $e->getMessage());
        }
        $this->recordSuccess();
        return ToolResult::ok(['file_id' => $meta['id']], "Fichier « {$p['name']} » créé dans Drive.");
    }

    private function shareFile(array $p, array $c): ToolResult
    {
        try {
            $this->client($c)->post("files/{$p['file_id']}/permissions", [
                'type' => 'user', 'role' => $p['role'] ?? 'reader', 'emailAddress' => $p['email'],
            ]);
        } catch (RequestException $e) {
            if ($e->response?->status() === 404) return ToolResult::fail('not_found', 'Fichier introuvable.');
            throw new ConnectorUnavailableException('Google Drive indisponible: ' . $e->getMessage());
        }
        $this->recordSuccess();
        return ToolResult::ok(['file_id' => $p['file_id']], "Fichier partagé avec {$p['email']}.");
    }

    private function client(array $c)
    {
        return $this->http(self::API_BASE)->withToken($c['access_token']);
    }
}
