<?php

namespace App\Domain\MCP\Connectors\Odoo;

use App\Domain\MCP\Contracts\{ToolResult, ToolSchema};
use App\Domain\MCP\Exceptions\ToolNotFoundException;

class CRMModule implements OdooModuleInterface
{
    public function technicalModuleName(): string { return 'crm'; }

    public function listTools(): array
    {
        return [
            new ToolSchema('odoo', 'crm_create_contact', "Crée ou retrouve un contact à partir de son email.", [
                'type' => 'object', 'properties' => ['name' => ['type' => 'string'], 'email' => ['type' => 'string'], 'phone' => ['type' => 'string'], 'company_name' => ['type' => 'string']], 'required' => ['email'],
            ], isWriteAction: true, defaultMode: 'auto', capability: 'crm.create_or_update_contact'),

            new ToolSchema('odoo', 'crm_find_contact', "Vérifie si un contact existe pour cet email.", [
                'type' => 'object', 'properties' => ['email' => ['type' => 'string']], 'required' => ['email'],
            ], defaultMode: 'auto'),

            new ToolSchema('odoo', 'crm_create_lead', "Crée une opportunité commerciale (ex: intérêt exprimé par le visiteur).", [
                'type' => 'object', 'properties' => ['name' => ['type' => 'string'], 'contact_email' => ['type' => 'string'], 'description' => ['type' => 'string'], 'expected_revenue' => ['type' => 'number']], 'required' => ['name'],
            ], isWriteAction: true, defaultMode: 'auto', capability: 'crm.create_opportunity'),

            new ToolSchema('odoo', 'crm_qualify_lead', "Qualifie un prospect (chaud/tiède/froid).", [
                'type' => 'object', 'properties' => ['lead_id' => ['type' => 'integer'], 'temperature' => ['type' => 'string', 'enum' => ['chaud', 'tiède', 'froid']]], 'required' => ['lead_id', 'temperature'],
            ], isWriteAction: true, defaultMode: 'auto', capability: 'crm.qualify_lead'),

            new ToolSchema('odoo', 'crm_get_lead', "Détails d'une opportunité.", [
                'type' => 'object', 'properties' => ['lead_id' => ['type' => 'integer']], 'required' => ['lead_id'],
            ], defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('odoo', 'crm_search_leads', "Recherche libre d'opportunités.", [
                'type' => 'object', 'properties' => ['query' => ['type' => 'string']], 'required' => ['query'],
            ], defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('odoo', 'crm_log_activity', "Journalise une note sur une opportunité.", [
                'type' => 'object', 'properties' => ['lead_id' => ['type' => 'integer'], 'note' => ['type' => 'string']], 'required' => ['lead_id', 'note'],
            ], isWriteAction: true, defaultActorScope: 'admin', defaultMode: 'auto', capability: 'crm.log_activity'),
        ];
    }

    public function callTool(string $toolName, array $params, array $credentials, array $context, OdooClient $client): ToolResult
    {
        return match ($toolName) {
            'crm_create_contact' => $this->createContact($params, $client),
            'crm_find_contact' => $this->findContact($params, $client),
            'crm_create_lead' => $this->createLead($params, $client),
            'crm_qualify_lead' => $this->qualifyLead($params, $client),
            'crm_get_lead' => $this->getLead($params, $client),
            'crm_search_leads' => $this->searchLeads($params, $client),
            'crm_log_activity' => $this->logActivity($params, $client),
            default => throw new ToolNotFoundException("Outil '{$toolName}' inconnu pour le module CRM Odoo."),
        };
    }

    private function createContact(array $p, OdooClient $client): ToolResult
    {
        $existing = $this->findPartner($p['email'], $client);
        if ($existing) return ToolResult::ok(['contact_id' => $existing['id']], 'Contact déjà existant.', identity: ['email' => $p['email']]);

        $id = $client->create('res.partner', array_filter([
            'name' => $p['name'] ?? $p['email'], 'email' => $p['email'], 'phone' => $p['phone'] ?? null, 'company_name' => $p['company_name'] ?? null,
        ]));
        return ToolResult::ok(['contact_id' => $id], 'Contact créé.', identity: ['email' => $p['email'], 'firstname' => $p['name'] ?? null, 'phone' => $p['phone'] ?? null]);
    }

    private function findContact(array $p, OdooClient $client): ToolResult
    {
        $partner = $this->findPartner($p['email'], $client);
        if (!$partner) return ToolResult::ok(['exists' => false], "Aucun contact n'existe avec cet email.");
        return ToolResult::ok(['exists' => true, 'name' => $partner['name']], 'Un contact existe avec cet email.', identity: ['email' => $p['email']]);
    }

    private function createLead(array $p, OdooClient $client): ToolResult
    {
        $values = array_filter(['name' => $p['name'], 'description' => $p['description'] ?? null, 'expected_revenue' => $p['expected_revenue'] ?? null]);

        if (!empty($p['contact_email'])) {
            $partner = $this->findPartner($p['contact_email'], $client);
            $values[$partner ? 'partner_id' : 'email_from'] = $partner['id'] ?? $p['contact_email'];
        }

        $id = $client->create('crm.lead', $values);
        return ToolResult::ok(['lead_id' => $id], "Opportunité « {$p['name']} » créée.", identity: !empty($p['contact_email']) ? ['email' => $p['contact_email']] : null);
    }

    private function qualifyLead(array $p, OdooClient $client): ToolResult
    {
        // ⚠️ Suppose un champ personnalisé x_elchat_temperature sur crm.lead
        // (à créer via Odoo Studio ou un module technique — même principe
        // que elchat_lead_temperature côté HubSpot).
        $client->write('crm.lead', (int) $p['lead_id'], ['x_elchat_temperature' => $p['temperature']]);
        return ToolResult::ok(['lead_id' => $p['lead_id']], "Prospect qualifié : {$p['temperature']}.");
    }

    private function getLead(array $p, OdooClient $client): ToolResult
    {
        $lead = $client->read('crm.lead', (int) $p['lead_id'], ['name', 'partner_id', 'expected_revenue', 'stage_id', 'probability']);
        if (!$lead) return ToolResult::fail('not_found', 'Opportunité introuvable.');
        return ToolResult::ok($lead, 'Opportunité récupérée.');
    }

    private function searchLeads(array $p, OdooClient $client): ToolResult
    {
        $rows = $client->searchRead('crm.lead', [['name', 'ilike', $p['query']]], ['name', 'expected_revenue', 'stage_id'], 10);
        if (empty($rows)) return ToolResult::fail('not_found', 'Aucune opportunité trouvée.');
        return ToolResult::ok(['leads' => $rows], count($rows) . ' opportunité(s) trouvée(s)');
    }

    private function logActivity(array $p, OdooClient $client): ToolResult
    {
        $client->call('crm.lead', 'message_post', [(int) $p['lead_id']], ['body' => $p['note']]);
        return ToolResult::ok(['lead_id' => $p['lead_id']], 'Note ajoutée.');
    }

    private function findPartner(string $email, OdooClient $client): ?array
    {
        return $client->searchRead('res.partner', [['email', '=', $email]], ['id', 'name'], 1)[0] ?? null;
    }
}
