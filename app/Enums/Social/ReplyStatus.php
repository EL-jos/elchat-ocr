<?php

namespace App\Enums\Social;

enum ReplyStatus: string
{
    case PENDING = 'pending';

    case APPROVED = 'approved';

    case REJECTED = 'rejected';

    case PUBLISHED = 'published';

    case FAILED = 'failed';

    case PROCESSING = 'processing';
}
