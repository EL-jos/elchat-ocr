<?php

namespace App\Services\WebsiteSecurityAuditor;

final class WebsiteSecuritySbomComparisonService
{
    /** @param array<int, array<string, mixed>> $current @param array<int, array<string, mixed>> $previous @return array<string, mixed> */
    public function compare(array $current, array $previous): array
    {
        $currentMap = $this->map($current);
        $previousMap = $this->map($previous);
        if ($currentMap === [] || $previousMap === []) {
            return ['available' => false, 'reason' => 'empty_or_unrecognized_sbom', 'new' => [], 'removed' => [], 'version_changed' => []];
        }

        $common = array_intersect_key($currentMap, $previousMap);
        $versionChanged = [];
        foreach ($common as $key => $component) {
            if (($component['version'] ?? null) !== ($previousMap[$key]['version'] ?? null)) {
                $versionChanged[] = [
                    'component' => $component['name'] ?? $key,
                    'ecosystem' => $component['ecosystem'] ?? null,
                    'from' => $previousMap[$key]['version'] ?? null,
                    'to' => $component['version'] ?? null,
                ];
            }
        }
        $project = static fn (array $component): array => [
            'name' => $component['name'] ?? null,
            'version' => $component['version'] ?? null,
            'ecosystem' => $component['ecosystem'] ?? null,
            'purl' => $component['purl'] ?? null,
        ];

        return [
            'available' => true,
            'new' => array_map($project, array_values(array_diff_key($currentMap, $previousMap))),
            'removed' => array_map($project, array_values(array_diff_key($previousMap, $currentMap))),
            'version_changed' => $versionChanged,
        ];
    }

    /** @param array<int, array<string, mixed>> $components @return array<string, array<string, mixed>> */
    private function map(array $components): array
    {
        $map = [];
        foreach ($components as $component) {
            if (! is_array($component)) continue;
            $name = strtolower((string) ($component['name'] ?? ''));
            $ecosystem = strtolower((string) ($component['ecosystem'] ?? ''));
            $manager = strtolower((string) ($component['package_manager'] ?? ''));
            if ($name === '' || $ecosystem === '') continue;
            $map[$ecosystem.'|'.$manager.'|'.$name] = $component;
        }
        return $map;
    }
}
