<?php

namespace App\Domain\MCP\Security;

use RuntimeException;

/** Fail-closed validator for the JSON Schema subset used by MCP tool inputs. */
final class ToolArgumentsValidator
{
    private const MAX_ARGUMENT_BYTES = 16384;
    private const MAX_DEPTH = 8;

    public function validate(array $schema, array $arguments): array
    {
        $encoded = json_encode($arguments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($encoded) > self::MAX_ARGUMENT_BYTES) throw new RuntimeException('Les paramètres du connecteur dépassent la limite autorisée.');
        $this->validateValue($schema, $arguments, '$', 0);

        return $arguments;
    }

    private function validateValue(array $schema, mixed $value, string $path, int $depth): void
    {
        if ($depth > self::MAX_DEPTH || isset($schema['$ref']) || isset($schema['oneOf']) || isset($schema['anyOf']) || isset($schema['allOf'])) {
            throw new RuntimeException('Le schéma du connecteur utilise une forme non prise en charge; opération bloquée.');
        }

        $types = $schema['type'] ?? null;
        if ($types !== null) {
            $types = is_array($types) ? $types : [$types];
            if ($types === [] || array_filter($types, static fn ($type): bool => ! in_array($type, ['object', 'array', 'string', 'integer', 'number', 'boolean', 'null'], true)) !== []) {
                throw new RuntimeException('Le schéma du connecteur contient un type non pris en charge.');
            }
            $matches = false;
            foreach ($types as $type) if ($this->matchesType((string) $type, $value)) { $matches = true; break; }
            if (! $matches) throw new RuntimeException("Le paramètre {$path} ne respecte pas son type déclaré.");
        }

        if (isset($schema['enum']) && is_array($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            throw new RuntimeException("Le paramètre {$path} n’est pas une valeur autorisée.");
        }

        if (is_string($value)) {
            $length = mb_strlen($value, 'UTF-8');
            if (isset($schema['minLength']) && $length < (int) $schema['minLength']) throw new RuntimeException("Le paramètre {$path} est trop court.");
            if (isset($schema['maxLength']) && $length > (int) $schema['maxLength']) throw new RuntimeException("Le paramètre {$path} dépasse la longueur autorisée.");
            $pattern = $schema['pattern'] ?? null;
            $pattern = is_string($pattern) ? str_replace('~', '\\~', $pattern) : null;
            if (isset($schema['pattern']) && ($pattern === null || @preg_match('~'.$pattern.'~u', $value) !== 1)) {
                throw new RuntimeException("Le paramètre {$path} ne respecte pas son format déclaré.");
            }
            $format = $schema['format'] ?? null;
            if ($format === 'email' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) throw new RuntimeException("Le paramètre {$path} doit être une adresse courriel valide.");
            if (in_array($format, ['uri', 'url'], true) && filter_var($value, FILTER_VALIDATE_URL) === false) throw new RuntimeException("Le paramètre {$path} doit être une URL valide.");
            if ($format !== null && ! in_array($format, ['email', 'uri', 'url', 'date-time', 'date', 'uuid'], true)) {
                throw new RuntimeException('Le schéma du connecteur utilise un format non pris en charge.');
            }
            if ($format === 'uuid' && ! \Illuminate\Support\Str::isUuid($value)) throw new RuntimeException("Le paramètre {$path} doit être un UUID valide.");
            if ($format === 'date' && strtotime($value) === false) throw new RuntimeException("Le paramètre {$path} doit être une date valide.");
            if ($format === 'date-time' && strtotime($value) === false) throw new RuntimeException("Le paramètre {$path} doit être une date/heure valide.");
        }

        if (is_int($value) || is_float($value)) {
            if (isset($schema['minimum']) && $value < (float) $schema['minimum']) throw new RuntimeException("Le paramètre {$path} est inférieur au minimum autorisé.");
            if (isset($schema['maximum']) && $value > (float) $schema['maximum']) throw new RuntimeException("Le paramètre {$path} dépasse le maximum autorisé.");
        }

        if (is_array($value) && ($schema['type'] ?? null) === 'object') {
            if (array_is_list($value) && $value !== []) throw new RuntimeException("Le paramètre {$path} doit être un objet.");
            $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
            $required = is_array($schema['required'] ?? null) ? $schema['required'] : [];
            foreach ($required as $key) if (! is_string($key) || ! array_key_exists($key, $value)) throw new RuntimeException("Le paramètre {$path}.{$key} est obligatoire.");
            foreach ($value as $key => $item) {
                if (! is_string($key) || preg_match('/^[A-Za-z0-9_.-]{1,128}$/D', $key) !== 1) throw new RuntimeException("Le paramètre {$path} contient un nom de champ invalide.");
                if (! array_key_exists($key, $properties)) {
                    $additional = $schema['additionalProperties'] ?? false;
                    if ($additional === true) {
                        $this->validateUnconstrainedValue($item, $path.'.'.$key, $depth + 1);
                    } elseif (is_array($additional) && $additional !== []) {
                        $this->validateValue($additional, $item, $path.'.'.$key, $depth + 1);
                    } else {
                        throw new RuntimeException("Le paramètre {$path}.{$key} n’est pas exposé par le schéma du connecteur.");
                    }
                    continue;
                }
                if (! is_array($properties[$key])) throw new RuntimeException('Le schéma du connecteur est mal formé.');
                $this->validateValue($properties[$key], $item, $path.'.'.$key, $depth + 1);
            }
        }

        if (is_array($value) && ($schema['type'] ?? null) === 'array') {
            if (isset($schema['minItems']) && count($value) < (int) $schema['minItems']) throw new RuntimeException("Le paramètre {$path} contient trop peu d’éléments.");
            if (isset($schema['maxItems']) && count($value) > (int) $schema['maxItems']) throw new RuntimeException("Le paramètre {$path} contient trop d’éléments.");
            if (isset($schema['items'])) {
                if (! is_array($schema['items'])) throw new RuntimeException('Le schéma du connecteur est mal formé.');
                foreach ($value as $index => $item) $this->validateValue($schema['items'], $item, $path.'['.$index.']', $depth + 1);
            }
        }
    }

    private function matchesType(string $type, mixed $value): bool
    {
        return match ($type) {
            'object' => is_array($value) && ($value === [] || ! array_is_list($value)),
            'array' => is_array($value) && array_is_list($value),
            'string' => is_string($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'null' => $value === null,
            default => false,
        };
    }

    private function validateUnconstrainedValue(mixed $value, string $path, int $depth): void
    {
        if ($depth > self::MAX_DEPTH) throw new RuntimeException("Le paramètre {$path} est trop profondément imbriqué.");
        if (is_array($value)) {
            if (count($value) > 256) throw new RuntimeException("Le paramètre {$path} contient trop d’éléments.");
            foreach ($value as $key => $child) {
                if (is_string($key) && preg_match('/^[A-Za-z0-9_.-]{1,128}$/D', $key) !== 1) throw new RuntimeException("Le paramètre {$path} contient un nom de champ invalide.");
                $this->validateUnconstrainedValue($child, $path.'.'.(string) $key, $depth + 1);
            }
            return;
        }
        if (is_string($value) && mb_strlen($value, 'UTF-8') > self::MAX_ARGUMENT_BYTES) {
            throw new RuntimeException("Le paramètre {$path} dépasse la longueur autorisée.");
        }
        if (! is_string($value) && ! is_int($value) && ! is_float($value) && ! is_bool($value) && $value !== null) {
            throw new RuntimeException("Le paramètre {$path} contient une valeur non prise en charge.");
        }
    }
}
