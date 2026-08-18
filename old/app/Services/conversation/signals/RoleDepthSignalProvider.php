<?php

namespace App\Services\conversation\signals;

use App\Contracts\DepthSignalProviderInterface;
use App\Models\AIRole;
use App\Models\Conversation;
use App\Models\Site;
use App\Services\queryAnalyzer\QueryPlan;
use App\ValueObjects\DepthSignal;

final class RoleDepthSignalProvider implements DepthSignalProviderInterface
{
    public function collect(QueryPlan $plan, Site $site, Conversation $conversation, string $question, array $history): array
    {
        // Même résolution de rôle que PromptBuilder::buildSystemPrompt, pour rester cohérent.
        $role = $site->settings?->aiRole ?? AIRole::default()->first();

        if (!$role?->name) {
            return [];
        }

        $modifiers = config('conversation_engine.role_modifiers', []);
        $config = $modifiers[$role->name] ?? null;

        if (!$config || (float) $config['weight'] === 0.0) {
            return [];
        }

        return [new DepthSignal('role', (float) $config['weight'], "role:{$role->name}")];
    }
}
