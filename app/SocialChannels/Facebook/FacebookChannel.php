<?php

namespace App\SocialChannels\Facebook;

use App\Models\Social\SocialAccount;
use App\Models\Social\SocialMessage;
use App\SocialChannels\Contracts\SocialChannelInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class FacebookChannel implements SocialChannelInterface
{
    private string $version;

    public function __construct()
    {
        $this->version = config(
            'services.facebook.graph_version',
            'v23.0'
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

        Log::info("DANS FACEBOOK CHANNEL DANS SENDREPLY",[
            "message" => $message->toArray(),
            "metadata" => $message?->metadata ?? []
        ]);

        $metadata = $message->metadata ?? [];

        /**
         * COMMENTAIRE FACEBOOK
         */
        if (!empty($metadata['comment_id'])) {

            return $this->sendCommentReply(
                account: $account,
                commentId: $metadata['comment_id'],
                message: $message->content
            );
        }

        /**
         * MESSENGER
         */
        if (!empty($metadata['sender_id'])) {

            return $this->sendMessengerReply(
                account: $account,
                recipientId: $metadata['sender_id'],
                message: $message->content
            );
        }

        throw new RuntimeException(
            'Facebook target not found.'
        );
    }

    private function sendCommentReply(
        SocialAccount $account,
        string $commentId,
        string $message
    ): array {

        $response = Http::timeout(30)
            ->post(
                "https://graph.facebook.com/{$this->version}/{$commentId}/comments",
                [
                    'message' => $message,
                    'access_token' => $account->access_token,
                ]
            );

        if (!$response->successful()) {

            throw new RuntimeException(
                $response->body()
            );
        }

        return $response->json();
    }

    private function sendMessengerReply(
        SocialAccount $account,
        string $recipientId,
        string $message
    ): array {

        $response = Http::timeout(30)
            ->post(
                "https://graph.facebook.com/{$this->version}/me/messages",
                [
                    'recipient' => [
                        'id' => $recipientId
                    ],
                    'message' => [
                        'text' => $message
                    ],
                    'messaging_type' => 'RESPONSE',
                    'access_token' => $account->access_token,
                ]
            );

        if (!$response->successful()) {

            throw new RuntimeException(
                $response->body()
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
        return 'facebook';
    }
}
