<?php

namespace App\Jobs;
use romanzipp\QueueMonitor\Traits\IsMonitored;

use App\Models\Chunk;
use App\Models\Document;
use App\Models\Page;
use App\Models\Site;
use App\Services\crawl\CrawlService;
use App\Services\IndexService;
use App\Services\MercureService;
use App\Services\vector\VectorIndexService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

class PageImportJob implements ShouldQueue
{
    use IsMonitored;
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected Document $document;
    protected array $mapping;
    protected Site $site;
    public $timeout = 600;

    public function __construct(Document $document, array $mapping, Site $site)
    {
        $this->document = $document;
        $this->mapping = $mapping;
        $this->site = $site;
    }

    public function handle(CrawlService $crawlService, IndexService $indexService, VectorIndexService $vectorIndexService, MercureService $mercureService)
    {
        $this->document->update(['indexing_status' => 'processing', 'indexing_error' => null]);

        $mercureService->post(
            "site/{$this->site->id}/pages/indexing",
            [
                'type' => 'indexing_progress',
                'progress' => 0,
                'message' => 'Lecture du fichier...',
                'done' => false
            ]
        );

        Log::info("🚀 PageImportJob démarré pour le site {$this->site->id}");
        $this->site->update(['status' => 'indexing']);

        try {
            $rows = $this->parseFile($this->document->path);

            $totalPages = count($rows);

            if ($totalPages === 0) {

                $this->site->update(['status' => 'ready']);
                $this->document->update([
                    'indexing_status' => 'failed',
                    'indexing_error' => 'Aucune page exploitable trouvée dans le fichier.',
                ]);

                $mercureService->post(
                    "site/{$this->site->id}/pages/indexing",
                    [
                        'type' => 'indexing_warning',
                        'progress' => 100,
                        'message' => 'Aucune page trouvée dans le fichier',
                        'done' => true
                    ]
                );

                return;
            }

            $processedPages = 0;

            $mercureService->post(
                "site/{$this->site->id}/pages/indexing",
                [
                    'type' => 'indexing_progress',
                    'progress' => 5,
                    'message' => "{$totalPages} pages détectées",
                    'done' => false
                ]
            );

            $batchSize = 50;
            $chunks = array_chunk($rows, $batchSize);

            $mercureService->post(
                "site/{$this->site->id}/pages/indexing",
                [
                    'type' => 'indexing_progress',
                    'progress' => 10,
                    'message' => 'Préparation des batches...',
                    'done' => false
                ]
            );

            foreach ($chunks as $batchIndex => $batchRows) {
                Log::info("📦 Batch {$batchIndex} traitement démarré pour site {$this->site->id}");

                foreach ($batchRows as $row) {

                    try {

                        $pageData = $this->mapRow($row);

                        if (empty($pageData['content']) && empty($pageData['url'])) {

                            $processedPages++;

                            $progress = 10 + round(($processedPages / max($totalPages, 1)) * 85);

                            $mercureService->post(
                                "site/{$this->site->id}/pages/indexing",
                                [
                                    'type' => 'indexing_warning',
                                    'progress' => $progress,
                                    'processed' => $processedPages,
                                    'total' => $totalPages,
                                    'message' => "Ligne ignorée : contenu et URL vides",
                                    'done' => false
                                ]
                            );
                            continue;
                        }

                        // Normalisation URL
                        $url = $pageData['url']
                            ? $crawlService->normalizeUrl($pageData['url'])
                            : null;

                        // Préparer meta
                        $meta = [
                            'title' => $pageData['title'] ?? null,
                            'description' => $pageData['seo_description'] ?? null,
                            'keywords' => !empty($pageData['seo_keywords']) ? explode(',', $pageData['seo_keywords']) : [],
                            'published_at' => $pageData['published_at'] ?? null,
                        ];

                        // Ajouter résumé, catégories, tags dans le contenu pour RAG
                        $content = $pageData['content'] ?? '';
                        if (!empty($pageData['excerpt'])) {
                            $content = "<h2>Résumé</h2><p>{$pageData['excerpt']}</p>" . $content;
                        }
                        if (!empty($pageData['categories'])) {
                            $content .= "<h3>Catégories</h3><p>{$pageData['categories']}</p>";
                        }
                        if (!empty($pageData['tags'])) {
                            $content .= "<h3>Tags</h3><p>{$pageData['tags']}</p>";
                        }

                        // Process content via CrawlService pour RAG
                        $processed = $crawlService->processRawContent($this->site, $content, $meta, $url);

                        // Créer ou update page
                        $page = Page::updateOrCreate(
                            [
                                'site_id' => $this->site->id,
                                'url' => $url,
                            ],
                            [
                                'title' => $pageData['title'] ?? null,
                                'content' => $processed['content'],
                                'plain_text' => $processed['plain_text'],
                                //'type'       => $processed['type'] ?? 'article',
                                //'importance' => $processed['importance'] ?? 2.5,
                                'source' => 'import',
                            ]
                        );

                        Log::info("📄 Page traitée: {$page->title} | URL: {$page->url}");
                        $mercureService->post(
                            "site/{$this->site->id}/pages/indexing",
                            [
                                'type' => 'indexing_info',
                                'message' => "Page indexée : {$page->title}",
                                'done' => false
                            ]
                        );

                        // Indexation RAG
                        if (!empty($page->content)) {
                            // Supprimer anciens chunks
                            $existingChunks = Chunk::where('page_id', $page->id)->pluck('id')->all();
                            if (!empty($existingChunks)) {
                                $vectorIndexService->deleteChunksBatch($existingChunks, "chunks_{$this->site->id}");
                                Chunk::whereIn('id', $existingChunks)->delete();
                                Log::info("♻️ Chunks existants supprimés pour la page: {$page->title}");
                            }

                            $mercureService->post(
                                "site/{$this->site->id}/pages/indexing",
                                [
                                    'type' => 'indexing_info',
                                    'message' => "Indexation de la page : {$page->title}",
                                    'done' => false
                                ]
                            );
                            $indexService->indexPage($page, ['source' => 'import']);
                            Log::info("✅ Page indexée: {$page->title}");

                        }

                        $processedPages++;
                        $progress = 10 + round(($processedPages / max($totalPages, 1)) * 85);
                        $mercureService->post(
                            "site/{$this->site->id}/pages/indexing",
                            [
                                'type' => 'indexing_progress',
                                'progress' => $progress,
                                'processed' => $processedPages,
                                'total' => $totalPages,
                                'message' => "Import des pages ({$processedPages}/{$totalPages})",
                                'done' => false
                            ]
                        );

                    }catch (Throwable  $e){
                        $processedPages++;
                        $progress = 10 + round(($processedPages / max($totalPages, 1)) * 85);
                        Log::error("❌ Erreur import page", [
                            'error' => $e->getMessage(),
                            'row' => $row
                        ]);
                        $mercureService->post(
                            "site/{$this->site->id}/pages/indexing",
                            [
                                'type' => 'indexing_warning',
                                'progress' => $progress,
                                'message' => "Page ignorée : " . $e->getMessage(),
                                'done' => false
                            ]
                        );
                    }

                }

                Log::info("📦 Batch {$batchIndex} terminé pour site {$this->site->id}");
            }

            $this->site->update(['status' => 'ready']);
            $this->document->update([
                'indexing_status' => 'indexed',
                'last_indexed_at' => now(),
                'indexing_error' => null,
            ]);
            $mercureService->post(
                "site/{$this->site->id}/pages/indexing",
                [
                    'type' => 'indexing_progress',
                    'progress' => 100,
                    'processed' => $processedPages,
                    'total' => $totalPages,
                    'message' => "Import terminé ({$processedPages}/{$totalPages}) ✅",
                    'done' => true
                ]
            );
            Log::info("🎉 PageImportJob terminé avec succès pour site {$this->site->id}");

        } catch (\Throwable $e) {
            $this->site->update(['status' => 'error']);
            $this->document->update([
                'indexing_status' => 'failed',
                'indexing_error' => mb_substr($e->getMessage(), 0, 2000),
            ]);
            $mercureService->post(
                "site/{$this->site->id}/pages/indexing",
                [
                    'type' => 'indexing_error',
                    'message' => $e->getMessage(),
                    'done' => true
                ]
            );
            Log::error("❌ PageImportJob échoué pour site {$this->site->id}: {$e->getMessage()}");
            throw $e;
        }
    }
    protected function parseFile(string $path): array
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $rows = [];

