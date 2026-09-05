<?php

namespace App\Domain\MCP\Connectors\Microsoft365;

use App\Domain\MCP\Contracts\ToolResult;
use App\Domain\MCP\Contracts\ToolSchema;
use App\Domain\MCP\Exceptions\ToolNotFoundException;
use App\Domain\Microsoft365\MicrosoftGraphClient;

final class ToDoModule extends AbstractMicrosoft365Module
{
    public function key(): string { return 'todo'; }

    public function label(): string { return 'To Do'; }

    public function iconUrl(): ?string { return 'https://upload.wikimedia.org/wikipedia/commons/6/6e/Microsoft_To-Do_icon.svg'; }

    /** @return ToolSchema[] */
    public function listTools(): array
    {
        return [
            $this->readTool('todo_list_lists', 'Liste les listes personnelles Microsoft To Do.', [], [], 'todo.read'),
            $this->writeTool('todo_create_list', 'Crée une liste personnelle Microsoft To Do après confirmation.', ['display_name' => ['type' => 'string']], ['display_name'], 'todo.create_list', 'confirm'),
            $this->readTool('todo_list_tasks', 'Liste les tâches d’une liste Microsoft To Do.', ['list_id' => ['type' => 'string'], 'top' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]], ['list_id'], 'todo.read'),
            $this->readTool('todo_get_task', 'Récupère une tâche Microsoft To Do précise.', ['list_id' => ['type' => 'string'], 'task_id' => ['type' => 'string']], ['list_id', 'task_id'], 'todo.read'),
            $this->writeTool('todo_create_task', 'Crée une tâche Microsoft To Do après confirmation.', ['list_id' => ['type' => 'string'], 'title' => ['type' => 'string'], 'body' => ['type' => 'string'], 'due_date' => ['type' => 'string'], 'due_time' => ['type' => 'string'], 'time_zone' => ['type' => 'string'], 'importance' => ['type' => 'string', 'enum' => ['low', 'normal', 'high']]], ['list_id', 'title'], 'todo.create_task', 'confirm'),
            $this->writeTool('todo_update_task', 'Met à jour le titre, le contenu, le statut ou l’échéance d’une tâche Microsoft To Do après confirmation.', ['list_id' => ['type' => 'string'], 'task_id' => ['type' => 'string'], 'title' => ['type' => 'string'], 'body' => ['type' => 'string'], 'status' => ['type' => 'string', 'enum' => ['notStarted', 'inProgress', 'completed', 'waitingOnOthers', 'deferred']], 'due_date' => ['type' => 'string'], 'due_time' => ['type' => 'string'], 'time_zone' => ['type' => 'string'], 'importance' => ['type' => 'string', 'enum' => ['low', 'normal', 'high']]], ['list_id', 'task_id'], 'todo.update_task', 'confirm'),
            $this->writeTool('todo_delete_task', 'Supprime une tâche Microsoft To Do après confirmation.', ['list_id' => ['type' => 'string'], 'task_id' => ['type' => 'string']], ['list_id', 'task_id'], 'todo.delete_task', 'confirm'),
        ];
    }

    /** @return array<string, list<string>> */
    protected function requiredScopes(): array
    {
        return [
            'todo_list_lists' => ['Tasks.Read'], 'todo_list_tasks' => ['Tasks.Read'], 'todo_get_task' => ['Tasks.Read'],
            'todo_create_list' => ['Tasks.ReadWrite'], 'todo_create_task' => ['Tasks.ReadWrite'], 'todo_update_task' => ['Tasks.ReadWrite'], 'todo_delete_task' => ['Tasks.ReadWrite'],
        ];
    }

    public function callTool(string $toolName, array $params, array $credentials, array $context, MicrosoftGraphClient $graph): ToolResult
    {
        return match ($toolName) {
            'todo_list_lists' => $this->listLists($graph),
            'todo_create_list' => $this->createList($graph, $params),
            'todo_list_tasks' => $this->listTasks($graph, $params),
            'todo_get_task' => $this->getTask($graph, $params),
            'todo_create_task' => $this->createTask($graph, $params),
            'todo_update_task' => $this->updateTask($graph, $params),
            'todo_delete_task' => $this->deleteTask($graph, $params),
            default => throw new ToolNotFoundException("Outil '{$toolName}' inconnu pour le module To Do Microsoft 365."),
        };
    }

    private function listLists(MicrosoftGraphClient $g): ToolResult
    {
        $lists = $g->collectPages('/me/todo/lists', ['$select' => 'id,displayName,isOwner,isShared,lastModifiedDateTime']);
        return ToolResult::ok(['lists' => $lists], count($lists) . ' liste(s) To Do récupérée(s)');
    }

    private function createList(MicrosoftGraphClient $g, array $p): ToolResult
    {
        $list = $g->post('/me/todo/lists', ['displayName' => (string) $p['display_name']]);
        return ToolResult::ok(['list' => $list], 'Liste To Do créée.');
    }

    private function listTasks(MicrosoftGraphClient $g, array $p): ToolResult
    {
        $tasks = $g->collectPages('/me/todo/lists/' . $this->id($p['list_id']) . '/tasks', [
            '$top' => min(100, max(1, (int) ($p['top'] ?? 50))),
            '$select' => 'id,title,status,importance,body,dueDateTime,startDateTime,createdDateTime,lastModifiedDateTime',
        ]);
        return ToolResult::ok(['tasks' => $tasks], count($tasks) . ' tâche(s) To Do récupérée(s)');
    }

    private function getTask(MicrosoftGraphClient $g, array $p): ToolResult
    {
        $task = $g->get($this->taskPath($p), ['$select' => 'id,title,status,importance,body,dueDateTime,startDateTime,createdDateTime,lastModifiedDateTime']);
        return ToolResult::ok(['task' => $task], 'Tâche To Do récupérée.');
    }

    private function createTask(MicrosoftGraphClient $g, array $p): ToolResult
    {
        $task = $g->post('/me/todo/lists/' . $this->id($p['list_id']) . '/tasks', $this->taskBody($p));
        return ToolResult::ok(['task' => $task], 'Tâche To Do créée.');
    }

    private function updateTask(MicrosoftGraphClient $g, array $p): ToolResult
    {
        $task = $g->patch($this->taskPath($p), $this->taskBody($p));
        return ToolResult::ok(['task' => $task], 'Tâche To Do mise à jour.');
    }

    private function deleteTask(MicrosoftGraphClient $g, array $p): ToolResult
    {
        $g->delete($this->taskPath($p));
        return ToolResult::ok(['task_id' => $p['task_id']], 'Tâche To Do supprimée.');
    }

    private function taskPath(array $p): string
    {
        return '/me/todo/lists/' . $this->id($p['list_id']) . '/tasks/' . $this->id($p['task_id']);
    }

    private function taskBody(array $p): array
    {
        $body = array_filter([
            'title' => $p['title'] ?? null,
            'status' => $p['status'] ?? null,
            'importance' => $p['importance'] ?? null,
            'body' => isset($p['body']) ? ['contentType' => 'text', 'content' => (string) $p['body']] : null,
            'dueDateTime' => isset($p['due_date']) ? ['dateTime' => trim((string) $p['due_date'] . ' ' . (string) ($p['due_time'] ?? '00:00:00')), 'timeZone' => (string) ($p['time_zone'] ?? 'UTC')] : null,
        ], static fn ($value): bool => $value !== null);

        return $body;
    }
}
