<?php

namespace App\Services\queryAnalyzer;

use App\Interfaces\ChatIntentHandlerInterface;
use App\Models\Conversation;
use App\Models\Site;
use Illuminate\Support\Facades\Http;

class LeadService implements ChatIntentHandlerInterface
{

    /**
     * @inheritDoc
     */
    public function handle(string $question, Site $site, Conversation $conversation): string
    {
        // Récupérer le formulaire ou l'action lead configuré pour ce site
        $leadConfig = config("chatbot_leads.{$site->type->slug}")
            ?? config("chatbot_leads.default");

        if (!$leadConfig) {
            return "Merci pour votre message. Un membre de notre équipe vous contactera bientôt.";
        }

        /*// Option 1: envoyer un formulaire web
        $formUrl = $leadConfig['form_url'] ?? null;

        // Option 2: trigger email
        $emailTemplate = $leadConfig['email_template'] ?? null;

        // Option 3: trigger webhook/action
        $webhook = $leadConfig['webhook'] ?? null;

        // Exemple: si form_url existe, on retourne un message
        if ($formUrl) {
            return "Vous pouvez compléter notre formulaire ici : {$formUrl}";
        }

        // Sinon si webhook défini
        if ($webhook) {
            // envoyer payload question + conversation
            Http::post($webhook, [
                'question' => $question,
                'conversation_id' => $conversation->id,
                'site_id' => $site->id
            ]);
            return "Merci, nous avons bien reçu votre demande et nous vous recontacterons.";
        }

        return "Merci pour votre message. Un membre de notre équipe vous contactera bientôt.";*/
        return LeadService::class;
    }
}
