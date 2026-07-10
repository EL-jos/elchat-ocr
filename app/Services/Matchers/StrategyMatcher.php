<?php

namespace App\Services\Matchers;

use App\Interfaces\CtaRuleMatcher;
use App\Models\ChatbotCta;
use App\Models\Conversation;
use App\Services\cta\ScoreResult;
use App\Services\queryAnalyzer\QueryPlan;

class StrategyMatcher implements CtaRuleMatcher
{
    public function score($cta, $queryPlan, $conversation): ScoreResult
    {
        $result = new ScoreResult();

        foreach ($cta->rules as $rule) {

            if ($rule->rule_type !== 'strategy') {
                continue;
            }

            if ($rule->rule_value === $queryPlan->searchStrategy) {

                $result->add(
                    config('cta.weights.strategy', 3),
                    "Strategy match: {$rule->rule_value}"
                );
            }
        }

        return $result;
    }
}
