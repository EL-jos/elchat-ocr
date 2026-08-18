<?php

namespace App\Services\conversation;

use App\Contracts\StyleHintRendererInterface;
use App\Enums\ConversationPace;
use App\Enums\ResponseDepth;
use App\Models\Site;

final class StyleHintRenderer implements StyleHintRendererInterface
{
    private const DEPTH_HINTS = [
        'minimal'  => "réponds en une phrase ou deux, sans développer",
        'short'    => "va droit à l'essentiel, une réponse courte suffit ici",
        'normal'   => "réponds de façon claire et complète, sans en dire plus que nécessaire",
        'detailed' => "tu peux structurer ta réponse en plusieurs points si cela aide à la clarté",
        'expert'   => "développe avec précision, l'utilisateur montre un intérêt approfondi pour ce sujet",
    ];

    private const PACE_HINTS = [
        'opening'   => "c'est le début de l'échange : donne l'essentiel et laisse la place à une relance plutôt que de tout couvrir d'un coup",
        'building'  => "l'utilisateur commence à préciser son besoin, tu peux développer un peu plus qu'au premier message",
        'engaged'   => "la conversation est déjà engagée, tu peux t'appuyer sur ce qui a déjà été dit sans tout répéter",
        'deepening' => "l'utilisateur pousse volontairement vers plus de détail, tu peux t'y engager pleinement",
    ];

    public function render(
        ResponseDepth $depth,
        ConversationPace $pace,
        bool $shouldOfferClarifyingQuestion,
        bool $suppressStockClosings,
        Site $site,
        ?string $roleSlug,
    ): array {
        $hints = [
            self::DEPTH_HINTS[$depth->label()],
            self::PACE_HINTS[$pace->value],
        ];

        if ($shouldOfferClarifyingQuestion) {
            $hints[] = "si une précision te permettrait de mieux répondre, tu peux la demander plutôt que de deviner";
        }

        if ($suppressStockClosings) {
            $hints[] = "varie tes formulations, évite les formules automatiques répétées comme \"n'hésitez pas\" ou \"je reste à votre disposition\"";
        }

        $roleModifiers = config('conversation_engine.role_modifiers', []);
        if ($roleSlug && !empty($roleModifiers[$roleSlug]['hint'])) {
            $hints[] = $roleModifiers[$roleSlug]['hint'];
        }

        $siteModifiers = config('conversation_engine.site_type_modifiers', []);
        $typeName = $site->type?->name;
        if ($typeName && !empty($siteModifiers[$typeName]['hint'])) {
            $hints[] = $siteModifiers[$typeName]['hint'];
        }

        return array_values(array_filter($hints));
    }
}
