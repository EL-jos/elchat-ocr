<?php

use App\Services\Matchers\ContextRuleMatcher;
use App\Services\Matchers\EntityRuleMatcher;
use App\Services\Matchers\IntentRuleMatcher;
use App\Services\Matchers\KeywordRuleMatcher;
use App\Services\Matchers\QueryTypeMatcher;
use App\Services\Matchers\StrategyMatcher;

return [
    'limit' => 3,

    'weights' => [
        'intent' => 5,
        'keyword' => 2,
        'entity' => 6,
        'query_type' => 4,
        'strategy' => 3,
        'context' => 2,
    ],

    'matchers' => [
        IntentRuleMatcher::class,
        KeywordRuleMatcher::class,
        ContextRuleMatcher::class,
        EntityRuleMatcher::class,
        QueryTypeMatcher::class,
        StrategyMatcher::class,
    ],

    'relevance' => [

        'threshold' => 0.5,

        'max_ctas' => 2,

        'weights' => [
            'intent'   => 0.4,
            'keyword'  => 0.2,
            'context'  => 0.2,
            'entity'   => 0.2,
        ],

    ],
];
