<?php

namespace App\Services\VisitorIntelligence;

use App\Models\VisitorSession;
use App\Models\VisitorSessionReplayChunk;
use Symfony\Component\Process\Process;

class VisitorIntelligenceReplayContextService
{
    /**
     * Reconstruct only selected timestamps. The raw replay is passed to the
     * dedicated renderer, never to the LLM.
     *
     * @param array<int, array<string, mixed>> $moments
     * @return array{available: bool, moments: array<int, array<string, mixed>>, error?: string}
     */
    public function build(VisitorSession $session, array $moments): array
    {
        if (!config('visitor-intelligence.rrweb_context.enabled', true) || $moments === []) {
            return ['available' => false, 'moments' => []];
        }

        $script = (string) config('visitor-intelligence.rrweb_context.worker_script');
        if ($script === '' || !is_file($script)) {
            return ['available' => false, 'moments' => [], 'error' => 'rrweb_renderer_script_missing'];
        }

        $chunks = VisitorSessionReplayChunk::query()
            ->where('site_id', $session->site_id)
            ->where('visitor_session_id', $session->id)
            ->orderBy('chunk_index')
            ->get(['chunk_index', 'format', 'payload', 'rrweb_version']);

        if ($chunks->isEmpty()) {
            return ['available' => false, 'moments' => [], 'error' => 'rrweb_chunks_missing'];
        }

        $payload = [
            'chunks' => $chunks->map(fn (VisitorSessionReplayChunk $chunk): array => [
                'chunk_index' => (int) $chunk->chunk_index,
                'format' => (string) $chunk->format,
                'payload' => (string) $chunk->payload,
            ])->values()->all(),
            'moments' => array_values($moments),
            'replay_umd_path' => (string) config('visitor-intelligence.rrweb_context.replay_umd_path'),
            'chromium_path' => config('visitor-intelligence.rrweb_context.chromium_path'),
            'max_visual_captures' => max(0, (int) config('visitor-intelligence.ai.max_visual_captures', 3)),
        ];
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $maxPayload = max(1048576, (int) config('visitor-intelligence.rrweb_context.max_payload_bytes', 33554432));
        if (strlen($encoded) > $maxPayload) {
            return ['available' => false, 'moments' => [], 'error' => 'rrweb_context_payload_too_large'];
        }

        try {
            $process = new Process([
                (string) config('visitor-intelligence.rrweb_context.node_binary', 'node'),
                $script,
            ]);
            $process->setInput($encoded);
            $process->setTimeout(max(5, (int) config('visitor-intelligence.rrweb_context.timeout', 90)));
            $process->run();

            if (!$process->isSuccessful()) {
                return [
                    'available' => false,
                    'moments' => [],
                    'error' => trim($process->getErrorOutput()) ?: 'rrweb_renderer_failed',
                ];
            }

            $result = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($result)) {
                return ['available' => false, 'moments' => [], 'error' => 'rrweb_renderer_invalid_output'];
            }

            return [
                'available' => (bool) ($result['available'] ?? false),
                'moments' => is_array($result['moments'] ?? null) ? $result['moments'] : [],
                ...(!empty($result['error']) ? ['error' => (string) $result['error']] : []),
            ];
        } catch (\Throwable $exception) {
            return ['available' => false, 'moments' => [], 'error' => $exception->getMessage()];
        }
    }
}
