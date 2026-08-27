<?php

namespace App\Jobs;
use romanzipp\QueueMonitor\Traits\IsMonitored;

use App\Models\Chunk;
use App\Models\Page;
use App\Services\CrawlService;
use App\Services\IndexService;
use App\Services\MercureService;
use App\Services\vector\VectorIndexService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class RecrawlSinglePageJob implements ShouldQueue
{
    use IsMonitored;
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        protected string $pageId,
        protected string $siteId
    ) {}

    public function handle(
        CrawlService $crawlService,
        IndexService $indexService,
        VectorIndexService $vectorIndexService,
        MercureService $mercureService
    ) {


        $oldPage = Page::findOrFail($this->pageId);
        $site = $oldPage->site;
        $topic = "site/{$site->id}/pages/indexing";

        $mercureService->post(
            topic: $topic,
            data: [
                'type' => 'indexing_progress',
                'progress' => 0,
                'message' => 'Démarrage du recrawl...',
                'done' => false,
            ]
        );

        $site->update([
            'status' => 'indexing'
        ]);

        try {

            // tout ton code
            if ($oldPage->source === "manual"){

                $manualContent = $oldPage;

                $isUpdate = $manualContent->update([
                    "is_indexed" => false
                ]);

                $mercureService->post(
                    topic: $topic,
                    data: [
                        'type' => 'indexing_progress',
                        'progress' => 50,
                        'message' => 'Réindexation du contenu manuel...',
                        'done' => false,
                    ]
                );

                if($isUpdate){

                    $indexService->indexPage($manualContent, [
                        'source'  => 'recrawl',
                        'site_id' => $site->id,
                    ]);

                    Log::info("Recrawl completed", [
                        'site_id' => $site->id,
                        'page_id' => $manualContent->id,
                    ]);

                    // 🔄 On passe en ready immédiatement
                    $site->update([
                        'status' => 'ready'
                    ]);
                    $mercureService->post(
                        topic: $topic,
                        data: [
                            'type' => 'indexing_progress',
                            'progress' => 100,
                            'message' => 'Réindexation terminée',
                            'done' => true,
                        ]
                    );
                }else{

                    $site->update([
                        'status' => 'error'
                    ]);

                    Log::warning("Recrawl failed — page not updated", [
                        'id' => $oldPage->id,
                        'site_id' => $site->id
                    ]);

                    $mercureService->post(
                        topic: $topic,
                        data: [
                            'type' => 'indexing_error',
                            'progress' => 50,
                            'message' => 'Échec de la nouvelle exploration — page non mise à jour',
                            'done' => true,
                        ]
                    );
                }

                return;
            }

            DB::beginTransaction();

            try {

                /*
                |--------------------------------------------------------------------------
                | 1️⃣ Supprimer anciens chunks
                |--------------------------------------------------------------------------
                */
                $chunkIds = Chunk::where('page_id', $oldPage->id)
                    ->pluck('id')
                    ->toArray();

                if (!empty($chunkIds)) {

                    $mercureService->post(
                        topic: $topic,
                        data: [
                            'type' => 'indexing_progress',
                            'progress' => 10,
                            'message' => 'Suppression des anciens chunks...',
                            'done' => false,
                        ]
                    );

                    $vectorIndexService->deleteChunksBatch($chunkIds, collection: "chunks_{$site->id}");
                    Chunk::whereIn('id', $chunkIds)->delete();
                }

                /*
                |--------------------------------------------------------------------------
                | 2️⃣ Supprimer ancienne page
                |--------------------------------------------------------------------------
                */
                $oldPage->delete();

                $mercureService->post(
                    topic: $topic,
                    data: [
                        'type' => 'indexing_progress',
                        'progress' => 25,
                        'message' => 'Ancienne page supprimée...',
                        'done' => false,
                    ]
                );

                DB::commit();

            } catch (\Throwable $e) {
                DB::rollBack();
                throw $e;
            }

            /*
            |--------------------------------------------------------------------------
            | 3️⃣ Re-crawl (même moteur que sitemap / crawl URL)
            |--------------------------------------------------------------------------
            */
            $mercureService->post(
                topic: $topic,
                data: [
                    'type' => 'indexing_progress',
                    'progress' => 50,
                    'message' => 'Récupération de la nouvelle version...',
                    'done' => false,
                ]
            );

            $url = $oldPage->url;
            $newPage = $crawlService->crawlSinglePage(
                $site,
                $url,
                0,
                null
            );

            if (!$newPage) {

                $site->update([
                    'status' => 'error'
                ]);

                Log::warning("Recrawl failed — page not recreated", [
                    'url' => $url,
                    'site_id' => $site->id
                ]);
                $mercureService->post(
                    topic: $topic,
                    data: [
                        'type' => 'indexing_error',
                        'message' => 'Impossible de recréer la page',
                        'done' => true,
                    ]
                );
                return;
            }

            /*
            |--------------------------------------------------------------------------
            | 4️⃣ Réindexation normale
            |--------------------------------------------------------------------------
            */

            $mercureService->post(
                topic: $topic,
                data: [
                    'type' => 'indexing_progress',
                    'progress' => 75,
                    'message' => 'Réindexation en cours...',
                    'done' => false,
                ]
            );
            $indexService->indexPage($newPage, [
                'source'  => 'recrawl',
                'site_id' => $site->id,
            ]);

            Log::info("Recrawl completed", [
                'site_id' => $site->id,
                'url' => $url,
                'page_id' => $newPage->id,
            ]);

            // 🔄 On passe en ready
            $site->update([
                'status' => 'ready'
            ]);

            $mercureService->post(
                topic: $topic,
                data: [
                    'type' => 'indexing_progress',
                    'progress' => 100,
                    'message' => 'Réindexation terminée avec succès',
                    'done' => true,
                ]
            );
        } catch (Throwable $e) {

            $site->update([
                'status' => 'error'
            ]);

            $mercureService->post(
                topic: $topic,
                data: [
                    'type' => 'indexing_error',
                    'message' => $e->getMessage(),
                    'done' => true,
                ]
            );

            throw $e;
        }

    }

    /*public function failed(Throwable $e): void
    {
        try {

            $topic = "site/{$this->siteId}/pages/indexing";

            app(MercureService::class)->post(
                topic: $topic,
                data: [
                    'type' => 'indexing_error',
                    'message' => $e->getMessage(),
                    'done' => true,
                ]
            );

        } catch (\Throwable $ignored) {
        }
    }*/
}
