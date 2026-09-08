<?php

namespace App\Services\VisitorIntelligence;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;

/**
 * Le pipeline de capture d'écran (html2canvas côté widget) a été retiré :
 * rrweb est l'unique canal de replay visuel (voir VisitorIntelligenceReplayService).
 * Ce service ne conserve que la capacité de purge des screenshots déjà stockés
 * avant ce changement, nécessaire pour une suppression RGPD complète tant que
 * ces fichiers existent encore sur le disque de stockage legacy.
 */
class VisitorIntelligenceFrameService
{
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
