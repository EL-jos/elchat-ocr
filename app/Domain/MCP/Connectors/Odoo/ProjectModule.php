<?php

namespace App\Domain\MCP\Connectors\Odoo;

use App\Domain\MCP\Contracts\{ToolResult, ToolSchema};
use App\Domain\MCP\Exceptions\ToolNotFoundException;

class ProjectModule implements OdooModuleInterface
{
    public function technicalModuleName(): string { return 'project'; }

    public function listTools(): array
    {
        return [
            new ToolSchema('odoo', 'project_create_task', "Crée une tâche projet.", [
                'type' => 'object', 'properties' => ['name' => ['type' => 'string'], 'project_id' => ['type' => 'integer'], 'description' => ['type' => 'string'], 'due_date' => ['type' => 'string']], 'required' => ['name'],
            ], isWriteAction: true, defaultMode: 'auto', capability: 'crm.create_task'),

            new ToolSchema('odoo', 'project_update_task', "Modifie une tâche existante.", [
                'type' => 'object', 'properties' => ['task_id' => ['type' => 'integer'], 'name' => ['type' => 'string'], 'description' => ['type' => 'string']], 'required' => ['task_id'],
            ], isWriteAction: true, defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('odoo', 'project_list_tasks', "Liste les tâches d'un projet.", [
                'type' => 'object', 'properties' => ['project_id' => ['type' => 'integer']], 'required' => ['project_id'],
            ], defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('odoo', 'project_search_tasks', "Recherche libre de tâches.", [
                'type' => 'object', 'properties' => ['query' => ['type' => 'string']], 'required' => ['query'],
            ], defaultActorScope: 'admin', defaultMode: 'auto'),
        ];
    }

    public function callTool(string $toolName, array $params, array $credentials, array $context, OdooClient $client): ToolResult
    {
        return match ($toolName) {
            'project_create_task' => $this->createTask($params, $client),
            'project_update_task' => $this->updateTask($params, $client),
            'project_list_tasks' => $this->listTasks($params, $client),
            'project_search_tasks' => $this->searchTasks($params, $client),
            default => throw new ToolNotFoundException("Outil '{$toolName}' inconnu pour le module Project Odoo."),
        };
    }

    private function createTask(array $p, OdooClient $client): ToolResult
    {
        $id = $client->create('project.task', array_filter([
            'name' => $p['name'], 'project_id' => $p['project_id'] ?? null, 'description' => $p['description'] ?? null,
            'date_deadline' => !empty($p['due_date']) ? substr($p['due_date'], 0, 10) : null,
        ]));
        return ToolResult::ok(['task_id' => $id], "Tâche « {$p['name']} » créée.");
    }

    private function updateTask(array $p, OdooClient $client): ToolResult
    {
        $client->write('project.task', (int) $p['task_id'], array_filter(['name' => $p['name'] ?? null, 'description' => $p['description'] ?? null]));
        return ToolResult::ok(['task_id' => $p['task_id']], 'Tâche mise à jour.');
    }

    private function listTasks(array $p, OdooClient $client): ToolResult
    {
        $rows = $client->searchRead('project.task', [['project_id', '=', (int) $p['project_id']]], ['name', 'stage_id'], 20);
        if (empty($rows)) return ToolResult::fail('not_found', 'Aucune tâche trouvée pour ce projet.');
        return ToolResult::ok(['tasks' => $rows], count($rows) . ' tâche(s)');
    }

    private function searchTasks(array $p, OdooClient $client): ToolResult
    {
        $rows = $client->searchRead('project.task', [['name', 'ilike', $p['query']]], ['name', 'stage_id', 'project_id'], 10);
        if (empty($rows)) return ToolResult::fail('not_found', 'Aucune tâche trouvée.');
        return ToolResult::ok(['tasks' => $rows], count($rows) . ' tâche(s) trouvée(s)');
    }
}
