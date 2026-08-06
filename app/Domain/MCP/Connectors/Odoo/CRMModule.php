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
            new ToolSchema('odoo', 'crm_create_contact',
                "Crée un nouveau contact Odoo ou retourne le contact existant lorsque l'adresse e-mail est déjà enregistrée. Utiliser lorsque l'utilisateur souhaite ajouter un nouveau contact et qu'une adresse e-mail valide est disponible. Cet outil évite automatiquement les doublons à partir de l'adresse e-mail. Ne pas appeler crm_find_contact au préalable sauf si l'utilisateur souhaite uniquement vérifier l'existence d'un contact sans le créer. Ne jamais inventer une adresse e-mail ou créer un contact à partir d'informations incomplètes.", [
                'type' => 'object', 'properties' => ['name' => ['type' => 'string'], 'email' => ['type' => 'string'], 'phone' => ['type' => 'string'], 'company_name' => ['type' => 'string']], 'required' => ['email'],
            ], isWriteAction: true, defaultMode: 'auto', capability: 'crm.create_or_update_contact'),

            new ToolSchema('odoo', 'crm_find_contact',
                "Vérifie si un contact associé à une adresse e-mail existe dans Odoo sans créer ni modifier de données. Utiliser lorsque l'utilisateur souhaite uniquement confirmer l'existence d'un contact ou obtenir cette information avant une décision métier. Ne pas utiliser pour créer un contact ; utiliser crm_create_contact dans ce cas.", [
                'type' => 'object', 'properties' => ['email' => ['type' => 'string']], 'required' => ['email'],
            ], defaultMode: 'auto'),

            new ToolSchema('odoo', 'crm_create_lead',
                "Crée une nouvelle opportunité commerciale dans Odoo. Si une adresse e-mail est fournie et qu'un contact correspondant existe, l'opportunité sera automatiquement associée à ce contact ; sinon, l'adresse e-mail sera enregistrée comme prospect. Utiliser uniquement lorsqu'une nouvelle opportunité doit être créée. Vérifier que le nom de l'opportunité est connu avant l'appel. Ne jamais créer plusieurs opportunités identiques sans demande explicite.", [
                'type' => 'object', 'properties' => ['name' => ['type' => 'string'], 'contact_email' => ['type' => 'string'], 'description' => ['type' => 'string'], 'expected_revenue' => ['type' => 'number']], 'required' => ['name'],
            ], isWriteAction: true, defaultMode: 'auto', capability: 'crm.create_opportunity'),

            new ToolSchema('odoo', 'crm_qualify_lead',
                "Met à jour la qualification commerciale d'une opportunité existante (chaud, tiède ou froid). Utiliser uniquement lorsqu'une opportunité est identifiée de manière unique et que l'utilisateur souhaite modifier sa qualification. Ne jamais qualifier une opportunité par déduction ou sans instruction explicite.", [
                'type' => 'object', 'properties' => ['lead_id' => ['type' => 'integer'], 'temperature' => ['type' => 'string', 'enum' => ['chaud', 'tiède', 'froid']]], 'required' => ['lead_id', 'temperature'],
            ], isWriteAction: true, defaultMode: 'auto', capability: 'crm.qualify_lead'),

            new ToolSchema('odoo', 'crm_get_lead',
                "Récupère les informations détaillées d'une opportunité identifiée de manière unique, notamment son client associé, son étape commerciale, sa probabilité de succès et son chiffre d'affaires prévisionnel. Utiliser lorsque l'utilisateur souhaite consulter l'état d'une opportunité avant une autre action. Ne jamais inventer un identifiant d'opportunité.", [
                'type' => 'object', 'properties' => ['lead_id' => ['type' => 'integer']], 'required' => ['lead_id'],
            ], defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('odoo', 'crm_search_leads',
                "Recherche des opportunités commerciales selon leur nom ou un texte fourni par l'utilisateur. Utiliser pour retrouver une opportunité avant une consultation, une qualification ou une autre action lorsque son identifiant est inconnu. Si plusieurs opportunités correspondent, demander une clarification avant de poursuivre. Ne jamais supposer qu'une opportunité est unique.", [
                'type' => 'object', 'properties' => ['query' => ['type' => 'string']], 'required' => ['query'],
            ], defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('odoo', 'crm_log_activity',
                "Ajoute une note ou un commentaire à une opportunité existante afin d'enrichir son historique. Utiliser lorsque l'utilisateur souhaite enregistrer une information, un échange ou un suivi sans modifier les propriétés de l'opportunité. Vérifier que l'opportunité est identifiée de manière unique avant l'ajout.", [
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
