<?php

namespace App\Domain\MCP\Connectors;

use App\Domain\MCP\Contracts\ToolResult;
use App\Domain\MCP\Contracts\ToolSchema;
use App\Domain\MCP\Exceptions\ToolNotFoundException;
use App\Domain\Sales\Email\OutboundEmailSender;
use App\Domain\Sales\WebsiteIntelligenceService;
use App\Models\Sales\Prospect;
use App\Models\Sales\ProspectMessage;
use Illuminate\Support\Str;

/**
 * Connecteur interne (auth_type 'internal', comme ElchatPlatformConnector).
 *
 * ⚠️ Révision : 'draft_outreach_message' (rédiger, sans effet externe) et
 * 'send_prospect_message' (envoyer réellement) sont désormais DEUX outils
 * distincts, pas un seul. C'est ce qui permet aux 3 modes d'autonomie de
 * s'articuler avec le système de permissions EXISTANT sans rien dupliquer :
 * - Suggestion  : 'send_prospect_message' absent de agent.skills → le LLM
 *   ne peut physiquement pas l'appeler (AgentSkillResolver le filtre).
 * - Human approval : présent dans skills, mode='confirm' (défaut) → passe
 *   automatiquement par la file d'attente admin existante.
 * - Autonome : même outil, règle mcp_permissions basculée sur 'auto' par
 *   SalesProspectingController quand l'admin choisit ce mode.
 */
class SalesHunterConnector extends AbstractConnector
{
    public function __construct(
        private readonly WebsiteIntelligenceService $websiteIntelligence,
        private readonly OutboundEmailSender $emailSender,
    ) {
    }

    public function slug(): string
    {
        return 'sales_hunter';
    }

