<?php

namespace App\Services;

use App\Traits\TextNormalizer;

class DocumentChunkingService
{
    use TextNormalizer;
    public function chunk(array $document): array
    {
        $chunks = [];

        $chunks[] = $this->buildDocumentSummary($document);

        $blocks = $document['blocks'] ?? [];

        // 🔥 UNWRAP Excel/PDF/Doc wrappers
        if (isset($blocks['blocks']) && is_array($blocks['blocks'])) {
            $blocks = $blocks['blocks'];
        }

        $chunks = array_merge(
            $chunks,
            $this->processBlocks($blocks, $document)
        );

        return $chunks;
    }
    private function processBlocks(array $blocks, array $document): array
    {
        $chunks = [];

        foreach ($this->groupBlocksByStructure($blocks) as $section) {
            $chunks = array_merge(
                $chunks,
                $this->chunkSection($section, $document)
            );
        }

        return $chunks;
    }
    private function buildDocumentSummary(array $doc): array
    {
        return [
            'text' => "Document: {$doc['title']} ({$doc['format']})",
            'priority' => 10,
            'metadata' => [
                'level' => 'document_summary',
                'title' => $doc['title']
            ]
        ];
    }
    private function groupBlocksByStructure(array $blocks): array
    {
        $sections = [];
        $current = [];

        foreach ($blocks as $block) {

            if (!is_array($block)) {
                continue; // 🔥 skip strings/invalid data
            }

            // 🔥 TABLE = section atomique immédiate
            if (($block['subtype'] ?? null) === 'table') {

                if (!empty($current)) {
                    $sections[] = $current;
                    $current = [];
                }

                // 👉 transformation directe en chunk-like block
                $sections[] = [$this->transformTableBlock($block)];

                continue;
            }

            $current[] = $block;
        }

        if (!empty($current)) {
            $sections[] = $current;
        }

        return $sections;
    }
    private function transformTableBlock(array $block): array
    {
        return [
            'type' => 'block',
            'subtype' => 'table',
            'text' => $block['text'],
            'lexical_text' => $this->normalizeForLexicalSearch($block['text']),
            'meta' => array_merge($block['meta'] ?? [], [
                'level' => 'table',
                'is_atomic' => true
            ])
        ];
    }
    private function chunkSection(array $section, array $doc): array
    {
        $chunks = [];

        $buffer = '';
        $meta = [];

        foreach ($section as $block) {

            $text = $this->buildContextualText($block, $doc);

            $candidate = $buffer . "\n" . $text;

            if ($this->tooBig($candidate)) {

                $chunks[] = $this->buildChunk($buffer, $doc, $meta);

                $buffer = $text;
                $meta = $block['meta'] ?? [];

            } else {
                $buffer = $candidate;
            }
        }

        if (!empty($buffer)) {
            $chunks[] = $this->buildChunk($buffer, $doc, $meta);
        }

        return $chunks;
    }
    private function buildContextualText(array $block, array $doc): string
    {
        $prefix = [];

        $prefix[] = $doc['title'];

        if (!empty($block['meta']['section_title'])) {
            $prefix[] = $block['meta']['section_title'];
        }

        return implode(" | ", $prefix) . "\n" . $this->normalizeText($block['text']);
    }
    private function buildChunk(string $text, array $doc, array $meta): array
    {

        $normalizedText = $this->normalizeText($text);

        return [
            'text' => $normalizedText,
            'lexical_text' => $this->normalizeForLexicalSearch($normalizedText),
            'priority' => $this->computePriority($meta),
            'metadata' => [
                'document_id' => $doc['id'] ?? null,
                'title' => $doc['title'],
                'level' => 'chunk',
                'source_meta' => $meta,
                'length' => mb_strlen($text)
            ],
            'no_embedding' => false
        ];
    }
    private function tooBig(string $text): bool
    {
        $length = mb_strlen($text);

        return $length > 2500;
    }
    private function computePriority(array $meta): int
    {
        return match ($meta['level'] ?? 'chunk') {
            'document_summary' => 10,
            'table' => 100,
            'chunk' => 50,
            default => 60
        };
    }
}
