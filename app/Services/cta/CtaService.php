<?php

namespace App\Services\cta;

use App\Enums\CtaAction;
use App\Models\ChatbotCta;
use Exception;

class CtaService
{
    public function create(array $data): ChatbotCta
    {
        $this->validate($data);

        return ChatbotCta::create($data);
    }

    protected function validate(array $data): void
    {
        if (!CtaAction::tryFrom($data['action'])) {
            throw new Exception("Invalid CTA action");
        }
    }
}
