<?php

namespace App\Services\crawl;

use DOMDocument;
use DOMElement;
use DOMNode;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Selects the useful page content from untrusted, inconsistently marked-up HTML.
 * Semantic elements are treated as signals, not as authoritative selectors.
 */
final class PageContentExtractor
{
    private const VERSION = '1.0';

    private const CONTENT_BLOCKS = 'p,li,blockquote,pre,dt,dd,td,th,figcaption';

    private const CHAT_MARKERS = '/(?:^|[-_\s])(?:intercom|crisp|tawk|live[_-]?chat|zendesk|drift|freshchat|chatbot|chat[_-]?widget|whatsapp[_-]?widget|messenger[_-]?widget)(?:$|[-_\s])/i';

    private const CHAT_LINKS = '/(?:api\.whatsapp\.com\/send|wa\.me\/|m\.me\/|messenger\.com\/|t\.me\/)/i';

    private const POPUP_MARKERS = '/(?:^|[-_\s])(?:popup|modal|floating|widget)(?:$|[-_\s])/i';

    /**
     * @return array{content: ?Crawler, diagnostics: array<string, mixed>}
     */
    public function extract(Crawler $document, ?string $pageTitle = null): array
    {
        $working = $this->cloneDocument($document);

        if (!$working) {
            return [
                'content' => null,
                'diagnostics' => [
                    'version' => self::VERSION,
                    'strategy' => 'unavailable',
                    'confidence' => 'low',
                    'warnings' => ['source_dom_unavailable'],
                ],
            ];
        }

        $this->removeBoilerplate($working);
        $candidates = $this->collectCandidates($working, $pageTitle);

        if ($candidates === []) {
            return [
                'content' => null,
                'diagnostics' => [
                    'version' => self::VERSION,
                    'strategy' => 'no_candidate',
                    'confidence' => 'low',
                    'candidate_count' => 0,
                    'warnings' => ['no_readable_content_candidate'],
                ],
            ];
        }

        usort($candidates, static fn (array $left, array $right): int => $right['score'] <=> $left['score']);
        $best = $candidates[0];
        $confidence = $this->confidence($best['metrics'], $best['score']);
        $warnings = [];

        if ($best['selector'] === 'body') {
            $warnings[] = 'cleaned_body_fallback';
        }

        if ($confidence === 'low') {
            $warnings[] = 'low_extraction_confidence';
        }

        if ($best['metrics']['link_density'] >= 0.5) {
            $warnings[] = 'high_link_density';
        }

        return [
            'content' => new Crawler($best['node']),
            'diagnostics' => [
                'version' => self::VERSION,
                'strategy' => $best['selector'] === 'body' ? 'clean_body_fallback' : 'scored_dom_candidate',
                'candidate' => $best['selector'],
                'score' => round($best['score'], 2),
                'confidence' => $confidence,
                'text_chars' => $best['metrics']['text_chars'],
                'content_blocks' => $best['metrics']['content_blocks'],
                'heading_count' => $best['metrics']['heading_count'],
                'link_density' => round($best['metrics']['link_density'], 3),
                'candidate_count' => count($candidates),
                'warnings' => $warnings,
            ],
        ];
    }

    private function cloneDocument(Crawler $source): ?Crawler
    {
        $node = $source->getNode(0);

        if (!$node) {
            return null;
        }

        $document = $node instanceof DOMDocument ? $node : $node->ownerDocument;
        if (!$document instanceof DOMDocument) {
            return null;
        }

        $html = $document->saveHTML();

        return is_string($html) && $html !== '' ? new Crawler($html) : null;
    }

