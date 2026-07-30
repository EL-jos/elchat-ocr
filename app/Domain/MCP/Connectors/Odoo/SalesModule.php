<?php

namespace App\Domain\MCP\Connectors\Odoo;

use App\Domain\MCP\Contracts\{ToolResult, ToolSchema};
use App\Domain\MCP\Exceptions\ToolNotFoundException;

class SalesModule implements OdooModuleInterface
{
    public function technicalModuleName(): string { return 'sale'; }

    public function listTools(): array
    {
        return [
            new ToolSchema('odoo', 'sales_search_products', "Recherche des produits vendables.", [
                'type' => 'object', 'properties' => ['query' => ['type' => 'string']], 'required' => ['query'],
            ], defaultMode: 'auto', capability: 'commerce.search_products'),

            new ToolSchema('odoo', 'sales_get_product', "Détails d'un produit (prix, stock).", [
                'type' => 'object', 'properties' => ['product_id' => ['type' => 'integer']], 'required' => ['product_id'],
            ], defaultMode: 'auto'),

            new ToolSchema('odoo', 'sales_create_quotation', "Crée un devis pour un contact identifié.", [
                'type' => 'object', 'properties' => ['contact_email' => ['type' => 'string'], 'product_id' => ['type' => 'integer'], 'quantity' => ['type' => 'integer']], 'required' => ['contact_email', 'product_id'],
            ], isWriteAction: true, defaultMode: 'auto', capability: 'commerce.create_quote'),

            new ToolSchema('odoo', 'sales_get_order_status', "Statut d'une commande/devis.", [
                'type' => 'object', 'properties' => ['order_id' => ['type' => 'integer']], 'required' => ['order_id'],
            ], defaultMode: 'auto'),

            new ToolSchema('odoo', 'sales_confirm_order', "Confirme un devis en commande ferme.", [
                'type' => 'object', 'properties' => ['order_id' => ['type' => 'integer']], 'required' => ['order_id'],
            ], isWriteAction: true, defaultActorScope: 'admin', defaultMode: 'confirm', defaultConfirmActor: 'admin'),

            new ToolSchema('odoo', 'sales_search_orders', "Recherche libre de commandes/devis.", [
                'type' => 'object', 'properties' => ['query' => ['type' => 'string']], 'required' => ['query'],
            ], defaultActorScope: 'admin', defaultMode: 'auto'),
        ];
    }

    public function callTool(string $toolName, array $params, array $credentials, array $context, OdooClient $client): ToolResult
    {
        return match ($toolName) {
            'sales_search_products' => $this->searchProducts($params, $client),
            'sales_get_product' => $this->getProduct($params, $client),
            'sales_create_quotation' => $this->createQuotation($params, $client),
            'sales_get_order_status' => $this->getOrderStatus($params, $client),
            'sales_confirm_order' => $this->confirmOrder($params, $client),
            'sales_search_orders' => $this->searchOrders($params, $client),
            default => throw new ToolNotFoundException("Outil '{$toolName}' inconnu pour le module Sales Odoo."),
        };
    }

    private function searchProducts(array $p, OdooClient $client): ToolResult
    {
        $rows = $client->searchRead('product.product', [['name', 'ilike', $p['query']], ['sale_ok', '=', true]], ['name', 'list_price', 'qty_available'], 10);
        if (empty($rows)) return ToolResult::fail('not_found', 'Aucun produit trouvé.');
        return ToolResult::ok(['products' => $rows], count($rows) . ' produit(s) trouvé(s)');
    }

    private function getProduct(array $p, OdooClient $client): ToolResult
    {
        $product = $client->read('product.product', (int) $p['product_id'], ['name', 'list_price', 'qty_available', 'description_sale']);
        if (!$product) return ToolResult::fail('not_found', 'Produit introuvable.');
        return ToolResult::ok($product, "Produit : {$product['name']}");
    }

    private function createQuotation(array $p, OdooClient $client): ToolResult
    {
        $partner = $client->searchRead('res.partner', [['email', '=', $p['contact_email']]], ['id'], 1)[0] ?? null;
        if (!$partner) return ToolResult::fail('not_found', "Aucun contact trouvé pour {$p['contact_email']} — créez-le d'abord.");

        $orderId = $client->create('sale.order', [
            'partner_id' => $partner['id'],
            'order_line' => [[0, 0, ['product_id' => (int) $p['product_id'], 'product_uom_qty' => $p['quantity'] ?? 1]]],
        ]);
        return ToolResult::ok(['order_id' => $orderId], 'Devis créé.', identity: ['email' => $p['contact_email']]);
    }

    private function getOrderStatus(array $p, OdooClient $client): ToolResult
    {
        $order = $client->read('sale.order', (int) $p['order_id'], ['name', 'state', 'amount_total']);
        if (!$order) return ToolResult::fail('not_found', 'Commande introuvable.');
        return ToolResult::ok($order, "Commande {$order['name']} : {$order['state']}");
    }

    private function confirmOrder(array $p, OdooClient $client): ToolResult
    {
        $client->call('sale.order', 'action_confirm', [(int) $p['order_id']]);
        return ToolResult::ok(['order_id' => $p['order_id']], 'Commande confirmée.');
    }

    private function searchOrders(array $p, OdooClient $client): ToolResult
    {
        $rows = $client->searchRead('sale.order', [['name', 'ilike', $p['query']]], ['name', 'state', 'amount_total'], 10);
        if (empty($rows)) return ToolResult::fail('not_found', 'Aucune commande trouvée.');
        return ToolResult::ok(['orders' => $rows], count($rows) . ' commande(s) trouvée(s)');
    }
}
