<?php

namespace App\Services\ia;



use Illuminate\Support\Facades\Log;

class EntityExtractor
{
    protected array $transformers;

    public function __construct()
    {
        $this->transformers = collect(config('entities.transformers'))
            ->map(fn($class) => app($class))
            ->toArray();
    }

    public function extract(array $chunks): array
    {
        $entities = [];

        foreach ($chunks as $chunk) {

            foreach ($this->transformers as $transformer) {

                if (!$transformer->supports($chunk)) {
                    continue;
                }

                $entity = $transformer->transform($chunk);

                if ($entity) {
                    $entities[] = $entity;
                }

                break;
            }
        }

        return collect($entities)
            // 🖼️ FIX : la dédup doit tenir compte du TYPE. Avant ce correctif,
            // une entité 'image' et une entité 'page' partageant la même 'url'
            // (le cas typique : le texte d'une page + une image de cette même
            // page, retrouvés tous les deux comme contexte pertinent) se
            // faisaient dédupliquer l'une l'autre, et l'image disparaissait
            // silencieusement. On préfère `image_url` quand disponible (2
            // images différentes de la même page doivent rester distinctes),
            // et on préfixe systématiquement par le type pour ne jamais faire
            // collisionner 2 entités de nature différente.
            ->unique(function ($e) {
                $key = $e['image_url'] ?? $e['url'] ?? $e['title'] ?? null;
                return ($e['type'] ?? 'unknown') . '|' . $key;
            })
            ->values()
            ->take(4)
            ->toArray();
    }
}
