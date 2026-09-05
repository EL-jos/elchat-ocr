<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Concerns\AuthorizesSiteAccess;
use App\Http\Controllers\Controller;
use App\Jobs\sitemap\ProcessSitemapJob;
use App\Models\Site;
use App\Services\DocumentLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SitemapController extends Controller
{
    use AuthorizesSiteAccess;

    public function __construct(private readonly DocumentLifecycleService $lifecycle)
    {
    }

    public function store(Request $request, Site $site): JsonResponse
    {
        $this->authorizeSiteAccess($request, $site);

        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'sitemap_file' => ['required', 'file', 'mimes:xml,txt', 'max:2048'],
            'include_pages' => ['nullable', 'array'],
            'include_pages.*' => ['string', 'max:2048'],
            'exclude_pages' => ['nullable', 'array'],
            'exclude_pages.*' => ['string', 'max:2048'],
        ]);

        $uploadedFile = $request->file('sitemap_file');
        $fileData = $this->lifecycle->storeUploadedFile($uploadedFile, 'sitemap');
        $title = trim((string) ($validated['title'] ?? ''))
            ?: pathinfo($uploadedFile->getClientOriginalName(), PATHINFO_FILENAME);

        $sitemap = $site->documents()->create(array_merge($fileData, [
            'title' => $title,
            'type' => 'sitemap',
            'purpose' => 'sitemap',
            'indexing_status' => 'queued',
        ]));

        $site->update([
            'include_pages' => $validated['include_pages'] ?? [],
            'exclude_pages' => $validated['exclude_pages'] ?? [],
            'status' => 'crawling',
        ]);

        ProcessSitemapJob::dispatch(
            siteId: $site->id,
            sitemapDocumentId: $sitemap->id,
            revision: $sitemap->index_revision,
        );

        return response()->json([
            'message' => 'Sitemap envoyé. Son traitement a démarré.',
            'document_id' => $sitemap->id,
        ], 202);
    }
}
