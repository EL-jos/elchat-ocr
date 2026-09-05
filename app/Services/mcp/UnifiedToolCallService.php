<?php

namespace App\Services\mcp;

use App\Domain\MCP\Contracts\ToolResult;
use App\Domain\MCP\Contracts\ToolSchema;
use App\Models\Conversation;
use App\Models\Mcp\McpAgent;
use App\Models\Site;
use App\Services\hops\LLMService;
use Illuminate\Support\Facades\Log;
use JsonException;
use Throwable;

/**
 * Orchestrateur texte + function calling pour le flux visiteur.
 *
 * Il n'exécute aucune logique MCP lui-même : le catalogue et l'exécution sont
 * délégués à MCPActionGateService, qui reste l'unique porte d'entrée pour les
 * permissions, confirmations, audit et effets secondaires métier.
 */
class UnifiedToolCallService
{
    private int $maxHops;

    /** @var array<string, array<string, mixed>|null> */
    private array $contextCache = [];

    public function __construct(
        private readonly LLMService $llm,
        private readonly MCPActionGateService $gate,
    ) {
        $this->maxHops = max(1, (int) config('mcp.orchestrator.unified_max_hops', 6));
    }

    /**
     * Exécute la génération finale avec les outils MCP autorisés.
     *
     * Retourne null lorsque le mode unifié ne doit pas s'appliquer (MCP
     * désactivé ou aucun outil autorisé). Le contexte multi-agent est supporté
     * directement par MCPActionGateService.
     *
     * @param  array{system?: string, messages?: array, max_tokens?: int}|null  $prompt
     */
    public function respond(
        Site $site,
        Conversation $conversation,
        ?array $prompt,
        string $question,
        array $history = [],
        ?string $intent = null,
    ): ?UnifiedToolCallResult {
        $contextKey = implode(':', [
            (string) $site->id,
            (string) $conversation->id,
            (string) ($intent ?? ''),
        ]);
        if (! array_key_exists($contextKey, $this->contextCache)) {
            $this->contextCache[$contextKey] = $this->gate->unifiedToolContext($site, $conversation, $intent);
        }

        $context = $this->contextCache[$contextKey];

        if ($context === null) {
            return null;
        }

        $messages = $this->buildMessages($prompt, $context['system_prompt'], $question, $history);
        $settings = $site->settings;
        $options = [
            'task' => 'chat',
            'temperature' => (float) ($settings->ai_temperature ?? 0.2),
            'max_tokens' => (int) ($prompt['max_tokens'] ?? $settings->ai_max_tokens ?? 350),
            'parallel_tool_calls' => false,
        ];

        $trace = [];
        $suggestedActions = [];
        /** @var array<string, ToolResult> $executedCalls */
        $executedCalls = [];
        $toolWasUsed = false;

        for ($hop = 1; $hop <= $this->maxHops; $hop++) {
            if ($hop === 1) {
                $this->gate->notifyUnifiedThinking($site, $conversation, 'Préparation de la réponse...');
            }

            try {
                $llmResponse = $this->llm->chatWithTools(
                    $messages,
                    $context['tools'],
                    [...$options, 'tool_choice' => 'auto'],
                );
            } catch (Throwable $exception) {
                Log::error('MCP unified tool calling failed before tool execution', [
                    'site_id' => $site->id,
                    'conversation_id' => $conversation->id,
                    'error' => $exception->getMessage(),
                ]);

                return new UnifiedToolCallResult(
                    status: $toolWasUsed ? UnifiedToolCallResult::FAILED_AFTER_TOOL : UnifiedToolCallResult::FAILED,
                    message: $toolWasUsed
                        ? 'Je n’ai pas pu finaliser cette demande automatiquement. Un conseiller va prendre le relais.'
                        : null,
                    suggestedActions: $suggestedActions,
                    trace: $trace,
                );
            }

            $toolCalls = $llmResponse['tool_calls'] ?? [];
            if (! is_array($toolCalls) || $toolCalls === []) {
                $text = trim((string) ($llmResponse['text'] ?? ''));

                if ($text === '') {
                    Log::warning('MCP unified tool calling returned neither text nor tool call', [
                        'site_id' => $site->id,
                        'conversation_id' => $conversation->id,
                        'hop' => $hop,
                    ]);

                    return new UnifiedToolCallResult(
                        status: $toolWasUsed ? UnifiedToolCallResult::FAILED_AFTER_TOOL : UnifiedToolCallResult::FAILED,
                        message: $toolWasUsed
                            ? 'Je n’ai pas pu finaliser cette demande automatiquement. Un conseiller va prendre le relais.'
                            : null,
                        suggestedActions: $suggestedActions,
                        trace: $trace,
                    );
                }

                return new UnifiedToolCallResult(
                    status: $toolWasUsed ? UnifiedToolCallResult::HANDLED : UnifiedToolCallResult::TEXT,
                    message: $text,
                    suggestedActions: $suggestedActions,
                    trace: $trace,
                );
            }

            $normalizedCalls = $this->normalizeToolCalls($toolCalls, $context['allowed_tool_names']);
            if ($normalizedCalls === null) {
                // Fail closed : si un même message contient un appel invalide,
                // aucun appel valide de ce message ne doit être exécuté.
                return new UnifiedToolCallResult(
                    status: $toolWasUsed ? UnifiedToolCallResult::FAILED_AFTER_TOOL : UnifiedToolCallResult::FAILED,
                    message: $toolWasUsed
                        ? 'Je n’ai pas pu finaliser cette demande automatiquement. Un conseiller va prendre le relais.'
                        : null,
                    suggestedActions: $suggestedActions,
                    trace: $trace,
                );
            }

            if (count($normalizedCalls) === 1 && $normalizedCalls[0]['name'] === 'control__ask_clarification') {
                return new UnifiedToolCallResult(
                    status: UnifiedToolCallResult::CLARIFICATION,
                    message: $normalizedCalls[0]['arguments']['question'],
                    suggestedActions: $suggestedActions,
                    trace: $trace,
                );
            }

            // Ce contrôle ne fait pas partie du catalogue unifié, mais on
            // traite explicitement une éventuelle émission par le provider.
            if (array_filter($normalizedCalls, static fn (array $call): bool => $call['name'] === 'control__ask_clarification') !== []) {
                Log::warning('MCP unified tool calling received mixed clarification and action calls', [
                    'site_id' => $site->id,
                    'conversation_id' => $conversation->id,
                ]);

                return new UnifiedToolCallResult(
                    status: $toolWasUsed ? UnifiedToolCallResult::FAILED_AFTER_TOOL : UnifiedToolCallResult::FAILED,
                    message: $toolWasUsed
                        ? 'Je n’ai pas pu finaliser cette demande automatiquement. Un conseiller va prendre le relais.'
                        : null,
                    suggestedActions: $suggestedActions,
                    trace: $trace,
                );
            }

            $rawAssistantMessage = $this->assistantMessage($llmResponse['raw_message'] ?? null);
            if ($rawAssistantMessage === null) {
                return new UnifiedToolCallResult(
                    status: $toolWasUsed ? UnifiedToolCallResult::FAILED_AFTER_TOOL : UnifiedToolCallResult::FAILED,
                    message: $toolWasUsed
                        ? 'Je n’ai pas pu finaliser cette demande automatiquement. Un conseiller va prendre le relais.'
                        : null,
                    suggestedActions: $suggestedActions,
                    trace: $trace,
                );
            }

            // L'assistant et tous ses tool_calls doivent être persistés dans le
            // snapshot de confirmation, exactement dans l'ordre provider.
            $rawAssistantMessage['tool_calls'] = $this->assistantToolCalls(
                $normalizedCalls,
                is_array($rawAssistantMessage['tool_calls'] ?? null) ? $rawAssistantMessage['tool_calls'] : [],
            );
            $messages[] = $rawAssistantMessage;

            foreach ($normalizedCalls as $call) {
                $toolWasUsed = true;
                $qualifiedName = $call['name'];
                ['connector' => $connectorSlug, 'tool' => $toolName] = ToolSchema::fromQualifiedName($qualifiedName);
                $signature = $connectorSlug.'.'.$toolName.':'.md5($this->encode($call['arguments']));
                $agent = $this->agentForTool($context, $qualifiedName);

                if (isset($executedCalls[$signature])) {
                    $result = $executedCalls[$signature];
                    $trace[] = [
                        'hop' => $hop,
                        'connector' => $connectorSlug,
                        'tool' => $toolName,
                        'status' => 'duplicate_blocked',
                    ];
                    Log::warning("MCP unified: appel dupliqué bloqué ({$signature}) pour le site {$site->id}");
                    $messages[] = $this->toolMessage($call['id'], $result);

                    continue;
                }

                if ($hop === 1) {
                    $this->gate->notifyUnifiedThinking(
                        $site,
                        $conversation,
                        $this->gate->unifiedThinkingLabel($connectorSlug, $toolName),
                    );
                }

                $execution = $this->gate->executeUnifiedToolCall(
                    site: $site,
                    conversation: $conversation,
                    qualifiedToolName: $qualifiedName,
                    params: $call['arguments'],
                    allowedToolNames: $context['allowed_tool_names'],
                    hop: $hop,
                    agent: $agent,
                );
                $executionStatus = (string) ($execution['status'] ?? 'error');
                $trace[] = [
                    'hop' => $hop,
                    'connector' => $connectorSlug,
                    'tool' => $toolName,
                    'status' => $executionStatus,
                ];

                if ($executionStatus === 'awaiting_confirmation') {
                    $pending = $this->gate->createUnifiedPendingAction(
                        site: $site,
                        conversation: $conversation,
                        connectorSlug: $connectorSlug,
                        toolName: $toolName,
                        params: $call['arguments'],
                        confirmActor: (string) ($execution['confirm_actor'] ?? 'admin'),
                        toolCallId: $call['id'],
                        messagesSnapshot: $messages,
                        agent: $agent,
                        agentScopeSnapshot: $context['agent_scope_snapshot'] ?? [],
                    );

                    return new UnifiedToolCallResult(
                        status: UnifiedToolCallResult::AWAITING_CONFIRMATION,
                        pendingAction: $pending,
                        suggestedActions: $suggestedActions,
                        trace: $trace,
                    );
                }

                $result = $execution['result'] ?? ToolResult::fail(
                    'invalid_tool_result',
                    'Le service n’a pas retourné de résultat exploitable.',
                );
                if (! $result instanceof ToolResult) {
                    $result = ToolResult::fail('invalid_tool_result', 'Le service n’a pas retourné de résultat exploitable.');
                }

                if ($executionStatus === 'success' && $result->success) {
                    $executedCalls[$signature] = $result;
                    $suggestedActions = array_merge(
                        $suggestedActions,
                        is_array($execution['suggested_actions'] ?? null) ? $execution['suggested_actions'] : [],
                    );
                }

                $messages[] = $this->toolMessage($call['id'], $result);
            }
        }

        // Un dernier passage texte limite le risque de laisser le visiteur sans
        // explication si une chaîne d'actions atteint la limite de hops.
        Log::warning("MCP unified: nombre maximum de hops atteint pour le site {$site->id}");
        try {
            $finalResponse = $this->llm->chatWithTools(
                $messages,
                $context['tools'],
                [...$options, 'tool_choice' => 'none'],
            );
            $finalText = trim((string) ($finalResponse['text'] ?? ''));
        } catch (Throwable $exception) {
            Log::error('MCP unified: échec de la synthèse finale', [
                'site_id' => $site->id,
                'conversation_id' => $conversation->id,
                'error' => $exception->getMessage(),
            ]);
            $finalText = '';
        }

        return new UnifiedToolCallResult(
            status: UnifiedToolCallResult::HANDLED,
            message: $finalText !== ''
                ? $finalText
                : 'Je n’ai pas pu finaliser cette action automatiquement. Un conseiller va prendre le relais.',
            suggestedActions: $suggestedActions,
            trace: $trace,
        );
    }

