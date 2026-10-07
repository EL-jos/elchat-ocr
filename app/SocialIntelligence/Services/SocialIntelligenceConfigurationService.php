<?php

namespace App\SocialIntelligence\Services;

use App\Models\Site;
use App\Models\Social\SocialIntelligenceSetting;
use Illuminate\Support\Carbon;

class SocialIntelligenceConfigurationService
{
    public function defaults(Site $site): array
    {
        return [
            'sources' => $site->socialAccounts()->whereIn('provider', config('social-intelligence.supported_providers'))->where('is_active', true)->pluck('provider')->unique()->values()->all(),
            'period' => '30d', 'custom_from' => null, 'custom_to' => null, 'comparison' => 'previous_period',
            'domains' => ['content', 'engagement', 'audience', 'conversations', 'trends', 'opportunities', 'recommendations'],
            'depth' => 'standard', 'frequency' => 'manual', 'is_active' => true,
        ];
    }

    public function settingsFor(Site $site): SocialIntelligenceSetting
    {
        return SocialIntelligenceSetting::firstOrCreate(['site_id' => $site->id], ['account_id' => $site->account_id, ...$this->defaults($site)]);
    }

    public function normalize(Site $site, array $input): array
    {
        $defaults = $this->defaults($site);
        $data = array_replace($defaults, array_filter($input, static fn ($value) => $value !== null));
        $data['sources'] = collect((array) $data['sources'])->filter(fn ($provider) => in_array($provider, config('social-intelligence.supported_providers'), true))->unique()->values()->all();
        $data['domains'] = collect((array) $data['domains'])->filter(fn ($domain) => in_array($domain, $defaults['domains'], true))->unique()->values()->all();
        $data['period'] = in_array($data['period'], ['7d', '30d', '90d', 'custom'], true) ? $data['period'] : '30d';
        $data['comparison'] = in_array($data['comparison'], ['previous_period', 'previous_year', 'none'], true) ? $data['comparison'] : 'previous_period';
        $data['depth'] = in_array($data['depth'], ['quick', 'standard', 'deep'], true) ? $data['depth'] : 'standard';
        $data['frequency'] = in_array($data['frequency'], ['manual', 'daily', 'weekly', 'monthly'], true) ? $data['frequency'] : 'manual';
        if ($data['period'] === 'custom' && (!$data['custom_from'] || !$data['custom_to'])) throw new \InvalidArgumentException('custom_from et custom_to sont requis pour une période personnalisée.');
        return $data;
    }

    /** @return array{from:Carbon,to:Carbon,comparison_from:?Carbon,comparison_to:?Carbon} */
    public function period(array $configuration, ?Carbon $now = null): array
    {
        $now ??= now();
        $to = $now->copy()->endOfDay();
        $from = match ($configuration['period'] ?? '30d') {
            '7d' => $now->copy()->subDays(6)->startOfDay(),
            '90d' => $now->copy()->subDays(89)->startOfDay(),
            'custom' => Carbon::parse($configuration['custom_from'])->startOfDay(),
            default => $now->copy()->subDays(29)->startOfDay(),
        };
        if (($configuration['period'] ?? null) === 'custom') $to = Carbon::parse($configuration['custom_to'])->endOfDay();
        $comparisonFrom = $comparisonTo = null;
        if (($configuration['comparison'] ?? 'previous_period') !== 'none') {
            $days = $from->diffInDays($to) + 1;
            if (($configuration['comparison'] ?? null) === 'previous_year') {
                $comparisonFrom = $from->copy()->subYear(); $comparisonTo = $to->copy()->subYear();
            } else {
                $comparisonTo = $from->copy()->subDay()->endOfDay(); $comparisonFrom = $comparisonTo->copy()->subDays($days - 1)->startOfDay();
            }
        }
        return compact('from', 'to', 'comparisonFrom', 'comparisonTo');
    }

    public function nextRunAt(array $configuration, Carbon $from): ?Carbon
    {
        return match ($configuration['frequency'] ?? 'manual') {
            'daily' => $from->copy()->addDay(), 'weekly' => $from->copy()->addWeek(), 'monthly' => $from->copy()->addMonth(), default => null,
        };
    }
}
