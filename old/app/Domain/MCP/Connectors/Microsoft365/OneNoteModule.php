<?php

namespace App\Domain\MCP\Connectors\Microsoft365;

use App\Domain\MCP\Contracts\ToolResult;
use App\Domain\MCP\Contracts\ToolSchema;
use App\Domain\MCP\Exceptions\ToolNotFoundException;
use App\Domain\Microsoft365\MicrosoftGraphClient;

final class OneNoteModule extends AbstractMicrosoft365Module
{
    public function key(): string { return 'onenote'; }

    public function label(): string { return 'OneNote'; }

    public function iconUrl(): ?string { return 'https://upload.wikimedia.org/wikipedia/commons/3/34/Microsoft_OneNote_Icon_%282025_-_present%29.svg'; }

    /** @return ToolSchema[] */
    public function listTools(): array
    {
        return [
            $this->readTool('onenote_list_notebooks', 'Liste les blocs-notes OneNote de l’utilisateur connecté.', [], [], 'onenote.read'),
            $this->readTool('onenote_list_sections', 'Liste les sections d’un bloc-notes OneNote.', ['notebook_id' => ['type' => 'string']], [], 'onenote.read'),
            $this->readTool('onenote_list_pages', 'Liste les pages d’une section OneNote.', ['section_id' => ['type' => 'string'], 'top' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]], ['section_id'], 'onenote.read'),
            $this->readTool('onenote_get_page', 'Récupère les métadonnées d’une page OneNote.', ['page_id' => ['type' => 'string']], ['page_id'], 'onenote.read'),
            $this->readTool('onenote_get_page_content', 'Récupère le contenu HTML d’une page OneNote.', ['page_id' => ['type' => 'string']], ['page_id'], 'onenote.read'),
            $this->writeTool('onenote_create_page', 'Crée une page OneNote à partir de contenu HTML après confirmation.', ['section_id' => ['type' => 'string'], 'title' => ['type' => 'string'], 'content_html' => ['type' => 'string']], ['section_id', 'title', 'content_html'], 'onenote.create_page', 'confirm'),
        ];
    }

    /** @return array<string, list<string>> */
    protected function requiredScopes(): array
    {
        return [
            'onenote_list_notebooks' => ['Notes.Read'], 'onenote_list_sections' => ['Notes.Read'], 'onenote_list_pages' => ['Notes.Read'],
            'onenote_get_page' => ['Notes.Read'], 'onenote_get_page_content' => ['Notes.Read'], 'onenote_create_page' => ['Notes.ReadWrite'],
        ];
    }

    public function callTool(string $toolName, array $params, array $credentials, array $context, MicrosoftGraphClient $graph): ToolResult
    {
        return match ($toolName) {
            'onenote_list_notebooks' => $this->listNotebooks($graph),
            'onenote_list_sections' => $this->listSections($graph, $params),
            'onenote_list_pages' => $this->listPages($graph, $params),
            'onenote_get_page' => $this->getPage($graph, $params),
            'onenote_get_page_content' => $this->getPageContent($graph, $params),
            'onenote_create_page' => $this->createPage($graph, $params),
            default => throw new ToolNotFoundException("Outil '{$toolName}' inconnu pour le module OneNote Microsoft 365."),
        };
    }

    private function listNotebooks(MicrosoftGraphClient $g): ToolResult
    {
        $notebooks = $g->collectPages('/me/onenote/notebooks', ['$select' => 'id,displayName,isDefault,createdDateTime,lastModifiedDateTime,links']);
        return ToolResult::ok(['notebooks' => $notebooks], count($notebooks) . ' bloc-notes OneNote récupéré(s)');
    }

    private function listSections(MicrosoftGraphClient $g, array $p): ToolResult
    {
        $path = !empty($p['notebook_id'])
            ? '/me/onenote/notebooks/' . $this->id($p['notebook_id']) . '/sections'
            : '/me/onenote/sections';
        $sections = $g->collectPages($path, ['$select' => 'id,displayName,isDefault,createdDateTime,lastModifiedDateTime']);
        return ToolResult::ok(['sections' => $sections], count($sections) . ' section(s) OneNote récupérée(s)');
    }

    private function listPages(MicrosoftGraphClient $g, array $p): ToolResult
    {
        $pages = $g->collectPages('/me/onenote/sections/' . $this->id($p['section_id']) . '/pages', [
            '$top' => min(100, max(1, (int) ($p['top'] ?? 50))),
            '$select' => 'id,title,createdDateTime,lastModifiedDateTime,links',
        ]);
        return ToolResult::ok(['pages' => $pages], count($pages) . ' page(s) OneNote récupérée(s)');
    }

    private function getPage(MicrosoftGraphClient $g, array $p): ToolResult
    {
        $page = $g->get('/me/onenote/pages/' . $this->id($p['page_id']), ['$select' => 'id,title,createdDateTime,lastModifiedDateTime,links,parentNotebook,parentSection']);
        return ToolResult::ok(['page' => $page], 'Page OneNote récupérée.');
    }

    private function getPageContent(MicrosoftGraphClient $g, array $p): ToolResult
    {
        $content = $g->download('/me/onenote/pages/' . $this->id($p['page_id']) . '/content');
        if (strlen($content) > 768 * 1024) {
            return ToolResult::fail('too_large', 'Le contenu de cette page OneNote est trop volumineux pour être renvoyé dans la conversation.');
        }

        return ToolResult::ok(['page_id' => $p['page_id'], 'content_html' => $content], 'Contenu HTML de la page OneNote récupéré.');
    }

    private function createPage(MicrosoftGraphClient $g, array $p): ToolResult
    {
        $title = htmlspecialchars((string) $p['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<!DOCTYPE html><html><head><title>' . $title . '</title></head><body>' . (string) $p['content_html'] . '</body></html>';
        $page = $g->postContent('/me/onenote/sections/' . $this->id($p['section_id']) . '/pages', $html);
        return ToolResult::ok(['page' => $page], 'Page OneNote créée.');
    }
}
