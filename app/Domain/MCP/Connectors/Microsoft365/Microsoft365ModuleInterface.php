<?php

namespace App\Domain\MCP\Connectors\Microsoft365;

use App\Domain\MCP\Contracts\ToolResult;
use App\Domain\MCP\Contracts\ToolSchema;
use App\Domain\Microsoft365\MicrosoftGraphClient;

/**
 * Contrat d'un module applicatif Microsoft 365.
 *
 * Chaque application expose uniquement ses outils et sait les exécuter.
 * Le connecteur Microsoft365Connector ne fait ensuite que router les appels,
 * comme le connecteur Odoo route les appels vers ses modules métier.
 */
interface Microsoft365ModuleInterface
{
    public function key(): string;

    public function label(): string;

    public function iconUrl(): ?string;

    public function supportsTools(): bool;

    public function availabilityMessage(): ?string;

    /** @return ToolSchema[] */
    public function listTools(): array;

    /** @return ToolSchema[] */
    public function toolsAvailableFor(array $credentials): array;

    public function callTool(string $toolName, array $params, array $credentials, array $context, MicrosoftGraphClient $graph): ToolResult;
}
