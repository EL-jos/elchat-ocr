<?php

namespace App\Domain\MCP\Connectors;

use App\Domain\MCP\Commerce\CartRepository;
use App\Domain\MCP\Contracts\ToolResult;
use App\Domain\MCP\Contracts\ToolSchema;
use App\Domain\MCP\Exceptions\AuthExpiredException;
use App\Domain\MCP\Exceptions\ConnectorUnavailableException;
use App\Domain\MCP\Exceptions\ToolNotFoundException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;

/**
 * Connecteur Shopify (Admin API, custom app). Réutilise le même
 * CartRepository que WooCommerce — le panier local est agnostique du
 * connecteur, ce qui permet à Shopify et WooCommerce de coexister ou de se
 * substituer l'un à l'autre sans doublon de code.
 * credentials attendus : { "shop_domain": "monshop.myshopify.com", "access_token": "shpat_..." }
 */
class ShopifyConnector extends AbstractConnector
{
    public function __construct(private readonly CartRepository $carts)
    {
    }

    public function slug(): string { return 'shopify'; }

    public function authenticate(array $credentials): array
    {
        if (empty($credentials['access_token']) || empty($credentials['shop_domain'])) {
            throw new AuthExpiredException('Domaine boutique ou jeton API Shopify manquant.');
        }
        return $credentials;
    }

    public function listTools(): array
    {
        return [
            new ToolSchema('shopify', 'search_products', "Recherche des produits par mot-clé.", [
                'type' => 'object', 'properties' => ['query' => ['type' => 'string']], 'required' => ['query'],
            ], defaultMode: 'auto', capability: 'commerce.search_products'),

            new ToolSchema('shopify', 'get_product', "Détails d'un produit.", [
                'type' => 'object', 'properties' => ['product_id' => ['type' => 'string']], 'required' => ['product_id'],
            ], defaultMode: 'auto', capability: 'commerce.search_products'),

            new ToolSchema('shopify', 'get_product_stock', "Vérifie le stock d'un produit ou d'une variante.", [
                'type' => 'object', 'properties' => ['product_id' => ['type' => 'string'], 'variant_id' => ['type' => 'string']], 'required' => ['product_id'],
            ], defaultMode: 'auto'),

            new ToolSchema('shopify', 'add_to_cart', "Ajoute un produit au panier.", [
                'type' => 'object', 'properties' => [
                    'product_id' => ['type' => 'string'], 'variant_id' => ['type' => 'string'], 'quantity' => ['type' => 'integer'],
                ], 'required' => ['product_id'],
            ], defaultMode: 'auto', capability: 'commerce.manage_cart'),

            new ToolSchema('shopify', 'remove_from_cart', "Retire un produit du panier.", [
                'type' => 'object', 'properties' => ['product_id' => ['type' => 'string'], 'variant_id' => ['type' => 'string']], 'required' => ['product_id'],
            ], defaultMode: 'auto', capability: 'commerce.manage_cart'),

            new ToolSchema('shopify', 'update_cart_quantity', "Modifie la quantité d'un article du panier.", [
                'type' => 'object', 'properties' => ['product_id' => ['type' => 'string'], 'variant_id' => ['type' => 'string'], 'quantity' => ['type' => 'integer']], 'required' => ['product_id', 'quantity'],
            ], defaultMode: 'auto', capability: 'commerce.manage_cart'),

            new ToolSchema('shopify', 'get_cart', "Affiche le panier.", ['type' => 'object', 'properties' => []], defaultMode: 'auto', capability: 'commerce.manage_cart'),
            new ToolSchema('shopify', 'clear_cart', "Vide le panier.", ['type' => 'object', 'properties' => []], defaultMode: 'auto', capability: 'commerce.manage_cart'),

            new ToolSchema('shopify', 'generate_checkout', "Crée une commande brouillon et retourne le lien de paiement.", [
                'type' => 'object', 'properties' => ['email' => ['type' => 'string']],
            ], isWriteAction: true, defaultMode: 'auto', capability: 'commerce.checkout'),

            new ToolSchema('shopify', 'get_order_status', "Statut d'une commande.", [
                'type' => 'object', 'properties' => ['order_id' => ['type' => 'string']], 'required' => ['order_id'],
            ], defaultMode: 'auto'),

            new ToolSchema('shopify', 'create_customer', "Crée un compte client.", [
                'type' => 'object', 'properties' => ['email' => ['type' => 'string'], 'first_name' => ['type' => 'string'], 'last_name' => ['type' => 'string']], 'required' => ['email'],
            ], isWriteAction: true, defaultMode: 'auto', capability: 'commerce.create_account'),
        ];
    }

