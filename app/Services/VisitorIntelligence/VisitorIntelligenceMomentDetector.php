<?php

namespace App\Services\VisitorIntelligence;

use App\Enums\AnalyticsEventType;
use App\Models\AnalyticsEvent;
use Illuminate\Support\Collection;

class VisitorIntelligenceMomentDetector
{
    /**
     * Select a small, evidence-linked set of moments. Pointer moves are never
     * sent individually to the model; they only contribute to pause/context
     * detection through the surrounding semantic events.
     *
     * @return array<int, array<string, mixed>>
     */
    public function detect(Collection $events, ?int $maxMoments = null): array
    {
        $maxMoments ??= max(1, (int) config('visitor-intelligence.ai.max_moments', 12));
        $moments = [];
        $lastScrollY = null;
        $lastScrollAt = null;
        $pointerCount = 0;

        foreach ($events as $event) {
            if (!$event instanceof AnalyticsEvent) continue;
            $type = (string) $event->event_type;
            $metadata = is_array($event->metadata) ? $event->metadata : [];
            if ($type === AnalyticsEventType::POINTER_MOVE->value) {
                $pointerCount++;
                continue;
            }

            $reason = $this->reasonFor($type, $event, $metadata, $lastScrollY);
            if ($type === AnalyticsEventType::SCROLL_DEPTH->value) {
                $scrollY = $this->number($metadata['scroll_y'] ?? null);
                $lastScrollY = $scrollY ?? $lastScrollY;
                $lastScrollAt = $event->occurred_at?->valueOf() ?? $lastScrollAt;
            }

            if ($reason === null) continue;

            $timestamp = $event->occurred_at?->valueOf();
            if ($timestamp === null) continue;
            $moment = [
                'id' => 'moment-'.count($moments).'-'.substr((string) $event->id, 0, 8),
                'event_id' => (string) $event->id,
                'event_type' => $type,
                'occurred_at' => $event->occurred_at?->toISOString(),
                'replay_timestamp' => $timestamp,
                'path' => data_get($metadata, 'path'),
                'reason' => $reason['label'],
                'visual_priority' => $reason['visual_priority'],
                'needs_visual_context' => $reason['needs_visual_context'],
                'capture_candidate' => $reason['capture_candidate'],
                'pointer_moves_since_previous' => $pointerCount,
                'event_context' => [$this->eventContext($event, $metadata)],
            ];
            $pointerCount = 0;

            $previous = $moments[count($moments) - 1] ?? null;
            if ($previous && abs($timestamp - (int) ($previous['replay_timestamp'] ?? 0)) <= 1200) {
                $previous['event_ids'][] = (string) $event->id;
                $previous['event_types'][] = $type;
                $previous['reasons'][] = $reason['label'];
                $previous['needs_visual_context'] = $previous['needs_visual_context'] || $moment['needs_visual_context'];
                $previous['capture_candidate'] = $previous['capture_candidate'] || $moment['capture_candidate'];
                $previous['pointer_moves_since_previous'] = (int) ($previous['pointer_moves_since_previous'] ?? 0)
                    + (int) ($moment['pointer_moves_since_previous'] ?? 0);
                $previous['event_context'] = array_slice(array_merge(
                    (array) ($previous['event_context'] ?? []),
                    (array) ($moment['event_context'] ?? []),
                ), 0, 12);
                $moments[count($moments) - 1] = $previous;
                continue;
            }

            $moment['event_ids'] = [(string) $event->id];
            $moment['event_types'] = [$type];
            $moment['reasons'] = [$reason['label']];
            $moments[] = $moment;
        }

        if ($moments === [] && $events->isNotEmpty()) {
            $first = $events->first();
            if ($first instanceof AnalyticsEvent && $first->occurred_at) {
                $moments[] = [
                    'id' => 'moment-0-'.substr((string) $first->id, 0, 8),
                    'event_id' => (string) $first->id,
                    'event_ids' => [(string) $first->id],
                    'event_type' => (string) $first->event_type,
                    'event_types' => [(string) $first->event_type],
                    'occurred_at' => $first->occurred_at->toISOString(),
                    'replay_timestamp' => $first->occurred_at->valueOf(),
                    'path' => data_get($first->metadata, 'path'),
                    'reason' => 'point_de_depart_du_parcours',
                    'reasons' => ['point_de_depart_du_parcours'],
                    'visual_priority' => 'low',
                    'needs_visual_context' => false,
                    'capture_candidate' => false,
                    'pointer_moves_since_previous' => $pointerCount,
                    'event_context' => $first instanceof AnalyticsEvent
                        ? [$this->eventContext($first, is_array($first->metadata) ? $first->metadata : [])]
                        : [],
                ];
            }
        }

        return collect($moments)
            ->sortByDesc(fn (array $moment): int => $this->priority($moment['visual_priority'] ?? 'low'))
            ->take($maxMoments)
            ->sortBy('replay_timestamp')
            ->values()
            ->all();
    }

