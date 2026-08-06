<?php

namespace App\Domain\MCP\Connectors;

use App\Domain\MCP\Commerce\CartRepository;
use App\Domain\MCP\Commerce\WishlistRepository;
use App\Domain\MCP\Contracts\ToolResult;
use App\Domain\MCP\Contracts\ToolSchema;
use App\Domain\MCP\Exceptions\AuthExpiredException;
use App\Domain\MCP\Exceptions\ConnectorUnavailableException;
use App\Domain\MCP\Exceptions\ToolNotFoundException;
use App\Models\Mcp\McpCustomerLink;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WooCommerceConnector extends AbstractConnector
{
    public function __construct(
        private readonly CartRepository $carts,
        private readonly WishlistRepository $wishlists,
    ) {
    }

    public function slug(): string
    {
        return 'woocommerce';
    }

    public function authenticate(array $credentials): array
    {
        if (empty($credentials['consumer_key']) || empty($credentials['consumer_secret'])) {
            throw new AuthExpiredException('Clés API WooCommerce manquantes ou invalides.');
        }
        return $credentials;
    }

    public function listTools(): array
    {
        return [
            ...$this->productTools(),
            ...$this->cartTools(),
            ...$this->checkoutTools(),
            ...$this->orderTools(),
            ...$this->wishlistTools(),
            ...$this->accountTools(),
            ...$this->reviewTools(),
            ...$this->shippingTools(),
            ...$this->adminTools(),
        ];
    }

    public function callTool(string $toolName, array $params, array $credentials, array $context = []): ToolResult
    {
        return match ($toolName) {
            // Produits
            'search_products' => $this->searchProducts($params, $credentials),
            'get_product' => $this->getProduct($params, $credentials),
            'get_product_variations' => $this->getProductVariations($params, $credentials),
            'get_product_stock' => $this->getProductStock($params, $credentials),
            'recommend_products' => $this->recommendProducts($params, $credentials),

            // Panier (local)
            'add_to_cart' => $this->addToCart($params, $credentials, $context),
            'remove_from_cart' => $this->removeFromCart($params, $context),
            'update_cart_quantity' => $this->updateCartQuantity($params, $context),
            'get_cart' => $this->getCart($context),
            'clear_cart' => $this->clearCart($context),
            'calculate_cart' => $this->calculateCart($credentials, $context),

            // Checkout
            'apply_coupon' => $this->applyCoupon($params, $credentials, $context),
            'remove_coupon' => $this->removeCoupon($context),
            'generate_checkout' => $this->generateCheckout($params, $credentials, $context),

            // Commandes
            'get_order_status' => $this->getOrderStatus($params, $credentials),
            'search_orders_by_email' => $this->searchOrdersByEmail($params, $credentials),
            'get_customer_orders' => $this->searchOrdersByEmail($params, $credentials), // même logique, framing différent côté LLM
            'track_order' => $this->trackOrder($params, $credentials),
            'download_invoice' => $this->downloadInvoice($params, $credentials),
            'request_return' => $this->requestReturn($params, $credentials),
            'request_refund' => $this->issueRefund($params, $credentials), // exécuté seulement après confirmation admin
            'update_shipping_address' => $this->updateShippingAddress($params, $credentials),
            'cancel_order' => $this->cancelOrder($params, $credentials),

            // Wishlist (local)
            'add_to_wishlist' => $this->addToWishlist($params, $context),
            'remove_from_wishlist' => $this->removeFromWishlist($params, $context),
            'get_wishlist' => $this->getWishlist($context),

            // Compte
            'create_customer' => $this->createCustomer($params, $credentials, $context),
            'find_customer' => $this->findCustomer($params, $credentials),
            'update_customer' => $this->updateCustomer($params, $credentials, $context),

            // Avis
            'create_review' => $this->createReview($params, $credentials),
            'get_reviews' => $this->getReviews($params, $credentials),

            // Livraison
            'get_shipping_methods' => $this->getShippingMethods($credentials),
            'estimate_shipping' => $this->estimateShipping($params, $credentials),
            'track_package' => $this->trackOrder($params, $credentials), // best-effort, voir note dans le schema

            // Admin uniquement
            'issue_refund' => $this->issueRefund($params, $credentials),
            'update_order_status' => $this->updateOrderStatus($params, $credentials),
            'adjust_stock' => $this->adjustStock($params, $credentials),
            'update_product_price' => $this->updateProductPrice($params, $credentials),

            default => throw new ToolNotFoundException("Outil '{$toolName}' inconnu pour woocommerce."),
        };
    }

    // =====================================================================
    // 🛒 PRODUITS (lecture seule, visiteur, auto)
    // =====================================================================

    private function productTools(): array
    {
        return [
            new ToolSchema('woocommerce', 'search_products',
                "Recherche les produits publiés correspondant aux critères fournis (mot-clé, catégorie, prix minimum et/ou maximum). Utiliser cet outil lorsqu'un visiteur recherche un produit sans connaître son identifiant. Ne jamais supposer qu'un produit existe. Utiliser uniquement les résultats retournés pour recommander ou sélectionner un produit.", [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string'],
                    'category' => ['type' => 'string'],
                    'min_price' => ['type' => 'number'],
                    'max_price' => ['type' => 'number'],
                ],
            ], defaultMode: 'auto', capability: 'commerce.search_products'),

            new ToolSchema('woocommerce', 'get_product',
                "Récupère les informations complètes d'un produit identifié : description, prix actuel, promotions, disponibilité, images et variantes éventuelles. Utiliser cet outil avant toute décision nécessitant les informations exactes d'un produit. Ne jamais compléter ou deviner les données manquantes.", [
                'type' => 'object', 'properties' => ['product_id' => ['type' => 'string']], 'required' => ['product_id'],
            ], defaultMode: 'auto', capability: 'commerce.search_products'),

            new ToolSchema('woocommerce', 'get_product_variations',
                "Liste toutes les variantes disponibles d'un produit variable (taille, couleur, capacité, etc.) avec leurs identifiants et disponibilités. Utiliser cet outil lorsqu'un produit possède plusieurs variantes avant toute tentative d'ajout au panier. Ne jamais choisir une variante à la place du visiteur.", [
                'type' => 'object', 'properties' => ['product_id' => ['type' => 'string']], 'required' => ['product_id'],
            ], defaultMode: 'auto'),

            new ToolSchema('woocommerce', 'get_product_stock',
                "Vérifie la disponibilité réelle d'un produit ou d'une variante spécifique. Utiliser cet outil lorsqu'un visiteur demande si un article est disponible ou avant une opération sensible nécessitant un stock valide. Ne jamais déduire le stock à partir d'informations anciennes.", [
                'type' => 'object', 'properties' => ['product_id' => ['type' => 'string'], 'variation_id' => ['type' => 'string']], 'required' => ['product_id'],
            ], defaultMode: 'auto'),

            new ToolSchema('woocommerce', 'recommend_products',
                "Recherche des produits similaires, complémentaires ou liés à un produit existant ou à une recherche utilisateur. Les recommandations doivent exclusivement provenir des résultats retournés par l'outil. Ne jamais inventer de recommandations.", [
                'type' => 'object', 'properties' => ['product_id' => ['type' => 'string'], 'query' => ['type' => 'string']],
            ], defaultMode: 'auto'),
        ];
    }

    private function searchProducts(array $p, array $c): ToolResult
    {
        try {
            $res = $this->client($c)->get('products', array_filter([
                'search' => $p['query'] ?? null, 'category' => $p['category'] ?? null,
                'min_price' => $p['min_price'] ?? null, 'max_price' => $p['max_price'] ?? null,
                'per_page' => 10, 'status' => 'publish',
            ]));
        } catch (RequestException $e) {
            throw new ConnectorUnavailableException($e->getMessage());
        }
        $this->recordSuccess();

        $products = collect($res->json())->map(fn ($pr) => [
            'id' => $pr['id'], 'name' => $pr['name'], 'price' => $pr['price'], 'stock_status' => $pr['stock_status'],
            'permalink' => $pr['permalink'], 'image' => $pr['images'][0]['src'] ?? null,
        ])->all();

        if (empty($products)) {
            return ToolResult::fail('not_found', 'Aucun produit trouvé.');
        }
        return ToolResult::ok(['products' => $products], count($products) . ' produit(s) trouvé(s)');
    }

    private function getProduct(array $p, array $c): ToolResult
    {
        try {
            $res = $this->client($c)->get("products/{$p['product_id']}");
        } catch (RequestException $e) {
            if ($e->response?->status() === 404) return ToolResult::fail('not_found', 'Produit introuvable.');
            throw new ConnectorUnavailableException($e->getMessage());
        }
        $this->recordSuccess();
        $pr = $res->json();

        return ToolResult::ok([
            'id' => $pr['id'], 'name' => $pr['name'], 'description' => strip_tags($pr['short_description'] ?? $pr['description'] ?? ''),
            'price' => $pr['price'], 'regular_price' => $pr['regular_price'], 'sale_price' => $pr['sale_price'],
            'stock_status' => $pr['stock_status'], 'stock_quantity' => $pr['stock_quantity'],
            'has_variations' => !empty($pr['variations']), 'permalink' => $pr['permalink'],
            'images' => array_column($pr['images'] ?? [], 'src'),
        ], "Produit : {$pr['name']}");
    }

    private function getProductVariations(array $p, array $c): ToolResult
    {
        try {
            $res = $this->client($c)->get("products/{$p['product_id']}/variations", ['per_page' => 50]);
        } catch (RequestException $e) {
            throw new ConnectorUnavailableException($e->getMessage());
        }
        $this->recordSuccess();
        $variations = collect($res->json())->map(fn ($v) => [
            'id' => $v['id'], 'attributes' => $v['attributes'], 'price' => $v['price'], 'stock_status' => $v['stock_status'],
        ])->all();

        if (empty($variations)) return ToolResult::fail('not_found', 'Ce produit n\'a pas de variantes.');
        return ToolResult::ok(['variations' => $variations], count($variations) . ' variante(s)');
    }

    private function getProductStock(array $p, array $c): ToolResult
    {
        try {
            $endpoint = !empty($p['variation_id'])
                ? "products/{$p['product_id']}/variations/{$p['variation_id']}"
                : "products/{$p['product_id']}";
            $res = $this->client($c)->get($endpoint);
        } catch (RequestException $e) {
            if ($e->response?->status() === 404) return ToolResult::fail('not_found', 'Produit introuvable.');
            throw new ConnectorUnavailableException($e->getMessage());
        }
        $this->recordSuccess();
        $pr = $res->json();

        return ToolResult::ok([
            'stock_status' => $pr['stock_status'], 'stock_quantity' => $pr['stock_quantity'],
        ], $pr['stock_status'] === 'instock' ? 'En stock' : 'Rupture de stock');
    }

    private function recommendProducts(array $p, array $c): ToolResult
    {
        try {
            if (!empty($p['product_id'])) {
                $product = $this->client($c)->get("products/{$p['product_id']}")->json();
                $relatedIds = array_slice($product['related_ids'] ?? [], 0, 5);
                if (empty($relatedIds)) return ToolResult::fail('not_found', 'Aucune recommandation disponible.');
                $res = $this->client($c)->get('products', ['include' => implode(',', $relatedIds)]);
            } else {
                $res = $this->client($c)->get('products', ['search' => $p['query'] ?? '', 'per_page' => 5]);
            }
        } catch (RequestException $e) {
            throw new ConnectorUnavailableException($e->getMessage());
        }
        $this->recordSuccess();
        $products = collect($res->json())->map(fn ($pr) => ['id' => $pr['id'], 'name' => $pr['name'], 'price' => $pr['price'], 'permalink' => $pr['permalink']])->all();
        return ToolResult::ok(['products' => $products], count($products) . ' suggestion(s)');
    }

    // =====================================================================
    // 🛒 PANIER (local, visiteur, auto — aucun impact financier réel)
    // =====================================================================

    private function cartTools(): array
    {
        $qty = ['type' => 'integer', 'minimum' => 1];
        return [
            new ToolSchema('woocommerce', 'add_to_cart',
                "Ajoute un produit ou une variante au panier courant du visiteur. Vérifier auparavant que le produit existe et qu'une variante obligatoire a été choisie si nécessaire. Ne jamais ajouter automatiquement une variante. Éviter les ajouts répétés du même produit sauf si le visiteur demande explicitement d'augmenter la quantité.", [
                'type' => 'object',
                'properties' => ['product_id' => ['type' => 'string'], 'variation_id' => ['type' => 'string'], 'quantity' => $qty],
                'required' => ['product_id'],
            ], defaultMode: 'auto', capability: 'commerce.manage_cart'),
            new ToolSchema('woocommerce', 'remove_from_cart',
                "Retire un produit ou une variante du panier courant. Utiliser uniquement si l'article est présent dans le panier ou si le visiteur demande explicitement sa suppression.", [
                'type' => 'object', 'properties' => ['product_id' => ['type' => 'string'], 'variation_id' => ['type' => 'string']], 'required' => ['product_id'],
            ], defaultMode: 'auto', capability: 'commerce.manage_cart'),
            new ToolSchema('woocommerce', 'update_cart_quantity',
                "Modifie la quantité d'un article déjà présent dans le panier. Utiliser cette opération plutôt qu'un nouvel ajout lorsqu'il s'agit simplement d'augmenter ou diminuer une quantité.", [
                'type' => 'object', 'properties' => ['product_id' => ['type' => 'string'], 'variation_id' => ['type' => 'string'], 'quantity' => ['type' => 'integer', 'minimum' => 0]], 'required' => ['product_id', 'quantity'],
            ], defaultMode: 'auto', capability: 'commerce.manage_cart'),
            new ToolSchema('woocommerce', 'get_cart',
                "Retourne le contenu actuel du panier local, y compris les articles et le coupon éventuellement appliqué. Utiliser cet outil avant de répondre à une question concernant le panier ou avant une opération dépendant de son contenu.", ['type' => 'object', 'properties' => []], defaultMode: 'auto'),
            new ToolSchema('woocommerce', 'clear_cart',
                "Vide entièrement le panier courant. Utiliser uniquement lorsque le visiteur demande explicitement de supprimer tous les articles.", ['type' => 'object', 'properties' => []], defaultMode: 'auto', capability: 'commerce.manage_cart'),
            new ToolSchema('woocommerce', 'calculate_cart',
                "Calcule le montant actuel du panier à partir de son contenu réel et du coupon éventuellement appliqué. Retourne le sous-total, les remises et le total estimé. Ce calcul ne crée aucune commande et n'effectue aucun paiement.", ['type' => 'object', 'properties' => []], defaultMode: 'auto'),
        ];
    }

    private function addToCart(array $p, array $c, array $ctx): ToolResult
    {
        $qty = max(1, (int) ($p['quantity'] ?? 1));
        $variationId = !empty($p['variation_id']) ? (string) $p['variation_id'] : null; // 🆕 normalise "" -> null

        try {
            $endpoint = $variationId ? "products/{$p['product_id']}/variations/{$variationId}" : "products/{$p['product_id']}";
            $product = $this->client($c)->get($endpoint)->json();
        } catch (RequestException $e) {
            if ($e->response?->status() === 404) return ToolResult::fail('not_found', 'Produit introuvable.');
            throw new ConnectorUnavailableException($e->getMessage());
        }
        $this->recordSuccess();

        // 🆕 Produit variable sans variante choisie : WooCommerce refuse l'ajout
        // du produit parent au panier. On bloque ici, avec un message qui pousse
        // le LLM à enchaîner sur get_product_variations puis à redemander la
        // couleur/taille au visiteur, plutôt que de laisser l'erreur remonter
        // jusqu'au navigateur du visiteur.
        if (!$variationId && ($product['type'] ?? null) === 'variable') {
            return ToolResult::fail(
                'variation_required',
                "Ce produit a plusieurs variantes (couleur, taille...). Utilise get_product_variations pour lister les options, demande au visiteur laquelle il veut, puis rappelle add_to_cart avec le variation_id correspondant."
            );
        }

        if (($product['stock_status'] ?? 'instock') !== 'instock') {
            return ToolResult::fail('out_of_stock', 'Ce produit est en rupture de stock.');
        }

        $cart = $this->carts->find($ctx['site_id'], $ctx['owner_type'], $ctx['owner_id']);
        $this->carts->addItem($cart, [
            'product_id' => (string) $p['product_id'], 'variation_id' => $variationId,
            'quantity' => $qty, 'name' => $product['name'] ?? "Produit #{$p['product_id']}", 'price' => (float) ($product['price'] ?? 0),
        ]);

        return ToolResult::ok(
            ['cart' => $cart->refresh()->items],
            "{$qty} × {$product['name']} ajouté(s) au panier.",
            cartSync: ['type' => 'add', 'product_id' => (string) $p['product_id'], 'variation_id' => $variationId, 'quantity' => $qty],
        );
    }

    private function removeFromCart(array $p, array $ctx): ToolResult
    {
        $variationId = !empty($p['variation_id']) ? (string) $p['variation_id'] : null;

        $cart = $this->carts->find($ctx['site_id'], $ctx['owner_type'], $ctx['owner_id']);
        $this->carts->removeItem($cart, $p['product_id'], $variationId);
        return ToolResult::ok(
            ['cart' => $cart->refresh()->items],
            'Article retiré du panier.',
            cartSync: ['type' => 'remove', 'product_id' => (string) $p['product_id'], 'variation_id' => $variationId],
        );
    }

    private function updateCartQuantity(array $p, array $ctx): ToolResult
    {
        $variationId = !empty($p['variation_id']) ? (string) $p['variation_id'] : null;

        $cart = $this->carts->find($ctx['site_id'], $ctx['owner_type'], $ctx['owner_id']);
        $this->carts->updateQuantity($cart, $p['product_id'], $variationId, (int) $p['quantity']);
        return ToolResult::ok(
            ['cart' => $cart->refresh()->items],
            'Quantité mise à jour.',
            cartSync: ['type' => 'update', 'product_id' => (string) $p['product_id'], 'variation_id' => $variationId, 'quantity' => (int) $p['quantity']],
        );
    }

    private function getCart(array $ctx): ToolResult
    {
        $cart = $this->carts->find($ctx['site_id'], $ctx['owner_type'], $ctx['owner_id']);
        if (empty($cart->items)) return ToolResult::fail('empty_cart', 'Le panier est vide.');
        return ToolResult::ok(['cart' => $cart->items, 'coupon_code' => $cart->coupon_code], count($cart->items) . ' article(s) dans le panier');
    }

    private function clearCart(array $ctx): ToolResult
    {
        $cart = $this->carts->find($ctx['site_id'], $ctx['owner_type'], $ctx['owner_id']);
        $this->carts->clear($cart);
        return ToolResult::ok([], 'Panier vidé.', cartSync: ['type' => 'clear']);
    }

    private function calculateCart(array $c, array $ctx): ToolResult
    {
        $cart = $this->carts->find($ctx['site_id'], $ctx['owner_type'], $ctx['owner_id']);
        if (empty($cart->items)) return ToolResult::fail('empty_cart', 'Le panier est vide.');

        $subtotal = collect($cart->items)->sum(fn ($i) => $i['price'] * $i['quantity']);
        $discount = 0;

        if ($cart->coupon_code) {
            $discount = $this->computeCouponDiscount($cart->coupon_code, $subtotal, $c);
        }

        return ToolResult::ok([
            'subtotal' => round($subtotal, 2), 'discount' => round($discount, 2), 'total' => round($subtotal - $discount, 2),
            'coupon_code' => $cart->coupon_code,
        ], 'Total calculé : ' . round($subtotal - $discount, 2));
    }

    // =====================================================================
    // 💳 CHECKOUT (visiteur, auto — génère une URL, ne débite rien directement)
    // =====================================================================

    private function checkoutTools(): array
    {
        return [
            new ToolSchema('woocommerce', 'apply_coupon',
                "Vérifie puis applique un code promotionnel valide au panier courant. Utiliser uniquement lorsque le visiteur fournit un code. Ne jamais inventer ni suggérer un coupon inexistant.", [
                'type' => 'object', 'properties' => ['code' => ['type' => 'string']], 'required' => ['code'],
            ], defaultMode: 'auto'),
            new ToolSchema('woocommerce', 'remove_coupon',
                "Supprime le coupon actuellement appliqué au panier sans modifier les autres articles.", ['type' => 'object', 'properties' => []], defaultMode: 'auto'),
            new ToolSchema('woocommerce', 'generate_checkout',
                "Crée une commande WooCommerce à partir du panier courant (ou d'une sélection d'articles) puis retourne l'URL officielle de paiement. Cette opération ne prélève aucun paiement mais génère une véritable commande en attente. Vérifier que le panier n'est pas vide avant l'exécution. Éviter toute génération répétée d'une même commande sans demande explicite du visiteur.", [
                'type' => 'object',
                'properties' => [
                    'billing_email' => ['type' => 'string'], 'billing_firstname' => ['type' => 'string'], 'billing_lastname' => ['type' => 'string'],
                    'product_ids' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Optionnel : ne commander que ces product_id du panier (sinon commande sur tout le panier)'],
                ],
            ], isWriteAction: true, defaultMode: 'auto', capability: 'commerce.checkout'),
        ];
    }

    private function applyCoupon(array $p, array $c, array $ctx): ToolResult
    {
        try {
            $res = $this->client($c)->get('coupons', ['code' => $p['code']]);
        } catch (RequestException $e) {
            throw new ConnectorUnavailableException($e->getMessage());
        }
        $this->recordSuccess();
        $coupon = $res->json()[0] ?? null;

        if (!$coupon) return ToolResult::fail('invalid_coupon', 'Ce code promo est invalide ou expiré.');
        if (!empty($coupon['date_expires']) && now()->greaterThan($coupon['date_expires'])) {
            return ToolResult::fail('expired_coupon', 'Ce code promo a expiré.');
        }

        $cart = $this->carts->find($ctx['site_id'], $ctx['owner_type'], $ctx['owner_id']);
        $this->carts->setCoupon($cart, $p['code']);
        return ToolResult::ok(
            ['coupon_code' => $p['code']],
            "Code promo « {$p['code']} » appliqué.",
            cartSync: ['type' => 'apply_coupon', 'code' => $p['code']],
        );
    }

    private function removeCoupon(array $ctx): ToolResult
    {
        $cart = $this->carts->find($ctx['site_id'], $ctx['owner_type'], $ctx['owner_id']);
        $code = $cart->coupon_code; // 🆕 capturé avant suppression, nécessaire pour l'appel DELETE côté navigateur
        $this->carts->setCoupon($cart, null);
        return ToolResult::ok(
            [],
            'Code promo retiré.',
            cartSync: $code ? ['type' => 'remove_coupon', 'code' => $code] : null,
        );
    }

    private function generateCheckout(array $p, array $c, array $ctx): ToolResult
    {
        $cart = $this->carts->find($ctx['site_id'], $ctx['owner_type'], $ctx['owner_id']);
        if (empty($cart->items)) return ToolResult::fail('empty_cart', 'Le panier est vide, impossible de générer un paiement.');

        $requestedIds = !empty($p['product_ids']) ? array_map('strval', (array) $p['product_ids']) : null;

        $itemsToOrder = $requestedIds
            ? collect($cart->items)->filter(fn ($i) => in_array((string) $i['product_id'], $requestedIds, true))->values()->all()
            : $cart->items;

        if (empty($itemsToOrder)) {
            return ToolResult::fail('not_found', "Aucun des produits demandés n'est présent dans le panier.");
        }

        $lineItems = collect($itemsToOrder)->map(fn ($i) => array_filter([
            'product_id' => (int) $i['product_id'], 'variation_id' => $i['variation_id'] ? (int) $i['variation_id'] : null, 'quantity' => $i['quantity'],
        ]))->all();

        $payload = [
            'payment_method' => '', 'set_paid' => false, 'status' => 'pending', 'line_items' => $lineItems,
            'billing' => array_filter([
                'email' => $p['billing_email'] ?? null, 'first_name' => $p['billing_firstname'] ?? null, 'last_name' => $p['billing_lastname'] ?? null,
            ]),
        ];
        // Le coupon n'est cohérent que pour une commande sur tout le panier.
        if ($cart->coupon_code && !$requestedIds) {
            $payload['coupon_lines'] = [['code' => $cart->coupon_code]];
        }

        try {
            $order = $this->client($c)->post('orders', $payload)->json();
        } catch (RequestException $e) {
            Log::error('MCP WooCommerce generate_checkout a échoué', [
                'status' => $e->response?->status(),
                'body' => $e->response?->body(),
            ]);
            throw new ConnectorUnavailableException($e->getMessage());
        }
        $this->recordSuccess();

        $checkoutUrl = $this->resolveCheckoutUrl((string) $order['id'], $order['order_key'], $c);

        // Ne retire que les articles effectivement commandés (panier local ET
        // vrai panier navigateur via cartSync) — le reste reste intact si commande partielle.
        foreach ($itemsToOrder as $item) {
            $this->carts->removeItem($cart, $item['product_id'], $item['variation_id'] ?? null);
        }

        $identity = !empty($p['billing_email']) ? ['email' => $p['billing_email'], 'firstname' => $p['billing_firstname'] ?? null, 'lastname' => $p['billing_lastname'] ?? null] : null;

        return ToolResult::ok(
            ['order_id' => $order['id'], 'checkout_url' => $checkoutUrl, 'total' => $order['total']],
            "Commande #{$order['id']} créée, en attente de paiement.",
            $identity,
            cartSync: $requestedIds
                ? ['type' => 'remove_many', 'items' => collect($itemsToOrder)->map(fn ($i) => ['product_id' => (string) $i['product_id'], 'variation_id' => $i['variation_id'] ?? null])->all()]
                : ['type' => 'clear'],
        );
    }

    /**
     * 🆕 Résout la VRAIE URL de la page de paiement WooCommerce (son slug est
     * configurable et souvent traduit — jamais forcément "/checkout/"). Passe
     * par system_status (WooCommerce) pour trouver l'ID de la page, puis par
     * l'API REST WordPress core (publique) pour son permalien réel. Mis en
     * cache 24h par site, cette information change rarement.
     */
    private function resolveCheckoutUrl(string $orderId, string $orderKey, array $credentials): string
    {
        $storeUrl = rtrim($credentials['store_url'] ?? '', '/');
        $cacheKey = 'mcp:woocommerce:checkout_base_url:v2:' . md5($storeUrl); // 🆕 :v2 pour invalider l'ancien cache erroné

        $checkoutBaseUrl = Cache::remember($cacheKey, now()->addDay(), function () use ($credentials, $storeUrl) {
            try {
                $status = $this->client($credentials)->get('system_status')->json();
                $pages = collect($status['pages'] ?? []);

                Log::info('MCP: pages WooCommerce détectées pour résolution checkout', ['pages' => $pages->toArray()]);

                // 🆕 3 critères de correspondance, du plus fiable au plus large :
                // shortcode classique, checkout par blocs, puis le nom de page
                // interne WooCommerce (toujours "Checkout", même sur un site en
                // français — c'est un identifiant WooCommerce, pas un libellé traduit).
                $checkoutPage = $pages->first(fn ($p) => ($p['shortcode'] ?? null) === '[woocommerce_checkout]')
                    ?? $pages->first(fn ($p) => str_contains(strtolower((string) ($p['block'] ?? '')), 'checkout'))
                    ?? $pages->first(fn ($p) => ($p['page_name'] ?? null) === 'Checkout');

                if (!empty($checkoutPage['page_id']) && $checkoutPage['page_id'] !== '0') {
                    $page = Http::get("{$storeUrl}/wp-json/wp/v2/pages/{$checkoutPage['page_id']}")->json();
                    if (!empty($page['link'])) {
                        return rtrim($page['link'], '/');
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('MCP: impossible de résoudre la vraie page de checkout, repli sur /checkout/', ['error' => $e->getMessage()]);
            }

            return "{$storeUrl}/checkout";
        });

        return "{$checkoutBaseUrl}/order-pay/{$orderId}/?pay_for_order=true&key={$orderKey}";
    }

    private function computeCouponDiscount(string $code, float $subtotal, array $c): float
    {
        try {
            $coupon = $this->client($c)->get('coupons', ['code' => $code])->json()[0] ?? null;
        } catch (RequestException) {
            return 0;
        }
        if (!$coupon) return 0;

        return match ($coupon['discount_type'] ?? '') {
            'percent' => $subtotal * ((float) $coupon['amount'] / 100),
            'fixed_cart' => min((float) $coupon['amount'], $subtotal),
            default => 0,
        };
    }

    // =====================================================================
    // 📦 COMMANDES
    // =====================================================================

    private function orderTools(): array
    {
        return [
            new ToolSchema('woocommerce', 'get_order_status',
                "Récupère l'état réel d'une commande : statut, montant, date, méthode de livraison et informations de suivi disponibles. Utiliser uniquement avec un identifiant de commande valide.", [
                'type' => 'object', 'properties' => ['order_id' => ['type' => 'string']], 'required' => ['order_id'],
            ], defaultMode: 'auto'),
            new ToolSchema('woocommerce', 'search_orders_by_email',
                "Recherche les commandes récentes associées à une adresse email. Utiliser lorsque le visiteur ne connaît pas son numéro de commande. Ne retourner que les commandes réellement trouvées.", [
                'type' => 'object', 'properties' => ['email' => ['type' => 'string']], 'required' => ['email'],
            ], defaultMode: 'auto'),
            new ToolSchema('woocommerce', 'get_customer_orders',
                "Retourne l'historique des commandes du client identifié par son adresse email. Utiliser lorsqu'un visiteur souhaite consulter ses achats passés.", [
                'type' => 'object', 'properties' => ['email' => ['type' => 'string']], 'required' => ['email'],
            ], defaultMode: 'auto'),
            new ToolSchema('woocommerce', 'track_order',
                "Recherche les informations de suivi disponibles pour une commande existante. Si aucun numéro de suivi n'est disponible, l'indiquer clairement sans en inventer un.", [
                'type' => 'object', 'properties' => ['order_id' => ['type' => 'string']], 'required' => ['order_id'],
            ], defaultMode: 'auto'),
            new ToolSchema('woocommerce', 'download_invoice',
                "Retourne le lien officiel de téléchargement de la facture lorsqu'il est disponible pour cette boutique.", [
                'type' => 'object', 'properties' => ['order_id' => ['type' => 'string']], 'required' => ['order_id'],
            ], defaultMode: 'auto'),
            new ToolSchema('woocommerce', 'request_return',
                "Enregistre une demande de retour à destination de l'équipe commerciale. Cette opération ne valide pas automatiquement le retour ni le remboursement.", [
                'type' => 'object', 'properties' => ['order_id' => ['type' => 'string'], 'reason' => ['type' => 'string']], 'required' => ['order_id', 'reason'],
            ], defaultMode: 'auto'),
            new ToolSchema('woocommerce', 'request_refund',
                "Enregistre une demande de remboursement qui devra être validée selon les règles de la boutique. Cette opération ne déclenche jamais automatiquement un remboursement financier.", [
                'type' => 'object', 'properties' => ['order_id' => ['type' => 'string'], 'amount' => ['type' => 'number'], 'reason' => ['type' => 'string']], 'required' => ['order_id', 'reason'],
            ], isWriteAction: true, defaultMode: 'confirm', defaultConfirmActor: 'admin'),
            new ToolSchema('woocommerce', 'update_shipping_address',
                "Met à jour l'adresse de livraison d'une commande uniquement si celle-ci est encore modifiable. Utiliser après confirmation explicite du client.", [
                'type' => 'object', 'properties' => ['order_id' => ['type' => 'string'], 'address_1' => ['type' => 'string'], 'city' => ['type' => 'string'], 'postcode' => ['type' => 'string'], 'country' => ['type' => 'string']], 'required' => ['order_id'],
            ], isWriteAction: true, defaultMode: 'confirm', defaultConfirmActor: 'visitor'),
            new ToolSchema('woocommerce', 'cancel_order',
                "Demande l'annulation d'une commande. Cette opération est irréversible et peut être refusée selon l'état actuel de la commande. Toujours demander confirmation avant exécution.", [
                'type' => 'object', 'properties' => ['order_id' => ['type' => 'string'], 'reason' => ['type' => 'string']], 'required' => ['order_id'],
            ], isWriteAction: true, defaultMode: 'confirm', defaultConfirmActor: 'admin'),
        ];
    }

    private function getOrderStatus(array $p, array $c): ToolResult
    {
        try {
            $order = $this->client($c)->get("orders/{$p['order_id']}")->json();
        } catch (RequestException $e) {
            if ($e->response?->status() === 404) return ToolResult::fail('not_found', "Aucune commande #{$p['order_id']}.");
            throw new ConnectorUnavailableException($e->getMessage());
        }
        $this->recordSuccess();

        $identity = !empty($order['billing']['email']) ? [
            'email' => $order['billing']['email'], 'firstname' => $order['billing']['first_name'] ?? null, 'lastname' => $order['billing']['last_name'] ?? null,
            'phone' => $order['billing']['phone'] ?? null,
        ] : null;

        return ToolResult::ok([
            'order_id' => $order['id'], 'status' => $order['status'], 'total' => $order['total'], 'currency' => $order['currency'],
            'shipping_method' => $order['shipping_lines'][0]['method_title'] ?? null,
            'tracking_note' => $this->extractTrackingInfo($order), 'date_created' => $order['date_created'],
        ], "Commande #{$order['id']} : statut {$order['status']}", $identity);
    }

    private function searchOrdersByEmail(array $p, array $c): ToolResult
    {
        try {
            $res = $this->client($c)->get('orders', ['search' => $p['email'], 'per_page' => 5, 'orderby' => 'date', 'order' => 'desc']);
        } catch (RequestException $e) {
            throw new ConnectorUnavailableException($e->getMessage());
        }
        $this->recordSuccess();
        $orders = collect($res->json())->map(fn ($o) => ['order_id' => $o['id'], 'status' => $o['status'], 'total' => $o['total'], 'date_created' => $o['date_created']])->all();

        if (empty($orders)) return ToolResult::fail('not_found', "Aucune commande pour {$p['email']}.");
        return ToolResult::ok(['orders' => $orders], count($orders) . ' commande(s) trouvée(s)', ['email' => $p['email']]);
    }

    private function trackOrder(array $p, array $c): ToolResult
    {
        $result = $this->getOrderStatus($p, $c);
        if (!$result->success) return $result;

        $tracking = $result->data['tracking_note'] ?? null;
        return ToolResult::ok(
            ['order_id' => $result->data['order_id'], 'status' => $result->data['status'], 'tracking' => $tracking],
            $tracking ? "Suivi : {$tracking}" : "Pas encore de numéro de suivi disponible pour cette commande.",
            $result->identity,
        );
    }

    private function downloadInvoice(array $p, array $c): ToolResult
    {
        $template = config('mcp.connectors.woocommerce.invoice_url_template');
        if (!$template) {
            return ToolResult::fail('not_available', "La génération de facture n'est pas configurée pour ce site.");
        }
        $url = str_replace('{order_id}', $p['order_id'], $template);
        return ToolResult::ok(['invoice_url' => $url], 'Facture disponible.');
    }

    private function requestReturn(array $p, array $c): ToolResult
    {
        try {
            $this->client($c)->post("orders/{$p['order_id']}/notes", [
                'note' => "Demande de retour (via ELChat) : {$p['reason']}", 'customer_note' => false,
            ]);
        } catch (RequestException $e) {
            if ($e->response?->status() === 404) return ToolResult::fail('not_found', 'Commande introuvable.');
            throw new ConnectorUnavailableException($e->getMessage());
        }
        $this->recordSuccess();
        return ToolResult::ok(['order_id' => $p['order_id']], "Demande de retour enregistrée pour la commande #{$p['order_id']}, un conseiller va l'examiner.");
    }

    private function updateShippingAddress(array $p, array $c): ToolResult
    {
        try {
            $order = $this->client($c)->get("orders/{$p['order_id']}")->json();
        } catch (RequestException $e) {
            if ($e->response?->status() === 404) return ToolResult::fail('not_found', 'Commande introuvable.');
            throw new ConnectorUnavailableException($e->getMessage());
        }

        if (in_array($order['status'], ['completed', 'shipped', 'cancelled', 'refunded'])) {
            return ToolResult::fail('not_editable', 'Cette commande a déjà été expédiée, son adresse ne peut plus être modifiée.');
        }

        try {
            $this->client($c)->put("orders/{$p['order_id']}", [
                'shipping' => array_filter([
                    'address_1' => $p['address_1'] ?? null, 'city' => $p['city'] ?? null, 'postcode' => $p['postcode'] ?? null, 'country' => $p['country'] ?? null,
                ]),
            ]);
        } catch (RequestException $e) {
            throw new ConnectorUnavailableException($e->getMessage());
        }
        $this->recordSuccess();
        return ToolResult::ok(['order_id' => $p['order_id']], 'Adresse de livraison mise à jour.');
    }

    private function cancelOrder(array $p, array $c): ToolResult
    {
        try {
            $this->client($c)->put("orders/{$p['order_id']}", ['status' => 'cancelled']);
        } catch (RequestException $e) {
            if ($e->response?->status() === 404) return ToolResult::fail('not_found', "Commande {$p['order_id']} introuvable.");
            throw new ConnectorUnavailableException($e->getMessage());
        }
        $this->recordSuccess();
        return ToolResult::ok(['order_id' => $p['order_id'], 'status' => 'cancelled'], "Commande #{$p['order_id']} annulée.");
    }

    private function extractTrackingInfo(array $order): ?string
    {
        foreach ($order['meta_data'] ?? [] as $meta) {
            if (str_contains(strtolower($meta['key']), 'tracking')) return (string) $meta['value'];
        }
        return null;
    }

    // =====================================================================
    // ❤️ WISHLIST (local, visiteur, auto)
    // =====================================================================

    private function wishlistTools(): array
    {
        return [
            new ToolSchema('woocommerce', 'add_to_wishlist',
                "Ajoute un produit à la liste de souhaits du visiteur.

Utiliser cet outil uniquement lorsque l'utilisateur exprime clairement son intention de sauvegarder un produit pour plus tard, de le retrouver ultérieurement ou de le comparer plus tard.

Avant l'appel :

- vérifier que le produit concerné est clairement identifié ;
- si plusieurs produits correspondent à la demande, demander lequel choisir ;
- si le produit possède des variantes et qu'aucune variante n'a encore été sélectionnée alors qu'elle est nécessaire (taille, couleur, etc.), demander la variante avant d'ajouter le produit.

Ne jamais inventer un product_id ni un variation_id.

Si le produit est déjà présent dans la liste de souhaits, éviter un nouvel ajout et informer simplement l'utilisateur.

Ne pas utiliser cet outil pour ajouter un article au panier.

Après succès, confirmer que le produit a bien été ajouté à la liste de souhaits.", [
                'type' => 'object', 'properties' => ['product_id' => ['type' => 'string'], 'variation_id' => ['type' => 'string']], 'required' => ['product_id'],
            ], defaultMode: 'auto'),
            new ToolSchema('woocommerce', 'remove_from_wishlist',
                "Retire un produit de la liste de souhaits du visiteur.

Utiliser uniquement lorsque l'utilisateur souhaite supprimer un produit enregistré.

Avant l'appel :

- identifier précisément le produit ;
- si plusieurs produits correspondent, demander lequel retirer ;
- si plusieurs variantes du même produit existent dans la liste, identifier la bonne variante.

Ne jamais supprimer un produit sans certitude.

Ne jamais supprimer plusieurs éléments si un seul est demandé.

Si le produit n'existe pas dans la liste de souhaits, éviter l'appel inutile et informer l'utilisateur.

Après succès, confirmer la suppression.", [
                'type' => 'object', 'properties' => ['product_id' => ['type' => 'string'], 'variation_id' => ['type' => 'string']], 'required' => ['product_id'],
            ], defaultMode: 'auto'),
            new ToolSchema('woocommerce', 'get_wishlist',
                "Retourne la liste actuelle des produits enregistrés dans la liste de souhaits du visiteur.

Utiliser cet outil lorsque l'utilisateur souhaite :

- consulter sa wishlist ;
- retrouver un produit enregistré ;
- voir les produits sauvegardés ;
- choisir un produit à ajouter au panier ;
- gérer sa liste de souhaits.

Ne pas utiliser cet outil si la conversation contient déjà une version récente de la wishlist et qu'aucune modification n'a eu lieu depuis.

Présenter les résultats de manière claire et structurée.

Si la liste est vide, informer simplement l'utilisateur et ne rien inventer.

Ne jamais déduire la présence d'un produit sans utiliser cet outil.", ['type' => 'object', 'properties' => []], defaultMode: 'auto'),
        ];
    }

    private function addToWishlist(array $p, array $ctx): ToolResult
    {
        $wl = $this->wishlists->find($ctx['site_id'], $ctx['owner_type'], $ctx['owner_id']);
        $this->wishlists->add($wl, $p['product_id'], $p['variation_id'] ?? null);
        return ToolResult::ok(['wishlist' => $wl->refresh()->items], 'Ajouté à la liste de souhaits.');
    }

    private function removeFromWishlist(array $p, array $ctx): ToolResult
    {
        $wl = $this->wishlists->find($ctx['site_id'], $ctx['owner_type'], $ctx['owner_id']);
        $this->wishlists->remove($wl, $p['product_id'], $p['variation_id'] ?? null);
        return ToolResult::ok(['wishlist' => $wl->refresh()->items], 'Retiré de la liste de souhaits.');
    }

    private function getWishlist(array $ctx): ToolResult
    {
        $wl = $this->wishlists->find($ctx['site_id'], $ctx['owner_type'], $ctx['owner_id']);
        if (empty($wl->items)) return ToolResult::fail('empty', 'La liste de souhaits est vide.');
        return ToolResult::ok(['wishlist' => $wl->items], count($wl->items) . ' article(s)');
    }

    // =====================================================================
    // 👤 COMPTE CLIENT
    // =====================================================================

    private function accountTools(): array
    {
        return [
            new ToolSchema('woocommerce', 'create_customer',
                "Crée un nouveau compte client WooCommerce à partir des informations fournies. Vérifier auparavant qu'aucun compte n'existe déjà pour cette adresse email.", [
                'type' => 'object', 'properties' => ['email' => ['type' => 'string'], 'firstname' => ['type' => 'string'], 'lastname' => ['type' => 'string'], 'phone' => ['type' => 'string']], 'required' => ['email'],
            ], isWriteAction: true, defaultMode: 'auto', capability: 'commerce.create_account'),
            new ToolSchema('woocommerce', 'find_customer',
                "Vérifie si une adresse email correspond à un compte client existant. Pour des raisons de confidentialité, ne jamais révéler d'informations personnelles autres que celles explicitement retournées par l'outil.", [
                'type' => 'object', 'properties' => ['email' => ['type' => 'string']], 'required' => ['email'],
            ], defaultMode: 'auto'),
            new ToolSchema('woocommerce',
                'update_customer', "Met à jour les informations du compte actuellement identifié. Utiliser uniquement après identification du client et confirmation explicite des modifications.", [
                'type' => 'object', 'properties' => ['firstname' => ['type' => 'string'], 'lastname' => ['type' => 'string'], 'phone' => ['type' => 'string']],
            ], isWriteAction: true, defaultMode: 'confirm', defaultConfirmActor: 'visitor'),
        ];
    }

    private function createCustomer(array $p, array $c, array $ctx): ToolResult
    {
        try {
            $customer = $this->client($c)->post('customers', [
                'email' => $p['email'], 'first_name' => $p['firstname'] ?? '', 'last_name' => $p['lastname'] ?? '',
            ])->json();
        } catch (RequestException $e) {
            if ($e->response?->status() === 400) return ToolResult::fail('already_exists', 'Un compte existe déjà avec cet email.');
            throw new ConnectorUnavailableException($e->getMessage());
        }
        $this->recordSuccess();

        if (!empty($ctx['owner_id']) && $ctx['owner_type'] === 'user') {
            McpCustomerLink::updateOrCreate(
                ['site_id' => $ctx['site_id'], 'user_id' => $ctx['owner_id'], 'connector_slug' => 'woocommerce'],
                ['external_customer_id' => (string) $customer['id']]
            );
        }

        return ToolResult::ok(
            ['customer_id' => $customer['id'], 'email' => $customer['email']],
            'Compte créé avec succès.',
            ['email' => $p['email'], 'firstname' => $p['firstname'] ?? null, 'lastname' => $p['lastname'] ?? null, 'phone' => $p['phone'] ?? null],
        );
    }

    private function findCustomer(array $p, array $c): ToolResult
    {
        try {
            $res = $this->client($c)->get('customers', ['email' => $p['email']]);
        } catch (RequestException $e) {
            throw new ConnectorUnavailableException($e->getMessage());
        }
        $this->recordSuccess();
        $customer = $res->json()[0] ?? null;

        // 🔒 Volontairement minimal : ne confirme QUE l'existence + prénom,
        // jamais l'adresse/téléphone d'un tiers non authentifié.
        if (!$customer) {
            return ToolResult::ok(['exists' => false], "Aucun compte n'existe avec cet email.");
        }
        return ToolResult::ok(['exists' => true, 'firstname' => $customer['first_name']], 'Un compte existe avec cet email.', ['email' => $p['email']]);
    }

    private function updateCustomer(array $p, array $c, array $ctx): ToolResult
    {
        $link = McpCustomerLink::where('site_id', $ctx['site_id'])->where('connector_slug', 'woocommerce')
            ->where('user_id', $ctx['owner_type'] === 'user' ? $ctx['owner_id'] : null)->first();

        if (!$link) {
            return ToolResult::fail('not_identified', "Identifiez-vous d'abord (email) avant de modifier votre compte.");
        }

        try {
            $this->client($c)->put("customers/{$link->external_customer_id}", array_filter([
                'first_name' => $p['firstname'] ?? null, 'last_name' => $p['lastname'] ?? null,
                'billing' => !empty($p['phone']) ? ['phone' => $p['phone']] : null,
            ]));
        } catch (RequestException $e) {
            throw new ConnectorUnavailableException($e->getMessage());
        }
        $this->recordSuccess();
        return ToolResult::ok([], 'Informations du compte mises à jour.');
    }

    // =====================================================================
    // ⭐ AVIS
    // =====================================================================

    private function reviewTools(): array
    {
        return [
            new ToolSchema('woocommerce', 'create_review',
                "Publie un avis sur un produit. L'avis peut être soumis à une modération avant publication. Ne jamais prétendre qu'il est déjà visible publiquement.", [
                'type' => 'object', 'properties' => ['product_id' => ['type' => 'string'], 'rating' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5], 'comment' => ['type' => 'string'], 'reviewer_name' => ['type' => 'string'], 'reviewer_email' => ['type' => 'string']], 'required' => ['product_id', 'rating', 'comment'],
            ], defaultMode: 'auto'),
            new ToolSchema('woocommerce', 'get_reviews',
                "Récupère les avis réellement publiés sur un produit. Utiliser exclusivement les commentaires retournés par l'outil.", [
                'type' => 'object', 'properties' => ['product_id' => ['type' => 'string']], 'required' => ['product_id'],
            ], defaultMode: 'auto'),
        ];
    }

    private function createReview(array $p, array $c): ToolResult
    {
        try {
            $this->client($c)->post('products/reviews', [
                'product_id' => (int) $p['product_id'], 'review' => $p['comment'], 'reviewer' => $p['reviewer_name'] ?? 'Visiteur',
                'reviewer_email' => $p['reviewer_email'] ?? 'anonymous@site.local', 'rating' => (int) $p['rating'],
            ]);
        } catch (RequestException $e) {
            throw new ConnectorUnavailableException($e->getMessage());
        }
        $this->recordSuccess();
        return ToolResult::ok([], 'Avis envoyé, en attente de modération.');
    }

    private function getReviews(array $p, array $c): ToolResult
    {
        try {
            $res = $this->client($c)->get('products/reviews', ['product' => $p['product_id'], 'per_page' => 10]);
        } catch (RequestException $e) {
            throw new ConnectorUnavailableException($e->getMessage());
        }
        $this->recordSuccess();
        $reviews = collect($res->json())->map(fn ($r) => ['rating' => $r['rating'], 'review' => strip_tags($r['review']), 'reviewer' => $r['reviewer']])->all();
        if (empty($reviews)) return ToolResult::fail('not_found', 'Aucun avis pour ce produit.');
        return ToolResult::ok(['reviews' => $reviews], count($reviews) . ' avis');
    }

    // =====================================================================
    // 🚚 LIVRAISON (best-effort, simplifié)
    // =====================================================================

    private function shippingTools(): array
    {
        return [
            new ToolSchema('woocommerce', 'get_shipping_methods',
                "Retourne les méthodes de livraison configurées pour la boutique. Ne jamais déduire les modes de livraison sans interroger l'outil.", ['type' => 'object', 'properties' => []], defaultMode: 'auto'),
            new ToolSchema('woocommerce', 'estimate_shipping',
                "Estime les frais de livraison en fonction du pays fourni et de la configuration actuelle de la boutique. Cette estimation peut varier selon le contenu réel du panier et ne constitue pas un montant garanti.", [
                'type' => 'object', 'properties' => ['country' => ['type' => 'string'], 'postcode' => ['type' => 'string']], 'required' => ['country'],
            ], defaultMode: 'auto'),
            new ToolSchema('woocommerce', 'track_package',
                "Retourne les informations de suivi disponibles pour le colis associé à une commande lorsque celles-ci existent.", [
                'type' => 'object', 'properties' => ['order_id' => ['type' => 'string']], 'required' => ['order_id'],
            ], defaultMode: 'auto'),
        ];
    }

    private function getShippingMethods(array $c): ToolResult
    {
        try {
            $zones = $this->client($c)->get('shipping/zones')->json();
        } catch (RequestException $e) {
            throw new ConnectorUnavailableException($e->getMessage());
        }
        $this->recordSuccess();
        $methods = [];
        foreach ($zones as $zone) {
            try {
                $zoneMethods = $this->client($c)->get("shipping/zones/{$zone['id']}/methods")->json();
                foreach ($zoneMethods as $m) {
                    $methods[] = ['zone' => $zone['name'], 'method' => $m['title'], 'enabled' => $m['enabled']];
                }
            } catch (RequestException) {
                continue;
            }
        }
        if (empty($methods)) return ToolResult::fail('not_found', 'Aucune méthode de livraison configurée.');
        return ToolResult::ok(['methods' => $methods], count($methods) . ' méthode(s) de livraison');
    }

    private function estimateShipping(array $p, array $c): ToolResult
    {
        // Simplification volontaire : WooCommerce n'expose pas de moteur de
        // calcul de frais via REST v3. On approxime via la zone correspondant
        // au pays et son tarif fixe s'il existe.
        try {
            $zones = $this->client($c)->get('shipping/zones')->json();
        } catch (RequestException $e) {
            throw new ConnectorUnavailableException($e->getMessage());
        }
        $this->recordSuccess();

        foreach ($zones as $zone) {
            try {
                $locations = $this->client($c)->get("shipping/zones/{$zone['id']}/locations")->json();
            } catch (RequestException) {
                continue;
            }
            $matches = collect($locations)->contains(fn ($l) => strtoupper($l['code'] ?? '') === strtoupper($p['country']));
            if ($matches) {
                $methods = $this->client($c)->get("shipping/zones/{$zone['id']}/methods")->json();
                $flat = collect($methods)->firstWhere('method_id', 'flat_rate');
                $cost = $flat['settings']['cost']['value'] ?? null;
                return ToolResult::ok(['estimated_cost' => $cost, 'zone' => $zone['name']], $cost ? "Frais estimés : {$cost}" : "Frais variables selon le panier.");
            }
        }
        return ToolResult::fail('not_found', "Pas de zone de livraison configurée pour {$p['country']}.");
    }

    // =====================================================================
    // 🔒 ADMIN UNIQUEMENT
    // =====================================================================

    private function adminTools(): array
    {
        return [
            new ToolSchema('woocommerce', 'issue_refund',
                "Exécute un remboursement réel sur une commande. Cette opération a un impact financier irréversible. Utiliser uniquement après toutes les validations requises et ne jamais l'exécuter plusieurs fois pour une même demande.", [
                'type' => 'object', 'properties' => ['order_id' => ['type' => 'string'], 'amount' => ['type' => 'number'], 'reason' => ['type' => 'string']], 'required' => ['order_id', 'amount'],
            ], isWriteAction: true, defaultActorScope: 'admin', defaultMode: 'auto'),
            new ToolSchema('woocommerce', 'update_order_status',
                "Modifie le statut d'une commande existante. Vérifier que le nouveau statut est autorisé par le workflow métier avant l'exécution.", [
                'type' => 'object', 'properties' => ['order_id' => ['type' => 'string'], 'status' => ['type' => 'string']], 'required' => ['order_id', 'status'],
            ], isWriteAction: true, defaultActorScope: 'admin', defaultMode: 'auto'),
            new ToolSchema('woocommerce', 'adjust_stock',
                "Met à jour le stock réel d'un produit ou d'une variante. Cette opération affecte immédiatement la disponibilité des produits. Utiliser uniquement lorsque la nouvelle quantité est connue avec certitude.", [
                'type' => 'object', 'properties' => ['product_id' => ['type' => 'string'], 'variation_id' => ['type' => 'string'], 'stock_quantity' => ['type' => 'integer']], 'required' => ['product_id', 'stock_quantity'],
            ], isWriteAction: true, defaultActorScope: 'admin', defaultMode: 'auto'),
            new ToolSchema('woocommerce', 'update_product_price',
                "Met à jour le prix réel d'un produit ou d'une variante. Vérifier les nouvelles valeurs avant exécution afin d'éviter toute modification tarifaire involontaire.", [
                'type' => 'object', 'properties' => ['product_id' => ['type' => 'string'], 'variation_id' => ['type' => 'string'], 'regular_price' => ['type' => 'string'], 'sale_price' => ['type' => 'string']], 'required' => ['product_id', 'regular_price'],
            ], isWriteAction: true, defaultActorScope: 'admin', defaultMode: 'auto'),
        ];
    }

    private function issueRefund(array $p, array $c): ToolResult
    {
        try {
            $refund = $this->client($c)->post("orders/{$p['order_id']}/refunds", array_filter([
                'amount' => isset($p['amount']) ? (string) $p['amount'] : null, 'reason' => $p['reason'] ?? null,
            ]))->json();
        } catch (RequestException $e) {
            if ($e->response?->status() === 404) return ToolResult::fail('not_found', 'Commande introuvable.');
            throw new ConnectorUnavailableException($e->getMessage());
        }
        $this->recordSuccess();
        return ToolResult::ok(['refund_id' => $refund['id'] ?? null, 'amount' => $refund['amount'] ?? $p['amount'] ?? null], "Remboursement effectué pour la commande #{$p['order_id']}.");
    }

    private function updateOrderStatus(array $p, array $c): ToolResult
    {
        try {
            $this->client($c)->put("orders/{$p['order_id']}", ['status' => $p['status']]);
        } catch (RequestException $e) {
            throw new ConnectorUnavailableException($e->getMessage());
        }
        $this->recordSuccess();
        return ToolResult::ok(['order_id' => $p['order_id'], 'status' => $p['status']], "Statut mis à jour : {$p['status']}.");
    }

    private function adjustStock(array $p, array $c): ToolResult
    {
        try {
            $endpoint = !empty($p['variation_id']) ? "products/{$p['product_id']}/variations/{$p['variation_id']}" : "products/{$p['product_id']}";
            $this->client($c)->put($endpoint, ['manage_stock' => true, 'stock_quantity' => (int) $p['stock_quantity']]);
        } catch (RequestException $e) {
            throw new ConnectorUnavailableException($e->getMessage());
        }
        $this->recordSuccess();
        return ToolResult::ok(['product_id' => $p['product_id'], 'stock_quantity' => $p['stock_quantity']], 'Stock ajusté.');
    }

    private function updateProductPrice(array $p, array $c): ToolResult
    {
        try {
            $endpoint = !empty($p['variation_id']) ? "products/{$p['product_id']}/variations/{$p['variation_id']}" : "products/{$p['product_id']}";
            $this->client($c)->put($endpoint, array_filter(['regular_price' => $p['regular_price'] ?? null, 'sale_price' => $p['sale_price'] ?? null]));
        } catch (RequestException $e) {
            throw new ConnectorUnavailableException($e->getMessage());
        }
        $this->recordSuccess();
        return ToolResult::ok(['product_id' => $p['product_id']], 'Prix mis à jour.');
    }

    private function client(array $credentials)
    {
        $storeUrl = rtrim($credentials['store_url'] ?? '', '/');
        return $this->http("{$storeUrl}/wp-json/wc/v3/")->withBasicAuth($credentials['consumer_key'], $credentials['consumer_secret']);
    }
}
