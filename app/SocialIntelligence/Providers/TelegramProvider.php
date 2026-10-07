<?php

namespace App\SocialIntelligence\Providers;

use App\Models\Social\SocialAccount;
use Illuminate\Support\Carbon;

class TelegramProvider extends AbstractProvider
{
    public function provider(): string { return 'telegram'; }

    public function capabilities(SocialAccount $account): array
    {
        $connected = $account->access_token ? 'available' : 'not_connected';
        return [
            'content' => $this->status('not_supported', 'Le Bot API ne fournit pas de flux analytique historique des publications d’un canal via le connecteur actuel.'),
            'views' => $this->status('not_supported', 'Aucune métrique de portée historique native disponible dans le Bot API.'),
            'likes' => $this->status('not_supported', 'Réactions non exposées comme métrique agrégée par le connecteur actuel.'),
            'comments' => $this->status($connected, 'Messages et commentaires reçus via le webhook Telegram ELChat.'),
            'shares' => $this->status('not_supported', 'Partages non exposés par le Bot API.'),
            'audience_growth' => $this->status('not_supported', 'Croissance d’audience non exposée par le Bot API.'),
            'messages' => $this->status($connected, 'Messages reçus par le webhook Telegram, conservés dans SocialConversation/SocialMessage.'),
        ];
    }

    public function sync(SocialAccount $account, Carbon $from, Carbon $to): array
    {
        $result = $this->unavailablePayload($account, $from, $to);
        $result['limitations'][] = 'Telegram est analysé à partir des messages reçus par le webhook ; l’API Bot ne permet pas de reconstituer un historique analytique de contenu.';
        return $result;
    }
}
