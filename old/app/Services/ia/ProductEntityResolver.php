<?php

namespace App\Services\ia;

use App\Models\Chunk;
use Illuminate\Support\Collection;

class ProductEntityResolver
{
    public function resolve(Collection $rankedChunks): array
    {
        $resolved = collect();
        $processedProducts = [];

        foreach ($rankedChunks as $chunk) {

            //$metadata = $chunk->metadata ?? [];
            $metadata = $chunk['metadata'] ?? [];
            //dd($chunk, $metadata);

            // Si pas un produit WooCommerce → on garde tel quel
            if (
                //$chunk->source_type !== 'woocommerce' ||
                $chunk['source_type'] !== 'woocommerce' ||
                !isset($metadata['product_index'])
            ) {
                $resolved->push($chunk);
                continue;
            }

            $productIndex = $metadata['product_index'];
            $originalChunk = Chunk::find($chunk['id']);

            if (!$originalChunk) {
                $resolved->push($chunk);
                continue;
            }

            $documentId = $originalChunk->document_id;

            // Si déjà traité → skip
            if (in_array($productIndex, $processedProducts)) {
                continue;
            }

            $processedProducts[] = $productIndex;

            // 🔥 On récupère TOUS les chunks du produit
            $allProductChunks = Chunk::where('document_id', $documentId)
                ->where('source_type', 'woocommerce')
                ->where('metadata->product_index', $productIndex)
                ->get();

            // On cherche le chunk global
            $globalChunk = $allProductChunks->first(function ($c) {
                return ($c->metadata['type'] ?? null) === 'global';
            });

            if ($globalChunk) {
                //$resolved->push($globalChunk);
                $resolved->push([
                    'id' => $globalChunk->id,
                    'text' => $globalChunk->text,
                    'source_type' => $globalChunk->source_type,
                    'metadata' => $globalChunk->metadata,
                    'priority' => $globalChunk->priority,
                    'vector_score' => $chunk['vector_score'] ?? null,
                    'final_score' => $chunk['final_score'] ?? null,
                ]);
            } else {
                // fallback : on reconstruit à partir des morceaux
                $combinedText = $allProductChunks->pluck('text')->implode('. ');
                $chunk->text = $combinedText;
                $resolved->push($chunk);
            }
        }

        return $resolved->toArray();
    }
}
