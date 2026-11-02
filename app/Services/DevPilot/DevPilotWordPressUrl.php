<?php

namespace App\Services\DevPilot;

use RuntimeException;

/** Canonical WordPress URL; HTTP is permitted only for an unauthenticated upgrade probe. */
final class DevPilotWordPressUrl
{
    /** @return array{site_url:string,scheme:string,host:string,port:int} */
    public static function normalize(string $value, bool $allowHttpProbe = false): array
    {
        $value = trim($value);
        if ($value === '' || preg_match('/[\x00-\x20\x7f]/', $value) === 1) {
            throw new RuntimeException('Une URL HTTPS WordPress valide est obligatoire.');
        }

        $parts = parse_url($value);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (! is_array($parts)
            || ($scheme !== 'https' && ! ($allowHttpProbe && $scheme === 'http'))
            || ! is_string($parts['host'] ?? null)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            throw new RuntimeException('WordPress exige une URL HTTPS sans identifiants, paramètres ni fragment.');
        }

        $host = trim((string) $parts['host'], '[]');
        $defaultPort = $scheme === 'https' ? 443 : 80;
        $port = isset($parts['port']) ? (int) $parts['port'] : $defaultPort;
        if ($port < 1 || $port > 65535) {
            throw new RuntimeException('Le port de l’URL WordPress est invalide.');
        }

        $packedAddress = @inet_pton($host);
        if (is_string($packedAddress)) {
            $normalizedAddress = @inet_ntop($packedAddress);
            if (! is_string($normalizedAddress)) {
                throw new RuntimeException('L’hôte de l’URL WordPress est invalide.');
            }
            $host = strtolower($normalizedAddress);
        } else {
            $host = self::asciiDomain($host);
            if (strlen($host) > 253 || self::validDomain($host) === false) {
                throw new RuntimeException('L’hôte de l’URL WordPress est invalide.');
            }
        }

        $path = (string) ($parts['path'] ?? '/');
        if ($path === '') $path = '/';
        if (preg_match('/[\x00-\x20\x7f\\\\]/', $path) === 1
            || preg_match('/%(?![A-Fa-f0-9]{2})/', $path) === 1) {
            throw new RuntimeException('Le chemin de l’URL WordPress contient une séquence invalide.');
        }
        $decodedPath = rawurldecode($path);
        foreach (explode('/', $decodedPath) as $segment) {
            if (in_array($segment, ['.', '..'], true)) {
                throw new RuntimeException('Le chemin de l’URL WordPress ne peut pas traverser un répertoire.');
            }
        }

        $path = preg_replace('#/+#', '/', $path) ?? $path;
        $trimmedPath = trim($path, '/');
        $path = $trimmedPath === '' ? '/' : '/'.$trimmedPath.'/';
        $hostInUrl = str_contains($host, ':') ? '['.$host.']' : $host;
        $portInUrl = $port === $defaultPort ? '' : ':'.$port;

        return [
            'site_url' => $scheme.'://'.$hostInUrl.$portInUrl.$path,
            'scheme' => $scheme,
            'host' => $host,
            'port' => $port,
        ];
    }

    public static function sameOrigin(string $left, string $right): bool
    {
        try {
            $leftUrl = self::origin($left);
            $rightUrl = self::origin($right);
        } catch (RuntimeException) {
            return false;
        }

        return $leftUrl['host'] === $rightUrl['host']
            && $leftUrl['port'] === $rightUrl['port'];
    }

    /** @return array{host:string,port:int} */
    private static function origin(string $url): array
    {
        $parts = parse_url($url);
        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ! is_string($parts['host'] ?? null)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])) {
            throw new RuntimeException('URL origin is invalid.');
        }

        $host = trim((string) $parts['host'], '[]');
        $hostInUrl = str_contains($host, ':') ? '['.$host.']' : $host;
        $port = isset($parts['port']) && (int) $parts['port'] !== 443 ? ':'.(int) $parts['port'] : '';
        $normalized = self::normalize('https://'.$hostInUrl.$port.'/');

        return ['host' => $normalized['host'], 'port' => $normalized['port']];
    }

    private static function asciiDomain(string $host): string
    {
        $host = rtrim($host, '.');
        if (! function_exists('idn_to_ascii')) {
            if (preg_match('/[^\x20-\x7e]/', $host) === 1) {
                throw new RuntimeException('La prise en charge IDN du serveur est indisponible.');
            }
            return strtolower($host);
        }

        $flags = defined('IDNA_NONTRANSITIONAL_TO_ASCII') ? IDNA_NONTRANSITIONAL_TO_ASCII : IDNA_DEFAULT;
        if (defined('IDNA_USE_STD3_RULES')) $flags |= IDNA_USE_STD3_RULES;
        $info = [];
        $ascii = idn_to_ascii($host, $flags, INTL_IDNA_VARIANT_UTS46, $info);
        if (! is_string($ascii) || $ascii === '' || (($info['errors'] ?? 0) !== 0)) {
            throw new RuntimeException('L’hôte IDN de l’URL WordPress est invalide.');
        }

        return strtolower(rtrim($ascii, '.'));
    }

    private static function validDomain(string $host): bool
    {
        if ($host === '' || strlen($host) > 253) return false;
        foreach (explode('.', $host) as $label) {
            if ($label === '' || strlen($label) > 63
                || preg_match('/^(?!-)[a-z0-9-]+(?<!-)$/D', $label) !== 1) {
                return false;
            }
        }

        return true;
    }
}
