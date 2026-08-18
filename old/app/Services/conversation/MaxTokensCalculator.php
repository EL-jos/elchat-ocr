<?php

namespace App\Services\conversation;

use App\Contracts\MaxTokensCalculatorInterface;
use App\Enums\ResponseDepth;

final class MaxTokensCalculator implements MaxTokensCalculatorInterface
{
    public function calculate(ResponseDepth $depth): int
    {
        $map = config('conversation_engine.max_tokens_by_depth', []);

        return (int) ($map[$depth->value] ?? 380);
    }
}
