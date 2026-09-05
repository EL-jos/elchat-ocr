<?php

namespace App\Domain\MCP\Connectors\Microsoft365;

use App\Domain\MCP\Contracts\ToolResult;
use App\Domain\MCP\Contracts\ToolSchema;
use App\Domain\MCP\Exceptions\ToolNotFoundException;
use App\Domain\Microsoft365\MicrosoftGraphClient;

final class ContactsModule extends AbstractMicrosoft365Module
{
    public function key(): string { return 'contacts'; }

    public function label(): string { return 'Contacts Outlook'; }

    public function iconUrl(): ?string { return 'https://upload.wikimedia.org/wikipedia/commons/c/cc/Microsoft_Outlook_Icon_%282025%E2%80%93present%29.svg'; }

    /** @return ToolSchema[] */
    public function listTools(): array
    {
        return [
            $this->readTool('contacts_search', 'Recherche des contacts personnels dans Outlook.', ['query' => ['type' => 'string'], 'top' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]], ['query'], 'contacts.read'),
            $this->readTool('contacts_get', 'Récupère un contact Outlook précis.', ['contact_id' => ['type' => 'string']], ['contact_id'], 'contacts.read'),
            $this->writeTool('contacts_create', 'Crée un contact personnel Outlook après confirmation.', ['display_name' => ['type' => 'string'], 'email' => ['type' => 'string'], 'phone' => ['type' => 'string'], 'company' => ['type' => 'string']], ['display_name'], 'contacts.create', 'confirm'),
        ];
    }

    /** @return array<string, list<string>> */
    protected function requiredScopes(): array
    {
        return ['contacts_search' => ['Contacts.Read'], 'contacts_get' => ['Contacts.Read'], 'contacts_create' => ['Contacts.ReadWrite']];
    }

    public function callTool(string $toolName, array $params, array $credentials, array $context, MicrosoftGraphClient $graph): ToolResult
    {
        return match ($toolName) {
            'contacts_search' => $this->search($graph, $params),
            'contacts_get' => $this->get($graph, $params),
            'contacts_create' => $this->create($graph, $params),
            default => throw new ToolNotFoundException("Outil '{$toolName}' inconnu pour le module Contacts Microsoft 365."),
        };
    }

    private function search(MicrosoftGraphClient $g, array $p): ToolResult
    {
        $query = str_replace("'", "''", (string) $p['query']);
        $contacts = $g->collectPages('/me/contacts', [
            '$filter' => "startswith(displayName,'{$query}')",
            '$top' => min(100, max(1, (int) ($p['top'] ?? 50))),
            '$select' => 'id,displayName,emailAddresses,businessPhones,mobilePhone,companyName,personalNotes',
        ]);
        return ToolResult::ok(['contacts' => $contacts], count($contacts) . ' contact(s) trouvé(s)');
    }

    private function get(MicrosoftGraphClient $g, array $p): ToolResult
    {
        $contact = $g->get('/me/contacts/' . $this->id($p['contact_id']), ['$select' => 'id,displayName,emailAddresses,businessPhones,mobilePhone,companyName,personalNotes']);
        return ToolResult::ok(['contact' => $contact], 'Contact Outlook récupéré.');
    }

    private function create(MicrosoftGraphClient $g, array $p): ToolResult
    {
        $contact = $g->post('/me/contacts', array_filter([
            'displayName' => (string) $p['display_name'],
            'emailAddresses' => !empty($p['email']) && filter_var($p['email'], FILTER_VALIDATE_EMAIL) ? [['address' => $p['email'], 'name' => $p['display_name']]] : null,
            'businessPhones' => !empty($p['phone']) ? [(string) $p['phone']] : null,
            'companyName' => $p['company'] ?? null,
        ], static fn ($value): bool => $value !== null));
        return ToolResult::ok(['contact' => $contact], 'Contact Outlook créé.');
    }
}
