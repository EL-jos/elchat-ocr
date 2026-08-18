<?php

namespace App\Services\analytics;

use App\Enums\AnalyticsAttributionType;
use App\Enums\AnalyticsEventType;
use App\Events\AnalyticsEventRecorded;
use App\Jobs\RecordAnalyticsEventJob;
use App\Models\AnalyticsEvent;
use App\Models\Site;
use App\Services\DashboardRealtimeService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class AnalyticsEventService
{
    public function __construct(private readonly DashboardRealtimeService $dashboardRealtime)
    {
    }

    public function capture(
        Site $site,
        AnalyticsEventType|string $eventType,
        array $context = [],
        array $metadata = [],
        ?string $idempotencyKey = null,
        ?bool $async = null,
    ): ?AnalyticsEvent {
        if (!config('analytics.enabled', true)) {
            return null;
        }

        $type = $eventType instanceof AnalyticsEventType ? $eventType->value : $eventType;
        $payload = [
            ...Arr::only($context, [
                'visitor_id', 'conversation_id', 'message_id', 'agent_id', 'workflow_id',
                'session_id', 'correlation_id', 'causation_id', 'parent_event_id',
                'resource_type', 'resource_id', 'source', 'channel',
                'attribution_type', 'value', 'currency', 'action', 'label', 'occurred_at',
            ]),
            'account_id' => $site->account_id,
            'site_id' => $site->id,
            'id' => (string) Str::uuid(),
            'event_type' => $type,
            'source' => $context['source'] ?? 'elchat',
            'attribution_type' => $context['attribution_type'] ?? AnalyticsAttributionType::UNKNOWN->value,
            'idempotency_key' => $idempotencyKey ?: (string) Str::uuid(),
            'metadata' => $this->sanitizeMetadata($metadata),
            'occurred_at' => $context['occurred_at'] ?? now()->toISOString(),
        ];
        $payload['correlation_id'] ??= $context['conversation_id']
            ?? $context['session_id']
            ?? $context['visitor_id']
            ?? $payload['id'];
        $payload = $this->normalizePayload($payload);

        try {
            if ($async ?? config('analytics.async', true)) {
                RecordAnalyticsEventJob::dispatch($payload)->onQueue(config('analytics.queue', 'analytics'));
                return null;
            }

            return $this->recordOrFail($payload);
        } catch (Throwable $exception) {
            Log::warning('Analytics event capture failed without affecting the product flow.', [
                'site_id' => $site->id,
                'event_type' => $type,
                'idempotency_key' => $payload['idempotency_key'],
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    public function recordOrFail(array $payload): AnalyticsEvent
    {
        $payload = $this->normalizePayload($payload);
        $eventType = (string) ($payload['event_type'] ?? '');
        if (!preg_match('/^[a-z][a-z0-9_]{1,63}$/', $eventType)) {
            throw new \InvalidArgumentException("Invalid analytics event type: {$eventType}");
        }

        $siteId = (string) ($payload['site_id'] ?? '');
        $idempotencyKey = (string) ($payload['idempotency_key'] ?? Str::uuid());

        $event = AnalyticsEvent::firstOrCreate(
            ['site_id' => $siteId, 'idempotency_key' => $idempotencyKey],
            [
                ...Arr::only($payload, (new AnalyticsEvent())->getFillable()),
                'site_id' => $siteId,
                'idempotency_key' => $idempotencyKey,
                'metadata' => $this->sanitizeMetadata($payload['metadata'] ?? []),
                'occurred_at' => $payload['occurred_at'] ?? now(),
            ],
        );

        if ($event->wasRecentlyCreated) {
            AnalyticsEventRecorded::dispatch($event->id);
            $this->dashboardRealtime->publish((string) $event->site_id, 'analytics_event_recorded', [
                'analytics_event_id' => (string) $event->id,
                'event_type' => (string) $event->event_type,
                'conversation_id' => $event->conversation_id,
                'visitor_id' => $event->visitor_id,
                'resource_type' => $event->resource_type,
                'resource_id' => $event->resource_id,
            ]);
        }

        return $event;
    }

    public function deterministicKey(string ...$parts): string
    {
        return hash('sha256', implode('|', $parts));
    }

    public function resourceIdempotencyKey(
        string $siteId,
        string $conversationId,
        ?string $messageId,
        string $resourceType,
        ?string $resourceId,
        string $legacyEventType,
    ): string {
        return $this->deterministicKey(
            'resource', $siteId, $conversationId, $messageId ?? '-',
            $resourceType, $resourceId ?? '-', $legacyEventType,
        );
    }

    public function canonicalResourceEventType(string $resourceType, string $legacyEventType): AnalyticsEventType
    {
        return match ("{$resourceType}:{$legacyEventType}") {
            'cta:impression' => AnalyticsEventType::CTA_IMPRESSION,
            'cta:click' => AnalyticsEventType::CTA_CLICK,
            'cta:conversion' => AnalyticsEventType::CTA_CONVERSION,
            'product:impression' => AnalyticsEventType::PRODUCT_RECOMMENDED,
            'product:click' => AnalyticsEventType::PRODUCT_CLICKED,
            'product:conversion' => AnalyticsEventType::PURCHASE_COMPLETED,
            'page:impression' => AnalyticsEventType::PAGE_RECOMMENDED,
            'page:click' => AnalyticsEventType::PAGE_CLICKED,
            'document:impression' => AnalyticsEventType::DOCUMENT_RECOMMENDED,
            'document:click' => AnalyticsEventType::DOCUMENT_CLICKED,
            'document:conversion' => AnalyticsEventType::DOCUMENT_DOWNLOADED,
            'image:impression' => AnalyticsEventType::IMAGE_DISPLAYED,
            'image:click' => AnalyticsEventType::IMAGE_CLICKED,
            default => throw new \InvalidArgumentException("Unsupported resource event: {$resourceType}:{$legacyEventType}"),
        };
    }

    private function sanitizeMetadata(array $metadata): array
    {
        $sensitiveKeys = array_map('strtolower', config('analytics.sensitive_metadata_keys', []));
        $sanitized = [];

        foreach ($metadata as $key => $value) {
            if (in_array(strtolower((string) $key), $sensitiveKeys, true)) {
                continue;
            }

            $sanitized[$key] = is_array($value) ? $this->sanitizeMetadata($value) : $value;
        }

        return $sanitized;
    }

    private function normalizePayload(array $payload): array
    {
        foreach ([
            'session_id' => 100,
            'correlation_id' => 100,
            'causation_id' => 191,
            'resource_type' => 64,
            'resource_id' => 191,
            'source' => 64,
            'channel' => 32,
            'action' => 30,
            'label' => 255,
        ] as $key => $maxLength) {
            if (isset($payload[$key])) {
                $payload[$key] = mb_substr((string) $payload[$key], 0, $maxLength);
            }
        }

        if (isset($payload['idempotency_key']) && mb_strlen((string) $payload['idempotency_key']) > 191) {
            $payload['idempotency_key'] = hash('sha256', (string) $payload['idempotency_key']);
        }

        $allowedAttribution = array_column(AnalyticsAttributionType::cases(), 'value');
        if (!in_array($payload['attribution_type'] ?? null, $allowedAttribution, true)) {
            $payload['attribution_type'] = AnalyticsAttributionType::UNKNOWN->value;
        }

        if (isset($payload['currency'])) {
            $currency = strtoupper((string) $payload['currency']);
            $payload['currency'] = preg_match('/^[A-Z]{3}$/', $currency) ? $currency : null;
        }

        if (isset($payload['value']) && !is_numeric($payload['value'])) {
            $payload['value'] = null;
        }

        $payload['metadata'] = $this->sanitizeMetadata($payload['metadata'] ?? []);

        return $payload;
    }
}
