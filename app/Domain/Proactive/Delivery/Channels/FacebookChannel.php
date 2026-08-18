<?php
namespace App\Domain\Proactive\Delivery\Channels;
use App\Services\Social\SocialReplyDispatcher;
class FacebookChannel extends AbstractSocialChannel
{
    public function __construct(SocialReplyDispatcher $dispatcher) { parent::__construct($dispatcher, ['facebook']); }
    public function channel(): string { return 'facebook'; }
}
