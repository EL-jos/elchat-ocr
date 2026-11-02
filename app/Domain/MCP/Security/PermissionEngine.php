<?php

namespace App\Domain\MCP\Security;

use App\Domain\MCP\Contracts\ToolSchema;
use App\Domain\MCP\Exceptions\ConfirmationRequiredException;
use App\Domain\MCP\Exceptions\PermissionDeniedException;
use App\Models\Mcp\McpPermission;
use App\Models\Site;
use Illuminate\Support\Facades\Cache;

/**
 * Porte d'entrée obligatoire avant tout appel d'outil. Fail-closed : une
 * action sans règle explicite est refusée. Prend désormais en compte QUI
 * demande l'action (visiteur vs admin), pas seulement SI c'est permis.
 */
class PermissionEngine
{
    public function authorize(Site $site, ActorContext $actor, string $connectorSlug, string $toolName, bool $skipConfirmation = false, bool $consumeDailyLimit = true): McpPermission
    {
        $permission = McpPermission::where('site_id', $site->id)
            ->where('connector_slug', $connectorSlug)
            ->where('tool_name', $toolName)
            ->first();

        if (!$permission || $permission->mode === 'deny') {
            throw new PermissionDeniedException("L'action {$connectorSlug}.{$toolName} n'est pas autorisée pour ce site.");
        }

        // 🆕 Un outil réservé à l'admin ne peut pas être appelé par un visiteur,
        // même si mode='auto'.
        if (! $this->actorMayInvoke((string) $permission->actor_scope, $actor)) {
            throw new PermissionDeniedException("L'action {$connectorSlug}.{$toolName} est réservée à un administrateur.");
        }

        if ($permission->mode === 'confirm' && !$skipConfirmation) {
            throw new ConfirmationRequiredException(
                $connectorSlug,
                $toolName,
                [],
                confirmActor: $permission->confirm_actor ?? 'admin',
            );
        }

        // A confirmation prompt has not executed the action, so it must not spend quota.
        if ($consumeDailyLimit) {
            $this->enforceDailyLimit($site, $permission);
        }

        return $permission;
    }

    /**
     * @param ToolSchema[] $tools
     * @return ToolSchema[]
     */
    public function filterAllowedTools(Site $site, ActorContext $actor, array $tools): array
    {
        $rules = McpPermission::where('site_id', $site->id)
            ->where('mode', '!=', 'deny')
            ->get()
            ->keyBy(fn ($p) => "{$p->connector_slug}.{$p->tool_name}");

        return array_values(array_filter($tools, function (ToolSchema $t) use ($rules, $actor) {
            $rule = $rules->get("{$t->connectorSlug}.{$t->name}");
            if (!$rule) {
                return false;
            }
            // Un admin voit aussi les outils 'visitor' (il peut tout faire),
            // un visiteur ne voit jamais les outils 'admin'.
            return $this->actorMayInvoke((string) $rule->actor_scope, $actor);
        }));
    }

    /**
     * 🆕 Pré-remplit mcp_permissions à partir des suggestions embarquées dans
     * chaque ToolSchema, lors de l'activation d'un connecteur. N'écrase
     * jamais une règle déjà configurée manuellement (firstOrCreate).
     *
     * @param ToolSchema[] $tools
     */
    public function seedDefaultsIfMissing(Site $site, array $tools): void
    {
        foreach ($tools as $tool) {
            McpPermission::firstOrCreate(
                ['site_id' => $site->id, 'connector_slug' => $tool->connectorSlug, 'tool_name' => $tool->name],
                [
                    'mode' => $tool->defaultMode,
                    'actor_scope' => $tool->defaultActorScope,
                    'confirm_actor' => $tool->defaultConfirmActor,
                ]
            );
        }
    }

    private function enforceDailyLimit(Site $site, McpPermission $permission): void
    {
        if (!$permission->daily_call_limit) {
            return;
        }

        $key = "mcp:calls:{$site->id}:{$permission->connector_slug}:{$permission->tool_name}:" . now()->format('Y-m-d');
        Cache::add($key, 0, now()->endOfDay());
        $count = (int) Cache::increment($key);

        if ($count > $permission->daily_call_limit) {
            Cache::decrement($key);
            throw new PermissionDeniedException("Limite quotidienne atteinte pour {$permission->connector_slug}.{$permission->tool_name}.");
        }
    }

    private function actorMayInvoke(string $actorScope, ActorContext $actor): bool
    {
        return $actorScope === 'visitor' || $actor->isAdmin;
    }
}
