<?php

namespace App\Services\VisitorIntelligence;

use App\Jobs\VisitorIntelligence\ExportVisitorSessionReplayVideoJob;
use App\Models\AnalyticsEvent;
use App\Models\Site;
use App\Models\VisitorSession;
use App\Models\VisitorSessionReplayChunk;
use App\Models\VisitorSessionReplayVideoExport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Throwable;

class VisitorReplayVideoExportService
{
    /**
     * @return array{export: VisitorSessionReplayVideoExport, created: bool}
     */
    public function request(Site $site, VisitorSession $session, bool $skipInactive, float $speed): array
    {
        $diskName = (string) config('visitor-intelligence.replay_video.storage_disk', 'local');
        $rendererVersion = max(1, (int) config('visitor-intelligence.replay_video.renderer_version', 4));
        if ($diskName === 'public' || config("filesystems.disks.{$diskName}.visibility") === 'public') {
            throw new \LogicException('replay_video_storage_must_be_private');
        }

        $result = DB::transaction(function () use ($site, $session, $skipInactive, $speed, $rendererVersion): array {
            $lockedSession = VisitorSession::query()
                ->where('site_id', $site->id)
                ->lockForUpdate()
                ->findOrFail($session->id);

            $latest = VisitorSessionReplayVideoExport::query()
                ->where('site_id', $site->id)
                ->where('visitor_session_id', $lockedSession->id)
                ->where('expires_at', '>', now())
                ->latest('created_at')
                ->first();

            if ($latest && in_array($latest->status, ['queued', 'running'], true)) {
                return ['export' => $latest, 'created' => false];
            }
            if ($latest && $latest->status === 'ready' && (int) $latest->renderer_version === $rendererVersion) {
                return ['export' => $latest, 'created' => false];
            }

            $export = VisitorSessionReplayVideoExport::query()->create([
                'account_id' => $site->account_id,
                'site_id' => $site->id,
                'visitor_session_id' => $lockedSession->id,
                'status' => 'queued',
                'progress' => 0,
                'phase' => 'queued',
                'progress_message' => 'La vidéo est en attente de génération.',
                'storage_disk' => (string) config('visitor-intelligence.replay_video.storage_disk', 'local'),
                'fps' => max(6, min(20, (int) config('visitor-intelligence.replay_video.fps', 12))),
                'renderer_version' => $rendererVersion,
                'skip_inactive' => $skipInactive,
                'playback_speed' => $speed,
                'expires_at' => now()->addDays(max(1, (int) config('visitor-intelligence.replay_video.retention_days', 2))),
            ]);

            return ['export' => $export, 'created' => true];
        });

        if ($result['created']) {
            ExportVisitorSessionReplayVideoJob::dispatch((string) $result['export']->id);
        }

        return $result;
    }

    public function latestForSession(Site $site, VisitorSession $session): ?array
    {
        $export = VisitorSessionReplayVideoExport::query()
            ->where('site_id', $site->id)
            ->where('visitor_session_id', $session->id)
            ->where('expires_at', '>', now())
            ->latest('created_at')
            ->first();

        return $export ? $this->publicPayload($export) : null;
    }

    public function findForSession(Site $site, VisitorSession $session, string $exportId): VisitorSessionReplayVideoExport
    {
        return VisitorSessionReplayVideoExport::query()
            ->where('site_id', $site->id)
            ->where('visitor_session_id', $session->id)
            ->findOrFail($exportId);
    }

    /** @return array<string, mixed> */
    public function publicPayload(VisitorSessionReplayVideoExport $export): array
    {
        return [
            'id' => (string) $export->id,
            'status' => (string) $export->status,
            'progress' => (int) $export->progress,
            'phase' => (string) $export->phase,
            'progress_message' => $export->progress_message,
            'error_message' => $export->error_message,
            'duration_ms' => $export->duration_ms,
            'frame_count' => $export->frame_count,
            'fps' => (int) $export->fps,
            'renderer_version' => (int) $export->renderer_version,
            'file_size_bytes' => $export->file_size_bytes,
            'expires_at' => $export->expires_at?->toISOString(),
        ];
    }

