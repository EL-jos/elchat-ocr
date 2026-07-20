<?php

namespace App\Services\conversation\signals;

use App\Contracts\DepthSignalProviderInterface;
use App\Models\Conversation;
use App\Models\Site;
use App\Services\queryAnalyzer\QueryPlan;
use App\ValueObjects\DepthSignal;

final class QuestionComplexitySignalProvider implements DepthSignalProviderInterface
{
    public function collect(QueryPlan $plan, Site $site, Conversation $conversation, string $question, array $history): array
    {
        $config = config('conversation_engine.question_complexity');
        $signals = [];

        $wordCount = str_word_count($question);
        if ($wordCount >= $config['long_question_word_threshold']) {
            $signals[] = new DepthSignal(
                'question_complexity',
                (float) $config['long_question_weight'],
                "long_question:{$wordCount}w"
            );
        }

        $subQueryCount = count($plan->subQueries ?? []);
        if ($subQueryCount > 0) {
            $bonus = min(
                $config['max_sub_query_bonus'],
                $subQueryCount * $config['sub_query_weight_per_item']
            );
            $signals[] = new DepthSignal('question_complexity', $bonus, "sub_queries:{$subQueryCount}");
        }

        return $signals;
    }
}
