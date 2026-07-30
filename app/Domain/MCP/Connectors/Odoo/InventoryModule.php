<?php

namespace App\Domain\MCP\Connectors\Odoo;

use App\Domain\MCP\Contracts\{ToolResult, ToolSchema};
use App\Domain\MCP\Exceptions\ToolNotFoundException;

class InventoryModule implements OdooModuleInterface
{
    public function technicalModuleName(): string { return 'stock'; }

    public function listTools(): array
    {
        return [
            new ToolSchema('odoo', 'inventory_check_stock', "Vérifie la quantité disponible d'un produit.", [
                'type' => 'object', 'properties' => ['product_id' => ['type' => 'integer']], 'required' => ['product_id'],
            ], defaultMode: 'auto', capability: 'inventory.check_stock'),

            new ToolSchema('odoo', 'inventory_search_warehouses', "Liste les entrepôts configurés.", ['type' => 'object', 'properties' => []],
                defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('odoo', 'inventory_get_stock_moves', "Historique des mouvements de stock d'un produit.", [
                'type' => 'object', 'properties' => ['product_id' => ['type' => 'integer']], 'required' => ['product_id'],
            ], defaultActorScope: 'admin', defaultMode: 'auto'),
        ];
    }

    public function callTool(string $toolName, array $params, array $credentials, array $context, OdooClient $client): ToolResult
    {
        return match ($toolName) {
            'inventory_check_stock' => $this->checkStock($params, $client),
            'inventory_search_warehouses' => $this->searchWarehouses($client),
            'inventory_get_stock_moves' => $this->getStockMoves($params, $client),
            default => throw new ToolNotFoundException("Outil '{$toolName}' inconnu pour le module Inventory Odoo."),
        };
    }

    private function checkStock(array $p, OdooClient $client): ToolResult
    {
        $product = $client->read('product.product', (int) $p['product_id'], ['name', 'qty_available', 'virtual_available']);
        if (!$product) return ToolResult::fail('not_found', 'Produit introuvable.');
        return ToolResult::ok($product, $product['qty_available'] > 0 ? 'En stock' : 'Rupture de stock');
    }

    private function searchWarehouses(OdooClient $client): ToolResult
    {
        $rows = $client->searchRead('stock.warehouse', [], ['name', 'code'], 20);
        return ToolResult::ok(['warehouses' => $rows], count($rows) . ' entrepôt(s)');
    }

    private function getStockMoves(array $p, OdooClient $client): ToolResult
    {
        $rows = $client->searchRead('stock.move', [['product_id', '=', (int) $p['product_id']]], ['reference', 'product_uom_qty', 'state', 'date'], 15);
        if (empty($rows)) return ToolResult::fail('not_found', 'Aucun mouvement trouvé pour ce produit.');
        return ToolResult::ok(['moves' => $rows], count($rows) . ' mouvement(s)');
    }
}
