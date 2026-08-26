<?php

namespace App\Http\Controllers\api\v1;

use App\Domain\Proactive\ProactiveAuditService;
use App\Enums\AnalyticsEventType;
use App\Http\Controllers\Controller;
use App\Models\Proactive\ProactiveMessage;
use App\Models\Site;
use App\Models\Visitor;
use App\Services\analytics\AnalyticsEventService;
use Illuminate\Http\Request;

class ProactiveWidgetController extends Controller
{
    public function __construct(
        private readonly AnalyticsEventService $analytics,
        private readonly ProactiveAuditService $audit,
    ) {}

    public function pending(Request $request, Site $site)
    {
        $data = $request->validate(['visitor_uuid' => ['required', 'uuid']]);
        $visitor = Visitor::query()->where('site_id', $site->id)->where('uuid', $data['visitor_uuid'])->first();
        if (! $visitor) {
            return response()->json(['data' => null]);
        }

        $message = ProactiveMessage::query()
            ->where('site_id', $site->id)
            ->where('visitor_id', $visitor->id)
            ->where('channel', 'website')
            ->where('status', 'sent')
            ->whereNull('opened_at')
            ->whereNull('notified_at')
            ->whereNotNull('message_id')
            ->whereHas('campaign', fn ($query) => $query->where('widget_behavior', '!=', 'disabled'))
            ->with('campaign:id,name,widget_behavior,priority,status')
            ->orderByDesc('sent_at')
            ->first();

        if (! $message) {
            return response()->json(['data' => null]);
        }

        return response()->json(['data' => [
            'id' => $message->id,
            'conversation_id' => $message->conversation_id,
            'message_id' => $message->message_id,
            'behavior' => $message->campaign?->widget_behavior ?? 'notification_only',
            'priority' => $message->campaign?->priority ?? 5,
            'scheduled_at' => $message->scheduled_at,
            'sent_at' => $message->sent_at,
        ]]);
    }

    public function opened(Request $request, Site $site, ProactiveMessage $message)
    {
        [$visitor] = $this->visitorAndMessage($request, $site, $message);
        if (! $message->opened_at) {
            $message->update([
                'opened_at' => now(),
                'clicked_at' => now(),
                'notified_at' => $message->notified_at ?: now(),
            ]);
            $this->analytics->capture($site, AnalyticsEventType::PROACTIVE_MESSAGE_OPENED, [
                'visitor_id' => $visitor->id, 'conversation_id' => $message->conversation_id,
                'message_id' => $message->message_id, 'agent_id' => $message->agent_id,
                'workflow_id' => $message->workflow_id, 'source' => 'widget', 'channel' => 'website',
                'resource_type' => 'proactive_message', 'resource_id' => $message->id,
            ], ['campaign_id' => $message->campaign_id, 'sequence_id' => $message->sequence_id],
                $this->analytics->deterministicKey('proactive_message_opened', $message->id));
            $this->analytics->capture($site, AnalyticsEventType::PROACTIVE_MESSAGE_CLICKED, [
                'visitor_id' => $visitor->id, 'conversation_id' => $message->conversation_id,
                'message_id' => $message->message_id, 'agent_id' => $message->agent_id,
                'workflow_id' => $message->workflow_id, 'source' => 'widget', 'channel' => 'website',
                'resource_type' => 'proactive_message', 'resource_id' => $message->id, 'action' => 'open',
            ], ['campaign_id' => $message->campaign_id, 'sequence_id' => $message->sequence_id],
                $this->analytics->deterministicKey('proactive_message_clicked', $message->id));
            $this->captureAIEngagementEvent($site, $message, AnalyticsEventType::ENGAGEMENT_WIDGET_OPENED);
            $this->captureAIEngagementEvent($site, $message, AnalyticsEventType::ENGAGEMENT_ACCEPTED);
        }

        return response()->json(['status' => 'opened']);
    }

