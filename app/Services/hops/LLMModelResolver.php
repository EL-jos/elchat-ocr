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

    /**
     * Retourne le principal puis les modèles de secours d'une tâche.
     * Les options d'appel restent prioritaires pour préserver la compatibilité
     * avec les appelants qui choisissent explicitement un modèle.
     *
     * @return array<int, string>
     */
    public static function modelsForTask(string $task, array $overrides = []): array
    {
        $taskConfig = (array) config("llm.tasks.{$task}", config('llm.tasks.chat', []));

        $primary = array_key_exists('model', $overrides)
            ? $overrides['model']
            : ($taskConfig['model'] ?? null);

        $fallbacks = [];
        if (array_key_exists('fallback_models', $overrides)) {
            $fallbacks = (array) $overrides['fallback_models'];
        } elseif (array_key_exists('fallback_model', $overrides)) {
            $fallbacks = [$overrides['fallback_model']];
        } elseif (array_key_exists('fallback_models', $taskConfig)) {
            $fallbacks = (array) $taskConfig['fallback_models'];
        } else {
            $fallbacks = [$taskConfig['fallback_model'] ?? null];
        }

        $models = [$primary, ...$fallbacks];

        return array_values(array_unique(array_filter(array_map(
            fn ($model) => self::normalize(is_string($model) ? $model : null),
            $models,
        ), fn (string $model) => $model !== '')));
    }

    public static function modelForTask(string $task, array $overrides = []): string
    {
        return self::modelsForTask($task, $overrides)[0] ?? '';
    }

    /**
     * Résout les délais HTTP d'une tâche avec la priorité suivante :
     * options de l'appel > configuration de la tâche > configuration globale.
     *
     * `timeout` reste accepté comme alias historique du délai de requête,
     * mais les nouveaux appels doivent utiliser `request_timeout`.
     *
     * @return array{connect_timeout: int, request_timeout: int}
     */
    public static function timeoutsForTask(string $task, array $overrides = []): array
    {
        $taskConfig = (array) config("llm.tasks.{$task}", config('llm.tasks.chat', []));
        $providerConfig = (array) config('llm.provider', []);

        $connectTimeout = self::firstConfiguredValue([
            $overrides['connect_timeout'] ?? null,
            $taskConfig['connect_timeout'] ?? null,
            $providerConfig['connect_timeout'] ?? null,
        ], 10);

        $requestTimeout = self::firstConfiguredValue([
            $overrides['request_timeout'] ?? null,
            $overrides['timeout'] ?? null,
            $taskConfig['request_timeout'] ?? null,
            $taskConfig['timeout'] ?? null,
            $providerConfig['request_timeout'] ?? null,
            // Compatibilité avec une configuration intermédiaire utilisant
            // encore `llm.provider.timeout`.
            $providerConfig['timeout'] ?? null,
        ], 120);

        $requestTimeout = max(1, $requestTimeout);

        return [
            // Un délai de connexion supérieur au délai total n'a pas de sens
            // et serait neutralisé par Guzzle.
            'connect_timeout' => min(max(1, $connectTimeout), $requestTimeout),
            'request_timeout' => $requestTimeout,
        ];
    }

    private static function firstConfiguredValue(array $values, int $default): int
    {
        foreach ($values as $value) {
            if ($value !== null && $value !== '') {
                return (int) $value;
            }
        }

        return $default;
    }
}
