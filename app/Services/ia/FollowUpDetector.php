<?php

namespace App\Services\ia;

use App\Models\Conversation;
use App\Models\Message;
use App\Services\hops\LLMService;

class FollowUpDetector
{
    private const MAX_RETRIES = 5;

    public function __construct(private readonly LLMService $llm) {}

    public function isFollowUp(string $question, Conversation $conversation): bool
    {
        $history = $this->buildHistory($conversation);

        $baseMessages = [
            [
                'role' => 'system',
                'content' => $this->systemPrompt(),
            ],
            [
                'role' => 'user',
                'content' => $this->userPrompt($history, $question),
            ]
        ];

        $temperature = 0;

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {

            try {

                $content = $this->llm->chat($baseMessages, [
                    'task' => 'follow_up_detection',
                    'temperature' => $temperature,
                    'max_tokens' => 16,
                ]);

                $normalized = $this->normalizeAnswer($content);

                if ($normalized !== null) {

                    logger()->info('FollowUpDetector success', [
                        'attempt' => $attempt,
                        'answer' => $normalized,
                    ]);

                    return $normalized;
                }

                logger()->warning('FollowUpDetector invalid response', [
                    'attempt' => $attempt,
                    'raw_response' => $content,
                ]);

                // Retry prompt reinforcement
                $baseMessages[] = [
                    'role' => 'system',
                    'content' => 'INVALID RESPONSE FORMAT. Reply ONLY with YES or NO.',
                ];

                // Lower randomness progressively
                $temperature = max(0, $temperature - 0.1);

            } catch (\Throwable $e) {

                logger()->error('FollowUpDetector exception', [
                    'attempt' => $attempt,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        // Safe fallback
        return false;
    }

    private function normalizeAnswer(string $answer): ?bool
    {
        $answer = strtoupper(trim($answer));

        // strict cleanup
        $answer = preg_replace('/[^A-Z]/', '', $answer);

        return match ($answer) {
            'YES' => true,
            'NO' => false,
            default => null,
        };
    }

    private function buildHistory(Conversation $conversation): string
    {
        return Message::where('conversation_id', $conversation->id)
            ->latest()
            ->take(4)
            ->get()
            ->reverse()
            ->map(fn($m) => $m->content)
            ->implode("\n");
    }

    private function systemPrompt(): string
    {
        return <<<PROMPT
You are a strict binary classifier.

Your task:
Determine whether the new user question depends on previous conversation context.

Rules:
- Reply ONLY with YES or NO
- No explanations
- No punctuation
- No extra words
- No markdown

Examples:

Context:
User: Who is Elon Musk?
User: Where was he born?

Answer:
YES

Context:
User: What is Laravel?
User: Explain quantum physics.

Answer:
NO
PROMPT;
    }

    private function userPrompt(string $history, string $question): string
    {
        return <<<PROMPT
Conversation history:
{$history}

New question:
{$question}

Does the new question depend on previous context?
PROMPT;
    }
}