    /**
     * Render a validated, bounded rrweb recording in an isolated Node/Chromium
     * process and stream the resulting MP4 to the configured private disk.
     *
     * @param callable(array{progress:int,phase:string,message:string}):void $onProgress
     * @return array{duration_ms:int,frame_count:int,fps:int,file_size_bytes:int}
     */
    public function generate(VisitorSessionReplayVideoExport $export, callable $onProgress): array
    {
        $session = VisitorSession::query()
            ->where('site_id', $export->site_id)
            ->findOrFail($export->visitor_session_id);
        $local = Storage::disk('local');
        $workspaceRelative = 'visitor-intelligence/replay-video-tmp/'.$export->id;
        $workspace = $local->path($workspaceRelative);
        if (!is_dir($workspace) && !@mkdir($workspace, 0700, true) && !is_dir($workspace)) {
            throw new \RuntimeException('replay_video_temp_directory_unavailable');
        }

        try {
            $manifest = $this->prepareManifest($export, $session, $workspace);
            $manifestPath = $workspace.DIRECTORY_SEPARATOR.'manifest.json';
            $outputPath = $workspace.DIRECTORY_SEPARATOR.'replay.mp4';
            $manifest['output_path'] = $outputPath;
            $manifest['ffmpeg_binary'] = (string) config('visitor-intelligence.replay_video.ffmpeg_binary', 'ffmpeg');
            $this->writeJson($manifestPath, $manifest);

            $script = (string) config('visitor-intelligence.replay_video.worker_script');
            if ($script === '' || !is_file($script)) {
                throw new \RuntimeException('replay_video_worker_script_missing');
            }

            $process = new Process([
                (string) config('visitor-intelligence.replay_video.node_binary', 'node'),
                $script,
                $manifestPath,
            ], base_path());
            $process->setTimeout(max(60, (int) config('visitor-intelligence.replay_video.timeout', 900)));
            $process->setIdleTimeout(max(30, (int) config('visitor-intelligence.replay_video.idle_timeout', 180)));
            $stderrLineBuffer = '';
            $process->run(function (string $type, string $buffer) use (&$stderrLineBuffer, $onProgress): void {
                if ($type !== Process::ERR) return;
                $stderrLineBuffer .= $buffer;
                while (($lineEnd = strpos($stderrLineBuffer, "\n")) !== false) {
                    $line = trim(substr($stderrLineBuffer, 0, $lineEnd));
                    $stderrLineBuffer = substr($stderrLineBuffer, $lineEnd + 1);
                    if (!str_starts_with($line, 'ELCHAT_PROGRESS:')) continue;
                    $progress = json_decode(substr($line, strlen('ELCHAT_PROGRESS:')), true);
                    if (!is_array($progress)) continue;
                    $onProgress([
                        'progress' => max(5, min(95, (int) ($progress['progress'] ?? 5))),
                        'phase' => mb_substr((string) ($progress['phase'] ?? 'rendering'), 0, 32),
                        'message' => mb_substr((string) ($progress['message'] ?? 'Génération vidéo en cours.'), 0, 255),
                    ]);
                }
            });

            if (!$process->isSuccessful()) {
                $diagnostic = trim($process->getErrorOutput());
                if (preg_match('/ELCHAT_ERROR:([^\r\n]+)/', $diagnostic, $matches)) {
                    $diagnostic = trim($matches[1]);
                }
                $diagnostic = preg_replace('/ELCHAT_(?:PROGRESS|ERROR):[^\r\n]*/', '', $diagnostic) ?: '';
                throw new \RuntimeException(trim($diagnostic) !== ''
                    ? mb_substr(trim($diagnostic), -1000)
                    : 'replay_video_renderer_failed');
            }

            $result = json_decode(trim($process->getOutput()), true);
            if (!is_array($result) || ($result['status'] ?? null) !== 'ready') {
                throw new \RuntimeException('replay_video_renderer_invalid_output');
            }
            if (!is_file($outputPath) || filesize($outputPath) < 1024) {
                throw new \RuntimeException('replay_video_output_invalid');
            }

            $size = (int) filesize($outputPath);
            $relativePath = 'visitor-intelligence/replay-exports/'.$export->site_id.'/'.$export->visitor_session_id.'/'.$export->id.'.mp4';
            $storage = Storage::disk($export->storage_disk);
            $outputStream = fopen($outputPath, 'rb');
            if (!is_resource($outputStream)) throw new \RuntimeException('replay_video_output_unreadable');
            $stored = false;
            try {
                $stored = $storage->put($relativePath, $outputStream);
            } finally {
                fclose($outputStream);
            }
            if (!$stored || !$storage->exists($relativePath)) {
                throw new \RuntimeException('replay_video_private_storage_failed');
            }

            $current = VisitorSessionReplayVideoExport::query()->find($export->id);
            if (!$current || $current->status === 'failed' || !$current->site_id || !$current->visitor_session_id) {
                if ($current) {
                    $current->forceFill([
                        'storage_path' => $relativePath,
                        'file_size_bytes' => $size,
                        'duration_ms' => max(0, (int) ($result['duration_ms'] ?? 0)),
                        'frame_count' => max(0, (int) ($result['frame_count'] ?? 0)),
                        'fps' => max(1, (int) ($result['fps'] ?? $export->fps)),
                    ])->save();
                    try {
                        $this->deleteExportFile($current);
                    } catch (Throwable $exception) {
                        Log::warning('Cancelled visitor replay export left its private file for scheduled cleanup.', [
                            'export_id' => (string) $current->id,
                            'error' => $exception->getMessage(),
                        ]);
                    }
                } else {
                    $storage->delete($relativePath);
                }
                throw new \RuntimeException('replay_video_export_cancelled');
            }

            $updated = VisitorSessionReplayVideoExport::query()->whereKey($export->id)->update([
                'storage_path' => $relativePath,
                'file_size_bytes' => $size,
                'duration_ms' => max(0, (int) ($result['duration_ms'] ?? 0)),
                'frame_count' => max(0, (int) ($result['frame_count'] ?? 0)),
                'fps' => max(1, (int) ($result['fps'] ?? $export->fps)),
                'updated_at' => now(),
            ]);
            if (!$updated) {
                $storage->delete($relativePath);
                throw new \RuntimeException('replay_video_export_cancelled');
            }
            $export->refresh();

            return [
                'duration_ms' => (int) $export->duration_ms,
                'frame_count' => (int) $export->frame_count,
                'fps' => (int) $export->fps,
                'file_size_bytes' => $size,
            ];
        } finally {
            $local->deleteDirectory($workspaceRelative);
        }
    }

