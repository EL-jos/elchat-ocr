<?php

namespace App\Services\transformers;

use App\Interfaces\EntityTransformer;

class DocumentEntityTransformer implements EntityTransformer
{

    public function supports(array $chunk): bool
    {
        return ($chunk['source_type'] ?? null) === "document";
    }

    public function transform(array $chunk): ?array
    {
        $metadata = $chunk['metadata'] ?? [];

        return [
            'id' => $chunk['id'],
            'type' => 'document',
            'title' => $metadata['document_name'] ?? null,
            'url' => '/document/' . ($metadata['document_id'] ?? ''),
            'description' => $chunk['text'] ?? null,
        ];
    }
}
