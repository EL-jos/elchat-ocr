<?php

namespace App\Enums\Social;

enum MessageDirection: string
{
    case INCOMING = 'incoming';

    case OUTGOING = 'outgoing';
}
