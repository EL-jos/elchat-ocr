<?php
namespace App\Domain\Proactive\Delivery\Channels;
use App\Services\Social\SocialReplyDispatcher;
class EmailChannel extends AbstractSocialChannel
{
    public function __construct(SocialReplyDispatcher $dispatcher) { parent::__construct($dispatcher, ['gmail', 'outlook', 'imap']); }
    public function channel(): string { return 'email'; }
}
