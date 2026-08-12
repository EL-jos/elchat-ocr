<?php

namespace App\Domain\Email\DTO;

final readonly class EmailEvent
{
    public function __construct(
        public string $type, // 'delivered' | 'bounced' | 'complained' | 'rejected' | 'opened' | 'clicked'
        public string $providerMessageId,
        public ?string $recipientEmail,
        public \DateTimeImmutable $occurredAt,
        // Pour les bounces : 'soft' | 'hard' | null — un bounce transitoire
        // ne doit jamais bloquer un prospect comme un bounce définitif.
        public ?string $subtype = null,
        public array $raw = [],
    ) {
    }

    public function isPermanentFailure(): bool
    {
        return $this->type === 'complained'
            || $this->type === 'rejected'
            || ($this->type === 'bounced' && $this->subtype === 'hard');
    }
}
