<?php

namespace App\Services\mcp;

use App\Domain\MCP\Agent\AgentSkillResolver;
use App\Domain\MCP\Agent\AgentSupervisor;
use App\Domain\MCP\Audit\AuditLogger;
use App\Domain\MCP\Capability\CapabilityResolver;
use App\Domain\MCP\Contracts\ProvidesSiteScopedTools;
use App\Domain\MCP\Contracts\ToolResult;
use App\Domain\MCP\Contracts\ToolSchema;
use App\Domain\MCP\Exceptions\AuthExpiredException;
use App\Domain\MCP\Exceptions\ConfirmationRequiredException;
use App\Domain\MCP\Exceptions\ConnectorUnavailableException;
use App\Domain\MCP\Exceptions\MCPException;
use App\Domain\MCP\Exceptions\PermissionDeniedException;
use App\Domain\MCP\Orchestration\MCPGateResult;
use App\Domain\MCP\Orchestration\OpenRouterToolClient;
use App\Domain\MCP\Registry\ConnectorRegistry;
use App\Domain\MCP\Security\ActorContext;
use App\Domain\MCP\Security\CredentialVault;
use App\Domain\MCP\Security\PermissionEngine;
use App\Domain\RAG\RAGToolAdapter;
use App\Enums\AnalyticsAttributionType;
use App\Enums\AnalyticsEventType;
use App\Models\Conversation;
use App\Models\Mcp\McpAgent;
use App\Models\Mcp\McpPendingAction;
use App\Models\Mcp\McpWorkflow;
use App\Models\Site;
use App\Services\analytics\AnalyticsEventService;
use App\Services\cta\ChatResponse;
use App\Services\MercureService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class MCPActionGateService
{
    private const MAX_HOPS = 6; // 🆕 relevé : chaînes plus longues possibles (recherche -> panier -> checkout)

    private int $maxHops;

    public function __construct(
        private readonly ConnectorRegistry $registry,
        private readonly PermissionEngine $permissions,
        private readonly CredentialVault $vault,
        private readonly AuditLogger $audit,
        private readonly RAGToolAdapter $ragTool,
        private readonly OpenRouterToolClient $llm,
        private readonly MercureService $mercureService,
        private readonly VisitorIdentityService $visitorIdentity, // 🆕
        private readonly CapabilityResolver $capabilities, // 🆕
        private readonly AgentSkillResolver $agentSkills, // 🆕
        private readonly AgentSupervisor $supervisor, // 🆕
        private readonly AnalyticsEventService $analytics,
    ) {
        $this->maxHops = (int) config('mcp.orchestrator.max_hops', 8); // 🆕
    }

    /**
     * Indique si le site possède plusieurs agents publiés. Cette information
     * sert uniquement au choix du mode de routage dans ChatService.
     */
    public function hasMultipleActiveAgents(Site $site): bool
    {
        return McpAgent::where('site_id', $site->id)
            ->where('is_active', true)
            ->take(2)
            ->get()
            ->count() > 1;
    }

    /**
     * Construit le catalogue réellement exposable au modèle de conversation.
     *
     * Ce catalogue est filtré par acteur puis par l'union des compétences des
     * agents actifs. Il ne constitue pas une autorisation en lui-même :
     * executeUnifiedToolCall() repasse toujours par PermissionEngine et par
     * le périmètre agent juste avant l'exécution réelle.
     *
     * @return array{
     *     tools: array<int, array<string, mixed>>,
     *     allowed_tool_names: array<int, string>,
     *     system_prompt: string,
     *     agent: ?McpAgent,
     *     agents: array<int, McpAgent>,
     *     tool_agent_ids: array<string, array<int, string>>,
     *     agent_scope_snapshot: array<string, mixed>
     * }|null
     */
    public function unifiedToolContext(Site $site, Conversation $conversation, ?string $intent = null): ?array
    {
        if (! config('mcp.enabled', true) || ! config('mcp.unified_tool_calling', true)) {
            return null;
        }

        $actor = ActorContext::fromConversation($conversation);
        $permittedTools = $this->permissions->filterAllowedTools(
            $site,
            $actor,
            $this->connectorToolSchemas($site),
        );

        if ($permittedTools === []) {
            return null;
        }

        $activeAgents = McpAgent::where('site_id', $site->id)
            ->where('is_active', true)
            ->get();

        if ($activeAgents->count() > 1 && ! config('mcp.unified_multi_agent_tool_calling', true)) {
            return null;
        }

        $agent = $activeAgents->first();
        $agentAllowedNames = $agent && ! empty($agent->skills)
            ? $this->agentSkills->resolveAllowedToolNames($site, $agent->skills)
            : [];
        $permittedToolNames = array_map(
            static fn (ToolSchema $tool): string => $tool->qualifiedName(),
            $permittedTools,
        );

        /** @var array<string, array<int, string>> $toolAgentIds */
        $toolAgentIds = [];
        /** @var array<string, array<int, string>> $agentToolNames */
        $agentToolNames = [];

        foreach ($activeAgents as $activeAgent) {
            $allowedNames = ! empty($activeAgent->skills)
                ? $this->agentSkills->resolveAllowedToolNames($site, $activeAgent->skills)
                : $permittedToolNames;

            $agentId = (string) $activeAgent->id;
            $agentToolNames[$agentId] = array_values(array_unique($allowedNames));

            foreach ($agentToolNames[$agentId] as $toolName) {
                if (! in_array($toolName, $permittedToolNames, true)) {
                    continue;
                }

                $toolAgentIds[$toolName] ??= [];
                $toolAgentIds[$toolName][] = $agentId;
            }
        }

        $scopedNames = $activeAgents->isEmpty() ? null : array_keys($toolAgentIds);
        $scopedTools = $scopedNames === null
            ? $permittedTools
            : array_values(array_filter(
                $permittedTools,
                static fn (ToolSchema $tool): bool => in_array($tool->qualifiedName(), $scopedNames, true),
            ));

        if ($scopedTools === []) {
            return null;
        }

        $agentWorkflowIds = $agent ? ($agent->workflow_ids ?? []) : null;
        $systemPrompt = $activeAgents->count() > 1
            ? $this->multiAgentSystemPrompt($site, $actor, $intent, $activeAgents->all(), $agentToolNames)
            : $this->systemPrompt($site, $actor, $intent, $agent, $agentAllowedNames, $agentWorkflowIds);

        $tools = [
            // En mode unifié, l'absence de tool_call signifie directement
            // "répondre en texte" : control__no_action_needed n'est donc pas
            // nécessaire et ne doit pas consommer un appel d'outil artificiel.
            ...array_values(array_filter(
                $this->controlTools(),
                fn (array $tool) => ($tool['function']['name'] ?? null) === 'control__ask_clarification',
            )),
            ...array_map(
                static fn (ToolSchema $tool): array => $tool->toOpenAIFormat(),
                $scopedTools,
            ),
        ];

        $actionPrompt = <<<'PROMPT'

MODE DÉCISION + RÉPONSE UNIFIÉ :
Tu reçois simultanément le contexte documentaire et la liste des outils MCP
réellement autorisés pour cet acteur. Si la demande est une question de
connaissance sans action externe, réponds directement en texte à partir du
contexte documentaire selon les règles de factualité déjà présentes. Si la
demande nécessite une action réalisable par un outil, émets le tool_call
correspondant. Si une donnée indispensable manque pour une action, utilise
control__ask_clarification. N'utilise jamais un outil uniquement pour vérifier
s'il pourrait être pertinent et n'invente jamais un résultat d'exécution.
Après un tool_call, le résultat fourni par le système est la source de vérité
pour rédiger la réponse finale.
PROMPT;

        return [
            'tools' => $tools,
            'allowed_tool_names' => array_map(
                static fn (ToolSchema $tool): string => $tool->qualifiedName(),
                $scopedTools,
            ),
            'system_prompt' => $systemPrompt.$actionPrompt,
            'agent' => $agent,
            'agents' => $activeAgents->all(),
            'tool_agent_ids' => $toolAgentIds,
            'agent_scope_snapshot' => [
                'agent_ids' => $activeAgents->pluck('id')->map(static fn ($id): string => (string) $id)->values()->all(),
                'allowed_tool_names' => array_map(
                    static fn (ToolSchema $tool): string => $tool->qualifiedName(),
                    $scopedTools,
                ),
                'tool_agent_ids' => $toolAgentIds,
            ],
        ];
    }

    /**
     * Exécute un tool_call issu du flux unifié via le même chemin sécurisé que
     * l'ancien runLoop(). Aucun appel ne peut contourner l'autorisation finale.
     *
     * @return array{status: string, result?: ToolResult, confirm_actor?: string, suggested_actions?: array}
     */
    public function executeUnifiedToolCall(
        Site $site,
        Conversation $conversation,
        string $qualifiedToolName,
        array $params,
        array $allowedToolNames,
        int $hop = 1,
        ?McpAgent $agent = null,
    ): array {
        if (! in_array($qualifiedToolName, $allowedToolNames, true)) {
            return [
                'status' => 'denied',
                'result' => ToolResult::fail(
                    'tool_not_allowed',
                    'Cet outil n’est pas disponible pour cette demande.',
                ),
            ];
        }

        if ($agent !== null) {
            // Recharge l'agent afin de ne jamais faire confiance à un objet
            // potentiellement périmé entre la construction du catalogue et
            // l'exécution effective de l'outil.
            $freshAgent = McpAgent::query()
                ->whereKey($agent->id)
                ->where('site_id', $site->id)
                ->where('is_active', true)
                ->first();

            if (! $freshAgent || ! $this->agentCanUseTool($site, $freshAgent, $qualifiedToolName)) {
                Log::warning('MCP unified: outil refusé hors du périmètre agent', [
                    'site_id' => $site->id,
                    'agent_id' => $agent->id,
                    'tool' => $qualifiedToolName,
                ]);

                return [
                    'status' => 'denied',
                    'result' => ToolResult::fail(
                        'agent_scope_denied',
                        'Cet outil n’est pas disponible pour cet agent.',
                    ),
                ];
            }

            $agent = $freshAgent;
        }

        $parts = ToolSchema::fromQualifiedName($qualifiedToolName);
        $connectorSlug = $parts['connector'] ?? null;
        $toolName = $parts['tool'] ?? null;

        if (! is_string($connectorSlug) || trim($connectorSlug) === '' || ! is_string($toolName) || trim($toolName) === '') {
            return [
                'status' => 'denied',
                'result' => ToolResult::fail('invalid_tool_name', 'Nom d’outil invalide.'),
            ];
        }

        $actor = ActorContext::fromConversation($conversation);
        $agentCallKey = $agent
            ? $this->analytics->deterministicKey(
                'unified_agent',
                $conversation->id,
                $agent->id,
                $connectorSlug,
                $toolName,
                hash('sha256', json_encode($params)),
            )
            : null;

        if ($agent && $agentCallKey) {
            $this->trackAgentExecution($site, $conversation, $agent, AnalyticsEventType::AGENT_STARTED, $agentCallKey);
        }

        try {
            $execution = $this->executeHop(
                $site,
                $conversation,
                $actor,
                $connectorSlug,
                $toolName,
                $params,
                $hop,
            );

            if ($agent && $agentCallKey && ($execution['status'] ?? null) !== 'awaiting_confirmation') {
                $this->trackAgentExecution($site, $conversation, $agent, AnalyticsEventType::AGENT_COMPLETED, $agentCallKey);
            }
        } catch (Throwable $exception) {
            if ($agent && $agentCallKey) {
                $this->trackAgentExecution(
                    $site,
                    $conversation,
                    $agent,
                    AnalyticsEventType::AGENT_FAILED,
                    $agentCallKey,
                    'execution_exception',
                );
            }

            throw $exception;
        }

        if (($execution['status'] ?? null) === 'success' && ($execution['result'] ?? null) instanceof ToolResult) {
            /** @var ToolResult $result */
            $result = $execution['result'];

            return [
                ...$execution,
                'agent_id' => $agent?->id,
                'suggested_actions' => $result->success
                    ? $this->suggestionsFor($site, $actor, $connectorSlug, $toolName, $result->data)
                    : [],
            ];
        }

        return $execution;
    }

    public function createUnifiedPendingAction(
        Site $site,
        Conversation $conversation,
        string $connectorSlug,
        string $toolName,
        array $params,
        string $confirmActor,
        string $toolCallId,
        array $messagesSnapshot,
        ?McpAgent $agent = null,
        array $agentScopeSnapshot = [],
    ): McpPendingAction {
        return McpPendingAction::create([
            'id' => (string) Str::uuid(),
            'site_id' => $site->id,
            'conversation_id' => $conversation->id,
            'session_id' => $conversation->metadata['session_id'] ?? null,
            'correlation_id' => $conversation->metadata['session_id'] ?? $conversation->id,
            'connector_slug' => $connectorSlug,
            'tool_name' => $toolName,
            'params' => $params,
            'confirm_actor' => $confirmActor,
            'tool_call_id' => $toolCallId,
            'messages_snapshot' => $messagesSnapshot,
            'agent_id' => $agent?->id,
            'orchestration_mode' => 'unified',
            'agent_scope_snapshot' => $agentScopeSnapshot !== [] ? $agentScopeSnapshot : null,
            'status' => 'pending',
            'expires_at' => now()->addDays(3),
        ]);
    }

    public function notifyUnifiedThinking(Site $site, Conversation $conversation, string $label): void
    {
        $this->notifyThinking($site, $conversation, $label);
    }

    public function unifiedThinkingLabel(string $connectorSlug, string $toolName): string
    {
        return $this->thinkingLabelFor($connectorSlug, $toolName);
    }

    public function tryHandle(Site $site, Conversation $conversation, string $question, array $history, ?string $intent = null): MCPGateResult
    {
        $actor = ActorContext::fromConversation($conversation);
        $permittedTools = $this->permissions->filterAllowedTools($site, $actor, $this->connectorToolSchemas($site));

        if (empty($permittedTools)) {
            return MCPGateResult::notApplicable();
        }

        $activeAgents = McpAgent::where('site_id', $site->id)->where('is_active', true)->get();

        // Aucun agent configuré : comportement historique, passerelle générique.
        if ($activeAgents->isEmpty()) {
            return $this->handleForAgent($site, $conversation, $actor, $question, $history, $permittedTools, null, $intent);
        }

        $selected = $this->supervisor->route($site, $question, $history, $activeAgents);

        // Le superviseur n'a rien trouvé de spécifique : repli sur l'agent
        // marqué "par défaut", sinon le premier agent actif du site.
        if (empty($selected)) {
            $fallback = $activeAgents->firstWhere('is_default', true) ?? $activeAgents->first();

            return $this->handleForAgent($site, $conversation, $actor, $question, $history, $permittedTools, $fallback, $intent);
        }

        if (count($selected) === 1) {
            return $this->handleForAgent($site, $conversation, $actor, $question, $history, $permittedTools, $selected[0], $intent);
        }

        return $this->handleMultiAgent($site, $conversation, $actor, $question, $history, $permittedTools, $selected, $intent);
    }

    /**
     * 🆕 Exécution scopée à UN agent (ou générique si $agent est null) —
     * c'est exactement ce que faisait l'ancien corps de tryHandle().
     */
    private function handleForAgent(
        Site $site, Conversation $conversation, ActorContext $actor, string $question, array $history,
        array $permittedTools, ?McpAgent $agent, ?string $intent,
    ): MCPGateResult {
        $agentAllowedNames = ($agent && ! empty($agent->skills)) ? $this->agentSkills->resolveAllowedToolNames($site, $agent->skills) : [];
        $scopedTools = $agentAllowedNames
            ? array_values(array_filter($permittedTools, fn ($t) => in_array($t->qualifiedName(), $agentAllowedNames, true)))
            : $permittedTools;

        if (empty($scopedTools)) {
            return MCPGateResult::notApplicable();
        }

        $agentCallKey = $agent
            ? $this->analytics->deterministicKey('agent', $conversation->id, $agent->id, hash('sha256', $question))
            : null;

        if ($agent && $agentCallKey) {
            $this->trackAgentExecution($site, $conversation, $agent, AnalyticsEventType::AGENT_STARTED, $agentCallKey);
        }

        // 🆕 Un agent explicite n'utilise QUE les workflows cochés — aucun coché
        // = aucun workflow pour lui. Seule l'absence totale d'agent (site sans
        // Agent Studio configuré) continue de voir tous les workflows du site.
        $agentWorkflowIds = $agent ? ($agent->workflow_ids ?? []) : null;

        $tools = [
            ...$this->controlTools(),
            ...array_map(fn (ToolSchema $t) => $t->toOpenAIFormat(), [...$scopedTools, $this->ragTool->schema()]),
        ];

        $messages = [
            ['role' => 'system', 'content' => $this->systemPrompt($site, $actor, $intent, $agent, $agentAllowedNames, $agentWorkflowIds)],
            ...$history,
            ['role' => 'user', 'content' => $question],
        ];

        try {
            $result = $this->runLoop($site, $conversation, $actor, $messages, $tools, hop: 1, trace: [], suggestedActions: [], agent: $agent);

            if ($agent && $agentCallKey && $result->status !== 'awaiting_confirmation') {
                $this->trackAgentExecution($site, $conversation, $agent, AnalyticsEventType::AGENT_COMPLETED, $agentCallKey);
            }

            return $result;
        } catch (Throwable $exception) {
            if ($agent && $agentCallKey) {
                $this->trackAgentExecution($site, $conversation, $agent, AnalyticsEventType::AGENT_FAILED, $agentCallKey, 'execution_exception');
            }

            throw $exception;
        }
    }

    /**
     * 🆕 Plusieurs agents concernés par le même message : chacun traite sa
     * partie via handleForAgent() — les outils de contrôle lui permettent de
     * se retirer proprement si sa partie du message ne le concerne pas. Les
     * réponses obtenues sont ensuite fusionnées en une seule réponse cohérente.
     */
    private function handleMultiAgent(
        Site $site, Conversation $conversation, ActorContext $actor, string $question, array $history,
        array $permittedTools, array $agents, ?string $intent,
    ): MCPGateResult {
        $answers = [];
        $suggestedActions = [];
        $processedAgentIds = [];
        $runningHistory = $history; // 🆕 s'enrichit au fil des agents traités dans ce même tour

        foreach ($agents as $agent) {
            if (in_array($agent->id, $processedAgentIds, true)) {
                continue;
            }
            $processedAgentIds[] = $agent->id;

            $result = $this->handleForAgent($site, $conversation, $actor, $question, $runningHistory, $permittedTools, $agent, $intent);

            if ($result->status === 'awaiting_confirmation') {
                return $result;
            }

            if ($result->status === 'finished' && trim($result->response->message) !== '') {
                $answers[] = $result->response->message;
                $suggestedActions = array_merge($suggestedActions, $result->response->suggestedActions ?? []);

                // 🆕 L'agent suivant voit ce que celui-ci vient de faire — évite
                // qu'un 2ᵉ agent duplique ou contredise une action déjà traitée
                // par le 1er pour la même demande. Cette note reste locale à
                // cette boucle : jamais persistée dans l'historique réel de la
                // conversation, le visiteur ne la voit jamais.
                $runningHistory[] = ['role' => 'user', 'content' => $question];
                $runningHistory[] = ['role' => 'assistant', 'content' => "[Note interne : le collègue {$agent->name} vient de traiter la partie suivante de cette même demande : \"{$result->response->message}\". Ne duplique pas ce qui a déjà été fait — concentre-toi uniquement sur ce qui reste dans ton propre domaine, et si tout est déjà couvert, n'appelle aucun outil.]"];
            }
        }

        if (empty($answers)) {
            return MCPGateResult::notApplicable();
        }

        $mergedMessage = count($answers) === 1 ? $answers[0] : implode("\n\n", $answers);

        return MCPGateResult::finished(new ChatResponse(message: $mergedMessage, ctas: [], entities: [], suggestedActions: $suggestedActions), []);
    }

    /**
     * 🆕 Reprend après résolution d'une mcp_pending_actions (approuvée ou
     * refusée), par le visiteur OU par un agent back-office.
     */
    public function resumeAfterConfirmation(Site $site, McpPendingAction $pendingAction, bool $approved): MCPGateResult
    {
        $conversation = $pendingAction->conversation;
        $actor = ActorContext::fromConversation($conversation);
        $messages = $pendingAction->messages_snapshot;
        $suggestedActions = [];
        $isUnifiedPending = ($pendingAction->orchestration_mode ?? 'legacy') === 'unified';
        $agentScope = $isUnifiedPending && is_array($pendingAction->agent_scope_snapshot)
            ? $pendingAction->agent_scope_snapshot
            : null;

        // 🆕 Reconstruit le même agent (ou aucun) que celui actif au moment de
        // la demande initiale — sinon la reprise pourrait exécuter l'action
        // hors du périmètre qui avait motivé la confirmation.
        $agent = $pendingAction->agent_id
            ? ($isUnifiedPending
                ? McpAgent::query()
                    ->whereKey($pendingAction->agent_id)
                    ->where('site_id', $site->id)
                    ->where('is_active', true)
                    ->first()
                : McpAgent::find($pendingAction->agent_id))
            : null;

        if (
            $isUnifiedPending
            && (
                (
                    $pendingAction->agent_id !== null
                    && (
                        $agent === null
                        || ! $this->agentCanUseTool(
                            $site,
                            $agent,
                            $pendingAction->connector_slug.'__'.$pendingAction->tool_name,
                        )
                    )
                )
                || (
                    is_array($agentScope['allowed_tool_names'] ?? null)
                    && ! in_array(
                        $pendingAction->connector_slug.'__'.$pendingAction->tool_name,
                        $agentScope['allowed_tool_names'],
                        true,
                    )
                )
            )
        ) {
            Log::warning('MCP unified: confirmation refusée hors du périmètre agent courant', [
                'site_id' => $site->id,
                'conversation_id' => $conversation->id,
                'agent_id' => $pendingAction->agent_id,
                'tool' => $pendingAction->connector_slug.'__'.$pendingAction->tool_name,
            ]);

            return MCPGateResult::finished(
                new ChatResponse(
                    message: "Cette action n'est plus disponible dans le périmètre de sécurité actuel.",
                    ctas: [],
                    entities: [],
                ),
                [],
            );
        }

        if (! $approved) {
            $messages[] = ['role' => 'tool', 'tool_call_id' => $pendingAction->tool_call_id, 'content' => json_encode(['status' => 'declined'])];
        } else {
            $result = $this->executeAuthorized($site, $conversation, $actor, $pendingAction->connector_slug, $pendingAction->tool_name, $pendingAction->params, hop: 0, forced: true);
            $messages[] = ['role' => 'tool', 'tool_call_id' => $pendingAction->tool_call_id, 'content' => json_encode($result->toArrayForLLM())];

            if ($result->success) {
                $suggestedActions = $this->suggestionsFor($site, $actor, $pendingAction->connector_slug, $pendingAction->tool_name, $result->data);
            }
        }

        $permittedTools = $this->permissions->filterAllowedTools($site, $actor, $this->connectorToolSchemas($site));
        $agentAllowedNames = ($agent && ! empty($agent->skills)) ? $this->agentSkills->resolveAllowedToolNames($site, $agent->skills) : [];
        $scopedTools = $agentAllowedNames
            ? array_values(array_filter($permittedTools, fn ($t) => in_array($t->qualifiedName(), $agentAllowedNames, true)))
            : $permittedTools;

        // Une reprise unifiée doit conserver l'union des compétences
        // originale, et non la réduire au seul agent qui portait l'action
        // confirmée : le tour initial pouvait couvrir plusieurs domaines.
        if ($agentScope !== null && is_array($agentScope['allowed_tool_names'] ?? null)) {
            $scopedTools = array_values(array_filter(
                $permittedTools,
                static fn (ToolSchema $tool): bool => in_array(
                    $tool->qualifiedName(),
                    $agentScope['allowed_tool_names'],
                    true,
                ),
            ));
        }

        $resumeToolSchemas = $isUnifiedPending
            ? $scopedTools
            : [...$scopedTools, $this->ragTool->schema()];
        $tools = [
            ...$this->controlTools(),
            ...array_map(fn (ToolSchema $t) => $t->toOpenAIFormat(), $resumeToolSchemas),
        ];

        return $this->runLoop(
            $site,
            $conversation,
            $actor,
            $messages,
            $tools,
            hop: $this->maxHops,
            trace: [],
            suggestedActions: $suggestedActions,
            agent: $agent,
            agentScope: $agentScope,
        );
    }

    private function runLoop(
        Site $site,
        Conversation $conversation,
        ActorContext $actor,
        array $messages,
        array $tools,
        int $hop,
        array $trace,
        array $suggestedActions = [],
        array $executedCalls = [],
        ?McpAgent $agent = null,
        ?array $agentScope = null,
    ): MCPGateResult
    {
        while ($hop <= $this->maxHops) {
            if ($hop === 1) {
                $this->notifyThinking($site, $conversation, 'Vérification des actions possibles...');
            }

            $toolChoice = $hop === 1 ? 'required' : 'auto';
            $llmResponse = $this->llm->send($messages, $tools, $toolChoice);

            if (empty($llmResponse['tool_calls'])) {
                return MCPGateResult::finished(
                    new ChatResponse(message: trim((string) $llmResponse['text']), ctas: [], entities: [], suggestedActions: $suggestedActions),
                    $trace,
                );
            }

            $messages[] = $llmResponse['raw_message'];

            foreach ($llmResponse['tool_calls'] as $toolCall) {
                $qualifiedName = $toolCall['function']['name'];
                $args = json_decode($toolCall['function']['arguments'] ?? '{}', true) ?: [];
                $toolCallId = $toolCall['id'];

                if ($qualifiedName === 'control__no_action_needed') {
                    return MCPGateResult::notApplicable();
                }
                if ($qualifiedName === 'control__ask_clarification') {
                    return MCPGateResult::finished(
                        new ChatResponse(message: (string) ($args['question'] ?? ''), ctas: [], entities: [], suggestedActions: $suggestedActions),
                        $trace,
                    );
                }

                $unifiedToolAgent = $agentScope !== null
                    ? $this->activeAgentForUnifiedTool($site, $qualifiedName, $agentScope)
                    : null;
                if (
                    $agentScope !== null
                    && ! empty($agentScope['agent_ids'] ?? [])
                    && $unifiedToolAgent === null
                ) {
                    $trace[] = [
                        'hop' => $hop,
                        'connector' => 'unknown',
                        'tool' => $qualifiedName,
                        'status' => 'agent_scope_denied',
                    ];
                    $messages[] = [
                        'role' => 'tool',
                        'tool_call_id' => $toolCallId,
                        'content' => json_encode(ToolResult::fail(
                            'agent_scope_denied',
                            'Cet outil n’est pas disponible dans le périmètre multi-agent de cette demande.',
                        )->toArrayForLLM()),
                    ];
                    continue;
                }

                [$connectorSlug, $toolName] = array_values(ToolSchema::fromQualifiedName($qualifiedName));

                // 🆕 Garde-fou anti-doublon : le LLM redemande EXACTEMENT le même
                // appel (même outil, mêmes paramètres) qu'il a déjà exécuté avec
                // succès dans cette même boucle. On n'exécute JAMAIS une 2e fois
                // — critique pour les actions financières (generate_checkout,
                // issue_refund...) — et on répond directement avec le résultat
                // déjà obtenu au lieu de laisser la boucle tourner jusqu'à MAX_HOPS.
                $callSignature = $connectorSlug.'.'.$toolName.':'.md5(json_encode($args));

                if (isset($executedCalls[$callSignature])) {
                    $cached = $executedCalls[$callSignature];
                    $fallbackMessage = $cached->humanSummary ?: 'Cette action a déjà été effectuée avec succès.';
                    if (! empty($cached->data['checkout_url'])) {
                        $fallbackMessage .= " Voici le lien de paiement : {$cached->data['checkout_url']}";
                    }

                    Log::warning("MCP: appel dupliqué détecté et bloqué ({$callSignature}) pour le site {$site->id}");

                    return MCPGateResult::finished(
                        new ChatResponse(message: $fallbackMessage, ctas: [], entities: [], suggestedActions: $suggestedActions),
                        $trace,
                    );
                }

                if ($hop === 1) {
                    $this->notifyThinking($site, $conversation, $this->thinkingLabelFor($connectorSlug, $toolName));
                }

                $agentForExecution = $unifiedToolAgent ?? $agent;
                $agentCallKey = $agentScope !== null && $agentForExecution
                    ? $this->analytics->deterministicKey(
                        'unified_agent',
                        $conversation->id,
                        $agentForExecution->id,
                        $connectorSlug,
                        $toolName,
                        hash('sha256', json_encode($args)),
                    )
                    : null;

                if ($agentScope !== null && $agentForExecution && $agentCallKey) {
                    $this->trackAgentExecution(
                        $site,
                        $conversation,
                        $agentForExecution,
                        AnalyticsEventType::AGENT_STARTED,
                        $agentCallKey,
                    );
                }

                try {
                    $execution = $this->executeHop($site, $conversation, $actor, $connectorSlug, $toolName, $args, $hop);

                    if (
                        $agentScope !== null
                        && $agentForExecution
                        && $agentCallKey
                        && ($execution['status'] ?? null) !== 'awaiting_confirmation'
                    ) {
                        $this->trackAgentExecution(
                            $site,
                            $conversation,
                            $agentForExecution,
                            AnalyticsEventType::AGENT_COMPLETED,
                            $agentCallKey,
                        );
                    }
                } catch (Throwable $exception) {
                    if ($agentScope !== null && $agentForExecution && $agentCallKey) {
                        $this->trackAgentExecution(
                            $site,
                            $conversation,
                            $agentForExecution,
                            AnalyticsEventType::AGENT_FAILED,
                            $agentCallKey,
                            'execution_exception',
                        );
                    }

                    throw $exception;
                }
                $trace[] = ['hop' => $hop, 'connector' => $connectorSlug, 'tool' => $toolName, 'status' => $execution['status']];

                if ($execution['status'] === 'awaiting_confirmation') {
                    $pendingAgent = $agentScope !== null
                        ? ($unifiedToolAgent ?? $agent)
                        : $agent;

                    $pendingAction = McpPendingAction::create([
                        'id' => (string) Str::uuid(),
                        'site_id' => $site->id,
                        'conversation_id' => $conversation->id,
                        'session_id' => $conversation->metadata['session_id'] ?? null,
                        'correlation_id' => $conversation->metadata['session_id'] ?? $conversation->id,
                        'connector_slug' => $connectorSlug,
                        'tool_name' => $toolName,
                        'params' => $args,
                        'confirm_actor' => $execution['confirm_actor'],
                        'tool_call_id' => $toolCallId,
                        'messages_snapshot' => $messages,
                        'agent_id' => $pendingAgent?->id, // 🆕
                        'orchestration_mode' => $agentScope !== null ? 'unified' : 'legacy',
                        'agent_scope_snapshot' => $agentScope,
                        'status' => 'pending',
                        'expires_at' => now()->addDays(3),
                    ]);

                    return MCPGateResult::awaitingConfirmation($pendingAction, $trace);
                }

                // 🆕 On ne met en cache que les VRAIS succès métier (ToolResult::success
                // === true), jamais un échec métier (out_of_stock, variation_required,
                // empty_cart...). Un échec doit toujours pouvoir être retenté par le LLM
                // après correction — seule une action réellement aboutie doit être protégée
                // contre une ré-exécution.
                if ($execution['status'] === 'success' && $execution['result']->success) {
                    $suggestedActions = array_merge(
                        $suggestedActions,
                        $this->suggestionsFor($site, $actor, $connectorSlug, $toolName, $execution['result']->data),
                    );
                    $executedCalls[$callSignature] = $execution['result'];
                }

                $messages[] = ['role' => 'tool', 'tool_call_id' => $toolCallId, 'content' => json_encode($execution['result']->toArrayForLLM())];
            }

            $hop++;
        }

        Log::warning("MCP: nombre maximum de hops atteint pour le site {$site->id}, tentative de synthèse finale");

        // 🆕 Plutôt qu'un message générique, on redonne une dernière fois la
        // parole au LLM en mode texte forcé (tool_choice='none') : il a déjà tous
        // les résultats des outils exécutés dans $messages, il peut donc résumer
        // honnêtement ce qui a été fait (et ce qui ne l'a pas été) au lieu de
        // laisser le visiteur sans aucune explication.
        try {
            $finalResponse = $this->llm->send($messages, $tools, 'none');
            $finalText = trim((string) ($finalResponse['text'] ?? ''));
        } catch (Throwable $e) {
            Log::error("MCP: échec de la synthèse finale après hops épuisés : {$e->getMessage()}");
            $finalText = '';
        }

        if ($finalText === '') {
            $finalText = "Je n'ai pas pu finaliser cette action automatiquement. Un conseiller va prendre le relais.";
        }

        return MCPGateResult::finished(new ChatResponse(message: $finalText, ctas: [], entities: [], suggestedActions: $suggestedActions), $trace);
    }

    private function executeHop(Site $site, Conversation $conversation, ActorContext $actor, string $connectorSlug, string $toolName, array $params, int $hop): array
    {
        try {
            $result = $this->executeAuthorized($site, $conversation, $actor, $connectorSlug, $toolName, $params, $hop);

            return ['status' => 'success', 'result' => $result];
        } catch (ConfirmationRequiredException $e) {
            $this->audit->log($site, $connectorSlug, $toolName, $params, 'confirm', 'awaiting_confirmation', conversationId: $conversation->id, hopNumber: $hop);

            return ['status' => 'awaiting_confirmation', 'confirm_actor' => $e->confirmActor];
        } catch (PermissionDeniedException $e) {
            $this->audit->log($site, $connectorSlug, $toolName, $params, 'deny', 'denied', errorCode: 'permission_denied', conversationId: $conversation->id, hopNumber: $hop);

            return ['status' => 'denied', 'result' => ToolResult::fail('permission_denied', $e->getMessage())];
        } catch (ConnectorUnavailableException $e) {
            Log::warning("MCP connector_unavailable: {$e->getMessage()}", ['connector' => $connectorSlug, 'tool' => $toolName]);
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

    private function executeAuthorized(Site $site, Conversation $conversation, ActorContext $actor, string $connectorSlug, string $toolName, array $params, int $hop, bool $forced = false): ToolResult
    {
        if ($connectorSlug === RAGToolAdapter::CONNECTOR_SLUG) {
            $result = $this->ragTool->search($site, $params['query'] ?? '', actor: $actor);
            $this->audit->log($site, $connectorSlug, $toolName, $params, 'auto', $result->success ? 'success' : 'error', $result, conversationId: $conversation->id, hopNumber: $hop);

            if ($result->success) {
                $this->analytics->capture(
                    $site,
                    AnalyticsEventType::KNOWLEDGE_SOURCE_USED,
                    [
                        'visitor_id' => $conversation->visitor_id,
                        'conversation_id' => $conversation->id,
                        'source' => 'rag',
                        'channel' => $conversation->metadata['channel'] ?? 'widget',
                    ],
                    metadata: ['tool_name' => $toolName],
                    idempotencyKey: $this->analytics->deterministicKey(
                        'knowledge_source_used', $conversation->id, $toolName, hash('sha256', (string) ($params['query'] ?? '')),
                    ),
                );
            }

            return $result;
        }

        $callKey = $this->analytics->deterministicKey(
            'mcp', $conversation->id, $connectorSlug, $toolName, hash('sha256', json_encode($params)),
        );
        $this->trackMcpAction($site, $conversation, AnalyticsEventType::MCP_ACTION_STARTED, $callKey, $connectorSlug, $toolName);

        try {
            // Une confirmation ne doit contourner que la seconde demande de
            // confirmation. Le mode deny, le périmètre de l'acteur et une
            // éventuelle modification de permission sont toujours revalidés.
            $this->permissions->authorize(
                $site,
                $actor,
                $connectorSlug,
                $toolName,
                skipConfirmation: $forced,
                consumeDailyLimit: !$forced,
            );
        } catch (Throwable $exception) {
            $this->trackMcpAction($site, $conversation, AnalyticsEventType::MCP_ACTION_FAILED, $callKey, $connectorSlug, $toolName, errorCode: 'permission_denied');
            throw $exception;
        }

        $credentials = $this->vault->retrieve($site, $connectorSlug);
        if ($credentials === null) {
            $this->trackMcpAction($site, $conversation, AnalyticsEventType::MCP_ACTION_FAILED, $callKey, $connectorSlug, $toolName, errorCode: 'not_connected');

            return ToolResult::fail('not_connected', "Le connecteur {$connectorSlug} n'est pas configuré pour ce site.");
        }

        $connector = $this->registry->get($connectorSlug);

        try {
            $freshCredentials = $connector->authenticate($credentials);
            if ($freshCredentials !== $credentials) {
                $this->vault->refresh($site, $connectorSlug, $freshCredentials);
            }
        } catch (AuthExpiredException $e) {
            $this->vault->markAuthExpired($site, $connectorSlug, $e->getMessage());
            $this->trackMcpAction($site, $conversation, AnalyticsEventType::MCP_ACTION_FAILED, $callKey, $connectorSlug, $toolName, errorCode: 'auth_expired');
            throw $e;
        }

        // 🆕 Contexte injecté (jamais fourni par le LLM) : panier/wishlist scopés au bon propriétaire.
        $context = [
            'site_id' => $site->id, 'conversation_id' => $conversation->id,
            'owner_type' => $actor->ownerType, 'owner_id' => $actor->ownerId, 'is_admin' => $actor->isAdmin,
        ];

        $startedAt = microtime(true);
        try {
            $result = $connector->callTool($toolName, $params, $freshCredentials, $context);
        } catch (Throwable $exception) {
            $durationMs = (int) ((microtime(true) - $startedAt) * 1000);
            $this->trackMcpAction($site, $conversation, AnalyticsEventType::MCP_ACTION_FAILED, $callKey, $connectorSlug, $toolName, $durationMs, 'connector_exception');
            throw $exception;
        }
        $durationMs = (int) ((microtime(true) - $startedAt) * 1000);

        $this->audit->log($site, $connectorSlug, $toolName, $params, 'auto', $result->success ? 'success' : 'error', $result, $durationMs, conversationId: $conversation->id, hopNumber: $hop);
        $this->trackMcpAction(
            $site,
            $conversation,
            $result->success ? AnalyticsEventType::MCP_ACTION_COMPLETED : AnalyticsEventType::MCP_ACTION_FAILED,
            $callKey,
            $connectorSlug,
            $toolName,
            $durationMs,
            $result->errorCode,
        );

        if ($result->success) {
            $this->trackBusinessOutcome($site, $conversation, $callKey, $connectorSlug, $toolName, $params, $result);
        }

        // 🆕 Les effets secondaires ne doivent JAMAIS pouvoir invalider un résultat
        // d'action déjà réussi côté WooCommerce (ex: une commande déjà créée et
        // payable). Isolés dans leur propre try/catch : une erreur ici est
        // loggée, jamais propagée — sinon le LLM croit que l'action a échoué et
        // la retente, au risque de créer une 2e commande pour de vrai.
        if ($result->success && ! empty($result->cartSync)) {
            try {
                $this->notifyCartSync($site, $conversation, $result->cartSync);
            } catch (Throwable $e) {
                Log::error("MCP: échec notifyCartSync après {$connectorSlug}.{$toolName} : {$e->getMessage()}");
            }
        }

        if ($result->success && $result->identity && $actor->ownerType === 'visitor') {
            try {
                $visitor = $conversation->visitor;
                $user = $this->visitorIdentity->resolveFromIdentity($site, $visitor, $result->identity);
                if ($user) {
                    $conversation->refresh();
                    Log::info("MCP: visiteur transformé en user {$user->id} suite à {$connectorSlug}.{$toolName}");
                }
            } catch (Throwable $e) {
                Log::error("MCP: échec transformation visiteur après {$connectorSlug}.{$toolName} : {$e->getMessage()}");
            }
        }

        return $result;
    }

    private function trackMcpAction(
        Site $site,
        Conversation $conversation,
        AnalyticsEventType $eventType,
        string $callKey,
        string $connectorSlug,
        string $toolName,
        ?int $durationMs = null,
        ?string $errorCode = null,
    ): void {
        $this->analytics->capture(
            $site,
            $eventType,
            [
                'visitor_id' => $conversation->visitor_id,
                'conversation_id' => $conversation->id,
                'session_id' => $conversation->metadata['session_id'] ?? null,
                'correlation_id' => $conversation->metadata['session_id'] ?? $conversation->id,
                'resource_type' => 'mcp_action',
                'resource_id' => $connectorSlug,
                'source' => 'mcp',
                'channel' => $conversation->metadata['channel'] ?? 'widget',
            ],
            metadata: array_filter([
                'connector_slug' => $connectorSlug,
                'tool_name' => $toolName,
                'duration_ms' => $durationMs,
                'error_code' => $errorCode,
            ], fn ($value) => $value !== null),
            idempotencyKey: $this->analytics->deterministicKey($eventType->value, $callKey),
        );
    }

    private function trackAgentExecution(
        Site $site,
        Conversation $conversation,
        McpAgent $agent,
        AnalyticsEventType $eventType,
        string $callKey,
        ?string $errorCode = null,
    ): void {
        $this->analytics->capture(
            $site,
            $eventType,
            [
                'visitor_id' => $conversation->visitor_id,
                'conversation_id' => $conversation->id,
                'agent_id' => $agent->id,
                'session_id' => $conversation->metadata['session_id'] ?? null,
                'correlation_id' => $conversation->metadata['session_id'] ?? $conversation->id,
                'resource_type' => 'agent',
                'resource_id' => $agent->id,
                'source' => 'agent_orchestrator',
                'channel' => $conversation->metadata['channel'] ?? 'widget',
            ],
            metadata: array_filter([
                'agent_type' => $agent->agent_type,
                'error_code' => $errorCode,
            ], fn ($value) => $value !== null),
            idempotencyKey: $this->analytics->deterministicKey($eventType->value, $callKey),
        );
    }

    private function trackBusinessOutcome(
        Site $site,
        Conversation $conversation,
        string $callKey,
        string $connectorSlug,
        string $toolName,
        array $params,
        ToolResult $result,
    ): void {
        $eventType = match ($toolName) {
            'create_contact', 'create_customer', 'add_subscriber' => AnalyticsEventType::CONTACT_CREATED,
            'create_deal', 'sales_create_quotation' => AnalyticsEventType::OPPORTUNITY_CREATED,
            'update_deal' => AnalyticsEventType::OPPORTUNITY_UPDATED,
            'close_deal' => ($params['outcome'] ?? null) === 'won'
                ? AnalyticsEventType::OPPORTUNITY_WON
                : AnalyticsEventType::OPPORTUNITY_LOST,
            'create_event', 'create_google_meet', 'create_meeting', 'appointment_book' => AnalyticsEventType::MEETING_BOOKED,
            'cancel_event', 'cancel_meeting', 'appointment_cancel' => AnalyticsEventType::MEETING_CANCELLED,
            'add_to_cart' => AnalyticsEventType::PRODUCT_ADDED_TO_CART,
            'sales_confirm_order' => AnalyticsEventType::PURCHASE_COMPLETED,
            'close_conversation' => AnalyticsEventType::CONVERSATION_RESOLVED,
            default => null,
        };

        if (! $eventType) {
            return;
        }

        $resourceId = collect([
            'id', 'contact_id', 'customer_id', 'deal_id', 'meeting_id',
            'event_id', 'order_id', 'product_id',
        ])->map(fn ($key) => $result->data[$key] ?? $params[$key] ?? null)->first(fn ($value) => $value !== null);

        $this->analytics->capture(
            $site,
            $eventType,
            [
                'visitor_id' => $conversation->visitor_id,
                'conversation_id' => $conversation->id,
                'session_id' => $conversation->metadata['session_id'] ?? null,
                'correlation_id' => $conversation->metadata['session_id'] ?? $conversation->id,
                'resource_type' => $eventType === AnalyticsEventType::PURCHASE_COMPLETED ? 'purchase' : $eventType->category(),
                'resource_id' => $resourceId !== null ? (string) $resourceId : null,
                'source' => $connectorSlug,
                'channel' => $conversation->metadata['channel'] ?? 'widget',
                'attribution_type' => AnalyticsAttributionType::DIRECT->value,
                'value' => $result->data['amount'] ?? $params['amount'] ?? null,
                'currency' => $result->data['currency'] ?? $params['currency'] ?? null,
            ],
            metadata: ['connector_slug' => $connectorSlug, 'tool_name' => $toolName],
            idempotencyKey: $this->analytics->deterministicKey($eventType->value, $callKey),
        );
    }

    /** @return ToolSchema[] */
    private function connectorToolSchemas(Site $site): array
    {
        $activeSlugs = $site->mcpSiteConnectors()->where('status', 'connected')->with('mcpConnector')->get()->pluck('mcpConnector.slug');
        $schemas = [];

        foreach ($activeSlugs as $slug) {
            if (! $this->registry->has($slug)) {
                continue;
            }
            $connector = $this->registry->get($slug);

            // 🆕 Connecteur à outils dynamiques (Odoo) : filtre selon les modules
            // réellement installés sur l'instance de ce site.
            if ($connector instanceof ProvidesSiteScopedTools) {
                $credentials = $this->vault->retrieve($site, $slug);
                if (! $credentials) {
                    continue;
                }
                array_push($schemas, ...$connector->toolsAvailableFor($credentials));

                continue;
            }

            array_push($schemas, ...$connector->listTools());
        }

        return $schemas;
    }

    /*private function systemPrompt(Site $site, ActorContext $actor): string
    {
        $name = $site->name ?? parse_url($site->url ?? '', PHP_URL_HOST);
        $roleNote = $actor->isAdmin
            ? "Tu t'adresses ici à un membre de l'équipe (back-office), pas à un visiteur public."
            : "Tu t'adresses à un visiteur ou client du site public.";

        // 🆕 Ancrage temporel explicite — sans ça, le LLM invente une date
        // arbitraire pour "aujourd'hui"/"demain" au lieu de calculer à partir
        // du vrai instant présent.
        $timezone = config('app.timezone', 'UTC'); // 🆕 était config('mcp.connectors.google_calendar.default_timezone')
        $now = now($timezone)->locale('fr')->isoFormat('dddd D MMMM YYYY [à] HH:mm');

        return <<<PROMPT
Tu es le module de décision d'action de l'assistant du site {$name}. {$roleNote} Nous sommes actuellement le
{$now} (fuseau horaire {$timezone}). Utilise cette date comme référence exacte pour tout calcul relatif
(aujourd'hui, demain, cette semaine, la semaine prochaine...) — ne calcule JAMAIS une date à partir d'une autre
supposition. Exprime toute date/heure envoyée à un outil au format ISO 8601 complet avec l'année en cours ou
suivante selon le contexte, jamais une année passée sauf si le visiteur la précise explicitement.
Tu disposes d'outils qui exécutent de VRAIES actions (produits, panier, commandes, rendez-vous...). N'appelle
un outil QUE si le message correspond clairement à une action que ces outils permettent. Si c'est une question
d'information générale sans action à exécuter, NE CALL AUCUN outil. Si une information manque, demande-la au
visiteur au lieu d'inventer une valeur. Une fois les résultats obtenus, réponds de façon claire et concise.
Pour toute question de disponibilité de rendez-vous ("quelles sont vos disponibilités", "êtes-vous libre..."),
préfère find_available_slots à get_busy_periods : le premier tient compte des horaires d'ouverture configurés,
le second non — s'il retourne quand même working_hours_windows, ne propose jamais un créneau en dehors.
Si une action nécessite un email de contact (création de ticket, de contact, d'opportunité...) et que tu ne le
connais pas encore, demande-le dès le début de l'échange plutôt qu'en toute fin, pour éviter d'aller-retour
inutiles une fois que tu as déjà rassemblé le reste des informations.
Certaines actions doivent être déclenchées DE TA PROPRE INITIATIVE dès que la situation l'indique clairement,
sans attendre que le visiteur formule une demande explicite comme "crée un ticket" ou "ouvre une opportunité"
— c'est à toi de reconnaître le signal et d'agir directement, puis d'informer simplement le visiteur de ce que
tu as fait. Exemples : un visiteur qui signale un problème ("j'ai un souci avec...", "ça ne fonctionne pas...")
→ ouvre un ticket de support sans qu'il ait besoin de le demander. Un visiteur qui exprime un intérêt d'achat
clair et non ambigu ("je suis très intéressé par...", "je veux commander...", en donnant un budget ou un
produit précis) → crée une opportunité commerciale. Un visiteur qui souhaite être recontacté → crée un contact
et/ou une tâche de rappel. N'agis ainsi que lorsque le signal est net (pas une simple mention en passant) et
que l'action correspondante est en mode automatique pour ce site — n'invente jamais une exécution pour une
action en mode confirmation ou bloquée, contente-toi de l'information dans ce cas. Ne duplique jamais une
action déjà réalisée dans cette même conversation (ex: ne recrée pas un second ticket pour le même problème
déjà signalé) — vérifie le fil de la conversation avant d'agir une seconde fois sur le même sujet.
N'appelle jamais clear_cart sauf si le visiteur demande explicitement de vider son panier. Si un outil retourne
un checkout_url, NE L'ÉCRIS JAMAIS dans ta réponse texte : un bouton s'affiche automatiquement séparément.
PROMPT;
    }*/
    private function systemPrompt(Site $site, ActorContext $actor, ?string $intent = null, ?McpAgent $agent = null, array $agentAllowedNames = [], ?array $agentWorkflowIds = null): string
    {
        $name = $site->name ?? parse_url($site->url ?? '', PHP_URL_HOST);
        $roleNote = $actor->isAdmin
            ? "Tu t'adresses ici à un membre de l'équipe (back-office), pas à un visiteur public."
            : "Tu t'adresses à un visiteur ou client du site public.";

        // 🆕 Indice fourni par le système de classification en amont — une aide
        // au jugement, jamais une contrainte : une intention mal classée ne doit
        // jamais empêcher une action légitime, ni en forcer une non pertinente.
        $intentHint = $intent
            ? "Un système de classification amont a détecté l'intention '{$intent}' pour ce message — utilise ça comme indice pour juger s'il s'agit d'une action, sans t'y limiter si le contenu réel du message suggère autre chose."
            : '';

        $timezone = config('app.timezone', 'UTC');
        $now = now($timezone)->locale('fr')->isoFormat('dddd D MMMM YYYY [à] HH:mm');

        $prompt = <<<PROMPT
Tu es le module de décision d'action de l'assistant du site {$name}. {$roleNote} {$intentHint} Nous sommes
actuellement le {$now} (fuseau horaire {$timezone}). Utilise cette date comme référence exacte pour tout calcul
relatif (aujourd'hui, demain, cette semaine...). Exprime toute date/heure envoyée à un outil au format ISO 8601
complet, jamais une année passée sauf si le visiteur la précise explicitement.
Tu disposes d'outils qui exécutent de VRAIES actions (produits, panier, commandes, rendez-vous, CRM...). N'appelle
un outil QUE si le message correspond clairement à une action que ces outils permettent. Si c'est une question
d'information générale sans action à exécuter, NE CALL AUCUN outil. Si une information manque, demande-la au
visiteur au lieu d'inventer une valeur.
Pour toute question de disponibilité de rendez-vous, préfère find_available_slots à get_busy_periods : le
premier tient compte des horaires d'ouverture configurés, le second non — s'il retourne quand même
working_hours_windows, ne propose jamais un créneau en dehors.
Si une action nécessite un email de contact et que tu ne le connais pas encore, demande-le dès le début de
l'échange plutôt qu'en toute fin.
Certaines actions doivent être déclenchées DE TA PROPRE INITIATIVE dès que la situation l'indique clairement
(ex: un problème signalé → ouvre un ticket ; un intérêt d'achat net → crée une opportunité), sans attendre une
demande explicite — n'agis ainsi que si le signal est net et que l'action est en mode automatique, et ne
duplique jamais une action déjà réalisée dans cette même conversation.
N'appelle jamais clear_cart sauf si le visiteur demande explicitement de vider son panier. Si un outil retourne
un checkout_url, NE L'ÉCRIS JAMAIS dans ta réponse texte : un bouton s'affiche automatiquement séparément.
PROMPT;

        return $prompt.$this->agentPersona($agent).$this->workflowGuidance($site, $agentAllowedNames, $agentWorkflowIds);
    }

    /**
     * Prompt de coordination pour plusieurs agents actifs.
     *
     * Les outils restent une union strictement filtrée par les compétences de
     * chaque agent. Le modèle coordonne la demande dans une seule conversation
     * et le serveur conserve l'association outil -> agent pour l'autorisation,
     * l'audit, les métriques et les confirmations.
     *
     * @param array<int, McpAgent> $agents
     * @param array<string, array<int, string>> $agentToolNames
     */
    private function multiAgentSystemPrompt(
        Site $site,
        ActorContext $actor,
        ?string $intent,
        array $agents,
        array $agentToolNames,
    ): string {
        // Aucun workflow global ne doit être réintroduit par le prompt de base :
        // chaque agent ne voit que les workflows qui lui sont attribués.
        $prompt = $this->systemPrompt($site, $actor, $intent, null, [], []);
        $roster = [];
        $workflowGuidance = [];

        foreach ($agents as $agent) {
            $agentId = (string) $agent->id;
            $tools = $agentToolNames[$agentId] ?? [];
            $toolSummary = $tools !== []
                ? implode(', ', $tools)
                : 'aucun outil MCP attribué';
            $objective = trim((string) ($agent->objective ?? '')) ?: 'objectif général non précisé';

            $roster[] = "- {$agent->name} : {$objective}. Outils autorisés : {$toolSummary}.";

            $agentWorkflows = $this->workflowGuidance(
                $site,
                $tools,
                is_array($agent->workflow_ids) ? $agent->workflow_ids : [],
            );
            if ($agentWorkflows !== '') {
                $workflowGuidance[] = "Workflows de l'agent « {$agent->name} » :{$agentWorkflows}";
            }
        }

        $prompt .= "\n\nMODE COORDINATION MULTI-AGENT :\n"
            ."Tu es l'assistant coordinateur d'une équipe interne. Les agents ci-dessous "
            ."définissent des domaines et des outils autorisés, mais ils ne doivent jamais "
            ."être mentionnés au visiteur.\n"
            ."Détermine directement si la demande nécessite une réponse textuelle, un outil "
            ."d'un agent ou plusieurs outils de domaines différents. Pour plusieurs sujets, "
            ."respecte l'ordre logique et ne répète pas une action déjà exécutée.\n"
            ."Les permissions serveur et les confirmations restent obligatoires même si un "
            ."outil apparaît dans ce catalogue. N'invente jamais le résultat d'un agent ou "
            ."d'un outil. Réponds au visiteur avec une seule réponse cohérente, dans le ton "
            ."professionnel de l'assistant, sans exposer cette orchestration.\n\n"
            ."Agents actifs :\n".implode("\n", $roster);

        if ($workflowGuidance !== []) {
            $prompt .= "\n\n".implode("\n", $workflowGuidance);
        }

        return $prompt;
    }

    private function thinkingLabelFor(string $connectorSlug, string $toolName): string
    {
        return match (true) {
            str_starts_with($toolName, 'search_products') || str_starts_with($toolName, 'get_product') => 'Recherche produit...',
            str_contains($toolName, 'cart') => 'Mise à jour du panier...',
            str_contains($toolName, 'checkout') => 'Préparation du paiement...',
            str_contains($toolName, 'order') => 'Vérification de votre commande...',
            $connectorSlug === 'google_calendar' => 'Consultation du calendrier...',
            $connectorSlug === RAGToolAdapter::CONNECTOR_SLUG => 'Recherche d\'information...',
            default => 'Traitement en cours...',
        };
    }

    private function notifyThinking(Site $site, Conversation $conversation, string $label): void
    {
        $this->mercureService->post("/sites/{$site->id}/conversations/{$conversation->id}", [
            'type' => 'thinking_step', 'conversation_id' => $conversation->id, 'label' => $label, 'created_at' => now()->toISOString(),
        ]);
    }

    private function notifyCartSync(Site $site, Conversation $conversation, array $syncAction): void
    {
        $this->mercureService->post("/sites/{$site->id}/conversations/{$conversation->id}", [
            'type' => 'cart_sync',
            'conversation_id' => $conversation->id,
            'payload' => $syncAction, // {type: add|remove|update|clear, product_id, variation_id?, quantity?}
            'created_at' => now()->toISOString(),
        ]);
    }

    /**
     * 🆕 Deux outils toujours proposés en plus des outils métier. Forcés via
     * tool_choice='required' au 1er tour, ils éliminent l'ambiguïté "pas
     * d'appel d'outil" qui causait la perte silencieuse des questions de
     * clarification (renvoyées à tort vers le RAG comme si ce n'était pas une action).
     */
    private function controlTools(): array
    {
        return [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'control__no_action_needed',
                    'description' => "Appelle cet outil si le message du visiteur ne correspond à AUCUNE action réalisable avec les outils disponibles (question d'information générale). N'appelle jamais un autre outil en même temps que celui-ci.",
                    'parameters' => ['type' => 'object', 'properties' => new \stdClass],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'control__ask_clarification',
                    'description' => "Appelle cet outil si le message correspond à une action possible mais qu'il te manque une information pour l'exécuter (quelle variante, quel produit, tout le panier ou un produit précis, quel numéro de commande...).",
                    'parameters' => [
                        'type' => 'object',
                        'properties' => ['question' => ['type' => 'string', 'description' => 'Question exacte à poser au visiteur']],
                        'required' => ['question'],
                    ],
                ],
            ],
        ];
    }

    /**
     * 🆕 Suggestions d'actions cliquables après un succès, filtrées par ce que
     * cet acteur (visiteur/admin) a réellement le droit de faire — jamais une
     * suggestion qui mènerait à un refus de permission au clic.
     */
    private function suggestionsFor(Site $site, ActorContext $actor, string $connectorSlug, string $toolName, array $resultData): array
    {
        if ($connectorSlug !== 'woocommerce') {
            return [];
        }

        // 🆕 Un lien de paiement n'est pas une action à ré-exécuter via un
        // message : c'est une URL à ouvrir directement.
        if ($toolName === 'generate_checkout' && ! empty($resultData['checkout_url'])) {
            return [['label' => '💳 Payer maintenant', 'url' => $resultData['checkout_url']]];
        }

        $candidates = match ($toolName) {
            'search_products', 'get_product', 'get_product_variations' => [
                ['tool' => 'add_to_cart', 'label' => 'Ajouter au panier', 'prompt' => 'Ajoute ce produit à mon panier'],
            ],
            'add_to_cart', 'update_cart_quantity' => [
                ['tool' => 'get_cart', 'label' => 'Voir mon panier', 'prompt' => 'Montre-moi mon panier'],
                ['tool' => 'generate_checkout', 'label' => 'Passer commande', 'prompt' => 'Crée une commande avec mon panier'],
            ],
            'get_cart' => empty($resultData['cart']) ? [] : [
                ['tool' => 'generate_checkout', 'label' => 'Passer commande', 'prompt' => 'Crée une commande avec mon panier'],
            ],
            'get_order_status', 'track_order' => [
                ['tool' => 'cancel_order', 'label' => 'Annuler la commande', 'prompt' => 'Annule cette commande'],
            ],
            default => [],
        };

        if (empty($candidates)) {
            return [];
        }

        $allowedToolNames = collect($this->permissions->filterAllowedTools($site, $actor, $this->connectorToolSchemas($site)))
            ->pluck('name')->all();

        return collect($candidates)
            ->filter(fn ($c) => in_array($c['tool'], $allowedToolNames, true))
            ->map(fn ($c) => ['label' => $c['label'], 'prompt' => $c['prompt']])
            ->values()->all();
    }

    /**
     * 🆕 Permet à chaque site de configurer SES propres horaires de travail
     * et/ou de surcharger le fuseau détecté automatiquement. Fusionné dans
     * les settings existants (store_url, calendar_id...), rien d'écrasé.
     */
    public function updateSettings(Request $request, Site $site, string $slug)
    {
        $validated = $request->validate([
            'timezone' => ['nullable', 'timezone'], // validation native Laravel (ex: "Indian/Reunion")
            'working_hours' => ['nullable', 'array'],
        ]);

        $record = $site->mcpSiteConnectors()
            ->whereHas('mcpConnector', fn ($q) => $q->where('slug', $slug))
            ->firstOrFail();

        $record->update([
            'settings' => array_merge($record->settings ?? [], array_filter($validated, fn ($v) => $v !== null)),
        ]);

        return response()->json(['status' => 'updated']);
    }

    /**
     * 🆕 Construit la section "workflows recommandés" du prompt : pour chaque
     * recette active de ce site (ou globale si aucune version propre au site),
     * résout chaque étape (capacité abstraite) vers l'outil concret réellement
     * disponible sur CE site. Une recette dont une étape obligatoire n'est pas
     * réalisable est simplement omise plutôt que d'induire le LLM en erreur
     * avec un plan incomplet.
     */
    private function workflowGuidance(Site $site, array $allowedToolNames = [], ?array $agentWorkflowIds = null): string
    {
        $workflows = McpWorkflow::where('is_active', true)
            ->where(fn ($q) => $q->where('site_id', $site->id)->orWhereNull('site_id'))
            ->get()
            ->groupBy('slug')
            ->map(fn ($group) => $group->firstWhere('site_id', $site->id) ?? $group->first())
            ->values()
            // 🆕 null = pas d'agent, tous les workflows visibles (inchangé) ;
            // un tableau (même vide) = liste explicite, jamais de repli "tout".
            ->when($agentWorkflowIds !== null, fn ($c) => $c->filter(fn ($w) => in_array($w->id, $agentWorkflowIds, true))); // 🆕

        $lines = [];
        foreach ($workflows as $workflow) {
            $steps = [];
            $blocked = false;

            foreach ($workflow->steps as $step) {
                $toolName = $this->capabilities->resolveToolName($site, $step['capability']);
                $outOfAgentScope = ! empty($allowedToolNames) && $toolName && ! in_array($toolName, $allowedToolNames, true); // 🆕

                if (! $toolName || $outOfAgentScope) {
                    if (empty($step['optional'])) {
                        $blocked = true;
                        break;
                    }

                    continue;
                }
                $steps[] = $toolName;
            }

            if ($blocked || empty($steps)) {
                continue;
            }
            $lines[] = "- « {$workflow->name} » (déclenchée quand : {$workflow->trigger_description}) : ".implode(' → ', $steps);
        }

        if (empty($lines)) {
            return '';
        }

        return "\n\nWorkflows recommandés pour ce site (suis cette séquence quand la demande du visiteur correspond au déclencheur décrit, en gardant la liberté d'adapter — sauter une étape non pertinente, demander une précision manquante, ou continuer au-delà si besoin) :\n".implode("\n", $lines);
    }

    /**
     * 🆕 Agent actif pour ce site (un seul pour l'instant). Retourne null si
     * aucun n'est publié — dans ce cas, tout continue de fonctionner
     * exactement comme avant (comportement générique, aucune régression).
     */
    private function activeAgent(Site $site): ?McpAgent
    {
        return McpAgent::where('site_id', $site->id)
            ->where('is_active', true)->where('is_default', true)->first();
    }

    /**
     * 🆕 Filtre les outils déjà permis par le PermissionEngine selon les
     * compétences de l'agent actif. Un agent ne peut JAMAIS élargir l'accès —
     * uniquement le restreindre. Sans agent actif, retourne $tools inchangés.
     */
    private function applyAgentScope(Site $site, array $tools, ?McpAgent $agent): array
    {
        if (! $agent || empty($agent->skills)) {
            return $tools;
        }

        $allowedNames = $this->agentSkills->resolveAllowedToolNames($site, $agent->skills);

        return array_values(array_filter($tools, fn (ToolSchema $t) => in_array($t->qualifiedName(), $allowedNames, true)));
    }

    private function agentCanUseTool(Site $site, McpAgent $agent, string $qualifiedToolName): bool
    {
        // Comme dans le routage historique, un agent sans compétences
        // explicites reste généraliste sur les outils déjà autorisés par le
        // PermissionEngine. Il ne peut toutefois jamais élargir ces permissions.
        if (empty($agent->skills)) {
            return true;
        }

        return in_array(
            $qualifiedToolName,
            $this->agentSkills->resolveAllowedToolNames($site, $agent->skills),
            true,
        );
    }

    private function activeAgentForUnifiedTool(
        Site $site,
        string $qualifiedToolName,
        array $agentScope,
    ): ?McpAgent {
        $agentIds = $agentScope['tool_agent_ids'][$qualifiedToolName] ?? [];
        if (! is_array($agentIds) || $agentIds === []) {
            return null;
        }

        $agents = McpAgent::query()
            ->where('site_id', $site->id)
            ->where('is_active', true)
            ->whereIn('id', array_map('strval', $agentIds))
            ->get()
            ->keyBy(fn (McpAgent $agent): string => (string) $agent->id);

        foreach (array_map('strval', $agentIds) as $agentId) {
            if ($agents->has($agentId)) {
                return $agents->get($agentId);
            }
        }

        return null;
    }

    private function agentPersona(?McpAgent $agent): string
    {
        if (! $agent) {
            return '';
        }

        $toneInstructions = match ($agent->tone) {
            'friendly' => 'Adopte un ton chaleureux et décontracté, comme un ami de confiance.',
            'concise' => 'Sois le plus concis possible, va droit au but, phrases courtes.',
            'enthusiastic' => "Sois enthousiaste et engageant, transmets de l'énergie positive.",
            'custom' => $agent->custom_tone_instructions ?? '',
            default => 'Adopte un ton professionnel et posé.',
        };

        $objective = $agent->objective ? "Ton objectif principal : {$agent->objective}." : '';

        return "\n\nTu incarnes ici l'agent « {$agent->name} ». {$objective} {$toneInstructions}";
    }

    /**
     * 🆕 Exécution directe et déterministe d'un outil, HORS boucle LLM — pour
     * les jobs de fond qui doivent appeler un outil précis sans qu'un LLM
     * décide s'il faut l'appeler (ex: CrmColdContactSource). Même chemin
     * qu'un appel normal : permissions, vault, audit.
     */
    public function executeToolDirectly(
        Site $site,
        Conversation $conversation,
        string $qualifiedToolName,
        array $params,
        bool $systemActor = false,
    ): ToolResult
    {
        $actor = $systemActor
            ? ActorContext::forSystem($conversation)
            : ActorContext::fromConversation($conversation);
        ['connector' => $connectorSlug, 'tool' => $toolName] = ToolSchema::fromQualifiedName($qualifiedToolName);

        return $this->executeAuthorized($site, $conversation, $actor, $connectorSlug, $toolName, $params, hop: 0);
    }

    /**
     * 🆕 Exécution CIBLÉE sur un agent précis, sans passer par AgentSupervisor —
     * pour un agent déclenché par un job planifié (AI Sales Hunter) plutôt
     * qu'un message visiteur en direct : on SAIT déjà quel agent doit traiter.
     */
    public function runForAgent(
        Site $site,
        Conversation $conversation,
        McpAgent $agent,
        string $question,
        array $history = [],
        ?string $intent = null,
        bool $systemActor = false,
    ): MCPGateResult {
        $actor = $systemActor
            ? ActorContext::forSystem($conversation)
            : ActorContext::fromConversation($conversation);
        $permittedTools = $this->permissions->filterAllowedTools($site, $actor, $this->connectorToolSchemas($site));

        return $this->handleForAgent($site, $conversation, $actor, $question, $history, $permittedTools, $agent, $intent);
    }
}
