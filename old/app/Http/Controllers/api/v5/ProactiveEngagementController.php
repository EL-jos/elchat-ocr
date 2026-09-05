<?php

namespace App\Http\Controllers\api\v5;

use App\Domain\Proactive\Delivery\DeliveryChannelRegistry;
use App\Domain\Proactive\ProactiveAuditService;
use App\Domain\Proactive\ProactivePolicyEngine;
use App\Domain\Proactive\ProactiveSequenceService;
use App\Http\Controllers\Controller;
use App\Models\Mcp\McpAgent;
use App\Models\Mcp\McpWorkflow;
use App\Models\Proactive\ProactiveAuditLog;
use App\Models\Proactive\ProactiveCampaign;
use App\Models\Proactive\ProactiveDelivery;
use App\Models\Proactive\ProactiveMessage;
use App\Models\Proactive\ProactiveOutcome;
use App\Models\Proactive\ProactiveSequence;
use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProactiveEngagementController extends Controller
{
    public function __construct(
        private readonly ProactiveSequenceService $sequences,
        private readonly ProactivePolicyEngine $policy,
        private readonly DeliveryChannelRegistry $channels,
        private readonly ProactiveAuditService $audit,
    ) {}

    public function index(Request $request, Site $site)
    {
        $this->ensureSiteAccess($request, $site);
        $campaigns = ProactiveCampaign::query()
            ->where('site_id', $site->id)
            ->with(['triggers', 'agent:id,name,is_active,can_proactively_engage', 'workflow:id,name,is_active'])
            ->withCount(['sequences', 'messages', 'outcomes'])
            ->latest()->get();

        return response()->json(['data' => $campaigns]);
    }

    public function show(Request $request, Site $site, ProactiveCampaign $campaign)
    {
        $this->ensureCampaignAccess($request, $site, $campaign);
        return response()->json(['data' => $campaign->load(['triggers', 'agent', 'workflow'])->loadCount(['sequences', 'messages', 'outcomes'])]);
    }

    public function store(Request $request, Site $site)
    {
        $this->ensureSiteAccess($request, $site);
        $data = $this->validateCampaign($request, false);
        $this->ensureDependencies($site, $data);
        $triggerData = Arr::pull($data, 'triggers');

        $campaign = DB::transaction(function () use ($request, $site, $data, $triggerData) {
            $campaign = ProactiveCampaign::create([
                ...$data,
                'account_id' => $site->account_id,
                'site_id' => $site->id,
                'created_by' => $request->user()?->id,
                'status' => 'draft',
            ]);
            foreach ($triggerData as $trigger) $campaign->triggers()->create($trigger);
            $this->audit->record('campaign_created', [
                'account_id' => $site->account_id, 'site_id' => $site->id,
                'campaign_id' => $campaign->id, 'actor_id' => $request->user()?->id, 'actor_type' => 'admin',
            ], after: $campaign->toArray());
            return $campaign;
        });

        return response()->json(['data' => $campaign->load(['triggers', 'agent', 'workflow'])], 201);
    }

    public function update(Request $request, Site $site, ProactiveCampaign $campaign)
    {
        $this->ensureCampaignAccess($request, $site, $campaign);
        abort_if(in_array($campaign->status, ['stopped', 'completed'], true), 409, 'Cette campagne est terminée.');
        $data = $this->validateCampaign($request, true);
        $this->ensureDependencies($site, $data);
        $triggers = Arr::pull($data, 'triggers');
        $before = $campaign->toArray();

        DB::transaction(function () use ($campaign, $data, $triggers) {
            $campaign->update($data);
            if ($triggers !== null) {
                $campaign->triggers()->delete();
                foreach ($triggers as $trigger) $campaign->triggers()->create($trigger);
            }
        });
        $this->audit->record('campaign_updated', [
            'account_id' => $site->account_id, 'site_id' => $site->id, 'campaign_id' => $campaign->id,
            'actor_id' => $request->user()?->id, 'actor_type' => 'admin',
        ], before: $before, after: $campaign->fresh()->toArray());

        return response()->json(['data' => $campaign->fresh()->load(['triggers', 'agent', 'workflow'])]);
    }

    public function destroy(Request $request, Site $site, ProactiveCampaign $campaign)
    {
        $this->ensureCampaignAccess($request, $site, $campaign);
        abort_unless($campaign->status === 'draft' && !$campaign->sequences()->exists(), 409, 'Seule une campagne brouillon sans historique peut être supprimée.');
        $campaign->delete();
        return response()->json(['status' => 'deleted']);
    }

    public function activate(Request $request, Site $site, ProactiveCampaign $campaign)
    {
        $this->ensureCampaignAccess($request, $site, $campaign);
        $campaign->load('agent');
        abort_unless($campaign->agent?->is_active && $campaign->agent?->can_proactively_engage, 422, "L'agent doit être actif et autorisé à engager proactivement.");
        abort_unless(config("proactive.channels.{$campaign->channel}.enabled", false), 422, 'Ce canal est indisponible ou désactivé pour les envois sortants.');
        abort_unless($campaign->triggers()->where('is_active', true)->exists(), 422, 'Au moins un déclencheur actif est requis.');

        $metadata = $campaign->metadata ?: [];
        $metadata['approved_at'] = now()->toISOString();
        $metadata['approved_by'] = $request->user()?->id;
        $campaign->update(['status' => 'active', 'metadata' => $metadata]);
        $this->auditCampaignAction($request, $site, $campaign, 'campaign_activated', 'admin_approval');
        return response()->json(['data' => $campaign->fresh()]);
    }

    public function pause(Request $request, Site $site, ProactiveCampaign $campaign)
    {
        $this->ensureCampaignAccess($request, $site, $campaign);
        $campaign->update(['status' => 'paused']);
        $this->auditCampaignAction($request, $site, $campaign, 'campaign_paused');
        return response()->json(['data' => $campaign]);
    }

    public function stop(Request $request, Site $site, ProactiveCampaign $campaign)
    {
        $this->ensureCampaignAccess($request, $site, $campaign);
        DB::transaction(function () use ($campaign) {
            $campaign->update(['status' => 'stopped']);
            ProactiveSequence::query()->where('campaign_id', $campaign->id)->where('status', 'active')->update([
                'status' => 'stopped', 'stopped_at' => now(), 'stop_reason' => 'campaign_stopped', 'next_scheduled_at' => null,
            ]);
            ProactiveMessage::query()->where('campaign_id', $campaign->id)->whereIn('status', ['scheduled', 'retrying'])->update([
                'status' => 'canceled', 'canceled_at' => now(), 'failure_code' => 'campaign_stopped',
            ]);
        });
        $this->auditCampaignAction($request, $site, $campaign, 'campaign_stopped');
        return response()->json(['data' => $campaign->fresh()]);
    }

    public function schedule(Request $request, Site $site, ProactiveCampaign $campaign)
    {
        $this->ensureCampaignAccess($request, $site, $campaign);
        abort_unless($campaign->status === 'active', 409, 'La campagne doit être active.');
        $data = $request->validate([
            'conversation_id' => ['nullable', 'uuid'], 'visitor_id' => ['nullable', 'uuid'],
            'social_conversation_id' => ['nullable', 'uuid'], 'scheduled_at' => ['nullable', 'date'],
            'content' => ['nullable', 'string', 'max:1000'], 'idempotency_key' => ['nullable', 'string', 'max:191'],
        ]);
        $message = $this->sequences->scheduleManual($campaign, $data, $data['content'] ?? null, isset($data['idempotency_key']) ? hash('sha256', $data['idempotency_key']) : null);
        return response()->json(['data' => $message->load('sequence')], 201);
    }

    public function messages(Request $request, Site $site)
    {
        $this->ensureSiteAccess($request, $site);
        $data = $request->validate(['status' => ['nullable', 'string', 'max:24'], 'channel' => ['nullable', 'string', 'max:32'], 'campaign_id' => ['nullable', 'uuid'], 'per_page' => ['nullable', 'integer', 'between:10,100']]);
        $query = ProactiveMessage::query()->where('site_id', $site->id)->with(['campaign:id,name', 'agent:id,name', 'workflow:id,name']);
        foreach (['status', 'channel', 'campaign_id'] as $field) if (!empty($data[$field])) $query->where($field, $data[$field]);
        return response()->json($query->latest('scheduled_at')->paginate($data['per_page'] ?? 25));
    }

    public function cancelMessage(Request $request, Site $site, ProactiveMessage $message)
    {
        $this->ensureMessageAccess($request, $site, $message);
        abort_unless(in_array($message->status, ['scheduled', 'retrying'], true), 409, 'Ce message ne peut plus être annulé.');
        $message->update(['status' => 'canceled', 'canceled_at' => now(), 'failure_code' => 'admin_canceled']);
        $this->audit->record('message_canceled', [
            'account_id' => $site->account_id, 'site_id' => $site->id,
            'campaign_id' => $message->campaign_id, 'sequence_id' => $message->sequence_id,
            'message_id' => $message->id, 'actor_id' => $request->user()?->id, 'actor_type' => 'admin',
        ], 'admin_canceled');
        return response()->json(['data' => $message]);
    }

    public function why(Request $request, Site $site, ProactiveMessage $message)
    {
        $this->ensureMessageAccess($request, $site, $message);
        return response()->json(['data' => [
            'message' => $message->load(['campaign.agent:id,name', 'campaign.workflow:id,name', 'sequence']),
            'current_policy' => $this->policy->evaluate($message),
            'audit' => ProactiveAuditLog::query()->where('message_id', $message->id)->latest('created_at')->get(),
        ]]);
    }

    public function history(Request $request, Site $site)
    {
        $this->ensureSiteAccess($request, $site);
        return response()->json(ProactiveAuditLog::query()->where('site_id', $site->id)->latest('created_at')->paginate(50));
    }

    public function outcomes(Request $request, Site $site)
    {
        $this->ensureSiteAccess($request, $site);
        return response()->json(ProactiveOutcome::query()->where('site_id', $site->id)->with('campaign:id,name')->latest('occurred_at')->paginate(50));
    }

    public function stats(Request $request, Site $site)
    {
        $this->ensureSiteAccess($request, $site);
        $from = $request->date('from')?->startOfDay() ?? now()->subDays(30)->startOfDay();
        $to = $request->date('to')?->endOfDay() ?? now()->endOfDay();
        $messages = ProactiveMessage::query()->where('site_id', $site->id)->whereBetween('created_at', [$from, $to]);
        $counts = (clone $messages)->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');
        $sent = (int) ($counts['sent'] ?? 0);
        $replied = (clone $messages)->whereNotNull('replied_at')->count();
        $opened = (clone $messages)->whereNotNull('opened_at')->count();
        $delivered = ProactiveDelivery::query()
            ->whereBetween('delivered_at', [$from, $to])
            ->whereHas('message', fn ($query) => $query->where('site_id', $site->id))
            ->count();
        $attempts = (int) (clone $messages)->sum('attempts');
        $outcomes = ProactiveOutcome::query()->where('site_id', $site->id)->whereBetween('occurred_at', [$from, $to]);
        return response()->json(['data' => [
            'messages_by_status' => $counts, 'sent' => $sent, 'delivered' => $delivered, 'failed' => (int) ($counts['failed'] ?? 0),
            'opened' => $opened, 'replied' => $replied, 'retries' => max(0, $attempts - $sent),
            'open_rate' => $sent ? round($opened / $sent * 100, 2) : 0,
            'reply_rate' => $sent ? round($replied / $sent * 100, 2) : 0,
            'outcomes' => (clone $outcomes)->count(), 'attributed_value' => (float) (clone $outcomes)->sum('value'),
            'channels' => $this->channels->availability(),
        ]]);
    }

    private function validateCampaign(Request $request, bool $update): array
    {
        $sometimes = $update ? 'sometimes' : 'required';
        return $request->validate([
            'name' => [$sometimes, 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:5000'],
            'agent_id' => [$sometimes, 'uuid', 'exists:mcp_agents,id'], 'workflow_id' => ['nullable', 'uuid', 'exists:mcp_workflows,id'],
            'channel' => [$sometimes, Rule::in(['website', 'facebook', 'instagram', 'telegram', 'youtube', 'email', 'whatsapp'])],
            'decision_mode' => ['sometimes', Rule::in(['ai', 'hybrid', 'template'])],
            'widget_behavior' => ['sometimes', Rule::in(['disabled', 'notification_only', 'auto_open'])],
            'priority' => ['sometimes', 'integer', 'between:1,10'], 'timezone' => ['sometimes', 'timezone'],
            'allowed_days' => ['nullable', 'array'], 'allowed_days.*' => ['integer', 'between:1,7'],
            'start_time' => ['nullable', 'date_format:H:i'], 'end_time' => ['nullable', 'date_format:H:i'],
            'first_delay_seconds' => ['sometimes', 'integer', 'between:0,2592000'],
            'follow_up_intervals' => ['nullable', 'array', 'max:10'], 'follow_up_intervals.*' => ['integer', 'between:60,7776000'],
            'max_messages' => ['sometimes', 'integer', 'between:1,10'], 'cooldown_seconds' => ['sometimes', 'integer', 'between:0,7776000'],
            'site_daily_cap' => ['sometimes', 'integer', 'between:1,10000'], 'visitor_daily_cap' => ['sometimes', 'integer', 'between:1,20'],
            'conversation_total_cap' => ['sometimes', 'integer', 'between:1,50'],
            'stop_on_reply' => ['sometimes', 'boolean'], 'stop_on_conversion' => ['sometimes', 'boolean'],
            'stop_on_human_handoff' => ['sometimes', 'boolean'], 'stop_on_refusal' => ['sometimes', 'boolean'], 'stop_on_unsubscribe' => ['sometimes', 'boolean'],
            'context_query' => ['nullable', 'string', 'max:500'], 'starts_at' => ['nullable', 'date'], 'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'metadata' => ['nullable', 'array'], 'metadata.message_template' => ['nullable', 'string', 'max:1000'],
            'triggers' => [$update ? 'sometimes' : 'required', 'array', 'min:1'],
            'triggers.*.type' => ['required', Rule::in(['event', 'schedule', 'manual'])],
            'triggers.*.event_type' => ['nullable', 'regex:/^[a-z][a-z0-9_]{1,63}$/'],
            'triggers.*.condition_mode' => ['sometimes', Rule::in(['all', 'any'])],
            'triggers.*.conditions' => ['nullable', 'array'], 'triggers.*.schedule' => ['nullable', 'array'],
            'triggers.*.is_active' => ['sometimes', 'boolean'], 'triggers.*.priority' => ['sometimes', 'integer', 'between:1,10'],
        ]);
    }

    private function ensureDependencies(Site $site, array $data): void
    {
        if (!empty($data['agent_id'])) abort_unless(McpAgent::query()->where('site_id', $site->id)->whereKey($data['agent_id'])->exists(), 422, "L'agent n'appartient pas à ce site.");
        if (!empty($data['workflow_id'])) abort_unless(McpWorkflow::query()->whereKey($data['workflow_id'])->where(fn ($query) => $query->whereNull('site_id')->orWhere('site_id', $site->id))->exists(), 422, "Le workflow n'est pas disponible pour ce site.");
    }

    private function ensureSiteAccess(Request $request, Site $site): void
    {
        $accountId = $request->user()?->ownedAccount?->id;
        abort_unless($accountId && $site->account_id === $accountId, 403);
    }
    private function ensureCampaignAccess(Request $request, Site $site, ProactiveCampaign $campaign): void { $this->ensureSiteAccess($request, $site); abort_unless($campaign->site_id === $site->id, 404); }
    private function ensureMessageAccess(Request $request, Site $site, ProactiveMessage $message): void { $this->ensureSiteAccess($request, $site); abort_unless($message->site_id === $site->id, 404); }
    private function auditCampaignAction(Request $request, Site $site, ProactiveCampaign $campaign, string $action, ?string $reason = null): void
    {
        $this->audit->record($action, ['account_id' => $site->account_id, 'site_id' => $site->id, 'campaign_id' => $campaign->id, 'actor_id' => $request->user()?->id, 'actor_type' => 'admin'], $reason);
    }
}
