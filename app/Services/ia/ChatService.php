<?php
namespace App\Services\ia;

use App\Enums\AnalyticsEventType;

use App\Contracts\ConversationEngineInterface;
use App\Models\Conversation;
use App\Models\ConversationMemory;
use App\Domain\MCP\Security\ActorContext;
use App\Models\Message;
use App\Models\Mcp\McpPendingAction;
use App\Models\Site;
use App\Models\WidgetSetting;
use App\Services\analytics\ResourceEventLogger;
use App\Services\analytics\AnalyticsEventService;
use App\Services\cta\ChatResponse;
use App\Services\hops\HopResponse;
use App\Services\hops\LLMService;
use App\Services\hops\MultiHopPipelineService;
use App\Services\hops\MultiHopPipelineServiceV2;
use App\Services\hops\SingleHopPipelineService;
use App\Services\mcp\MCPActionGateService;
use App\Services\mcp\UnifiedToolCallResult;
use App\Services\mcp\UnifiedToolCallService;
use App\Services\MercureService;
use App\Services\queryAnalyzer\IntentRouter;
use App\Services\queryAnalyzer\LeadService;
use App\Services\queryAnalyzer\NavigationService;
use App\Services\queryAnalyzer\QueryAnalyzer;
use App\Services\queryAnalyzer\TransactionService;
use App\Services\validator\AnswerValidatorService;
use App\Traits\TextNormalizer;
use Exception;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ChatService
{

    use TextNormalizer;

    private const COMMERCIAL_INTENTS = [
        'lead',
        'pricing',
        'booking',
        'transactional',
        'comparison',
    ];

    protected array $handlers = [

        'lead_capture' => LeadService::class,
        'navigation' => NavigationService::class,
        'transaction_flow' => TransactionService::class,
    ];

    private array $entityLabels = [
        'product' => [
            'singular' => 'produit',
            'plural' => 'produits',
            'priority' => 1,
        ],
        'page' => [
            'singular' => 'page',
            'plural' => 'pages',
            'priority' => 2,
        ],
        'document' => [
            'singular' => 'document',
            'plural' => 'documents',
            'priority' => 3,
        ],
        'image' => [
            'singular' => 'image',
            'plural' => 'images',
            'priority' => 4,
        ],
    ];
    protected HopResponse $results;

    public function __construct(

        protected IntentClassifier $intentClassifier,
        protected ConversationStateManager $conversationStateManager,
        protected ResponseGuard $responseGuard,

        protected QueryAnalyzer $queryAnalyzer,
        protected IntentRouter $intentRouter,

        protected MultiHopPipelineService $multiHopPipelineService,
        protected MultiHopPipelineServiceV2 $multiHopPipelineServiceV2,
        protected SingleHopPipelineService $singleHopPipelineService,

        protected AnswerValidatorService $answerValidatorService,
        protected ConversationRewriterService $conversationRewriterService,

        protected FollowUpDetector $followUpDetector,
        protected RetrievalQueryExpander $retrievalQueryExpander,
        protected LLMService $llm,

        protected MercureService $mercureService, // 🔹 ajouté

        protected ConversationEngineInterface $conversationEngine,
        // 🆕 MCP
        protected MCPActionGateService $mcpActionGateService,
        protected UnifiedToolCallService $unifiedToolCallService,
        private readonly ResourceEventLogger $resourceEventLogger, // 🆕
        private readonly AnalyticsEventService $analytics,
    )
    {}

    /**
     * Réponse commerciale incarnée (mode production)
     */
    public function answer(Site $site, string $question, Conversation $conversation): ChatResponse
    {

        // ─────────────────────────────
        // 0️⃣ Intent Classification
        // ─────────────────────────────
        $intent = $this->intentClassifier->classify($question);
        $earlyResponse = $this->conversationStateManager
            ->handle($intent, $conversation);

        if ($earlyResponse !== null) {
            return new ChatResponse(
                message: $earlyResponse,
                ctas: []
            );
        }

        $this->notifyThinking($site, $conversation, 'Analyse de votre demande...');

        // ─────────────────────────────
        // 1️⃣ Context Resolution (ONE TIME ONLY)
        // ─────────────────────────────
        $resolvedQuestion = $question;

        $followUp = $this->followUpDetector->isFollowUp(question: $question, conversation: $conversation);
        if($followUp){
            $resolvedQuestion = $this->conversationRewriterService->rewrite(question: $question, conversation: $conversation);
        }

        /*Log::info('Resolved Question', [
            'original' => $question,
            'resolved' => $resolvedQuestion,
            'follow_up' => $followUp,
        ]);*/

        // ─────────────────────────────
        // 2️⃣ Query Analysis
        // ─────────────────────────────
        $baseQueryPlan  = $this->queryAnalyzer->analyze(
            question: $resolvedQuestion,
            conversation: $conversation,
            rawQuestion: $question,   // 🆕 texte brut, avant réécriture
        );

        $memoryRefreshRequested = $this->trackIntent($site, $conversation, $baseQueryPlan->intent);

        // ─────────────────────────────
        // 3️⃣ Short History
        // ─────────────────────────────
        $history = Message::where('conversation_id', $conversation->id)
            ->orderBy('created_at', 'desc')
            //->skip(1)
            ->take(6)
            ->get()
            ->reverse()
            ->map(function ($m) {
                if ($m->role === 'bot') {
                    return [
                        'role' => 'assistant',
                        //'content' => '[Résumé interne: réponse déjà fournie, informations factuelles uniquement, sans nouveaux produits ni promesses]',
                        'content' => $m->content,
                    ];
                }

                $content = $m->content;

                // 🖼️ Si ce message avait une image jointe, on réinjecte sa
                // description dans l'historique : une question de suivi comme
                // "et la couleur ?" reste compréhensible par le LLM même sans
                // revoir l'image (le LLM principal n'a pas besoin d'être
                // multimodal, la description texte suffit).
                $attachment = $m->attachments->first();

                if ($attachment && $attachment->description) {
                    $content .= "\n[Image jointe précédemment par le visiteur : {$attachment->description}]";
                }

                return [
                    'role' => 'user',
                    'content' => $content,
                ];
            })
            ->toArray();

        // ─────────────────────────────
        // 4️⃣bis MCP : décision unifiée, y compris multi-agent
        // ─────────────────────────────
        // Le mode multi-agent unifié supprime le pré-appel du superviseur :
        // la génération finale reçoit directement le catalogue des outils
        // autorisés et peut répondre, appeler un outil ou en appeler plusieurs.
        // Le kill switch permet de restaurer l'ancien routage multi-agent sans
        // modifier le flux unifié pour zéro ou un agent.
        $mcpEnabled = (bool) config('mcp.enabled', true);
        $unifiedMcpEnabled = $mcpEnabled && (bool) config('mcp.unified_tool_calling', true);
        $unifiedMultiAgentEnabled = $unifiedMcpEnabled
            && (bool) config('mcp.unified_multi_agent_tool_calling', true);
        $multipleActiveAgents = $mcpEnabled
            && $this->mcpActionGateService->hasMultipleActiveAgents($site);
        $useUnifiedMcpFlow = $unifiedMcpEnabled
            && (! $multipleActiveAgents || $unifiedMultiAgentEnabled);
        $useLegacyMcpGate = $mcpEnabled && ! $useUnifiedMcpFlow;

        Log::info('MCP routing mode', [
            'multiple_active_agents' => $multipleActiveAgents,
            'unified_enabled' => $unifiedMcpEnabled,
            'unified_multi_agent_enabled' => $unifiedMultiAgentEnabled,
            'mode' => $useUnifiedMcpFlow ? 'unified' : ($useLegacyMcpGate ? 'legacy' : 'disabled'),
        ]);

        if ($useLegacyMcpGate) {
            $mcpResult = $this->mcpActionGateService->tryHandle(
                site: $site,
                conversation: $conversation,
                question: $question,
                history: $history,
                intent: $baseQueryPlan->intent,
            );

            if ($mcpResult->status === 'finished') {
                $mcpResult->response->memoryRefreshRequested = $memoryRefreshRequested;

                return $mcpResult->response;
            }

            if ($mcpResult->status === 'awaiting_confirmation') {
                return $this->chatResponseForPendingMcpAction(
                    $mcpResult->pendingAction,
                    $memoryRefreshRequested,
                );
            }
        }

        $directive = $this->conversationEngine->decide(
            plan: $baseQueryPlan,
            site: $site,
            conversation: $conversation,
            question: $question,        // 🆕 le texte BRUT tapé par l'utilisateur, PAS $resolvedQuestion
            history: $history,
        );

        Log::info('Conversation Directive', $directive->toLogArray());

        // ─────────────────────────────
        // Runtime State
        // ─────────────────────────────
        $bestScore = 0;

        $bestResponse = null;

        $bestValidation = null;

        $bestResults = null;

        $bestValidatedResponse = null;

        $previousHallucination = true;
        $multiHopNoticeSent = false;
        $unifiedActionFallbackAttempted = false;
        // La première tentative utilise uniquement la requête d'origine.
        // Les variantes sont ajoutées à la demande, après un échec de
        // validation de cette première tentative.
        $queries = [$resolvedQuestion];

        Log::info('Resolved Question', [
            'original' => $question,
            'resolved' => $resolvedQuestion,
            'follow_up' => $followUp,
            'baseQueryPlan' => $baseQueryPlan,
            'queries' => $queries

        ]);

        // ─────────────────────────────
        // 5️⃣ Retrieval Attempts
        // ─────────────────────────────

        for ($attemptIndex = 0; $attemptIndex < count($queries); $attemptIndex++) {
            $currentQuestion = $queries[$attemptIndex];

            // IMPORTANT:
            // only continue attempts if previous failed
            // due to hallucination / grounding issue
            if (
                $attemptIndex > 0
                && $previousHallucination === false
            ) {
                break;
            }

            Log::info('🧠 Retrieval Attempt', [
                'attempt' => $attemptIndex + 1,
                'question' => $currentQuestion,
            ]);

            // Re-analyze current retrieval variant
            $queryPlan = $attemptIndex === 0
                ? $baseQueryPlan
                : $this->queryAnalyzer->analyze(question: $currentQuestion, conversation: $conversation);

            if ($attemptIndex === 0) {
                $this->notifyThinking($site, $conversation, 'Recherche dans la base de connaissances...');
            }

            // ─────────────────────────────
            // 6️⃣ Retrieval
            // ─────────────────────────────

            $useMultiHop = $this->multiHopPipelineService->shouldUseMultiHop(queryPlan: $queryPlan);

            if ($useMultiHop && !$multiHopNoticeSent) {
                $this->notifyMultiHopStarted($site, $conversation);
                $multiHopNoticeSent = true;
            }

            $results = $useMultiHop
                ? $this->multiHopPipelineServiceV2->handle(
                    question: $currentQuestion,
                    plan: $queryPlan,
                    site: $site,
                    conversation: $conversation,
                    history: $history,
                    directive: $directive,
                    actor: ActorContext::fromConversation($conversation)
                )
                : $this->singleHopPipelineService->handle(
                    question: $currentQuestion,
                    queryPlan: $queryPlan,
                    site: $site,
                    conversation: $conversation,
                    history: $history,
                    directive: $directive,
                    actor: ActorContext::fromConversation($conversation)
                );

            if (is_null($results->prompt) || (!is_null($results->message))) {
                // Certains pipelines retournent directement un message quand
                // aucun contexte RAG n'a été trouvé. On laisse malgré tout un
                // unique passage unifié vérifier une action MCP explicite,
                // sans transformer les tentatives de recherche en appels
                // supplémentaires.
                if ($useUnifiedMcpFlow && $attemptIndex === 0 && ! $unifiedActionFallbackAttempted) {
                    $unifiedActionFallbackAttempted = true;
                    $unifiedResult = $this->unifiedToolCallService->respond(
                        site: $site,
                        conversation: $conversation,
                        prompt: null,
                        question: $question,
                        history: $history,
                        intent: $baseQueryPlan->intent,
                    );
                    $actionResponse = $this->chatResponseForUnifiedResult(
                        $unifiedResult,
                        $memoryRefreshRequested,
                    );

                    if (
                        $unifiedResult?->status === UnifiedToolCallResult::FAILED
                        && $multipleActiveAgents
                        && (bool) config('mcp.unified_multi_agent_legacy_fallback', true)
                    ) {
                        Log::warning('MCP unified multi-agent: repli legacy avant exécution d’outil', [
                            'site_id' => $site->id,
                            'conversation_id' => $conversation->id,
                        ]);

                        $legacyResult = $this->mcpActionGateService->tryHandle(
                            site: $site,
                            conversation: $conversation,
                            question: $question,
                            history: $history,
                            intent: $baseQueryPlan->intent,
                        );

                        if ($legacyResult->status === 'finished') {
                            $legacyResult->response->memoryRefreshRequested = $memoryRefreshRequested;

                            return $legacyResult->response;
                        }

                        if ($legacyResult->status === 'awaiting_confirmation') {
                            return $this->chatResponseForPendingMcpAction(
                                $legacyResult->pendingAction,
                                $memoryRefreshRequested,
                            );
                        }
                    }

                    if (
                        $actionResponse !== null
                        && $unifiedResult?->status !== UnifiedToolCallResult::TEXT
                    ) {
                        return $actionResponse;
                    }
                }

                continue;
            }

            if ($attemptIndex === 0) {
                $this->notifyThinking($site, $conversation, 'Synthèse de la réponse...');
            }

            // ─────────────────────────────
            // 7️⃣ Generation
            // ─────────────────────────────
            $unifiedResult = $useUnifiedMcpFlow
                ? $this->unifiedToolCallService->respond(
                    site: $site,
                    conversation: $conversation,
                    prompt: $results->prompt,
                    question: $currentQuestion,
                    history: $history,
                    intent: $queryPlan->intent,
                )
                : null;
            $unifiedActionResponse = $this->chatResponseForUnifiedResult(
                $unifiedResult,
                $memoryRefreshRequested,
            );

            if (
                $unifiedResult?->status === UnifiedToolCallResult::FAILED
                && $multipleActiveAgents
                && (bool) config('mcp.unified_multi_agent_legacy_fallback', true)
            ) {
                // Le repli historique est réservé aux erreurs survenues avant
                // toute exécution. FAILED_AFTER_TOOL est volontairement exclu
                // pour ne jamais rejouer une action déjà effectuée.
                Log::warning('MCP unified multi-agent: repli legacy avant exécution d’outil', [
                    'site_id' => $site->id,
                    'conversation_id' => $conversation->id,
                ]);

                $legacyResult = $this->mcpActionGateService->tryHandle(
                    site: $site,
                    conversation: $conversation,
                    question: $question,
                    history: $history,
                    intent: $queryPlan->intent,
                );

                if ($legacyResult->status === 'finished') {
                    $legacyResult->response->memoryRefreshRequested = $memoryRefreshRequested;

                    return $legacyResult->response;
                }

                if ($legacyResult->status === 'awaiting_confirmation') {
                    return $this->chatResponseForPendingMcpAction(
                        $legacyResult->pendingAction,
                        $memoryRefreshRequested,
                    );
                }
            }

            if ($unifiedActionResponse !== null) {
                // Une réponse texte sans tool_call continue dans le validateur
                // RAG. Une clarification, confirmation ou action exécutée est
                // déjà une réponse finale et quitte ce pipeline.
                if ($unifiedResult?->status !== UnifiedToolCallResult::TEXT) {
                    return $unifiedActionResponse;
                }

                $response = $unifiedActionResponse->message;
            } else {
                // Kill switch, absence d'outil autorisé, multi-agent ou
                // erreur avant exécution : retour au générateur texte existant.
                $response = $this->callLLM(
                    site: $site,
                    prompt: $results->prompt,
                    question: $resolvedQuestion
                );
            }

            // ─────────────────────────────
            // 8️⃣ Response Guard
            // ─────────────────────────────
            $validatedResponse = $this->responseGuard->validate(response: $response, conversation: $conversation);

            // ─────────────────────────────
            // 9️⃣ Validation
            // IMPORTANT:
            // validate FINAL guarded response
            // ─────────────────────────────
            $validation = $this->answerValidatorService->validate(
                question: $resolvedQuestion,
                answer: $response,
                context: $results->context
            );

            $score = $validation['final_score'] ?? 0;
            $hallucination = $validation['hallucination_risk'] ?? 1;
            $grounding = $validation['grounding'] ?? 0;
            $relevance = $validation['relevance'] ?? 0;

            Log::info('Validation Result', [
                'attempt' => $attemptIndex + 1,
                'score' => $score,
                'hallucination' => $hallucination,
                'grounding' => $grounding,
                'relevance' => $relevance,
                'response' => $response
            ]);

            // Track best candidate
            if ($score > $bestScore) {

                $bestScore = $score;

                $bestResponse = $response;

                $bestValidation = $validation;

                $bestResults = $results;

                $bestValidatedResponse = $validatedResponse;
            }

            // ─────────────────────────────
            // Determine retry condition
            // ─────────────────────────────
            $previousHallucination =
                $hallucination >= 0.3
                || $grounding < 0.4
                || $relevance < 0.5;

            /*if ($validation['grounding'] < 0.4) {
                // réponse plausible mais pas supportée
            }
            if ($validation['relevance'] < 0.5) {
                // hors sujet
            }
            if ($validation['hallucination_risk'] > 0.6) {
                Log::warning("⚠️ Hallucination détectée", $validation);
            }

            $hallucination = $validation['hallucination_risk'];

            if ($hallucination < 0.3 && $score >= $site->settings->min_similarity_score) {
                break;
            }*/

            // ─────────────────────────────
            // SUCCESS EXIT
            // ─────────────────────────────
            if (
                $hallucination < 0.3
                && $score >= $site->settings->min_similarity_score
            ) {

                Log::info('✅ Successful response selected', [
                    'attempt' => $attemptIndex + 1,
                ]);

                $bestResponse = $response;

                $bestValidation = $validation;

                $bestResults = $results;

                $bestValidatedResponse = $validatedResponse;

                break;
            }

            // Ne calculer les variantes qu'après l'échec de la première
            // validation. Le scoring et les critères ci-dessus restent
            // inchangés.
            if ($attemptIndex === 0 && $previousHallucination) {
                $queries = array_values(array_unique([
                    ...$queries,
                    ...$this->retrievalQueryExpander->expand(query: $resolvedQuestion),
                ]));

                Log::info('Retrieval query expansion triggered after first validation failure', [
                    'variants_count' => count($queries) - 1,
                ]);
            }

        }
        // ─────────────────────────────
        // 🔟 FINAL DECISION
        // ─────────────────────────────
        if ($bestScore >= $site->settings->min_similarity_score) {
            $bestGrounding = (float) ($bestValidation['grounding'] ?? 0);
            $bestHallucinationRisk = (float) ($bestValidation['hallucination_risk'] ?? 1);
            if ($bestGrounding < 0.4 || $bestHallucinationRisk >= 0.3) {
                $this->trackLowConfidenceAnswer(
                    $site,
                    $conversation,
                    $question,
                    $bestValidation,
                    'validator_threshold',
                );
            }

            // Use the response attached to the best-scoring candidate, not the
            // last attempted candidate when retrieval expansion ran.
            $validatedResponse = $bestValidatedResponse;

            if ($validatedResponse === "Cette information n’est pas disponible dans nos documents internes.") {
                // Ajoute un texte introductif pour contextualiser les entities

                if (!empty($bestResults->entities)) {
                    $fallbackMessage = $this->buildEntitiesFallbackMessage($bestResults->entities);

                    if ($fallbackMessage) {
                        $validatedResponse = $fallbackMessage;
                    }
                }

            }/* elseif (!empty($bestResults->entities)) {

                $validatedResponse .= "\n\n---\n\n **Ressources utiles :**";
            }*/

            return new ChatResponse(
                message: $validatedResponse,
                ctas: $bestResults->ctas,
                entities: $bestResults->entities,
                memoryRefreshRequested: $memoryRefreshRequested,
            );
        }

        // 🔥 fallback intelligent
        $this->trackLowConfidenceAnswer(
            $site,
            $conversation,
            $question,
            $bestValidation,
            'no_reliable_answer',
        );

        return new ChatResponse(
            message: "Je n’ai pas trouvé une réponse suffisamment fiable. Pouvez-vous préciser votre demande ?",
            ctas: [],
            entities: [],
            memoryRefreshRequested: $memoryRefreshRequested,
        );
    }

    private function trackLowConfidenceAnswer(
        Site $site,
        Conversation $conversation,
        string $question,
        ?array $validation,
        string $reason,
    ): void {
        $messageId = Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('role', 'user')
            ->latest('created_at')
            ->value('id');

        $this->analytics->capture(
            $site,
            AnalyticsEventType::LOW_CONFIDENCE_ANSWER,
            [
                'conversation_id' => $conversation->id,
                'message_id' => $messageId,
                'session_id' => $conversation->metadata['session_id'] ?? null,
                'correlation_id' => $conversation->metadata['session_id'] ?? $conversation->id,
                'source' => 'answer_validator',
                'channel' => $conversation->metadata['channel'] ?? 'widget',
            ],
            metadata: [
                'reason' => $reason,
                'final_score' => isset($validation['final_score']) ? (float) $validation['final_score'] : null,
                'grounding' => isset($validation['grounding']) ? (float) $validation['grounding'] : null,
                'hallucination_risk' => isset($validation['hallucination_risk']) ? (float) $validation['hallucination_risk'] : null,
                'relevance' => isset($validation['relevance']) ? (float) $validation['relevance'] : null,
            ],
            idempotencyKey: $this->analytics->deterministicKey(
                'low_confidence_answer',
                $site->id,
                $conversation->id,
                $messageId ?: hash('sha256', $question),
            ),
        );
    }

    private function chatResponseForUnifiedResult(
        ?UnifiedToolCallResult $result,
        bool $memoryRefreshRequested,
    ): ?ChatResponse {
        if ($result === null || $result->status === UnifiedToolCallResult::FAILED) {
            return null;
        }

        if ($result->status === UnifiedToolCallResult::AWAITING_CONFIRMATION) {
            return $this->chatResponseForPendingMcpAction(
                $result->pendingAction,
                $memoryRefreshRequested,
            );
        }

        if ($result->message === null || trim($result->message) === '') {
            return null;
        }

        return new ChatResponse(
            message: $result->message,
            ctas: [],
            entities: [],
            suggestedActions: $result->suggestedActions !== [] ? $result->suggestedActions : null,
            memoryRefreshRequested: $memoryRefreshRequested,
        );
    }

    private function chatResponseForPendingMcpAction(
        ?McpPendingAction $pending,
        bool $memoryRefreshRequested,
    ): ChatResponse {
        if ($pending === null) {
            return new ChatResponse(
                message: "Je n’ai pas pu préparer cette action. Pouvez-vous préciser votre demande ?",
                ctas: [],
                entities: [],
                memoryRefreshRequested: $memoryRefreshRequested,
            );
        }

        return new ChatResponse(
            message: $pending->confirm_actor === 'visitor'
                ? "Avant de continuer, pouvez-vous confirmer cette action ?"
                : "Votre demande a été transmise à un conseiller, qui va la valider sous peu.",
            ctas: [],
            entities: [],
            pendingConfirmation: $pending->confirm_actor === 'visitor' ? [
                'id' => $pending->id,
                'connector' => $pending->connector_slug,
                'tool' => $pending->tool_name,
                'params' => $pending->params,
            ] : null,
            memoryRefreshRequested: $memoryRefreshRequested,
        );
    }

    /**
     * Appel LLM avec PERSONA EMPLOYÉ INTERNE
     */
    public function callLLM(Site $site, array $prompt, string $question): string
    {
        $companyName = $site->name ?? parse_url($site->url, PHP_URL_HOST);
        /**
         * @var WidgetSetting $settings
         */
        $settings = $site->settings;

        $messages = [
            ['role' => 'system', 'content' => $prompt['system']],
            ...$prompt['messages'],
        ];

        // Tous les appels de réponse du widget passent par le client commun :
        // sélection centralisée du modèle et basculement vers son secours.
        try {
            return $this->llm->chat($messages, [
                'task' => 'chat',
                'temperature' => (float) $settings->ai_temperature,
                'max_tokens' => $prompt['max_tokens'] ?? $settings->ai_max_tokens ?? 350,
            ]);
        } catch (\Throwable $exception) {
            Log::error('Échec des modèles LLM de conversation', [
                'site_id' => $site->id,
                'question' => substr($question, 0, 100),
                'error' => $exception->getMessage(),
            ]);

            return "Notre équipe chez {$companyName} reste disponible pour vous accompagner.";
        }

        // --- DÉBUT DE LA LOGIQUE DE RETRY ---
        $maxRetries = 5;
        $delaySeconds = 1; // Délai de base pour le backoff exponentiel
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {

                Log::info("Appel à l'API LLM (tentative {$attempt})", ['site_id' => $site->id, 'question' => substr($question, 0, 100)]);

                $response = Http::withHeaders([
                    'Authorization' => 'Bearer ' . env('OPENROUTER_API_KEY'),
                    'Content-Type' => 'application/json', // Bonne pratique
                ])->post('https://openrouter.ai/api/v1/chat/completions', [
                    'model' => config('llm.tasks.chat.model'),
                    'messages' => $messages,
                    'temperature' => floatval($settings->ai_temperature),
                    'max_tokens' => $prompt['max_tokens'] ?? $settings->ai_max_tokens ?? 350,//$settings->ai_max_tokens,
                ]);

                // Vérifier si la requête HTTP a échoué (statut 4xx, 5xx)
                if (!$response->successful()) {
                    $errorMessage = "Erreur HTTP API LLM (tentative {$attempt}): " . $response->status() . " - " . $response->body();
                    Log::warning($errorMessage);
                    // Si ce n'est pas la dernière tentative, attendre avant de réessayer
                    if ($attempt < $maxRetries) {
                        $newAttempt = $attempt + 1;
                        Log::info("Attente de {$delaySeconds}s avant la tentative {$newAttempt}...");
                        sleep($delaySeconds);
                        $delaySeconds *= 2; // Backoff exponentiel
                        continue; // Passer à la prochaine itération de la boucle (réessayer)
                    } else {
                        // C'est la dernière tentative, sortir de la boucle pour lever l'exception ou retourner le fallback
                        break; // Sortir de la boucle pour gérer l'échec final
                    }
                }

                // La requête a réussi, vérifier la structure de la réponse
                $responseData = $response->json();

                // Vérifier si la structure attendue est présente
                if (isset($responseData['choices']) && is_array($responseData['choices']) && count($responseData['choices']) > 0) {
                    $choice = $responseData['choices'][0];
                    if (isset($choice['message']) && isset($choice['message']['content'])) {
                        $content = $choice['message']['content'];
                        Log::info("Réponse API LLM reçue (tentative {$attempt})", ['content_length' => strlen($content)]);
                        return $content;
                    } else {
                        $errorMessage = "Structure de réponse API LLM invalide (tentative {$attempt}): 'choices.0.message.content' manquant";
                        Log::warning($errorMessage, ['response_data' => $responseData]);
                    }
                } else {
                    $errorMessage = "Structure de réponse API LLM invalide (tentative {$attempt}): 'choices' manquant ou vide";
                    Log::warning($errorMessage, ['response_data' => $responseData]);
                }

                // Si on arrive ici, c'est que la réponse n'était pas correctement formatée
                // Si ce n'est pas la dernière tentative, attendre avant de réessayer
                if ($attempt < $maxRetries) {
                    $newAttempt = $attempt + 1;
                    Log::info("Attente de {$delaySeconds}s avant la tentative {$newAttempt}...");
                    sleep($delaySeconds);
                    $delaySeconds *= 2; // Backoff exponentiel
                    continue; // Passer à la prochaine itération de la boucle (réessayer)
                }

                /*return $response->json()['choices'][0]['message']['content']
                    ?? "N'hésitez pas à nous contacter, nous serons ravis de vous aider.";*/

            }catch (RequestException $e) {
                $errorMessage = "Erreur de requête HTTP (tentative {$attempt}): " . $e->getMessage();
                Log::warning($errorMessage);
                // Si ce n'est pas la dernière tentative
                $newAttempt = $attempt+1;
                if ($attempt < $maxRetries) {
                    Log::info("Attente de {$delaySeconds}s avant la tentative {$newAttempt}...");
                    sleep($delaySeconds);
                    $delaySeconds *= 2; // Backoff exponentiel
                    continue; // Passer à la prochaine itération de la boucle (réessayer)
                }
            } catch (Exception $e) { // Capture d'autres exceptions potentielles (JSON invalide, etc.)
                $errorMessage = "Erreur inattendue lors de l'appel API (tentative {$attempt}): " . $e->getMessage();
                Log::error($errorMessage, ['exception' => $e]);
                // Si ce n'est pas la dernière tentative
                if ($attempt < $maxRetries) {
                    $newAttempt = $attempt+1;
                    Log::info("Attente de {$delaySeconds}s avant la tentative {$newAttempt}...");
                    sleep($delaySeconds);
                    $delaySeconds *= 2; // Backoff exponentiel
                    continue; // Passer à la prochaine itération de la boucle (réessayer)
                }
            }
        }

        // --- FIN DE LA BOUCLE DE RETRY ---
        // Si on arrive ici, c'est que toutes les tentatives ont échoué
        $finalErrorMessage = "Échec de l'appel API LLM après {$maxRetries} tentatives.";
        Log::error($finalErrorMessage, [
            'site_id' => $site->id,
            'question' => substr($question, 0, 100), // Logguer une partie de la question pour le contexte
        ]);

        // RETOUR MANQUANT AJOUTÉ ICI
        return "Notre équipe chez {$companyName} reste disponible pour vous accompagner.";
        // OU Optionnellement, vous pouvez lever une exception ici si le contrôleur doit la gérer
        // throw new Exception($finalErrorMessage);

    }
    public function updateConversationSummary(Conversation $conversation): void
    {
        $oldSummary = $conversation->summary ?? '{}';

        $recentMessages = Message::where('conversation_id', $conversation->id)
            ->orderBy('created_at', 'desc')
            ->take(8)
            ->get()
            ->reverse()
            ->map(fn($m) => "{$m->role}: {$m->content}")
            ->implode("\n");

        $prompt = <<<PROMPT
        Tu es un moteur de mémoire conversationnelle utilisé dans un chatbot SaaS multi-domaines
        (e-commerce, support client, blog, SaaS, etc.).

        Ton rôle est de maintenir un résumé court et utile d'une conversation.

        OBJECTIF
        Mettre à jour le résumé existant avec les nouvelles informations pertinentes.

        RÈGLES IMPORTANTES

        - Conserve uniquement les informations durables et utiles au contexte.
        - N'invente jamais d'information.
        - N'interprète pas les intentions non exprimées.
        - Supprime les informations temporaires ou inutiles.
        - Fusionne les informations similaires pour éviter les répétitions.
        - Si une nouvelle information contredit une ancienne, garde la plus récente.

        INFORMATIONS À CONSERVER

        - préférences utilisateur
        - objectifs utilisateur
        - contraintes
        - décisions confirmées
        - informations personnelles utiles

        INFORMATIONS À IGNORER

        - salutations
        - formules de politesse
        - small talk
        - réponses marketing
        - détails temporaires

        FORMAT DU RÉSUMÉ

        - phrases courtes
        - style neutre
        - maximum 12 lignes
        - une information par ligne

        RÉSUMÉ ACTUEL
        {$oldSummary}

        NOUVEAUX MESSAGES
        {$recentMessages}

        INSTRUCTION FINALE

        Génère le nouveau résumé mis à jour en respectant les règles ci-dessus.

        Retourne uniquement le résumé.
        Aucun texte explicatif.
        PROMPT;

        $response = $this->callLLMForSummary($prompt, $conversation, false);

        $conversation->update([
            'summary' => $response,
            'summary_updated_at' => now()
        ]);
    }
    public function updateConversationMemory(Conversation $conversation): void
    {
        $memory = $this->extractStructuredMemory($conversation);

        if (!empty($memory)) {
            ConversationMemory::updateOrCreate(
                ['conversation_id' => $conversation->id],
                ['memory' => $memory]
            );
        }
    }

    public function updateConversationMemoryFromMessage(Message $message): void
    {
        $memory = $this->extractStructuredMemoryFromMessage($message);

        if (!empty($memory)) {
            ConversationMemory::updateOrCreate(
                ['conversation_id' => $message->conversation_id],
                ['memory' => $memory]
            );
        }
    }

    private function callLLMForSummary(string $prompt, ?Conversation $conversation, bool $return_json = true): string
    {
        $maxRetries = 5;
        $delaySeconds = 1; // base backoff
        $conversationId = $conversation?->id ?? 'unknown';

        $fallback = $return_json
            ? json_encode(['preferences'=>[],'objectives'=>[],'constraints'=>[],'decisions'=>[],'user_info'=>[]])
            : ($conversation?->summary ?? 'Résumé indisponible');

        try {
            $content = $this->llm->chat([
                ['role' => 'system', 'content' => $prompt],
            ], [
                'task' => 'chat_summary',
                'temperature' => 0.3,
                'max_tokens' => 300,
            ]);

            if (! $return_json) {
                return trim(preg_replace('/^```[a-z]*|```$/mi', '', $content));
            }

            if (json_decode($content, true) !== null && json_last_error() === JSON_ERROR_NONE) {
                return $content;
            }
        } catch (\Throwable $exception) {
            Log::warning('Résumé LLM indisponible, utilisation du résumé de secours', [
                'conversation_id' => $conversationId,
                'error' => $exception->getMessage(),
            ]);
        }

        // Le client central a déjà épuisé le principal et son secours.
        // Ne pas relancer ici un ancien appel qui contournerait le registre.
        return $fallback;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {

            try {

                Log::info("Appel à l'API LLM pour résumé (tentative {$attempt})", ['conversation_id' => $conversationId]);

                $response = Http::withHeaders([
                    'Authorization' => 'Bearer ' . env('OPENROUTER_API_KEY'),
                    'Content-Type' => 'application/json',
                ])->timeout(30)
                    ->post('https://openrouter.ai/api/v1/chat/completions', [
                        'model' => config('llm.tasks.chat_summary.model'),
                        'messages' => [
                            ['role' => 'system', 'content' => $prompt]
                        ],
                        'temperature' => 0.3,
                        'max_tokens' => 300,
                    ]);

                if (!$response->successful()) {
                    Log::warning("Erreur HTTP API LLM (tentative {$attempt}): {$response->status()}", [
                        'body' => $response->body(),
                        'conversation_id' => $conversationId
                    ]);
                    if ($attempt < $maxRetries) {
                        sleep($delaySeconds);
                        $delaySeconds *= 2;
                        continue;
                    }
                    break;
                }

                $data = $response->json();
                $content = data_get($data, 'choices.0.message.content', null);

                if (empty($content)) {
                    Log::warning("Réponse vide ou malformée (tentative {$attempt})", [
                        'response_data' => $data,
                        'conversation_id' => $conversationId
                    ]);
                    if ($attempt < $maxRetries) {
                        sleep($delaySeconds);
                        $delaySeconds *= 2;
                        continue;
                    }
                }else {
                    $content = trim($content);

                    if (!$return_json) {
                        // nettoyage markdown ```json ou ``` si string brut
                        $content = preg_replace('/^```[a-z]*|```$/mi', '', $content);
                        $content = trim($content);
                        Log::info("Résumé LLM reçu (string) avec succès (tentative {$attempt})", ['conversation_id' => $conversationId]);
                        return $content;
                    }

                    // Cas JSON attendu
                    $decoded = json_decode($content, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                        Log::info("Résumé LLM reçu (JSON) avec succès (tentative {$attempt})", ['conversation_id' => $conversationId]);
                        return $content;
                    }

                    Log::warning("JSON invalide reçu (tentative {$attempt})", ['content' => $content]);
                    if ($attempt < $maxRetries) {
                        sleep($delaySeconds);
                        $delaySeconds *= 2;
                        continue;
                    }
                }

            } catch (Exception $e) {
                Log::error("Exception lors de l'appel API LLM (tentative {$attempt}): " . $e->getMessage(), ['conversation_id' => $conversationId]);
                if ($attempt < $maxRetries) {
                    sleep($delaySeconds);
                    $delaySeconds *= 2;
                    continue;
                }
            }

        }

        Log::error("Échec de l'appel API LLM après {$maxRetries} tentatives, fallback utilisé", ['conversation_id' => $conversationId]);
        return $fallback;

    }
    public function extractStructuredMemory(Conversation $conversation): array
    {
        $summary = $conversation->summary ?? '';

        if ($summary === '' || $summary === 'Résumé indisponible') {
            return [];
        }

        $prompt = <<<PROMPT
        Tu es un moteur d'extraction de mémoire structurée utilisé dans un chatbot SaaS multi-domaines
        (blog, e-commerce, support client, SaaS, etc.).

        Ton rôle :
        Transformer un résumé de conversation en JSON structuré exploitable par un agent conversationnel.

        Règles importantes :

        - Extrais uniquement les informations explicitement présentes dans le résumé.
        - Tu peux reformuler légèrement pour rendre l'information exploitable.
        - N'invente jamais d'information absente.
        - Fusionne les informations identiques pour éviter les doublons.
        - Si une information contredit une autre plus ancienne, garde la plus récente.
        - Les éléments doivent être courts (2 à 8 mots).
        - Maximum 15 éléments par catégorie.
        - Si aucune information pertinente n'est trouvée pour une catégorie, retourne un tableau vide.

        Catégories et exemples :

        preferences : produits, services, choix ou intérêts exprimés.
        Exemples :
        "stylo noir"
        "ordinateur Apple"
        "coffret cadeau"

        objectives : ce que l'utilisateur souhaite faire ou obtenir.
        Exemples :
        "acheter un stylo"
        "contacter le support"
        "obtenir des informations"

        constraints : conditions ou limitations exprimées.
        Exemples :
        "budget limité"
        "livraison rapide"
        "couleur noire"

        decisions : décisions déjà prises ou validées.
        Exemples :
        "choisir offre premium"
        "prendre ce modèle"

        user_info : informations personnelles explicitement mentionnées.
        Exemples :
        "vit à Paris"
        "photographe"
        "email exemple@mail.com"

        Format STRICT :

        {
          "preferences": [],
          "objectives": [],
          "constraints": [],
          "decisions": [],
          "user_info": []
        }

        Résumé à traiter :
        {$summary}

        ⚠️ Réponds uniquement avec un JSON valide.
        Aucun texte avant ou après.

        Exemple 1 :

        Résumé : "l'utilisateur préfère les stylos bleus, souhaite un coffret cadeau pour un ami, a un budget limité, veut contacter le support par email"

        Réponse :
        {
          "preferences": ["stylos bleus", "coffret cadeau"],
          "objectives": ["contacter le support"],
          "constraints": ["budget limité"],
          "decisions": [],
          "user_info": []
        }

        Exemple 2 :

        Résumé : "l'utilisateur veut un ordinateur Apple pas cher"

        Réponse :
        {
          "preferences": ["ordinateur Apple"],
          "objectives": ["acheter un ordinateur"],
          "constraints": ["prix bas"],
          "decisions": [],
          "user_info": []
        }
        PROMPT;

        $response = $this->callLLMForSummary($prompt, $conversation, true);

        Log::info("Extract Structure Memory: ", [
            'response' => $response,
        ]);

        $memory = json_decode($response, true);

        return is_array($memory) ? $memory : [
            'preferences' => [],
            'objectives' => [],
            'constraints' => [],
            'decisions' => [],
            'user_info' => []
        ];
    }
    public function extractStructuredMemoryFromMessage(Message $message): array
    {
        $prompt = <<<PROMPT
        Tu es un moteur d'extraction de mémoire structurée utilisé dans un chatbot SaaS multi-domaines
        (blog, e-commerce, support client, SaaS, etc.).

        Ton rôle est d'extraire les informations utiles contenues dans le message utilisateur.

        Règles importantes :

        - Extrais uniquement les informations présentes dans le message.
        - Tu peux reformuler légèrement pour rendre l'information exploitable.
        - N'invente jamais d'information absente.
        - Si aucune information pertinente n'est trouvée, retourne des tableaux vides.
        - Les éléments doivent être courts (2 à 8 mots).

        Catégories :

        preferences
        Produits, services, choix ou intérêts exprimés.

        Exemples :
        "stylo noir"
        "ordinateur Apple"
        "coffrets cadeaux"

        objectives
        Ce que l'utilisateur souhaite faire ou obtenir.

        Exemples :
        "acheter un stylo"
        "contacter l'entreprise"
        "obtenir des informations"

        constraints
        Conditions ou limitations.

        Exemples :
        "budget limité"
        "livraison rapide"
        "couleur noir"

        decisions
        Décisions déjà prises.

        Exemples :
        "choisir offre premium"
        "prendre ce modèle"

        user_info
        Informations personnelles explicitement mentionnées.

        Exemples :
        "vit à Paris"
        "photographe"
        "travaille en freelance"

        Format STRICT :

        {
          "preferences": [],
          "objectives": [],
          "constraints": [],
          "decisions": [],
          "user_info": []
        }

        Message utilisateur :
        {$message->content}

        Réponds uniquement avec un JSON valide.
        Aucun texte avant ou après.

        Exemple 1 :

        Message : "Je veux un ordinateur Apple pas cher"

        Réponse :
        {
         "preferences": ["ordinateur Apple"],
         "objectives": ["acheter un ordinateur"],
         "constraints": ["prix bas"],
         "decisions": [],
         "user_info": []
        }

        Exemple 2 :

        Message : "Je suis intéressé par vos coffrets"

        Réponse :
        {
         "preferences": ["coffrets"],
         "objectives": ["obtenir des informations"],
         "constraints": [],
         "decisions": [],
         "user_info": []
        }
        PROMPT;

        $response = $this->callLLMForSummary($prompt, $message->conversation, true);

        Log::info("Extract Structure Memory From Message: ", [
            'response' => $response,
        ]);

        $memory = json_decode($response, true);

        return is_array($memory) ? $memory : [
            'preferences' => [],
            'objectives' => [],
            'constraints' => [],
            'decisions' => [],
            'user_info' => []
        ];
    }
    private function buildEntitiesFallbackMessage(array $entities): ?string
    {
        if (empty($entities)) {
            return null;
        }

        // 🔢 Compter les types
        $counts = collect($entities)
            ->groupBy('type')
            ->map(fn($items) => count($items));

        // 🎯 Construire les labels avec count
        $parts = collect($counts)
            ->filter(fn($count, $type) => isset($this->entityLabels[$type]))
            ->sortBy(fn($count, $type) => $this->entityLabels[$type]['priority'])
            ->map(function ($count, $type) {

                $config = $this->entityLabels[$type];

                $label = $count === 1
                    ? $config['singular']
                    : $config['plural'];

                return "{$count} {$label}";
            })
            ->values()
            ->toArray();

        if (empty($parts)) {
            return null;
        }

        // 🧠 Phrase naturelle
        if (count($parts) === 1) {
            $list = $parts[0];
        } elseif (count($parts) === 2) {
            $list = implode(' et ', $parts);
        } else {
            $last = array_pop($parts);
            $list = implode(', ', $parts) . ' et ' . $last;
        }

        // ✨ Markdown propre
        return "Nous n’avons pas cette information exacte.\n\n---\n\n **Voici {$list} qui pourraient vous être utiles :**";
    }

    private function trackIntent(Site $site, Conversation $conversation, string $intent): bool
    {
        $messageId = Message::where('conversation_id', $conversation->id)
            ->where('role', 'user')
            ->latest('created_at')
            ->value('id');

        if (!$messageId) {
            return false;
        }

        // La mémoire ne doit pas être recalculée à chaque changement d'intention :
        // on ne déclenche qu'à l'entrée dans le bucket commercial, avec un
        // debounce de trois messages pour éviter les bascules rapprochées.
        $metadata = $conversation->metadata ?? [];
        $previousIntent = $metadata['query_analyzer_last_intent'] ?? null;
        $isCommercial = in_array($intent, self::COMMERCIAL_INTENTS, true);
        $wasCommercial = in_array($previousIntent, self::COMMERCIAL_INTENTS, true);
        $messageCount = $conversation->messages()->count();
        $lastMemoryTriggerCount = (int) ($metadata['memory_last_intent_trigger_count'] ?? 0);

        $memoryRefreshRequested = $isCommercial
            && !$wasCommercial
            && ($lastMemoryTriggerCount === 0 || ($messageCount - $lastMemoryTriggerCount) >= 3);

        $metadata['query_analyzer_last_intent'] = $intent;

        if ($memoryRefreshRequested) {
            // Marqué avant l'envoi du job pour éviter les doublons si plusieurs
            // messages arrivent avant que le worker ait traité le premier job.
            $metadata['memory_last_intent_trigger_count'] = $messageCount;
        }

        $conversation->update(['metadata' => $metadata]);

        $eventTypes = [AnalyticsEventType::INTENT_DETECTED];

        if ($isCommercial) {
            $eventTypes[] = AnalyticsEventType::COMMERCIAL_INTENT_DETECTED;
        }

        $specific = match ($intent) {
            'support' => AnalyticsEventType::SUPPORT_INTENT_DETECTED,
            'transactional' => AnalyticsEventType::PURCHASE_INTENT_DETECTED,
            'booking' => AnalyticsEventType::BOOKING_INTENT_DETECTED,
            'pricing' => AnalyticsEventType::PRICING_INTENT_DETECTED,
            default => null,
        };

        if ($specific) {
            $eventTypes[] = $specific;
        }

        foreach ($eventTypes as $eventType) {
            $this->analytics->capture(
                $site,
                $eventType,
                [
                    'visitor_id' => $conversation->visitor_id,
                    'conversation_id' => $conversation->id,
                    'message_id' => $messageId,
                    'session_id' => $conversation->metadata['session_id'] ?? null,
                    'correlation_id' => $conversation->metadata['session_id'] ?? $conversation->id,
                    'source' => 'query_analyzer',
                    'channel' => $conversation->metadata['channel'] ?? 'widget',
                ],
                metadata: ['intent' => $intent],
                idempotencyKey: $this->analytics->deterministicKey($eventType->value, $messageId),
            );
        }

        return $memoryRefreshRequested;
    }

    private function notifyThinking(Site $site, Conversation $conversation, string $label): void
    {
        $this->mercureService->post(
            "/sites/{$site->id}/conversations/{$conversation->id}",
            [
                'type' => 'thinking_step',
                'conversation_id' => $conversation->id,
                'label' => $label,
                'created_at' => now()->toISOString(),
            ]
        );
    }

    private function notifyMultiHopStarted(Site $site, Conversation $conversation): void
    {
        $this->mercureService->post(
            "/sites/{$site->id}/conversations/{$conversation->id}",
            [
                'type' => 'multi_hop_started',
                'conversation_id' => $conversation->id,
                'created_at' => now()->toISOString(),
            ]
        );
    }
}
