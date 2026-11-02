<?php

namespace App\Services\DevPilot;

final class DevPilotSecretRedactor
{
    /**
     * Match secret-bearing key segments as whole words. Separators and camelCase
     * are split in secretKeySegments(); short substrings such as "pass" in
     * "passenger", "bypass", or "passport" are never considered secret keys.
     */
    private const SECRET_SEGMENTS = [
        'password', 'passwd', 'pass', 'pwd', 'passphrase', 'secret', 'token',
    ];

    /** Last segments that identify metadata rather than a credential value. */
    private const NON_SECRET_METADATA_SEGMENTS = [
        'type', 'ttl', 'length', 'min', 'max', 'expires', 'lifetime',
        'field', 'name', 'label', 'url', 'path', 'rules',
    ];

    private const SECRET_COMPOUND_SEGMENTS = [
        ['api', 'key'], ['access', 'key'], ['private', 'key'],
        ['app', 'key'], ['client', 'secret'],
    ];

    private const SECRET_COMPOUND_ALIASES = [
        'apikey', 'accesskey', 'privatekey', 'appkey', 'clientsecret',
    ];

    private const VALIDATION_RULE_NAMES = [
        'accepted', 'accepted_if', 'active_url', 'after', 'alpha', 'alpha_dash', 'alpha_num',
        'array', 'ascii', 'bail', 'before', 'between', 'boolean', 'confirmed', 'current_password',
        'date', 'date_format', 'different', 'digits', 'distinct', 'email', 'ends_with', 'exclude',
        'exists', 'file', 'filled', 'gt', 'gte', 'image', 'in', 'integer', 'ip', 'ipv4', 'ipv6',
        'json', 'lt', 'lte', 'max', 'mimes', 'mimetypes', 'min', 'multiple_of', 'nullable',
        'numeric', 'present', 'prohibited', 'regex', 'required', 'required_if', 'required_unless',
        'required_with', 'required_without', 'same', 'size', 'sometimes', 'starts_with', 'string',
        'timezone', 'unique', 'url', 'uuid',
    ];

    private const PRIVATE_KEY_PATTERN = '/-----BEGIN (?:(?:RSA|EC|OPENSSH|DSA|PGP|ENCRYPTED) )?PRIVATE KEY(?: BLOCK)?-----[\s\S]*?-----END (?:(?:RSA|EC|OPENSSH|DSA|PGP|ENCRYPTED) )?PRIVATE KEY(?: BLOCK)?-----/i';

    private const RECOGNIZABLE_TOKEN_PATTERN = '/(?<![A-Za-z0-9])(?:AKIA[0-9A-Z]{16}|ASIA[0-9A-Z]{16}|gh[pousr]_[A-Za-z0-9_]{20,}|github_pat_[A-Za-z0-9_]{20,}|sk_(?:live|test)_[A-Za-z0-9]{12,}|rk_live_[A-Za-z0-9]{12,}|rk_test_[A-Za-z0-9]{12,}|sk-[A-Za-z0-9_-]{20,}|xox[baprs]-[A-Za-z0-9-]{10,}|AIza[0-9A-Za-z_-]{30,}|glpat-[A-Za-z0-9_-]{20,}|eyJ[A-Za-z0-9_-]+\.eyJ[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)?)(?![A-Za-z0-9])/';

