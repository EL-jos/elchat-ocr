<?php

namespace App\Enums;

enum CtaAction: string
{
    case OPEN_URL = 'open_url';
    case NAVIGATE = 'navigate';
    case SEND_MESSAGE = 'send_message';
    case EMAIL = 'email';
    case PHONE = 'phone';
    case WHATSAPP = 'whatsapp';
    case OPEN_FORM = 'open_form';
    case TRIGGER_EVENT = 'trigger_event';
}
