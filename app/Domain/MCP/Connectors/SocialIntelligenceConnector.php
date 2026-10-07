<?php

namespace App\Domain\MCP\Connectors;

use App\Domain\MCP\Contracts\{ToolResult, ToolSchema};
use App\Domain\MCP\Exceptions\ToolNotFoundException;
use App\Models\Site;
use App\SocialIntelligence\Services\SocialIntelligenceQueryService;

class SocialIntelligenceConnector extends AbstractConnector
{
    public function __construct(private readonly SocialIntelligenceQueryService $query) {}
    public function slug(): string { return 'social_intelligence'; }
    public function authenticate(array $credentials): array { return $credentials; }

    public function listTools(): array
    {
        return [
            new ToolSchema('social_intelligence', 'get_overview', 'Retourne la dernière analyse Social Intelligence du site courant, avec faits observés, qualité des données, plateformes, tendances et recommandations. Ne complète jamais les métriques absentes et distingue les limites de permissions.', ['type' => 'object', 'properties' => []], defaultActorScope: 'admin', defaultMode: 'auto', capability: 'social_intelligence.overview'),
            new ToolSchema('social_intelligence', 'get_insights', 'Retourne les insights sociaux persistés et leurs preuves pour le site courant. Utilise cet outil pour répondre à une question sur les tendances, diagnostics ou opportunités sociales. Les résultats restent limités aux comptes synchronisés.', ['type' => 'object', 'properties' => ['type' => ['type' => 'string'], 'limit' => ['type' => 'integer']]], defaultActorScope: 'admin', defaultMode: 'auto', capability: 'social_intelligence.insights'),
            new ToolSchema('social_intelligence', 'get_platforms', 'Retourne les résultats par plateforme de la dernière analyse sociale. Les métriques ne sont comparables entre plateformes que lorsque comparable=true.', ['type' => 'object', 'properties' => []], defaultActorScope: 'admin', defaultMode: 'auto', capability: 'social_intelligence.platforms'),
        ];
    }

    public function callTool(string $toolName, array $params, array $credentials, array $context = []): ToolResult
    {
        $site = Site::find($context['site_id'] ?? null);
        if (!$site) return ToolResult::fail('not_found', 'Site introuvable dans ce contexte.');
        if (($context['account_id'] ?? null) !== null && (string) $site->account_id !== (string) $context['account_id']) {
            return ToolResult::fail('forbidden', 'Le site ne correspond pas au tenant MCP courant.');
        }
        return match ($toolName) {
            'get_overview' => ToolResult::ok($this->query->overview($site), 'Synthèse Social Intelligence récupérée.'),
            'get_platforms' => ToolResult::ok($this->query->platforms($site), 'Résultats sociaux par plateforme récupérés.'),
            'get_insights' => ToolResult::ok(['insights' => $this->query->insights($site, ['type' => $params['type'] ?? null, 'per_page' => min(50, max(1, (int) ($params['limit'] ?? 20)))])->items()], 'Insights sociaux récupérés.'),
            default => throw new ToolNotFoundException("Outil '{$toolName}' inconnu pour social_intelligence."),
        };
    }
}
