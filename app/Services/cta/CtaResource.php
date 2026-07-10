<?php

namespace App\Services\cta;

use App\Models\ChatbotCta;

class CtaResource
{
    public static function make(ChatbotCta $cta): array
    {

        $rules = $cta->rules
            ->groupBy('rule_type')
            ->map(fn($group) => $group->pluck('rule_value')->values())
            ->toArray();

        return [
            'id' => $cta->id,
            'label' => $cta->label,
            'action' => $cta->action,
            'value' => $cta->value,
            'style' => $cta->style,
            // 🔥 STRUCTURE NORMALISÉE
            'rules' => [
                'intents'  => $rules['intent'] ?? [],
                'keywords' => $rules['keyword'] ?? [],
                'contexts' => $rules['context'] ?? [],
                'entities' => $rules['entity'] ?? [],
            ],
        ];
    }
}