    private function removeBoilerplate(Crawler $document): void
    {
        $remove = [];
        $document->filter('script,style,noscript,template,nav,footer,aside,iframe,svg,button,form,[role="navigation"],[role="search"],[role="dialog"],[aria-modal="true"]')
            ->each(function (Crawler $node) use (&$remove): void {
                $domNode = $node->getNode(0);
                if ($domNode instanceof DOMElement && !in_array(strtolower($domNode->tagName), ['html', 'body'], true)) {
                    $remove[] = $domNode;
                }
            });

        $document->filter('body *')->each(function (Crawler $node) use (&$remove): void {
            $element = $node->getNode(0);
            if (!$element instanceof DOMElement) {
                return;
            }

            $identity = strtolower(implode(' ', [
                $element->getAttribute('id'),
                $element->getAttribute('class'),
                $element->getAttribute('aria-label'),
            ]));

            $isCookieUi = preg_match('/(?:cookie[-_\s]?(?:banner|consent|notice|preferences?|settings?|modal)|consent[-_\s]?(?:banner|modal)|onetrust|cookiebot|didomi|quantcast)/i', $identity) === 1;
            $isChatWidget = preg_match(self::CHAT_MARKERS, $identity) === 1;
            $isCommonChrome = preg_match('/(?:^|[-_\s])(?:sidebar|related|share|social-share)(?:$|[-_\s])/i', $identity) === 1;

            if ($isCookieUi || $isChatWidget || $isCommonChrome) {
                $remove[] = $element;
            }
        });

        // Some chat tools use generic popup classes and identify themselves only
        // through their outbound messaging link. Remove that popup ancestor, not
        // the surrounding page content or an ordinary WhatsApp contact link.
        $document->filter('a[href]')->each(function (Crawler $link) use (&$remove): void {
            $element = $link->getNode(0);
            if (!$element instanceof DOMElement || !preg_match(self::CHAT_LINKS, $element->getAttribute('href'))) {
                return;
            }

            $ancestor = $element->parentNode;
            $depth = 0;
            while ($ancestor instanceof DOMElement && $depth++ < 7) {
                $identity = strtolower(implode(' ', [
                    $ancestor->getAttribute('id'),
                    $ancestor->getAttribute('class'),
                    $ancestor->getAttribute('aria-label'),
                ]));

                if (preg_match(self::POPUP_MARKERS, $identity) || preg_match(self::CHAT_MARKERS, $identity)) {
                    $remove[] = $ancestor;
                    break;
                }

                if (strtolower($ancestor->tagName) === 'body') {
                    break;
                }

                $ancestor = $ancestor->parentNode;
            }
        });

        // Preserve an article's own header; remove page chrome headers when they
        // contain navigation or are clearly identified as the site's masthead.
        $document->filter('header')->each(function (Crawler $header) use (&$remove): void {
            $element = $header->getNode(0);
            if (!$element instanceof DOMElement || $this->hasAncestorTag($element, 'article')) {
                return;
            }

            $identity = strtolower($element->getAttribute('id').' '.$element->getAttribute('class'));
            $hasNavigation = (new Crawler($element))->filter('nav')->count() > 0;
            $linkDensity = $this->linkDensity($element);

            if ($hasNavigation || $linkDensity >= 0.25 || preg_match('/(?:site[-_ ]?)?(?:header|masthead)/i', $identity)) {
                $remove[] = $element;
            }
        });

        $unique = [];
        foreach ($remove as $element) {
            $unique[spl_object_id($element)] = $element;
        }

        $remove = array_values($unique);
        usort($remove, fn (DOMNode $left, DOMNode $right): int => $this->depth($right) <=> $this->depth($left));

        foreach ($remove as $element) {
            $element->parentNode?->removeChild($element);
        }
    }

    private function collectCandidates(Crawler $document, ?string $pageTitle): array
    {
        $candidates = [];
        $seen = [];
        $maxDivCandidates = max(50, (int) config('crawl.content_extraction.max_div_candidates', 300));

        $consider = function (Crawler $node, bool $bodyFallback = false) use (&$candidates, &$seen, $pageTitle): void {
            $element = $node->getNode(0);
            if (!$element instanceof DOMElement) {
                return;
            }

            $id = spl_object_id($element);
            if (isset($seen[$id])) {
                return;
            }
            $seen[$id] = true;

            $metrics = $this->measure($node, $pageTitle);
            if ($metrics['text_chars'] < 40 || ($metrics['content_blocks'] === 0 && !$bodyFallback)) {
                return;
            }

            $selector = $this->describe($element);
            $candidates[] = [
                'node' => $element,
                'selector' => $bodyFallback ? 'body' : $selector,
                'metrics' => $metrics,
                'score' => $this->score($element, $metrics, $bodyFallback),
            ];
        };

        foreach (['main', '[role="main"]', '[itemprop="articleBody"]', 'article', 'section'] as $selector) {
            $document->filter($selector)->each($consider);
        }

        $visitedDivs = 0;
        $document->filter('div')->each(function (Crawler $node) use (&$visitedDivs, $maxDivCandidates, $consider): void {
            if ($visitedDivs++ >= $maxDivCandidates) {
                return;
            }

            $element = $node->getNode(0);
            if (!$element instanceof DOMElement) {
                return;
            }

            $identity = strtolower($element->getAttribute('id').' '.$element->getAttribute('class'));
            $hasContentMarker = preg_match('/(?:^|[-_\s])(?:content|article|entry|post|product|description|detail|faq|main|body)(?:$|[-_\s])/i', $identity) === 1;
            $hasTextBlocks = (new Crawler($element))->filter(self::CONTENT_BLOCKS)->count() > 0;

            if ($hasContentMarker || $hasTextBlocks) {
                $consider($node);
            }
        });

        $document->filter('body')->each(fn (Crawler $body) => $consider($body, true));

        return $candidates;
    }

