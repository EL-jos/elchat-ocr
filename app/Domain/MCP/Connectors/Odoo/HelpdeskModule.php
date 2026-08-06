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
            new ToolSchema('odoo', 'helpdesk_create_ticket',
                "Crée un nouveau ticket de support Odoo pour signaler un incident, une demande ou une question. Utiliser uniquement lorsqu'un nouveau ticket est nécessaire. Vérifier que le sujet, la description et les informations du contact sont disponibles avant la création. Si un ticket similaire semble déjà exister, rechercher les tickets existants avant d'en créer un nouveau afin d'éviter les doublons. Ne jamais créer un ticket à partir d'informations supposées ou incomplètes. Utiliser exclusivement les données et l'identifiant retournés par l'ERP.", [
                'type' => 'object', 'properties' => ['subject' => ['type' => 'string'], 'description' => ['type' => 'string'], 'contact_email' => ['type' => 'string']], 'required' => ['subject', 'description', 'contact_email'],
            ], isWriteAction: true, defaultMode: 'auto', capability: 'support.create_ticket'),

            new ToolSchema('odoo', 'helpdesk_get_ticket',
                "Récupère les informations détaillées d'un ticket de support identifié de manière unique, notamment son statut, son étape actuelle et sa description. Utiliser lorsque l'utilisateur souhaite consulter un ticket existant ou obtenir ses détails avant une autre action. Si l'identifiant est inconnu, rechercher d'abord le ticket. Ne jamais inventer un identifiant de ticket.", [
                'type' => 'object', 'properties' => ['ticket_id' => ['type' => 'integer']], 'required' => ['ticket_id'],
            ], defaultMode: 'auto'),

            new ToolSchema('odoo', 'helpdesk_update_ticket',
                "Met à jour un ticket de support existant identifié de manière unique. Modifier uniquement les champs explicitement demandés par l'utilisateur. Si l'identifiant du ticket est inconnu, rechercher d'abord le ticket. En cas de plusieurs correspondances, demander une clarification avant toute modification. Ne jamais créer un nouveau ticket à la place d'une mise à jour.", [
                'type' => 'object', 'properties' => ['ticket_id' => ['type' => 'integer'], 'description' => ['type' => 'string']], 'required' => ['ticket_id'],
            ], isWriteAction: true, defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('odoo', 'helpdesk_close_ticket',
                "Clôture un ticket de support existant identifié de manière unique. Utiliser uniquement lorsque l'utilisateur demande explicitement de fermer, résoudre ou clôturer le ticket. Si plusieurs tickets correspondent, demander une clarification avant l'exécution. Ne jamais clôturer un ticket par supposition ni clôturer plusieurs tickets sans confirmation explicite. Utiliser uniquement le résultat retourné par Odoo.", [
                'type' => 'object', 'properties' => ['ticket_id' => ['type' => 'integer']], 'required' => ['ticket_id'],
            ], isWriteAction: true, defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('odoo', 'helpdesk_search_tickets',
                "Recherche des tickets de support selon leur sujet ou un texte fourni par l'utilisateur. Utiliser pour retrouver un ticket avant une consultation, une mise à jour ou une clôture lorsque son identifiant est inconnu. Si plusieurs tickets correspondent, demander une clarification avant toute autre action. Ne jamais supposer qu'un ticket est unique.", [
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
