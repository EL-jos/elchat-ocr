<?php

namespace App\Services\WebsiteSecurityAuditor;

use App\Models\Mcp\McpAgent;
use App\Models\Site;
use App\Models\WebsiteSecurityConfiguration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class WebsiteSecurityConfigurationService
{
    /** @return array<string, mixed> */
    public function defaults(): array
    {
        return [
            'audit_mode' => 'audit',
            'analysis_depth' => 'standard',
            'ai_interpretation' => true,
            'checks' => [
                'security_headers' => true,
                'tls_transport' => true,
                'cookie_flags' => true,
                'discovered_surface' => true,
                'vulnerability_intelligence' => false,
                'network_surface_scan' => false,
                'dns_discovery' => false,
                'repository_analysis' => false,
                'server_configuration' => false,
                'database_posture' => false,
            ],
            'authorized_scope' => ['hosts' => [], 'ports' => [], 'authorization_confirmed' => false],
            'execution_mode' => 'manual',
            'schedule_frequency' => null,
            'schedule_time' => '09:00',
            'next_run_at' => null,
            'is_active' => true,
            'version' => 1,
        ];
    }

    public function forAgent(Site $site, McpAgent $agent): WebsiteSecurityConfiguration
    {
        $configuration = WebsiteSecurityConfiguration::query()->firstOrCreate(
            ['site_id' => $site->id, 'agent_id' => $agent->id],
            array_merge($this->defaults(), [
                'id' => (string) Str::uuid(),
                'account_id' => $site->account_id,
            ]),
        );
        $configuration->setAttribute('checks', array_replace(
            $this->defaults()['checks'],
            is_array($configuration->checks) ? $configuration->checks : [],
        ));
        return $configuration;
    }

    /** @return array<string, mixed> */
    public function effective(?WebsiteSecurityConfiguration $configuration): array
    {
        $defaults = $this->defaults();
        if (! $configuration) return $defaults;

        $values = $configuration->toArray();
        $values['checks'] = is_array($values['checks'] ?? null)
            ? array_replace($defaults['checks'], $values['checks'])
            : $defaults['checks'];
        $values['authorized_scope'] = is_array($values['authorized_scope'] ?? null)
            ? array_replace($defaults['authorized_scope'], $values['authorized_scope'])
            : $defaults['authorized_scope'];

        return array_replace($defaults, array_intersect_key($values, $defaults));
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function normalize(array $input, ?WebsiteSecurityConfiguration $current = null): array
    {
        $values = array_replace($this->effective($current), $input);
        if (! in_array($values['audit_mode'] ?? null, ['audit', 'investigation'], true)) {
            throw ValidationException::withMessages(['audit_mode' => 'Le mode doit être audit ou investigation.']);
        }
        if (! in_array($values['analysis_depth'] ?? null, ['quick', 'standard', 'deep'], true)) {
            throw ValidationException::withMessages(['analysis_depth' => 'Profondeur d’analyse invalide.']);
        }

        $checks = is_array($values['checks'] ?? null) ? $values['checks'] : [];
        $values['checks'] = [];
        foreach (array_keys($this->defaults()['checks']) as $key) {
            $values['checks'][$key] = (bool) ($checks[$key] ?? false);
        }
        $scope = is_array($values['authorized_scope'] ?? null) ? $values['authorized_scope'] : [];
        $hosts = is_array($scope['hosts'] ?? null) ? $scope['hosts'] : [];
        $ports = is_array($scope['ports'] ?? null) ? $scope['ports'] : [];
        $values['authorized_scope'] = [
            'hosts' => array_values(array_unique(array_filter(array_map(fn ($host): string => strtolower(trim((string) $host)), $hosts), fn (string $host): bool => $host !== ''))),
            'ports' => array_values(array_unique(array_filter(array_map('intval', $ports), fn (int $port): bool => $port >= 1 && $port <= 65535))),
            'authorization_confirmed' => (bool) ($scope['authorization_confirmed'] ?? false),
        ];
        $values['ai_interpretation'] = (bool) ($values['ai_interpretation'] ?? false);

        $values['execution_mode'] = in_array($values['execution_mode'] ?? null, ['manual', 'scheduled'], true)
            ? $values['execution_mode'] : 'manual';
        $values['schedule_frequency'] = in_array($values['schedule_frequency'] ?? null, ['daily', 'weekly', 'monthly'], true)
            ? $values['schedule_frequency'] : null;
        $values['schedule_time'] = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) ($values['schedule_time'] ?? ''))
            ? $values['schedule_time'] : '09:00';
        $values['is_active'] = (bool) ($values['is_active'] ?? true);

        if ($values['execution_mode'] === 'scheduled' && ! $values['schedule_frequency']) {
            throw ValidationException::withMessages(['schedule_frequency' => 'Une fréquence est requise pour une exécution planifiée.']);
        }

        $values['next_run_at'] = $this->scheduleNextRun($values);
        unset($values['id'], $values['account_id'], $values['site_id'], $values['agent_id'], $values['created_at'], $values['updated_at']);
        return $values;
    }

    /** @param array<string, mixed> $settings */
    public function scheduleNextRun(array $settings, ?Carbon $from = null): ?Carbon
    {
        if (($settings['is_active'] ?? true) !== true || ($settings['execution_mode'] ?? 'manual') !== 'scheduled') return null;
        $now = ($from ?? now())->copy();
        $time = (string) ($settings['schedule_time'] ?? '09:00');
        if (($settings['schedule_frequency'] ?? null) === 'weekly') {
            return $now->next(Carbon::MONDAY)->setTimeFromTimeString($time);
        }
        if (($settings['schedule_frequency'] ?? null) === 'monthly') {
            $at = $now->copy()->startOfMonth()->setTimeFromTimeString($time);
            return $at->lessThanOrEqualTo($now) ? $at->addMonthNoOverflow()->startOfMonth()->setTimeFromTimeString($time) : $at;
        }

        $at = $now->copy()->setTimeFromTimeString($time);
        return $at->lessThanOrEqualTo($now) ? $at->addDay() : $at;
    }
}