    public function listTools(): array
    {
        return [
            new ToolSchema('sales_hunter', 'analyze_website',
                "Analyse le site public d'un prospect pour identifier des signaux d'opportunité commerciale (absence de chatbot, formulaire de contact seul, forte activité sociale, solution concurrente détectée...). Utiliser UNE FOIS par prospect avant de rédiger un message, jamais pour analyser le site du tenant lui-même.",
                ['type' => 'object', 'properties' => [
                    'prospect_id' => ['type' => 'string'], 'website_url' => ['type' => 'string'],
                ], 'required' => ['prospect_id', 'website_url']],
                defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('sales_hunter', 'save_prospect_note',
                "Enregistre une observation qualitative sur un prospect. Ne remplace jamais une note existante, s'ajoute à l'historique.",
                ['type' => 'object', 'properties' => [
                    'prospect_id' => ['type' => 'string'], 'note' => ['type' => 'string'],
                ], 'required' => ['prospect_id', 'note']],
                defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('sales_hunter', 'update_prospect_status',
                "Change le statut d'un prospect suite à une interaction. Utiliser 'do_not_contact' dès qu'un prospect demande explicitement à ne plus être contacté — bloque irréversiblement toute prospection future envers lui.",
                ['type' => 'object', 'properties' => [
                    'prospect_id' => ['type' => 'string'],
                    'status' => ['type' => 'string', 'enum' => ['qualified', 'rejected', 'contacted', 'replied', 'interested', 'not_interested', 'meeting_booked', 'converted', 'do_not_contact']],
                    'reason' => ['type' => 'string'],
                ], 'required' => ['prospect_id', 'status', 'reason']],
                isWriteAction: true, defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('sales_hunter', 'draft_outreach_message',
                "Rédige un brouillon de message de prospection, à partir des informations RÉELLEMENT disponibles (jamais un prix, produit ou avantage inventé — si une information nécessaire manque, le signaler dans le message plutôt que de l'inventer). Ceci ne l'envoie PAS — utiliser send_prospect_message ensuite si l'outil est disponible.",
                ['type' => 'object', 'properties' => [
                    'prospect_id' => ['type' => 'string'], 'channel' => ['type' => 'string', 'enum' => ['email']],
                    'content' => ['type' => 'string'],
                ], 'required' => ['prospect_id', 'channel', 'content']],
                defaultActorScope: 'admin', defaultMode: 'auto'),

            new ToolSchema('sales_hunter', 'send_prospect_message',
                "Envoie RÉELLEMENT un message de prospection préalablement rédigé (draft_outreach_message). Action irréversible avec effet externe direct — n'est disponible pour un agent que si le mode d'autonomie de la campagne l'autorise.",
                ['type' => 'object', 'properties' => [
                    'prospect_id' => ['type' => 'string'], 'message_id' => ['type' => 'string'],
                ], 'required' => ['prospect_id', 'message_id']],
                isWriteAction: true, defaultActorScope: 'admin', defaultMode: 'confirm', defaultConfirmActor: 'admin',
                capability: 'sales-prospecting.send_message'),
        ];
    }

    public function authenticate(array $credentials): array
    {
        return $credentials; // connecteur interne, rien à authentifier
    }

    public function callTool(string $toolName, array $params, array $credentials, array $context = []): ToolResult
    {
        return match ($toolName) {
            'analyze_website' => $this->analyzeWebsite($params),
            'save_prospect_note' => $this->saveProspectNote($params),
            'update_prospect_status' => $this->updateProspectStatus($params),
            'draft_outreach_message' => $this->draftOutreachMessage($params),
            'send_prospect_message' => $this->sendProspectMessage($params),
            default => throw new ToolNotFoundException("Outil '{$toolName}' inconnu pour sales_hunter."),
        };
    }

    private function analyzeWebsite(array $p): ToolResult
    {
        $prospect = Prospect::find($p['prospect_id']);
        if (!$prospect) return ToolResult::fail('not_found', "Prospect '{$p['prospect_id']}' introuvable.");

        $signals = $this->websiteIntelligence->analyze($p['website_url']);
        return ToolResult::ok($signals, $this->summarizeSignals($signals));
    }

    private function saveProspectNote(array $p): ToolResult
    {
        $prospect = Prospect::find($p['prospect_id']);
        if (!$prospect) return ToolResult::fail('not_found', "Prospect '{$p['prospect_id']}' introuvable.");

        $prospect->touchActivity();
        return ToolResult::ok(['prospect_id' => $prospect->id], 'Note enregistrée.');
    }

    private function updateProspectStatus(array $p): ToolResult
    {
        $prospect = Prospect::find($p['prospect_id']);
        if (!$prospect) return ToolResult::fail('not_found', "Prospect '{$p['prospect_id']}' introuvable.");

        $prospect->update(['status' => $p['status']]);
        $prospect->touchActivity();

        return ToolResult::ok(
            ['prospect_id' => $prospect->id, 'status' => $p['status']],
            $p['status'] === 'do_not_contact'
                ? "Prospect marqué comme ne devant plus être contacté."
                : "Statut mis à jour : {$p['status']}.",
        );
    }

    private function draftOutreachMessage(array $p): ToolResult
    {
        $prospect = Prospect::find($p['prospect_id']);
        if (!$prospect) return ToolResult::fail('not_found', "Prospect '{$p['prospect_id']}' introuvable.");
        if (!$prospect->isContactable()) {
            return ToolResult::fail('do_not_contact', "Ce prospect a demandé à ne plus être contacté — aucun message ne peut être préparé.");
        }

        $message = ProspectMessage::create([
            'id' => (string) Str::uuid(), 'prospect_id' => $prospect->id,
            'channel' => $p['channel'], 'direction' => 'outbound', 'status' => 'draft', 'content' => $p['content'],
        ]);

        return ToolResult::ok(['message_id' => $message->id, 'status' => 'draft'], "Brouillon préparé pour {$prospect->name}.");
    }

    private function sendProspectMessage(array $p): ToolResult
    {
        $prospect = Prospect::find($p['prospect_id']);
        $draft = ProspectMessage::find($p['message_id']);

        if (!$prospect || !$draft || $draft->prospect_id !== $prospect->id) {
            return ToolResult::fail('not_found', "Prospect ou brouillon introuvable.");
        }
        if ($draft->status !== 'draft') {
            return ToolResult::fail('invalid_state', "Ce message n'est plus à l'état brouillon (statut actuel : {$draft->status}).");
        }
        if (!$prospect->isContactable()) {
            return ToolResult::fail('do_not_contact', "Ce prospect a demandé à ne plus être contacté.");
        }

        $this->emailSender->send($prospect, $draft);

        return ToolResult::ok(
            ['message_id' => $draft->id, 'status' => $draft->fresh()->status],
            $draft->fresh()->status === 'failed' ? "L'envoi a échoué." : "Message envoyé à {$prospect->name}.",
        );
    }

    private function summarizeSignals(array $signals): string
    {
        $notes = [];
        if (empty($signals['has_chatbot'])) $notes[] = "pas de chatbot";
        if (!empty($signals['contact_form_only'])) $notes[] = "formulaire de contact seul";
        if (!empty($signals['social_activity_score'])) $notes[] = "{$signals['social_activity_score']} canal/aux social/aux actif/s";

        return empty($notes) ? "Analyse du site terminée." : "Site analysé : " . implode(', ', $notes) . '.';
    }
}
