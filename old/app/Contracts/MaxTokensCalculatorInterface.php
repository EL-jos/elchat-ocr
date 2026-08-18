<?php

namespace App\Contracts;

use App\Enums\ResponseDepth;

interface MaxTokensCalculatorInterface
{
    public function calculate(ResponseDepth $depth): int;
}
