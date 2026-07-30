<?php

namespace App\Domain\MCP\Connectors\Odoo;

use App\Domain\MCP\Contracts\{ToolResult, ToolSchema};
use App\Domain\MCP\Exceptions\ToolNotFoundException;

class KnowledgeModule implements OdooModuleInterface
{
    public function technicalModuleName(): string { return 'knowledge'; }

    public function listTools(): array
    {
        return [
            new ToolSchema('odoo', 'knowledge_search_articles', "Recherche des articles de la base de connaissances.", [
                'type' => 'object', 'properties' => ['query' => ['type' => 'string']], 'required' => ['query'],
            ], defaultActorScope: 'admin', defaultMode: 'auto', capability: 'documentation.search'),

            new ToolSchema('odoo', 'knowledge_get_article', "Contenu d'un article.", [
                'type' => 'object', 'properties' => ['article_id' => ['type' => 'integer']], 'required' => ['article_id'],
            ], defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('odoo', 'knowledge_create_article', "Crée un article.", [
                'type' => 'object', 'properties' => ['title' => ['type' => 'string'], 'content' => ['type' => 'string']], 'required' => ['title'],
            ], isWriteAction: true, defaultActorScope: 'admin', defaultMode: 'auto', capability: 'documentation.create_page'),
        ];
    }

    public function callTool(string $toolName, array $params, array $credentials, array $context, OdooClient $client): ToolResult
    {
        return match ($toolName) {
            'knowledge_search_articles' => $this->searchArticles($params, $client),
            'knowledge_get_article' => $this->getArticle($params, $client),
            'knowledge_create_article' => $this->createArticle($params, $client),
            default => throw new ToolNotFoundException("Outil '{$toolName}' inconnu pour le module Knowledge Odoo."),
        };
    }

    private function searchArticles(array $p, OdooClient $client): ToolResult
    {
        $rows = $client->searchRead('knowledge.article', [['name', 'ilike', $p['query']]], ['name'], 10);
        if (empty($rows)) return ToolResult::fail('not_found', 'Aucun article trouvé.');
        return ToolResult::ok(['articles' => $rows], count($rows) . ' article(s) trouvé(s)');
    }

    private function getArticle(array $p, OdooClient $client): ToolResult
    {
        $article = $client->read('knowledge.article', (int) $p['article_id'], ['name', 'body']);
        if (!$article) return ToolResult::fail('not_found', 'Article introuvable.');
        return ToolResult::ok(['name' => $article['name'], 'content' => strip_tags($article['body'] ?? '')], 'Article récupéré.');
    }

    private function createArticle(array $p, OdooClient $client): ToolResult
    {
        $id = $client->create('knowledge.article', ['name' => $p['title'], 'body' => $p['content'] ?? '']);
        return ToolResult::ok(['article_id' => $id], "Article « {$p['title']} » créé.");
    }
}
