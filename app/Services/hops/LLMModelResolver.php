<?php

namespace App\Services\hops;

final class LLMModelResolver
{
    /** Identifiants historiques encore présents dans certains .env de production. */
    private const MODEL_ALIASES = [
        'deepseek/deepseek-v3.1' => 'deepseek/deepseek-chat-v3.1',
    ];

    public static function normalize(?string $model, string $default = ''): string
    {
        $model = trim((string) $model);

        if ($model === '') {
            return $default;
        }

        return self::MODEL_ALIASES[$model] ?? $model;
    }
}
