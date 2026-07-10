<?php

namespace App\Services\ia;

class ContextBuilder
{
    public function build(array $rankedChunks): string
    {
        if (empty($rankedChunks)) {
            return '';
        }

        return collect($rankedChunks)
            ->pluck('text')
            ->filter()
            ->implode("\n\n---\n\n");
    }
}
