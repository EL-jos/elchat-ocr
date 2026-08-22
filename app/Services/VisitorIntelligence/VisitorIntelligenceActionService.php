<?php

namespace App\Services\VisitorIntelligence;

use App\Domain\Proactive\ProactiveSequenceService;
use App\Services\mcp\MCPActionGateService;
use App\Models\Conversation;
use App\Models\Mcp\McpAgent;
use App\Models\VisitorIntelligenceAction;
use App\Models\VisitorOpportunity;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class VisitorIntelligenceActionService
{
    public function __construct(
        private readonly ProactiveSequenceService $proactive,
        private readonly VisitorIntelligenceRealtimeService $realtime,
    )
    {
    }

    public function execute(VisitorIntelligenceAction $action): VisitorIntelligenceAction
    {
        $action->loadMissing(['site', 'session']);
        if ($action->status === 'completed') return $action;
        if ($action->approval_required && $action->status !== 'approved') {
            throw new RuntimeException('Cette action nécessite une validation humaine.');
        }

        $action->update(['status' => 'executing', 'failure_reason' => null]);

        try {
            $result = match ($action->action_type) {
                'create_opportunity' => $this->createOpportunity($action),
                'proactive_campaign' => $this->scheduleProactiveCampaign($action),
                'workflow' => $this->scheduleWorkflowCampaign($action),
                'mcp' => $this->executeMcpTool($action),
                'agent' => $this->executeAgent($action),
                'human_review' => ['status' => 'review_required', 'message' => 'Action conservée pour traitement humain.'],
                default => throw new RuntimeException('Type d’action Visitor Intelligence inconnu.'),
            };

            $action->update(['status' => 'completed', 'result' => $result, 'executed_at' => now()]);
            $this->realtime->publish((string) $action->site_id, 'action_updated', [
                'action_id' => (string) $action->id,
                'status' => 'completed',
                'session_id' => $action->visitor_session_id,
            ]);
            Log::info('Visitor Intelligence action completed.', [
                'site_id' => $action->site_id, 'action_id' => $action->id, 'action_type' => $action->action_type,
            ]);
        } catch (\Throwable $exception) {
            $action->update(['status' => 'failed', 'failure_reason' => $exception->getMessage(), 'executed_at' => now()]);
            $this->realtime->publish((string) $action->site_id, 'action_updated', [
                'action_id' => (string) $action->id,
                'status' => 'failed',
                'session_id' => $action->visitor_session_id,
            ]);
            Log::warning('Visitor Intelligence action failed.', [
                'site_id' => $action->site_id, 'action_id' => $action->id, 'action_type' => $action->action_type,
                'error' => $exception->getMessage(),
            ]);
            throw $exception;
        }

        return $action->fresh();
    }

    private function createOpportunity(VisitorIntelligenceAction $action): array
    {
        $payload = $action->payload ?? [];
        if ($action->opportunity_id) return ['opportunity_id' => $action->opportunity_id, 'created' => false];

        $session = $action->session;
        $opportunity = VisitorOpportunity::query()->firstOrCreate(
            ['site_id' => $action->site_id, 'deduplication_key' => hash('sha256', 'autopilot|'.$action->id)],
            [
                'account_id' => $action->account_id, 'visitor_session_id' => $session?->id,
                'visitor_id' => $session?->visitor_id, 'type' => $payload['opportunity_type'] ?? 'autopilot_signal',
                'title' => $payload['title'] ?? 'Opportunité détectée par Visitor Intelligence',
                'description' => $payload['description'] ?? 'Créée à partir d’une règle approuvée.',
                'evidence' => $payload['evidence'] ?? ['action_id' => $action->id],
                'impact' => $payload['impact'] ?? 'medium', 'priority' => $payload['priority'] ?? 'medium',
                'confidence' => isset($payload['confidence']) ? (float) $payload['confidence'] : null,
                'recommendations' => $payload['recommendations'] ?? [], 'actions' => $payload['actions'] ?? [],
                'status' => 'open', 'detected_at' => now(),
            ],
        );

        $action->update(['opportunity_id' => $opportunity->id]);
        return ['opportunity_id' => $opportunity->id, 'created' => $opportunity->wasRecentlyCreated];
    }

    private function scheduleProactiveCampaign(VisitorIntelligenceAction $action): array
    {
        $payload = $action->payload ?? [];
        $campaign = \App\Models\Proactive\ProactiveCampaign::query()
            ->where('site_id', $action->site_id)->whereKey($payload['campaign_id'] ?? '')->firstOrFail();
        $session = $action->session;
        $conversationId = $payload['conversation_id'] ?? null;
        $conversation = $conversationId
            ? Conversation::query()->where('site_id', $action->site_id)->whereKey($conversationId)->first()
            : ($session?->visitor_id
                ? Conversation::query()->where('site_id', $action->site_id)->where('visitor_id', $session->visitor_id)->latest()->first()
                : null);
        if (!$conversation) throw new RuntimeException('Le moteur proactif exige une conversation ELChat existante pour le canal website.');

        $message = $this->proactive->scheduleManual($campaign, [
            'conversation_id' => $conversation->id,
            'visitor_id' => $session?->visitor_id,
            'scheduled_at' => $payload['scheduled_at'] ?? now()->toISOString(),
        ], $payload['content'] ?? null, hash('sha256', 'visitor-action|'.$action->id));

        return ['proactive_message_id' => $message->id, 'campaign_id' => $campaign->id, 'conversation_id' => $conversation->id];
    }

    private function scheduleWorkflowCampaign(VisitorIntelligenceAction $action): array
    {
        if (empty(data_get($action->payload, 'campaign_id'))) {
            throw new RuntimeException('Un workflow Visitor Intelligence doit référencer une campagne proactive existante.');
        }

        // Les workflows restent exécutés par la séquence proactive existante,
        // qui applique déjà les dépendances, caps, horaires et permissions.
        return $this->scheduleProactiveCampaign($action);
    }

    private function executeMcpTool(VisitorIntelligenceAction $action): array
    {
        $payload = $action->payload ?? [];
        $qualifiedTool = (string) ($payload['qualified_tool_name'] ?? '');
        if (!preg_match('/^[a-z0-9_-]+__[a-z0-9_-]+$/i', $qualifiedTool)) {
            throw new RuntimeException('Une action MCP doit référencer un outil qualifié existant.');
        }

        $conversation = $this->conversationForAction($action, $payload);
        $result = app(MCPActionGateService::class)->executeToolDirectly(
            $action->site,
            $conversation,
            $qualifiedTool,
            is_array($payload['params'] ?? null) ? $payload['params'] : [],
        );
        if (!$result->success) {
            throw new RuntimeException($result->errorMessage ?: 'L’outil MCP a refusé l’action.');
        }

        return ['qualified_tool_name' => $qualifiedTool, 'data' => $result->data, 'summary' => $result->humanSummary];
    }

    private function executeAgent(VisitorIntelligenceAction $action): array
    {
        $payload = $action->payload ?? [];
        $agent = McpAgent::query()->where('site_id', $action->site_id)->where('is_active', true)->find($payload['agent_id'] ?? null);
        if (!$agent) throw new RuntimeException('L’agent demandé est absent, inactif ou hors périmètre du site.');

        $conversation = $this->conversationForAction($action, $payload);
        $question = trim((string) ($payload['question'] ?? ''));
        if ($question === '') throw new RuntimeException('Une action agent doit fournir une instruction approuvée.');

        $result = app(MCPActionGateService::class)->runForAgent($action->site, $conversation, $agent, $question);
        if ($result->status !== 'finished' || !$result->response) {
            throw new RuntimeException('L’agent n’a pas terminé sans étape de confirmation supplémentaire.');
        }

        return ['agent_id' => $agent->id, 'message' => $result->response->message, 'trace' => $result->trace];
    }

    private function conversationForAction(VisitorIntelligenceAction $action, array $payload): Conversation
    {
        $conversationId = $payload['conversation_id'] ?? null;
        $conversation = $conversationId
            ? Conversation::query()->where('site_id', $action->site_id)->find($conversationId)
            : ($action->session?->visitor_id
                ? Conversation::query()->where('site_id', $action->site_id)->where('visitor_id', $action->session->visitor_id)->latest()->first()
                : null);
        if (!$conversation) throw new RuntimeException('Une conversation ELChat existante est requise pour cette action.');
        return $conversation;
    }
}
