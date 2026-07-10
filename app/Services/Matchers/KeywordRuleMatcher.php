<?php

namespace App\Services\Matchers;

use App\Interfaces\CtaRuleMatcher;
use App\Services\cta\ScoreResult;

class KeywordRuleMatcher implements CtaRuleMatcher
{
    public function score($cta, $queryPlan, $conversation): ScoreResult
    {
        $result = new ScoreResult();
        $query = strtolower($queryPlan->cleanQuery);

        foreach ($cta->rules as $rule) {

            if ($rule->rule_type !== 'keyword') {
                continue;
            }

            if (str_contains($query, strtolower($rule->rule_value))) {
                $result->add(
                    config('cta.weights.keyword', 2),
                    "Keyword match: {$rule->rule_value}"
                );
            }
        }

        return $result;
    }
}
