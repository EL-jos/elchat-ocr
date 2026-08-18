<?php

namespace App\Domain\Proactive;

use App\Domain\Proactive\Delivery\DeliveryChannelRegistry;
use App\Enums\AnalyticsEventType;
use App\Models\Proactive\ProactiveDelivery;
use App\Models\Proactive\ProactiveMessage;
use App\Models\Proactive\ProactiveSequence;
use App\Services\analytics\AnalyticsEventService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProactiveDeliveryService
{
    public function __construct(
        private readonly ProactivePolicyEngine $policy,
        private readonly ProactiveScheduleService $schedule,
        private readonly ProactiveDecisionService $decision,
        private readonly DeliveryChannelRegistry $channels,
        private readonly ProactiveAuditService $audit,
        private readonly AnalyticsEventService $analytics,
    ) {}

    public function send(string $messageId): void
    {
        $message = DB::transaction(function () use ($messageId) {
            $message = ProactiveMessage::query()->lockForUpdate()->find($messageId);
            if (!$message || !in_array($message->status, ['scheduled', 'retrying'], true) || $message->scheduled_at->isFuture()) return null;
            $message->update(['status' => 'processing', 'locked_at' => now(), 'attempts' => $message->attempts + 1]);
            return $message->fresh(['campaign.agent', 'campaign.site', 'campaign.workflow', 'sequence']);
        });

        if (!$message) return;

        try {
            $policy = $this->policy->evaluate($message);
            if (!$policy['allowed']) {
                $this->handlePolicyDenial($message, $policy);
                return;
            }

            $adapter = $this->channels->get($message->channel);
            if (!$adapter) {
                $this->skip($message, 'channel_adapter_unavailable');
                return;
            }

            $availability = $adapter->canSend($message);
            if (!$availability['allowed']) {
                $this->skip($message, $availability['reason'] ?? 'channel_unavailable');
                return;
            }

            if (!$message->content) {
                $decision = $this->decision->decide($message);
                $message->update([
                    'decided_at' => now(),
                    'decision_reason' => $decision['reason'],
                    'evidence' => $decision['evidence'],
                ]);
                $message->sequence?->update(['context_snapshot' => $decision['context'], 'evidence' => $decision['evidence']]);

                if (!$decision['send']) {
                    $this->skip($message, 'decision_declined:'.$decision['reason']);
                    return;
                }
                $message->update(['content' => $decision['message']]);
            }

            $deliveryKey = hash('sha256', "delivery|{$message->id}|{$message->channel}");
            $delivery = ProactiveDelivery::query()->firstOrCreate(
                ['message_id' => $message->id, 'idempotency_key' => $deliveryKey],
                ['channel' => $message->channel, 'status' => 'processing', 'attempted_at' => now()],
            );

            // Si le worker a été interrompu après l'acceptation du provider,
            // reprendre la livraison depuis l'état durable plutôt que rappeler
            // le canal (protection contre les doubles envois sur retry).
            if (in_array($delivery->status, ['accepted', 'delivered'], true)) {
                $this->finalizeAcceptedDelivery($message, $delivery);
                return;
            }

            $result = $adapter->deliver($message->fresh(['sequence']));
            if (!$result['accepted']) throw new \RuntimeException('Channel refused the delivery.');

            DB::transaction(function () use ($message, $delivery, $result) {
                $now = now();
                $message->update(['status' => 'sent', 'sent_at' => $now, 'locked_at' => null]);
                $delivery->update([
                    'status' => 'accepted', 'provider' => $result['provider'],
                    'external_message_id' => $result['external_message_id'],
                    'delivered_at' => $now, 'provider_response' => $result['details'],
                ]);
                $sequence = $message->sequence()->lockForUpdate()->first();
                $sequence->update([
                    'message_count' => $sequence->message_count + 1,
                    'current_step' => $message->step,
                    'last_sent_at' => $now,
                ]);
                $this->scheduleFollowUp($message, $sequence);
            });

            $site = $message->campaign->site;
            $this->analytics->capture($site, AnalyticsEventType::PROACTIVE_MESSAGE_SENT, [
                'visitor_id' => $message->visitor_id, 'conversation_id' => $message->conversation_id,
                'message_id' => $message->message_id, 'agent_id' => $message->agent_id,
                'workflow_id' => $message->workflow_id, 'source' => 'proactive', 'channel' => $message->channel,
                'resource_type' => 'proactive_message', 'resource_id' => $message->id,
            ], ['campaign_id' => $message->campaign_id, 'sequence_id' => $message->sequence_id, 'step' => $message->step],
                $this->analytics->deterministicKey('proactive_message_sent', $message->id));
            if (!empty($result['details']['delivered'])) {
                $this->analytics->capture($site, AnalyticsEventType::PROACTIVE_MESSAGE_DELIVERED, [
                    'visitor_id' => $message->visitor_id, 'conversation_id' => $message->conversation_id,
                    'message_id' => $message->message_id, 'agent_id' => $message->agent_id,
                    'workflow_id' => $message->workflow_id, 'source' => 'proactive', 'channel' => $message->channel,
                    'resource_type' => 'proactive_message', 'resource_id' => $message->id,
                ], ['campaign_id' => $message->campaign_id, 'sequence_id' => $message->sequence_id],
                    $this->analytics->deterministicKey('proactive_message_delivered', $message->id));
            }
            $this->auditMessage($message, 'message_sent', 'delivery_accepted', ['provider' => $result['provider']]);
        } catch (Throwable $exception) {
            $this->retryOrFail($message, $exception);
        }
    }

    private function handlePolicyDenial(ProactiveMessage $message, array $policy): void
    {
        if ($policy['retryable'] && (!$message->campaign->ends_at || now()->lt($message->campaign->ends_at))) {
            $next = $this->schedule->nextAllowedAt($message->campaign, CarbonImmutable::now()->addMinutes(15));
            $message->update(['status' => 'scheduled', 'scheduled_at' => $next, 'locked_at' => null, 'failure_code' => $policy['reason']]);
            return;
        }
        $this->skip($message, $policy['reason']);
    }

    private function scheduleFollowUp(ProactiveMessage $message, $sequence): void
    {
        $campaign = $message->campaign;
        if ($message->step >= $campaign->max_messages || $sequence->status !== 'active') {
            $sequence->update(['status' => 'completed', 'stopped_at' => now(), 'stop_reason' => 'sequence_completed', 'next_scheduled_at' => null]);
            return;
        }

        $intervals = $campaign->follow_up_intervals ?: [];
        $seconds = max(60, (int) ($intervals[$message->step - 1] ?? 86400));
        $scheduledAt = $this->schedule->nextAllowedAt($campaign, CarbonImmutable::now()->addSeconds($seconds));
        $nextStep = $message->step + 1;
        $key = hash('sha256', "{$sequence->id}|{$nextStep}");
        $nextMessage = ProactiveMessage::query()->firstOrCreate(
            ['site_id' => $message->site_id, 'idempotency_key' => $key],
            [
                'account_id' => $message->account_id, 'campaign_id' => $message->campaign_id,
                'sequence_id' => $sequence->id, 'conversation_id' => $message->conversation_id,
                'visitor_id' => $message->visitor_id, 'agent_id' => $message->agent_id,
                'workflow_id' => $message->workflow_id, 'channel' => $message->channel,
                'status' => 'scheduled', 'step' => $nextStep, 'scheduled_at' => $scheduledAt,
                'metadata' => $message->metadata,
            ],
        );
        $sequence->update(['next_scheduled_at' => $scheduledAt]);

        if ($nextMessage->wasRecentlyCreated && $campaign->site) {
            $this->analytics->capture(
                $campaign->site,
                AnalyticsEventType::PROACTIVE_MESSAGE_SCHEDULED,
                [
                    'visitor_id' => $nextMessage->visitor_id,
                    'conversation_id' => $nextMessage->conversation_id,
                    'agent_id' => $nextMessage->agent_id,
                    'workflow_id' => $nextMessage->workflow_id,
                    'source' => 'proactive',
                    'channel' => $nextMessage->channel,
                    'resource_type' => 'proactive_message',
                    'resource_id' => $nextMessage->id,
                ],
                ['campaign_id' => $nextMessage->campaign_id, 'sequence_id' => $nextMessage->sequence_id, 'step' => $nextMessage->step],
                $this->analytics->deterministicKey('proactive_message_scheduled', $nextMessage->id),
            );
        }
    }

    private function retryOrFail(ProactiveMessage $message, Throwable $exception): void
    {
        $terminal = $message->attempts >= 5;
        $message->update([
            'status' => $terminal ? 'failed' : 'retrying',
            'scheduled_at' => $terminal ? $message->scheduled_at : now()->addSeconds(min(3600, 30 * (2 ** max(0, $message->attempts - 1)))),
            'locked_at' => null,
            'failure_code' => $terminal ? 'delivery_failed' : 'delivery_retry',
            'failure_details' => mb_substr($exception->getMessage(), 0, 2000),
        ]);
        ProactiveDelivery::query()->where('message_id', $message->id)->latest()->first()?->update([
            'status' => 'failed', 'failed_at' => now(), 'error_code' => class_basename($exception),
            'error_details' => mb_substr($exception->getMessage(), 0, 2000),
        ]);
        $this->auditMessage($message, $terminal ? 'message_failed' : 'message_retry_scheduled', $exception->getMessage());
        $site = $message->campaign?->site;
        if ($site) {
            $this->analytics->capture(
                $site,
                AnalyticsEventType::PROACTIVE_MESSAGE_FAILED,
                [
                    'visitor_id' => $message->visitor_id, 'conversation_id' => $message->conversation_id,
                    'message_id' => $message->message_id, 'agent_id' => $message->agent_id,
                    'workflow_id' => $message->workflow_id, 'source' => 'proactive', 'channel' => $message->channel,
                    'resource_type' => 'proactive_message', 'resource_id' => $message->id,
                ],
                ['campaign_id' => $message->campaign_id, 'sequence_id' => $message->sequence_id, 'terminal' => $terminal],
                $this->analytics->deterministicKey('proactive_message_failed', $message->id, (string) $message->attempts),
            );
        }
    }

    private function skip(ProactiveMessage $message, string $reason): void
    {
        $message->update(['status' => 'skipped', 'locked_at' => null, 'failure_code' => mb_substr($reason, 0, 64), 'failure_details' => $reason]);
        $this->auditMessage($message, 'message_skipped', $reason);
        $site = $message->campaign?->site;
        if ($site) {
            $this->analytics->capture(
                $site,
                AnalyticsEventType::PROACTIVE_MESSAGE_SKIPPED,
                [
                    'visitor_id' => $message->visitor_id, 'conversation_id' => $message->conversation_id,
                    'message_id' => $message->message_id, 'agent_id' => $message->agent_id,
                    'workflow_id' => $message->workflow_id, 'source' => 'proactive', 'channel' => $message->channel,
                    'resource_type' => 'proactive_message', 'resource_id' => $message->id,
                ],
                ['campaign_id' => $message->campaign_id, 'sequence_id' => $message->sequence_id, 'reason' => $reason],
                $this->analytics->deterministicKey('proactive_message_skipped', $message->id),
            );
        }
    }

    private function finalizeAcceptedDelivery(ProactiveMessage $message, ProactiveDelivery $delivery): void
    {
        DB::transaction(function () use ($message, $delivery) {
            $locked = ProactiveMessage::query()->lockForUpdate()->find($message->id);
            if (!$locked || $locked->status === 'sent') return;

            $now = $locked->sent_at ?: now();
            $locked->update(['status' => 'sent', 'sent_at' => $now, 'locked_at' => null]);
            $sequence = ProactiveSequence::query()->lockForUpdate()->find($locked->sequence_id);
            if (!$sequence) return;
            if (!$sequence->last_sent_at || $sequence->last_sent_at->lt($now)) {
                $sequence->update([
                    'message_count' => $sequence->message_count + 1,
                    'current_step' => $locked->step,
                    'last_sent_at' => $now,
                ]);
                $this->scheduleFollowUp($locked->fresh(['campaign']), $sequence);
            }
        });

        $site = $message->campaign?->site;
        if ($site) {
            $this->analytics->capture($site, AnalyticsEventType::PROACTIVE_MESSAGE_SENT, [
                'visitor_id' => $message->visitor_id, 'conversation_id' => $message->conversation_id,
                'message_id' => $message->message_id, 'agent_id' => $message->agent_id,
                'workflow_id' => $message->workflow_id, 'source' => 'proactive', 'channel' => $message->channel,
                'resource_type' => 'proactive_message', 'resource_id' => $message->id,
            ], ['campaign_id' => $message->campaign_id, 'sequence_id' => $message->sequence_id, 'recovered' => true],
                $this->analytics->deterministicKey('proactive_message_sent', $message->id));
        }
    }

    private function auditMessage(ProactiveMessage $message, string $action, string $reason, array $metadata = []): void
    {
        $this->audit->record($action, [
            'account_id' => $message->account_id, 'site_id' => $message->site_id,
            'campaign_id' => $message->campaign_id, 'sequence_id' => $message->sequence_id, 'message_id' => $message->id,
        ], $reason, metadata: $metadata);
    }
}
