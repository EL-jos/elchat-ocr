<?php

namespace App\Domain\AIEngagement;

use App\Models\WidgetSetting;
use App\Services\ia\ResponseLanguageResolver;

class AIEngagementMessageStrategy
{
    private const MESSAGES = [
        'assistance' => [
            'fr' => 'Vous cherchez une information précise ? Je peux vous orienter.',
            'en' => 'Looking for specific information? I can point you in the right direction.',
            'pt' => 'Procura uma informação específica? Posso ajudar a orientá-lo.',
            'es' => '¿Buscas información concreta? Puedo orientarte.',
        ],
        'support' => [
            'fr' => 'Vous cherchez une information précise ? Je peux vous orienter rapidement.',
            'en' => 'Looking for specific information? I can point you in the right direction.',
            'pt' => 'Procura uma informação específica? Posso ajudá-lo rapidamente.',
            'es' => '¿Buscas información concreta? Puedo orientarte rápidamente.',
        ],
        'sales' => [
            'fr' => 'Vous comparez actuellement nos offres ? Je peux vous aider à trouver celle qui correspond le mieux à votre équipe.',
            'en' => 'Comparing our plans? I can help you find the one that best fits your team.',
            'pt' => 'Está a comparar as nossas ofertas? Posso ajudá-lo a encontrar a que melhor se adapta à sua equipa.',
            'es' => '¿Estás comparando nuestros planes? Puedo ayudarte a encontrar el que mejor se adapte a tu equipo.',
        ],
        'navigation' => [
            'fr' => 'Vous explorez cette solution ? Je peux vous aider à vérifier si elle correspond à votre besoin.',
            'en' => 'Exploring this solution? I can help you see whether it fits your needs.',
            'pt' => 'Está a explorar esta solução? Posso ajudá-lo a verificar se corresponde às suas necessidades.',
            'es' => '¿Estás explorando esta solución? Puedo ayudarte a comprobar si se ajusta a tus necesidades.',
        ],
        'booking' => [
            'fr' => 'Vous préparez un échange avec notre équipe ? Je peux vous aider à organiser la prochaine étape.',
            'en' => 'Planning a conversation with our team? I can help you arrange the next step.',
            'pt' => 'Está a preparar uma conversa com a nossa equipa? Posso ajudá-lo a organizar o próximo passo.',
            'es' => '¿Estás preparando una conversación con nuestro equipo? Puedo ayudarte a organizar el siguiente paso.',
        ],
        'returning' => [
            'fr' => 'Content de vous revoir 👋 Vous souhaitez reprendre votre recherche ?',
            'en' => 'Welcome back 👋 Would you like to pick up where you left off?',
            'pt' => 'Que bom tê-lo de volta 👋 Quer continuar de onde ficou?',
            'es' => 'Qué bueno verte de nuevo 👋 ¿Quieres continuar donde lo dejaste?',
        ],
        'targeted_question' => [
            'fr' => 'Vous recherchez plutôt une solution pour une petite équipe ou une organisation plus large ?',
            'en' => 'Are you looking for a solution for a small team or a larger organization?',
            'pt' => 'Procura uma solução para uma equipa pequena ou para uma organização maior?',
            'es' => '¿Buscas una solución para un equipo pequeño o para una organización más grande?',
        ],
    ];

    public function __construct(private readonly ResponseLanguageResolver $languages) {}

    public function create(array $context, WidgetSetting $settings): array
    {
        $page = $context['page'] ?? [];
        $intent = $context['intent'] ?? [];
        $allowed = array_values(array_filter($settings->ai_engagement_strategies ?: [
            'assistance', 'targeted_question', 'navigation', 'sales', 'support', 'booking', 'cta',
        ]));
        $pageType = $page['type'] ?? 'other';

        $strategy = match (true) {
            $pageType === 'pricing' && in_array('sales', $allowed, true) => 'sales',
            $pageType === 'product' && in_array('navigation', $allowed, true) => 'navigation',
            ($intent['friction'] ?? false) && in_array('support', $allowed, true) => 'support',
            $pageType === 'contact' && in_array('booking', $allowed, true) => 'booking',
            !empty($context['visitor']['is_returning']) && in_array('assistance', $allowed, true) => 'returning',
            in_array('targeted_question', $allowed, true) && ($intent['commercial'] ?? false) => 'targeted_question',
            default => 'assistance',
        };
        $messageKey = $strategy;
        if ($strategy === 'returning') {
            $strategy = 'assistance';
        }

        $locale = $this->languages->resolve(
            allowedLanguages: $settings->bot_language,
            browserLanguages: data_get($context, 'visitor.browser_languages'),
        );

        return [
            'strategy' => $strategy,
            'message_key' => $messageKey,
            'message' => $this->messageForStrategy($messageKey, $locale)
                ?? $this->messageForStrategy($messageKey, 'fr')
                ?? self::MESSAGES['assistance']['fr'],
        ];
    }

    public function messageForStrategy(string $strategy, string $locale): ?string
    {
        return self::MESSAGES[$strategy][$locale] ?? null;
    }
}
