<?php

namespace App\Jobs\sitemap;

use App\Models\Document;
use App\Models\Site;
use App\Services\MercureService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Symfony\Component\BrowserKit\HttpBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpClient\HttpClient;

class GenerateSitemapJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected string $siteId;
    protected int $maxUrls = 5000;
    protected int $maxDepth = 1; // 🔹 Limite à 1

    public function __construct(Site $site)
    {
        $this->siteId = (string) $site->id;
    }

    public function handle(): void
    {
        $site = Site::findOrFail($this->siteId);

        $this->notify('indexing_progress', 0, 'Initialisation du sitemap...', false);

        $baseUrl = rtrim($site->url, '/');
        $host = parse_url($baseUrl, PHP_URL_HOST);

        $client = new HttpBrowser(HttpClient::create([
            'timeout' => 60,
        ]));

        $visited = [];
        $toVisit = [['url' => $baseUrl, 'depth' => 0]];

        $totalEstimated = 100; // base simplifiée pour UX
        $stepProgress = 0;

        try {

            while (!empty($toVisit) && count($visited) < $this->maxUrls) {

                $current = array_shift($toVisit);
                $url = $current['url'];
                $depth = $current['depth'];

                if (isset($visited[$url]) || $depth > $this->maxDepth) {
                    continue;
                }

                try {
                    $this->notify(
                        'indexing_progress',
                        min(80, intval((count($visited) / $this->maxUrls) * 80)),
                        "Analyse : {$url}",
                        false
                    );

                    $crawler = $client->request('GET', $url);
                    $visited[$url] = true;

                    if ($depth < $this->maxDepth) {
                        $crawler->filter('a[href]')->each(function (Crawler $node) use (&$toVisit, &$visited, $host, $baseUrl, $depth) {

                            $href = trim($node->attr('href'));

                            if (
                                empty($href) ||
                                str_starts_with($href, '#') ||
                                str_starts_with($href, 'mailto:') ||
                                str_starts_with($href, 'tel:')
                            ) {
                                return;
                            }

                            if (str_starts_with($href, '/')) {
                                $href = $baseUrl . $href;
                            }

                            $parsedHost = parse_url($href, PHP_URL_HOST);

                            if ($parsedHost === $host) {
                                $href = rtrim($href, '/');

                                if (!isset($visited[$href])) {
                                    $toVisit[] = ['url' => $href, 'depth' => $depth + 1];
                                }
                            }
                        });
                    }

                } catch (\Throwable $e) {
                    // warning non bloquant
                    $this->notify('indexing_warning', 0, "Erreur sur {$url}", false);
                    continue;
                }
            }

            // STEP 2: BUILD SITEMAP
            $this->notify('indexing_progress', 85, 'Génération du fichier sitemap...', false);

            $document = $this->saveSitemapDocument(array_keys($visited), $site);

            // STEP 3: DONE
            $this->notify(
                'indexing_progress',
                100,
                'Sitemap généré avec succès',
                true
            );

            return;

        } catch (\Throwable $e) {

            $this->notify(
                'indexing_error',
                0,
                'Erreur critique : ' . $e->getMessage(),
                true
            );

            throw $e;
        }
    }
    private function moveFileFromContent(string $content, string $extension = 'xml'): string
    {
        $filename = (string) Str::uuid() . '.' . $extension;
        $relativePath = 'assets/resources/sitemaps/' . $filename;
        $absolutePath = public_path($relativePath);

        if (!is_dir(dirname($absolutePath))) {
            mkdir(dirname($absolutePath), 0755, true);
        }

        file_put_contents($absolutePath, $content);

        return $relativePath;
    }
    private function deleteImage(string $path): void
    {
        $fullPath = public_path($path);
        if (file_exists($fullPath)) {
            unlink($fullPath);
        }
    }
    private function saveSitemapDocument(array $urls, Site $site): Document
    {
        $xml = new \XMLWriter();
        $xml->openMemory();
        $xml->startDocument('1.0', 'UTF-8');
        $xml->setIndent(true);

        $xml->startElement('urlset');
        $xml->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');

        foreach ($urls as $url) {
            $xml->startElement('url');
            $xml->writeElement('loc', $url);
            $xml->writeElement('lastmod', Carbon::now()->toAtomString());
            $xml->writeElement('changefreq', 'weekly');
            $xml->writeElement('priority', '0.8');
            $xml->endElement();
        }

        $xml->endElement();
        $xml->endDocument();

        $documentPath = $this->moveFileFromContent($xml->outputMemory(), 'xml');

        // Supprimer anciens sitemaps
        $site->documents()->where('type', 'sitemap')->get()->each(function (Document $doc) {
            $this->deleteImage($doc->path);
            $doc->delete();
        });

        $document = new Document([
            'id' => (string) Str::uuid(),
            'path' => $documentPath,
            'type' => 'sitemap',
        ]);

        return $site->documents()->save($document);
    }
    private function notify(string $type, int $progress = 0, string $message = '', bool $done = false): void
    {
        app(MercureService::class)->post(
            "site/{$this->siteId}/generate/sitemap",
            [
                'type' => $type,
                'progress' => $progress,
                'message' => $message,
                'done' => $done,
            ]
        );
    }
}
