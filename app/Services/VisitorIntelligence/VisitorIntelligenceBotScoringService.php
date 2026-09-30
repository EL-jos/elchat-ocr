<?php

namespace App\Services\VisitorIntelligence;

use App\Models\VisitorSession;
use App\Models\VisitorSessionReplayChunk;

class VisitorIntelligenceBotScoringService
{
    /**
     * Score a session using privacy-safe browser signals and rrweb behavior.
     *
     * A low score is not automatically a human classification: human evidence
     * is required before returning "human". This prevents missing telemetry
     * from being presented as proof of a real visitor.
     *
     * @return array{
     *     score:?int,
     *     classification:string,
     *     reasons:array<int,array<string,mixed>>,
     *     features:array<string,mixed>,
     *     data_quality:array<string,mixed>
     * }
     */
    public function scoreSession(VisitorSession $session): array
    {
        $events = $this->replayEvents($session);
        $features = $this->extractFeatures($events, $session);
        $assessment = $this->assess($features, $session->client_signals ?? []);

        $session->forceFill([
            'bot_score' => $assessment['score'],
            'bot_classification' => $assessment['classification'],
            'bot_reasons' => $assessment['reasons'],
            'bot_features' => $assessment['features'],
            'bot_score_computed_at' => now(),
        ])->save();

        return $assessment;
    }

    private function replayEvents(VisitorSession $session): array
    {
        $chunks = VisitorSessionReplayChunk::query()
            ->where('site_id', $session->site_id)
            ->where('visitor_session_id', $session->id)
            ->orderBy('chunk_index')
            ->get(['payload', 'format']);

        $maxEvents = max(100, (int) config('visitor-intelligence.replay_max_events', 100000));
        $events = [];
        $seen = [];

        foreach ($chunks as $chunk) {
            foreach (RrwebChunkCodec::decode((string) $chunk->payload, (string) $chunk->format) as $event) {
                if (!is_array($event) || !is_numeric($event['timestamp'] ?? null)) continue;

                $event['timestamp'] = (int) $event['timestamp'];
                if ($event['timestamp'] <= 0) continue;

                $key = (string) ($event['type'] ?? '').'|'.$event['timestamp'].'|'.sha1((string) json_encode($event));
                if (isset($seen[$key])) continue;
                $seen[$key] = true;
                $events[] = $event;

                if (count($events) >= $maxEvents) break 2;
            }
        }

        usort($events, static fn (array $left, array $right): int => $left['timestamp'] <=> $right['timestamp']);
        return $events;
    }

