<?php

namespace App\Enums\Social;

enum SocialProvider: string
{
    case YOUTUBE = 'youtube';
    case FACEBOOK = 'facebook';
    case INSTAGRAM = 'instagram';
    case TIKTOK = 'tiktok';
    case WHATSAPP = 'whatsapp';
    case SLACK = 'slack';
    case TELEGRAM = 'telegram';
    case IMAP = 'imap';
    case GMAIL = 'gmail';
    case OUTLOOK = 'outlook';
}
