<?php

namespace App\Domain\MCP\Connectors\Microsoft365;

use App\Domain\MCP\Contracts\ToolResult;
use App\Domain\MCP\Contracts\ToolSchema;
use App\Domain\MCP\Exceptions\ToolNotFoundException;
use App\Domain\Microsoft365\MicrosoftGraphClient;

/**
 * Module de catalogue pour une application sans API Graph publique adaptée à
 * ce connecteur. Il reste visible dans l’architecture, mais aucun faux outil
 * n’est exposé au modèle ou à l’exécution.
 */
final class UnavailableMicrosoft365Module extends AbstractMicrosoft365Module
{
    public function __construct(
        private readonly string $moduleKey,
        private readonly string $moduleLabel,
        private readonly string $message,
        private readonly ?string $moduleIcon = null,
    ) {
    }

    public function key(): string { return $this->moduleKey; }

    public function label(): string { return $this->moduleLabel; }

    public function iconUrl(): ?string { return $this->moduleIcon; }

    public function supportsTools(): bool { return false; }

    public function availabilityMessage(): ?string { return $this->message; }

    /** @return ToolSchema[] */
    public function listTools(): array { return []; }

    /** @return ToolSchema[] */
    protected function requiredScopes(): array { return []; }

    public function callTool(string $toolName, array $params, array $credentials, array $context, MicrosoftGraphClient $graph): ToolResult
    {
        throw new ToolNotFoundException("Aucun outil n’est disponible pour le module {$this->moduleLabel}.");
    }
}
