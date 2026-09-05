<?php

namespace App\Domain\AIEngagement;

use App\Models\WidgetSetting;

class AIEngagementMessageStrategy
{
    public function create(array $context, WidgetSetting $settings): array
    {
        $page = $context['page'] ?? [];
        $intent = $context['intent'] ?? [];
        $allowed = array_values(array_filter($settings->ai_engagement_strategies ?: [
            'assistance', 'targeted_question', 'navigation', 'sales', 'support', 'booking', 'cta',
        ]));
        $pageType = $page['type'] ?? 'other';

        if ($pageType === 'pricing' && in_array('sales', $allowed, true)) {
            return ['strategy' => 'sales', 'message' => 'Vous comparez actuellement nos offres ? Je peux vous aider à trouver celle qui correspond le mieux à votre équipe.'];
        }
        if ($pageType === 'product' && in_array('navigation', $allowed, true)) {
            return ['strategy' => 'navigation', 'message' => 'Vous explorez cette solution ? Je peux vous aider à vérifier si elle correspond à votre besoin.'];
        }
        if (($intent['friction'] ?? false) && in_array('support', $allowed, true)) {
            return ['strategy' => 'support', 'message' => 'Vous cherchez une information précise ? Je peux vous orienter rapidement.'];
        }
        if ($pageType === 'contact' && in_array('booking', $allowed, true)) {
            return ['strategy' => 'booking', 'message' => 'Vous préparez un échange avec notre équipe ? Je peux vous aider à organiser la prochaine étape.'];
        }
        if (!empty($context['visitor']['is_returning']) && in_array('assistance', $allowed, true)) {
            return ['strategy' => 'assistance', 'message' => 'Content de vous revoir 👋 Vous souhaitez reprendre votre recherche ?'];
        }
        if (in_array('targeted_question', $allowed, true) && ($intent['commercial'] ?? false)) {
            return ['strategy' => 'targeted_question', 'message' => 'Vous recherchez plutôt une solution pour une petite équipe ou une organisation plus large ?'];
        }

        return ['strategy' => 'assistance', 'message' => 'Vous cherchez une information précise ? Je peux vous orienter.'];
    }
}
