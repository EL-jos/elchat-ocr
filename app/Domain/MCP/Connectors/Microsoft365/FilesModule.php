<?php

namespace App\Domain\MCP\Connectors\Microsoft365;

use App\Domain\MCP\Contracts\ToolResult;
use App\Domain\MCP\Contracts\ToolSchema;
use App\Domain\MCP\Exceptions\ToolNotFoundException;
use App\Domain\Microsoft365\MicrosoftGraphClient;

final class FilesModule extends AbstractMicrosoft365Module
{
    public function key(): string { return 'files'; }

    public function label(): string { return 'Fichiers OneDrive et SharePoint'; }

    public function iconUrl(): ?string { return 'https://upload.wikimedia.org/wikipedia/commons/e/e7/Microsoft_OneDrive_Icon_%282025_-_present%29.svg'; }

    /** @return ToolSchema[] */
    public function listTools(): array
    {
        return [
            $this->readTool('documents_search', 'Recherche des fichiers disponibles dans OneDrive ou une bibliothèque SharePoint.', ['query' => ['type' => 'string'], 'drive_id' => ['type' => 'string'], 'site_id' => ['type' => 'string']], ['query'], 'documents.search'),
            $this->readTool('documents_get', 'Récupère les métadonnées et le lien d’un fichier Microsoft 365.', ['item_id' => ['type' => 'string'], 'drive_id' => ['type' => 'string'], 'site_id' => ['type' => 'string']], ['item_id'], 'documents.read'),
            $this->readTool('documents_list_children', 'Liste les fichiers et dossiers d’un dossier OneDrive ou SharePoint.', ['item_id' => ['type' => 'string'], 'drive_id' => ['type' => 'string'], 'site_id' => ['type' => 'string']], [], 'documents.read'),
            $this->readTool('documents_download', 'Télécharge le contenu textuel ou binaire d’un fichier, dans la limite d’une réponse raisonnable pour le modèle.', ['item_id' => ['type' => 'string'], 'drive_id' => ['type' => 'string'], 'site_id' => ['type' => 'string']], ['item_id'], 'documents.download'),
            $this->writeTool('documents_upload', 'Crée un fichier dans OneDrive ou SharePoint.', ['name' => ['type' => 'string'], 'content' => ['type' => 'string'], 'content_base64' => ['type' => 'string'], 'mime_type' => ['type' => 'string'], 'parent_item_id' => ['type' => 'string'], 'drive_id' => ['type' => 'string'], 'site_id' => ['type' => 'string']], ['name'], 'documents.create', 'confirm'),
            $this->writeTool('documents_update', 'Remplace le contenu d’un fichier Microsoft 365 existant.', ['item_id' => ['type' => 'string'], 'content' => ['type' => 'string'], 'content_base64' => ['type' => 'string'], 'mime_type' => ['type' => 'string'], 'drive_id' => ['type' => 'string'], 'site_id' => ['type' => 'string']], ['item_id'], 'documents.update', 'confirm'),
            $this->writeTool('documents_create_folder', 'Crée un dossier dans une bibliothèque Microsoft 365.', ['name' => ['type' => 'string'], 'parent_item_id' => ['type' => 'string'], 'drive_id' => ['type' => 'string'], 'site_id' => ['type' => 'string']], ['name'], 'documents.create_folder', 'confirm'),
            $this->writeTool('documents_move', 'Déplace un fichier ou dossier dans le même drive Microsoft 365.', ['item_id' => ['type' => 'string'], 'parent_item_id' => ['type' => 'string'], 'drive_id' => ['type' => 'string'], 'site_id' => ['type' => 'string']], ['item_id', 'parent_item_id'], 'documents.move', 'confirm'),
            $this->writeTool('documents_copy', 'Lance la copie asynchrone d’un fichier vers un dossier du même drive.', ['item_id' => ['type' => 'string'], 'parent_item_id' => ['type' => 'string'], 'name' => ['type' => 'string'], 'drive_id' => ['type' => 'string'], 'site_id' => ['type' => 'string']], ['item_id', 'parent_item_id'], 'documents.copy', 'confirm'),
            $this->writeTool('documents_delete', 'Supprime un fichier ou dossier Microsoft 365 ; Graph le place dans la corbeille lorsqu’il le permet.', ['item_id' => ['type' => 'string'], 'drive_id' => ['type' => 'string'], 'site_id' => ['type' => 'string']], ['item_id'], 'documents.delete', 'confirm'),
            $this->writeTool('documents_share', 'Invite une adresse e-mail à accéder à un fichier Microsoft 365.', ['item_id' => ['type' => 'string'], 'email' => ['type' => 'string'], 'role' => ['type' => 'string', 'enum' => ['read', 'write']], 'drive_id' => ['type' => 'string'], 'site_id' => ['type' => 'string']], ['item_id', 'email'], 'documents.share', 'confirm'),
            $this->writeTool('documents_create_link', 'Crée un lien de partage Microsoft 365 pour un fichier.', ['item_id' => ['type' => 'string'], 'type' => ['type' => 'string', 'enum' => ['view', 'edit']], 'scope' => ['type' => 'string', 'enum' => ['anonymous', 'organization', 'users']], 'drive_id' => ['type' => 'string'], 'site_id' => ['type' => 'string']], ['item_id'], 'documents.share', 'confirm'),
            $this->readTool('sharepoint_get_site_by_path', 'Récupère un site SharePoint par nom d’hôte et chemin.', ['hostname' => ['type' => 'string'], 'path' => ['type' => 'string']], ['hostname', 'path'], 'sharepoint.read'),
            $this->readTool('sharepoint_list_drives', 'Liste les bibliothèques de documents d’un site SharePoint.', ['site_id' => ['type' => 'string']], ['site_id'], 'sharepoint.read'),
        ];
    }

