<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Concerns\AuthorizesSiteAccess;
use App\Http\Controllers\Controller;
use App\Jobs\VisitorIntelligence\ExecuteVisitorIntelligenceActionJob;
use App\Models\Site;
use App\Models\VisitorSession;
use App\Models\VisitorIntelligenceAction;
use App\Models\VisitorIntelligenceRule;
use App\Services\VisitorIntelligence\VisitorIntelligenceActionService;
use App\Services\VisitorIntelligence\VisitorIntelligenceFrameService;
use App\Services\VisitorIntelligence\VisitorIntelligenceQueryService;
use App\Services\VisitorIntelligence\VisitorIntelligenceRealtimeService;
use App\Services\VisitorIntelligence\VisitorIntelligenceReplayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class VisitorIntelligenceController extends Controller
{
    use AuthorizesSiteAccess;

    public function __construct(
        private readonly VisitorIntelligenceQueryService $query,
        private readonly VisitorIntelligenceActionService $actions,
        private readonly VisitorIntelligenceFrameService $frames,
        private readonly VisitorIntelligenceRealtimeService $realtime,
        private readonly VisitorIntelligenceReplayService $replays,
    ) {}

    public function overview(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        return response()->json(['data' => $this->query->overview($site, $this->filters($request))]);
    }

    public function sessions(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        return response()->json($this->query->sessions($site, $this->filters($request)));
    }

    public function visitors(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        return response()->json(['data' => $this->query->visitors($site, $this->filters($request))]);
    }

    public function session(Request $request, Site $site, string $session): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        return response()->json(['data' => $this->query->sessionDetail($site, $session)]);
    }

    public function replay(Request $request, Site $site, string $session): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        $detail = $this->query->sessionDetail($site, $session);
        $rrweb = $this->replays->metadataForSession($site, $detail['session']);
        return response()->json(['data' => [
            'session' => $detail['session'], 'summary' => $detail['summary'],
            'timeline' => $detail['timeline'], 'conversations' => $detail['conversations'],
            'rrweb' => [
                'available' => $rrweb['available'],
                'chunks' => $rrweb['chunks'],
                'chunk_indexes' => $rrweb['chunk_indexes'],
                'event_count' => $rrweb['event_count'],
                'version' => $rrweb['version'],
            ],
            'mode' => $rrweb['available'] ? 'rrweb_dom_replay' : 'event_replay',
            'privacy' => 'sensitive_fields_are_excluded_or_masked',
        ]])->header('Cache-Control', 'private, no-store');
    }

    public function replayChunk(Request $request, Site $site, string $session, int $chunk): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        $model = VisitorSession::query()
            ->where('site_id', $site->id)
            ->findOrFail($session);

        return response()->json([
            'data' => $this->replays->chunkForSession($site, $model, $chunk),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function journey(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        return response()->json(['data' => $this->query->journey($site, $this->filters($request))]);
    }

    public function opportunities(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        return response()->json($this->query->opportunities($site, $this->filters($request)));
    }

    public function actions(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        return response()->json($this->query->actions($site, $this->filters($request)));
    }

    public function rules(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        return response()->json(['data' => $this->query->rules($site)]);
    }

    public function storeRule(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        $data = $this->validateRule($request, false);
        $rule = VisitorIntelligenceRule::query()->create([
            ...$data, 'account_id' => $site->account_id, 'site_id' => $site->id,
            'created_by' => $request->user()?->id,
        ]);
        $this->realtime->publish((string) $site->id, 'rule_updated', ['rule_id' => (string) $rule->id]);
        return response()->json(['data' => $rule], 201);
    }

    public function updateRule(Request $request, Site $site, string $rule): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        $model = VisitorIntelligenceRule::query()->where('site_id', $site->id)->findOrFail($rule);
        $model->update($this->validateRule($request, true));
        $this->realtime->publish((string) $site->id, 'rule_updated', ['rule_id' => (string) $model->id]);
        return response()->json(['data' => $model->fresh()]);
    }

    public function destroyRule(Request $request, Site $site, string $rule): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        $model = VisitorIntelligenceRule::query()->where('site_id', $site->id)->findOrFail($rule);
        $model->delete();
        $this->realtime->publish((string) $site->id, 'rule_deleted', ['rule_id' => (string) $rule]);
        return response()->json(['status' => 'deleted']);
    }

    public function approveAction(Request $request, Site $site, string $action): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);
        $model = VisitorIntelligenceAction::query()->where('site_id', $site->id)->findOrFail($action);
        abort_unless(in_array($model->status, ['pending', 'queued'], true), 409, 'Cette action ne peut plus être approuvée.');
        $model->update([
            'status' => 'approved', 'approved_by' => $request->user()?->id,
            'approved_at' => now(), 'decision_reason' => $data['reason'] ?? null,
        ]);
        $this->realtime->publish((string) $site->id, 'action_updated', [
            'action_id' => (string) $model->id,
            'status' => 'approved',
            'session_id' => $model->visitor_session_id,
        ]);
        ExecuteVisitorIntelligenceActionJob::dispatch($model->id);
        return response()->json(['data' => $model->fresh()]);
    }

    public function executeAction(Request $request, Site $site, string $action): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        $model = VisitorIntelligenceAction::query()->where('site_id', $site->id)->findOrFail($action);
        abort_unless(!$model->approval_required || $model->status === 'approved', 422, 'Validation humaine requise avant exécution.');
        try {
            return response()->json(['data' => $this->actions->execute($model)]);
        } catch (\Throwable $exception) {
            return response()->json(['message' => $exception->getMessage(), 'data' => $model->fresh()], 422);
        }
    }

    public function deleteSession(Request $request, Site $site, string $session): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);
        $model = \App\Models\VisitorSession::query()->where('site_id', $site->id)->findOrFail($session);
        \Illuminate\Support\Facades\DB::transaction(function () use ($site, $model) {
            // resource_events is the shared Event Intelligence stream; delete
            // only the rows carrying this site/session boundary.
            $this->frames->deleteForQuery(\App\Models\AnalyticsEvent::query()
                ->where('site_id', $site->id)
                ->where('session_id', $model->session_key)
                ->where('source', 'visitor_intelligence'));
            // Session-specific derived records may use nullOnDelete to survive
            // ordinary model deletion, but an explicit admin erasure must
            // remove the complete journey projection as well.
            \App\Models\VisitorIntelligenceAction::query()->where('visitor_session_id', $model->id)->delete();
            \App\Models\VisitorOpportunity::query()->where('visitor_session_id', $model->id)->delete();
            $model->delete();
        });
        $this->realtime->publish((string) $site->id, 'session_deleted', [
            'session_id' => (string) $model->id,
            'visitor_id' => $model->visitor_id,
        ]);
        return response()->json(['status' => 'deleted']);
    }

    private function filters(Request $request): array
    {
        return $request->validate([
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'],
            'device' => ['nullable', Rule::in(['desktop', 'mobile', 'tablet'])],
            'source' => ['nullable', 'string', 'max:64'], 'page' => ['nullable', 'string', 'max:1024'],
            'intent' => ['nullable', Rule::in(['low', 'medium', 'high'])],
            'visitor_type' => ['nullable', Rule::in(['new', 'returning'])],
            'with_elchat' => ['nullable', 'boolean'], 'converted' => ['nullable', 'boolean'],
            'visitor_id' => ['nullable', 'uuid'],
            'per_page' => ['nullable', 'integer', 'between:10,100'],
        ]);
    }

    private function validateRule(Request $request, bool $update): array
    {
        $required = $update ? 'sometimes' : 'required';
        return $request->validate([
            'name' => [$required, 'string', 'max:255'],
            'trigger' => [$required, 'string', 'max:64'],
            'conditions' => ['nullable', 'array', 'max:20'],
            'action' => [$required, 'array', 'max:30'],
            'action.type' => ['required_with:action', Rule::in(['create_opportunity', 'proactive_campaign', 'workflow', 'agent', 'mcp', 'human_review'])],
            'action.condition_mode' => ['sometimes', Rule::in(['all', 'any'])],
            'action.source' => ['sometimes', 'string', 'max:64'],
            'action.campaign_id' => ['sometimes', 'uuid'],
            'action.conversation_id' => ['sometimes', 'uuid'],
            'action.agent_id' => ['sometimes', 'uuid'],
            'action.qualified_tool_name' => ['sometimes', 'regex:/^[a-z0-9_-]+__[a-z0-9_-]+$/i'],
            'action.params' => ['sometimes', 'array', 'max:30'],
            'action.question' => ['sometimes', 'string', 'max:1000'],
            'action.content' => ['sometimes', 'string', 'max:1000'],
            'action.scheduled_at' => ['sometimes', 'date'],
            'frequency' => ['sometimes', Rule::in(['event', 'session', 'day', 'once'])],
            'limits' => ['nullable', 'array'], 'cooldown_seconds' => ['sometimes', 'integer', 'between:0,7776000'],
            'approval_required' => ['sometimes', 'boolean'], 'channel' => ['nullable', 'string', 'max:32'],
            'audience' => ['nullable', 'array'], 'schedule' => ['nullable', 'array'], 'is_active' => ['sometimes', 'boolean'],
        ]);
    }
}