    private function extractFeatures(array $events, VisitorSession $session): array
    {
        $moves = [];
        $inputTimestamps = [];
        $activityTimestamps = [];
        $firstTimestamp = $events[0]['timestamp'] ?? null;
        $lastTimestamp = $events[count($events) - 1]['timestamp'] ?? null;
        $firstInteractionTimestamp = null;
        $movesBeforeFirstInteraction = 0;
        $interactionCount = 0;
        $inputCount = 0;
        $scrollCount = 0;
        $viewportChangeCount = 0;
        $sawInteraction = false;

        foreach ($events as $event) {
            if ((int) ($event['type'] ?? -1) !== 3) continue;

            $data = is_array($event['data'] ?? null) ? $event['data'] : [];
            $source = (int) ($data['source'] ?? -1);
            $eventTimestamp = (int) $event['timestamp'];

            if ($source === 1 && is_array($data['positions'] ?? null)) {
                foreach ($data['positions'] as $position) {
                    if (!is_array($position) || !is_numeric($position['x'] ?? null) || !is_numeric($position['y'] ?? null)) continue;

                    $timestamp = $eventTimestamp + (int) ($position['timeOffset'] ?? 0);
                    $moves[] = [
                        't' => max(0, $timestamp),
                        'x' => (float) $position['x'],
                        'y' => (float) $position['y'],
                    ];
                    $activityTimestamps[] = max(0, $timestamp);
                    if (!$sawInteraction) $movesBeforeFirstInteraction++;
                }
                continue;
            }

            if ($source === 2) {
                $interactionCount++;
                $activityTimestamps[] = $eventTimestamp;
                if (!$sawInteraction) $firstInteractionTimestamp = $eventTimestamp;
                $sawInteraction = true;
                continue;
            }

            if ($source === 3) {
                $scrollCount++;
                $activityTimestamps[] = $eventTimestamp;
                continue;
            }

            if ($source === 4) {
                $viewportChangeCount++;
                continue;
            }

            // rrweb Input events contain field changes, not raw key presses.
            // Only their timing is used; input values are never persisted.
            if ($source === 5) {
                $inputCount++;
                $inputTimestamps[] = $eventTimestamp;
                $activityTimestamps[] = $eventTimestamp;
            }
        }

        usort($moves, static fn (array $left, array $right): int => $left['t'] <=> $right['t']);
        sort($activityTimestamps);

        $intervals = [];
        $zeroIntervalCount = 0;
        for ($index = 1; $index < count($moves); $index++) {
            $interval = max(0, $moves[$index]['t'] - $moves[$index - 1]['t']);
            $intervals[] = $interval;
            if ($interval === 0) $zeroIntervalCount++;
        }

        $straightness = [];
        $totalDistance = 0.0;
        $directionChanges = 0;
        $directionSamples = 0;
        $previousAngle = null;
        $positions = [];

        foreach ($moves as $move) {
            $positions[sprintf('%.1f:%.1f', $move['x'], $move['y'])] = true;
        }

        foreach (array_chunk($moves, 15) as $segment) {
            if (count($segment) < 3) continue;

            $path = 0.0;
            for ($index = 1; $index < count($segment); $index++) {
                $dx = $segment[$index]['x'] - $segment[$index - 1]['x'];
                $dy = $segment[$index]['y'] - $segment[$index - 1]['y'];
                $distance = hypot($dx, $dy);
                $path += $distance;
                $totalDistance += $distance;

                if ($distance > 0) {
                    $angle = atan2($dy, $dx);
                    if ($previousAngle !== null) {
                        $delta = abs($angle - $previousAngle);
                        if ($delta > M_PI) $delta = (2 * M_PI) - $delta;
                        $directionSamples++;
                        if ($delta >= (M_PI / 8)) $directionChanges++;
                    }
                    $previousAngle = $angle;
                }
            }

            $net = hypot(
                $segment[count($segment) - 1]['x'] - $segment[0]['x'],
                $segment[count($segment) - 1]['y'] - $segment[0]['y'],
            );
            if ($path > 0) $straightness[] = min(1, $net / $path);
        }

        $intervalMean = $intervals ? array_sum($intervals) / count($intervals) : null;
        $intervalStddev = $this->stddev($intervals);
        $intervalCv = $intervalMean !== null && $intervalMean > 0 && $intervalStddev !== null
            ? $intervalStddev / $intervalMean
            : null;
        $activitySpan = count($activityTimestamps) > 1
            ? max(0, $activityTimestamps[count($activityTimestamps) - 1] - $activityTimestamps[0])
            : 0;
        $inputSpan = count($inputTimestamps) > 1
            ? max(0, max($inputTimestamps) - min($inputTimestamps))
            : null;
        $maxPause = $this->maxInterval($activityTimestamps);
        $humanPauseMs = max(1, (int) config('visitor-intelligence.bot_detection.behavior.human_pause_ms', 1200));

        $replayDuration = $firstTimestamp !== null && $lastTimestamp !== null
            ? max(0, $lastTimestamp - $firstTimestamp)
            : 0;

        return [
            'event_count' => count($events),
            'replay_duration_ms' => $replayDuration,
            'move_count' => count($moves),
            'interaction_count' => $interactionCount,
            'scroll_count' => $scrollCount,
            'input_count' => $inputCount,
            'viewport_change_count' => $viewportChangeCount,
            'action_count' => count($activityTimestamps),
            'interval_mean_ms' => $intervalMean,
            'interval_stddev_ms' => $intervalStddev,
            'interval_cv' => $intervalCv,
            'zero_interval_ratio' => $intervals ? $zeroIntervalCount / count($intervals) : null,
            'straightness_mean' => $straightness ? array_sum($straightness) / count($straightness) : null,
            'direction_change_ratio' => $directionSamples > 0 ? $directionChanges / $directionSamples : null,
            'duplicate_pointer_ratio' => $moves ? 1 - (count($positions) / count($moves)) : null,
            'pointer_distance' => $totalDistance,
            'time_to_first_interaction_ms' => $firstInteractionTimestamp !== null && $firstTimestamp !== null
                ? max(0, $firstInteractionTimestamp - $firstTimestamp)
                : null,
            'moves_before_first_interaction' => $movesBeforeFirstInteraction,
            'max_pause_ms' => $maxPause,
            'long_pause_count' => $this->countLongPauses($activityTimestamps, $humanPauseMs),
            'activity_span_ms' => $activitySpan,
            'input_span_ms' => $inputSpan,
            'events_per_second' => $replayDuration > 0 ? count($events) / ($replayDuration / 1000) : null,
            'device' => strtolower((string) ($session->device ?? ($session->metadata['device'] ?? ''))),
            'page_count' => (int) ($session->page_count ?? 0),
            'duration_seconds' => $session->duration_seconds !== null ? (int) $session->duration_seconds : null,
            'has_widget_interaction' => (bool) $session->has_widget_interaction,
        ];
    }

