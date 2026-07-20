<?php

namespace App\Services\conversation\signals;

use App\Contracts\DepthSignalProviderInterface;
use App\Models\Conversation;
use App\Models\Site;
use App\Services\queryAnalyzer\QueryPlan;
use App\ValueObjects\DepthSignal;

final class SiteTypeDepthSignalProvider implements DepthSignalProviderInterface
{
    public function collect(QueryPlan $plan, Site $site, Conversation $conversation, string $question, array $history): array
    {
        $typeName = $site->type?->name;

        if (!$typeName) {
            return [];
        }

        $modifiers = config('conversation_engine.site_type_modifiers', []);
        $config = $modifiers[$typeName] ?? null;

        if (!$config || (float) $config['weight'] === 0.0) {
            return [];
        }

        return [new DepthSignal('site_type', (float) $config['weight'], "site_type:{$typeName}")];
    }
}