    /** @return array<string, list<string>> */
    protected function requiredScopes(): array
    {
        return [
            'documents_search' => ['Files.Read'], 'documents_get' => ['Files.Read'], 'documents_list_children' => ['Files.Read'], 'documents_download' => ['Files.Read'],
            'documents_upload' => ['Files.ReadWrite'], 'documents_update' => ['Files.ReadWrite'], 'documents_create_folder' => ['Files.ReadWrite'], 'documents_move' => ['Files.ReadWrite'], 'documents_copy' => ['Files.ReadWrite'], 'documents_delete' => ['Files.ReadWrite'], 'documents_share' => ['Files.ReadWrite'], 'documents_create_link' => ['Files.ReadWrite'],
            'sharepoint_get_site_by_path' => ['Sites.Read.All'], 'sharepoint_list_drives' => ['Sites.Read.All'],
        ];
    }

    public function callTool(string $toolName, array $params, array $credentials, array $context, MicrosoftGraphClient $graph): ToolResult
    {
        return match ($toolName) {
            'documents_search' => $this->documentsSearch($graph, $params),
            'documents_get' => $this->documentsGet($graph, $params),
            'documents_list_children' => $this->documentsListChildren($graph, $params),
            'documents_download' => $this->documentsDownload($graph, $params),
            'documents_upload' => $this->documentsUpload($graph, $params),
            'documents_update' => $this->documentsUpdate($graph, $params),
            'documents_create_folder' => $this->documentsCreateFolder($graph, $params),
            'documents_move' => $this->documentsMove($graph, $params),
            'documents_copy' => $this->documentsCopy($graph, $params),
            'documents_delete' => $this->documentsDelete($graph, $params),
            'documents_share' => $this->documentsShare($graph, $params),
            'documents_create_link' => $this->documentsCreateLink($graph, $params),
            'sharepoint_get_site_by_path' => $this->sharepointSite($graph, $params),
            'sharepoint_list_drives' => $this->sharepointDrives($graph, $params),
            default => throw new ToolNotFoundException("Outil '{$toolName}' inconnu pour le module Fichiers Microsoft 365."),
        };
    }

