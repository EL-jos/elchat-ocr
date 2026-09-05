<?php
namespace App\Domain\Proactive\Delivery\Channels;
use App\Services\Social\SocialReplyDispatcher;
class YouTubeChannel extends AbstractSocialChannel
{
    public function __construct(SocialReplyDispatcher $dispatcher) { parent::__construct($dispatcher, ['youtube']); }
    public function channel(): string { return 'youtube'; }
}
