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
            new ToolSchema('onedrive', 'search_files',
                "Recherche un ou plusieurs fichiers dans OneDrive.

À utiliser lorsque l'utilisateur souhaite :

• retrouver un document
• retrouver un PDF
• retrouver un contrat
• retrouver une facture
• retrouver une présentation
• retrouver un fichier Excel
• retrouver un document Word
• retrouver un rapport
• retrouver un document par son nom
• savoir si un document existe

Bonnes pratiques :

- Effectuer une recherche pertinente sur le nom du fichier.
- Retourner uniquement les fichiers réellement pertinents.
- Trier les résultats du plus pertinent au moins pertinent.
- Inclure le nom, le lien, la dernière modification si disponible.
- Si aucun fichier n'est trouvé, l'indiquer clairement.
- Ne jamais inventer un fichier inexistant.", [
                'type' => 'object', 'properties' => ['query' => ['type' => 'string']], 'required' => ['query'],
            ], defaultActorScope: 'admin', defaultMode: 'auto', capability: 'storage.search_file'),

            new ToolSchema('onedrive', 'get_file',
                "Récupère les informations d'un fichier OneDrive.

À utiliser lorsque l'utilisateur souhaite :

• consulter un document
• obtenir le lien d'un fichier
• vérifier les informations d'un fichier
• retrouver un fichier précis

Bonnes pratiques :

- Retourner le nom du fichier.
- Retourner le lien OneDrive.
- Retourner les métadonnées disponibles (taille, date, auteur, dossier...).
- Si le fichier n'existe pas, l'indiquer clairement.
- Ne jamais inventer des informations absentes.", [
                'type' => 'object', 'properties' => ['file_id' => ['type' => 'string']], 'required' => ['file_id'],
            ], defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('onedrive', 'list_recent_files',
                "Liste les fichiers récemment modifiés dans OneDrive.

À utiliser lorsque l'utilisateur souhaite :

• retrouver les derniers documents
• voir les fichiers récents
• reprendre un travail récent
• retrouver les dernières modifications

Bonnes pratiques :

- Trier du plus récent au plus ancien.
- Retourner uniquement les fichiers réellement disponibles.
- Inclure le nom, le lien et la date de modification lorsque disponible.
- Si aucun fichier récent n'est disponible, l'indiquer.", [
                'type' => 'object', 'properties' => [],
            ], defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('onedrive', 'upload_file',
                "Crée un nouveau fichier texte dans OneDrive.

À utiliser lorsque l'utilisateur souhaite :

• enregistrer une note
• sauvegarder un compte-rendu
• créer une documentation
• exporter un résumé
• générer un rapport
• sauvegarder une conversation
• conserver un historique

Bonnes pratiques :

- Vérifier que le nom du fichier est valide.
- Générer un contenu propre et bien structuré.
- Ne jamais écraser un fichier existant sans demande explicite.
- Utiliser un nom explicite si aucun nom n'est fourni.
- Informer l'utilisateur une fois le fichier créé.", [
                'type' => 'object', 'properties' => ['name' => ['type' => 'string'], 'content' => ['type' => 'string']], 'required' => ['name', 'content'],
            ], isWriteAction: true, defaultActorScope: 'admin', defaultMode: 'auto', capability: 'storage.upload_file'),

            new ToolSchema('onedrive', 'share_file',
                "Partage un fichier OneDrive avec une personne.

À utiliser lorsque l'utilisateur souhaite :

• envoyer un document
• partager un rapport
• partager une présentation
• envoyer une facture
• envoyer un contrat
• collaborer avec une personne

Bonnes pratiques :

- Vérifier que le fichier existe.
- Vérifier que l'adresse email est valide.
- Expliquer clairement que le destinataire recevra une invitation Microsoft.
- Ne partager qu'après confirmation lorsque l'action peut exposer des données sensibles.
- Signaler tout échec de partage.", [
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
