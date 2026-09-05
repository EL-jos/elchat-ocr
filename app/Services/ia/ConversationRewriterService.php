<?php

namespace App\Services\ia;


use App\Models\Conversation;
use App\Models\Message;
use App\Services\hops\LLMService;
use Illuminate\Support\Facades\Log;

/*class ConversationRewriterService
{
    public function __construct()
    {
    }
    public function rewrite(string $question, Conversation $conversation): string
    {
        $lastMessages = Message::where('conversation_id', $conversation->id)
            ->orderBy('created_at', 'desc')
            ->take(6)
            ->get()
            ->reverse()
            ->map(fn($m) => $m->content)
            ->implode("\n");

        $systemPrompt = "
        Tu es un assistant chargé de reformuler une question utilisateur
        en la rendant autonome et complète.

        Règles :
        - Ne réponds PAS à la question.
        - Reformule uniquement.
        - Intègre le contexte précédent si nécessaire.
        - Sois factuel et précis.
        - Une seule phrase.
        ";

            $userPrompt = "
        Historique :
        {$lastMessages}

        Nouvelle question :
        {$question}

        Reformulation complète :
        ";

        try {
            $response = Http::timeout(15)
                ->retry(3, 500, function ($exception, $request) {
                    if ($exception instanceof RequestException) {
                        $status = optional($exception->response)->status();
                        return in_array($status, [429, 500, 502, 503, 504]);
                    }
                    return true; // retry sur timeout réseau
                })
                ->withHeaders([
                    'Authorization' => 'Bearer ' . env('OPENROUTER_API_KEY'),
                ])
                ->post('https://openrouter.ai/api/v1/chat/completions', [
                    'model' => config('llm.tasks.conversation_rewrite.model'),
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $userPrompt],
                    ],
                    'temperature' => 0.1,
                    'max_tokens' => 150,
                ]);

            return $response->json()['choices'][0]['message']['content']
                ?? $question;

        } catch (\Throwable $e) {
            Log::error('Rewrite failed', [
                'error' => $e->getMessage(),
            ]);

            // fallback intelligent
            return $question;
        }
    }
}*/

class ConversationRewriterService
{
    public function __construct(private readonly LLMService $llm) {}

    public function rewrite(string $question, Conversation $conversation): string
    {
        $history = $this->getHistory($conversation);
        $systemPrompt = $this->systemPrompt();

        $maxAttempts = 5;
        $minAttempts = 3;
        $lastError = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {

            $userPrompt = $this->buildUserPrompt($history, $question, $lastError);

            try {
                $content = $this->llm->chat([
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ], [
                    'task' => 'conversation_rewrite',
                    'temperature' => 0.1,
                    'max_tokens' => 150,
                ]);

                if ($this->isValid($content)) {
                    return $this->sanitize($content);
                }

                $lastError = "FORMAT INVALID (attempt {$attempt})";

            } catch (\Throwable $e) {
                $lastError = $e->getMessage();

                Log::warning('Rewrite failed', [
                    'attempt' => $attempt,
                    'error' => $lastError,
                ]);
            }

            usleep(200000 * $attempt);
        }

        Log::error('Rewrite failed after max retries', [
            'question' => $question,
            'last_error' => $lastError,
        ]);

        return $question;
    }
    private function buildUserPrompt(string $history, string $question, ?string $error): string
    {
        $errorBlock = $error
            ? "\n\n $error\n- Tu dois corriger STRICTEMENT selon les règles."
            : "";

        return <<<VALIDATION
===========
Historique:
===========
{$history}

=========
Question:
=========
{$question}

====================
ERREUR DE VALIDATION
====================
{$errorBlock}

Reformule en UNE seule phrase autonome.
VALIDATION;
    }
    private function isValid(?string $content): bool
    {
        if (!$content) return false;

        $content = trim($content);

        if (strlen($content) < 10) return false;

        if (preg_match('/[.!?].+[.!?]/', $content)) return false;

        if (str_contains($content, '```')) return false;

        $forbidden = ['reformulation', 'assistant:', 'here is'];
        foreach ($forbidden as $word) {
            if (str_contains(strtolower($content), $word)) {
                return false;
            }
        }

        return true;
    }
    private function sanitize(string $content): string
    {
        return trim(preg_replace('/\s+/', ' ', $content));
    }
    private function systemPrompt(): string
    {
        return <<<PROMPT
- Tu es un moteur de reformulation.
- Tu DOIS produire uniquement une phrase.
- Aucun commentaire.
- Aucun markdown.
- Aucun préfixe.
PROMPT;
    }
    private function getHistory(Conversation $conversation): string
    {
        return Message::where('conversation_id', $conversation->id)
            ->orderBy('created_at', 'desc')
            ->take(6)
            ->get()
            ->reverse()
            ->map(fn($m) => $m->content)
            ->implode("\n");
    }
}
