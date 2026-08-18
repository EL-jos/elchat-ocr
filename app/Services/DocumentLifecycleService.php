<?php

namespace App\Services;

use App\Models\Chunk;
use App\Models\Document;
use App\Models\Site;
use App\Services\lexical\LexicalIndexService;
use App\Services\vector\VectorIndexService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class DocumentLifecycleService
{
    public function __construct(
        private readonly VectorIndexService $vectorIndex,
        private readonly LexicalIndexService $lexicalIndex,
    ) {
    }

    public function storeUploadedFile(UploadedFile $file, string $purpose = 'file'): array
    {
        $extension = Str::lower($file->getClientOriginalExtension());
        $directory = $purpose === 'sitemap'
            ? 'assets/sitemaps'
            : 'assets/resources/documents';

        File::ensureDirectoryExists(public_path($directory));

        $storedName = Str::uuid().($extension !== '' ? ".{$extension}" : '');
        $file->move(public_path($directory), $storedName);

        return [
            'path' => "{$directory}/{$storedName}",
            'extension' => $extension !== '' ? $extension : null,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'file_size' => File::size(public_path("{$directory}/{$storedName}")),
        ];
    }

    public function purgeChunks(Document $document, Site $site): int
    {
        $chunkIds = Chunk::query()
            ->where('site_id', $site->id)
            ->where(function ($query) use ($document) {
                $query->where('document_id', $document->id)
                    ->orWhereHas('page.crawlJob', function ($crawlJobs) use ($document) {
                        $crawlJobs->where('source_document_id', $document->id);
                    });
            })
            ->pluck('id')
            ->map(static fn ($id) => (string) $id)
            ->all();

        if ($chunkIds === []) {
            return 0;
        }

        $this->vectorIndex->deleteChunksBatch($chunkIds, "chunks_{$site->id}");
        $this->lexicalIndex->deleteChunksBatch($chunkIds, $site->id);
        Chunk::query()->whereIn('id', $chunkIds)->delete();

        return count($chunkIds);
    }

    public function deleteDocument(Document $document, Site $site): int
    {
        $deletedChunks = $this->purgeChunks($document, $site);
        $path = $document->path;

        $document->delete();
        $this->deleteManagedFile($path);

        return $deletedChunks;
    }

    public function deleteManagedFile(?string $path): void
    {
        $relativePath = ltrim(str_replace('\\', '/', (string) $path), '/');

        if (!Str::startsWith($relativePath, [
            'assets/resources/documents/',
            'assets/sitemaps/',
        ])) {
            return;
        }

        $resolvedFile = realpath(public_path($relativePath));
        if ($resolvedFile === false || !is_file($resolvedFile)) {
            return;
        }

        $allowedDirectories = array_filter([
            realpath(public_path('assets/resources/documents')),
            realpath(public_path('assets/sitemaps')),
        ]);

        foreach ($allowedDirectories as $directory) {
            $directoryPrefix = rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
            if (Str::startsWith($resolvedFile, $directoryPrefix)) {
                File::delete($resolvedFile);
                return;
            }
        }
    }
}