    /** Remove retained MP4 files before deleting the associated session rows. */
    public function deleteForSession(VisitorSession $session): void
    {
        VisitorSessionReplayVideoExport::query()
            ->where('visitor_session_id', $session->id)
            ->get()
            ->each(function (VisitorSessionReplayVideoExport $export): void {
                $export->forceFill([
                    'status' => 'failed',
                    'phase' => 'failed',
                    'progress_message' => 'Le parcours associé a été supprimé.',
                    'error_code' => 'replay_video_session_deleted',
                    'error_message' => 'Le parcours associé à cette vidéo n’est plus disponible.',
                    'expires_at' => now(),
                    'completed_at' => now(),
                ])->save();

                try {
                    $this->deleteExportFile($export);
                } catch (Throwable $exception) {
                    Log::warning('Deleted visitor session left a replay video file for scheduled cleanup.', [
                        'export_id' => (string) $export->id,
                        'error' => $exception->getMessage(),
                    ]);
                }
            });
    }

    public function deleteExportFile(VisitorSessionReplayVideoExport $export): void
    {
        if (!$export->storage_path) return;

        $disk = Storage::disk($export->storage_disk);
        if ($disk->exists($export->storage_path) && !$disk->delete($export->storage_path) && $disk->exists($export->storage_path)) {
            throw new \RuntimeException('replay_video_file_delete_failed');
        }
    }

    public function pruneExpired(): int
    {
        $deleted = 0;
        VisitorSessionReplayVideoExport::query()
            ->where(function ($query): void {
                $query->whereNull('visitor_session_id')->orWhere('expires_at', '<=', now());
            })
            ->orderBy('id')
            ->chunkById(250, function ($exports) use (&$deleted): void {
                foreach ($exports as $export) {
                    try {
                        $this->deleteExportFile($export);
                    } catch (Throwable $exception) {
                        Log::warning('Expired visitor replay video file could not be removed; its record is retained for retry.', [
                            'export_id' => (string) $export->id,
                            'error' => $exception->getMessage(),
                        ]);
                        continue;
                    }
                    $deleted += VisitorSessionReplayVideoExport::query()->whereKey($export->id)->delete();
                }
            });

        return $deleted;
    }

