<?php

namespace App\Services\VisitorIntelligence;

use App\Models\VisitorSession;
use Illuminate\Support\Collection;

/**
 * Shared evidence pipeline for Visitor Intelligence analyses.
 *
 * This is deliberately not an LLM service. It owns the deterministic part
 * that must behave identically for the Visitor Intelligence "Analyse IA" and
 * Website Growth Advisor flows: select important moments, then reconstruct
 * only the targeted rrweb states.
 */
final class VisitorIntelligenceSessionEvidenceService
{
    public function __construct(
        private readonly VisitorIntelligenceMomentDetector $momentDetector,
        private readonly VisitorIntelligenceReplayContextService $replayContext,
    ) {
    }

    /** @return array<int, array<string, mixed>> */
    public function detect(Collection $events, ?int $maxMoments = null): array
    {
        return $this->momentDetector->detect(
            $events,
            $maxMoments ?? max(1, (int) config('visitor-intelligence.ai.max_moments', 12)),
        );
    }

    /** @return array<string, mixed> */
    public function render(VisitorSession $session, array $moments): array
    {
        return $this->replayContext->build($session, $moments);
    }

    /** @return array{moments: array<int, array<string, mixed>>, visual_context: array<string, mixed>} */
    public function investigate(
        VisitorSession $session,
        Collection $events,
        ?int $maxMoments = null,
    ): array {
        $moments = $this->detect($events, $maxMoments);

        return [
            'moments' => $moments,
            'visual_context' => $this->render($session, $moments),
        ];
    }
}
