<?php

namespace App\Console\Commands;

use App\Support\SiteLocale;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use XMLWriter;

class GeneratePublicSitemapsCommand extends Command
{
    protected $signature = 'seo:generate-sitemaps
                            {--base-url= : URL publique du site, par défaut APP_URL}';

    protected $description = 'Génère le sitemap index et un sitemap XML pour chaque langue du site public.';

    private const SITE_PAGES = [
        ['key' => 'home', 'changefreq' => 'weekly', 'priority' => '1.0'],
        ['key' => 'about', 'changefreq' => 'monthly', 'priority' => '0.8'],
        ['key' => 'services', 'changefreq' => 'weekly', 'priority' => '0.9'],
        ['key' => 'pricing', 'changefreq' => 'weekly', 'priority' => '0.9'],
        ['key' => 'faqs', 'changefreq' => 'monthly', 'priority' => '0.8'],
        ['key' => 'contact', 'changefreq' => 'monthly', 'priority' => '0.8'],
        ['key' => 'privacy', 'changefreq' => 'yearly', 'priority' => '0.4'],
        ['key' => 'terms', 'changefreq' => 'yearly', 'priority' => '0.4'],
        ['key' => 'legal', 'changefreq' => 'yearly', 'priority' => '0.4'],
        ['key' => 'service', 'slug' => 'knowledge-rag', 'changefreq' => 'monthly', 'priority' => '0.8'],
        ['key' => 'service', 'slug' => 'workflows-connecteurs', 'changefreq' => 'monthly', 'priority' => '0.8'],
        ['key' => 'service', 'slug' => 'intelligence-business', 'changefreq' => 'monthly', 'priority' => '0.8'],
        ['key' => 'service', 'slug' => 'engagement-proactif', 'changefreq' => 'monthly', 'priority' => '0.8'],
        ['key' => 'service', 'slug' => 'visitor-intelligence', 'changefreq' => 'monthly', 'priority' => '0.8'],
        ['key' => 'service', 'slug' => 'agents-ia', 'changefreq' => 'monthly', 'priority' => '0.8'],
        ['key' => 'service', 'slug' => 'ai-sales-hunter', 'changefreq' => 'monthly', 'priority' => '0.8'],
    ];

    /** @var array<int, string> */
    private const SITEMAP_LOCALES = ['fr', 'en', 'pt', 'es'];

    /** @var array<int, string> */
    private const REMOVED_SITEMAP_LOCALES = ['de'];

