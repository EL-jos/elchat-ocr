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

        // 🧱 Création page
        $page = Page::create([
            'site_id' => $site->id,
            'crawl_job_id' => null,
            'source' => 'manual',
            'title' => $validated['title'],
            'url' => null,
            'content' => $processed['content'],
            'plain_text' => $processed['plain_text'],
            //'type' => $processed['type'] ?? 'custom',
            //'importance' => $processed['importance'] ?? 2.5,
            'is_indexed' => false,
        ]);

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
        $this->indexService->indexPage($page, [
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
            'message' => 'Manual content indexed successfully',
            'page_id' => $page->id,
        ]);
    }

    private function authorizeSite(Site $site)
    {
        if ($site->account_id !== auth()->user()->ownedAccount->id) {
            abort(403);
        }
    }
}

