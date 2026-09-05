<?php

namespace App\Services;

use App\Models\Document;
use Illuminate\Support\Facades\Storage;

/** Resolves local public uploads and private cloud-synchronised documents. */
final class DocumentPathResolver
{
    public function resolve(Document $document): ?string
    {
        if ($document->storage_disk && $document->storage_path) {
            $disk = Storage::disk($document->storage_disk);
            return method_exists($disk, 'path') ? $disk->path($document->storage_path) : null;
        }

        return $document->path ? public_path($document->path) : null;
    }
}
