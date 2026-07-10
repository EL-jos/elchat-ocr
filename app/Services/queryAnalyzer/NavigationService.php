<?php

namespace App\Services\queryAnalyzer;

use App\Interfaces\ChatIntentHandlerInterface;
use App\Models\Conversation;
use App\Models\Site;

class NavigationService implements ChatIntentHandlerInterface
{

    /**
     * @inheritDoc
     */
    public function handle(string $question, Site $site, Conversation $conversation): string
    {
        $navigation = config("chatbot_navigation.{$site->type->slug}")
            ?? config("chatbot_navigation.default");

        /*// Simple recherche par mot-clé (peut être vectorisée plus tard)
        foreach ($navigation as $keyword => $url) {
            if (stripos($question, $keyword) !== false) {
                return "Vous pouvez trouver cette information ici : {$url}";
            }
        }

        return "Je n’ai pas trouvé de lien correspondant à votre question, mais voici la page principale du site : {$site->url}";*/
        return NavigationService::class;
    }
}
