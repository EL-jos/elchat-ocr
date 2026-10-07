<?php

namespace App\SocialIntelligence\Providers;

use App\Models\Social\SocialAccount;
use App\SocialIntelligence\Contracts\SocialIntelligenceProviderInterface;
use Illuminate\Support\Carbon;

abstract class AbstractProvider implements SocialIntelligenceProviderInterface
{
    protected function status(string $status, string $definition, ?string $reason = null): array
    {
        return ['status' => $status, 'reason' => $reason, 'definition' => $definition];
    }

    protected function interactions(SocialAccount $account, Carbon $from, Carbon $to): int
    {
        return (int) $account->conversations()
            ->whereHas('messages', fn ($query) => $query->where(function ($date) use ($from, $to) {
                $date->whereBetween('published_at', [$from, $to])
                    ->orWhere(fn ($null) => $null->whereNull('published_at')->whereBetween('created_at', [$from, $to]));
            }))
            ->withCount(['messages' => fn ($query) => $query->where(function ($date) use ($from, $to) {
                $date->whereBetween('published_at', [$from, $to])
                    ->orWhere(fn ($null) => $null->whereNull('published_at')->whereBetween('created_at', [$from, $to]));
            })])
            ->get()->sum('messages_count');
    }

    protected function unavailablePayload(SocialAccount $account, Carbon $from, Carbon $to): array
    {
        return ['contents' => [], 'metrics' => [], 'limitations' => [], 'interactions_seen' => $this->interactions($account, $from, $to)];
    }

    protected function appendInsightMetrics(array &$result, array $insights, array $metricMap, string $source): void
    {
        foreach ($insights as $insight) {
            $name = (string) ($insight['name'] ?? '');
            $definition = $metricMap[$name] ?? null;
            if (!$definition) continue;
            [$key, $fallbackDefinition] = $definition;
            foreach ($insight['values'] ?? [] as $point) {
                $rawValue = is_array($point['value'] ?? null) ? ($point['value']['value'] ?? null) : ($point['value'] ?? null);
                if (!is_numeric($rawValue)) continue;
                $end = isset($point['end_time']) ? Carbon::parse($point['end_time']) : now();
                $result['metrics'][] = [
                    'external_content_id' => null,
                    'metric_key' => $key,
                    'metric_definition' => (string) ($insight['description'] ?? $insight['title'] ?? $fallbackDefinition),
                    'value' => (float) $rawValue,
                    'unit' => 'count',
                    'source' => $source,
                    'comparable' => true,
                    'observed_at' => $end->copy()->startOfDay(),
                    'period_from' => $end->copy()->subDay()->startOfDay(),
                    'period_to' => $end->copy()->endOfDay(),
                ];
            }
        }
    }
}
