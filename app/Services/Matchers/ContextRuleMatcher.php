<?php

namespace App\Services\Matchers;

use App\Interfaces\CtaRuleMatcher;
use App\Models\ChatbotCta;
use App\Models\Conversation;
use App\Services\cta\ScoreResult;
use App\Services\queryAnalyzer\QueryPlan;
use Illuminate\Support\Facades\Log;

class ContextRuleMatcher implements CtaRuleMatcher
{
    public function score(ChatbotCta $cta, QueryPlan $queryPlan, Conversation $conversation): ScoreResult
    {
        $result = new ScoreResult();

        $messages = $conversation->messages()
            ->latest()
            ->take(6)
            ->get();

        foreach ($cta->rules as $rule) {

            if ($rule->rule_type !== 'context') {
                continue;
            }

            $ruleValue = strtolower($rule->rule_value);

            foreach ($messages as $message) {

                if (str_contains(strtolower($message->content), $ruleValue)) {

                    $result->add(
                        config('cta.weights.context', 2),
                        "Context match: {$ruleValue}"
                    );

                    break; // éviter spam score
                }
            }
        }

        return $result;
    }
}