    /** @return array<int, array<string, mixed>> */
    private function buildMessages(?array $prompt, string $unifiedSystemPrompt, string $question, array $history): array
    {
        if ($prompt !== null) {
            return [
                ['role' => 'system', 'content' => trim((string) ($prompt['system'] ?? '')."\n\n{$unifiedSystemPrompt}")],
                ...(is_array($prompt['messages'] ?? null) ? $prompt['messages'] : []),
            ];
        }

        $messages = $history;
        $lastMessage = $messages !== [] ? $messages[array_key_last($messages)] : null;
        if (! is_array($lastMessage) || ($lastMessage['role'] ?? null) !== 'user' || ($lastMessage['content'] ?? null) !== $question) {
            $messages[] = ['role' => 'user', 'content' => $question];
        }

        return [
            ['role' => 'system', 'content' => $unifiedSystemPrompt],
            ...$messages,
        ];
    }

    /**
     * @param  array<int, mixed>  $toolCalls
     * @param  array<int, string>  $allowedToolNames
     * @return array<int, array{id: string, name: string, arguments: array<string, mixed>}>|null
     */
    private function normalizeToolCalls(array $toolCalls, array $allowedToolNames): ?array
    {
        $normalized = [];

        foreach ($toolCalls as $toolCall) {
            if (! is_array($toolCall)) {
                return null;
            }

            $id = $toolCall['id'] ?? null;
            $function = $toolCall['function'] ?? null;
            $name = is_array($function) ? ($function['name'] ?? null) : null;
            $rawArguments = is_array($function) ? ($function['arguments'] ?? '{}') : null;

            if (! is_string($id) || trim($id) === '' || ! is_string($name) || trim($name) === '') {
                return null;
            }

            if ($name === 'control__ask_clarification') {
                // Ce contrôle est toujours exposé et reste le seul tool non
                // métier admis dans la branche unifiée.
            } elseif (! in_array($name, $allowedToolNames, true)) {
                Log::warning('MCP unified: tool_call refusé car absent du catalogue autorisé', [
                    'tool' => $name,
                ]);

                return null;
            }

            try {
                $arguments = is_array($rawArguments)
                    ? $rawArguments
                    : json_decode((string) $rawArguments, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return null;
            }

            if (! is_array($arguments)) {
                return null;
            }

            if ($name === 'control__ask_clarification' && trim((string) ($arguments['question'] ?? '')) === '') {
                return null;
            }

            $normalized[] = [
                'id' => $id,
                'name' => $name,
                'arguments' => $arguments,
            ];
        }

        return $normalized !== [] ? $normalized : null;
    }

    /** @return array<string, mixed>|null */
    private function assistantMessage(mixed $message): ?array
    {
        if (! is_array($message)) {
            return null;
        }

        $message['role'] = 'assistant';

        return $message;
    }

    /**
     * Reconstruit un format assistant strict même si un provider ou un fake
     * de test omet type/function.arguments dans raw_message.
     *
     * @param  array<int, array{id: string, name: string, arguments: array<string, mixed>}>  $calls
     * @param  array<int, mixed>  $rawCalls
     * @return array<int, array<string, mixed>>
     */
    private function assistantToolCalls(array $calls, array $rawCalls): array
    {
        $rawById = [];
        foreach ($rawCalls as $rawCall) {
            if (is_array($rawCall) && is_string($rawCall['id'] ?? null)) {
                $rawById[$rawCall['id']] = $rawCall;
            }
        }

        return array_map(function (array $call) use ($rawById): array {
            $rawCall = $rawById[$call['id']] ?? [];
            $rawCall['id'] = $call['id'];
            $rawCall['type'] = 'function';
            $rawCall['function'] = [
                'name' => $call['name'],
                'arguments' => $this->encode($call['arguments']),
            ];

            return $rawCall;
        }, $calls);
    }

    /** @return array{role: string, tool_call_id: string, content: string} */
    private function toolMessage(string $toolCallId, ToolResult $result): array
    {
        return [
            'role' => 'tool',
            'tool_call_id' => $toolCallId,
            'content' => $this->encode($result->toArrayForLLM()),
        ];
    }

    private function encode(array $value): string
    {
        try {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return '{}';
        }
    }

    /**
     * Résout l'agent propriétaire d'un outil à partir du catalogue construit
     * côté serveur. Le modèle ne reçoit jamais un identifiant d'agent à faire
     * respecter : cette association reste donc déterministe et non falsifiable.
     */
    private function agentForTool(array $context, string $qualifiedToolName): ?McpAgent
    {
        $agentIds = $context['tool_agent_ids'][$qualifiedToolName] ?? [];
        $agents = $context['agents'] ?? [];

        if (! is_array($agentIds) || ! is_array($agents)) {
            return ($context['agent'] ?? null) instanceof McpAgent ? $context['agent'] : null;
        }

        $agentIds = array_map('strval', $agentIds);
        foreach ($agents as $agent) {
            if ($agent instanceof McpAgent && in_array((string) $agent->id, $agentIds, true)) {
                return $agent;
            }
        }

        return ($context['agent'] ?? null) instanceof McpAgent ? $context['agent'] : null;
    }
}
