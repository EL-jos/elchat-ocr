<?php

namespace App\Services\Matchers;

use App\Interfaces\CtaRuleMatcher;
use App\Services\cta\ScoreResult;

class EntityRuleMatcher implements CtaRuleMatcher
{
    public function score($cta, $queryPlan, $conversation): ScoreResult
    {
        $result = new ScoreResult();

        foreach ($cta->rules as $rule) {

            if ($rule->rule_type !== 'entity') {
                continue;
            }

            foreach ($queryPlan->entities as $entity) {

                $type = strtolower($entity['type'] ?? '');
                $value = strtolower($entity['value'] ?? '');

                $ruleValue = strtolower($rule->rule_value);

                // Format attendu en DB:
                // product:stylo métallique
                if ($ruleValue === "{$type}:{$value}") {

                    $result->add(
                        config('cta.weights.entity', 6),
                        "Entity match: {$ruleValue}"
                    );
                }
            }
        }

        return $result;
    }
}
