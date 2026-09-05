<?php

namespace App\Domain\MCP\Connectors\Microsoft365;

use App\Domain\MCP\Contracts\ToolResult;
use App\Domain\MCP\Contracts\ToolSchema;
use App\Domain\MCP\Exceptions\ToolNotFoundException;
use App\Domain\Microsoft365\MicrosoftGraphClient;

final class ListsModule extends AbstractMicrosoft365Module
{
    public function key(): string { return 'lists'; }

    public function label(): string { return 'Microsoft Lists'; }

    public function iconUrl(): ?string { return 'https://upload.wikimedia.org/wikipedia/commons/2/28/Microsoft_Office_SharePoint_%282025%E2%80%93present%29.svg'; }

    /** @return ToolSchema[] */
    public function listTools(): array
    {
        return [
            $this->readTool('lists_list_lists', 'Liste les listes Microsoft Lists d’un site SharePoint.', ['site_id' => ['type' => 'string']], ['site_id'], 'lists.read'),
            $this->readTool('lists_get_list', 'Récupère les métadonnées d’une liste Microsoft Lists.', ['site_id' => ['type' => 'string'], 'list_id' => ['type' => 'string']], ['site_id', 'list_id'], 'lists.read'),
            $this->readTool('lists_list_items', 'Liste les éléments d’une liste Microsoft Lists avec leurs champs.', ['site_id' => ['type' => 'string'], 'list_id' => ['type' => 'string'], 'top' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200]], ['site_id', 'list_id'], 'lists.read'),
            $this->readTool('lists_get_item', 'Récupère un élément précis d’une liste Microsoft Lists.', ['site_id' => ['type' => 'string'], 'list_id' => ['type' => 'string'], 'item_id' => ['type' => 'string']], ['site_id', 'list_id', 'item_id'], 'lists.read'),
            $this->writeTool('lists_create_item', 'Crée un élément dans une liste Microsoft Lists après confirmation.', ['site_id' => ['type' => 'string'], 'list_id' => ['type' => 'string'], 'fields' => ['type' => 'object']], ['site_id', 'list_id', 'fields'], 'lists.create_item', 'confirm'),
            $this->writeTool('lists_update_item', 'Met à jour les champs d’un élément Microsoft Lists après confirmation.', ['site_id' => ['type' => 'string'], 'list_id' => ['type' => 'string'], 'item_id' => ['type' => 'string'], 'fields' => ['type' => 'object']], ['site_id', 'list_id', 'item_id', 'fields'], 'lists.update_item', 'confirm'),
            $this->writeTool('lists_delete_item', 'Supprime un élément Microsoft Lists après confirmation.', ['site_id' => ['type' => 'string'], 'list_id' => ['type' => 'string'], 'item_id' => ['type' => 'string']], ['site_id', 'list_id', 'item_id'], 'lists.delete_item', 'confirm'),
        ];
    }

    /** @return array<string, list<string>> */
    protected function requiredScopes(): array
    {
        return [
            'lists_list_lists' => ['Sites.Read.All'], 'lists_get_list' => ['Sites.Read.All'], 'lists_list_items' => ['Sites.Read.All'], 'lists_get_item' => ['Sites.Read.All'],
            'lists_create_item' => ['Sites.ReadWrite.All'], 'lists_update_item' => ['Sites.ReadWrite.All'], 'lists_delete_item' => ['Sites.ReadWrite.All'],
        ];
    }

    public function callTool(string $toolName, array $params, array $credentials, array $context, MicrosoftGraphClient $graph): ToolResult
    {
        return match ($toolName) {
            'lists_list_lists' => $this->listLists($graph, $params),
            'lists_get_list' => $this->getList($graph, $params),
            'lists_list_items' => $this->listItems($graph, $params),
            'lists_get_item' => $this->getItem($graph, $params),
            'lists_create_item' => $this->createItem($graph, $params),
            'lists_update_item' => $this->updateItem($graph, $params),
            'lists_delete_item' => $this->deleteItem($graph, $params),
            default => throw new ToolNotFoundException("Outil '{$toolName}' inconnu pour le module Microsoft Lists."),
        };
    }

    private function listLists(MicrosoftGraphClient $g, array $p): ToolResult
    {
        $lists = $g->collectPages($this->sitePath($p) . '/lists', ['$select' => 'id,name,displayName,webUrl,list,lastModifiedDateTime']);
        return ToolResult::ok(['lists' => $lists], count($lists) . ' liste(s) Microsoft Lists récupérée(s)');
    }

    private function getList(MicrosoftGraphClient $g, array $p): ToolResult
    {
        $list = $g->get($this->listPath($p), ['$expand' => 'columns($select=name,displayName,readOnly,hidden)']);
        return ToolResult::ok(['list' => $list], 'Liste Microsoft Lists récupérée.');
    }

    private function listItems(MicrosoftGraphClient $g, array $p): ToolResult
    {
        $items = $g->collectPages($this->listPath($p) . '/items', [
            '$top' => min(200, max(1, (int) ($p['top'] ?? 100))), '$expand' => 'fields',
        ]);
        return ToolResult::ok(['items' => $items], count($items) . ' élément(s) Microsoft Lists récupéré(s)');
    }

    private function getItem(MicrosoftGraphClient $g, array $p): ToolResult
    {
        $item = $g->get($this->listItemPath($p), ['$expand' => 'fields']);
        return ToolResult::ok(['item' => $item], 'Élément Microsoft Lists récupéré.');
    }

    private function createItem(MicrosoftGraphClient $g, array $p): ToolResult
    {
        $item = $g->post($this->listPath($p) . '/items', ['fields' => $p['fields']]);
        return ToolResult::ok(['item' => $item], 'Élément Microsoft Lists créé.');
    }

    private function updateItem(MicrosoftGraphClient $g, array $p): ToolResult
    {
        $item = $g->patch($this->listItemPath($p) . '/fields', $p['fields']);
        return ToolResult::ok(['item' => $item], 'Élément Microsoft Lists mis à jour.');
    }

    private function deleteItem(MicrosoftGraphClient $g, array $p): ToolResult
    {
        $g->delete($this->listItemPath($p));
        return ToolResult::ok(['item_id' => $p['item_id']], 'Élément Microsoft Lists supprimé.');
    }

    private function sitePath(array $p): string
    {
        return '/sites/' . $this->id($p['site_id']);
    }

    private function listPath(array $p): string
    {
        return $this->sitePath($p) . '/lists/' . $this->id($p['list_id']);
    }

    private function listItemPath(array $p): string
    {
        return $this->listPath($p) . '/items/' . $this->id($p['item_id']);
    }
}
