<?php

namespace App\Services\VisitorIntelligence;

use App\Enums\AnalyticsEventType;
use App\Models\AnalyticsEvent;
use App\Models\VisitorIntelligenceAction;
use App\Models\VisitorOpportunity;
use App\Models\VisitorSession;
use App\Models\VisitorSessionSummary;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class VisitorIntelligenceSummaryService
{
    public function __construct(private readonly VisitorIntelligenceEventService $events)
    {
    }

    /**
     * Build an evidence-linked summary. This first implementation is
     * deterministic and deliberately does not claim causality or invent text
     * from a visitor's conversation.
     */
    public function rebuild(VisitorSession $session): VisitorSessionSummary
    {
        $rows = AnalyticsEvent::query()
            ->where('site_id', $session->site_id)
            ->where('session_id', $session->session_key)
            ->orderBy('occurred_at')
            ->get(['id', 'event_type', 'resource_type', 'resource_id', 'label', 'metadata', 'occurred_at']);

        $types = $rows->pluck('event_type');
        $pages = $rows->filter(fn ($row) => in_array($row->event_type, [
            AnalyticsEventType::PAGE_VIEW->value,
            AnalyticsEventType::NAVIGATION->value,
        ], true))->map(fn ($row) => [
            'path' => data_get($row->metadata, 'path'),
            'url' => data_get($row->metadata, 'page_url'),
            'occurred_at' => $row->occurred_at?->toISOString(),
        ])->filter(fn ($page) => $page['path'] || $page['url'])->unique(fn ($page) => $page['path'] ?: $page['url'])->values()->take(50)->all();

        $ctas = $rows->filter(fn ($row) => in_array($row->event_type, [
            AnalyticsEventType::CTA_IMPRESSION->value,
            AnalyticsEventType::CTA_CLICK->value,
        ], true))->map(fn ($row) => [
            'id' => $row->resource_id,
            'label' => $row->label,
            'event_type' => $row->event_type,
            'occurred_at' => $row->occurred_at?->toISOString(),
        ])->values()->take(30)->all();

        $purchaseSignals = $rows->filter(fn ($row) => in_array($row->event_type, [
            AnalyticsEventType::PRICING_INTENT_DETECTED->value,
            AnalyticsEventType::COMMERCIAL_INTENT_DETECTED->value,
            AnalyticsEventType::PURCHASE_INTENT_DETECTED->value,
            AnalyticsEventType::PRODUCT_VIEWED->value,
            AnalyticsEventType::PRODUCT_CLICKED->value,
            AnalyticsEventType::CTA_CLICK->value,
            AnalyticsEventType::LEAD_CREATED->value,
            AnalyticsEventType::MEETING_BOOKED->value,
        ], true))->map(fn ($row) => $this->evidence($row))->values()->take(30)->all();

        $friction = $rows->filter(fn ($row) => in_array($row->event_type, [
            AnalyticsEventType::UNANSWERED_QUESTION->value,
            AnalyticsEventType::LOW_CONFIDENCE_ANSWER->value,
        ], true))->map(fn ($row) => $this->evidence($row))->values()->take(30)->all();

        $intentLevel = $this->intentLevel($session, $rows);
        $goal = $this->goal($types->all());
        $outcome = $session->converted
            ? 'converted'
            : ($session->ended_at ? 'abandoned_or_unknown' : ($session->has_widget_interaction ? 'engaged' : 'unknown'));
        $abandonment = $session->ended_at && !$session->converted
            ? [['signal' => 'session_end_without_observed_conversion', 'observed_at' => $session->ended_at->toISOString()]]
            : [];
        $evidence = $rows->filter(fn ($row) => in_array($row->event_type, [
            AnalyticsEventType::PAGE_VIEW->value,
            AnalyticsEventType::WIDGET_OPENED->value,
            AnalyticsEventType::MESSAGE_SENT->value,
            AnalyticsEventType::CTA_CLICK->value,
            AnalyticsEventType::LEAD_CREATED->value,
            AnalyticsEventType::MEETING_BOOKED->value,
            AnalyticsEventType::PURCHASE_COMPLETED->value,
            AnalyticsEventType::SESSION_END->value,
        ], true))->map(fn ($row) => $this->evidence($row))->values()->take(50)->all();

        $summaryText = $this->summaryText($session, $intentLevel, $goal, $outcome, count($pages), count($purchaseSignals), count($friction));
        $summary = VisitorSessionSummary::query()->updateOrCreate(
            ['visitor_session_id' => $session->id],
            [
                'account_id' => $session->account_id,
                'site_id' => $session->site_id,
                'summary' => $summaryText,
                'intent_level' => $intentLevel,
                'probable_goal' => $goal,
                'probable_outcome' => $outcome,
                'friction_points' => $friction,
                'purchase_signals' => $purchaseSignals,
                'unresolved_questions' => $friction,
                'important_pages' => $pages,
                'important_ctas' => $ctas,
                'abandonment_signals' => $abandonment,
                'evidence' => $evidence,
                'analysis_version' => 'deterministic-1',
                'generated_at' => now(),
            ],
        );

        $this->detectOpportunities($session, $summary, $purchaseSignals, $friction);
        return $summary;
    }

    public function detectOpportunities(VisitorSession $session, VisitorSessionSummary $summary, array $purchaseSignals = [], array $friction = []): void
    {
        if ($summary->intent_level === 'high' && $session->ended_at && !$session->converted) {
            VisitorOpportunity::query()->firstOrCreate(
                ['site_id' => $session->site_id, 'deduplication_key' => hash('sha256', "high-intent-abandonment|{$session->id}")],
                [
                    'account_id' => $session->account_id, 'visitor_session_id' => $session->id,
                    'visitor_id' => $session->visitor_id, 'type' => 'high_intent_abandonment',
                    'title' => 'Visiteur à forte intention parti sans conversion observée',
                    'description' => 'Le parcours contient plusieurs signaux d’intention et s’est terminé sans outcome business observé.',
                    'evidence' => ['signals' => $purchaseSignals, 'session_id' => $session->session_key],
                    'impact' => 'high', 'priority' => 'high', 'confidence' => 78.00,
                    'recommendations' => ['Vérifier le contexte de la conversation et proposer une relance autorisée.'],
                    'actions' => ['proactive_campaign', 'create_opportunity'], 'status' => 'open', 'detected_at' => now(),
                ],
            );
        }

        if ($friction !== []) {
            VisitorOpportunity::query()->firstOrCreate(
                ['site_id' => $session->site_id, 'deduplication_key' => hash('sha256', "session-friction|{$session->id}")],
                [
                    'account_id' => $session->account_id, 'visitor_session_id' => $session->id,
                    'visitor_id' => $session->visitor_id, 'type' => 'observed_friction',
                    'title' => 'Friction observée dans un parcours visiteur',
                    'description' => 'Des questions sans réponse ou des réponses à faible confiance ont été observées.',
                    'evidence' => ['signals' => $friction, 'session_id' => $session->session_key],
                    'impact' => 'medium', 'priority' => 'medium', 'confidence' => 71.00,
                    'recommendations' => ['Revoir la source de connaissance ou le parcours de la page concernée.'],
                    'actions' => ['create_opportunity'], 'status' => 'open', 'detected_at' => now(),
                ],
            );
        }
    }

    private function intentLevel(VisitorSession $session, $rows): string
    {
        $declared = $rows->map(fn ($row) => data_get($row->metadata, 'intent_level'))
            ->filter(fn ($value) => in_array($value, ['low', 'medium', 'high'], true));
        if ($declared->contains('high')) return 'high';
        if ($declared->contains('medium')) return 'medium';

        $highSignals = $rows->whereIn('event_type', [
            AnalyticsEventType::PURCHASE_INTENT_DETECTED->value,
            AnalyticsEventType::PRICING_INTENT_DETECTED->value,
            AnalyticsEventType::LEAD_CREATED->value,
            AnalyticsEventType::MEETING_BOOKED->value,
            AnalyticsEventType::PURCHASE_COMPLETED->value,
        ])->count();
        $mediumSignals = $rows->whereIn('event_type', [
            AnalyticsEventType::COMMERCIAL_INTENT_DETECTED->value,
            AnalyticsEventType::PRODUCT_CLICKED->value,
            AnalyticsEventType::CTA_CLICK->value,
        ])->count();

        return $highSignals >= 2 ? 'high' : ($highSignals === 1 || $mediumSignals >= 2 ? 'medium' : 'low');
    }

    private function goal(array $types): ?string
    {
        if (in_array(AnalyticsEventType::MEETING_BOOKED->value, $types, true) || in_array(AnalyticsEventType::APPOINTMENT_CREATED->value, $types, true)) return 'Prendre rendez-vous';
        if (in_array(AnalyticsEventType::PURCHASE_INTENT_DETECTED->value, $types, true) || in_array(AnalyticsEventType::PURCHASE_COMPLETED->value, $types, true)) return 'Évaluer ou réaliser un achat';
        if (in_array(AnalyticsEventType::LEAD_CREATED->value, $types, true)) return 'Être recontacté';
        if (in_array(AnalyticsEventType::PRICING_INTENT_DETECTED->value, $types, true)) return 'Comprendre le prix';
        return in_array(AnalyticsEventType::MESSAGE_SENT->value, $types, true) ? 'Obtenir une réponse' : null;
    }

    private function evidence(AnalyticsEvent $row): array
    {
        return [
            'event_type' => $row->event_type,
            'path' => data_get($row->metadata, 'path'),
            'resource_id' => $row->resource_id,
            'label' => $row->label,
            'occurred_at' => $row->occurred_at?->toISOString(),
        ];
    }

    private function summaryText(VisitorSession $session, string $intent, ?string $goal, string $outcome, int $pages, int $signals, int $friction): string
    {
        $parts = ["{$pages} page(s) observée(s)", $session->has_widget_interaction ? 'interaction ELChat observée' : 'aucune interaction ELChat observée'];
        if ($goal) $parts[] = "objectif probable : {$goal}";
        if ($signals) $parts[] = "{$signals} signal(aux) commercial(aux)";
        if ($friction) $parts[] = "{$friction} signal(aux) de friction";
        return Str::limit('Parcours observé : '.implode(' ; ', $parts).". Niveau d’intention {$intent}; outcome {$outcome}.", 1000, '');
    }
}
