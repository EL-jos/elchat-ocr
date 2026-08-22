<?php

namespace App\Services\VisitorIntelligence;

use App\Enums\AnalyticsEventType;
use App\Models\AnalyticsEvent;
use App\Models\Site;
use App\Models\Visitor;
use App\Models\VisitorSession;
use App\Services\analytics\AnalyticsEventService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class VisitorIntelligenceEventService
{
    /** Events accepted from a browser. Server-side business events keep using the same stream. */
    public static function browserEventTypes(): array
    {
        return [
            AnalyticsEventType::SESSION_START->value,
            AnalyticsEventType::PAGE_VIEW->value,
            AnalyticsEventType::PAGE_EXIT->value,
            AnalyticsEventType::NAVIGATION->value,
            AnalyticsEventType::SCROLL_DEPTH->value,
            AnalyticsEventType::CLICK->value,
            AnalyticsEventType::POINTER_MOVE->value,
            AnalyticsEventType::WIDGET_IMPRESSION->value,
            AnalyticsEventType::WIDGET_OPENED->value,
            AnalyticsEventType::WIDGET_CLOSED->value,
            AnalyticsEventType::FORM_START->value,
            AnalyticsEventType::FORM_SUBMIT->value,
            AnalyticsEventType::INACTIVITY_START->value,
            AnalyticsEventType::INACTIVITY_END->value,
            AnalyticsEventType::SESSION_END->value,
            AnalyticsEventType::CTA_IMPRESSION->value,
            AnalyticsEventType::CTA_CLICK->value,
            AnalyticsEventType::PRODUCT_VIEWED->value,
            AnalyticsEventType::PRODUCT_CLICKED->value,
            AnalyticsEventType::DOCUMENT_DOWNLOADED->value,
            AnalyticsEventType::IMAGE_DISPLAYED->value,
            AnalyticsEventType::IMAGE_CLICKED->value,
            // Business outcomes remain server-side events. Accepting them from
            // the public browser collector would let a client forge a lead or
            // conversion and pollute the shared Event Intelligence stream.
        ];
    }

    public function __construct(
        private readonly AnalyticsEventService $analytics,
        private readonly VisitorIntelligenceFrameService $frames,
    )
    {
    }

    /**
     * Resolve a browser identity within the site boundary. The browser UUID is
     * only a pseudonymous key; it is never used as permission to read data.
     */
    public function resolveVisitor(Site $site, string $visitorUuid, Request $request): array
    {
        $visitor = Visitor::query()->where('site_id', $site->id)->where('uuid', $visitorUuid)->first();
        $isNew = !$visitor;

        if (!$visitor) {
            $visitor = Visitor::query()->create([
                'site_id' => $site->id,
                'uuid' => $visitorUuid,
                'ip' => null, // Do not persist the IP for Visitor Intelligence.
                'user_agent' => null, // Device is enough for journey aggregation.
                'device' => $this->detectDevice($request->userAgent()),
            ]);
        }

        return [$visitor, $isNew];
    }

    public function ensureSession(
        Site $site,
        Visitor $visitor,
        string $sessionKey,
        array $event,
        bool $isNewVisitor,
    ): VisitorSession {
        $occurredAt = $this->occurredAt($event['occurred_at'] ?? null);
        $metadata = $this->sanitizeBrowserMetadata($event['metadata'] ?? [], $event);

        $session = VisitorSession::query()->firstOrCreate(
            ['site_id' => $site->id, 'session_key' => $sessionKey],
            [
                'account_id' => $site->account_id,
                'visitor_id' => $visitor->id,
                'started_at' => $occurredAt,
                'last_seen_at' => $occurredAt,
                'entry_url' => $metadata['page_url'] ?? null,
                'device' => $metadata['device'] ?? $visitor->device,
                'source' => $metadata['source'] ?? 'website',
                'is_new_visitor' => $isNewVisitor,
                'metadata' => $metadata,
            ],
        );

        abort_unless($session->visitor_id === $visitor->id, 422, 'Session invalide pour ce visiteur.');

        $startedAt = $session->started_at;
        $lastSeenAt = $session->last_seen_at;
        $session->forceFill([
            // Browser batches and frame uploads can arrive out of order. Keep
            // the session bounds chronological instead of letting the first
            // HTTP request define the journey start forever.
            'started_at' => $startedAt && $startedAt->lessThan($occurredAt) ? $startedAt : $occurredAt,
            'last_seen_at' => $lastSeenAt && $lastSeenAt->greaterThan($occurredAt) ? $lastSeenAt : $occurredAt,
            'metadata' => array_slice(array_replace($session->metadata ?? [], $metadata), 0, 30, true),
        ])->save();

        return $session;
    }

    public function capture(
        Site $site,
        VisitorSession $session,
        Visitor $visitor,
        array $event,
        Request $request,
        ?bool $async = null,
    ): void {
        $type = (string) $event['event_type'];
        if ($type === AnalyticsEventType::POINTER_MOVE->value && !config('visitor-intelligence.pointer_tracking_enabled', true)) {
            return;
        }
        $metadata = $this->sanitizeBrowserMetadata($event['metadata'] ?? [], $event);
        $context = [
            'visitor_id' => $visitor->id,
            'session_id' => $session->session_key,
            'correlation_id' => $session->session_key,
            'source' => 'visitor_intelligence',
            'channel' => 'website',
            'resource_type' => $event['resource_type'] ?? null,
            'resource_id' => $event['resource_id'] ?? null,
            'label' => $this->safeLabel($event['label'] ?? null),
            'occurred_at' => $this->occurredAt($event['occurred_at'] ?? null),
        ];

        try {
            $this->analytics->capture(
                $site,
                $type,
                $context,
                $metadata,
                $event['idempotency_key']
                    ?? hash('sha256', implode('|', ['visitor-intelligence', $site->id, $session->session_key, $type, $event['event_id'] ?? $context['occurred_at']])),
                $async,
            );
        } catch (\Throwable $exception) {
            // Tracking must never break the tenant's website or widget.
            Log::warning('Visitor Intelligence event capture failed.', [
                'site_id' => $site->id, 'session_id' => $session->session_key,
                'event_type' => $type, 'error' => $exception->getMessage(),
            ]);
        }
    }

    public function captureFrame(
        Site $site,
        UploadedFile $screenshot,
        array $data,
        Request $request,
    ): array {
        [$visitor, $isNewVisitor] = $this->resolveVisitor($site, (string) $data['visitor_uuid'], $request);
        $event = [
            'event_id' => (string) ($data['event_id'] ?? Str::uuid()),
            'event_type' => AnalyticsEventType::POINTER_MOVE->value,
            'occurred_at' => $data['occurred_at'] ?? now()->toISOString(),
            'page_url' => $data['page_url'] ?? null,
            'path' => $data['path'] ?? null,
            'title' => $data['title'] ?? null,
            'metadata' => $data['metadata'] ?? [],
        ];
        $session = $this->ensureSession($site, $visitor, (string) $data['session_id'], $event, $isNewVisitor);
        $frame = $this->frames->store($screenshot, $site, $session, $event['event_id']);
        $event['metadata'] = [
            ...($event['metadata'] ?? []),
            'screenshot_url' => $frame['url'],
            'screenshot_path' => $frame['path'],
            'screenshot_bytes' => $frame['bytes'],
        ];

        // A frame must be visible to the replay as soon as the upload has
        // completed. Other browser events may remain asynchronous.
        $this->capture($site, $session, $visitor, $event, $request, false);

        return [
            'visitor_id' => (string) $visitor->id,
            'session_id' => $session->session_key,
            'screenshot_url' => $frame['url'],
        ];
    }

    /** Apply only after AnalyticsEventService has accepted a new event. */
    public function applyRecordedEvent(AnalyticsEvent $event): ?VisitorSession
    {
        if (!$event->session_id) return null;

        return DB::transaction(function () use ($event) {
            $session = VisitorSession::query()
                ->where('site_id', $event->site_id)
                ->where('session_key', $event->session_id)
                ->lockForUpdate()
                ->first();
            if (!$session) return null;

            $metadata = $event->metadata ?? [];
            $updates = [
                'last_seen_at' => max($session->last_seen_at ?? $event->occurred_at, $event->occurred_at),
                'event_count' => $session->event_count + 1,
            ];

            if ($event->event_type === AnalyticsEventType::PAGE_VIEW->value) {
                $updates['page_count'] = $session->page_count + 1;
                $updates['unique_page_count'] = max($session->unique_page_count, $session->page_count + 1);
            }
            if (in_array($event->event_type, [
                AnalyticsEventType::WIDGET_IMPRESSION->value,
                AnalyticsEventType::WIDGET_OPENED->value,
                AnalyticsEventType::WIDGET_CLOSED->value,
                AnalyticsEventType::CONVERSATION_STARTED->value,
                AnalyticsEventType::MESSAGE_SENT->value,
            ], true)) {
                $updates['has_widget_interaction'] = true;
            }
            if (in_array($event->event_type, [
                AnalyticsEventType::LEAD_CREATED->value,
                AnalyticsEventType::MEETING_BOOKED->value,
                AnalyticsEventType::APPOINTMENT_CREATED->value,
                AnalyticsEventType::PURCHASE_COMPLETED->value,
                AnalyticsEventType::CONVERSION->value,
            ], true)) {
                $updates['converted'] = true;
                $updates['outcome'] = $event->event_type;
            }
            if ($event->event_type === AnalyticsEventType::SESSION_END->value) {
                $updates['ended_at'] = $event->occurred_at;
                $updates['duration_seconds'] = max(0, $session->started_at?->diffInSeconds($event->occurred_at) ?? 0);
                $updates['exit_url'] = $metadata['page_url'] ?? $metadata['path'] ?? $session->exit_url;
                $updates['outcome'] ??= $session->converted ? 'converted' : 'abandoned_or_unknown';
            }
            if (in_array($event->event_type, [
                AnalyticsEventType::INACTIVITY_START->value,
                AnalyticsEventType::INACTIVITY_END->value,
                AnalyticsEventType::SESSION_END->value,
            ], true)) {
                // Keep the browser-derived inactivity counters in the session
                // metadata so the existing schema remains backwards compatible.
                // The server still owns the canonical total session duration.
                $sessionMetadata = $session->metadata ?? [];
                foreach (['idle_duration_ms', 'active_duration_ms', 'inactivity_count', 'inactivity_threshold_ms'] as $key) {
                    if (array_key_exists($key, $metadata) && $metadata[$key] !== null) {
                        $sessionMetadata[$key] = $metadata[$key];
                    }
                }
                $updates['metadata'] = array_slice($sessionMetadata, 0, 30, true);
            }
            if (isset($metadata['intent_level']) && in_array($metadata['intent_level'], ['low', 'medium', 'high'], true)) {
                $updates['intent_level'] = $metadata['intent_level'];
            }

            $session->forceFill($updates)->save();
            return $session->fresh();
        });
    }

    public function sanitizeBrowserMetadata(array $metadata, array $event = []): array
    {
        $allowed = [
            'page_url', 'path', 'referrer', 'title', 'device', 'source', 'medium', 'campaign',
            'target', 'selector_hash', 'x', 'y', 'depth', 'duration_ms', 'form_id',
            'idle_duration_ms', 'active_duration_ms', 'inactivity_count', 'inactivity_threshold_ms',
            'session_duration_ms', 'reason', 'end_reason', 'visibility_state',
            'intent_level', 'outcome', 'question_hash', 'resource_type', 'resource_id',
            'image_url', 'image_width', 'image_height', 'image_x', 'image_y',
            'viewport_width', 'viewport_height', 'cursor_x', 'cursor_y', 'pointer_type',
            'surface', 'screenshot_url', 'screenshot_path', 'screenshot_bytes',
            'screenshot_width', 'screenshot_height', 'page_width', 'page_height',
            'scroll_x', 'scroll_y', 'scroll_source', 'scroll_positions', 'cursor_page_x', 'cursor_page_y', 'frame_index',
            'capture_mode', 'capture_scale',
        ];
        $result = [];
        foreach (array_merge($metadata, array_filter([
            'page_url' => $event['page_url'] ?? null,
            'path' => $event['path'] ?? null,
            'title' => $event['title'] ?? null,
            'device' => $event['device'] ?? null,
            'source' => $event['source'] ?? null,
        ])) as $key => $value) {
            if (!in_array((string) $key, $allowed, true) || $value === null) continue;
            // Multipart requests normally send this field as JSON, while
            // JSON clients may send it as a decoded array. Accept both forms
            // so mobile and desktop collectors use the same contract.
            if (is_array($value) && $key !== 'scroll_positions') continue;
            $value = is_string($value) ? trim(strip_tags($value)) : $value;
            // Screenshot paths/URLs contain the numeric event timestamp
            // (for example ".../1766...-abc.jpg"). They are technical
            // storage references, not phone numbers, and must not be
            // removed by the privacy filter.
            if (
                is_string($value)
                && !in_array($key, ['screenshot_url', 'screenshot_path', 'scroll_positions'], true)
                && preg_match('/(?:@|\+?\d[\d\s().-]{7,})/', $value)
            ) continue;
            if (in_array($key, ['page_url', 'referrer', 'image_url'], true)) {
                $value = Str::limit($this->safeUrl((string) $value), 2048, '');
            } elseif ($key === 'screenshot_url') {
                $value = Str::limit($this->safeScreenshotUrl((string) $value), 2048, '');
            } elseif (in_array($key, ['path', 'title', 'target', 'screenshot_path'], true)) {
                $value = Str::limit((string) $value, 255, '');
            } elseif ($key === 'scroll_positions') {
                $value = $this->sanitizeScrollPositions($value);
                if ($value === null) continue;
            } elseif (in_array($key, [
                'x', 'y', 'depth', 'duration_ms', 'idle_duration_ms', 'active_duration_ms',
                'inactivity_count', 'inactivity_threshold_ms', 'session_duration_ms',
                'image_width', 'image_height', 'image_x', 'image_y',
                'viewport_width', 'viewport_height', 'cursor_x', 'cursor_y',
                'screenshot_bytes', 'screenshot_width', 'screenshot_height', 'page_width', 'page_height',
                'scroll_x', 'scroll_y', 'cursor_page_x', 'cursor_page_y', 'frame_index',
            ], true)) {
                if (!is_numeric($value)) continue;
                $value = (int) $value;
                if (in_array($key, ['x', 'image_x', 'cursor_x'], true)) $value = max(0, min(10000, $value));
                if (in_array($key, ['y', 'image_y', 'cursor_y'], true)) $value = max(0, min(10000, $value));
                if (in_array($key, [
                    'viewport_width', 'viewport_height', 'image_width', 'image_height',
                    'screenshot_width', 'screenshot_height', 'page_width', 'page_height',
                    'scroll_x', 'scroll_y', 'cursor_page_x', 'cursor_page_y',
                ], true)) $value = max(0, min(20000, $value));
                if (in_array($key, ['screenshot_bytes'], true)) $value = max(0, min(10000000, $value));
                if (in_array($key, ['duration_ms', 'idle_duration_ms', 'active_duration_ms', 'session_duration_ms'], true)) $value = max(0, min(86400000, $value));
                if (in_array($key, ['inactivity_count'], true)) $value = max(0, min(10000, $value));
                if (in_array($key, ['inactivity_threshold_ms'], true)) $value = max(1000, min(86400000, $value));
            } elseif ($key === 'capture_mode') {
                $value = (string) $value === 'viewport' ? 'viewport' : null;
                if ($value === null) continue;
            } elseif ($key === 'capture_scale') {
                if (!is_numeric($value)) continue;
                $value = max(0.1, min(2, (float) $value));
            } elseif ($key === 'device') {
                $value = $this->normalizeDevice((string) $value);
                if ($value === null) continue;
            } elseif ($key === 'pointer_type') {
                $value = Str::limit((string) $value, 16, '');
            } elseif ($key === 'scroll_source') {
                $value = Str::limit((string) $value, 64, '');
            } elseif (in_array($key, ['reason', 'end_reason', 'visibility_state'], true)) {
                $value = Str::limit((string) $value, 64, '');
            } elseif ($key === 'surface') {
                $value = in_array((string) $value, ['page', 'widget'], true) ? (string) $value : null;
                if ($value === null) continue;
            }
            $result[$key] = $value;
        }
        return array_slice($result, 0, 30, true);
    }

    private function sanitizeScrollPositions(mixed $value): ?string
    {
        if (is_string($value)) {
            try {
                $positions = json_decode($value, true, 6, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                return null;
            }
        } elseif (is_array($value)) {
            $positions = $value;
        } else {
            return null;
        }
        if (!is_array($positions)) return null;

        $safe = [];
        foreach (array_slice($positions, 0, 128) as $position) {
            if (!is_array($position) || !is_array($position['path'] ?? null)) continue;
            $path = [];
            foreach (array_slice($position['path'], 0, 128) as $index) {
                if (!is_numeric($index) || (int) $index < 0) {
                    $path = [];
                    break;
                }
                $path[] = (int) $index;
            }
            if (!$path || !is_numeric($position['left'] ?? null) || !is_numeric($position['top'] ?? null)) continue;
            $safe[] = [
                'path' => $path,
                'left' => max(0, min(20000, (int) $position['left'])),
                'top' => max(0, min(20000, (int) $position['top'])),
            ];
        }

        $encoded = json_encode($safe, JSON_UNESCAPED_SLASHES);
        return is_string($encoded) && strlen($encoded) <= 32768 ? $encoded : null;
    }

    private function occurredAt(?string $value): Carbon
    {
        try {
            $date = $value ? Carbon::parse($value) : now();
        } catch (\Throwable) {
            $date = now();
        }
        return $date->between(now()->subDays(2), now()->addMinutes(5)) ? $date : now();
    }

    private function safeUrl(string $url): string
    {
        $parts = parse_url($url);
        if (!$parts || empty($parts['scheme']) || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)) return '';
        return ($parts['scheme'] . '://' . ($parts['host'] ?? '') . ($parts['path'] ?? ''));
    }

    private function safeScreenshotUrl(string $url): string
    {
        $url = trim($url);
        if (str_starts_with($url, '/storage/visitor-intelligence/frames/')) {
            return str_contains($url, '..') ? '' : $url;
        }

        return $this->safeUrl($url);
    }

    private function safeLabel(mixed $label): ?string
    {
        if (!is_scalar($label)) return null;
        $value = trim(strip_tags((string) $label));
        return preg_match('/(?:@|\+?\d[\d\s().-]{7,})/', $value) ? null : Str::limit($value, 255, '');
    }

    private function detectDevice(?string $userAgent): ?string
    {
        $agent = strtolower((string) $userAgent);
        if (str_contains($agent, 'tablet') || str_contains($agent, 'ipad') || (str_contains($agent, 'android') && !str_contains($agent, 'mobile'))) return 'tablet';
        return str_contains($agent, 'mobile') ? 'mobile' : 'desktop';
    }

    private function normalizeDevice(string $device): ?string
    {
        return in_array($device, ['desktop', 'mobile', 'tablet'], true) ? $device : null;
    }
}
