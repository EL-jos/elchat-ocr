<?php

namespace App\Domain\Email\DTO;

final readonly class InboundEmailMessage
{
    public function __construct(
        public string $from,
        public string $to,
        public string $subject,
        public string $textBody,
        public ?string $providerMessageId = null,
        public ?string $inReplyTo = null,
    ) {
    }
}
