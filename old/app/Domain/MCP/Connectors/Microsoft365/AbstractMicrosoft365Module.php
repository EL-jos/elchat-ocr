<?php

namespace App\Domain\MCP\Connectors\Microsoft365;

use App\Domain\MCP\Contracts\ToolSchema;
use App\Domain\Microsoft365\MicrosoftGraphClient;

abstract class AbstractMicrosoft365Module implements Microsoft365ModuleInterface
{
    public function supportsTools(): bool { return true; }

    public function availabilityMessage(): ?string { return null; }

    /** @return array<string, list<string>> */
    abstract protected function requiredScopes(): array;

    /** @return ToolSchema[] */
    public function toolsAvailableFor(array $credentials): array
    {
        // Microsoft Entra est la source de vérité : le flux Microsoft 365
        // demande /.default, donc toutes les permissions statiques déclarées
        // sur l'application ELChat. La présence d'un scope dans le texte du
        // token ne doit plus griser un outil dans le back office : selon le
        // type de ressource et le consentement du tenant, Microsoft Graph
        // reste l'autorité finale et retournera 401/403 à l'exécution.
        return $this->listTools();
    }

    protected function hasAllScopes(array $required, array $granted): bool
    {
        foreach ($required as $scope) {
            if (in_array($scope, $granted, true)) {
                continue;
            }

            $satisfied = match ($scope) {
                'Files.Read' => in_array('Files.ReadWrite', $granted, true),
                'Sites.Read.All' => in_array('Sites.ReadWrite.All', $granted, true),
                'Mail.ReadBasic' => in_array('Mail.Read', $granted, true) || in_array('Mail.ReadWrite', $granted, true),
                'Mail.Read' => in_array('Mail.ReadWrite', $granted, true),
                'Tasks.Read' => in_array('Tasks.ReadWrite', $granted, true),
                'Notes.Read' => in_array('Notes.ReadWrite', $granted, true),
                default => false,
            };

            if (!$satisfied) {
                return false;
            }
        }

        return true;
    }

    protected function readTool(string $name, string $description, array $properties, array $required, string $capability): ToolSchema
    {
        return new ToolSchema(
            'microsoft_365', $name, $description,
            ['type' => 'object', 'properties' => $properties, 'required' => $required],
            defaultActorScope: 'admin', defaultMode: 'auto', capability: $capability,
        );
    }

    protected function writeTool(string $name, string $description, array $properties, array $required, string $capability, string $mode = 'confirm'): ToolSchema
    {
        return new ToolSchema(
            'microsoft_365', $name, $description,
            ['type' => 'object', 'properties' => $properties, 'required' => $required],
            isWriteAction: true, defaultActorScope: 'admin', defaultMode: $mode,
            defaultConfirmActor: $mode === 'confirm' ? 'admin' : null, capability: $capability,
        );
    }

    protected function drivePrefix(array $p): string
    {
        if (!empty($p['drive_id'])) return '/drives/' . $this->id($p['drive_id']);
        if (!empty($p['site_id'])) return '/sites/' . $this->id($p['site_id']) . '/drive';
        return '/me/drive';
    }

    protected function itemPath(array $p): string
    {
        return $this->drivePrefix($p) . '/items/' . $this->id($p['item_id'] ?? $p['file_id'] ?? '');
    }

    protected function workbookPath(array $p): string
    {
        return $this->drivePrefix($p) . '/items/' . $this->id($p['item_id']) . '/workbook';
    }

    protected function id(string $value): string
    {
        return rawurlencode(trim($value));
    }

    protected function odata(string $value): string
    {
        return str_replace("'", "''", trim($value));
    }

    protected function binaryContent(array $p): ?string
    {
        if (isset($p['content_base64']) && $p['content_base64'] !== '') {
            $decoded = base64_decode((string) $p['content_base64'], true);
            return $decoded === false ? null : $decoded;
        }

        return isset($p['content']) ? (string) $p['content'] : null;
    }

    /**
     * Uploads small files directly and large files through a Graph upload
     * session. The same implementation is reused by Files, Word and PowerPoint.
     */
    protected function uploadBytes(MicrosoftGraphClient $graph, array $p, string $name, string $content, string $mimeType): array
    {
        if (strlen($content) > 512 * 1024 * 1024) {
            throw new \InvalidArgumentException('Les fichiers de plus de 512 Mo ne peuvent pas être envoyés par cet outil.');
        }

        $parent = !empty($p['parent_item_id']) ? 'items/' . $this->id($p['parent_item_id']) : 'root';
        $path = $this->drivePrefix($p) . '/' . $parent . ':/' . rawurlencode($name) . ':/content';
        if (strlen($content) <= 4 * 1024 * 1024) {
            return $graph->putContent($path, $content, $mimeType);
        }

        $session = $graph->post(str_replace(':/content', ':/createUploadSession', $path), [
            'item' => ['@microsoft.graph.conflictBehavior' => 'replace', 'name' => $name],
        ]);
        if (empty($session['uploadUrl'])) {
            throw new \RuntimeException('Microsoft 365 n’a pas fourni de session d’upload.');
        }

        return $graph->uploadLarge((string) $session['uploadUrl'], $content, $mimeType);
    }

    /** @return array<string, mixed> */
    protected function fileSummary(array $file): array
    {
        return array_filter([
            'id' => $file['id'] ?? null,
            'name' => $file['name'] ?? null,
            'size' => $file['size'] ?? null,
            'webUrl' => $file['webUrl'] ?? null,
            'lastModifiedDateTime' => $file['lastModifiedDateTime'] ?? null,
            'createdDateTime' => $file['createdDateTime'] ?? null,
            'eTag' => $file['eTag'] ?? null,
            'is_folder' => isset($file['folder']),
            'mime_type' => $file['file']['mimeType'] ?? null,
            'parent_reference' => $file['parentReference'] ?? null,
        ], static fn ($value) => $value !== null);
    }

    /** @return array<string, string> */
    protected function excelHeaders(array $p): array
    {
        return !empty($p['session_id']) ? ['workbook-session-id' => (string) $p['session_id']] : [];
    }

    protected function safeFilename(string $name, string $extension): string
    {
        $name = basename(trim($name));
        $name = preg_replace('/[^\pL\pN._ -]+/u', '-', $name) ?: 'document';
        if (!str_ends_with(strtolower($name), strtolower($extension))) {
            $name .= $extension;
        }

        return $name;
    }
}
