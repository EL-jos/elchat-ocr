<?php

namespace Tests\Unit\Proactive;

use App\Domain\Proactive\ProactiveConditionEvaluator;
use PHPUnit\Framework\TestCase;

class ProactiveConditionEvaluatorTest extends TestCase
{
    private ProactiveConditionEvaluator $evaluator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->evaluator = new ProactiveConditionEvaluator();
    }

    public function test_all_and_any_groups_are_evaluated_without_type_coercion_surprises(): void
    {
        $context = ['event' => ['value' => 87, 'metadata' => ['intent' => 'quote']]];

        $this->assertTrue($this->evaluator->evaluate([
            ['all' => [
                ['field' => 'event.value', 'operator' => 'gte', 'value' => 80],
                ['any' => [
                    ['field' => 'event.metadata.intent', 'operator' => 'eq', 'value' => 'quote'],
                    ['field' => 'event.metadata.intent', 'operator' => 'eq', 'value' => 'pricing'],
                ]],
            ]],
        ], $context));
    }

    public function test_missing_values_and_string_operators_fail_closed(): void
    {
        $context = ['event' => ['value' => null, 'label' => ['not-a-string']]];

        $this->assertFalse($this->evaluator->evaluate([
            ['field' => 'event.value', 'operator' => 'gte', 'value' => 80],
        ], $context));
        $this->assertFalse($this->evaluator->evaluate([
            ['field' => 'event.label', 'operator' => 'starts_with', 'value' => 'not'],
        ], $context));
    }

    public function test_between_and_opt_out_boolean_operators_are_supported(): void
    {
        $context = ['lead' => ['score' => 87, 'opted_out' => false]];

        $this->assertTrue($this->evaluator->evaluate([
            ['field' => 'lead.score', 'operator' => 'between', 'value' => [80, 90]],
            ['field' => 'lead.opted_out', 'operator' => 'is_false'],
        ], $context));
    }
}
