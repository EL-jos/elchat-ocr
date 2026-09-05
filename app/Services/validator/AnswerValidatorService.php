<?php

namespace App\Services\validator;

use App\Services\hops\LLMService;

class AnswerValidatorService
{
    public function __construct(
        protected LLMService $llm
    ) {}

    public function validate(
        string $question,
        string $answer,
        string $context
    ): array {

        if (empty(trim($answer)) || empty(trim($context))) {
            return 0.0;
        }

        $prompt = [
            [
                "role" => "system",
                "content" => $this->systemPrompt()
            ],
            [
                "role" => "user",
                "content" => json_encode([
                    "question" => $question,
                    "answer" => $answer,
                    "context" => $context
                ])
            ]
        ];

        $result = $this->llm->chatJson($prompt, [
            'task' => 'answer_validation',
        ]);

        return [
            'relevance' => $this->normalizeScore($result['relevance'] ?? 0),
            'grounding' => $this->normalizeScore($result['grounding'] ?? 0),
            'hallucination_risk' => $this->normalizeScore($result['hallucination_risk'] ?? 0),
            'final_score' => $this->normalizeScore($result['final_score'] ?? 0),
            'reason' => $result['reason'] ?? ''
        ];
    }

    // =====================================================
    // 🧠 SYSTEM PROMPT (CRITIQUE)
    // =====================================================

    protected function systemPrompt(): string
    {
        return <<<TXT
Tu es un évaluateur de réponses IA pour un système RAG.

Ta tâche :
Évaluer si la réponse est correctement supportée par le contexte fourni.

Critères :

1. RELEVANCE (0-1)
- La réponse répond-elle réellement à la question ?

2. GROUNDING (0-1)
- Les informations de la réponse sont-elles présentes dans le contexte ?

3. HALLUCINATION PENALTY
- Si la réponse contient des informations absentes du contexte → baisse le score

RÈGLES STRICTES :
- Ne juge QUE sur le contexte fourni
- Ignore toute connaissance externe
- Sois sévère sur les hallucinations

SORTIE STRICT ET OBLIGATOIRE (JSON):
{
  "relevance": float (0.0 à 1.0),
  "grounding": float (0.0 à 1.0),
  "hallucination_risk": float (0.0 à 1.0),
  "final_score": float (0.0 à 1.0),
  "reason": "string courte"
}
TXT;
    }

    // =====================================================
    // 🧠 NORMALISATION
    // =====================================================

    protected function normalizeScore($score): float
    {
        $score = floatval($score);

        if ($score < 0) return 0.0;
        if ($score > 1) return 1.0;

        return round($score, 3);
    }
}
