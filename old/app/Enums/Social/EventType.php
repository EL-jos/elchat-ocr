<?php

namespace App\Enums\Social;

enum EventType: string
{
    case COMMENT_RECEIVED = 'comment_received';

    case MESSAGE_RECEIVED = 'message_received';

    case REPLY_PUBLISHED = 'reply_published';

    case TOKEN_REFRESHED = 'token_refreshed';

    case SYNC_FAILED = 'sync_failed';
}
