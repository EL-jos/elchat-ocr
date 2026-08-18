<?php

namespace App\Domain\MCP\Commerce;


use App\Models\Mcp\McpCart;

class CartRepository
{
    public function find(string $siteId, string $ownerType, string $ownerId): McpCart
    {
        return McpCart::firstOrCreate(
            ['site_id' => $siteId, 'owner_type' => $ownerType, 'owner_id' => $ownerId],
            ['items' => [], 'coupon_code' => null]
        );
    }

    public function addItem(McpCart $cart, array $item): McpCart
    {
        $items = $cart->items ?? [];
        $key = fn ($i) => ($i['product_id'] ?? null) . ':' . ($i['variation_id'] ?? '0');

        $index = collect($items)->search(fn ($i) => $key($i) === $key($item));

        if ($index !== false) {
            $items[$index]['quantity'] += $item['quantity'];
        } else {
            $items[] = $item;
        }

        $cart->update(['items' => $items]);
        return $cart;
    }

    public function updateQuantity(McpCart $cart, string $productId, ?string $variationId, int $quantity): McpCart
    {
        $items = collect($cart->items ?? [])
            ->map(function ($i) use ($productId, $variationId, $quantity) {
                if ((string) $i['product_id'] === (string) $productId && ($i['variation_id'] ?? null) == $variationId) {
                    $i['quantity'] = $quantity;
                }
                return $i;
            })
            ->filter(fn ($i) => $i['quantity'] > 0)
            ->values()
            ->all();

        $cart->update(['items' => $items]);
        return $cart;
    }

    public function removeItem(McpCart $cart, string $productId, ?string $variationId): McpCart
    {
        $items = collect($cart->items ?? [])
            ->reject(fn ($i) => (string) $i['product_id'] === (string) $productId && ($i['variation_id'] ?? null) == $variationId)
            ->values()
            ->all();

        $cart->update(['items' => $items]);
        return $cart;
    }

    public function clear(McpCart $cart): McpCart
    {
        $cart->update(['items' => [], 'coupon_code' => null]);
        return $cart;
    }

    public function setCoupon(McpCart $cart, ?string $code): McpCart
    {
        $cart->update(['coupon_code' => $code]);
        return $cart;
    }
}