    /** @return array<string, mixed> */
    private function prepareManifest(VisitorSessionReplayVideoExport $export, VisitorSession $session, string $workspace): array
    {
        $maxInputBytes = max(1024 * 1024, (int) config('visitor-intelligence.replay_video.max_input_bytes', 67108864));
        $maxEvents = max(2, (int) config('visitor-intelligence.replay_video.max_events', 100000));
        $chunkQuery = VisitorSessionReplayChunk::query()
            ->where('site_id', $session->site_id)
            ->where('visitor_session_id', $session->id)
            ->orderBy('chunk_index');
        if (!$chunkQuery->exists()) throw new \RuntimeException('replay_video_rrweb_chunks_missing');

        $inputBytes = 0;
        $eventCount = 0;
        $chunkFiles = [];
        foreach ($chunkQuery->cursor(['chunk_index', 'format', 'payload', 'payload_hash', 'payload_bytes', 'event_count']) as $chunk) {
            if ($chunk->format !== 'rrweb-json-gzip-base64') throw new \RuntimeException('replay_video_chunk_format_unsupported');
            $encodedPayload = (string) $chunk->payload;
            if (strlen($encodedPayload) > (int) ceil($maxInputBytes / 3) * 4 + 4) {
                throw new \RuntimeException('replay_video_input_too_large');
            }
            $compressed = base64_decode($encodedPayload, true);
            if (!is_string($compressed) || $compressed === '') throw new \RuntimeException('replay_video_chunk_invalid');
            if (!hash_equals((string) $chunk->payload_hash, hash('sha256', $encodedPayload))) {
                throw new \RuntimeException('replay_video_chunk_integrity_failed');
            }
            $inputBytes += strlen($compressed);
            $eventCount += (int) $chunk->event_count;
            if ($inputBytes > $maxInputBytes) throw new \RuntimeException('replay_video_input_too_large');
            if ($eventCount > $maxEvents) throw new \RuntimeException('replay_video_too_many_events');

            $filename = $workspace.DIRECTORY_SEPARATOR.sprintf('chunk-%06d.gz', (int) $chunk->chunk_index);
            if (file_put_contents($filename, $compressed, LOCK_EX) !== strlen($compressed)) {
                throw new \RuntimeException('replay_video_chunk_temp_write_failed');
            }
            $chunkFiles[] = ['path' => $filename, 'index' => (int) $chunk->chunk_index];
        }

        $widgetEvents = $this->prepareWidgetEvents($session, $workspace);
        $siteUrl = Site::query()->whereKey($session->site_id)->value('url');
        $siteOrigin = null;
        if (is_string($siteUrl) && ($parts = parse_url(trim($siteUrl))) !== false) {
            $scheme = strtolower((string) ($parts['scheme'] ?? ''));
            $host = strtolower((string) ($parts['host'] ?? ''));
            if (in_array($scheme, ['http', 'https'], true) && $host !== '') {
                $siteOrigin = $scheme.'://'.$host.(isset($parts['port']) ? ':'.(int) $parts['port'] : '');
            }
        }

        return [
            'chunks' => $chunkFiles,
            'max_decoded_bytes' => max(1024 * 1024, (int) config('visitor-intelligence.replay_video.max_decoded_bytes', 268435456)),
            'max_events' => $maxEvents,
            'max_duration_seconds' => max(30, (int) config('visitor-intelligence.replay_video.max_duration_seconds', 600)),
            'fps' => (int) $export->fps,
            'max_output_dimension' => max(720, min(7680, (int) config('visitor-intelligence.replay_video.max_output_dimension', 4320))),
            'speed' => (float) $export->playback_speed,
            'skip_inactive' => (bool) $export->skip_inactive,
            'replay_umd_path' => (string) config('visitor-intelligence.rrweb_context.replay_umd_path'),
            'site_origin' => $siteOrigin,
            'chromium_path' => config('visitor-intelligence.rrweb_context.chromium_binary_path')
                ?: config('visitor-intelligence.rrweb_context.chromium_path'),
            'widget_events' => $widgetEvents,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function prepareWidgetEvents(VisitorSession $session, string $workspace): array
    {
        $maxFrames = max(0, (int) config('visitor-intelligence.replay_video.max_widget_frames', 120));
        if ($maxFrames === 0) return [];
        $disk = Storage::disk((string) config('visitor-intelligence.frame_storage_disk', 'public'));
        $prefix = "visitor-intelligence/frames/{$session->site_id}/{$session->id}/";
        $timeline = [];
        $savedFrames = 0;
        $savedBytes = 0;

        AnalyticsEvent::query()
            ->where('site_id', $session->site_id)
            ->where('session_id', $session->session_key)
            ->where('source', 'visitor_intelligence')
            ->orderBy('occurred_at')
            ->orderBy('created_at')
            ->cursor()
            ->each(function (AnalyticsEvent $event) use (&$timeline, &$savedFrames, &$savedBytes, $maxFrames, $disk, $prefix, $workspace): void {
                $timestamp = $event->occurred_at?->valueOf();
                if (!$timestamp) return;
                $type = strtolower((string) $event->event_type);
                if ($type === 'widget_opened') $timeline[] = ['kind' => 'open', 'timestamp' => $timestamp];
                if (in_array($type, ['widget_close', 'widget_closed'], true)) $timeline[] = ['kind' => 'close', 'timestamp' => $timestamp];

                $metadata = is_array($event->metadata) ? $event->metadata : [];
                if (strtolower((string) ($metadata['surface'] ?? '')) !== 'widget'
                    || strtolower((string) ($metadata['capture_mode'] ?? '')) !== 'viewport'
                    || $savedFrames >= $maxFrames) return;

                $hostWidth = $this->positiveNumber($metadata['host_viewport_width'] ?? null);
                $hostHeight = $this->positiveNumber($metadata['host_viewport_height'] ?? null);
                $width = $this->positiveNumber($metadata['widget_width'] ?? null);
                $height = $this->positiveNumber($metadata['widget_height'] ?? null);
                $left = $this->nonNegativeNumber($metadata['widget_left'] ?? null);
                $top = $this->nonNegativeNumber($metadata['widget_top'] ?? null);
                $path = $metadata['screenshot_path'] ?? null;
                $frameName = is_string($path) && str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : '';
                if (!$hostWidth || !$hostHeight || !$width || !$height || $left === null || $top === null
                    || $frameName === ''
                    || !preg_match('/^[a-zA-Z0-9_-]{1,128}\.(?:jpe?g|png|webp)$/iD', $frameName)) return;

                try {
                    if (!$disk->exists($path)) return;
                    $bytes = (int) $disk->size($path);
                    if ($bytes <= 0 || $bytes > 524288 || $savedBytes + $bytes > 8388608) return;
                    $stream = $disk->readStream($path);
                    if (!is_resource($stream)) return;
                    $framePath = $workspace.DIRECTORY_SEPARATOR.sprintf('widget-%03d%s', $savedFrames, strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'jpeg' ? '.jpg' : '.'.strtolower(pathinfo($path, PATHINFO_EXTENSION)));
                    $target = fopen($framePath, 'wb');
                    if (!is_resource($target)) { fclose($stream); return; }
                    $copied = stream_copy_to_stream($stream, $target);
                    fclose($stream);
                    fclose($target);
                    if (!is_int($copied) || $copied !== $bytes) {
                        @unlink($framePath);
                        return;
                    }

                    $timeline[] = [
                        'kind' => 'frame',
                        'key' => (string) $event->id,
                        'timestamp' => $timestamp,
                        'path' => $framePath,
                        'host_width' => $hostWidth,
                        'host_height' => $hostHeight,
                        'left' => $left,
                        'top' => $top,
                        'width' => $width,
                        'height' => $height,
                    ];
                    $savedFrames++;
                    $savedBytes += $bytes;
                } catch (Throwable $exception) {
                    Log::notice('Visitor replay export skipped an unavailable widget frame.', [
                        'site_id' => (string) $event->site_id,
                        'session_id' => (string) $event->session_id,
                        'error' => $exception->getMessage(),
                    ]);
                }
            });

        return $timeline;
    }

    private function positiveNumber(mixed $value): ?float
    {
        $number = is_numeric($value) ? (float) $value : 0.0;
        return is_finite($number) && $number > 0 ? $number : null;
    }

    private function nonNegativeNumber(mixed $value): ?float
    {
        $number = is_numeric($value) ? (float) $value : -1.0;
        return is_finite($number) && $number >= 0 ? $number : null;
    }

    private function writeJson(string $path, array $data): void
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($path, $json, LOCK_EX) !== strlen($json)) {
            throw new \RuntimeException('replay_video_manifest_write_failed');
        }
    }
}
