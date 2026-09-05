<?php

namespace App\Domain\Proactive;

use App\Models\AnalyticsEvent;
use App\Models\Proactive\ProactiveMessage;
use App\Models\Proactive\ProactiveMessage as MessageModel;
use Carbon\CarbonImmutable;

class ProactivePolicyEngine
{
    public function evaluate(ProactiveMessage $message): array
    {
        $message->loadMissing(['campaign.agent', 'campaign.workflow', 'sequence']);
        $campaign = $message->campaign;
        $sequence = $message->sequence;
        $agent = $campaign?->agent;

        // Une décision déjà livrée ne doit pas apparaître comme bloquée
        // simplement parce que la séquence a ensuite été clôturée (ex. max_messages = 1).
        if ($message->status === 'sent') {
            return ['allowed' => true, 'reason' => 'already_sent', 'retryable' => false];
        }

        if (! config('proactive.enabled', true)) {
            return $this->deny('engine_disabled');
        }
        if (! $campaign || $campaign->status !== 'active') {
            return $this->deny('campaign_not_active');
        }
        if ($message->site_id !== $campaign->site_id || $message->account_id !== $campaign->account_id) {
            return $this->deny('tenant_mismatch');
        }
        if (! config("proactive.channels.{$message->channel}.enabled", false)) {
            return $this->deny('channel_disabled');
        }
        if ($campaign->starts_at && now()->lt($campaign->starts_at)) {
            return $this->deny('campaign_not_started', true);
        }
        if ($campaign->ends_at && now()->gt($campaign->ends_at)) {
            return $this->deny('campaign_ended');
        }
        if (! $sequence || $sequence->status !== 'active') {
            return $this->deny('sequence_not_active');
        }
        if ($sequence->message_count >= $campaign->max_messages) {
            return $this->deny('sequence_message_limit');
        }
        if (! $agent || $agent->site_id !== $campaign->site_id || ! $agent->is_active || ! $agent->can_proactively_engage) {
            return $this->deny('agent_not_authorized');
        }
        if ($campaign->workflow_id && (! $campaign->workflow || ! $campaign->workflow->is_active || ($campaign->workflow->site_id && $campaign->workflow->site_id !== $campaign->site_id))) {
            return $this->deny('workflow_not_active');
        }

        $scope = $agent->proactive_channel_scope ?: [];
        if ($scope !== [] && ! in_array($message->channel, $scope, true)) {
            return $this->deny('agent_channel_not_authorized');
        }
        if ($agent->proactive_requires_approval && empty(($campaign->metadata ?? [])['approved_at'])) {
            return $this->deny('campaign_approval_required');
        }

        $local = CarbonImmutable::now($campaign->timezone ?: 'UTC');
        $allowedDays = $campaign->allowed_days ?: [1, 2, 3, 4, 5, 6, 7];
        $normalizedDays = array_map(fn ($day) => is_numeric($day) ? (int) $day : ['mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7][strtolower(substr((string) $day, 0, 3))] ?? 0, $allowedDays);
        if (! in_array($local->dayOfWeekIso, $normalizedDays, true)) {
            return $this->deny('outside_allowed_days', true);
        }

        if ($campaign->start_time && $campaign->end_time) {
            $start = $local->setTimeFromTimeString((string) $campaign->start_time);
            $end = $local->setTimeFromTimeString((string) $campaign->end_time);
            $inside = $end->gt($start) ? $local->betweenIncluded($start, $end) : ($local->gte($start) || $local->lte($end));
            if (! $inside) {
                return $this->deny('outside_allowed_hours', true);
            }
        }

        $dayStart = $local->startOfDay()->utc();
        $dayEnd = $local->endOfDay()->utc();
        $siteCap = min((int) $campaign->site_daily_cap, (int) config('proactive.max_site_daily_messages', 2000));
        $sentForSite = MessageModel::query()->where('site_id', $message->site_id)->whereNotNull('sent_at')->whereBetween('sent_at', [$dayStart, $dayEnd])->count();
        if ($sentForSite >= $siteCap) {
            return $this->deny('site_daily_cap_reached', true);
        }

        $tenantCap = (int) config('proactive.max_tenant_daily_messages', 10000);
        if ($tenantCap > 0) {
            $sentForTenant = MessageModel::query()
                ->where('account_id', $message->account_id)
                ->whereNotNull('sent_at')
                ->whereBetween('sent_at', [$dayStart, $dayEnd])
                ->count();
            if ($sentForTenant >= $tenantCap) {
                return $this->deny('tenant_daily_cap_reached', true);
            }
        }

        if ($message->visitor_id) {
            $sentForVisitor = MessageModel::query()->where('site_id', $message->site_id)->where('visitor_id', $message->visitor_id)->whereNotNull('sent_at')->whereBetween('sent_at', [$dayStart, $dayEnd])->count();
            if ($sentForVisitor >= (int) $campaign->visitor_daily_cap) {
                return $this->deny('visitor_daily_cap_reached', true);
            }
            $globalVisitorCap = (int) config('proactive.max_visitor_daily_messages', 5);
            if ($globalVisitorCap > 0 && $sentForVisitor >= $globalVisitorCap) {
                return $this->deny('visitor_global_daily_cap_reached', true);
            }

            $lastSent = MessageModel::query()->where('campaign_id', $campaign->id)->where('visitor_id', $message->visitor_id)->whereNotNull('sent_at')->latest('sent_at')->value('sent_at');
            if ($lastSent && now()->diffInSeconds($lastSent, true) < (int) $campaign->cooldown_seconds) {
                return $this->deny('visitor_cooldown_active', true);
            }

            // Une réponse ou un refus doit bloquer la séquence même si le
            // listener analytique n'a pas encore eu le temps de la clôturer.
            //
            // Pour le premier message, ne pas repartir de plusieurs années en
            // arrière : cela transforme n'importe quel ancien message du
            // visiteur en réponse à cette séquence. La séquence conserve
            // l'événement qui l'a déclenchée ; à défaut (ex. programmation
            // manuelle), sa date de création est le meilleur point d'ancrage.
            $triggerEvent = null;
            if (! $lastSent) {
                $triggerEventId = data_get($sequence?->context_snapshot, 'trigger_event_id');
                if ($triggerEventId) {
                    $triggerEvent = AnalyticsEvent::query()
                        ->where('site_id', $message->site_id)
                        ->find($triggerEventId);
                }
            }

            $after = $lastSent
                ? CarbonImmutable::parse($lastSent)
                : CarbonImmutable::parse(
                    $triggerEvent?->occurred_at
                        ?? $sequence?->created_at
                        ?? $message->created_at
                        ?? $message->scheduled_at
                        ?? now(),
                );

            if (AnalyticsEvent::query()
                ->where('site_id', $message->site_id)
                ->where('visitor_id', $message->visitor_id)
                ->where('occurred_at', '>=', $after)
                ->whereIn('event_type', ['proactive_refused', 'proactive_unsubscribed'])
                ->exists()) {
                return $this->deny('visitor_opted_out');
            }

            if (AnalyticsEvent::query()
                ->where('site_id', $message->site_id)
                ->where('visitor_id', $message->visitor_id)
                ->where('occurred_at', '>', $after)
                ->where('event_type', 'message_sent')
                ->where(function ($query) {
                    $query->whereNull('source')->orWhere('source', '!=', 'proactive');
                })
                ->when($triggerEvent?->message_id, function ($query, $triggerMessageId) {
                    $query->where(function ($query) use ($triggerMessageId) {
                        $query->whereNull('message_id')->orWhere('message_id', '!=', $triggerMessageId);
                    });
                })
                ->exists()) {
                return $this->deny('visitor_replied');
            }

            if ($lastSent && AnalyticsEvent::query()
                ->where('site_id', $message->site_id)
                ->where('visitor_id', $message->visitor_id)
                ->where('occurred_at', '>', $after)
                ->whereIn('event_type', array_values(array_unique([
                    ...config('proactive.conversion_events', []),
                    ...config('proactive.human_handoff_events', []),
                ])))
                ->exists()) {
                return $this->deny('visitor_conversion_or_handoff');
            }
        }

        if ($message->conversation_id) {
            $sentForConversation = MessageModel::query()->where('campaign_id', $campaign->id)->where('conversation_id', $message->conversation_id)->whereNotNull('sent_at')->count();
            if ($sentForConversation >= (int) $campaign->conversation_total_cap) {
                return $this->deny('conversation_cap_reached');
            }
        }

        return ['allowed' => true, 'reason' => null, 'retryable' => false];
    }

    private function deny(string $reason, bool $retryable = false): array
    {
        return ['allowed' => false, 'reason' => $reason, 'retryable' => $retryable];
    }
}