    public function callTool(string $toolName, array $params, array $credentials, array $context = []): ToolResult
    {
        return match ($toolName) {
            'search_products' => $this->searchProducts($params, $credentials),
            'get_product' => $this->getProduct($params, $credentials),
            'get_product_stock' => $this->getProductStock($params, $credentials),
            'add_to_cart' => $this->addToCart($params, $credentials, $context),
            'remove_from_cart' => $this->removeFromCart($params, $context),
            'update_cart_quantity' => $this->updateCartQuantity($params, $context),
            'get_cart' => $this->getCart($context),
            'clear_cart' => $this->clearCart($context),
            'generate_checkout' => $this->generateCheckout($params, $credentials, $context),
            'get_order_status' => $this->getOrderStatus($params, $credentials),
            'create_customer' => $this->createCustomer($params, $credentials),
            default => throw new ToolNotFoundException("Outil '{$toolName}' inconnu pour shopify."),
        };
    }

    private function searchProducts(array $p, array $c): ToolResult
    {
        try {
            $res = $this->client($c)->get('products.json', ['title' => $p['query'], 'limit' => 10]);
        } catch (RequestException $e) {
            throw new ConnectorUnavailableException('Shopify indisponible: ' . $e->getMessage());
        }
        $this->recordSuccess();
        $products = collect($res->json('products', []))->map(fn ($pr) => [
            'id' => $pr['id'], 'title' => $pr['title'], 'price' => $pr['variants'][0]['price'] ?? null,
            'variant_id' => $pr['variants'][0]['id'] ?? null,
        ])->all();
        if (empty($products)) return ToolResult::fail('not_found', 'Aucun produit trouvé.');
        return ToolResult::ok(['products' => $products], count($products) . ' produit(s) trouvé(s)');
    }

    private function getProduct(array $p, array $c): ToolResult
    {
        try {
            $product = $this->client($c)->get("products/{$p['product_id']}.json")->json('product');
        } catch (RequestException $e) {
            if ($e->response?->status() === 404) return ToolResult::fail('not_found', 'Produit introuvable.');
            throw new ConnectorUnavailableException('Shopify indisponible: ' . $e->getMessage());
        }
        $this->recordSuccess();
        return ToolResult::ok([
            'id' => $product['id'], 'title' => $product['title'], 'description' => strip_tags($product['body_html'] ?? ''),
            'variants' => collect($product['variants'])->map(fn ($v) => ['id' => $v['id'], 'title' => $v['title'], 'price' => $v['price'], 'inventory_quantity' => $v['inventory_quantity']])->all(),
        ], "Produit : {$product['title']}");
    }

    private function getProductStock(array $p, array $c): ToolResult
    {
        $result = $this->getProduct($p, $c);
        if (!$result->success) return $result;
        $variant = collect($result->data['variants'])->firstWhere('id', $p['variant_id'] ?? $result->data['variants'][0]['id']);
        return ToolResult::ok(['stock' => $variant['inventory_quantity'] ?? null], ($variant['inventory_quantity'] ?? 0) > 0 ? 'En stock' : 'Rupture de stock');
    }

    private function addToCart(array $p, array $c, array $ctx): ToolResult
    {
        $result = $this->getProduct($p, $c);
        if (!$result->success) return $result;
        $variant = collect($result->data['variants'])->firstWhere('id', $p['variant_id'] ?? $result->data['variants'][0]['id']);

        $cart = $this->carts->find($ctx['site_id'], $ctx['owner_type'], $ctx['owner_id']);
        $this->carts->addItem($cart, [
            'product_id' => (string) $p['product_id'], 'variation_id' => (string) ($variant['id'] ?? null),
            'quantity' => max(1, (int) ($p['quantity'] ?? 1)), 'name' => $result->data['title'], 'price' => (float) ($variant['price'] ?? 0),
        ]);
        return ToolResult::ok(['cart' => $cart->refresh()->items], 'Ajouté au panier.');
    }

    private function removeFromCart(array $p, array $ctx): ToolResult
    {
        $cart = $this->carts->find($ctx['site_id'], $ctx['owner_type'], $ctx['owner_id']);
        $this->carts->removeItem($cart, $p['product_id'], $p['variant_id'] ?? null);
        return ToolResult::ok(['cart' => $cart->refresh()->items], 'Retiré du panier.');
    }