    private function documentsSearch(MicrosoftGraphClient $g, array $p): ToolResult
    {
        $query = str_replace("'", "''", (string) $p['query']);
        $items = $g->collectPages($this->drivePrefix($p) . "/root/search(q='" . rawurlencode($query) . "')");
        return ToolResult::ok(['files' => array_map([$this, 'fileSummary'], $items)], count($items) . ' fichier(s) trouvé(s)');
    }

    private function documentsGet(MicrosoftGraphClient $g, array $p): ToolResult
    {
        $file = $g->get($this->itemPath($p), ['$select' => 'id,name,size,file,folder,webUrl,lastModifiedDateTime,createdDateTime,parentReference,eTag']);
        return ToolResult::ok(['file' => $this->fileSummary($file)], 'Métadonnées du fichier récupérées.');
    }

    private function documentsListChildren(MicrosoftGraphClient $g, array $p): ToolResult
    {
        $path = !empty($p['item_id']) ? $this->drivePrefix($p) . '/items/' . $this->id($p['item_id']) . '/children' : $this->drivePrefix($p) . '/root/children';
        $items = $g->collectPages($path, ['$select' => 'id,name,size,file,folder,webUrl,lastModifiedDateTime,parentReference']);
        return ToolResult::ok(['files' => array_map([$this, 'fileSummary'], $items)], count($items) . ' élément(s) récupéré(s)');
    }

    private function documentsDownload(MicrosoftGraphClient $g, array $p): ToolResult
    {
        $metadata = $g->get($this->itemPath($p), ['$select' => 'name,size,webUrl']);
        $link = !empty($metadata['webUrl']) ? ' Lien : ' . $metadata['webUrl'] : '';
        if (isset($metadata['size']) && (int) $metadata['size'] > 768 * 1024) {
            return ToolResult::fail('too_large', 'Le fichier est trop volumineux pour être renvoyé dans cette conversation. Ouvrez-le dans Microsoft 365.' . $link);
        }

        $content = $g->download($this->itemPath($p) . '/content');
        if (strlen($content) > 768 * 1024) {
            return ToolResult::fail('too_large', 'Le fichier est trop volumineux pour être renvoyé dans cette conversation. Ouvrez-le dans Microsoft 365.' . $link);
        }

        return ToolResult::ok(['item_id' => $p['item_id'], 'content_base64' => base64_encode($content)], 'Contenu du fichier téléchargé.');
    }

    private function documentsUpload(MicrosoftGraphClient $g, array $p): ToolResult
    {
        $content = $this->binaryContent($p);
        if ($content === null) return ToolResult::fail('invalid_input', 'Le contenu du fichier est absent ou son encodage base64 est invalide.');

        try {
            $file = $this->uploadBytes($g, $p, (string) $p['name'], $content, (string) ($p['mime_type'] ?? 'application/octet-stream'));
        } catch (\InvalidArgumentException|\RuntimeException $exception) {
            return ToolResult::fail('upload_failed', $exception->getMessage());
        }

        return ToolResult::ok(['file' => $this->fileSummary($file)], 'Fichier créé dans Microsoft 365.');
    }

    private function documentsUpdate(MicrosoftGraphClient $g, array $p): ToolResult
    {
        $content = $this->binaryContent($p);
        if ($content === null) return ToolResult::fail('invalid_input', 'Le contenu du fichier est absent ou son encodage base64 est invalide.');
        if (strlen($content) > 512 * 1024 * 1024) return ToolResult::fail('too_large', 'Les fichiers de plus de 512 Mo ne peuvent pas être envoyés par cet outil.');
        $mimeType = (string) ($p['mime_type'] ?? 'application/octet-stream');
        $contentPath = $this->itemPath($p) . '/content';
        if (strlen($content) <= 4 * 1024 * 1024) {
            $file = $g->putContent($contentPath, $content, $mimeType);
        } else {
            $session = $g->post($this->itemPath($p) . '/createUploadSession', ['item' => ['@microsoft.graph.conflictBehavior' => 'replace']]);
            if (empty($session['uploadUrl'])) return ToolResult::fail('upload_session_failed', 'Microsoft 365 n’a pas fourni de session d’upload.');
            $file = $g->uploadLarge((string) $session['uploadUrl'], $content, $mimeType);
        }

        return ToolResult::ok(['file' => $this->fileSummary($file)], 'Fichier mis à jour.');
    }

