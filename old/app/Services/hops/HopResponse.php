<?php

namespace App\Services\hops;

class HopResponse
{
    public function __construct(
        public ?string $message = null,
        public ?array $prompt = null,
        public ?array $ctas = [],
        public ?array $entities = [],
        public ?string $context = null,
    ) {}
}
