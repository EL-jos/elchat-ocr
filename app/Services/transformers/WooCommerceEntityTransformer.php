<?php

namespace App\Services\transformers;

use App\Interfaces\EntityTransformer;
use App\Models\Chunk;
use App\Models\Product;
use Illuminate\Support\Str;

class WooCommerceEntityTransformer implements EntityTransformer
{
    public function supports(array $chunk): bool
    {
        return ($chunk['source_type'] ?? null) === 'woocommerce';
    }

    public function transform(array $chunk): ?array
    {
        $chunk = Chunk::find($chunk['id']);
        if (!$chunk) return null;

        $product = Product::find($chunk->product_id);
        if (!$product) return null;

        return [
            'id' => $product->id,
            'type' => 'product',
            'title' => $product->product_name,
            'description' => Str::limit(($product->short_description ?? $product->description ?? "Unknown"), 100), // 🔥 IMPORTANT
            'url' => $product->product_url ?? null,
            'image' => $product->image_url ?? null,
            'price' => $product->price ?? null,
        ];
    }
}
