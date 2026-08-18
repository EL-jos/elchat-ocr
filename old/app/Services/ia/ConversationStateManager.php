<?php

namespace App\Services\ia;

use App\Models\Conversation;

class ConversationStateManager
{
    public function handle(string $intent, Conversation $conversation): ?string
    {
        if ($conversation->status === 'closed') {
            return "La conversation est terminée.
Merci de démarrer une nouvelle discussion si besoin.";
        }

        if ($intent === 'closing') {
            $conversation->status = 'resolved';
            $conversation->save();

            return "Merci pour votre échange.
Nous restons à votre disposition si besoin.";
        }

        if ($intent === 'confirmation') {
            return "Très bien.
N’hésitez pas à nous solliciter si vous avez besoin d’informations complémentaires.";
        }

        if ($intent === 'greeting') {
            return "Bonjour.
Comment pouvons-nous vous aider aujourd’hui ?";
        }

        return null; // continuer vers RAG
    }
}
