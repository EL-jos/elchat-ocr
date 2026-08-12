<?php

namespace App\Domain\Email\DTO;

final readonly class EmailSendResult
{
    private function __construct(
        public bool $accepted,
        public ?string $providerMessageId = null,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
    ) {
    }

    /** L'API du fournisseur a accepté la requête — ne signifie PAS "délivré". */
    public static function accepted(string $providerMessageId): self
    {
        return new self(true, $providerMessageId);
    }

    public static function failed(string $errorCode, string $errorMessage): self
    {
        return new self(false, errorCode: $errorCode, errorMessage: $errorMessage);
    }
}
