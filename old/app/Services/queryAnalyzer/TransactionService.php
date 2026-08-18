<?php

namespace App\Services\queryAnalyzer;

use App\Interfaces\ChatIntentHandlerInterface;
use App\Models\Conversation;
use App\Models\Site;
use Illuminate\Support\Facades\Http;

class TransactionService implements ChatIntentHandlerInterface
{

    /**
     * @inheritDoc
     */
    public function handle(string $question, Site $site, Conversation $conversation): string
    {
        /*// Vérifier la config transactionnelle du site
        $transactionConfig = config("chatbot_transactions.{$site->type->slug}")
            ?? config("chatbot_transactions.default");

        if (!$transactionConfig) {
            return "Cette action n’est pas disponible sur ce site.";
        }

        $apiEndpoint = $transactionConfig['api_endpoint'] ?? null;

        if ($apiEndpoint) {
            $payload = [
                'question' => $question,
                'site_id' => $site->id,
                'conversation_id' => $conversation->id
            ];

            $response = Http::post($apiEndpoint, $payload);

            if ($response->successful()) {
                return $response->json()['message'] ?? "Votre action a été enregistrée avec succès.";
            } else {
                return "Nous n’avons pas pu traiter votre demande, veuillez réessayer plus tard.";
            }
        }

        return "Cette action n’est pas disponible pour le moment.";*/

        return TransactionService::class;
    }
}
