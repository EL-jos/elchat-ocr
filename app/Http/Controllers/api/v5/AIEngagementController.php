<?php

namespace App\Http\Controllers\api\v5;

use App\Http\Controllers\Controller;
use App\Models\AIEngagementDecision;
use App\Models\AnalyticsEvent;
use App\Models\Mcp\McpAgent;
use App\Models\Mcp\McpWorkflow;
use App\Models\Site;
use App\Models\WidgetSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AIEngagementController extends Controller
{
    public function show(Request $request, Site $site)
    {
        $this->ensureSiteAccess($request, $site);
        $settings = $this->settings($site);

        return response()->json([
            'data' => [
                'settings' => $settings->load(['site']),
                'stats' => $this->statsFor($site),
                'agents' => McpAgent::query()->where('site_id', $site->id)->where('is_active', true)->get(['id', 'name', 'is_active', 'can_proactively_engage']),
                'workflows' => McpWorkflow::query()->where('site_id', $site->id)->where('is_active', true)->get(['id', 'name', 'is_active']),
            ],
        ]);
    }

    public function update(Request $request, Site $site)
    {
        $this->ensureSiteAccess($request, $site);
        $data = $request->validate([
            'ai_engagement_enabled' => ['required', 'boolean'],
            'ai_engagement_widget_behavior' => ['required', 'in:notification_only,auto_open'],
            'ai_engagement_agent_id' => ['nullable', 'uuid'],
            'ai_engagement_workflow_id' => ['nullable', 'uuid'],
            'ai_engagement_max_per_session' => ['required', 'integer', 'between:1,10'],
            'ai_engagement_max_per_visitor' => ['required', 'integer', 'between:1,20'],
            'ai_engagement_visitor_window_seconds' => ['required', 'integer', 'between:3600,2592000'],
            'ai_engagement_cooldown_seconds' => ['required', 'integer', 'between:0,2592000'],
            'ai_engagement_close_cooldown_seconds' => ['required', 'integer', 'between:0,2592000'],
            'ai_engagement_refusal_cooldown_seconds' => ['required', 'integer', 'between:0,31536000'],
            'ai_engagement_min_session_seconds' => ['required', 'integer', 'between:5,3600'],
            'ai_engagement_min_pages' => ['required', 'integer', 'between:1,20'],
            'ai_engagement_min_score' => ['required', 'integer', 'between:1,100'],
            'ai_engagement_strategies' => ['nullable', 'array'],
            'ai_engagement_strategies.*' => ['in:assistance,targeted_question,navigation,sales,lead_generation,booking,cta,support'],
        ]);

        if (!empty($data['ai_engagement_agent_id'])) {
            abort_unless(McpAgent::query()->where('site_id', $site->id)->whereKey($data['ai_engagement_agent_id'])->exists(), 422, 'L’agent doit appartenir à ce site.');
        }
        if (!empty($data['ai_engagement_workflow_id'])) {
            abort_unless(McpWorkflow::query()->where('site_id', $site->id)->whereKey($data['ai_engagement_workflow_id'])->exists(), 422, 'Le workflow doit appartenir à ce site.');
        }

        $settings = $this->settings($site);
        $settings->update($data);

        return response()->json(['data' => [
            'settings' => $settings->fresh(),
            'stats' => $this->statsFor($site),
        ]]);
    }

    public function decisions(Request $request, Site $site)
    {
        $this->ensureSiteAccess($request, $site);
        $data = $request->validate(['per_page' => ['nullable', 'integer', 'between:10,100']]);
        $decisions = AIEngagementDecision::query()
            ->where('site_id', $site->id)
            ->with(['visitor:id,uuid', 'sourceEvent:id,event_type,occurred_at', 'conversation:id', 'proactiveMessage:id,status,sent_at'])
            ->latest('evaluated_at')
            ->paginate($data['per_page'] ?? 25);

        return response()->json($decisions);
    }

    public function stats(Request $request, Site $site)
    {
        $this->ensureSiteAccess($request, $site);
        return response()->json(['data' => $this->statsFor($site)]);
    }

    private function settings(Site $site): WidgetSetting
    {
        return WidgetSetting::query()->firstOrCreate(
            ['site_id' => $site->id],
            ['id' => (string) Str::uuid()],
        );
    }

    private function statsFor(Site $site): array
    {
        $settings = $this->settings($site);
        $decisions = AIEngagementDecision::query()->where('site_id', $site->id)->where('evaluated_at', '>=', now()->subDays(30));
        $events = AnalyticsEvent::query()->where('site_id', $site->id)->where('occurred_at', '>=', now()->subDays(30));
        $engagedVisitors = (clone $decisions)->where('decision', 'engage_now')->whereNotNull('visitor_id')->select('visitor_id');
        $engagedConversations = (clone $decisions)->where('decision', 'engage_now')->whereNotNull('conversation_id')->select('conversation_id');
        $attributedEvents = (clone $events)->where(function ($query) use ($engagedVisitors, $engagedConversations) {
            $query->whereIn('visitor_id', $engagedVisitors)->orWhereIn('conversation_id', $engagedConversations);
        });

        return [
            'opportunities' => (clone $decisions)->where('score', '>=', (int) ($settings->ai_engagement_min_score ?: 60))->count(),
            'evaluated' => (clone $decisions)->count(),
            'triggered' => (clone $decisions)->where('decision', 'engage_now')->whereNotNull('proactive_message_id')->count(),
            'skipped' => (clone $decisions)->whereIn('decision', ['wait', 'do_not_engage'])->count(),
            'messages_sent' => (clone $events)->where('event_type', 'engagement_message_sent')->count(),
            'visitors_engaged' => (clone $decisions)->where('decision', 'engage_now')->distinct('visitor_id')->count('visitor_id'),
            'replied' => (clone $events)->where('event_type', 'engagement_replied')->count(),
            'accepted' => (clone $events)->where('event_type', 'engagement_accepted')->count(),
            'dismissed' => (clone $events)->whereIn('event_type', ['engagement_dismissed', 'engagement_rejected'])->count(),
            'conversation_starts' => (clone $decisions)->whereNotNull('conversation_id')->count(),
            'cta_interactions' => (clone $attributedEvents)->whereIn('event_type', ['cta_click', 'cta_conversion'])->count(),
            'leads_generated' => (clone $attributedEvents)->whereIn('event_type', ['lead_created', 'contact_created'])->count(),
            'appointments' => (clone $attributedEvents)->whereIn('event_type', ['appointment_created', 'meeting_booked'])->count(),
            'conversions' => (clone $attributedEvents)->whereIn('event_type', ['conversion', 'opportunity_won', 'purchase_completed'])->count(),
        ];
    }

    private function ensureSiteAccess(Request $request, Site $site): void
    {
        $accountId = $request->user()?->ownedAccount?->id;
        abort_unless($accountId && $site->account_id === $accountId, 403);
    }
}
