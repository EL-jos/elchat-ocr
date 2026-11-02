<?php

namespace App\Domain\MCP\Security;

use App\Domain\MCP\Contracts\ToolSchema;

/** Resolves connector effects from server-owned MCP metadata, never model input. */
final class ToolEffectResolver
{
    public function resolve(ToolSchema $tool): string
    {
        if ($tool->isWriteAction) return 'write';

        $qualifiedName = $tool->connectorSlug.'.'.$tool->name;
        $readOnlyTools = config('mcp.devpilot_read_only_tools', []);
        if (is_array($readOnlyTools) && in_array($qualifiedName, $readOnlyTools, true)) return 'read';

        return 'unknown';
    }
}
