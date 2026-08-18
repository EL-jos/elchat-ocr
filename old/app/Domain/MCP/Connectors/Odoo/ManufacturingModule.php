<?php

namespace App\Domain\MCP\Connectors\Odoo;

use App\Domain\MCP\Contracts\{ToolResult, ToolSchema};
use App\Domain\MCP\Exceptions\ToolNotFoundException;

class ManufacturingModule implements OdooModuleInterface
{
    public function technicalModuleName(): string { return 'mrp'; }

    public function listTools(): array
    {
        return [
            new ToolSchema('odoo', 'manufacturing_get_production_status',
                "Récupère les informations détaillées d'un ordre de fabrication identifié de manière unique, notamment son état, la quantité à produire et la date de démarrage planifiée. Utiliser lorsque le production_id est connu ou après une recherche ayant identifié un seul ordre. Si l'identifiant est inconnu, utiliser manufacturing_search_orders avant cet outil. Ne jamais inventer un identifiant d'ordre de fabrication.", [
                'type' => 'object', 'properties' => ['production_id' => ['type' => 'integer']], 'required' => ['production_id'],
            ], defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('odoo', 'manufacturing_search_orders',
                "Recherche des ordres de fabrication Odoo à partir d'un numéro, d'un nom ou d'un texte fourni par l'utilisateur. Utiliser lorsque l'utilisateur souhaite retrouver un ordre avant de consulter son état ou ses composants. Si plusieurs ordres correspondent, demander une clarification avant toute autre action. Ne jamais supposer qu'un ordre est unique ni inventer un production_id.", [
                'type' => 'object', 'properties' => ['query' => ['type' => 'string']], 'required' => ['query'],
            ], defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('odoo', 'manufacturing_check_component_availability',
                "Retourne les composants et leurs états associés à un ordre de fabrication identifié de manière unique afin d'évaluer la disponibilité des matières premières nécessaires à la production. Utiliser lorsque l'utilisateur souhaite vérifier si les composants requis sont prêts ou identifier d'éventuels blocages. Si l'ordre de fabrication n'est pas identifié de manière unique, effectuer une recherche puis demander une clarification avant de poursuivre. Ne pas utiliser cet outil pour consulter le stock global d'un produit ; utiliser les outils du module Inventory pour cela.", [
                'type' => 'object', 'properties' => ['production_id' => ['type' => 'integer']], 'required' => ['production_id'],
            ], defaultActorScope: 'admin', defaultMode: 'auto'),
        ];
    }

    public function callTool(string $toolName, array $params, array $credentials, array $context, OdooClient $client): ToolResult
    {
        return match ($toolName) {
            'manufacturing_get_production_status' => $this->getStatus($params, $client),
            'manufacturing_search_orders' => $this->searchOrders($params, $client),
            'manufacturing_check_component_availability' => $this->checkComponents($params, $client),
            default => throw new ToolNotFoundException("Outil '{$toolName}' inconnu pour le module Manufacturing Odoo."),
        };
    }

    private function getStatus(array $p, OdooClient $client): ToolResult
    {
        $mo = $client->read('mrp.production', (int) $p['production_id'], ['name', 'state', 'product_qty', 'date_planned_start']);
        if (!$mo) return ToolResult::fail('not_found', 'Ordre de fabrication introuvable.');
        return ToolResult::ok($mo, "Ordre {$mo['name']} : {$mo['state']}");
    }

    private function searchOrders(array $p, OdooClient $client): ToolResult
    {
        $rows = $client->searchRead('mrp.production', [['name', 'ilike', $p['query']]], ['name', 'state', 'product_qty'], 10);
        if (empty($rows)) return ToolResult::fail('not_found', 'Aucun ordre de fabrication trouvé.');
        return ToolResult::ok(['orders' => $rows], count($rows) . ' ordre(s) trouvé(s)');
    }

    private function checkComponents(array $p, OdooClient $client): ToolResult
    {
        $rows = $client->searchRead('stock.move', [['raw_material_production_id', '=', (int) $p['production_id']]], ['product_id', 'product_uom_qty', 'state'], 20);
        if (empty($rows)) return ToolResult::fail('not_found', 'Aucun composant trouvé pour cet ordre.');
        return ToolResult::ok(['components' => $rows], count($rows) . ' composant(s)');
    }
}