    /**
     * @return array{
     *     score:?int,
     *     classification:string,
     *     reasons:array<int,array<string,mixed>>,
     *     features:array<string,mixed>,
     *     data_quality:array<string,mixed>
     * }
     */
    private function assess(array $features, array $clientSignals): array
    {
        $score = 0;
        $reasons = [];
        $humanEvidence = [];
        $device = (string) ($features['device'] ?? '');
        $touchCapable = in_array($device, ['mobile', 'tablet'], true)
            || (int) ($clientSignals['max_touch_points'] ?? 0) > 0;

        if (($clientSignals['webdriver'] ?? false) === true) {
            $this->addReason($reasons, $score, 'webdriver_detected', 'Le navigateur signale explicitement un contexte automatisé.', 'client', 'high', 'webdriver');
        }

        $renderer = strtolower(trim((string) ($clientSignals['webgl_renderer'] ?? '')));
        if ($renderer !== '' && preg_match('/swiftshader|llvmpipe|software renderer|headless/i', $renderer)) {
            $this->addReason($reasons, $score, 'software_renderer', 'Le moteur WebGL ressemble à un rendu logiciel ou headless.', 'client', 'medium', 'headless_renderer');
        }

        if (!$touchCapable && array_key_exists('plugins_count', $clientSignals) && (int) $clientSignals['plugins_count'] === 0) {
            $this->addReason($reasons, $score, 'zero_plugins_desktop', 'Le navigateur desktop ne déclare aucun plugin.', 'client', 'low', 'zero_plugins_desktop');
        }

        if (array_key_exists('languages', $clientSignals) && trim((string) $clientSignals['languages']) === '') {
            $this->addReason($reasons, $score, 'empty_languages', 'Le navigateur ne déclare aucune langue préférée.', 'client', 'low', 'empty_languages');
        }

        if (array_key_exists('hardware_concurrency', $clientSignals) && (int) $clientSignals['hardware_concurrency'] <= 1) {
            $this->addReason($reasons, $score, 'low_hardware_concurrency', 'Le navigateur expose une capacité CPU minimale.', 'client', 'low', 'low_hardware_concurrency');
        }

        if (array_key_exists('device_memory', $clientSignals) && (float) $clientSignals['device_memory'] <= 1) {
            $this->addReason($reasons, $score, 'low_device_memory', 'Le navigateur expose une mémoire disponible minimale.', 'client', 'low', 'low_device_memory');
        }

        if (!$touchCapable
            && array_key_exists('chrome_diff_w', $clientSignals)
            && array_key_exists('chrome_diff_h', $clientSignals)
            && abs((int) $clientSignals['chrome_diff_w']) <= 1
            && abs((int) $clientSignals['chrome_diff_h']) <= 1
        ) {
            $this->addReason($reasons, $score, 'zero_browser_chrome', 'La fenêtre desktop ne présente pratiquement aucune bordure navigateur.', 'client', 'low', 'zero_browser_chrome');
        }

        if (array_key_exists('screen_w', $clientSignals)
            && array_key_exists('screen_h', $clientSignals)
            && ((int) $clientSignals['screen_w'] <= 0 || (int) $clientSignals['screen_h'] <= 0)
        ) {
            $this->addReason($reasons, $score, 'invalid_screen_dimensions', 'Les dimensions écran déclarées sont invalides.', 'client', 'medium', 'invalid_screen_dimensions');
        }

        $minimumMoves = max(1, (int) config('visitor-intelligence.bot_detection.behavior.minimum_moves', 20));
        if (!$touchCapable && ($features['interaction_count'] ?? 0) > 0 && ($features['move_count'] ?? 0) === 0) {
            $this->addReason($reasons, $score, 'interaction_without_pointer', 'Des interactions desktop sont observées sans mouvement de pointeur.', 'behavior', 'medium', 'interaction_without_pointer');
        }

        $firstInteraction = $features['time_to_first_interaction_ms'] ?? null;
        if ($firstInteraction !== null
            && $firstInteraction < (int) config('visitor-intelligence.bot_detection.behavior.instant_interaction_ms', 120)
            && ($features['interaction_count'] ?? 0) > 0
        ) {
            $this->addReason($reasons, $score, 'instant_first_interaction', 'La première interaction arrive presque immédiatement après le chargement.', 'behavior', 'low', 'instant_interaction');
        }

        $intervalCv = $features['interval_cv'] ?? null;
        if (($features['move_count'] ?? 0) >= $minimumMoves
            && $intervalCv !== null
            && $intervalCv <= (float) config('visitor-intelligence.bot_detection.behavior.regular_interval_cv', 0.08)
        ) {
            $this->addReason($reasons, $score, 'regular_pointer_timing', 'Les intervalles de mouvement sont anormalement réguliers.', 'behavior', 'low', 'regular_pointer_timing');
        }

        if (($features['move_count'] ?? 0) >= $minimumMoves
            && ($features['straightness_mean'] ?? null) !== null
            && $features['straightness_mean'] >= (float) config('visitor-intelligence.bot_detection.behavior.straightness_ratio', 0.995)
        ) {
            $this->addReason($reasons, $score, 'straight_pointer_path', 'Les trajectoires du pointeur sont presque parfaitement rectilignes.', 'behavior', 'low', 'straight_pointer_path');
        }

        if (($features['move_count'] ?? 0) >= $minimumMoves
            && ($features['duplicate_pointer_ratio'] ?? null) !== null
            && $features['duplicate_pointer_ratio'] >= 0.5
        ) {
            $this->addReason($reasons, $score, 'duplicate_pointer_positions', 'Le replay contient de nombreuses positions de pointeur répétées.', 'behavior', 'low', 'duplicate_pointer_positions');
        }

        if (($features['input_count'] ?? 0) >= 3
            && ($features['input_span_ms'] ?? null) !== null
            && $features['input_span_ms'] <= (int) config('visitor-intelligence.bot_detection.behavior.rapid_input_span_ms', 800)
        ) {
            $this->addReason($reasons, $score, 'rapid_input_burst', 'Plusieurs changements de champs arrivent dans un délai très court.', 'behavior', 'medium', 'rapid_input_burst');
        }

        if (($features['action_count'] ?? 0) >= 5
            && ($features['activity_span_ms'] ?? 0) > 0
            && $features['activity_span_ms'] <= (int) config('visitor-intelligence.bot_detection.behavior.rapid_activity_span_ms', 1500)
        ) {
            $this->addReason($reasons, $score, 'rapid_activity_burst', 'Plusieurs actions sont exécutées en rafale sur une période très courte.', 'behavior', 'medium', 'rapid_activity_burst');
        }

        if (($features['move_count'] ?? 0) >= 8 && ($features['direction_change_ratio'] ?? 0) >= 0.08) {
            $humanEvidence[] = ['code' => 'nonlinear_pointer', 'label' => 'mouvements de pointeur non linéaires'];
        }
        if (($features['interaction_count'] ?? 0) >= 2) {
            $humanEvidence[] = ['code' => 'multiple_interactions', 'label' => 'interactions réparties dans le parcours'];
        }
        if (($features['scroll_count'] ?? 0) > 0) {
            $humanEvidence[] = ['code' => 'scroll_activity', 'label' => 'activité de défilement observée'];
        }
        if (($features['input_count'] ?? 0) > 0) {
            $humanEvidence[] = ['code' => 'input_activity', 'label' => 'saisie ou modification de champ observée'];
        }
        if (($features['max_pause_ms'] ?? 0) >= (int) config('visitor-intelligence.bot_detection.behavior.human_pause_ms', 1200)) {
            $humanEvidence[] = ['code' => 'natural_pause', 'label' => 'pause significative entre des actions'];
        }
        if (($features['duration_seconds'] ?? 0) >= 10 && ($features['action_count'] ?? 0) >= 2) {
            $humanEvidence[] = ['code' => 'sustained_activity', 'label' => 'activité étalée dans le temps'];
        }

        $hasData = ($features['event_count'] ?? 0) > 0 || $clientSignals !== [];
        $botThreshold = max(1, (int) config('visitor-intelligence.bot_detection.thresholds.bot', 65));
        $humanThreshold = max(0, (int) config('visitor-intelligence.bot_detection.thresholds.human', 20));
        $minimumHumanEvidence = max(1, (int) config('visitor-intelligence.bot_detection.thresholds.minimum_human_evidence', 2));
        $classification = 'uncertain';
        $finalScore = $hasData ? min(100, max(0, $score)) : null;

        if ($hasData && $finalScore !== null && $finalScore >= $botThreshold) {
            $classification = 'bot';
        } elseif ($hasData
            && $finalScore !== null
            && $finalScore <= $humanThreshold
            && count($humanEvidence) >= $minimumHumanEvidence
        ) {
            $classification = 'human';
        }

        $quality = $this->dataQuality($features, $clientSignals, $reasons, $humanEvidence, $classification);
        $features['human_evidence'] = $humanEvidence;
        $features['data_quality'] = $quality;

        return [
            'score' => $finalScore,
            'classification' => $classification,
            'reasons' => $reasons,
            'features' => $features,
            'data_quality' => $quality,
        ];
    }

