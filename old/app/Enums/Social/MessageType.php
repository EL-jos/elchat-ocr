<?php

namespace App\Enums\Social;

enum MessageType: string
{
    case TEXT = 'text';

    case IMAGE = 'image';

    case VIDEO = 'video';

    case DOCUMENT = 'document';
}
