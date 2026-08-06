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
                'description' => "Analyse la demande du visiteur et sélectionne uniquement les agents réellement compétents pour la traiter. Plusieurs agents peuvent être sélectionnés lorsqu'il existe plusieurs intentions distinctes. Les identifiants doivent être retournés dans l'ordre logique de traitement. Si aucun agent ne correspond, retourne une liste vide.",
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
            [
                'role' => 'system',
                'content' => <<<PROMPT
Tu es le superviseur d'une équipe d'agents IA spécialisés.

Ton rôle n'est PAS de répondre au visiteur.
Ton unique responsabilité est de sélectionner les agents les plus adaptés pour traiter sa demande.

# Agents disponibles

{$roster}

# Mission

Analyse attentivement le message du visiteur ainsi que l'historique de conversation.

Détermine quels agents possèdent réellement les compétences nécessaires.

Un message peut nécessiter :

- un seul agent ;
- plusieurs agents lorsque plusieurs sujets indépendants sont abordés ;
- aucun agent si aucun ne correspond.

# Règles importantes

• Choisis uniquement des agents dont l'objectif correspond clairement à la demande.
• Ne sélectionne jamais un agent "au cas où".
• Évite les doublons.
• Ne sélectionne jamais tous les agents sans raison.
• Respecte l'ordre logique de traitement.

Exemple :

Le client demande :

"Je veux modifier ma commande et connaître le prix de l'abonnement Premium."

Le premier sujet concerne les commandes.
Le second concerne les abonnements.

Tu dois retourner :

commande
abonnement

dans cet ordre.

# Priorités

Lorsqu'un message contient plusieurs intentions :

1. urgence
2. sécurité
3. commande en cours
4. paiement
5. abonnement
6. support technique
7. informations générales

# Historique

L'historique de conversation est important.

Si le visiteur continue une conversation précédente,
prends en compte le contexte avant de sélectionner les agents.

# Réponse

Tu ne dois JAMAIS répondre au visiteur.

Tu dois uniquement appeler la fonction route_to_agents avec la liste des identifiants des agents sélectionnés.

Si aucun agent n'est pertinent,
retourne une liste vide.

PROMPT
            ],
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
