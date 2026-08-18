<?php

namespace App\Contracts;

use App\Enums\ResponseDepth;
use App\ValueObjects\DepthSignal;

interface ResponseDepthResolverInterface
{
    /**
     * @param DepthSignal[] $signals
     */
    public function resolve(array $signals, int $turnCount): ResponseDepth;
}
