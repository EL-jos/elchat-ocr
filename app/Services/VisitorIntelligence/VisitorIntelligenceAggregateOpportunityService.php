<?php

namespace App\Services\VisitorIntelligence;

use App\Enums\AnalyticsEventType;
use App\Models\AnalyticsEvent;
use App\Models\Site;
use App\Models\UnansweredQuestion;
use App\Models\VisitorOpportunity;
use App\Models\VisitorSession;
use Illuminate\Support\Str;

class VisitorIntelligenceAggregateOpportunityService
{
    public function rebuild(Site $site, int $days = 7): void
    {
        $days = max(1, min(31, $days));
        $from = now()->subDays($days);
        $window = now()->format('o-W');

        $sessions = VisitorSession::query()
            ->where('site_id', $site->id)
            ->where('started_at', '>=', $from)
            ->get(['id', 'session_key', 'visitor_id', 'intent_level', 'ended_at', 'converted', 'has_widget_interaction']);

        $abandoned = $sessions->filter(fn ($session) => $session->intent_level === 'high' && $session->ended_at !== null && $session->converted === false);
        if ($abandoned->count() >= 5) {
            VisitorOpportunity::query()->firstOrCreate(
                ['site_id' => $site->id, 'deduplication_key' => hash('sha256', "aggregate|high-intent-abandonment|{$window}")],
                [
                    'account_id' => $site->account_id, 'type' => 'aggregate_high_intent_abandonment',
                    'title' => $abandoned->count().' visiteurs à forte intention sont partis sans conversion observée',
                    'description' => 'Agrégation des sessions dont l’intention et la fin de parcours sont observées, sans inférer la cause de l’abandon.',
                    'evidence' => [
                        'window_days' => $days, 'session_count' => $abandoned->count(),
                        'session_ids' => $abandoned->pluck('id')->take(100)->values()->all(),
                    ],
                    'impact' => 'high', 'priority' => 'high', 'confidence' => 86.00,
                    'recommendations' => ['Comparer les conversations reliées et tester une relance approuvée via le moteur proactif.'],
                    'actions' => ['proactive_campaign', 'create_opportunity'], 'status' => 'open', 'detected_at' => now(),
                ],
            );
        }

        $this->detectRepeatedQuestions($site, $from, $window);
        $this->detectLowEngagementPages($site, $from, $window, $sessions);
    }

    private function detectRepeatedQuestions(Site $site, $from, string $window): void
    {
        // The existing unanswered-question table already owns the content. We
        // only hash it transiently and expose counts/evidence, never the text.
        $groups = UnansweredQuestion::query()
            ->where('site_id', $site->id)
            ->where('created_at', '>=', $from)
            ->get(['id', 'question'])
            ->groupBy(fn ($row) => hash('sha256', $this->normalize($row->question)))
            ->filter(fn ($rows) => $rows->count() >= 3)
            ->sortByDesc(fn ($rows) => $rows->count())
            ->take(10);

        foreach ($groups as $hash => $rows) {
            VisitorOpportunity::query()->firstOrCreate(
                ['site_id' => $site->id, 'deduplication_key' => hash('sha256', "aggregate|repeated-question|{$hash}|{$window}")],
                [
                    'account_id' => $site->account_id, 'type' => 'repeated_unanswered_question',
                    'title' => 'Question récurrente sans réponse suffisamment fiable',
                    'description' => $rows->count().' occurrences apparentées ont été observées sur la période. Le contenu n’est pas recopié dans cette opportunité.',
                    'evidence' => [
                        'question_hash' => $hash, 'occurrences' => $rows->count(),
                        'unanswered_question_ids' => $rows->pluck('id')->take(100)->values()->all(),
                    ],
                    'impact' => 'medium', 'priority' => 'high', 'confidence' => 82.00,
                    'recommendations' => ['Améliorer la source de connaissance correspondante et vérifier la couverture du parcours.'],
                    'actions' => ['create_opportunity'], 'status' => 'open', 'detected_at' => now(),
                ],
            );
        }
    }

    private function detectLowEngagementPages(Site $site, $from, string $window, $sessions): void
    {
        $sessionByKey = $sessions->keyBy('session_key');
        $rows = AnalyticsEvent::query()
            ->where('site_id', $site->id)
            ->where('event_type', AnalyticsEventType::PAGE_VIEW->value)
            ->where('occurred_at', '>=', $from)
            ->whereNotNull('session_id')
            ->limit(10000)
            ->get(['session_id', 'metadata']);

        $pages = $rows->map(function ($row) use ($sessionByKey) {
            $path = (string) data_get($row->metadata, 'path', '');
            return $path !== '' && $sessionByKey->has($row->session_id)
                ? ['path' => Str::limit($path, 255, ''), 'session' => $sessionByKey->get($row->session_id)]
                : null;
        })->filter()->groupBy('path');

        foreach ($pages as $path => $pageRows) {
            $uniqueSessions = $pageRows->pluck('session.id')->unique()->count();
            if ($uniqueSessions < 20) continue;
            $engaged = $pageRows->pluck('session')->filter(fn ($session) => $session->has_widget_interaction)->pluck('id')->unique()->count();
            $engagementRate = $uniqueSessions ? $engaged / $uniqueSessions : 0;
            if ($engagementRate >= 0.10) continue;

            VisitorOpportunity::query()->firstOrCreate(
                ['site_id' => $site->id, 'deduplication_key' => hash('sha256', "aggregate|low-page-engagement|{$path}|{$window}")],
                [
                    'account_id' => $site->account_id, 'type' => 'high_traffic_low_engagement',
                    'title' => 'Page très consultée avec peu d’engagement ELChat',
                    'description' => "La page {$path} reçoit un volume significatif de sessions mais peu d’interactions ELChat observées.",
                    'evidence' => ['path' => $path, 'sessions' => $uniqueSessions, 'engaged_sessions' => $engaged, 'engagement_rate' => round($engagementRate * 100, 2)],
                    'impact' => 'medium', 'priority' => 'medium', 'confidence' => 74.00,
                    'recommendations' => ['Vérifier la visibilité du widget et le contexte de la page avant toute modification.'],
                    'actions' => ['create_opportunity'], 'status' => 'open', 'detected_at' => now(),
                ],
            );
        }
    }

    private function normalize(?string $question): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', mb_strtolower((string) $question)));
    }
}
