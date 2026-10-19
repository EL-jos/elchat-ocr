<?php

namespace App\Domain\MCP\Connectors;

use App\Domain\MCP\Contracts\ToolResult;

/** Internal vault owner for Website Security Auditor data sources. */
final class WebsiteSecuritySourcesConnector extends AbstractConnector
{
    public function slug(): string
    {
        return 'website_security_sources';
    }

    /** Data-source credentials are managed only by the owner dashboard API. */
    public function listTools(): array
    {
        return [];
    }

    public function authenticate(array $credentials): array
    {
        return $credentials;
    }

    public function callTool(string $toolName, array $params, array $credentials, array $context = []): ToolResult
    {
        return ToolResult::fail('tool_not_available', 'Ce connecteur interne n’expose aucun outil de conversation.');
    }
}