    public function handle(): int
    {
        $baseUrl = rtrim(trim((string) ($this->option('base-url') ?: config('app.url'))), '/');

        if ($baseUrl === '') {
            $this->error('Impossible de générer les sitemaps : configurez APP_URL ou utilisez --base-url.');

            return self::FAILURE;
        }

        $lastModified = now()->toDateString();
        $documentationPaths = $this->documentationPathsByLocale();
        $generatedFiles = [];

        if (count(array_filter($documentationPaths)) === 0) {
            $this->warn('Aucune page du centre d’aide compilée détectée dans public/documentation.');
        }

        // Remove sitemap files for locales no longer published by this command.
        foreach (self::REMOVED_SITEMAP_LOCALES as $locale) {
            $stalePath = public_path("sitemap-{$locale}.xml");

            if (File::exists($stalePath)) {
                File::delete($stalePath);
            }
        }

        foreach (self::SITEMAP_LOCALES as $locale) {
            $urls = $this->siteUrls($baseUrl, $locale, $lastModified);

            foreach ($documentationPaths[$locale] ?? [] as $documentationPath) {
                $urls[] = [
                    'url' => $baseUrl . $this->documentationUrlPath($locale, $documentationPath),
                    'lastmod' => $lastModified,
                    'changefreq' => 'monthly',
                    'priority' => '0.6',
                    'alternates' => $this->documentationAlternates(
                        $baseUrl,
                        $documentationPath,
                        $documentationPaths,
                    ),
                ];
            }

            $filename = "sitemap-{$locale}.xml";
            $outputPath = public_path($filename);

            if (File::put($outputPath, $this->renderUrlSet($urls)) === false) {
                $this->error("Impossible d'écrire {$outputPath}.");

                return self::FAILURE;
            }

            $generatedFiles[] = [
                'url' => $baseUrl . '/' . $filename,
                'lastmod' => $lastModified,
            ];

            $this->info(sprintf(
                '%s : %d URL(s) → %s',
                strtoupper($locale),
                count($urls),
                $outputPath,
            ));
        }

        $indexPath = public_path('sitemap.xml');

        if (File::put($indexPath, $this->renderSitemapIndex($generatedFiles)) === false) {
            $this->error("Impossible d'écrire {$indexPath}.");

            return self::FAILURE;
        }

        $this->info("Index sitemap généré : {$indexPath}");

        return self::SUCCESS;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function siteUrls(string $baseUrl, string $locale, string $lastModified): array
    {
        return array_map(function (array $page) use ($baseUrl, $locale, $lastModified): array {
            $alternates = [];

            foreach ($this->sitemapLanguages() as $alternateLocale => $language) {
                $alternates[] = [
                    'hreflang' => $language['hreflang'],
                    'url' => $baseUrl . $this->siteUrlPath($page, $alternateLocale),
                ];
            }

            return [
                'url' => $baseUrl . $this->siteUrlPath($page, $locale),
                'lastmod' => $lastModified,
                'changefreq' => $page['changefreq'],
                'priority' => $page['priority'],
                'alternates' => $alternates,
            ];
        }, self::SITE_PAGES);
    }

    /**
     * @param array<string, mixed> $page
     */
    private function siteUrlPath(array $page, string $locale): string
    {
        $localePrefix = $locale === SiteLocale::DEFAULT ? '' : "/{$locale}";

        return match ($page['key']) {
            'home' => $locale === SiteLocale::DEFAULT ? '/accueil' : "{$localePrefix}/home",
            'about' => $locale === SiteLocale::DEFAULT ? '/a-propos' : "{$localePrefix}/about",
            'services' => "{$localePrefix}/services",
            'pricing' => $locale === SiteLocale::DEFAULT ? '/tarifs' : "{$localePrefix}/pricing",
            'faqs' => "{$localePrefix}/faqs",
            'contact' => "{$localePrefix}/contact",
            'privacy' => $locale === SiteLocale::DEFAULT
                ? '/politique-de-confidentialite'
                : "{$localePrefix}/privacy-policy",
            'terms' => $locale === SiteLocale::DEFAULT
                ? '/conditions-generales-d-utilisation'
                : "{$localePrefix}/terms-of-use",
            'legal' => $locale === SiteLocale::DEFAULT
                ? '/mentions-legales'
                : "{$localePrefix}/legal-notice",
            'service' => "{$localePrefix}/service/{$page['slug']}",
            default => throw new \InvalidArgumentException("Page sitemap inconnue : {$page['key']}"),
        };
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function documentationPathsByLocale(): array
    {
        $paths = [];

        foreach (self::SITEMAP_LOCALES as $locale) {
            $paths[$locale] = $this->documentationPaths($locale);
        }

        return $paths;
    }

    /**
     * @return array<int, string>
     */
    private function documentationPaths(string $locale): array
    {
        $documentationRoot = public_path('documentation');
        $localeRoot = $locale === SiteLocale::DEFAULT
            ? $documentationRoot
            : $documentationRoot . DIRECTORY_SEPARATOR . $locale;

        if (! is_dir($localeRoot)) {
            return [];
        }

        $paths = [];
        $localizedDirectories = array_values(array_diff(self::SITEMAP_LOCALES, [SiteLocale::DEFAULT]));

        foreach (File::allFiles($localeRoot) as $file) {
            $relativePath = str_replace('\\', '/', $file->getRelativePathname());

            if (! str_ends_with($relativePath, 'index.html')) {
                continue;
            }

            $firstDirectory = explode('/', $relativePath)[0];

            if (in_array($firstDirectory, ['_astro', 'pagefind', '404'] , true)) {
                continue;
            }

            // Le dossier racine contient aussi les sorties des autres langues.
            // Elles ne doivent pas être comptées dans le sitemap français.
            if ($locale === SiteLocale::DEFAULT && in_array($firstDirectory, $localizedDirectories, true)) {
                continue;
            }

            $path = $relativePath === 'index.html'
                ? ''
                : trim(substr($relativePath, 0, -strlen('/index.html')), '/');

            if ($path === '404' || str_starts_with($path, '404/')) {
                continue;
            }

            $paths[] = $path;
        }

        $paths = array_values(array_unique($paths));
        sort($paths);

        return $paths;
    }

    private function documentationUrlPath(string $locale, string $documentationPath): string
    {
        $localePrefix = $locale === SiteLocale::DEFAULT ? '' : "{$locale}/";
        $documentationPath = trim($documentationPath, '/');

        return '/centre-d-aide/' . $localePrefix . ($documentationPath === '' ? '' : "{$documentationPath}/");
    }

    /**
     * @param array<string, array<int, string>> $documentationPaths
     * @return array<int, array<string, string>>
     */
    private function documentationAlternates(
        string $baseUrl,
        string $documentationPath,
        array $documentationPaths,
    ): array {
        $alternates = [];

        foreach ($this->sitemapLanguages() as $locale => $language) {
            if (! in_array($documentationPath, $documentationPaths[$locale] ?? [], true)) {
                continue;
            }

            $alternates[] = [
                'hreflang' => $language['hreflang'],
                'url' => $baseUrl . $this->documentationUrlPath($locale, $documentationPath),
            ];
        }

        return $alternates;
    }

    /**
     * Return only the locales currently published by the sitemap command.
     * The public application may still keep additional server-side translations.
     *
     * @return array<string, array{label:string,native:string,flag:string,hreflang:string}>
     */
    private function sitemapLanguages(): array
    {
        return array_intersect_key(
            SiteLocale::languages(),
            array_flip(self::SITEMAP_LOCALES),
        );
    }

    /**
     * @param array<int, array<string, mixed>> $urls
     */
    private function renderUrlSet(array $urls): string
    {
        $xml = new XMLWriter();
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('urlset');
        $xml->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $xml->writeAttribute('xmlns:xhtml', 'http://www.w3.org/1999/xhtml');

        foreach ($urls as $url) {
            $xml->startElement('url');
            $xml->writeElement('loc', $url['url']);
            $xml->writeElement('lastmod', $url['lastmod']);
            $xml->writeElement('changefreq', $url['changefreq']);
            $xml->writeElement('priority', $url['priority']);

            foreach ($url['alternates'] as $alternate) {
                $xml->startElementNs('xhtml', 'link', 'http://www.w3.org/1999/xhtml');
                $xml->writeAttribute('rel', 'alternate');
                $xml->writeAttribute('hreflang', $alternate['hreflang']);
                $xml->writeAttribute('href', $alternate['url']);
                $xml->endElement();
            }

            $xml->endElement();
        }

        $xml->endElement();
        $xml->endDocument();

        return $xml->outputMemory();
    }

    /**
     * @param array<int, array{url: string, lastmod: string}> $sitemaps
     */
    private function renderSitemapIndex(array $sitemaps): string
    {
        $xml = new XMLWriter();
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('sitemapindex');
        $xml->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');

        foreach ($sitemaps as $sitemap) {
            $xml->startElement('sitemap');
            $xml->writeElement('loc', $sitemap['url']);
            $xml->writeElement('lastmod', $sitemap['lastmod']);
            $xml->endElement();
        }

        $xml->endElement();
        $xml->endDocument();

        return $xml->outputMemory();
    }
}
