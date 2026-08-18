<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Concerns\AuthorizesSiteAccess;
use App\Http\Controllers\Controller;
use App\Jobs\document\IndexDocumentJob;
use App\Jobs\product\ProductImportJob;
use App\Jobs\sitemap\ProcessSitemapJob;
use App\Models\Chunk;
use App\Models\Document;
use App\Models\Site;
use App\Services\DocumentLifecycleService;
use App\Services\DocumentLockService;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class DocumentController extends Controller
{
    use AuthorizesSiteAccess;

    private const DOCUMENT_MIMES = 'pdf,doc,docx,xls,xlsx,csv,txt,jpg,jpeg,png,webp,gif,bmp,tiff,tif';

    public function __construct(
        private readonly DocumentLifecycleService $lifecycle,
        private readonly DocumentLockService $locks,
    ) {
    }

    public function index(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'extension' => ['nullable', 'string', 'max:10'],
            'type' => ['nullable', Rule::in(['file', 'sitemap', 'image', 'other'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = $site->documents()
            ->withCount('chunks')
            ->withExists(['productImports as is_product_import'])
            ->latest('updated_at');

        if ($search = trim((string) ($validated['search'] ?? ''))) {
            $query->where(function ($builder) use ($search) {
                $builder->where('title', 'like', "%{$search}%")
                    ->orWhere('original_name', 'like', "%{$search}%")
                    ->orWhere('path', 'like', "%{$search}%");
            });
        }

        if (!empty($validated['extension'])) {
            $query->where('extension', Str::lower($validated['extension']));
        }

        if (!empty($validated['type'])) {
            $query->where('type', $validated['type']);
        }

        $documents = $query->paginate((int) ($validated['per_page'] ?? 60));
        $documents->through(fn (Document $document) => $this->resource($document));

        return response()->json($documents);
    }

    public function show(Request $request, Site $site, Document $document): JsonResponse
    {
        $this->authorizeDocument($request, $site, $document);

        return response()->json(['data' => $this->resource($document->loadCount('chunks'))]);
    }

    public function store(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);

        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'file' => ['required', 'file', 'max:20480', 'mimes:'.self::DOCUMENT_MIMES],
            'mapping' => ['nullable', 'json'],
        ]);

        $mapping = !empty($validated['mapping'])
            ? json_decode($validated['mapping'], true, 512, JSON_THROW_ON_ERROR)
            : [];
        $uploadedFile = $request->file('file');
        $fileData = $this->lifecycle->storeUploadedFile($uploadedFile);
        $title = trim((string) ($validated['title'] ?? ''))
            ?: pathinfo($uploadedFile->getClientOriginalName(), PATHINFO_FILENAME);

        $document = $site->documents()->create(array_merge($fileData, [
            'title' => $title,
            'type' => 'file',
            'purpose' => $mapping !== [] ? 'product_import' : 'knowledge',
            'indexing_status' => 'queued',
        ]));

        $site->update(['status' => 'indexing']);

        if ($mapping !== []) {
            ProductImportJob::dispatch($document, $mapping, $site);
        } else {
            IndexDocumentJob::dispatch($document, $site, $document->index_revision);
        }

        return response()->json([
            'data' => $this->resource($document->loadCount('chunks')),
            'message' => 'Document uploadé et indexation en cours.',
        ], 202);
    }

    public function update(Request $request, Site $site, Document $document): JsonResponse
    {
        $this->authorizeDocument($request, $site, $document);

        abort_unless($this->isManageable($document), 422, 'Cette ressource se gère depuis son onglet métier.');

        $isSitemap = $this->isSitemap($document);
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'file' => [
                'nullable',
                'file',
                $isSitemap ? 'max:2048' : 'max:20480',
                'mimes:'.($isSitemap ? 'xml,txt' : self::DOCUMENT_MIMES),
            ],
        ]);

        return $this->locked($document, function () use ($request, $site, $document, $validated, $isSitemap) {
            $oldPath = null;
            $changes = [
                'title' => trim($validated['title']),
                'indexing_status' => 'queued',
                'indexing_error' => null,
                'index_revision' => ((int) $document->index_revision) + 1,
            ];

            if ($request->hasFile('file')) {
                $oldPath = $document->path;
                $changes = array_merge(
                    $changes,
                    $this->lifecycle->storeUploadedFile($request->file('file'), $isSitemap ? 'sitemap' : 'file')
                );
            }

            $document->update($changes);
            $document->refresh();

            if ($oldPath !== null) {
                $this->lifecycle->deleteManagedFile($oldPath);
            }

            $site->update(['status' => $isSitemap ? 'crawling' : 'indexing']);

            if ($isSitemap) {
                ProcessSitemapJob::dispatch($site->id, $document->id, $document->index_revision);
            } else {
                IndexDocumentJob::dispatch($document, $site, $document->index_revision);
            }

            return response()->json([
                'data' => $this->resource($document->loadCount('chunks')),
                'message' => 'Ressource mise à jour. La réindexation a démarré.',
            ], 202);
        });
    }

    public function destroy(Request $request, Site $site, Document $document): JsonResponse
    {
        $this->authorizeDocument($request, $site, $document);

        abort_unless($this->isManageable($document), 422, 'Cette ressource se gère depuis son onglet métier.');

        return $this->locked($document, function () use ($site, $document) {
            $deletedChunks = $this->lifecycle->deleteDocument($document, $site);

            return response()->json([
                'message' => 'Ressource et chunks associés supprimés.',
                'deleted_chunks' => $deletedChunks,
            ]);
        });
    }

    public function reindex(Request $request, Site $site, Document $document): JsonResponse
    {
        $this->authorizeDocument($request, $site, $document);

        abort_unless($this->isManageable($document), 422, 'Cette ressource se gère depuis son onglet métier.');

        return $this->locked($document, function () use ($site, $document) {
            $document->update([
                'indexing_status' => 'queued',
                'indexing_error' => null,
                'index_revision' => ((int) $document->index_revision) + 1,
            ]);
            $document->refresh();

            if ($this->isSitemap($document)) {
                $site->update(['status' => 'crawling']);
                ProcessSitemapJob::dispatch($site->id, $document->id, $document->index_revision);
            } else {
                $site->update(['status' => 'indexing']);
                IndexDocumentJob::dispatch($document, $site, $document->index_revision);
            }

            return response()->json([
                'data' => $this->resource($document->loadCount('chunks')),
                'message' => 'Réindexation démarrée.',
            ], 202);
        });
    }

    private function authorizeDocument(Request $request, Site $site, Document $document): void
    {
        $this->authorizeSiteAccess($request, $site);

        abort_unless(
            $document->documentable_type === Site::class
            && (string) $document->documentable_id === (string) $site->id,
            404
        );
    }

    private function isSitemap(Document $document): bool
    {
        return $document->type === 'sitemap'
            || Str::startsWith((string) $document->path, 'assets/sitemaps/')
            || Str::lower((string) $document->extension) === 'xml';
    }

    private function locked(Document $document, Closure $operation): JsonResponse
    {
        try {
            return $this->locks->run($document->id, $operation);
        } catch (LockTimeoutException) {
            abort(409, 'Une opération est déjà en cours sur cette ressource. Réessayez dans quelques instants.');
        }
    }

    private function isManageable(Document $document): bool
    {
        return in_array($this->purpose($document), ['knowledge', 'sitemap'], true);
    }

    private function purpose(Document $document, ?bool $productImport = null): string
    {
        if ($productImport ?? $document->productImports()->exists()) {
            return 'product_import';
        }
        if ($this->isSitemap($document)) {
            return 'sitemap';
        }
        if (Str::startsWith((string) $document->path, 'assets/resources/pages/')) {
            return 'page_import';
        }
        if (in_array($document->purpose, ['product_import', 'page_import', 'sitemap'], true)) {
            return $document->purpose;
        }
        if ($document->type === 'other') {
            return 'system';
        }

        return $document->purpose ?: 'knowledge';
    }

    private function resource(Document $document): array
    {
        $extension = Str::lower((string) ($document->extension ?: pathinfo((string) $document->path, PATHINFO_EXTENSION)));
        $isSitemap = $this->isSitemap($document);
        $isImage = in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp', 'tiff', 'tif'], true);
        $productImport = (bool) ($document->is_product_import ?? $document->productImports()->exists());
        $purpose = $this->purpose($document, $productImport);

        return [
            'id' => $document->id,
            'title' => $document->title ?: pathinfo($document->original_name ?: basename((string) $document->path), PATHINFO_FILENAME),
            'original_name' => $document->original_name ?: basename((string) $document->path),
            'type' => $isSitemap ? 'sitemap' : $document->type,
            'extension' => $extension ?: null,
            'mime_type' => $document->mime_type,
            'file_size' => $document->file_size,
            'url' => url('/'.ltrim((string) $document->path, '/')),
            'preview_kind' => $isImage ? 'image' : ($extension === 'pdf' ? 'pdf' : 'icon'),
            'chunks_count' => $this->chunkCount($document, $isSitemap),
            'indexing_status' => $document->indexing_status,
            'indexing_error' => $document->indexing_error,
            'last_indexed_at' => $document->last_indexed_at?->toISOString(),
            'can_manage' => in_array($purpose, ['knowledge', 'sitemap'], true),
            'purpose' => $purpose,
            'created_at' => $document->created_at?->toISOString(),
            'updated_at' => $document->updated_at?->toISOString(),
        ];
    }

    private function chunkCount(Document $document, bool $isSitemap): int
    {
        $directCount = (int) ($document->chunks_count ?? $document->chunks()->count());
        if (!$isSitemap) {
            return $directCount;
        }

        return $directCount + Chunk::query()
            ->where('site_id', $document->documentable_id)
            ->whereNull('document_id')
            ->whereHas('page.crawlJob', function ($crawlJobs) use ($document) {
                $crawlJobs->where('source_document_id', $document->id);
            })
            ->count();
    }
}
