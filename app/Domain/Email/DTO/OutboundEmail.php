<?php

namespace App\Domain\Email\DTO;

final readonly class OutboundEmail
{
    public function __construct(
        public string $to,
        public string $from,
        public string $fromName,
        public string $subject,
        public string $textBody,
        /** @var array<string,string> en-têtes additionnels (ex: References pour le threading) */
        public array $headers = [],
    ) {
    }
}
