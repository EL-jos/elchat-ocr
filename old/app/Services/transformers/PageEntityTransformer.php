<?php

namespace App\Services\transformers;

use App\Interfaces\EntityTransformer;
use App\Models\Chunk;
use App\Models\Page;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PageEntityTransformer implements EntityTransformer
{
    public function supports(array $chunk): bool
    {
        return in_array($chunk['source_type'] ?? null, ['crawl', 'sitemap', 'import'/*, 'manual'*/]);
    }

    /*public function transform(array $chunk): ?array
    {
        $metadata = $chunk['metadata'] ?? [];
        preg_match('/URL:\s(.+)/', $chunk['text'], $match);
        preg_match('/Page:\s(.+)/', $chunk['text'], $matchPageTitle);

        if ($match[1] && !($match !== "" || $match[1] !== " ")){
            return [
                'id' => $chunk['id'],
                'type' => 'page',
                'title' => $chunk['title'] ?? $matchPageTitle[1] ?? 'Page',
                'url' => $match[1] ?? null,
                'description' => $chunk['text'] ?? null,
            ];
        }
        return [];
    }*/
    public function transform(array $chunk): ?array
    {
        $chunk = Chunk::find($chunk['id']);
        if (!$chunk) return null;

        $page = Page::find($chunk->page_id);
        if (!$page) return null;

        return [
            'id' => $page->id,
            'type' => 'page',
            'title' => $page->title,
            'url' => $page->url,
            'favicon' => $page->site->favicon,
            'description' => Str::limit($page->plain_text, 100),
        ];
    }
}