    private function documentsCreateFolder(MicrosoftGraphClient $g, array $p): ToolResult
    {
        $path = !empty($p['parent_item_id']) ? $this->drivePrefix($p) . '/items/' . $this->id($p['parent_item_id']) . '/children' : $this->drivePrefix($p) . '/root/children';
        $folder = $g->post($path, ['name' => $p['name'], 'folder' => new \stdClass(), '@microsoft.graph.conflictBehavior' => 'fail']);
        return ToolResult::ok(['folder' => $this->fileSummary($folder)], 'Dossier créé.');
    }

    private function documentsMove(MicrosoftGraphClient $g, array $p): ToolResult
    {
        $file = $g->patch($this->itemPath($p), ['parentReference' => ['id' => $p['parent_item_id']]]);
        return ToolResult::ok(['file' => $this->fileSummary($file)], 'Élément déplacé.');
    }

    private function documentsCopy(MicrosoftGraphClient $g, array $p): ToolResult
    {
        $body = ['parentReference' => ['id' => $p['parent_item_id']]];
        if (!empty($p['name'])) $body['name'] = $p['name'];
        $g->post($this->itemPath($p) . '/copy', $body);
        return ToolResult::ok(['status' => 'accepted'], 'Copie Microsoft 365 lancée ; Graph la traite de manière asynchrone.');
    }

    private function documentsDelete(MicrosoftGraphClient $g, array $p): ToolResult
    {
        $g->delete($this->itemPath($p));
        return ToolResult::ok(['item_id' => $p['item_id']], 'Élément supprimé.');
    }

    private function documentsShare(MicrosoftGraphClient $g, array $p): ToolResult
    {
        $g->post($this->itemPath($p) . '/invite', [
            'recipients' => [['email' => $p['email']]], 'requireSignIn' => true,
            'sendInvitation' => true, 'roles' => [in_array($p['role'] ?? 'read', ['read', 'write'], true) ? $p['role'] : 'read'],
        ]);
        return ToolResult::ok(['item_id' => $p['item_id'], 'recipient' => $p['email']], 'Invitation de partage envoyée.');
    }

    private function documentsCreateLink(MicrosoftGraphClient $g, array $p): ToolResult
    {
        $link = $g->post($this->itemPath($p) . '/createLink', [
            'type' => in_array($p['type'] ?? 'view', ['view', 'edit'], true) ? $p['type'] : 'view',
            'scope' => in_array($p['scope'] ?? 'organization', ['anonymous', 'organization', 'users'], true) ? $p['scope'] : 'organization',
        ]);
        return ToolResult::ok(['permission' => $link], 'Lien de partage Microsoft 365 créé.');
    }

    private function sharepointSite(MicrosoftGraphClient $g, array $p): ToolResult
    {
        $site = $g->get('/sites/' . rawurlencode($p['hostname']) . ':' . '/' . ltrim((string) $p['path'], '/'));
        return ToolResult::ok(['site' => $site], 'Site SharePoint récupéré.');
    }

    private function sharepointDrives(MicrosoftGraphClient $g, array $p): ToolResult
    {
        $drives = $g->collectPages('/sites/' . $this->id($p['site_id']) . '/drives');
        return ToolResult::ok(['drives' => $drives], count($drives) . ' bibliothèque(s) trouvée(s)');
    }
}
