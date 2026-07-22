<?php

namespace App\Domain\MCP\Contracts;

/**
 * Résultat normalisé d'un appel d'outil, quel que soit le connecteur.
 * L'orchestrateur ne manipule jamais la réponse brute de l'API tierce —
 * toujours ce format uniforme.
 */
final readonly class ToolResult
{
    public function __construct(
        public bool $success,
        public array $data = [],           // payload structuré, réinjecté au LLM
        public ?string $humanSummary = null, // résumé lisible, utile pour l'audit log
        public ?string $errorCode = null,   // 'not_found', 'auth_expired', 'rate_limited', 'unavailable'...
        public ?string $errorMessage = null,
    ) {
    }

    public static function ok(array $data, ?string $summary = null): self
    {
        return new self(success: true, data: $data, humanSummary: $summary);
    }

    public static function fail(string $errorCode, string $errorMessage): self
    {
        return new self(success: false, errorCode: $errorCode, errorMessage: $errorMessage);
    }

    public function toArrayForLLM(): array
    {
        if (!$this->success) {
            return ['error' => $this->errorCode, 'message' => $this->errorMessage];
        }

        return $this->data;
    }
}