    private function updateCartQuantity(array $p, array $ctx): ToolResult
    {
        $cart = $this->carts->find($ctx['site_id'], $ctx['owner_type'], $ctx['owner_id']);
        $this->carts->updateQuantity($cart, $p['product_id'], $p['variant_id'] ?? null, (int) $p['quantity']);
        return ToolResult::ok(['cart' => $cart->refresh()->items], 'Quantité mise à jour.');
    }

    private function getCart(array $ctx): ToolResult
    {
        $cart = $this->carts->find($ctx['site_id'], $ctx['owner_type'], $ctx['owner_id']);
        if (empty($cart->items)) return ToolResult::fail('empty_cart', 'Le panier est vide.');
        return ToolResult::ok(['cart' => $cart->items], count($cart->items) . ' article(s)');
    }

    private function clearCart(array $ctx): ToolResult
    {
        $cart = $this->carts->find($ctx['site_id'], $ctx['owner_type'], $ctx['owner_id']);
        $this->carts->clear($cart);
        return ToolResult::ok([], 'Panier vidé.');
    }

    private function generateCheckout(array $p, array $c, array $ctx): ToolResult
    {
        $cart = $this->carts->find($ctx['site_id'], $ctx['owner_type'], $ctx['owner_id']);
        if (empty($cart->items)) return ToolResult::fail('empty_cart', 'Le panier est vide.');

        $lineItems = collect($cart->items)->map(fn ($i) => [
            'variant_id' => (int) $i['variation_id'], 'quantity' => $i['quantity'],
        ])->all();

        try {
            $order = $this->client($c)->post('draft_orders.json', [
                'draft_order' => ['line_items' => $lineItems, 'email' => $p['email'] ?? null],
            ])->json('draft_order');
        } catch (RequestException $e) {
            Log::error('MCP Shopify generate_checkout a échoué', ['status' => $e->response?->status(), 'body' => $e->response?->body()]);
            throw new ConnectorUnavailableException('Shopify indisponible: ' . $e->getMessage());
        }
        $this->recordSuccess();
        $this->carts->clear($cart);

        return ToolResult::ok(
            ['order_id' => $order['id'], 'checkout_url' => $order['invoice_url'], 'total' => $order['total_price']],
            'Commande créée, en attente de paiement.',
            identity: !empty($p['email']) ? ['email' => $p['email']] : null,
            cartSync: ['type' => 'clear'],
        );
    }

    private function getOrderStatus(array $p, array $c): ToolResult
    {
        try {
            $order = $this->client($c)->get("orders/{$p['order_id']}.json")->json('order');
        } catch (RequestException $e) {
            if ($e->response?->status() === 404) return ToolResult::fail('not_found', 'Commande introuvable.');
            throw new ConnectorUnavailableException('Shopify indisponible: ' . $e->getMessage());
        }
        $this->recordSuccess();
        return ToolResult::ok([
            'order_id' => $order['id'], 'status' => $order['financial_status'], 'fulfillment_status' => $order['fulfillment_status'], 'total' => $order['total_price'],
        ], "Commande #{$order['id']} : {$order['financial_status']}", identity: !empty($order['email']) ? ['email' => $order['email']] : null);
    }

    private function createCustomer(array $p, array $c): ToolResult
    {
        try {
            $customer = $this->client($c)->post('customers.json', [
                'customer' => array_filter(['email' => $p['email'], 'first_name' => $p['first_name'] ?? null, 'last_name' => $p['last_name'] ?? null]),
            ])->json('customer');
        } catch (RequestException $e) {
            if ($e->response?->status() === 422) return ToolResult::fail('already_exists', 'Un client existe déjà avec cet email.');
            throw new ConnectorUnavailableException('Shopify indisponible: ' . $e->getMessage());
        }
        $this->recordSuccess();
        return ToolResult::ok(['customer_id' => $customer['id']], 'Compte créé.', identity: ['email' => $p['email'], 'firstname' => $p['first_name'] ?? null, 'lastname' => $p['last_name'] ?? null]);
    }

    private function client(array $c)
    {
        return $this->http("https://{$c['shop_domain']}/admin/api/2024-01/")->withHeaders(['X-Shopify-Access-Token' => $c['access_token']]);
    }
}
