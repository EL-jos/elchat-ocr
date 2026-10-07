<?php

namespace App\Jobs\VisitorIntelligence;

use App\Models\VisitorSessionReplayVideoExport;
use App\Services\VisitorIntelligence\VisitorIntelligenceRealtimeService;
use App\Services\VisitorIntelligence\VisitorReplayVideoExportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExportVisitorSessionReplayVideoJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 1200;
    public int $uniqueFor = 1800;
    public array $backoff = [60, 180];

    public function __construct(public readonly string $exportId)
    {
        $this->onQueue(config('visitor-intelligence.replay_video.queue', 'visitor-replay-export'));
    }

    public function uniqueId(): string
    {
        return $this->exportId;
    }

    public function handle(VisitorReplayVideoExportService $exports, VisitorIntelligenceRealtimeService $realtime): void
    {
        $export = VisitorSessionReplayVideoExport::query()->find($this->exportId);
        if (!$export || in_array($export->status, ['ready', 'failed'], true)) return;
        if (!$export->site_id || !$export->visitor_session_id) {
            $export->forceFill([
                'status' => 'failed',
                'phase' => 'failed',
                'progress_message' => 'Le parcours a été supprimé avant la génération de la vidéo.',
                'error_code' => 'replay_video_parent_deleted',
                'error_message' => 'Le parcours associé à cette vidéo n’est plus disponible.',
                'completed_at' => now(),
            ])->save();
            return;
        }

        $siteId = (string) $export->site_id;
        $export->forceFill([
            'status' => 'running',
            'phase' => 'preparation',
            'progress' => max(1, (int) $export->progress),
            'progress_message' => 'Préparation des données rrweb.',
            'renderer_version' => max(1, (int) config('visitor-intelligence.replay_video.renderer_version', 2)),
            'error_code' => null,
            'error_message' => null,
            'started_at' => $export->started_at ?: now(),
        ])->save();
        $this->publish($realtime, $export, 'replay_export_started');

        $result = $exports->generate($export, function (array $update) use ($realtime, $siteId): void {
            $export = VisitorSessionReplayVideoExport::query()->find($this->exportId);
            if (!$export) throw new \RuntimeException('replay_video_export_deleted');
            if (!$export->site_id || !$export->visitor_session_id) throw new \RuntimeException('replay_video_parent_deleted');
            if ($export->status === 'failed') throw new \RuntimeException('replay_video_export_cancelled');

            $progress = max((int) $export->progress, min(95, (int) ($update['progress'] ?? 5)));
            $phase = (string) ($update['phase'] ?? 'rendering');
            $message = (string) ($update['message'] ?? 'Génération vidéo en cours.');
            if ($progress === (int) $export->progress && $phase === $export->phase) return;

            $export->forceFill([
                'status' => 'running',
                'progress' => $progress,
                'phase' => $phase,
                'progress_message' => $message,
            ])->save();
            $this->publish($realtime, $export, 'replay_export_progress');
        });

        $export = VisitorSessionReplayVideoExport::query()->find($this->exportId);
        if (!$export) return;
        if ($export->status === 'failed') {
            $this->deleteFileBestEffort($export);
            return;
        }
        if (!$export->site_id || !$export->visitor_session_id) {
            $this->deleteFileBestEffort($export);
            $export->forceFill([
                'status' => 'failed',
                'phase' => 'failed',
                'progress_message' => 'Le parcours a été supprimé avant la fin de la conversion.',
                'error_code' => 'replay_video_parent_deleted',
                'error_message' => 'Le parcours associé à cette vidéo n’est plus disponible.',
                'completed_at' => now(),
            ])->save();
            return;
        }
        $export->forceFill([
            'status' => 'ready',
            'progress' => 100,
            'phase' => 'completed',
            'progress_message' => 'La vidéo MP4 est prête au téléchargement.',
            'error_code' => null,
            'error_message' => null,
            'completed_at' => now(),
            ...$result,
        ])->save();
        $this->publish($realtime, $export, 'replay_export_ready');
    }

    public function failed(?Throwable $exception): void
    {
        $export = VisitorSessionReplayVideoExport::query()->find($this->exportId);
        if (!$export) return;
        if ($export->status === 'failed' && $export->error_code === 'replay_video_session_deleted') {
            $this->deleteFileBestEffort($export);
            return;
        }
        if (!$export->site_id || !$export->visitor_session_id) {
            $this->deleteFileBestEffort($export);
            $export->forceFill([
                'status' => 'failed',
                'phase' => 'failed',
                'progress_message' => 'Le parcours a été supprimé avant la fin de la conversion.',
                'error_code' => 'replay_video_parent_deleted',
                'error_message' => 'Le parcours associé à cette vidéo n’est plus disponible.',
                'completed_at' => now(),
            ])->save();
            return;
        }

        $this->deleteFileBestEffort($export);
        $diagnostic = mb_substr(trim((string) ($exception?->getMessage() ?: 'replay_video_job_failed')), 0, 1000);
        $export->forceFill([
            'status' => 'failed',
            'phase' => 'failed',
            'progress_message' => 'La génération vidéo a échoué. Vous pouvez réessayer.',
            'error_code' => mb_substr(preg_replace('/[^a-zA-Z0-9_:-]/', '_', $diagnostic) ?: 'replay_video_job_failed', 0, 100),
            'error_message' => 'La vidéo n’a pas pu être générée. Vérifiez que le service de rendu est disponible, puis réessayez.',
            'completed_at' => now(),
        ])->save();

        Log::error('Visitor replay video export failed.', [
            'export_id' => (string) $export->id,
            'site_id' => (string) $export->site_id,
            'session_id' => (string) $export->visitor_session_id,
            'error' => $diagnostic,
        ]);
        $this->publish(app(VisitorIntelligenceRealtimeService::class), $export, 'replay_export_failed');
    }

    private function publish(VisitorIntelligenceRealtimeService $realtime, VisitorSessionReplayVideoExport $export, string $type): void
    {
        $realtime->publish((string) $export->site_id, $type, [
            'session_id' => (string) $export->visitor_session_id,
            'export_id' => (string) $export->id,
            'status' => (string) $export->status,
            'progress' => (int) $export->progress,
            'phase' => (string) $export->phase,
            'message' => $export->progress_message,
            'error_message' => $export->error_message,
            'duration_ms' => $export->duration_ms,
            'frame_count' => $export->frame_count,
            'file_size_bytes' => $export->file_size_bytes,
        ]);
    }

    private function deleteFileBestEffort(VisitorSessionReplayVideoExport $export): void
    {
        try {
            app(VisitorReplayVideoExportService::class)->deleteExportFile($export);
        } catch (Throwable $exception) {
            Log::warning('Failed replay video export left its private file for scheduled cleanup.', [
                'export_id' => (string) $export->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
