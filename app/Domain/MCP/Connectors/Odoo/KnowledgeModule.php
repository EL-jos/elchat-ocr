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
            new ToolSchema('odoo', 'knowledge_search_articles',
                "Recherche les articles de la base de connaissances correspondant à un mot-clé, un titre ou une description fournis par l'utilisateur. Utiliser lorsqu'un article doit être identifié avant sa consultation ou lorsqu'un utilisateur recherche une information documentée. Si plusieurs articles correspondent, demander une clarification avant de poursuivre. Ne jamais inventer un article ni supposer qu'un résultat est unique.", [
                'type' => 'object', 'properties' => ['query' => ['type' => 'string']], 'required' => ['query'],
            ], defaultActorScope: 'admin', defaultMode: 'auto', capability: 'documentation.search'),

            new ToolSchema('odoo', 'knowledge_get_article',
                "Récupère le contenu complet d'un article identifié de manière unique. Utiliser uniquement lorsque l'identifiant de l'article est connu ou après une recherche ayant permis d'identifier un seul article. Les informations retournées constituent la source de vérité. Ne jamais inventer, compléter, interpréter ou modifier le contenu de l'article. Si une information n'est pas présente dans l'article, l'indiquer explicitement plutôt que de la déduire.", [
                'type' => 'object', 'properties' => ['article_id' => ['type' => 'integer']], 'required' => ['article_id'],
            ], defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('odoo', 'knowledge_create_article',
                "Crée un nouvel article dans la base de connaissances. Utiliser uniquement lorsque l'utilisateur demande explicitement de créer une nouvelle documentation. Vérifier que le titre est connu et que le contenu est suffisamment défini avant la création. Ne pas utiliser pour modifier un article existant. Si un article similaire existe déjà ou si la demande est ambiguë, rechercher les articles existants ou demander une clarification avant de créer un nouvel article. Ne jamais créer volontairement des doublons.", [
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