        if (in_array($extension, ['xls','xlsx'])) {
            $spreadsheet = IOFactory::load(public_path($path));

            foreach ($spreadsheet->getAllSheets() as $sheet) {
                $sheetRows = $sheet->toArray(null, true, true, true);
                if (count($sheetRows) < 2) continue;

                $headers = array_map(fn($h) => trim((string)$h), array_shift($sheetRows));

                foreach ($sheetRows as $row) {
                    $row = array_values($row);
                    if (count($row) !== count($headers)) continue; // ✅ ignore ligne invalide
                    $rows[] = array_combine($headers, $row);
                }
            }
        } else { // csv
            if (($handle = fopen(public_path($path), 'r')) !== false) {
                $headers = fgetcsv($handle, 0, ',');
                $headers = array_map('trim', $headers);

                while (($data = fgetcsv($handle, 0, ',')) !== false) {
                    if (count($data) !== count($headers)) continue; // ✅ ignore ligne invalide
                    $rows[] = array_combine($headers, $data);
                }

                fclose($handle);
            }
        }

        return $rows;
    }
    protected function mapRow(array $row): array
    {
        return [
            'title'        => $row[$this->mapping['title']] ?? null,
            'content'      => $row[$this->mapping['content']] ?? null,
            'url'          => $row[$this->mapping['url']] ?? null,
            'categories'   => $row[$this->mapping['categories']] ?? null,
            'tags'         => $row[$this->mapping['tags']] ?? null,
            'seo_keywords' => $row[$this->mapping['seo_keywords']] ?? null,
            'seo_description' => $row[$this->mapping['seo_description']] ?? null,
        ];
    }
}
