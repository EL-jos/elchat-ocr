<?php

namespace App\Domain\AIEngagement;

use App\Models\WidgetSetting;

class AIEngagementScorer
{
    public function evaluate(array $context, WidgetSetting $settings): array
    {
        $page = $context['page'] ?? [];
        $behavior = $context['behavior'] ?? [];
        $intent = $context['intent'] ?? [];
        $history = $context['history'] ?? [];
        $session = $context['session'] ?? [];
        $score = 0;
        $signals = [];

        $add = function (string $key, int $points, string $reason) use (&$score, &$signals): void {
            $score += $points;
            $signals[$key] = ['points' => $points, 'reason' => $reason];
        };

        $pageType = (string) ($page['type'] ?? 'other');
        if ($pageType === 'pricing') $add('pricing_page', 28, 'Page tarif/offres consultée');
        if ($pageType === 'product') $add('product_page', 20, 'Page produit/solution consultée');
        if ($pageType === 'contact') $add('contact_page', 18, 'Page de contact ou de démonstration consultée');
        if ($pageType === 'support') $add('support_context', 16, 'Contexte support détecté');
        if ($pageType === 'documentation') $add('documentation_context', 8, 'Ressource documentaire consultée');

        $pageCount = (int) ($session['unique_page_count'] ?? $session['page_count'] ?? 0);
        if ($pageCount >= 2) $add('multi_page_journey', min(20, 8 + (($pageCount - 2) * 4)), 'Parcours multi-pages');
        if ((int) ($session['duration_seconds'] ?? 0) >= (int) $settings->ai_engagement_min_session_seconds) {
            $add('sustained_session', 15, 'Session suffisamment engagée');
        }
        if ((int) ($page['time_on_page_seconds'] ?? 0) >= 20) $add('time_on_page', 12, 'Temps de consultation significatif');
        if ((int) ($behavior['scroll_depth'] ?? 0) >= 50) $add('scroll_depth', 6, 'Contenu parcouru en profondeur');
        if ((int) ($behavior['clicks'] ?? 0) >= 2) $add('active_clicks', 6, 'Activité de navigation observée');
        if ((int) ($behavior['cta_clicks'] ?? 0) > 0) $add('cta_interest', 18, 'CTA déjà utilisé');
        if ((int) ($behavior['products_viewed'] ?? 0) > 0) $add('product_interest', 14, 'Produit consulté');
        if (($intent['level'] ?? 'low') === 'medium') $add('medium_intent', 18, 'Intention moyenne');
        if (($intent['level'] ?? 'low') === 'high') $add('high_intent', 35, 'Intention élevée');
        if (!empty($intent['friction'])) $add('friction_signal', 22, 'Friction ou question non résolue');
        if (!empty($context['visitor']['is_returning'])) $add('returning_visitor', 10, 'Visiteur récurrent');

        if ($pageType === 'home' && $pageCount < (int) $settings->ai_engagement_min_pages && (int) ($session['duration_seconds'] ?? 0) < (int) $settings->ai_engagement_min_session_seconds) {
            $add('new_homepage_short_visit', -45, 'Homepage et visite trop courte');
        }
        if (($intent['level'] ?? 'low') === 'low') $add('low_intent', -12, 'Aucun signal d’intention fort');
        if (($history['has_active_conversation'] ?? false)) $add('active_conversation', -100, 'Conversation active récente');
        if (!empty($history['active_proactive_message_id'])) $add('competing_proactive_message', -100, 'Engagement proactif déjà en cours');

        $fatigue = $this->fatigue($context, $settings);
        if ($fatigue['blocked']) $add('fatigue_block', -100, $fatigue['reason']);

        $score = max(0, min(100, $score));
        $hardBlock = ($history['has_active_conversation'] ?? false)
            || !empty($history['active_proactive_message_id'])
            || $fatigue['blocked'];
        $enoughJourney = $pageCount >= (int) $settings->ai_engagement_min_pages
            || (int) ($session['duration_seconds'] ?? 0) >= (int) $settings->ai_engagement_min_session_seconds
            || ($intent['level'] ?? 'low') !== 'low';
        $contextualOpportunity = in_array($pageType, ['pricing', 'product', 'contact', 'support'], true)
            || !empty($intent['commercial'])
            || !empty($intent['support'])
            || !empty($intent['friction']);

        if ($hardBlock) {
            $decision = 'do_not_engage';
            $reason = $fatigue['blocked'] ? $fatigue['reason'] : 'Une conversation ou un engagement existe déjà.';
        } elseif (!$enoughJourney || !$contextualOpportunity) {
            $decision = 'wait';
            $reason = 'Contexte encore insuffisant pour une prise de parole pertinente.';
        } elseif ($score >= (int) $settings->ai_engagement_min_score && ($intent['level'] ?? 'low') !== 'low') {
            $decision = 'engage_now';
            $reason = 'Contexte qualifié : intention et interaction suffisantes pour une prise de parole ciblée.';
        } else {
            $decision = 'wait';
            $reason = 'Signaux positifs présents mais opportunité encore trop faible ou incomplète.';
        }

        $signals['fatigue'] = $fatigue;
        $signals['decision_gates'] = [
            'enough_journey' => $enoughJourney,
            'contextual_opportunity' => $contextualOpportunity,
            'hard_block' => $hardBlock,
        ];

        return [
            'decision' => $decision,
            'score' => $score,
            'intent_level' => $intent['level'] ?? 'low',
            'page_type' => $pageType,
            'reason' => $reason,
            'signals' => $signals,
        ];
    }

    private function fatigue(array $context, WidgetSetting $settings): array
    {
        $history = $context['history'] ?? [];
        $now = now();
        $lastEngagementAt = data_get($history, 'last_engagement_at');
        $lastCloseAt = data_get($history, 'last_close_at');
        $lastRefusalAt = data_get($history, 'last_refusal_at');
        $lastEngagement = $lastEngagementAt ? $now->diffInSeconds($lastEngagementAt) : null;
        $lastClose = $lastCloseAt ? $now->diffInSeconds($lastCloseAt) : null;
        $lastRefusal = $lastRefusalAt ? $now->diffInSeconds($lastRefusalAt) : null;

        if ($lastRefusal !== null && $lastRefusal < (int) $settings->ai_engagement_refusal_cooldown_seconds) {
            return ['blocked' => true, 'reason' => 'Le visiteur a refusé un engagement récemment.', 'status' => 'refusal_cooldown'];
        }
        if ($lastClose !== null && $lastClose < (int) $settings->ai_engagement_close_cooldown_seconds) {
            return ['blocked' => true, 'reason' => 'Le widget a été fermé récemment.', 'status' => 'close_cooldown'];
        }
        if ($lastEngagement !== null && $lastEngagement < (int) $settings->ai_engagement_cooldown_seconds) {
            return ['blocked' => true, 'reason' => 'Un engagement récent impose une période de calme.', 'status' => 'engagement_cooldown'];
        }

        return ['blocked' => false, 'reason' => null, 'status' => 'allowed'];
    }
}
