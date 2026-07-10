<?php

namespace App\SocialChannels\Facebook;

class FacebookNormalizer
{
    public static function normalizeComment(array $comment, string $postId): array
    {
        return [
            'external_message_id' => $comment['id'],
            'external_user_id' => $comment['from']['id'] ?? null,
            'external_username' => $comment['from']['name'] ?? null,
            'content' => $comment['message'] ?? '',
            'source_object_id' => $postId,
            'metadata' => $comment,
            'published_at' => $comment['created_time'] ?? null,
        ];
    }
}
