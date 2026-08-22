<?php

namespace Tests\Unit;

use App\Services\DocumentChunkingService;
use PHPUnit\Framework\TestCase;

class DocumentTitleIndexingTest extends TestCase
{
    public function test_the_resource_title_is_included_in_document_chunks(): void
    {
        $chunks = (new DocumentChunkingService())->chunk([
            'id' => 'doc-1',
            'format' => 'txt',
            'title' => 'Guide commercial 2026',
            'blocks' => [[
                'type' => 'block',
                'subtype' => 'paragraph',
                'text' => 'Les conditions commerciales applicables aux clients.',
                'meta' => [],
            ]],
        ]);

        $this->assertNotEmpty($chunks);
        $this->assertStringContainsString('Guide commercial 2026', $chunks[0]['text']);
        $this->assertStringContainsString('Guide commercial 2026', $chunks[1]['text']);
        $this->assertSame('Guide commercial 2026', $chunks[1]['metadata']['title']);
    }
}
