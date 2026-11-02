<?php

namespace App\Services\DevPilot;

use RuntimeException;

/** Resolves every DNS answer and permits private targets only by explicit server-side CIDR. */
final class DevPilotHostResolver
{
    private const ALWAYS_RESTRICTED_CIDRS = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16',
        '172.16.0.0/12', '192.168.0.0/16', '224.0.0.0/4',
        '::/128', '::1/128', 'fc00::/7', 'fe80::/10', 'ff00::/8',
        '169.254.169.254/32', '169.254.170.2/32', '100.100.100.200/32', 'fd00:ec2::254/128',
    ];

    public static function resolve(string $host, bool $privateNetworkAuthorized, ?array $authorizedCidrs = null): string
    {
        $host = trim($host, " \t\n\r\0\x0B[]");
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $addresses = [$host];
        } else {
            $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
            $addresses = array_values(array_filter(array_map(
                static fn ($record) => $record['ip'] ?? $record['ipv6'] ?? null,
                $records,
            ), static fn ($address) => is_string($address) && $address !== ''));
        }

        if ($addresses === []) throw new RuntimeException('Remote host did not resolve to an IP address.');

        $cidrs = $authorizedCidrs ?? config('devpilot.private_network_allowed_cidrs', []);
        $cidrs = is_array($cidrs) ? $cidrs : [];
        $normalizedAddresses = [];
        foreach (array_unique($addresses) as $address) {
            $normalized = self::normalizeAddress((string) $address);
            if ($normalized === null) throw new RuntimeException('Remote host resolved to an invalid IP address.');

            $restricted = self::matchesAnyCidr($normalized, self::ALWAYS_RESTRICTED_CIDRS)
                || filter_var($normalized, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
            $explicitlyAllowed = $privateNetworkAuthorized && self::matchesAnyCidr($normalized, $cidrs);
            if ($restricted && ! $explicitlyAllowed) {
                throw new RuntimeException('Remote host resolves to a reserved or unapproved private address.');
            }
            $normalizedAddresses[] = $normalized;
        }

        return $normalizedAddresses[0];
    }

    private static function normalizeAddress(string $address): ?string
    {
        $packed = @inet_pton($address);
        if (! is_string($packed)) return null;
        if (strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat("\0", 10)."\xff\xff") {
            $packed = substr($packed, 12);
        }
        $normalized = @inet_ntop($packed);
        return is_string($normalized) ? strtolower($normalized) : null;
    }

    private static function matchesAnyCidr(string $address, array $cidrs): bool
    {
        $addressBytes = @inet_pton($address);
        if (! is_string($addressBytes)) return false;

        foreach ($cidrs as $cidr) {
            if (! is_string($cidr) || trim($cidr) === '') continue;
            $parts = explode('/', trim($cidr), 2);
            $networkBytes = @inet_pton($parts[0]);
            if (! is_string($networkBytes) || strlen($networkBytes) !== strlen($addressBytes)) continue;
            $maxBits = strlen($addressBytes) * 8;
            $prefix = isset($parts[1]) && ctype_digit($parts[1]) ? (int) $parts[1] : $maxBits;
            if ($prefix < 0 || $prefix > $maxBits) continue;

            $wholeBytes = intdiv($prefix, 8);
            $remainingBits = $prefix % 8;
            if ($wholeBytes > 0 && substr($addressBytes, 0, $wholeBytes) !== substr($networkBytes, 0, $wholeBytes)) continue;
            if ($remainingBits > 0) {
                $mask = (0xff << (8 - $remainingBits)) & 0xff;
                if ((ord($addressBytes[$wholeBytes]) & $mask) !== (ord($networkBytes[$wholeBytes]) & $mask)) continue;
            }
            return true;
        }

        return false;
    }
}
