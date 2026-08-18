<?php

namespace App\Services\transformers;

use App\Interfaces\EntityTransformer;

class ImageEntityTransformer implements EntityTransformer
{
    public function supports(array $chunk): bool
    {
        return ($chunk['source_type'] ?? null) === 'image';
    }

    public function transform(array $chunk): ?array
    {
        $metadata = $chunk['metadata'] ?? [];

        if (is_string($metadata)) {
            $metadata = json_decode($metadata, true) ?? [];
        }

        if (!is_array($metadata)) {
            $metadata = [];
        }

        $imageUrl = $metadata['image_url'] ?? null;

        // Sans image_url il n'y a rien d'affichable côté client : on ne pousse
        // pas d'entrée "source" vide (défense en profondeur, ne devrait pas
        // arriver puisque IndexService ne crée pas de chunk image sans image_url).
        if (!$imageUrl) {
            return null;
        }

        return [
            'id'          => $chunk['id'],
            'type'        => 'image',
            'title'       => $metadata['title'] ?? null,
            // 'url' = lien vers la SOURCE (page web crawlée ou fiche document),
            // à ne pas confondre avec 'image_url' = l'image elle-même à afficher.
            'url'         => $metadata['url'] ?? null,
            'image_url'   => $imageUrl,
            'description' => $chunk['text'] ?? null,
        ];
    }
}
