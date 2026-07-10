<?php

namespace App\Services\Matchers;

use App\Interfaces\CtaRuleMatcher;
use App\Models\ChatbotCta;
use App\Models\Conversation;
use App\Services\cta\ScoreResult;
use App\Services\queryAnalyzer\QueryPlan;

class QueryTypeMatcher implements CtaRuleMatcher
{
    public function score($cta, $queryPlan, $conversation): ScoreResult
    {
        $result = new ScoreResult();

        foreach ($cta->rules as $rule) {

            if ($rule->rule_type !== 'query_type') {
                continue;
            }

            if ($rule->rule_value === $queryPlan->queryType) {

                $result->add(
                    config('cta.weights.query_type', 4),
                    "QueryType match: {$rule->rule_value}"
                );
            }
        }

        return $result;
    }
}