    public function redact(string $content): string
    {
        $content = $this->redactRecognizableSecrets($content);

        $content = preg_replace_callback(
            '~\bdefine\s*\(\s*(?<key_quote>[\'"])(?<key>[A-Za-z0-9_$.-]+)\k<key_quote>\s*,\s*(?<value_quote>[\'"])(?<value>(?:\\\\.|(?!\k<value_quote>)[\s\S])*)\k<value_quote>~i',
            function (array $match): string {
                if (! $this->shouldRedactKeyValue($match['key'], $match['value'])) {
                    return $match[0];
                }

                $valueStart = strpos($match[0], $match['value_quote'], strpos($match[0], ',') + 1);
                if ($valueStart === false) return $match[0];

                return substr($match[0], 0, $valueStart + 1).'[REDACTED]'.$match['value_quote'];
            },
            $content,
        ) ?? $content;

        $content = preg_replace_callback(
            '~(?<![A-Za-z0-9_.-])(?<open>[\'"]?)(?<key>\$?[A-Za-z_][A-Za-z0-9_$.-]*)(?<close>[\'"]?)(?<separator>\s*(?:=>|=|:)\s*)(?:(?<value_open>[\'"])(?<quoted_value>(?:\\\\.|(?!\k<value_open>)[\s\S])*)\k<value_open>|(?<plain_value>[^\s,;}\]#\'"]+))~i',
            function (array $match): string {
                if (($match['open'] ?? '') !== ($match['close'] ?? '')) return $match[0];
                $quoted = ($match['value_open'] ?? '') !== '';
                $value = $quoted ? (string) ($match['quoted_value'] ?? '') : (string) ($match['plain_value'] ?? '');
                if (! $this->shouldRedactKeyValue((string) $match['key'], $value)) return $match[0];

                $valueQuote = $quoted ? (string) $match['value_open'] : '';
                return $match['open'].$match['key'].$match['close'].$match['separator']
                    .$valueQuote.'[REDACTED]'.$valueQuote;
            },
            $content,
        ) ?? $content;

        return $content;
    }

    /**
     * Redact secret forms in commands without interpreting arbitrary command
     * arguments as key/value configuration. Only connection credentials,
     * recognizable token formats, PEM private keys, bearer headers, MySQL
     * -p<password> options, and URLs containing credentials are handled here.
     */
    public function redactCommand(string $command, array $credentialSets = []): string
    {
        return $this->redactRecognizableSecrets($this->replaceKnownCredentialValues($command, $credentialSets));
    }

    public function commandContainsSecret(string $command, array $credentialSets = []): bool
    {
        return $this->redactCommand($command, $credentialSets) !== $command;
    }

    /** Replace literal credential values known to the server before persistence or LLM reuse. */
    public function redactKnownSecrets(string $content, array $credentialSets): string
    {
        return $this->redact($this->replaceKnownCredentialValues($content, $credentialSets));
    }

    /** True only when the content contains a credential value known to this connection. */
    public function containsKnownSecret(string $content, array $credentialSets): bool
    {
        return $this->replaceKnownCredentialValues($content, $credentialSets) !== $content;
    }

    private function replaceKnownCredentialValues(string $content, array $credentialSets): string
    {
        $values = [];
        foreach ($credentialSets as $credentials) {
            if (! is_array($credentials)) continue;
            $this->collectSecretValues($credentials, $values);
        }

        usort($values, static fn (string $left, string $right): int => strlen($right) <=> strlen($left));
        foreach (array_unique($values) as $secret) {
            // Ignore credentials shorter than four characters to avoid replacing
            // common fragments such as "yes", "off", or one-character values.
            if ($this->characterLength($secret) < 4) continue;
            foreach ($this->secretVariants($secret) as $variant) {
                if ($variant !== '') $content = str_replace($variant, '[REDACTED CREDENTIAL]', $content);
            }
        }

        return $content;
    }

    private function secretVariants(string $secret): array
    {
        $variants = [
            $secret,
            urlencode($secret),
            rawurlencode($secret),
            base64_encode($secret),
            escapeshellarg($secret),
            addslashes($secret),
        ];
        $json = json_encode($secret);
        if (is_string($json)) {
            $variants[] = $json;
            if (strlen($json) >= 2) $variants[] = substr($json, 1, -1);
        }

        return array_values(array_unique($variants));
    }

    private function characterLength(string $value): int
    {
        if (function_exists('mb_strlen')) {
            $length = mb_strlen($value, 'UTF-8');
            if (is_int($length)) return $length;
        }

        return strlen($value);
    }

    private function collectSecretValues(array $values, array &$secrets, ?string $parentKey = null): void
    {
        foreach ($values as $key => $value) {
            $key = is_string($key) ? $key : (string) $parentKey;
            if ($key !== '' && $this->isSecretKey($key) && is_string($value) && $value !== '') {
                $secrets[] = $value;
            } elseif (is_array($value)) {
                $this->collectSecretValues($value, $secrets, $key);
            }
        }
    }

