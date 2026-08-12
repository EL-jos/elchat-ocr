<?php

namespace App\Domain\Sales;

use App\Models\Sales\Prospect;

/**
 * Scoring déterministe à règles pondérées — jamais un jugement LLM
 * (§10 du cahier des charges : "ne jamais produire un score arbitraire").
 * Chaque règle produit un couple (points, raison lisible), et le score
 * final est la somme brute plafonnée à 100.
 *
 * Les règles n'utilisent QUE les données réellement présentes sur le
 * prospect ou dans l'ICP — jamais une supposition. Une règle dont la
 * donnée nécessaire est absente ne s'applique simplement pas (ni bonus ni
 * malus), plutôt que de deviner.
 */
class ProspectScoringService
{
    /**
     * @param array $icp {sector, company_type, location, company_size, custom_criteria}
     * @param array $websiteSignals résultat optionnel de WebsiteIntelligenceService::analyze()
     * @return array{score:int, reasons:array<array{points:int,reason:string}>}
     */
    public function score(Prospect $prospect, array $icp, array $websiteSignals = []): array
    {
        $reasons = [];

        if (!empty($icp['sector']) && !empty($prospect->sector)) {
            if (mb_strtolower($prospect->sector) === mb_strtolower($icp['sector'])) {
                $reasons[] = ['points' => 20, 'reason' => "Secteur correspondant à l'ICP ({$prospect->sector})"];
            }
        }

        if (!empty($icp['location']) && !empty($prospect->location)) {
            if (str_contains(mb_strtolower($prospect->location), mb_strtolower($icp['location']))) {
                $reasons[] = ['points' => 20, 'reason' => "Localisation correspondante ({$prospect->location})"];
            }
        }

        if (!empty($websiteSignals)) {
            if (empty($websiteSignals['has_chatbot'])) {
                $reasons[] = ['points' => 15, 'reason' => "Aucun chatbot détecté sur le site — besoin potentiel non couvert"];
            }
            if (!empty($websiteSignals['contact_form_only'])) {
                $reasons[] = ['points' => 15, 'reason' => "Formulaire de contact classique uniquement, pas d'assistant conversationnel"];
            }
            if (!empty($websiteSignals['social_activity_score']) && $websiteSignals['social_activity_score'] >= 3) {
                $reasons[] = ['points' => 10, 'reason' => "Forte activité sociale détectée ({$websiteSignals['social_activity_score']} canaux actifs)"];
            }
            if (empty($websiteSignals['has_competitor_solution'])) {
                $reasons[] = ['points' => 10, 'reason' => "Aucune solution concurrente détectée sur le site"];
            }
        }

        if (!empty($icp['company_size']) && !empty($prospect->crm_ref['company_size'] ?? null)) {
            if ($prospect->crm_ref['company_size'] === $icp['company_size']) {
                $reasons[] = ['points' => 7, 'reason' => "Taille d'entreprise pertinente"];
            }
        }

        $score = min(100, array_sum(array_column($reasons, 'points')));

        return ['score' => $score, 'reasons' => $reasons];
    }
}
