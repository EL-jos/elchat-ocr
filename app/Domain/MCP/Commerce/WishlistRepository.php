<?php

namespace App\Domain\MCP\Commerce;


use App\Models\Mcp\McpWishlist;

class WishlistRepository
{
    public function find(string $siteId, string $ownerType, string $ownerId): McpWishlist
    {
        return McpWishlist::firstOrCreate(
            ['site_id' => $siteId, 'owner_type' => $ownerType, 'owner_id' => $ownerId],
            ['items' => []]
        );
    }

    public function add(McpWishlist $wishlist, string $productId, ?string $variationId): McpWishlist
    {
        $items = $wishlist->items ?? [];
        $exists = collect($items)->contains(fn ($i) => (string) $i['product_id'] === (string) $productId && ($i['variation_id'] ?? null) == $variationId);

        if (!$exists) {
            $items[] = ['product_id' => $productId, 'variation_id' => $variationId];
            $wishlist->update(['items' => $items]);
        }

        return $wishlist;
    }

    public function remove(McpWishlist $wishlist, string $productId, ?string $variationId): McpWishlist
    {
        $items = collect($wishlist->items ?? [])
            ->reject(fn ($i) => (string) $i['product_id'] === (string) $productId && ($i['variation_id'] ?? null) == $variationId)
            ->values()
            ->all();

        $wishlist->update(['items' => $items]);
        return $wishlist;
    }
}
