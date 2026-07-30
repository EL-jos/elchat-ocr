<?php

namespace App\Domain\MCP\Agent;

use App\Domain\MCP\Orchestration\OpenRouterToolClient;
use App\Models\Mcp\McpAgent;
use App\Models\Site;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Décide QUEL(S) agent(s) doivent traiter un message donné. N'intervient
 * que si 2+ agents sont actifs sur le site — sinon retourne directement la
 * liste telle quelle, sans appel LLM supplémentaire.
 */
class AgentSupervisor
{
    public function __construct(private readonly OpenRouterToolClient $llm)
    {
    }

    /** @return McpAgent[] */
    public function route(Site $site, string $question, array $history, Collection $activeAgents): array
    {
        if ($activeAgents->count() <= 1) {
            return $activeAgents->all();
        }

        $agentIds = $activeAgents->pluck('id')->all();

        $tool = [
            'type' => 'function',
            'function' => [
                'name' => 'route_to_agents',
                'description' => "Sélectionne le ou les agents pertinents pour traiter ce message, dans l'ordre. Un message peut concerner plusieurs domaines à la fois (ex: une question sur une commande ET une question sur un abonnement) — sélectionne alors plusieurs agents.",
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'agent_ids' => [
                            'type' => 'array',
                            'items' => ['type' => 'string', 'enum' => $agentIds],
                            'description' => 'Identifiants des agents pertinents, dans l\'ordre de traitement.',
                        ],
                    ],
                    'required' => ['agent_ids'],
                    'additionalProperties' => false,
                ],
            ],
        ];

        $roster = $activeAgents->map(fn ($a) => "- id: {$a->id} | nom: {$a->name} | objectif: " . ($a->objective ?: 'généraliste, non précisé'))->implode("\n");

        $messages = [
            ['role' => 'system', 'content' => "Tu es le superviseur d'une équipe d'agents IA spécialisés. Voici les agents disponibles :\n{$roster}\n\nAnalyse le message du visiteur et détermine lequel (ou lesquels) de ces agents doit intervenir pour y répondre."],
            ...$history,
            ['role' => 'user', 'content' => $question],
        ];

        try {
            $response = $this->llm->send($messages, [$tool], 'required', temperature: 0.1, maxTokens: 200);
        } catch (\Throwable $e) {
            Log::warning('MCP AgentSupervisor: routage échoué, repli sur agent par défaut', ['error' => $e->getMessage()]);
            return [];
        }

        $call = $response['tool_calls'][0] ?? null;
        if (!$call) return [];

        $selectedIds = json_decode($call['function']['arguments'] ?? '{}', true)['agent_ids'] ?? [];

        return $activeAgents->whereIn('id', $selectedIds)
            ->sortBy(fn ($a) => array_search($a->id, $selectedIds))
            ->values()->all();
    }
}
