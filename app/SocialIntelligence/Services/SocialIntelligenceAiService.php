<?php

namespace App\SocialIntelligence\Services;

use App\Services\hops\LLMService;
use Illuminate\Support\Facades\Log;

class SocialIntelligenceAiService
{
    public function __construct(private readonly LLMService $llm) {}

    public function interpret(array $dataset, string $depth): array
    {
        if (!config('social-intelligence.ai_enabled', true) || !config('llm.provider.api_key')) return ['insights' => [], 'limitations' => ['Interprétation LLM non exécutée : aucun fournisseur LLM configuré.']];
        $prompt = 'Tu es le moteur d’interprétation de Social Intelligence ELChat. Tu reçois un dataset analytique déjà agrégé. Les nombres fournis sont des faits et ne doivent pas être recalculés à partir de données absentes. Réponds uniquement avec un objet JSON de la forme {"insights":[...]}. Chaque insight doit contenir type, title, importance, observation, correlation, interpretation, diagnosis, opportunity, recommendation, implementation, measurement, confidence, evidence, limitations. Sépare strictement les faits observés des corrélations et hypothèses. N’affirme aucune causalité. N’invente aucune métrique, compte, personne, plateforme ou permission. Si l’échantillon est insuffisant, utilise confidence="low" et indique insufficient_data. Une recommandation doit décrire une action testable et sa mesure, sans promettre un résultat commercial.';
        try {
            $result = $this->llm->chatJson([
                ['role' => 'system', 'content' => $prompt],
                ['role' => 'user', 'content' => json_encode(['depth' => $depth, 'dataset' => $dataset], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
            ], ['task' => 'social_intelligence_analysis', 'max_tokens' => $depth === 'deep' ? 1800 : 1000, 'detect_truncation' => true]);
            $insights = collect($result['insights'] ?? [])->filter(fn ($item) => is_array($item) && trim((string) ($item['title'] ?? '')) !== '')->map(function (array $item): array {
                $item['confidence'] = in_array($item['confidence'] ?? null, ['high', 'medium', 'low'], true) ? $item['confidence'] : 'low';
                $item['evidence'] = is_array($item['evidence'] ?? null) ? $item['evidence'] : [];
                $item['limitations'] = is_array($item['limitations'] ?? null) ? $item['limitations'] : [];
                return $item;
            })->values()->all();
            return ['insights' => $insights, 'limitations' => []];
        } catch (\Throwable $exception) {
            Log::warning('Social Intelligence LLM interpretation failed', ['error' => $exception->getMessage()]);
            return ['insights' => [], 'limitations' => ['Interprétation LLM indisponible ; les signaux déterministes restent présentés.']];
        }
    }
}
