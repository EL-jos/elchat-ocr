<?php

namespace App\Services\cta;

class ChatResponse
{
    public function __construct(
        public string $message,
        public array $ctas = [],
        public ?array $entities = [],
        // 🆕 non-null uniquement quand une action MCP attend une
        // confirmation humaine avant exécution (mode 'confirm').
        public ?array $pendingConfirmation = null,
    ) {}
}
