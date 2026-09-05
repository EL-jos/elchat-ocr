<?php

namespace App\Domain\Sales\Sources;

class OpenStreetMapQueryBuilder
{
    private const TAGS = [
        "architecte d'interieur" => ['shop', 'interior_decoration'],
        'architecte dinterieur' => ['shop', 'interior_decoration'],
        'decoration' => ['shop', 'interior_decoration'],
        'architecte' => ['office', 'architect'],
        'immobil' => ['office', 'estate_agent'],
        'promoteur' => ['office', 'estate_agent'],
        'cuisiniste' => ['shop', 'kitchen'],
        'cuisine' => ['shop', 'kitchen'],
        'meuble' => ['shop', 'furniture'],
        'magasin de meubles' => ['shop', 'furniture'],
        'menuisier' => ['craft', 'carpenter'],
        'renovation' => ['craft', 'builder'],
        'construction' => ['craft', 'builder'],
        'restaurant' => ['amenity', 'restaurant'],
        'cafe' => ['amenity', 'cafe'],
        'hotel' => ['tourism', 'hotel'],
        'pharmacy' => ['amenity', 'pharmacy'],
        'dentist' => ['amenity', 'dentist'],
        'doctor' => ['amenity', 'doctors'],
        'real estate' => ['office', 'estate_agent'],
        'immobilier' => ['office', 'estate_agent'],
        'immobili' => ['office', 'estate_agent'],
        'architect' => ['office', 'architect'],
        'architecture' => ['office', 'architect'],
        'lawyer' => ['office', 'lawyer'],
        'avocat' => ['office', 'lawyer'],
        'furniture' => ['shop', 'furniture'],
        'clothing' => ['shop', 'clothes'],
        'mode' => ['shop', 'clothes'],
        'car dealer' => ['shop', 'car'],
        'school' => ['amenity', 'school'],
        'coworking' => ['office', 'coworking'],
    ];

    public function build(array $icp, int $limit): ?string
    {
        $location = trim((string) ($icp['location'] ?? ''));
        if ($location === '') {
            return null;
        }

        $clauses = [];
        foreach ($this->terms($icp) as $term) {
            $tags = $this->tagsFor($term);
            if ($tags) {
                foreach ($tags as [$key, $value]) {
                    $clauses[] = sprintf('node["%s"="%s"];way["%s"="%s"];', $key, $value, $key, $value);
                }
            }
        }

        if (! $clauses) {
            return null;
        }

        $limit = max(1, min(500, $limit));

        $queryTimeout = max(5, min(120, (int) ($icp['_overpass_query_timeout'] ?? 20)));

        $bbox = $icp['_osm_bbox'] ?? null;
        if (is_array($bbox)
            && is_numeric($bbox['south'] ?? null)
            && is_numeric($bbox['west'] ?? null)
            && is_numeric($bbox['north'] ?? null)
            && is_numeric($bbox['east'] ?? null)) {
            return sprintf(
                "[out:json][timeout:%d][bbox:%s,%s,%s,%s];\n(\n    %s\n);\nout center;",
                $queryTimeout,
                $bbox['south'],
                $bbox['west'],
                $bbox['north'],
                $bbox['east'],
                implode("\n    ", array_unique($clauses)),
            );
        }

        $location = $this->escapeRegex($location);
        $areaClauses = [];
        foreach ($this->terms($icp) as $term) {
            $tags = $this->tagsFor($term);
            if ($tags) {
                foreach ($tags as [$key, $value]) {
                    $areaClauses[] = sprintf('nwr["%s"="%s"](area.searchArea);', $key, $value);
                }
            }
        }

        if (! $areaClauses) {
            return null;
        }

        return "[out:json][timeout:{$queryTimeout}];\n"
            ."area[\"name\"~\"{$location}\",i][\"boundary\"=\"administrative\"]->.searchArea;\n"
            ."(\n    ".implode("\n    ", array_unique($areaClauses))."\n);\n"
            ."out center tags {$limit};";
    }

    /** @return array<int, string> */
    private function terms(array $icp): array
    {
        $terms = [];
        foreach ([$icp['sector'] ?? null, $icp['company_type'] ?? null] as $value) {
            if (! $value) {
                continue;
            }
            foreach (preg_split('/[,;|]+/', mb_strtolower((string) $value)) ?: [] as $term) {
                $term = trim($term);
                if ($term !== '') {
                    $terms[] = $term;
                }
            }
        }

        return array_values(array_unique($terms));
    }

    private function tagFor(string $term): ?array
    {
        return $this->tagsFor($term)[0] ?? null;
    }

    /** @return array<int, array{0: string, 1: string}> */
    private function tagsFor(string $term): array
    {
        $term = $this->normalize($term);
        $matches = [];
        foreach (self::TAGS as $needle => $tag) {
            if (str_contains($term, $this->normalize($needle))) {
                $matches[implode('=', $tag)] = $tag;
            }
        }

        return array_values($matches);
    }

    private function escapeRegex(string $value): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], preg_quote($value, '/'));
    }

    private function normalize(string $value): string
    {
        return mb_strtolower(
            preg_replace('/[\\x{0300}-\\x{036f}]/u', '', normalizer_normalize($value, \Normalizer::FORM_D) ?: $value),
        );
    }
}
