<?php

namespace App\Domain\MCP\Audit;

use App\Domain\MCP\Contracts\ToolResult;
use App\Models\Mcp\McpAuditLog;
use App\Models\Site;

/**
 * Journalise chaque tentative d'appel MCP. Filtre les champs sensibles
 * (tokens, secrets, mots de passe) avant écriture — jamais de PII brute
 * au-delà de ce qui est strictement utile au debug.
 */
class AuditLogger
{
    private const SENSITIVE_KEYS = ['password', 'token', 'secret', 'consumer_key', 'consumer_secret', 'access_token', 'refresh_token'];

    public function log(
        Site $site,
        string $connectorSlug,
        string $toolName,
        array $inputParams,
        string $permissionMode,
        string $status,
        ?ToolResult $result = null,
        ?int $durationMs = null,
        ?string $errorCode = null,
        ?string $conversationId = null,
        ?int $hopNumber = null,
    ): McpAuditLog {
        return McpAuditLog::create([
            'site_id' => $site->id,
            'conversation_id' => $conversationId,
            'connector_slug' => $connectorSlug,
            'tool_name' => $toolName,
            'input_params' => $this->scrub($inputParams),
            'output_summary' => $result ? ['summary' => $result->humanSummary, 'success' => $result->success] : null,
            'permission_mode' => $permissionMode,
            'status' => $status,
            'duration_ms' => $durationMs,
            'error_code' => $errorCode,
            'hop_number' => $hopNumber,
        ]);
    }

    private function scrub(array $params): array
    {
        foreach ($params as $key => $value) {
            if (is_array($value)) {
                $params[$key] = $this->scrub($value);
                continue;
            }
            foreach (self::SENSITIVE_KEYS as $sensitive) {
                if (str_contains(strtolower((string) $key), $sensitive)) {
                    $params[$key] = '***redacted***';
                }
            }
        }

        return $params;
    }
}
