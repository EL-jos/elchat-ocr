<?php

namespace App\Domain\Sales;

use App\Models\Sales\Prospect;
use App\Models\Sales\ProspectingCampaign;
use App\Models\Sales\ProspectingConfig;

/**
 * Implémente la checklist du §21 du cahier des charges. N'exécute rien
 * elle-même — répond juste "autorisé ou non, et pourquoi". La vérification
 * PermissionEngine (connecteur/outil) reste TOUJOURS le dernier mot avant
 * exécution réelle, via le chemin normal MCPActionGateService — cette
 * classe est une couche EN AMONT, jamais un remplacement.
 */
class ProspectingPolicyEngine
{
    /** Un bounce transitoire n'exclut jamais un prospect — seul un échec définitif le fait. */
    private const BLOCKING_EMAIL_STATUSES = ['bounced_hard', 'complained'];

    public function canDiscover(ProspectingConfig $config, ProspectingCampaign $campaign): PolicyDecision
    {
        if (!$config->is_active) {
            return PolicyDecision::deny('config_inactive', "Configuration de prospection désactivée.");
        }
        if (!$config->agent->is_active) {
            return PolicyDecision::deny('agent_inactive', "Agent Sales Hunter désactivé sur ce site.");
        }

        $alreadyToday = Prospect::where('campaign_id', $campaign->id)
            ->whereDate('created_at', today())->count();
        $dailyLimit = $config->limitFor('max_new_prospects_per_day', 20);

        if ($alreadyToday >= $dailyLimit) {
            return PolicyDecision::deny('daily_limit_reached', "Limite quotidienne de nouveaux prospects atteinte ({$dailyLimit}).");
        }

        return PolicyDecision::allow();
    }

    public function canContact(ProspectingConfig $config, Prospect $prospect): PolicyDecision
    {
        if (!$prospect->isContactable()) {
            return PolicyDecision::deny('do_not_contact', "Ce prospect a demandé à ne plus être contacté.");
        }

        // Distinct de do_not_contact : ici c'est l'ADRESSE qui est invalide
        // (rejet définitif ou plainte), pas une décision du prospect.
        if (in_array($prospect->email_status, self::BLOCKING_EMAIL_STATUSES, true)) {
            return PolicyDecision::deny('invalid_email_address', "L'adresse email de ce prospect a généré un {$this->emailStatusLabel($prospect->email_status)} — contact bloqué.");
        }

        if (!$this->withinAllowedHours($config)) {
            return PolicyDecision::deny('outside_allowed_hours', "Hors des horaires de prospection autorisés.");
        }

        $sentToday = \App\Models\Sales\ProspectMessage::whereHas('prospect', fn ($q) => $q->where('campaign_id', $prospect->campaign_id))
            ->where('direction', 'outbound')->whereIn('status', ['approved', 'accepted', 'delivered'])
            ->whereDate('created_at', today())->count();
        $dailyLimit = $config->limitFor('max_outbound_actions_per_day', 20);

        if ($sentToday >= $dailyLimit) {
            return PolicyDecision::deny('daily_outbound_limit_reached', "Limite quotidienne d'actions sortantes atteinte ({$dailyLimit}).");
        }

        return PolicyDecision::allow();
    }

    /** Le mode d'autonomie de la campagne détermine si une action sortante s'exécute directement ou attend une validation. */
    public function requiresHumanApproval(ProspectingConfig $config): bool
    {
        return $config->autonomy_mode !== 'autonomous';
    }

    private function withinAllowedHours(ProspectingConfig $config): bool
    {
        $allowed = $config->limits['allowed_hours'] ?? null;
        if (!$allowed) return true;

        $currentHour = (int) now()->format('H');
        return $currentHour >= ($allowed['from'] ?? 0) && $currentHour < ($allowed['to'] ?? 24);
    }

    private function emailStatusLabel(?string $status): string
    {
        return match ($status) {
            'complained' => 'signalement comme spam',
            'bounced_hard' => 'rejet définitif (adresse invalide)',
            default => 'problème de délivrabilité',
        };
    }
}
