<?php

namespace App\Domain\MCP\Security;

use App\Domain\MCP\Contracts\ToolSchema;
use App\Domain\MCP\Exceptions\ConfirmationRequiredException;
use App\Domain\MCP\Exceptions\PermissionDeniedException;
use App\Models\Mcp\McpPermission;
use App\Models\Site;
use Illuminate\Support\Facades\Cache;

/**
 * Porte d'entrée obligatoire avant tout appel d'outil. Fail-closed par
 * conception : une action sans règle explicite en base est REFUSÉE, jamais
 * autorisée par défaut.
 */
class PermissionEngine
{
    /**
     * @throws PermissionDeniedException si l'action est bloquée ou si la
     *         limite d'appels quotidiens est atteinte
     * @throws ConfirmationRequiredException si une validation humaine est requise
     */
    public function authorize(Site $site, string $connectorSlug, string $toolName): McpPermission
    {
        $permission = McpPermission::where('site_id', $site->id)
            ->where('connector_slug', $connectorSlug)
            ->where('tool_name', $toolName)
            ->first();

        // Fail-closed : pas de règle définie = refus.
        if (!$permission || $permission->mode === 'deny') {
            throw new PermissionDeniedException(
                "L'action {$connectorSlug}.{$toolName} n'est pas autorisée pour ce site."
            );
        }

        $this->enforceDailyLimit($site, $permission);

        if ($permission->mode === 'confirm') {
            throw new ConfirmationRequiredException($connectorSlug, $toolName, []);
        }

        return $permission; // mode === 'auto'
    }

    /**
     * Filtre la liste de tools envoyée au LLM pour ne garder que ceux dont
     * le mode n'est pas 'deny'. Évite de proposer au LLM des actions qu'il
     * ne pourra de toute façon jamais exécuter.
     *
     * @param ToolSchema[] $tools
     * @return ToolSchema[]
     */
    public function filterAllowedTools(Site $site, array $tools): array
    {
        $rules = McpPermission::where('site_id', $site->id)
            ->where('mode', '!=', 'deny')
            ->get()
            ->keyBy(fn ($p) => "{$p->connector_slug}.{$p->tool_name}");

        return array_values(array_filter(
            $tools,
            fn (ToolSchema $t) => $rules->has("{$t->connectorSlug}.{$t->name}")
        ));
    }

    private function enforceDailyLimit(Site $site, McpPermission $permission): void
    {
        if (!$permission->daily_call_limit) {
            return;
        }

        $key = "mcp:calls:{$site->id}:{$permission->connector_slug}:{$permission->tool_name}:" . now()->format('Y-m-d');
        $count = (int) Cache::get($key, 0);

        if ($count >= $permission->daily_call_limit) {
            throw new PermissionDeniedException(
                "Limite quotidienne atteinte pour {$permission->connector_slug}.{$permission->tool_name}."
            );
        }

        Cache::put($key, $count + 1, now()->endOfDay());
    }
}
