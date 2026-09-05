<?php

namespace App\Domain\RAG;

use App\Domain\MCP\Contracts\ToolResult;
use App\Domain\MCP\Contracts\ToolSchema;
use App\Models\Site;
use App\Domain\MCP\Security\ActorContext;
use App\Services\chunks\ChunkHydrationService;
use App\Services\hybrid\HybridSearchService;
use App\Services\ia\EmbeddingService;

/**
 * Recherche documentaire LÉGÈRE exposée comme "tool" au LLM pendant la
 * boucle MCP (App\Services\mcp\MCPActionGateService). Ce n'est PAS un
 * remplacement de SingleHopPipelineService / MultiHopPipelineServiceV2 :
 * pas de rerank LLM, pas de context selection, pas de validation
 * anti-hallucination. Son seul rôle est de donner à l'agent MCP de quoi
 * répondre à une question factuelle simple ("quelle est votre politique de
 * retour ?") qui survient AU MILIEU d'une chaîne d'actions (ex: avant
 * d'annuler une commande), sans devoir sortir de la boucle. Pour toute
 * question purement informationnelle, c'est votre pipeline RAG existant qui
 * répond — cet adaptateur n'entre en jeu que si le LLM choisit d'appeler un
 * outil MCP en premier lieu (voir MCPActionGateService::tryHandle).
 */
class RAGToolAdapter
{
    public const CONNECTOR_SLUG = 'knowledge_base';

    public function __construct(
        private readonly EmbeddingService $embeddingService,
        private readonly HybridSearchService $hybridSearchService,
        private readonly ChunkHydrationService $chunkHydrationService,
    ) {
    }

    public function schema(): ToolSchema
    {
        return new ToolSchema(
            connectorSlug: self::CONNECTOR_SLUG,
            name: 'search',
            description: "Recherche rapide dans la base de connaissances (FAQ, politiques, catalogue) pour une information ponctuelle nécessaire à la réalisation d'une action en cours (ex: vérifier une condition avant d'exécuter une action). Pour une question purement informationnelle qui ne déclenche aucune action, ne PAS utiliser cet outil : laisse la conversation être traitée normalement.",
            parameters: [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => 'Requête de recherche'],
                ],
                'required' => ['query'],
            ],
            isWriteAction: false,
        );
    }

    public function search(Site $site, string $query, int $limit = 8, ?ActorContext $actor = null): ToolResult
    {
        try {
            $embedding = $this->embeddingService->getEmbedding($query);

            $results = $this->hybridSearchService->search(
                query: $query,
                embedding: $embedding,
                siteId: $site->id,
                limit: $limit,
                scoreThreshold: floatval($site->settings->min_similarity_score ?? 0),
            );

            $hydrated = $this->chunkHydrationService->hydrate($results, $actor);
        } catch (\Throwable $e) {
            return ToolResult::fail('rag_unavailable', 'Recherche documentaire indisponible: ' . $e->getMessage());
        }

        if (empty($hydrated)) {
            return ToolResult::fail('not_found', "Aucun résultat pertinent trouvé pour « {$query} ».");
        }

        $snippets = collect($hydrated)
            ->take(5)
            ->map(fn ($c) => ['text' => $c['text'] ?? '', 'source_type' => $c['source_type'] ?? null])
            ->all();

        return ToolResult::ok(['results' => $snippets], count($snippets) . ' extrait(s) trouvé(s)');
    }
}