    private function measure(Crawler $candidate, ?string $pageTitle): array
    {
        $element = $candidate->getNode(0);
        $text = $element instanceof DOMElement ? $this->normalize($element->textContent) : '';
        $textChars = mb_strlen($text);

        $blockTexts = [];
        $candidate->filter(self::CONTENT_BLOCKS)->each(function (Crawler $block) use (&$blockTexts): void {
            $value = $this->normalize($block->text());
            if ($value !== '') {
                $blockTexts[hash('sha256', mb_strtolower($value))] = $value;
            }
        });

        $headings = 0;
        $candidate->filter('h1,h2,h3')->each(function (Crawler $heading) use (&$headings): void {
            if ($this->normalize($heading->text()) !== '') {
                $headings++;
            }
        });

        $linkedChars = 0;
        $candidate->filter('a')->each(function (Crawler $link) use (&$linkedChars): void {
            $linkedChars += mb_strlen($this->normalize($link->text()));
        });

        return [
            'text_chars' => $textChars,
            'content_blocks' => count($blockTexts),
            'heading_count' => $headings,
            'link_density' => $textChars > 0 ? min(1, $linkedChars / $textChars) : 1,
            'title_match' => $this->titleMatches($pageTitle, $text),
        ];
    }

    private function score(DOMElement $element, array $metrics, bool $bodyFallback): float
    {
        $tag = strtolower($element->tagName);
        $identity = strtolower($element->getAttribute('id').' '.$element->getAttribute('class'));
        $score = log(1 + $metrics['text_chars']) * 10;
        $score += min(10, $metrics['content_blocks']) * 5;
        $score += min(6, $metrics['heading_count']) * 3;
        $score -= $metrics['link_density'] * 50;

        $score += match ($tag) {
            'main' => 10,
            'article' => 8,
            'section' => 3,
            default => 0,
        };

        if (strtolower($element->getAttribute('role')) === 'main') {
            $score += 12;
        }

        if (preg_match('/(?:^|[-_\s])(?:content|article|entry|post|product|description|detail|faq|main|body)(?:$|[-_\s])/i', $identity)) {
            $score += 8;
        }

        if (in_array(strtolower($element->getAttribute('itemprop')), ['articlebody', 'mainentity', 'description'], true)) {
            $score += 12;
        }

        if ($metrics['title_match']) {
            $score += 6;
        }

        if (preg_match('/(?:^|[-_\s])(?:banner|promo|advertisement|breadcrumb|pagination|newsletter|subscribe)(?:$|[-_\s])/i', $identity)) {
            $score -= 18;
        }

        if ($bodyFallback) {
            $score -= 10;
        }

        return $score;
    }

    private function confidence(array $metrics, float $score): string
    {
        $highChars = max(250, (int) config('crawl.content_extraction.high_confidence_chars', 500));
        $mediumChars = max(80, (int) config('crawl.content_extraction.medium_confidence_chars', 120));

        if ($metrics['text_chars'] >= $highChars && $metrics['content_blocks'] >= 3 && $metrics['link_density'] <= 0.4 && $score >= 45) {
            return 'high';
        }

        if ($metrics['text_chars'] >= $mediumChars && $metrics['content_blocks'] >= 1 && $metrics['link_density'] <= 0.65 && $score >= 20) {
            return 'medium';
        }

        return 'low';
    }

    private function titleMatches(?string $title, string $text): bool
    {
        $titleTokens = $this->tokens((string) $title);
        if ($titleTokens === []) {
            return false;
        }

        $textTokens = array_fill_keys($this->tokens($text), true);
        $matches = count(array_intersect_key(array_fill_keys($titleTokens, true), $textTokens));

        return $matches >= min(2, count($titleTokens));
    }

    private function tokens(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}]{3,}/u', mb_strtolower($text), $matches);

        return array_values(array_unique($matches[0] ?? []));
    }

    private function linkDensity(DOMElement $element): float
    {
        $crawler = new Crawler($element);
        $textLength = mb_strlen($this->normalize($element->textContent));
        $linkedLength = 0;

        $crawler->filter('a')->each(function (Crawler $link) use (&$linkedLength): void {
            $linkedLength += mb_strlen($this->normalize($link->text()));
        });

        return $textLength > 0 ? min(1, $linkedLength / $textLength) : 1;
    }

    private function hasAncestorTag(DOMElement $element, string $tag): bool
    {
        $ancestor = $element->parentNode;
        while ($ancestor instanceof DOMElement) {
            if (strtolower($ancestor->tagName) === $tag) {
                return true;
            }
            $ancestor = $ancestor->parentNode;
        }

        return false;
    }

    private function depth(DOMNode $node): int
    {
        $depth = 0;
        while ($node->parentNode) {
            $depth++;
            $node = $node->parentNode;
        }

        return $depth;
    }

    private function describe(DOMElement $element): string
    {
        $id = trim($element->getAttribute('id'));
        if ($id !== '') {
            return '#'.mb_substr($id, 0, 80);
        }

        $classes = preg_split('/\s+/', trim($element->getAttribute('class'))) ?: [];
        $classes = array_values(array_filter($classes));
        if ($classes !== []) {
            return strtolower($element->tagName).'.'.implode('.', array_slice($classes, 0, 3));
        }

        return strtolower($element->tagName);
    }

    private function normalize(string $text): string
    {
        return trim(preg_replace('/[\p{Z}\s]+/u', ' ', $text) ?? '');
    }
}
