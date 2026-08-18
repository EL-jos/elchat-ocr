<?php

namespace App\Domain\MCP\Contracts;

final readonly class ToolResult
{
    public function __construct(
        public bool $success,
        public array $data = [],
        public ?string $humanSummary = null,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
        // Renseigné par le connecteur quand le résultat révèle l'identité
        // réelle du visiteur (ex: billing email d'une commande). Consommé
        // par MCPActionGateService pour déclencher VisitorIdentityService.
        public ?array $identity = null,
        // 🆕 Renseigné par les outils panier WooCommerce : décrit l'action à
        // rejouer dans le VRAI panier (session navigateur du visiteur), via
        // widget.js. Consommé par MCPActionGateService::notifyCartSync().
        public ?array $cartSync = null,
    ) {
    }

    public static function ok(array $data, ?string $summary = null, ?array $identity = null, ?array $cartSync = null): self
    {
        return new self(success: true, data: $data, humanSummary: $summary, identity: $identity, cartSync: $cartSync);
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
