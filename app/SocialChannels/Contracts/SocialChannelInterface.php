<?php

namespace App\SocialChannels\Contracts;

use App\Models\Social\SocialAccount;
use App\Models\Social\SocialMessage;

interface SocialChannelInterface
{
    public function connect(): void;

    public function disconnect(): void;

    /**
     * Synchronise les nouveaux messages/commentaires
     */
    public function fetchMessages(): array;

    /**
     * Publication d'une réponse.
     *
     * Le Channel décide lui-même comment publier
     * selon les metadata du message.
     */
    public function sendReply(
        SocialAccount $account,
        SocialMessage $message
    ): array;

    /**
     * Rafraîchissement OAuth
     */
    public function refreshToken(SocialAccount $account): void;

    /**
     * facebook
     * youtube
     * instagram
     * whatsapp
     */
    public function getProvider(): string;
}
