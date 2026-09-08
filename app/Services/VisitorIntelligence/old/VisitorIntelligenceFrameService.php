<?php

namespace App\Services\VisitorIntelligence;

use App\Models\Site;
use App\Models\VisitorSession;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class VisitorIntelligenceFrameService
{
    public function store(UploadedFile $file, Site $site, VisitorSession $session, string $eventId): array
    {
        $diskName = (string) config('visitor-intelligence.frame_storage_disk', 'public');
        $extension = match ($file->getMimeType()) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };
        $safeEventId = preg_replace('/[^a-zA-Z0-9_-]/', '', $eventId) ?: (string) Str::uuid();
        $path = "visitor-intelligence/frames/{$site->id}/{$session->id}/{$safeEventId}.{$extension}";

        $stored = Storage::disk($diskName)->putFileAs(dirname($path), $file, basename($path));
        if (!$stored) {
            throw new \RuntimeException("Impossible d'écrire le screenshot sur le disque [{$diskName}].");
        }

        return [
            'path' => $path,
            'url' => Storage::disk($diskName)->url($path),
            'mime_type' => (string) $file->getMimeType(),
            'bytes' => (int) $file->getSize(),
        ];
    }

    public function deleteForQuery(Builder $query): int
    {
        $deleted = 0;

        while (true) {
            $events = (clone $query)->select(['id', 'metadata'])->limit(500)->get();
            if ($events->isEmpty()) break;

            foreach ($events as $event) {
                $this->deleteMetadataFile($event->metadata ?? []);
            }

            $ids = $events->pluck('id')->all();
            $deleted += (clone $query)->whereKey($ids)->delete();
        }

        return $deleted;
    }

    public function deleteMetadataFile(array $metadata): void
    {
        $path = $metadata['screenshot_path'] ?? null;
        if (!is_string($path) || !str_starts_with($path, 'visitor-intelligence/frames/')) return;

        Storage::disk((string) config('visitor-intelligence.frame_storage_disk', 'public'))->delete($path);
    }
}
