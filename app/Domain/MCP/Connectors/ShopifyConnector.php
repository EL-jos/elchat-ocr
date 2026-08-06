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
            new ToolSchema('shopify', 'search_products',
                "Recherche un ou plusieurs produits Shopify.

À utiliser lorsque l'utilisateur souhaite :

• trouver un produit
• rechercher par mot-clé
• rechercher une catégorie
• rechercher une marque
• rechercher une collection
• trouver un produit selon un besoin
• obtenir des recommandations
• comparer plusieurs produits

Bonnes pratiques :

- Comprendre l'intention de l'utilisateur avant la recherche.
- Retourner uniquement les produits les plus pertinents.
- Présenter le nom, le prix, la disponibilité et la variante principale lorsqu'elle existe.
- Si plusieurs produits correspondent, les classer du plus pertinent au moins pertinent.
- Si aucun produit n'est trouvé, l'indiquer clairement et proposer une recherche alternative.
- Ne jamais inventer un produit inexistant.", [
                'type' => 'object', 'properties' => ['query' => ['type' => 'string']], 'required' => ['query'],
            ], defaultMode: 'auto', capability: 'commerce.search_products'),

            new ToolSchema('shopify', 'get_product',
                "Récupère les informations détaillées d'un produit Shopify.

À utiliser lorsque l'utilisateur souhaite :

• consulter un produit
• connaître les caractéristiques
• connaître le prix
• consulter les variantes
• voir les tailles
• voir les couleurs
• vérifier les options disponibles

Bonnes pratiques :

- Retourner toutes les variantes disponibles.
- Expliquer clairement les différences entre les variantes.
- Mettre en évidence les promotions lorsqu'elles existent.
- Indiquer la disponibilité du stock.
- Ne jamais masquer une rupture de stock.", [
                'type' => 'object', 'properties' => ['product_id' => ['type' => 'string']], 'required' => ['product_id'],
            ], defaultMode: 'auto', capability: 'commerce.search_products'),

            new ToolSchema('shopify', 'get_product_stock',
                "Vérifie la disponibilité d'un produit ou d'une variante.

À utiliser lorsque l'utilisateur souhaite :

• vérifier le stock
• savoir si une taille est disponible
• savoir si une couleur est disponible
• connaître les quantités restantes

Bonnes pratiques :

- Vérifier la variante demandée lorsqu'elle est précisée.
- Si aucune variante n'est fournie, utiliser la variante par défaut.
- Expliquer clairement si le produit est disponible.
- Si le produit est en rupture, proposer une alternative lorsque cela est possible.", [
                'type' => 'object', 'properties' => ['product_id' => ['type' => 'string'], 'variant_id' => ['type' => 'string']], 'required' => ['product_id'],
            ], defaultMode: 'auto'),

            new ToolSchema('shopify', 'add_to_cart',
                "Ajoute un produit au panier du visiteur.

À utiliser lorsque l'utilisateur souhaite :

• acheter un produit
• ajouter un article
• préparer une commande

Bonnes pratiques :

- Vérifier que le produit existe.
- Vérifier que la variante existe.
- Vérifier le stock avant l'ajout.
- Si plusieurs variantes existent et qu'aucune n'est choisie, demander la couleur, la taille ou l'option souhaitée avant d'ajouter au panier.
- Ajouter uniquement la quantité demandée.
- Confirmer clairement le contenu ajouté.
- Ne jamais ajouter automatiquement une variante incorrecte.", [
                'type' => 'object', 'properties' => [
                    'product_id' => ['type' => 'string'], 'variant_id' => ['type' => 'string'], 'quantity' => ['type' => 'integer'],
                ], 'required' => ['product_id'],
            ], defaultMode: 'auto', capability: 'commerce.manage_cart'),

            new ToolSchema('shopify', 'remove_from_cart',
                "Retire un article du panier.

À utiliser lorsque l'utilisateur souhaite :

• supprimer un produit
• retirer un article
• annuler un ajout

Bonnes pratiques :

- Vérifier que l'article est présent.
- Supprimer uniquement l'article demandé.
- Confirmer la suppression.
- Si l'article est absent, l'indiquer clairement.", [
                'type' => 'object', 'properties' => ['product_id' => ['type' => 'string'], 'variant_id' => ['type' => 'string']], 'required' => ['product_id'],
            ], defaultMode: 'auto', capability: 'commerce.manage_cart'),

            new ToolSchema('shopify', 'update_cart_quantity',
                "Met à jour la quantité d'un article dans le panier.

À utiliser lorsque l'utilisateur souhaite :

• modifier une quantité
• augmenter une quantité
• diminuer une quantité

Bonnes pratiques :

- Vérifier que l'article est présent.
- Vérifier la disponibilité du stock.
- Si la quantité demandée dépasse le stock, informer l'utilisateur.
- Confirmer la nouvelle quantité.", [
                'type' => 'object', 'properties' => ['product_id' => ['type' => 'string'], 'variant_id' => ['type' => 'string'], 'quantity' => ['type' => 'integer']], 'required' => ['product_id', 'quantity'],
            ], defaultMode: 'auto', capability: 'commerce.manage_cart'),

            new ToolSchema('shopify', 'get_cart',
                "Affiche le contenu actuel du panier.

À utiliser lorsque l'utilisateur souhaite :

• consulter son panier
• vérifier sa commande
• connaître le total avant paiement

Bonnes pratiques :

- Présenter chaque article clairement.
- Afficher les quantités.
- Afficher les prix unitaires.
- Afficher le sous-total.
- Si le panier est vide, l'indiquer clairement.", ['type' => 'object', 'properties' => []], defaultMode: 'auto', capability: 'commerce.manage_cart'),
            new ToolSchema('shopify', 'clear_cart',
                "Vide complètement le panier.

À utiliser lorsque l'utilisateur souhaite :

• recommencer ses achats
• supprimer tous les articles
• annuler le panier

Bonnes pratiques :

- Vérifier que le panier contient des articles.
- Confirmer que le panier est désormais vide.", ['type' => 'object', 'properties' => []], defaultMode: 'auto', capability: 'commerce.manage_cart'),

            new ToolSchema('shopify', 'generate_checkout',
                "Génère un lien de paiement Shopify.

À utiliser lorsque l'utilisateur souhaite :

• passer commande
• payer son panier
• finaliser son achat

Bonnes pratiques :

- Vérifier que le panier n'est pas vide.
- Vérifier que tous les produits sont encore disponibles.
- Générer le lien de paiement.
- Expliquer que le paiement sera effectué sur Shopify.
- Ne jamais prétendre que le paiement est déjà effectué.
- Après création du checkout, fournir le lien au visiteur.", [
                'type' => 'object', 'properties' => ['email' => ['type' => 'string']],
            ], isWriteAction: true, defaultMode: 'auto', capability: 'commerce.checkout'),

            new ToolSchema('shopify', 'get_order_status',
                "Consulte le statut d'une commande Shopify.

À utiliser lorsque l'utilisateur souhaite :

• suivre une commande
• connaître son statut
• savoir si elle est expédiée
• vérifier le paiement

Bonnes pratiques :

- Expliquer le statut financier.
- Expliquer le statut d'expédition.
- Afficher le total de la commande.
- Si la commande est introuvable, l'indiquer clairement.
- Ne jamais divulguer les informations d'une autre commande.", [
                'type' => 'object', 'properties' => ['order_id' => ['type' => 'string']], 'required' => ['order_id'],
            ], defaultMode: 'auto'),

            new ToolSchema('shopify', 'create_customer',
                "Crée un nouveau compte client Shopify.

À utiliser lorsque l'utilisateur souhaite :

• créer un compte
• devenir client
• enregistrer ses informations

Bonnes pratiques :

- Vérifier que l'adresse email est valide.
- Vérifier qu'aucun compte n'existe déjà.
- Créer le compte avec les informations fournies.
- Informer clairement l'utilisateur du résultat.
- Ne jamais créer plusieurs comptes pour la même adresse email.", [
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