    private function redactRecognizableSecrets(string $content): string
    {
        $content = preg_replace(self::PRIVATE_KEY_PATTERN, '[REDACTED PRIVATE KEY]', $content) ?? $content;
        $content = preg_replace(self::RECOGNIZABLE_TOKEN_PATTERN, '[REDACTED TOKEN]', $content) ?? $content;
        $content = preg_replace(
            '/(?im)(?<prefix>\bAuthorization\s*:\s*Bearer\s+)(?!\[REDACTED\b)(?<token>[^\s,;]+)/',
            '$1[REDACTED TOKEN]',
            $content,
        ) ?? $content;
        $content = preg_replace_callback(
            '/\b(?:mysql|mysqldump|mariadb)\b(?:(?![;\r\n]).*?)(?<option>(?<!\S)-p)(?<secret>[^\s]+)/i',
            static function (array $match): string {
                if (str_starts_with($match['secret'], '[REDACTED')) return $match[0];
                return substr($match[0], 0, strlen($match[0]) - strlen($match['secret'])).'[REDACTED]';
            },
            $content,
        ) ?? $content;
        $content = preg_replace(
            '/\b([A-Za-z][A-Za-z0-9+.-]*:\/\/[^\s\/:@]+:)([^\s\/@]+)(@)/',
            '$1[REDACTED]$3',
            $content,
        ) ?? $content;

        return $content;
    }

    private function shouldRedactKeyValue(string $key, string $value): bool
    {
        return $this->isSecretKey($key) && ! $this->shouldPreserveValue($value);
    }

    private function isSecretKey(string $key): bool
    {
        $segments = $this->secretKeySegments($key);
        if ($segments === []) return false;
        if (in_array($segments[array_key_last($segments)], self::NON_SECRET_METADATA_SEGMENTS, true)) return false;

        foreach ($segments as $segment) {
            if (in_array($segment, self::SECRET_SEGMENTS, true) || in_array($segment, self::SECRET_COMPOUND_ALIASES, true)) {
                return true;
            }
        }
        foreach (self::SECRET_COMPOUND_SEGMENTS as $compound) {
            for ($index = 0; $index < count($segments) - 1; $index++) {
                if ($segments[$index] === $compound[0] && $segments[$index + 1] === $compound[1]) return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function secretKeySegments(string $key): array
    {
        $key = ltrim($key, '$');
        $segments = preg_split(
            '/[_\-.]+|(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/',
            $key,
            -1,
            PREG_SPLIT_NO_EMPTY,
        ) ?: [];

        return array_map(static fn (string $segment): string => strtolower($segment), $segments);
    }

    private function shouldPreserveValue(string $value): bool
    {
        $trimmed = trim($value);
        if ($trimmed === '' || str_starts_with($trimmed, '$') || str_starts_with($trimmed, '[REDACTED')) return true;
        if (preg_match('/^(?:[-+]?(?:(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][-+]?\d+)?|0[xX][0-9A-Fa-f]+)|true|false|null)$/i', $trimmed) === 1) return true;

        // Laravel-style validation expressions use a fixed allowlist of rule
        // names, optionally followed by colon arguments and joined with "|".
        if ($this->isValidationRuleExpression($trimmed)) return true;

        // Preserve translation-like sentences only when they have at least five
        // words, begin with an uppercase letter, and end in sentence punctuation.
        if (preg_match('/^\p{Lu}[\p{L}\p{M}\p{N}\'’\-]*(?:\s+[\p{L}\p{M}\p{N}\'’\-]+){4,}[.!?]$/u', $trimmed) === 1) return true;

        return preg_match('/^(?:process\.env\b|\$_(?:ENV|SERVER|GET|POST)\b|[A-Za-z_][A-Za-z0-9_]*(?:(?:::|\.)[A-Za-z_][A-Za-z0-9_]*)*\s*\()/i', $trimmed) === 1;
    }

    private function isValidationRuleExpression(string $value): bool
    {
        foreach (explode('|', $value) as $rule) {
            if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)(?::[A-Za-z0-9_.*,+\/-]+)?$/', $rule, $matches) !== 1
                || ! in_array(strtolower($matches[1]), self::VALIDATION_RULE_NAMES, true)) {
                return false;
            }
        }

        return $value !== '';
    }
}
