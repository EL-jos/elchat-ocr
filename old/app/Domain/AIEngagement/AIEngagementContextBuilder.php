<?php

namespace App\Domain\AIEngagement;

use App\Models\AnalyticsEvent;
use App\Models\Conversation;
use App\Models\Proactive\ProactiveMessage;
use App\Models\VisitorSession;
use Carbon\CarbonInterface;

class AIEngagementContextBuilder
{
    public function build(AnalyticsEvent $event): array
    {
        $session = VisitorSession::query()
            ->where('site_id', $event->site_id)
            ->where('session_key', $event->session_id)
            ->with('summary')
            ->first();

        $metadata = $event->metadata ?: [];
        $currentPath = $this->path($metadata);
        $currentTitle = (string) ($metadata['title'] ?? '');
        $sessionEvents = AnalyticsEvent::query()
            ->where('site_id', $event->site_id)
            ->when(
                $event->session_id,
                fn ($query) => $query->where('session_id', $event->session_id),
                fn ($query) => $query->whereKey($event->id),
            )
            ->orderBy('occurred_at')
            ->limit(200)
            ->get(['id', 'event_type', 'metadata', 'resource_type', 'resource_id', 'occurred_at']);

        $pageEvents = $sessionEvents->filter(fn (AnalyticsEvent $item): bool => in_array($item->event_type, ['page_view', 'navigation'], true));
        $paths = $pageEvents
            ->map(fn (AnalyticsEvent $item): string => $this->path($item->metadata ?: []))
            ->filter()
            ->unique()
            ->values();
        $currentPageEvent = $pageEvents
            ->filter(fn (AnalyticsEvent $item): bool => $this->path($item->metadata ?: []) === $currentPath)
            ->last();
        $scrollDepth = (int) ($sessionEvents
            ->where('event_type', 'scroll_depth')
            ->map(fn (AnalyticsEvent $item): int => (int) data_get($item->metadata ?: [], 'depth', 0))
            ->max() ?: 0);
        $clicks = $sessionEvents->where('event_type', 'click')->count();
        $ctaViews = $sessionEvents->where('event_type', 'cta_impression')->count();
        $ctaClicks = $sessionEvents->where('event_type', 'cta_click')->count();
        $products = $sessionEvents->whereIn('event_type', ['product_viewed', 'product_clicked'])->count();
        $documents = $sessionEvents->whereIn('event_type', ['document_clicked', 'document_downloaded'])->count();
        $intent = $this->intent($event, $sessionEvents, $currentPath);
        $pageType = $this->pageType($currentPath, $currentTitle);

        $visitorEvents = AnalyticsEvent::query()
            ->where('site_id', $event->site_id)
            ->when($event->visitor_id, fn ($query) => $query->where('visitor_id', $event->visitor_id))
            ->latest('occurred_at')
            ->limit(200)
            ->get(['event_type', 'occurred_at']);
        $lastClose = $visitorEvents->first(fn (AnalyticsEvent $item): bool => in_array($item->event_type, ['widget_close', 'widget_closed'], true));
        $lastRefusal = $visitorEvents->first(fn (AnalyticsEvent $item): bool => in_array($item->event_type, ['proactive_refused', 'proactive_unsubscribed', 'engagement_rejected', 'engagement_dismissed'], true));
        $lastEngagement = ProactiveMessage::query()
            ->where('site_id', $event->site_id)
            ->when($event->visitor_id, fn ($query) => $query->where('visitor_id', $event->visitor_id))
            ->where('channel', 'website')
            ->whereNotNull('sent_at')
            ->latest('sent_at')
            ->first(['id', 'sent_at', 'metadata']);

        $activeConversation = Conversation::query()
            ->where('site_id', $event->site_id)
            ->when($event->visitor_id, fn ($query) => $query->where('visitor_id', $event->visitor_id))
            ->where('status', 'active')
            ->where('updated_at', '>=', now()->subMinutes(30))
            ->latest('updated_at')
            ->first(['id', 'updated_at']);

        $activeProactiveMessage = ProactiveMessage::query()
            ->where('site_id', $event->site_id)
            ->when($event->visitor_id, fn ($query) => $query->where('visitor_id', $event->visitor_id))
            ->where('channel', 'website')
            ->whereIn('status', ['scheduled', 'processing', 'retrying', 'sent'])
            ->where(function ($query) {
                $query->where('sent_at', '>=', now()->subHours(24))
                    ->orWhere('created_at', '>=', now()->subHours(24));
            })
            ->latest('created_at')
            ->first(['id', 'status', 'created_at']);

        return [
            'event' => [
                'id' => $event->id,
                'type' => $event->event_type,
                'occurred_at' => $event->occurred_at?->toISOString(),
            ],
            'session' => [
                'id' => $session?->id,
                'key' => $event->session_id,
                'duration_seconds' => $this->secondsBetween($session?->started_at, $event->occurred_at),
                'page_count' => max((int) ($session?->page_count ?? 0), $paths->count()),
                'unique_page_count' => $paths->count(),
                'is_new_visitor' => (bool) ($session?->is_new_visitor ?? true),
                'event_count' => $sessionEvents->count(),
            ],
            'page' => [
                'path' => $currentPath,
                'title' => $currentTitle,
                'type' => $pageType,
                'time_on_page_seconds' => $this->secondsBetween($currentPageEvent?->occurred_at, now()),
                'pages' => $paths->all(),
            ],
            'behavior' => [
                'scroll_depth' => max(0, min(100, $scrollDepth)),
                'clicks' => $clicks,
                'cta_views' => $ctaViews,
                'cta_clicks' => $ctaClicks,
                'products_viewed' => $products,
                'documents_viewed' => $documents,
            ],
            'intent' => $intent,
            'history' => [
                'last_engagement_at' => $lastEngagement?->sent_at?->toISOString(),
                'last_close_at' => $lastClose?->occurred_at?->toISOString(),
                'last_refusal_at' => $lastRefusal?->occurred_at?->toISOString(),
                'has_active_conversation' => (bool) $activeConversation,
                'active_conversation_id' => $activeConversation?->id,
                'active_proactive_message_id' => $activeProactiveMessage?->id,
            ],
            'visitor' => [
                'id' => $event->visitor_id,
                'is_returning' => !((bool) ($session?->is_new_visitor ?? true)),
            ],
        ];
    }