    public function optOut(Request $request, Site $site, ProactiveMessage $message)
    {
        [$visitor] = $this->visitorAndMessage($request, $site, $message);
        $data = $request->validate(['action' => ['required', 'in:refuse,unsubscribe']]);
        $eventType = $data['action'] === 'unsubscribe' ? 'proactive_unsubscribed' : 'proactive_refused';
        $message->update(['opened_at' => $message->opened_at ?: now(), 'clicked_at' => now()]);
        $this->analytics->capture($site, AnalyticsEventType::PROACTIVE_MESSAGE_CLICKED, [
            'visitor_id' => $visitor->id, 'conversation_id' => $message->conversation_id,
            'message_id' => $message->message_id, 'source' => 'widget', 'channel' => 'website',
            'resource_type' => 'proactive_message', 'resource_id' => $message->id, 'action' => 'opt_out',
        ], ['campaign_id' => $message->campaign_id, 'sequence_id' => $message->sequence_id],
            $this->analytics->deterministicKey('proactive_message_clicked', $message->id));
        $sequence = $message->sequence()->first();
        if ($sequence?->status === 'active') {
            $sequence->update([
                'status' => 'stopped',
                'stopped_at' => now(),
                'stop_reason' => $eventType,
                'next_scheduled_at' => null,
            ]);
            $sequence->messages()
                ->whereIn('status', ['scheduled', 'retrying'])
                ->update(['status' => 'canceled', 'canceled_at' => now(), 'failure_code' => 'visitor_opted_out']);
            $this->audit->record('sequence_stopped', [
                'account_id' => $message->account_id,
                'site_id' => $message->site_id,
                'campaign_id' => $message->campaign_id,
                'sequence_id' => $sequence->id,
                'message_id' => $message->id,
            ], $eventType, metadata: ['source' => 'widget']);
            $this->analytics->capture($site, AnalyticsEventType::PROACTIVE_SEQUENCE_STOPPED, [
                'visitor_id' => $visitor->id, 'conversation_id' => $message->conversation_id,
                'message_id' => $message->message_id, 'source' => 'proactive', 'channel' => 'website',
                'resource_type' => 'proactive_sequence', 'resource_id' => $sequence->id,
            ], ['campaign_id' => $message->campaign_id, 'sequence_id' => $sequence->id, 'reason' => $eventType],
                $this->analytics->deterministicKey('proactive_sequence_stopped', $sequence->id, $eventType));
        }
        $this->analytics->capture($site, $eventType, [
            'visitor_id' => $visitor->id, 'conversation_id' => $message->conversation_id,
            'message_id' => $message->message_id, 'source' => 'widget', 'channel' => 'website',
            'resource_type' => 'proactive_message', 'resource_id' => $message->id,
        ], ['campaign_id' => $message->campaign_id, 'sequence_id' => $message->sequence_id],
            $this->analytics->deterministicKey($eventType, $message->id));
        $this->captureAIEngagementEvent(
            $site,
            $message,
            $data['action'] === 'unsubscribe' ? AnalyticsEventType::ENGAGEMENT_DISMISSED : AnalyticsEventType::ENGAGEMENT_REJECTED,
        );

        return response()->json(['status' => $data['action']]);
    }

    private function visitorAndMessage(Request $request, Site $site, ProactiveMessage $message): array
    {
        $data = $request->validate(['visitor_uuid' => ['required', 'uuid']]);
        $visitor = Visitor::query()->where('site_id', $site->id)->where('uuid', $data['visitor_uuid'])->firstOrFail();
        abort_unless($message->site_id === $site->id && $message->visitor_id === $visitor->id && $message->channel === 'website', 404);

        return [$visitor, $message];
    }

    private function captureAIEngagementEvent(Site $site, ProactiveMessage $message, AnalyticsEventType $type): void
    {
        $decisionId = data_get($message->metadata, 'ai_engagement_decision_id');
        if (!$decisionId) return;
        $this->analytics->capture(
            $site,
            $type,
            [
                'visitor_id' => $message->visitor_id,
                'conversation_id' => $message->conversation_id,
                'message_id' => $message->message_id,
                'source' => 'ai_engagement',
                'channel' => 'website',
                'resource_type' => 'ai_engagement_decision',
                'resource_id' => $decisionId,
            ],
            ['proactive_message_id' => $message->id],
            $this->analytics->deterministicKey('ai-engagement-widget-event', $type->value, $message->id),
        );
    }
}
