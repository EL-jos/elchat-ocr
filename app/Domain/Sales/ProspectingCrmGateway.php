<?php

namespace App\Domain\Sales;

use App\Domain\MCP\Capability\CapabilityResolver;
use App\Domain\MCP\Contracts\ToolResult;
use App\Models\Conversation;
use App\Models\Site;
use App\Services\mcp\MCPActionGateService;

class ProspectingCrmGateway
{
    public function __construct(
        private readonly CapabilityResolver $capabilities,
        private readonly MCPActionGateService $gate,
    ) {}

    public function isAvailable(Site $site, ?string $connectorSlug): bool
    {
        return $this->tool($site, 'create', $connectorSlug) !== null
            && $this->tool($site, 'find', $connectorSlug) !== null;
    }

    public function find(Site $site, Conversation $conversation, string $email, ?string $connectorSlug = null): ToolResult
    {
        $tool = $this->tool($site, 'find', $connectorSlug);
        if (! $tool) {
            return ToolResult::fail('crm_unavailable', 'Aucun outil CRM de recherche n’est connecté.');
        }

        return $this->gate->executeToolDirectly($site, $conversation, $tool, ['email' => $email], systemActor: true);
    }

    public function create(Site $site, Conversation $conversation, array $candidate, ?string $connectorSlug = null): ToolResult
    {
        $tool = $this->tool($site, 'create', $connectorSlug);
        if (! $tool) {
            return ToolResult::fail('crm_unavailable', 'Aucun outil CRM de création de contact n’est connecté.');
        }

        $connector = explode('__', $tool, 2)[0];
        $name = trim((string) ($candidate['name'] ?? $candidate['company'] ?? ''));
        [$firstName, $lastName] = $this->splitName($name);
        $params = $connector === 'hubspot'
            ? array_filter([
                'firstname' => $firstName, 'lastname' => $lastName, 'email' => $candidate['email'] ?? null,
                'phone' => $candidate['phone'] ?? null, 'company' => $candidate['company'] ?? $name,
            ], fn ($value) => $value !== null && trim((string) $value) !== '')
            : array_filter([
                'name' => $name, 'email' => $candidate['email'] ?? null, 'phone' => $candidate['phone'] ?? null,
                'company_name' => $candidate['company'] ?? $name,
            ], fn ($value) => $value !== null && trim((string) $value) !== '');

        return $this->gate->executeToolDirectly($site, $conversation, $tool, $params, systemActor: true);
    }

    private function tool(Site $site, string $operation, ?string $connectorSlug): ?string
    {
        $keys = $operation === 'create'
            ? ['crm_create_contact', 'crm-create-contact']
            : ['crm_find_contact', 'crm-find-contact'];

        foreach ($keys as $key) {
            $tool = $this->capabilities->resolveToolName($site, $key);
            if ($tool && (! $connectorSlug || str_starts_with($tool, "{$connectorSlug}__"))) {
                return $tool;
            }
        }

        $suffixes = $operation === 'create' ? ['__create_contact', '__crm_create_contact'] : ['__find_contact', '__crm_find_contact'];

        return collect($this->capabilities->availableToolsCatalog($site))
            ->first(function (array $tool) use ($connectorSlug, $suffixes) {
                if ($connectorSlug && ($tool['connector']['slug'] ?? null) !== $connectorSlug) {
                    return false;
                }

                return collect($suffixes)->contains(fn ($suffix) => str_ends_with($tool['tool_name'], $suffix));
            })['tool_name'] ?? null;
    }

    private function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        if (count($parts) < 2) {
            return [$name, ''];
        }

        return [array_shift($parts), implode(' ', $parts)];
    }
}
