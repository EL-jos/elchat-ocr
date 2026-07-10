<?php

namespace App\SocialChannels\Instagram;

use App\Models\Social\SocialAccount;
use App\Models\Social\SocialMessage;
use App\SocialChannels\Contracts\SocialChannelInterface;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class InstagramChannel implements SocialChannelInterface
{
    private string $version;

    public function __construct()
    {
        $this->version = config(
            'services.facebook.graph_version',
            'v25.0'
        );
    }

    public function connect(): void
    {
        //
    }

    public function disconnect(): void
    {
        //
    }

    public function fetchMessages(): array
    {
        return [];
    }

    public function sendReply(
        SocialAccount $account,
        SocialMessage $message
    ): array {

        $metadata = $message->metadata ?? [];

        /**
         * =========================================================
         * INSTAGRAM DM
         * =========================================================
         */
        if (!empty($metadata['sender_id'])) {

            return $this->sendDirectMessageReply(
                account: $account,
                recipientId: $metadata['sender_id'],
                message: $message->content
            );
        }

        /**
         * =========================================================
         * INSTAGRAM COMMENT REPLY
         * =========================================================
         */
        if (!empty($metadata['comment_id'])) {

            return $this->sendCommentReply(
                account: $account,
                commentId: $metadata['comment_id'],
                message: $message->content
            );
        }

        throw new RuntimeException(
            'Instagram target not found (missing sender_id or comment_id).'
        );
    }

    /**
     * =========================================================
     * DM Instagram (Messaging API via /me/messages)
     * =========================================================
     */
    private function sendDirectMessageReply(
        SocialAccount $account,
        string $recipientId,
        string $message
    ): array {

        $response = Http::timeout(30)
            ->post(
                "https://graph.facebook.com/{$this->version}/me/messages",
                [
                    'recipient' => [
                        'id' => $recipientId,
                    ],
                    'message' => [
                        'text' => $message,
                    ],
                    'messaging_type' => 'RESPONSE',
                    'access_token' => $account->access_token,
                ]
            );

        if (!$response->successful()) {

            throw new RuntimeException(
                '[Instagram DM ERROR] ' . $response->body()
            );
        }

        return $response->json();
    }

    /**
     * =========================================================
     * Réponse à un commentaire Instagram
     * =========================================================
     */
    private function sendCommentReply(
        SocialAccount $account,
        string $commentId,
        string $message
    ): array {

        $response = Http::timeout(30)
            ->post(
                "https://graph.facebook.com/{$this->version}/{$commentId}/replies",
                [
                    'message' => $message,
                    'access_token' => $account->access_token,
                ]
            );

        if (!$response->successful()) {

            throw new RuntimeException(
                '[Instagram COMMENT ERROR] ' . $response->body()
            );
        }

        return $response->json();
    }

    public function refreshToken(SocialAccount $account): void
    {
        //
    }

    public function getProvider(): string
    {
        return 'instagram';
    }
}
