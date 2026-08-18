<?php
namespace App\Domain\Proactive\Delivery\Channels;
use App\Services\Social\SocialReplyDispatcher;
class TelegramChannel extends AbstractSocialChannel
{
    public function __construct(SocialReplyDispatcher $dispatcher) { parent::__construct($dispatcher, ['telegram']); }
    public function channel(): string { return 'telegram'; }
}
