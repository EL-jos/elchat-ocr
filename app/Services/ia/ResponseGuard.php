<?php

namespace App\Services\ia;

use App\Models\Conversation;

class ResponseGuard
{
    public function validate(string $response, Conversation $conversation): string
    {
        $lastBotMessage = $conversation->messages()
            ->where('role', 'bot')
            ->latest()
            ->first();

        if ($lastBotMessage && trim($lastBotMessage->content) === trim($response)) {
            return "Nous restons à votre disposition si besoin d’informations supplémentaires.";
        }

        if (mb_strlen($response) < 5) {
            return "Nous restons disponibles pour toute question complémentaire.";
        }

        return $response;
    }
}