    private function addReason(
        array &$reasons,
        int &$score,
        string $code,
        string $label,
        string $category,
        string $severity,
        string $weightKey,
    ): void {
        $weight = max(0, (int) config('visitor-intelligence.bot_detection.weights.'.$weightKey, 0));
        if ($weight <= 0) return;

        $score += $weight;
        $reasons[] = [
            'code' => $code,
            'label' => $label,
            'category' => $category,
            'severity' => $severity,
            'weight' => $weight,
        ];
    }

    private function dataQuality(array $features, array $clientSignals, array $reasons, array $humanEvidence, string $classification): array
    {
        $eventCount = (int) ($features['event_count'] ?? 0);
        $signalCount = count($clientSignals);
        $quality = $eventCount >= 10 && $signalCount >= 3
            ? 'high'
            : (($eventCount > 0 || $signalCount > 0) ? 'medium' : 'low');

        return [
            'level' => $quality,
            'replay_events' => $eventCount,
            'client_signals' => $signalCount,
            'bot_evidence' => count($reasons),
            'human_evidence' => count($humanEvidence),
            'classification' => $classification,
            'has_replay' => $eventCount > 0,
            'has_client_signals' => $signalCount > 0,
        ];
    }

    private function maxInterval(array $timestamps): int
    {
        if (count($timestamps) < 2) return 0;

        $max = 0;
        for ($index = 1; $index < count($timestamps); $index++) {
            $max = max($max, (int) $timestamps[$index] - (int) $timestamps[$index - 1]);
        }
        return max(0, $max);
    }

    private function countLongPauses(array $timestamps, int $threshold): int
    {
        if (count($timestamps) < 2) return 0;

        $count = 0;
        for ($index = 1; $index < count($timestamps); $index++) {
            if (((int) $timestamps[$index] - (int) $timestamps[$index - 1]) >= $threshold) $count++;
        }
        return $count;
    }

    private function stddev(array $values): ?float
    {
        if ($values === []) return null;
        $mean = array_sum($values) / count($values);
        $variance = array_sum(array_map(static fn (float|int $value): float => ($value - $mean) ** 2, $values)) / count($values);
        return sqrt($variance);
    }
}