    private function reasonFor(string $type, AnalyticsEvent $event, array $metadata, ?int $lastScrollY): ?array
    {
        $important = [
            AnalyticsEventType::PAGE_VIEW->value => ['label' => 'arrivee_sur_page', 'priority' => 'medium', 'visual' => true, 'capture' => false],
            AnalyticsEventType::NAVIGATION->value => ['label' => 'navigation', 'priority' => 'medium', 'visual' => true, 'capture' => false],
            AnalyticsEventType::CLICK->value => ['label' => 'clic', 'priority' => 'high', 'visual' => true, 'capture' => true],
            AnalyticsEventType::CTA_IMPRESSION->value => ['label' => 'cta_visible', 'priority' => 'high', 'visual' => true, 'capture' => true],
            AnalyticsEventType::CTA_CLICK->value => ['label' => 'cta_clique', 'priority' => 'high', 'visual' => true, 'capture' => true],
            AnalyticsEventType::FORM_START->value => ['label' => 'formulaire_commence', 'priority' => 'high', 'visual' => true, 'capture' => true],
            AnalyticsEventType::FORM_SUBMIT->value => ['label' => 'formulaire_soumis', 'priority' => 'high', 'visual' => true, 'capture' => true],
            AnalyticsEventType::INACTIVITY_START->value => ['label' => 'pause_prolongee', 'priority' => 'high', 'visual' => true, 'capture' => true],
            AnalyticsEventType::INACTIVITY_END->value => ['label' => 'reprise_apres_pause', 'priority' => 'medium', 'visual' => true, 'capture' => false],
            AnalyticsEventType::SESSION_END->value => ['label' => 'fin_de_session', 'priority' => 'high', 'visual' => true, 'capture' => true],
            AnalyticsEventType::WIDGET_OPENED->value => ['label' => 'interaction_elchat', 'priority' => 'medium', 'visual' => true, 'capture' => false],
            AnalyticsEventType::MESSAGE_SENT->value => ['label' => 'message_elchat', 'priority' => 'high', 'visual' => false, 'capture' => false],
            AnalyticsEventType::UNANSWERED_QUESTION->value => ['label' => 'friction_question_sans_reponse', 'priority' => 'high', 'visual' => true, 'capture' => true],
            AnalyticsEventType::LOW_CONFIDENCE_ANSWER->value => ['label' => 'reponse_faible_confiance', 'priority' => 'high', 'visual' => true, 'capture' => true],
            AnalyticsEventType::PRICING_INTENT_DETECTED->value => ['label' => 'intention_prix', 'priority' => 'high', 'visual' => true, 'capture' => true],
            AnalyticsEventType::COMMERCIAL_INTENT_DETECTED->value => ['label' => 'intention_commerciale', 'priority' => 'high', 'visual' => true, 'capture' => true],
            AnalyticsEventType::PURCHASE_INTENT_DETECTED->value => ['label' => 'intention_achat', 'priority' => 'high', 'visual' => true, 'capture' => true],
            AnalyticsEventType::PRODUCT_VIEWED->value => ['label' => 'produit_consulte', 'priority' => 'medium', 'visual' => true, 'capture' => true],
            AnalyticsEventType::PRODUCT_CLICKED->value => ['label' => 'produit_clique', 'priority' => 'high', 'visual' => true, 'capture' => true],
            AnalyticsEventType::LEAD_CREATED->value => ['label' => 'lead', 'priority' => 'high', 'visual' => false, 'capture' => false],
            AnalyticsEventType::MEETING_BOOKED->value => ['label' => 'rendez_vous', 'priority' => 'high', 'visual' => false, 'capture' => false],
            AnalyticsEventType::CONVERSION->value => ['label' => 'conversion', 'priority' => 'high', 'visual' => false, 'capture' => false],
        ];
        if (isset($important[$type])) {
            $item = $important[$type];
            return [
                'label' => $item['label'],
                'visual_priority' => $item['priority'],
                'needs_visual_context' => $item['visual'],
                'capture_candidate' => $item['capture'],
            ];
        }

        if ($type === AnalyticsEventType::SCROLL_DEPTH->value) {
            $scrollY = $this->number($metadata['scroll_y'] ?? null);
            $viewportHeight = max(1, $this->number($metadata['viewport_height'] ?? null) ?? 1);
            $depth = $this->number($metadata['depth'] ?? null);
            if ($scrollY !== null && ($lastScrollY === null || abs($scrollY - $lastScrollY) >= $viewportHeight * 0.65)) {
                return [
                    'label' => $depth !== null ? 'scroll_important_'.(int) $depth.'pct' : 'scroll_important',
                    'visual_priority' => 'high',
                    'needs_visual_context' => true,
                    'capture_candidate' => true,
                ];
            }
        }

        return null;
    }

