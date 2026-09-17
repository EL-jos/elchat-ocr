<?php

namespace App\Services\VisitorIntelligence;

use App\Models\Site;
use App\Models\VisitorSession;
use App\Models\VisitorSessionReplayChunk;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class VisitorIntelligenceReplayService
{
    /**
     * rrweb sérialise le DOM en JSON imbriqué (node.childNodes[].childNodes[]...),
     * donc la profondeur JSON reflète directement la profondeur d'imbrication réelle
     * du DOM source. Un site construit avec un page builder (Elementor, WPBakery...)
     * dépasse couramment plusieurs dizaines de niveaux rien que dans son squelette
     * structurel. Cette constante DOIT rester identique à l'écriture (storeChunk)
     * et à la lecture (decodeChunk) : un écart entre les deux fait qu'un chunk
     * s'encode et se stocke avec succès, puis devient illisible à la relecture
     * sans qu'aucune erreur ne soit jamais remontée à l'écriture — exactement le
     * bug qui a produit les 422 "Le chunk rrweb est illisible." sur DOM profond.
     */
    public function storeChunk(Site $site, VisitorSession $session, array $data): array
    {
        abort_unless($session->site_id === $site->id, 404, 'Session introuvable.');

        $events = $this->validatedEvents($data['events'] ?? []);
        abort_if($events === [], 422, 'Le chunk rrweb ne contient aucun événement exploitable.');

        try {
            [$payload, $hash, $compressedBytes] = RrwebChunkCodec::encode($events);
        } catch (\Throwable $exception) {
            Log::warning('Visitor Intelligence rrweb chunk encoding failed.', [
                'site_id' => $site->id,
                'session_id' => $session->session_key,
                'error' => $exception->getMessage(),
            ]);
            abort(422, 'Le chunk rrweb est invalide.');
        }

        $maxBytes = max(65536, (int) config('visitor-intelligence.replay_chunk_max_bytes', 1572864));
        abort_if($compressedBytes > $maxBytes, 413, 'Le chunk rrweb est trop volumineux.');
        $chunkIndex = max(0, min(1000000, (int) ($data['chunk_index'] ?? 0)));
        $existing = VisitorSessionReplayChunk::query()
            ->where('site_id', $site->id)
            ->where('visitor_session_id', $session->id)
            ->where('chunk_index', $chunkIndex)
            ->first();

        // Retries are expected when a tenant changes page or loses the
        // network. Keep the first accepted chunk immutable so a duplicate
        // cannot silently replace part of a replay with different events.
        if ($existing) {
            if ($existing->payload_hash !== $hash) {
                Log::warning('Visitor Intelligence rrweb chunk collision: index already used with different content.', [
                    'site_id' => $site->id,
                    'session_id' => $session->session_key,
                    'chunk_index' => $chunkIndex,
                ]);
            }

            return [
                'accepted' => true,
                'duplicate' => true,
                'chunk_index' => $existing->chunk_index,
                'event_count' => $existing->event_count,
            ];
        }

        [$firstEventAt, $lastEventAt] = $this->eventBounds($events);
        $chunk = VisitorSessionReplayChunk::query()->create([
            'account_id' => $site->account_id,
            'site_id' => $site->id,
            'visitor_session_id' => $session->id,
            'chunk_index' => $chunkIndex,
            'format' => 'rrweb-json-gzip-base64',
            'rrweb_version' => Str::limit((string) ($data['rrweb_version'] ?? '2.0.0'), 16, ''),
            'event_count' => count($events),
            'payload_bytes' => $compressedBytes,
            'payload_hash' => $hash,
            'first_event_at' => $firstEventAt,
            'last_event_at' => $lastEventAt,
            'payload' => $payload,
        ]);

        $updates = [];
        if ($firstEventAt && (!$session->started_at || $firstEventAt->lessThan($session->started_at))) {
            $updates['started_at'] = $firstEventAt;
        }
        if ($lastEventAt && (!$session->last_seen_at || $lastEventAt->greaterThan($session->last_seen_at))) {
            $updates['last_seen_at'] = $lastEventAt;
        }
        if ($updates) $session->forceFill($updates)->save();

        return [
            'accepted' => true,
            'duplicate' => false,
            'chunk_index' => $chunk->chunk_index,
            'event_count' => $chunk->event_count,
        ];
    }

    public function metadataForSession(Site $site, VisitorSession $session): array
    {
        $chunks = VisitorSessionReplayChunk::query()
            ->where('site_id', $site->id)
            ->where('visitor_session_id', $session->id)
            ->select([
                'chunk_index',
                'event_count',
                'payload_bytes',
                'rrweb_version',
                'first_event_at',
                'last_event_at',
            ])
            ->orderBy('chunk_index')
            ->get();

        return [
            'available' => $chunks->isNotEmpty(),
            'chunks' => $chunks->count(),
            'chunk_indexes' => $chunks->pluck('chunk_index')->map(static fn ($index): int => (int) $index)->values()->all(),
            'event_count' => (int) $chunks->sum('event_count'),
            'version' => $chunks->first()?->rrweb_version,
        ];
    }

    public function chunkForSession(Site $site, VisitorSession $session, int $chunkIndex): array
    {
        abort_unless($session->site_id === $site->id, 404, 'Session introuvable.');

        $chunk = VisitorSessionReplayChunk::query()
            ->where('site_id', $site->id)
            ->where('visitor_session_id', $session->id)
            ->where('chunk_index', $chunkIndex)
            ->firstOrFail();

        $events = RrwebChunkCodec::decode($chunk->payload, $chunk->format);
        abort_if($events === [], 422, 'Le chunk rrweb est illisible.');

        return [
            'chunk_index' => (int) $chunk->chunk_index,
            'event_count' => (int) $chunk->event_count,
            'events' => $events,
            'version' => $chunk->rrweb_version,
            'first_event_at' => $chunk->first_event_at?->toISOString(),
            'last_event_at' => $chunk->last_event_at?->toISOString(),
        ];
    }

    private function validatedEvents(mixed $events): array
    {
        if (!is_array($events)) return [];
        $maxEvents = max(1, (int) config('visitor-intelligence.replay_chunk_max_events', 500));
        $result = [];
        foreach (array_slice($events, 0, $maxEvents) as $event) {
            if (!is_array($event)) continue;
            if (!isset($event['type'], $event['timestamp']) || !is_numeric($event['type']) || !is_numeric($event['timestamp'])) continue;
            if (!array_key_exists('data', $event)) continue;
            $event['type'] = (int) $event['type'];
            $event['timestamp'] = (int) $event['timestamp'];
            if ($event['timestamp'] <= 0 || $event['type'] < 0 || $event['type'] > 100) continue;
            $result[] = $event;
        }
        return $result;
    }

    private function eventBounds(array $events): array
    {
        $timestamps = array_values(array_filter(array_map(
            static fn (array $event): ?int => is_numeric($event['timestamp'] ?? null) ? (int) $event['timestamp'] : null,
            $events,
        ), static fn (?int $timestamp): bool => $timestamp !== null && $timestamp > 0));
        if ($timestamps === []) return [null, null];
        sort($timestamps);
        return [Carbon::createFromTimestampMs($timestamps[0]), Carbon::createFromTimestampMs($timestamps[count($timestamps) - 1])];
    }

}
