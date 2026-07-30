<?php

namespace App\Domain\MCP\Connectors\Odoo;

use App\Domain\MCP\Contracts\ToolResult;
use App\Domain\MCP\Contracts\ToolSchema;

interface OdooModuleInterface
{
    /** Nom technique du module Odoo (tel qu'enregistré dans ir.module.module). */
    public function technicalModuleName(): string;

    /** @return ToolSchema[] */
    public function listTools(): array;

    public function callTool(string $toolName, array $params, array $credentials, array $context, OdooClient $client): ToolResult;
}