    private function priority(string $priority): int
    {
        return ['high' => 3, 'medium' => 2, 'low' => 1][$priority] ?? 1;
    }

    /**
     * Keep a compact, privacy-conscious description of the semantic event
     * surrounding a visual moment. This is context for the reasoning model;
     * it is never presented as a fact extracted from the screenshot itself.
     *
     * @return array<string, mixed>
     */
    private function eventContext(AnalyticsEvent $event, array $metadata): array
    {
        $context = [
            'event_id' => (string) $event->id,
            'event_type' => (string) $event->event_type,
            'path' => $this->text($metadata['path'] ?? $metadata['page_path'] ?? null, 500),
            'page_url' => $this->text($metadata['page_url'] ?? $metadata['url'] ?? null, 800),
            'target' => $this->text($metadata['target'] ?? $metadata['target_text'] ?? $metadata['label'] ?? null, 300),
            'selector' => $this->text($metadata['selector'] ?? $metadata['target_selector'] ?? null, 500),
            'scroll_y' => $this->number($metadata['scroll_y'] ?? null),
            'depth' => $this->number($metadata['depth'] ?? null),
            'viewport_height' => $this->number($metadata['viewport_height'] ?? null),
            'x' => $this->number($metadata['x'] ?? $metadata['client_x'] ?? null),
            'y' => $this->number($metadata['y'] ?? $metadata['client_y'] ?? null),
            'duration_ms' => $this->number($metadata['duration_ms'] ?? null),
            'idle_duration_ms' => $this->number($metadata['idle_duration_ms'] ?? null),
        ];

        return collect($context)
            ->reject(fn (mixed $value): bool => $value === null || $value === '')
            ->all();
    }

    private function text(mixed $value, int $limit): ?string
    {
        if (! is_scalar($value)) return null;
        $text = trim((string) $value);
        if ($text === '') return null;
        $text = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/iu', '[email]', $text) ?? $text;
        $text = preg_replace('/(?:\+?\d[\d .()\-]{7,}\d)/u', '[phone]', $text) ?? $text;
        return mb_substr($text, 0, $limit);
    }

    private function number(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