    private function intent(AnalyticsEvent $event, $sessionEvents, string $currentPath): array
    {
        $eventTypes = $sessionEvents->pluck('event_type')->push($event->event_type)->map(fn ($type): string => (string) $type);
        $metadataIntent = data_get($event->metadata ?: [], 'intent_level') ?: data_get($event->metadata ?: [], 'intent');
        $highIntentEvents = [
            'commercial_intent_detected', 'purchase_intent_detected', 'pricing_intent_detected',
            'booking_intent_detected', 'lead_created', 'meeting_booked', 'appointment_created',
            'purchase_completed',
        ];
        $commercial = $eventTypes->contains(fn (string $type): bool => in_array($type, $highIntentEvents, true));
        $support = $eventTypes->contains(fn (string $type): bool => in_array($type, [
            'support_intent_detected', 'unanswered_question', 'low_confidence_answer',
        ], true));
        $friction = $eventTypes->contains(fn (string $type): bool => in_array($type, [
            'unanswered_question', 'low_confidence_answer',
        ], true));
        $pathValue = mb_strtolower($currentPath);
        $commercial = $commercial || str_contains($pathValue, 'pricing') || str_contains($pathValue, 'tarif') || str_contains($pathValue, 'devis');
        $level = in_array($metadataIntent, ['low', 'medium', 'high'], true) ? $metadataIntent : 'low';
        if ($level === 'low' && $commercial) {
            $level = $eventTypes->contains(fn (string $type): bool => in_array($type, $highIntentEvents, true)) ? 'high' : 'medium';
        }
        if ($level === 'low' && $support) $level = 'medium';

        return [
            'level' => $level,
            'commercial' => $commercial,
            'support' => $support,
            'friction' => $friction,
            'evidence' => $eventTypes->filter(fn (string $type): bool => str_contains($type, 'intent') || in_array($type, ['unanswered_question', 'low_confidence_answer'], true))->unique()->values()->all(),
        ];
    }

    private function path(array $metadata): string
    {
        $value = (string) ($metadata['path'] ?? $metadata['page_url'] ?? '/');
        $parsed = parse_url($value, PHP_URL_PATH);
        return (string) ($parsed ?: ($value ?: '/'));
    }

    private function pageType(string $path, string $title): string
    {
        $value = mb_strtolower($path.' '.$title);
        return match (true) {
            str_contains($value, 'pricing') || str_contains($value, 'tarif') || str_contains($value, 'prix') || str_contains($value, 'offre') => 'pricing',
            str_contains($value, 'product') || str_contains($value, 'produit') || str_contains($value, 'solution') => 'product',
            str_contains($value, 'support') || str_contains($value, 'help') || str_contains($value, 'aide') || str_contains($value, 'faq') => 'support',
            str_contains($value, 'doc') || str_contains($value, 'guide') || str_contains($value, 'ressource') || str_contains($value, 'manual') => 'documentation',
            str_contains($value, 'contact') || str_contains($value, 'demo') || str_contains($value, 'rendez') || str_contains($value, 'devis') || str_contains($value, 'quote') || str_contains($value, 'booking') || str_contains($value, 'reservation') => 'contact',
            trim($path, '/') === '' || trim($path, '/') === 'home' => 'home',
            default => 'other',
        };
    }

    private function secondsBetween(?CarbonInterface $from, ?CarbonInterface $to): int
    {
        if (!$from || !$to) return 0;
        return max(0, min(86400, (int) $from->diffInSeconds($to, true)));
    }
}
