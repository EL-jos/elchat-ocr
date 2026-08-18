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
            new ToolSchema('odoo', 'inventory_check_stock',
                "Retourne les niveaux de stock actuels d'un produit identifié de manière unique, y compris les quantités disponibles et, lorsque l'ERP les fournit, les quantités réservées, prévues ou virtuelles. Utiliser uniquement lorsque le produit est déjà identifié (product_id) ou après une recherche ayant permis d'identifier un seul produit. Si plusieurs produits correspondent ou si le produit ne peut pas être identifié de manière certaine, demander une clarification avant l'appel. Cet outil sert à consulter l'état actuel du stock et ne doit pas être utilisé pour analyser l'historique des mouvements ou rechercher des produits. Ne jamais inventer une quantité, une disponibilité ou un identifiant produit ; utiliser exclusivement les données retournées par l'ERP.", [
                'type' => 'object', 'properties' => ['product_id' => ['type' => 'integer']], 'required' => ['product_id'],
            ], defaultMode: 'auto', capability: 'inventory.check_stock'),

            new ToolSchema('odoo', 'inventory_search_warehouses',
                "Retourne la liste des entrepôts configurés dans Odoo avec leurs informations principales. Utiliser lorsque l'utilisateur souhaite consulter les entrepôts disponibles ou lorsqu'une opération logistique nécessite d'identifier un entrepôt. Cet outil ne retourne ni les produits ni les niveaux de stock.", ['type' => 'object', 'properties' => []],
                defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('odoo', 'inventory_get_stock_moves',
                "Retourne l'historique des mouvements de stock d'un produit identifié de manière unique (entrées, sorties et mouvements internes selon les données disponibles). Utiliser lorsque l'utilisateur souhaite analyser les mouvements ou comprendre l'évolution du stock. Ne pas utiliser pour connaître uniquement la disponibilité actuelle ; utiliser inventory_check_stock dans ce cas. Ne jamais tirer de conclusion sur le stock actuel à partir des seuls mouvements si cette information n'est pas explicitement retournée.", [
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
