<?php
namespace App\Domain\Proactive\Delivery\Channels;
use App\Services\Social\SocialReplyDispatcher;
class InstagramChannel extends AbstractSocialChannel
{
    public function __construct(SocialReplyDispatcher $dispatcher) { parent::__construct($dispatcher, ['instagram']); }
    public function channel(): string { return 'instagram'; }
}
