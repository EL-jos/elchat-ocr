<?php

namespace App\Services\WebsiteSecurityAuditor;

final class WebsiteSecurityFindingCoverageService
{
    /** @param array<string, mixed> $finding @param array<int, array<string, mixed>> $inventory @param array<string, bool> $checks */
    public function reassessed(array $finding, array $inventory, array $checks): bool
    {
        $type = (string) ($finding['type'] ?? '');
        if ($type === 'dependency_advisory_match') {
            if (empty($checks['vulnerability_intelligence'])) return false;
            foreach ($inventory as $item) {
                if (! is_array($item) || ($item['type'] ?? null) !== 'package' || empty($item['vulnerability_checked'])) continue;
                if (strtolower((string) ($item['ecosystem'] ?? '')) !== strtolower((string) ($finding['ecosystem'] ?? $this->ecosystemFromPurl((string) ($finding['purl'] ?? ''))))) continue;
                if (strtolower((string) ($item['name'] ?? '')) !== strtolower((string) ($finding['component'] ?? ''))) continue;
                if (strtolower((string) ($item['version'] ?? '')) !== strtolower((string) ($finding['component_version'] ?? ''))) continue;
                return true;
            }
            return false;
        }

        $requiredCheck = match (true) {
            $type === 'insecure_transport' => 'tls_transport',
            $type === 'missing_frame_ancestors', str_starts_with($type, 'missing_header_') => 'security_headers',
            str_starts_with($type, 'cookie_missing_') => 'cookie_flags',
            default => null,
        };
        if ($requiredCheck === null || empty($checks[$requiredCheck])) return false;
        $asset = is_string($finding['asset'] ?? null) ? $finding['asset'] : null;
        if ($asset === null) return false;
        $identity = $this->endpointIdentity($asset);
        if ($identity === null) return false;

        foreach ($inventory as $item) {
            if (is_array($item) && ($item['type'] ?? null) === 'web_endpoint'
                && $this->endpointIdentity((string) ($item['url'] ?? '')) === $identity) return true;
        }
        return false;
    }

    private function endpointIdentity(string $url): ?string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['host'])) return null;
        return strtolower((string) $parts['host']).':'.(isset($parts['port']) ? (int) $parts['port'] : 0).(string) ($parts['path'] ?? '/');
    }

    private function ecosystemFromPurl(string $purl): string
    {
        if (! str_starts_with($purl, 'pkg:')) return '';
        return (string) strstr(substr($purl, 4), '/', true);
    }
}
