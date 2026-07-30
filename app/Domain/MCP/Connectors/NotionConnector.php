<?php

namespace App\Domain\MCP\Connectors;

use App\Domain\MCP\Contracts\ToolResult;
use App\Domain\MCP\Contracts\ToolSchema;
use App\Domain\MCP\Exceptions\AuthExpiredException;
use App\Domain\MCP\Exceptions\ConnectorUnavailableException;
use App\Domain\MCP\Exceptions\ToolNotFoundException;
use Illuminate\Http\Client\RequestException;

/** credentials attendus : { "access_token": "secret_...", "database_id": "..." (optionnel, pour create_page) } */
class NotionConnector extends AbstractConnector
{
    private const VERSION = '2022-06-28';

    public function slug(): string { return 'notion'; }

    public function authenticate(array $credentials): array
    {
        if (empty($credentials['access_token'])) {
            throw new AuthExpiredException("Jeton d'intégration Notion manquant.");
        }
        return $credentials;
    }

    public function listTools(): array
    {
        return [
            new ToolSchema('notion', 'create_page', "Crée une page dans la base de connaissances Notion (ex: compte-rendu d'un échange visiteur).", [
                'type' => 'object', 'properties' => ['title' => ['type' => 'string'], 'content' => ['type' => 'string']], 'required' => ['title'],
            ], isWriteAction: true, defaultMode: 'auto', capability: 'documentation.create_page'),

            new ToolSchema('notion', 'search_pages', "Recherche des pages par mot-clé.", [
                'type' => 'object', 'properties' => ['query' => ['type' => 'string']], 'required' => ['query'],
            ], defaultActorScope: 'admin', defaultMode: 'auto', capability: 'documentation.search'),

            new ToolSchema('notion', 'get_page', "Contenu d'une page.", [
                'type' => 'object', 'properties' => ['page_id' => ['type' => 'string']], 'required' => ['page_id'],
            ], defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('notion', 'update_page', "Modifie le titre d'une page existante.", [
                'type' => 'object', 'properties' => ['page_id' => ['type' => 'string'], 'title' => ['type' => 'string']], 'required' => ['page_id', 'title'],
            ], isWriteAction: true, defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('notion', 'append_to_page', "Ajoute du contenu à la fin d'une page existante.", [
                'type' => 'object', 'properties' => ['page_id' => ['type' => 'string'], 'content' => ['type' => 'string']], 'required' => ['page_id', 'content'],
            ], isWriteAction: true, defaultActorScope: 'admin', defaultMode: 'auto'),
        ];
    }

    public function callTool(string $toolName, array $params, array $credentials, array $context = []): ToolResult
    {
        return match ($toolName) {
            'create_page' => $this->createPage($params, $credentials),
            'search_pages' => $this->searchPages($params, $credentials),
            'get_page' => $this->getPage($params, $credentials),
            'update_page' => $this->updatePage($params, $credentials),
            'append_to_page' => $this->appendToPage($params, $credentials),
            default => throw new ToolNotFoundException("Outil '{$toolName}' inconnu pour notion."),
        };
    }

    private function createPage(array $p, array $c): ToolResult
    {
        if (empty($c['database_id'])) return ToolResult::fail('not_configured', 'Aucune base Notion par défaut configurée pour ce site.');

        try {
            $page = $this->client($c)->post('pages', [
                'parent' => ['database_id' => $c['database_id']],
                'properties' => ['title' => ['title' => [['text' => ['content' => $p['title']]]]]],
                'children' => !empty($p['content']) ? [$this->paragraphBlock($p['content'])] : [],
            ])->json();
        } catch (RequestException $e) {
            throw new ConnectorUnavailableException('Notion indisponible: ' . $e->getMessage());
        }
        $this->recordSuccess();
        return ToolResult::ok(['page_id' => $page['id'], 'url' => $page['url']], "Page « {$p['title']} » créée.");
    }

    private function searchPages(array $p, array $c): ToolResult
    {
        try {
            $res = $this->client($c)->post('search', ['query' => $p['query'], 'filter' => ['property' => 'object', 'value' => 'page']]);
        } catch (RequestException $e) {
            throw new ConnectorUnavailableException('Notion indisponible: ' . $e->getMessage());
        }
        $this->recordSuccess();
        $pages = collect($res->json('results', []))->map(fn ($pg) => [
            'page_id' => $pg['id'], 'url' => $pg['url'],
            'title' => collect($pg['properties'] ?? [])->first(fn ($prop) => $prop['type'] === 'title')['title'][0]['plain_text'] ?? '(sans titre)',
        ])->all();
        if (empty($pages)) return ToolResult::fail('not_found', 'Aucune page trouvée.');
        return ToolResult::ok(['pages' => $pages], count($pages) . ' page(s) trouvée(s)');
    }

    private function getPage(array $p, array $c): ToolResult
    {
        try {
            $page = $this->client($c)->get("pages/{$p['page_id']}")->json();
            $blocks = $this->client($c)->get("blocks/{$p['page_id']}/children")->json('results', []);
        } catch (RequestException $e) {
            if ($e->response?->status() === 404) return ToolResult::fail('not_found', 'Page introuvable.');
            throw new ConnectorUnavailableException('Notion indisponible: ' . $e->getMessage());
        }
        $this->recordSuccess();
        $text = collect($blocks)->map(fn ($b) => collect($b['paragraph']['rich_text'] ?? [])->pluck('plain_text')->implode(''))->filter()->implode("\n");
        return ToolResult::ok(['page_id' => $page['id'], 'url' => $page['url'], 'content' => $text], 'Page récupérée.');
    }

    private function updatePage(array $p, array $c): ToolResult
    {
        try {
            $this->client($c)->patch("pages/{$p['page_id']}", [
                'properties' => ['title' => ['title' => [['text' => ['content' => $p['title']]]]]],
            ]);
        } catch (RequestException $e) {
            if ($e->response?->status() === 404) return ToolResult::fail('not_found', 'Page introuvable.');
            throw new ConnectorUnavailableException('Notion indisponible: ' . $e->getMessage());
        }
        $this->recordSuccess();
        return ToolResult::ok(['page_id' => $p['page_id']], 'Titre de la page mis à jour.');
    }

    private function appendToPage(array $p, array $c): ToolResult
    {
        try {
            $this->client($c)->patch("blocks/{$p['page_id']}/children", ['children' => [$this->paragraphBlock($p['content'])]]);
        } catch (RequestException $e) {
            if ($e->response?->status() === 404) return ToolResult::fail('not_found', 'Page introuvable.');
            throw new ConnectorUnavailableException('Notion indisponible: ' . $e->getMessage());
        }
        $this->recordSuccess();
        return ToolResult::ok(['page_id' => $p['page_id']], 'Contenu ajouté à la page.');
    }

    private function paragraphBlock(string $content): array
    {
        return ['object' => 'block', 'type' => 'paragraph', 'paragraph' => ['rich_text' => [['type' => 'text', 'text' => ['content' => $content]]]]];
    }

    private function client(array $c)
    {
        return $this->http('https://api.notion.com/v1/')->withToken($c['access_token'])->withHeaders(['Notion-Version' => self::VERSION]);
    }
}
