<?php

namespace App\Services\WebsiteSecurityAuditor;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class WebsiteSecurityManifestImportService
{
    /** @return array<string, mixed> */
    public function import(string $lockfileName, string $lockfileJson, ?string $manifestName = null, ?string $manifestJson = null): array
    {
        $lock = $this->decode($lockfileJson, 'lockfile');
        $manifest = $manifestJson !== null ? $this->decode($manifestJson, 'manifest') : [];
        $lockName = strtolower(basename($lockfileName));
        $manifestName = $manifestName !== null ? strtolower(basename($manifestName)) : null;

        if ($lockName === 'composer.lock' && ($manifestName === null || $manifestName === 'composer.json')) {
            $components = $this->composerComponents($lock, $manifest);
            $ecosystem = 'Packagist';
            $manager = 'Composer';
        } elseif ($lockName === 'package-lock.json' && ($manifestName === null || $manifestName === 'package.json')) {
            $components = $this->npmComponents($lock, $manifest);
            $ecosystem = 'npm';
            $manager = 'npm';
        } else {
            throw ValidationException::withMessages(['lockfile' => 'Fournissez composer.lock ou package-lock.json, avec le manifeste correspondant si disponible.']);
        }

        if ($components === []) throw ValidationException::withMessages(['lockfile' => 'Aucun composant versionné reconnu dans ce lockfile.']);
        $components = array_slice($components, 0, 5000);
        usort($components, fn (array $a, array $b): int => strcmp($a['purl'], $b['purl']));

        $bom = [
            'bomFormat' => 'CycloneDX',
            'specVersion' => '1.5',
            'serialNumber' => 'urn:uuid:'.Str::uuid(),
            'version' => 1,
            'metadata' => ['timestamp' => now()->toISOString(), 'tools' => [['vendor' => 'ELChat', 'name' => 'Website Security Auditor']]],
            'components' => array_map(fn (array $component): array => $this->cycloneComponent($component), $components),
        ];

        return [
            'ecosystem' => $ecosystem,
            'package_manager' => $manager,
            'lockfile_name' => basename($lockfileName),
            'manifest_name' => $manifestName,
            'components' => $components,
            'component_count' => count($components),
            'cyclonedx' => $bom,
            'fingerprint' => hash('sha256', json_encode($components, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}'),
        ];
    }

    /** @return array<string, mixed> */
    private function decode(string $json, string $field): array
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw ValidationException::withMessages([$field => 'Le fichier doit contenir un document JSON valide.']);
        }
        if (! is_array($data)) throw ValidationException::withMessages([$field => 'La racine JSON doit être un objet.']);
        return $data;
    }

    /** @param array<string, mixed> $lock @param array<string, mixed> $manifest @return array<int, array<string, mixed>> */
    private function composerComponents(array $lock, array $manifest): array
    {
        $require = is_array($manifest['require'] ?? null) ? $manifest['require'] : [];
        $requireDev = is_array($manifest['require-dev'] ?? null) ? $manifest['require-dev'] : [];
        $direct = array_fill_keys(array_map('strtolower', array_keys(array_merge($require, $requireDev))), true);
        $development = array_fill_keys(array_map('strtolower', array_keys($requireDev)), true);
        $devPackages = [];
        $rows = [...(is_array($lock['packages'] ?? null) ? $lock['packages'] : []), ...(is_array($lock['packages-dev'] ?? null) ? $lock['packages-dev'] : [])];
        foreach (is_array($lock['packages-dev'] ?? null) ? $lock['packages-dev'] : [] as $package) {
            if (is_array($package) && is_string($package['name'] ?? null)) $devPackages[strtolower($package['name'])] = true;
        }
        $components = [];
        foreach ($rows as $package) {
            if (! is_array($package) || ! is_string($package['name'] ?? null) || ! is_string($package['version'] ?? null)) continue;
            $name = strtolower($package['name']);
            if (! preg_match('#^[a-z0-9_.-]+/[a-z0-9_.-]+$#', $name)) continue;
            $version = ltrim($package['version'], 'vV');
            if (! $this->safeVersion($version)) continue;
            $segments = explode('/', $name);
            $distSha1 = data_get($package, 'dist.shasum');
            $components[] = [
                'name' => $name, 'version' => $version, 'ecosystem' => 'Packagist', 'package_manager' => 'Composer',
                'directness' => $manifest === [] ? 'unknown' : (isset($direct[$name]) ? 'direct' : 'transitive'),
                'environment' => isset($development[$name]) || isset($devPackages[$name]) ? 'development' : 'production',
                'license' => $this->licenses($package['license'] ?? []),
                'hashes' => is_string($distSha1) && preg_match('/^[a-f0-9]{40}$/i', $distSha1)
                    ? [['alg' => 'SHA-1', 'content' => strtolower($distSha1)]] : [],
                'source_reference' => $this->safeReference(data_get($package, 'dist.reference') ?: data_get($package, 'source.reference')),
                'provenance' => $this->safeOrigin(data_get($package, 'dist.url') ?: data_get($package, 'source.url')),
                'purl' => 'pkg:composer/'.implode('/', array_map('rawurlencode', $segments)).'@'.rawurlencode($version),
            ];
        }
        return $this->deduplicate($components);
    }

    /** @param array<string, mixed> $lock @param array<string, mixed> $manifest @return array<int, array<string, mixed>> */
    private function npmComponents(array $lock, array $manifest): array
    {
        $dependencyGroups = [];
        foreach (['dependencies', 'devDependencies', 'optionalDependencies', 'peerDependencies'] as $group) {
            $dependencyGroups[$group] = is_array($manifest[$group] ?? null) ? $manifest[$group] : [];
        }
        $directNames = array_fill_keys(array_map('strtolower', array_keys(array_merge(...array_values($dependencyGroups)))), true);
        $developmentNames = array_fill_keys(array_map('strtolower', array_keys($dependencyGroups['devDependencies'])), true);
        $components = [];
        if (is_array($lock['packages'] ?? null)) {
            foreach ($lock['packages'] as $path => $package) {
                if ($path === '' || ! is_array($package)) continue;
                $name = is_string($package['name'] ?? null) ? $package['name'] : $this->npmNameFromPath((string) $path);
                $this->addNpmComponent($components, $name, $package, $directNames, $developmentNames, $manifest !== []);
            }
        } else {
            $this->walkNpmDependencies($lock['dependencies'] ?? [], $components, $directNames, $developmentNames, $manifest !== []);
        }
        return $this->deduplicate($components);
    }

    /** @param array<int, array<string, mixed>> $components @param array<string, mixed> $package @param array<string, bool> $directNames @param array<string, bool> $developmentNames */
    private function addNpmComponent(array &$components, string $name, array $package, array $directNames, array $developmentNames, bool $hasManifest): void
    {
        if (! preg_match('#^(?:@[a-z0-9._~-]+/)?[a-z0-9._~-]+$#', $name) || ! is_string($package['version'] ?? null)) return;
        $version = ltrim($package['version'], 'vV');
        if (! $this->safeVersion($version)) return;
        $name = strtolower($name);
        $encodedName = implode('/', array_map('rawurlencode', explode('/', $name)));
        $components[] = [
            'name' => strtolower($name), 'version' => $version, 'ecosystem' => 'npm', 'package_manager' => 'npm',
            'directness' => ! $hasManifest ? 'unknown' : (isset($directNames[$name]) ? 'direct' : 'transitive'),
            'environment' => isset($developmentNames[$name]) ? 'development' : (($package['dev'] ?? false) ? 'development' : 'production'),
            'license' => $this->licenses($package['license'] ?? []),
            'hashes' => $this->npmIntegrity($package['integrity'] ?? null),
            'source_reference' => null,
            'provenance' => $this->safeOrigin($package['resolved'] ?? null),
            'purl' => 'pkg:npm/'.$encodedName.'@'.rawurlencode($version),
        ];
    }

    /** @param mixed $dependencies @param array<int, array<string, mixed>> $components @param array<string, bool> $directNames @param array<string, bool> $developmentNames */
    private function walkNpmDependencies(mixed $dependencies, array &$components, array $directNames, array $developmentNames, bool $hasManifest): void
    {
        if (! is_array($dependencies)) return;
        foreach ($dependencies as $name => $package) {
            if (! is_array($package)) continue;
            $this->addNpmComponent($components, (string) $name, $package, $directNames, $developmentNames, $hasManifest);
            $this->walkNpmDependencies($package['dependencies'] ?? [], $components, $directNames, $developmentNames, $hasManifest);
        }
    }

    private function npmNameFromPath(string $path): string
    {
        $name = preg_replace('#^(?:.*/)?node_modules/#', '', $path) ?? '';
        return $name;
    }

    /** @param mixed $licenses @return array<int, string> */
    private function licenses(mixed $licenses): array
    {
        if (is_string($licenses)) return strlen($licenses) <= 128 ? [$licenses] : [];
        if (! is_array($licenses)) return [];
        $values = array_map(fn ($license) => is_string($license) ? $license : (is_array($license) ? ($license['type'] ?? null) : null), $licenses);
        return array_values(array_unique(array_filter($values, fn ($license): bool => is_string($license) && strlen($license) <= 128)));
    }

    private function safeOrigin(mixed $url): ?string
    {
        if (! is_string($url)) return null;
        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['host']) || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)) return null;
        $origin = strtolower((string) $parts['scheme']).'://'.strtolower((string) $parts['host']);
        if (isset($parts['port']) && is_numeric($parts['port'])) $origin .= ':'.(int) $parts['port'];
        return $origin;
    }

    private function safeVersion(string $version): bool
    {
        return $version !== '' && strlen($version) <= 256 && preg_match('/^[A-Za-z0-9.+_-]+$/', $version) === 1;
    }

    private function safeReference(mixed $reference): ?string
    {
        return is_string($reference) && preg_match('/^[a-f0-9]{7,64}$/i', $reference) === 1 ? strtolower($reference) : null;
    }

    /** @return array<int, array{alg: string, content: string}> */
    private function npmIntegrity(mixed $integrity): array
    {
        if (! is_string($integrity)) return [];
        foreach (preg_split('/\s+/', trim($integrity)) ?: [] as $entry) {
            if (! preg_match('/^(sha1|sha256|sha384|sha512)-([A-Za-z0-9+\/=]+)(?:\?.*)?$/', $entry, $matches)) continue;
            $bytes = base64_decode($matches[2], true);
            if ($bytes === false) continue;
            $expected = ['sha1' => 20, 'sha256' => 32, 'sha384' => 48, 'sha512' => 64][$matches[1]];
            if (strlen($bytes) !== $expected) continue;
            $algorithm = ['sha1' => 'SHA-1', 'sha256' => 'SHA-256', 'sha384' => 'SHA-384', 'sha512' => 'SHA-512'][$matches[1]];
            return [['alg' => $algorithm, 'content' => bin2hex($bytes)]];
        }
        return [];
    }

    /** @param array<int, array<string, mixed>> $components @return array<int, array<string, mixed>> */
    private function deduplicate(array $components): array
    {
        $unique = [];
        foreach ($components as $component) {
            $key = $component['purl'];
            if (! isset($unique[$key])) {
                $unique[$key] = $component;
                continue;
            }
            $existing = $unique[$key];
            if ($existing['directness'] !== 'direct' && $component['directness'] === 'direct') $existing['directness'] = 'direct';
            if ($existing['environment'] !== $component['environment']) $existing['environment'] = 'production_and_development';
            $existing['license'] = array_values(array_unique([...$existing['license'], ...$component['license']]));
            if ($existing['hashes'] === [] && $component['hashes'] !== []) $existing['hashes'] = $component['hashes'];
            $existing['provenance'] ??= $component['provenance'];
            $existing['source_reference'] ??= $component['source_reference'];
            $unique[$key] = $existing;
        }
        return array_values($unique);
    }

    /** @param array<string, mixed> $component @return array<string, mixed> */
    private function cycloneComponent(array $component): array
    {
        return [
            'type' => 'library', 'bom-ref' => $component['purl'], 'name' => $component['name'],
            'version' => $component['version'], 'purl' => $component['purl'],
            ...($component['directness'] === 'unknown' ? [] : ['scope' => 'required']),
            'licenses' => array_map(fn (string $license): array => ['license' => ['name' => $license]], $component['license']),
            'hashes' => $component['hashes'],
            'properties' => [
                ['name' => 'elchat:directness', 'value' => $component['directness']],
                ['name' => 'elchat:environment', 'value' => $component['environment']],
                ['name' => 'elchat:provenance', 'value' => $component['provenance'] ?? 'not_available'],
                ['name' => 'elchat:source_reference', 'value' => $component['source_reference'] ?? 'not_available'],
            ],
        ];
    }
}
