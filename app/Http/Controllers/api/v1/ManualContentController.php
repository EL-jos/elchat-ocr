<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Models\Site;
use App\Services\crawl\CrawlService;
use App\Services\IndexService;
use App\Services\MercureService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class ManualContentController extends Controller
{
    public function __construct(
        protected IndexService $indexService,
        protected CrawlService $crawlService,
        protected MercureService $mercureService,
    ) {}

    public function store(Request $request, Site $site)
    {
        $this->authorizeSite($site);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'required|string|min:20',
        ]);

        return $this->saveAndIndex($site, $validated);
    }

    public function show(Page $page)
    {
        $this->authorizeManualPage($page);

        return response()->json([
            'id' => $page->id,
            'title' => $page->title,
            'content' => $this->editableContent($page),
        ]);
    }

    public function update(Request $request, Page $page)
    {
        $site = $this->authorizeManualPage($page);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'required|string|min:20',
        ]);

        return $this->saveAndIndex($site, $validated, $page);
    }

    private function saveAndIndex(Site $site, array $validated, ?Page $page = null)
    {

        $this->mercureService->post(
            "site/{$site->id}/knowledge/indexing",
            [
                'type' => 'indexing_info',
                'progress' => 0,
                'message' => 'Indexation du contenu manuel...',
                'done' => false
            ]
        );

        // 🧠 Meta enrichie
        $meta = [
            'title' => $validated['title'],
            'description' => null,
            'keywords' => [],
            'published_at' => now()->toDateTimeString(),
        ];

        try {

            $this->mercureService->post(
                "site/{$site->id}/knowledge/indexing",
                [
                    'type' => 'indexing_progress',
                    'progress' => 30,
                    'message' => 'Analyse du contenu...',
                    'done' => false
                ]
            );

            // 🔥 Nouveau pipeline AI (avec fallback interne)
            $processed = $this->crawlService->processManualContentWithAI(
                $site,
                $validated['content'],
                $meta,
                null
            );

            $this->mercureService->post(
                "site/{$site->id}/knowledge/indexing",
                [
                    'type' => 'indexing_progress',
                    'progress' => 70,
                    'message' => 'Génération du contenu structuré...',
                    'done' => false
                ]
            );

        } catch (Throwable $e) {
            // ⚠️ Sécurité ultime (ne jamais casser l’expérience)
            Log::error("Manual AI processing failed", [
                'site_id' => $site->id,
                'error' => $e->getMessage()
            ]);

            // fallback classique
            $processed = $this->crawlService->processRawContent(
                $site,
                $validated['content'],
                $meta,
                null
            );
            $this->mercureService->post(
                "site/{$site->id}/knowledge/indexing",
                [
                    'type' => 'indexing_warning',
                    'progress' => 70,
                    'message' => 'Erreur AI, fallback utilisé',
                    'done' => false
                ]
            );

        }

        $pageData = [
            'title' => $validated['title'],
            'content' => $processed['content'],
            'plain_text' => $processed['plain_text'],
            'manual_source_content' => $validated['content'],
            'is_indexed' => false,
        ];

        if ($page) {
            $page->update($pageData);
        } else {
            $page = Page::create([
                'site_id' => $site->id,
                'crawl_job_id' => null,
                'source' => 'manual',
                'url' => null,
                ...$pageData,
            ]);
        }

        $this->mercureService->post(
            "site/{$site->id}/knowledge/indexing",
            [
                'type' => 'indexing_progress',
                'progress' => 90,
                'message' => 'Indexation en cours...',
                'done' => false
            ]
        );

        // 🚀 Indexation enrichie (GROS upgrade)
        $this->indexService->reindexPage($page, [
            'source' => $page->source,
            'site_id' => $site->id,
            /*'type' => $processed['type'] ?? 'custom',

            // 🔥 clés pour retrieval avancé
            'intents' => $processed['intents'] ?? [],
            'entities' => $processed['entities'] ?? [],*/
        ]);

        $this->mercureService->post(
            "site/{$site->id}/knowledge/indexing",
            [
                'type' => 'indexing_progress',
                'progress' => 100,
                'message' => 'Contenu indexé avec succès',
                'done' => true
            ]
        );

        return response()->json([
            'message' => $page->wasRecentlyCreated
                ? 'Manual content indexed successfully'
                : 'Manual content updated and reindexed successfully',
            'page_id' => $page->id,
        ]);
    }

    private function editableContent(Page $page): string
    {
        if (is_string($page->manual_source_content) && trim($page->manual_source_content) !== '') {
            return $page->manual_source_content;
        }

        if (is_string($page->plain_text) && trim($page->plain_text) !== '') {
            return $page->plain_text;
        }

        $decoded = json_decode((string) $page->content, true);
        if (is_array($decoded) && is_array($decoded['sections'] ?? null)) {
            $sectionText = collect($decoded['sections'])
                ->map(function ($section): string {
                    if (! is_array($section)) {
                        return '';
                    }

                    return trim(implode("\n", array_filter([
                        $section['title'] ?? null,
                        $section['content'] ?? null,
                    ], static fn ($value) => is_string($value) && trim($value) !== '')));
                })
                ->filter()
                ->implode("\n\n");

            if ($sectionText !== '') {
                return $sectionText;
            }
        }

        return (string) $page->content;
    }

    private function authorizeManualPage(Page $page): Site
    {
        $site = $page->site;
        $this->authorizeSite($site);

        abort_unless(in_array($page->source, ['manual', 'manuel'], true), 404);

        return $site;
    }

    private function authorizeSite(Site $site)
    {
        if ($site->account_id !== auth()->user()->ownedAccount->id) {
            abort(403);
        }
    }
}

