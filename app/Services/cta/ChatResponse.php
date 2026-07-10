<?php

namespace App\Services\cta;

class ChatResponse
{
    public function __construct(
        public string $message,
        public array $ctas = [],
        public ?array $entities = []
    ) {}
}
