<?php

namespace App\Services\VisitorIntelligence;

use App\Models\VisitorSession;
use App\Models\VisitorSessionReplayChunk;

class VisitorIntelligenceBotScoringService
{
    public function scoreSession(VisitorSession $session): ?array
    {
        $events = $this->replayEvents($session);
        $features = $this->extractFeatures($events);
        if ($events === [] && ($session->client_signals ?? []) === []) return null;

        $score = $this->computeScore($features, $session->client_signals ?? []);
        $session->forceFill([
            'bot_score' => $score,
            'bot_score_computed_at' => now(),
        ])->save();

        return ['score' => $score, 'features' => $features];
    }

    private function replayEvents(VisitorSession $session): array
    {
        $chunks = VisitorSessionReplayChunk::query()
            ->where('site_id', $session->site_id)
            ->where('visitor_session_id', $session->id)
            ->orderBy('chunk_index')
            ->get(['payload', 'format']);

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
            }
        }

        usort($events, static fn (array $left, array $right): int => $left['timestamp'] <=> $right['timestamp']);
        return $events;
    }

    private function extractFeatures(array $events): array
    {
        $moves = [];
        $firstTimestamp = $events[0]['timestamp'] ?? null;
        $firstInteractionTimestamp = null;
        $movesBeforeFirstInteraction = 0;
        $interactionCount = 0;
        $sawInteraction = false;

        foreach ($events as $event) {
            if ((int) ($event['type'] ?? -1) !== 3) continue;
            $data = is_array($event['data'] ?? null) ? $event['data'] : [];
            $source = (int) ($data['source'] ?? -1);

            if ($source === 1 && is_array($data['positions'] ?? null)) {
                foreach ($data['positions'] as $position) {
                    if (!is_array($position) || !is_numeric($position['x'] ?? null) || !is_numeric($position['y'] ?? null)) continue;
                    $moves[] = [
                        't' => (int) $event['timestamp'] + (int) ($position['timeOffset'] ?? 0),
                        'x' => (float) $position['x'],
                        'y' => (float) $position['y'],
                    ];
                    if (!$sawInteraction) $movesBeforeFirstInteraction++;
                }
            }

            if ($source === 2) {
                $interactionCount++;
                if (!$sawInteraction) $firstInteractionTimestamp = (int) $event['timestamp'];
                $sawInteraction = true;
            }
        }

        $intervals = [];
        for ($index = 1; $index < count($moves); $index++) {
            $intervals[] = max(0, $moves[$index]['t'] - $moves[$index - 1]['t']);
        }

        $straightness = [];
        foreach (array_chunk($moves, 15) as $segment) {
            if (count($segment) < 3) continue;
            $path = 0.0;
            for ($index = 1; $index < count($segment); $index++) {
                $path += hypot(
                    $segment[$index]['x'] - $segment[$index - 1]['x'],
                    $segment[$index]['y'] - $segment[$index - 1]['y'],
                );
            }
            $net = hypot(
                $segment[count($segment) - 1]['x'] - $segment[0]['x'],
                $segment[count($segment) - 1]['y'] - $segment[0]['y'],
            );
            if ($path > 0) $straightness[] = min(1, $net / $path);
        }

        return [
            'event_count' => count($events),
            'move_count' => count($moves),
            'interaction_count' => $interactionCount,
            'interval_mean_ms' => $intervals ? array_sum($intervals) / count($intervals) : null,
            'interval_stddev_ms' => $this->stddev($intervals),
            'straightness_mean' => $straightness ? array_sum($straightness) / count($straightness) : null,
            'time_to_first_interaction_ms' => $firstInteractionTimestamp !== null && $firstTimestamp !== null
                ? max(0, $firstInteractionTimestamp - $firstTimestamp)
                : null,
            'moves_before_first_interaction' => $movesBeforeFirstInteraction,
        ];
    }

    private function computeScore(array $features, array $clientSignals): int
    {
        $score = 0;
        if (($clientSignals['webdriver'] ?? false) === true) $score += 40;
        if (array_key_exists('plugins_count', $clientSignals) && (int) $clientSignals['plugins_count'] === 0) $score += 10;
        if (($features['interaction_count'] ?? 0) > 0 && ($features['move_count'] ?? 0) === 0) $score += 20;
        if (($features['time_to_first_interaction_ms'] ?? null) !== null && $features['time_to_first_interaction_ms'] < 150) $score += 15;
        if (($features['interval_stddev_ms'] ?? null) !== null && $features['interval_stddev_ms'] < 2 && ($features['move_count'] ?? 0) > 20) $score += 20;
        if (($features['straightness_mean'] ?? null) !== null && $features['straightness_mean'] >= 0.998 && ($features['move_count'] ?? 0) > 20) $score += 20;

        return min(100, $score);
    }

    private function stddev(array $values): ?float
    {
        if ($values === []) return null;
        $mean = array_sum($values) / count($values);
        $variance = array_sum(array_map(static fn (float|int $value): float => ($value - $mean) ** 2, $values)) / count($values);
        return sqrt($variance);
    }
}
