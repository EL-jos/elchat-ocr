<?php

namespace App\Domain\MCP\Connectors\Odoo;

use App\Domain\MCP\Contracts\{ToolResult, ToolSchema};
use App\Domain\MCP\Exceptions\ToolNotFoundException;

class AccountingModule implements OdooModuleInterface
{
    public function technicalModuleName(): string { return 'account'; }

    public function listTools(): array
    {
        return [
            new ToolSchema('odoo', 'accounting_get_invoice_status',
                "Récupère les informations détaillées d'une facture identifiée de manière unique, notamment son état, son statut de paiement et son montant total. Utiliser lorsque l'identifiant de la facture est connu ou après une recherche ayant permis d'identifier une seule facture. Ne jamais inventer un identifiant de facture ni déduire son état.", [
                'type' => 'object', 'properties' => ['invoice_id' => ['type' => 'integer']], 'required' => ['invoice_id'],
            ], defaultMode: 'auto'),

            new ToolSchema('odoo', 'accounting_search_invoices',
                "Recherche les factures associées à un contact identifié par son adresse e-mail. Utiliser lorsqu'un utilisateur souhaite retrouver une facture avant de consulter son état ou d'enregistrer un paiement, ou lorsque l'identifiant de la facture est inconnu. Si aucune facture n'est trouvée, ne pas supposer son existence. Utiliser uniquement les résultats retournés par l'ERP.", [
                'type' => 'object', 'properties' => ['contact_email' => ['type' => 'string']], 'required' => ['contact_email'],
            ], defaultMode: 'auto'),

            new ToolSchema('odoo', 'accounting_create_invoice',
                "Crée une nouvelle facture client en brouillon pour un contact existant et un produit identifié. Utiliser uniquement lorsque le client et le produit sont connus avec certitude. Si le contact ou le produit ne sont pas identifiés de manière unique, effectuer une recherche ou demander une clarification avant la création. Vérifier que les informations essentielles (client, produit, quantité) sont disponibles. Ne jamais créer une facture à partir d'informations supposées ni créer volontairement des factures en double. Utiliser uniquement les identifiants et données retournés par l'ERP.", [
                'type' => 'object', 'properties' => ['contact_email' => ['type' => 'string'], 'product_id' => ['type' => 'integer'], 'quantity' => ['type' => 'integer']], 'required' => ['contact_email', 'product_id'],
            ], isWriteAction: true, defaultActorScope: 'admin', defaultMode: 'confirm', defaultConfirmActor: 'admin'),

            new ToolSchema('odoo', 'accounting_record_payment',
                "Enregistre un paiement sur une facture existante identifiée de manière unique. Utiliser uniquement lorsque l'utilisateur indique explicitement qu'un paiement a été reçu et que le montant à enregistrer est connu. Vérifier que la facture existe avant l'enregistrement. Ne jamais inventer un montant, enregistrer un paiement approximatif ou enregistrer plusieurs paiements sans instruction explicite. Utiliser exclusivement les données retournées par l'ERP.", [
                'type' => 'object', 'properties' => ['invoice_id' => ['type' => 'integer'], 'amount' => ['type' => 'number']], 'required' => ['invoice_id', 'amount'],
            ], isWriteAction: true, defaultActorScope: 'admin', defaultMode: 'confirm', defaultConfirmActor: 'admin'),
        ];
    }

    public function callTool(string $toolName, array $params, array $credentials, array $context, OdooClient $client): ToolResult
    {
        return match ($toolName) {
            'accounting_get_invoice_status' => $this->getInvoiceStatus($params, $client),
            'accounting_search_invoices' => $this->searchInvoices($params, $client),
            'accounting_create_invoice' => $this->createInvoice($params, $client),
            'accounting_record_payment' => $this->recordPayment($params, $client),
            default => throw new ToolNotFoundException("Outil '{$toolName}' inconnu pour le module Accounting Odoo."),
        };
    }

    private function getInvoiceStatus(array $p, OdooClient $client): ToolResult
    {
        $invoice = $client->read('account.move', (int) $p['invoice_id'], ['name', 'state', 'payment_state', 'amount_total']);
        if (!$invoice) return ToolResult::fail('not_found', 'Facture introuvable.');
        return ToolResult::ok($invoice, "Facture {$invoice['name']} : {$invoice['payment_state']}");
    }

    private function searchInvoices(array $p, OdooClient $client): ToolResult
    {
        $partner = $client->searchRead('res.partner', [['email', '=', $p['contact_email']]], ['id'], 1)[0] ?? null;
        if (!$partner) return ToolResult::fail('not_found', 'Aucun contact trouvé pour cet email.');

        $rows = $client->searchRead('account.move', [['partner_id', '=', $partner['id']], ['move_type', '=', 'out_invoice']], ['name', 'state', 'payment_state', 'amount_total'], 10);
        if (empty($rows)) return ToolResult::fail('not_found', 'Aucune facture trouvée.');
        return ToolResult::ok(['invoices' => $rows], count($rows) . ' facture(s)', identity: ['email' => $p['contact_email']]);
    }

    private function createInvoice(array $p, OdooClient $client): ToolResult
    {
        $partner = $client->searchRead('res.partner', [['email', '=', $p['contact_email']]], ['id'], 1)[0] ?? null;
        if (!$partner) return ToolResult::fail('not_found', "Aucun contact trouvé pour {$p['contact_email']}.");

        $invoiceId = $client->create('account.move', [
            'move_type' => 'out_invoice', 'partner_id' => $partner['id'],
            'invoice_line_ids' => [[0, 0, ['product_id' => (int) $p['product_id'], 'quantity' => $p['quantity'] ?? 1]]],
        ]);
        return ToolResult::ok(['invoice_id' => $invoiceId], 'Facture créée en brouillon.');
    }

    private function recordPayment(array $p, OdooClient $client): ToolResult
    {
        $invoice = $client->read('account.move', (int) $p['invoice_id'], ['partner_id']);
        if (!$invoice) return ToolResult::fail('not_found', 'Facture introuvable.');

        $paymentId = $client->create('account.payment', [
            'partner_id' => $invoice['partner_id'][0], 'amount' => $p['amount'], 'payment_type' => 'inbound', 'partner_type' => 'customer',
        ]);
        $client->call('account.payment', 'action_post', [$paymentId]);

        return ToolResult::ok(['payment_id' => $paymentId], 'Paiement enregistré.');
    }
}
