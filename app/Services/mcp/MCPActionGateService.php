<?php

namespace App\Services\mcp;

use App\Domain\MCP\Audit\AuditLogger;
use App\Domain\MCP\Contracts\ToolResult;
use App\Domain\MCP\Contracts\ToolSchema;
use App\Domain\MCP\Exceptions\ConfirmationRequiredException;
use App\Domain\MCP\Exceptions\ConnectorUnavailableException;
use App\Domain\MCP\Exceptions\MCPException;
use App\Domain\MCP\Exceptions\PermissionDeniedException;
use App\Domain\MCP\Orchestration\MCPGateResult;
use App\Domain\MCP\Orchestration\OpenRouterToolClient;
use App\Domain\MCP\Registry\ConnectorRegistry;
use App\Domain\MCP\Security\CredentialVault;
use App\Domain\MCP\Security\PermissionEngine;
use App\Domain\RAG\RAGToolAdapter;
use App\Models\Conversation;
use App\Models\Site;
use App\Services\cta\ChatResponse;
use App\Services\MercureService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Passerelle MCP : s'exécute EN AMONT du pipeline RAG existant
 * (SingleHopPipelineService / MultiHopPipelineServiceV2), qu'elle ne
 * modifie ni ne remplace.
 *
 * Principe : un seul appel LLM (function-calling natif OpenRouter) avec les
 * outils MCP actifs du site + l'outil de recherche documentaire légère. Si
 * le modèle n'appelle AUCUN outil, la demande n'est pas une action → on
 * retourne 'not_applicable' et ChatService::answer() poursuit exactement
 * comme aujourd'hui. Si le modèle appelle un ou plusieurs outils, on entre
 * dans une boucle multi-hop dédiée (jusqu'à self::MAX_HOPS), avec
 * vérification de permission à CHAQUE hop.
 *
 * Coût maîtrisé : si le site n'a aucun connecteur MCP actif, retourne
 * immédiatement sans le moindre appel LLM (voir tryHandle, garde initiale).
 */
class MCPActionGateService
{
    private const MAX_HOPS = 4;

    public function __construct(
        private readonly ConnectorRegistry $registry,
        private readonly PermissionEngine $permissions,
        private readonly CredentialVault $vault,
        private readonly AuditLogger $audit,
        private readonly RAGToolAdapter $ragTool,
        private readonly OpenRouterToolClient $llm,
        private readonly MercureService $mercureService,
    ) {
    }

    public function tryHandle(Site $site, Conversation $conversation, string $question, array $history): MCPGateResult
    {
        $mcpTools = $this->permissions->filterAllowedTools($site, $this->connectorToolSchemas($site));

        // Garde de coût : aucun outil autorisé pour ce site => on ne fait
        // même pas d'appel LLM, la conversation part directement en RAG.
        if (empty($mcpTools)) {
            return MCPGateResult::notApplicable();
        }

        $tools = array_map(
            fn (ToolSchema $t) => $t->toOpenAIFormat(),
            [...$mcpTools, $this->ragTool->schema()]
        );

        $messages = [
            ['role' => 'system', 'content' => $this->systemPrompt($site)],
            ...$this->historyToOpenAIFormat($history),
            ['role' => 'user', 'content' => $question],
        ];

        return $this->runLoop($site, $conversation, $messages, $tools, hop: 1, trace: []);
    }

    /**
     * Reprend après confirmation humaine d'une action en attente.
     */
    public function resumeAfterConfirmation(
        Site $site,
        Conversation $conversation,
        array $pendingMessages,
        string $connectorSlug,
        string $toolName,
        array $params,
        string $toolCallId,
        bool $approved,
    ): MCPGateResult {
        $messages = $pendingMessages;

        if (!$approved) {
            $messages[] = [
                'role' => 'tool',
                'tool_call_id' => $toolCallId,
                'content' => json_encode(['status' => 'declined_by_user']),
            ];
        } else {
            $result = $this->executeAuthorized($site, $connectorSlug, $toolName, $params, $conversation, hop: 0, forced: true);
            $messages[] = [
                'role' => 'tool',
                'tool_call_id' => $toolCallId,
                'content' => json_encode($result->toArrayForLLM()),
            ];
        }

        $mcpTools = $this->permissions->filterAllowedTools($site, $this->connectorToolSchemas($site));
        $tools = array_map(fn (ToolSchema $t) => $t->toOpenAIFormat(), [...$mcpTools, $this->ragTool->schema()]);

        return $this->runLoop($site, $conversation, $messages, $tools, hop: self::MAX_HOPS, trace: []); // dernier tour : on force la synthèse
    }

    private function runLoop(Site $site, Conversation $conversation, array $messages, array $tools, int $hop, array $trace): MCPGateResult
    {
        while ($hop <= self::MAX_HOPS) {
            if ($hop === 1) {
                $this->notifyThinking($site, $conversation, 'Vérification des actions possibles...');
            }

            $llmResponse = $this->llm->send($messages, $tools);

            if (empty($llmResponse['tool_calls'])) {
                if ($hop === 1) {
                    // Aucun outil demandé dès le premier tour => pas une action.
                    return MCPGateResult::notApplicable();
                }

                // Le modèle a des résultats d'outils et formule la réponse finale.
                return MCPGateResult::finished(
                    new ChatResponse(message: trim((string) $llmResponse['text']), ctas: [], entities: []),
                    $trace,
                );
            }

            $messages[] = $llmResponse['raw_message'];

            foreach ($llmResponse['tool_calls'] as $toolCall) {
                $qualifiedName = $toolCall['function']['name'];
                $args = json_decode($toolCall['function']['arguments'] ?? '{}', true) ?: [];
                $toolCallId = $toolCall['id'];

                [$connectorSlug, $toolName] = array_values(ToolSchema::fromQualifiedName($qualifiedName));

                if ($hop === 1) {
                    $this->notifyThinking($site, $conversation, $this->thinkingLabelFor($connectorSlug, $toolName));
                }

                $execution = $this->executeHop($site, $connectorSlug, $toolName, $args, $conversation, $hop);
                $trace[] = ['hop' => $hop, 'connector' => $connectorSlug, 'tool' => $toolName, 'status' => $execution['status']];

                if ($execution['status'] === 'awaiting_confirmation') {
                    return MCPGateResult::awaitingConfirmation(
                        connectorSlug: $connectorSlug,
                        toolName: $toolName,
                        params: $args,
                        toolCallId: $toolCallId,
                        messages: $messages,
                        trace: $trace,
                    );
                }

                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $toolCallId,
                    'content' => json_encode($execution['result']->toArrayForLLM()),
                ];
            }

            $hop++;
        }

        Log::warning("MCP: nombre maximum de hops atteint pour le site {$site->id}");

        return MCPGateResult::finished(
            new ChatResponse(
                message: "Je n'ai pas pu finaliser cette action automatiquement. Un conseiller va prendre le relais.",
                ctas: [],
                entities: [],
            ),
            $trace,
        );
    }

    private function executeHop(Site $site, string $connectorSlug, string $toolName, array $params, Conversation $conversation, int $hop): array
    {
        try {
            $result = $this->executeAuthorized($site, $connectorSlug, $toolName, $params, $conversation, $hop);
            return ['status' => 'success', 'result' => $result];
        } catch (ConfirmationRequiredException) {
            $this->audit->log($site, $connectorSlug, $toolName, $params, 'confirm', 'awaiting_confirmation', conversationId: $conversation->id, hopNumber: $hop);
            return ['status' => 'awaiting_confirmation', 'result' => null];
        } catch (PermissionDeniedException $e) {
            $this->audit->log($site, $connectorSlug, $toolName, $params, 'deny', 'denied', errorCode: 'permission_denied', conversationId: $conversation->id, hopNumber: $hop);
            return ['status' => 'denied', 'result' => ToolResult::fail('permission_denied', $e->getMessage())];
        } catch (ConnectorUnavailableException) {
            $this->audit->log($site, $connectorSlug, $toolName, $params, 'auto', 'error', errorCode: 'connector_unavailable', conversationId: $conversation->id, hopNumber: $hop);
            return ['status' => 'error', 'result' => ToolResult::fail('connector_unavailable', "Le service {$connectorSlug} est momentanément indisponible.")];
        } catch (MCPException $e) {
            $this->audit->log($site, $connectorSlug, $toolName, $params, 'auto', 'error', errorCode: $e->errorCode(), conversationId: $conversation->id, hopNumber: $hop);
            return ['status' => 'error', 'result' => ToolResult::fail($e->errorCode(), $e->getMessage())];
        } catch (Throwable $e) {
            Log::error("MCP hop non géré: {$e->getMessage()}", ['connector' => $connectorSlug, 'tool' => $toolName]);
            $this->audit->log($site, $connectorSlug, $toolName, $params, 'auto', 'error', errorCode: 'unexpected', conversationId: $conversation->id, hopNumber: $hop);
            return ['status' => 'error', 'result' => ToolResult::fail('unexpected', 'Une erreur technique est survenue.')];
        }
    }

    private function executeAuthorized(Site $site, string $connectorSlug, string $toolName, array $params, Conversation $conversation, int $hop, bool $forced = false): ToolResult
    {
        if ($connectorSlug === RAGToolAdapter::CONNECTOR_SLUG) {
            $result = $this->ragTool->search($site, $params['query'] ?? '');
            $this->audit->log($site, $connectorSlug, $toolName, $params, 'auto', $result->success ? 'success' : 'error', $result, conversationId: $conversation->id, hopNumber: $hop);
            return $result;
        }

        if (!$forced) {
            $this->permissions->authorize($site, $connectorSlug, $toolName);
        }

        $credentials = $this->vault->retrieve($site, $connectorSlug);
        if (!$credentials) {
            return ToolResult::fail('not_connected', "Le connecteur {$connectorSlug} n'est pas configuré pour ce site.");
        }

        $connector = $this->registry->get($connectorSlug);

        try {
            $freshCredentials = $connector->authenticate($credentials);
            if ($freshCredentials !== $credentials) {
                $this->vault->refresh($site, $connectorSlug, $freshCredentials);
            }
        } catch (\App\Domain\MCP\Exceptions\AuthExpiredException $e) {
            $this->vault->markAuthExpired($site, $connectorSlug, $e->getMessage());
            throw $e;
        }

        $startedAt = microtime(true);
        $result = $connector->callTool($toolName, $params, $freshCredentials);
        $durationMs = (int) ((microtime(true) - $startedAt) * 1000);

        $this->audit->log($site, $connectorSlug, $toolName, $params, 'auto', $result->success ? 'success' : 'error', $result, $durationMs, conversationId: $conversation->id, hopNumber: $hop);

        return $result;
    }

    /**
     * @return ToolSchema[]
     */
    private function connectorToolSchemas(Site $site): array
    {
        $activeSlugs = $site->mcpSiteConnectors()
            ->where('status', 'connected')
            ->with('mcpConnector')
            ->get()
            ->pluck('mcpConnector.slug');

        $schemas = [];
        foreach ($activeSlugs as $slug) {
            if (!$this->registry->has($slug)) {
                continue;
            }
            array_push($schemas, ...$this->registry->get($slug)->listTools());
        }

        return $schemas;
    }

    private function historyToOpenAIFormat(array $history): array
    {
        // $history est déjà au format ['role' => 'user'|'assistant', 'content' => string]
        // (voir ChatService::answer, étape 4️⃣) — aucune conversion nécessaire,
        // on le passe tel quel.
        return $history;
    }

    private function systemPrompt(Site $site): string
    {
        $name = $site->name ?? parse_url($site->url ?? '', PHP_URL_HOST);

        return <<<PROMPT
Tu es le module de décision d'action de l'assistant du site {$name}. Tu disposes d'outils qui exécutent de
VRAIES actions (vérifier une commande, prendre un rendez-vous...). N'appelle un outil QUE si le message du
visiteur correspond clairement à une action que ces outils permettent. Si c'est une question d'information
générale (produit, politique, contenu du site) sans action à exécuter, NE CALL AUCUN outil et laisse la
phrase vide : un autre système s'en chargera. Si une information manque pour appeler un outil (ex: numéro de
commande), demande-la au visiteur au lieu d'inventer une valeur. Une fois les résultats d'outils obtenus,
réponds de façon claire et concise, sans détails techniques.
PROMPT;
    }

    private function thinkingLabelFor(string $connectorSlug, string $toolName): string
    {
        return match (true) {
            $connectorSlug === 'woocommerce' => 'Vérification de votre commande...',
            $connectorSlug === 'google_calendar' => 'Consultation du calendrier...',
            $connectorSlug === RAGToolAdapter::CONNECTOR_SLUG => 'Recherche d\'information...',
            default => 'Traitement en cours...',
        };
    }

    private function notifyThinking(Site $site, Conversation $conversation, string $label): void
    {
        $this->mercureService->post("/sites/{$site->id}/conversations/{$conversation->id}", [
            'type' => 'thinking_step',
            'conversation_id' => $conversation->id,
            'label' => $label,
            'created_at' => now()->toISOString(),
        ]);
    }
}
