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
        public ?array $suggestedActions = null, // 🆕 [{label, prompt}]
        // Indique au transporteur qu'une extraction mémoire différée est
        // souhaitable pour cette réponse (entrée dans une intention commerciale).
        public bool $memoryRefreshRequested = false,
    ) {}
}
