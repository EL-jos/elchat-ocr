<?php

namespace App\Services\Matchers;

use App\Interfaces\CtaRuleMatcher;
use App\Services\cta\ScoreResult;

class IntentRuleMatcher implements CtaRuleMatcher
{
    public function score($cta, $queryPlan, $conversation): ScoreResult
    {
        $result = new ScoreResult();

        foreach ($cta->rules as $rule) {

            if ($rule->rule_type !== 'intent') {
                continue;
            }

            if ($rule->rule_value === $queryPlan->intent) {
                $result->add(
                    config('cta.weights.intent', 5),
                    "Intent match: {$rule->rule_value}"
                );
            }
        }

        return $result;
    }
}
