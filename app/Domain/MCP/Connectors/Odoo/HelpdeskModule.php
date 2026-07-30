<?php

namespace App\Domain\MCP\Connectors\Odoo;

use App\Domain\MCP\Contracts\{ToolResult, ToolSchema};
use App\Domain\MCP\Exceptions\ToolNotFoundException;

class HelpdeskModule implements OdooModuleInterface
{
    public function technicalModuleName(): string { return 'helpdesk'; }

    public function listTools(): array
    {
        return [
            new ToolSchema('odoo', 'helpdesk_create_ticket', "Ouvre un ticket de support.", [
                'type' => 'object', 'properties' => ['subject' => ['type' => 'string'], 'description' => ['type' => 'string'], 'contact_email' => ['type' => 'string']], 'required' => ['subject', 'description', 'contact_email'],
            ], isWriteAction: true, defaultMode: 'auto', capability: 'support.create_ticket'),

            new ToolSchema('odoo', 'helpdesk_get_ticket', "Statut d'un ticket.", [
                'type' => 'object', 'properties' => ['ticket_id' => ['type' => 'integer']], 'required' => ['ticket_id'],
            ], defaultMode: 'auto'),

            new ToolSchema('odoo', 'helpdesk_update_ticket', "Modifie un ticket existant.", [
                'type' => 'object', 'properties' => ['ticket_id' => ['type' => 'integer'], 'description' => ['type' => 'string']], 'required' => ['ticket_id'],
            ], isWriteAction: true, defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('odoo', 'helpdesk_close_ticket', "Clôture un ticket.", [
                'type' => 'object', 'properties' => ['ticket_id' => ['type' => 'integer']], 'required' => ['ticket_id'],
            ], isWriteAction: true, defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('odoo', 'helpdesk_search_tickets', "Recherche libre de tickets.", [
                'type' => 'object', 'properties' => ['query' => ['type' => 'string']], 'required' => ['query'],
            ], defaultActorScope: 'admin', defaultMode: 'auto'),
        ];
    }

    public function callTool(string $toolName, array $params, array $credentials, array $context, OdooClient $client): ToolResult
    {
        return match ($toolName) {
            'helpdesk_create_ticket' => $this->createTicket($params, $client),
            'helpdesk_get_ticket' => $this->getTicket($params, $client),
            'helpdesk_update_ticket' => $this->updateTicket($params, $client),
            'helpdesk_close_ticket' => $this->closeTicket($params, $client),
            'helpdesk_search_tickets' => $this->searchTickets($params, $client),
            default => throw new ToolNotFoundException("Outil '{$toolName}' inconnu pour le module Helpdesk Odoo."),
        };
    }

    private function createTicket(array $p, OdooClient $client): ToolResult
    {
        $partner = $client->searchRead('res.partner', [['email', '=', $p['contact_email']]], ['id'], 1)[0] ?? null;

        $id = $client->create('helpdesk.ticket', array_filter([
            'name' => $p['subject'], 'description' => $p['description'], 'partner_id' => $partner['id'] ?? null, 'partner_email' => $p['contact_email'],
        ]));
        return ToolResult::ok(['ticket_id' => $id], "Ticket « {$p['subject']} » créé, référence #{$id}.", identity: ['email' => $p['contact_email']]);
    }

    private function getTicket(array $p, OdooClient $client): ToolResult
    {
        $ticket = $client->read('helpdesk.ticket', (int) $p['ticket_id'], ['name', 'stage_id', 'description']);
        if (!$ticket) return ToolResult::fail('not_found', 'Ticket introuvable.');
        return ToolResult::ok($ticket, 'Ticket récupéré.');
    }

    private function updateTicket(array $p, OdooClient $client): ToolResult
    {
        $client->write('helpdesk.ticket', (int) $p['ticket_id'], array_filter(['description' => $p['description'] ?? null]));
        return ToolResult::ok(['ticket_id' => $p['ticket_id']], 'Ticket mis à jour.');
    }

    private function closeTicket(array $p, OdooClient $client): ToolResult
    {
        // ⚠️ Nom de méthode/étape de clôture variable selon version Odoo —
        // à ajuster si votre pipeline helpdesk a un stage "Terminé" différent.
        $client->call('helpdesk.ticket', 'action_close', [(int) $p['ticket_id']]);
        return ToolResult::ok(['ticket_id' => $p['ticket_id']], 'Ticket clôturé.');
    }

    private function searchTickets(array $p, OdooClient $client): ToolResult
    {
        $rows = $client->searchRead('helpdesk.ticket', [['name', 'ilike', $p['query']]], ['name', 'stage_id'], 10);
        if (empty($rows)) return ToolResult::fail('not_found', 'Aucun ticket trouvé.');
        return ToolResult::ok(['tickets' => $rows], count($rows) . ' ticket(s) trouvé(s)');
    }
}
