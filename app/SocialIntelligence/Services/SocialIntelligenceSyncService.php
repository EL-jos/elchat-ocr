<?php

namespace App\SocialIntelligence\Services;

use App\Models\Site;
use App\Models\Social\{SocialAccount, SocialContent, SocialContentMetric, SocialSyncRun};
use App\SocialIntelligence\Providers\ProviderRegistry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class SocialIntelligenceSyncService
{
    public function __construct(private readonly ProviderRegistry $providers) {}

    public function syncSite(Site $site, ?array $sources = null): array
    {
        $summary = ['accounts' => 0, 'contents' => 0, 'metrics' => 0, 'interactions' => 0, 'failed' => 0, 'limitations' => []];
        if (!config('social-intelligence.enabled', true)) {
            $summary['limitations'][] = 'Social Intelligence est désactivé par configuration.';
            return $summary;
        }
        $to = now()->endOfDay();
        $from = now()->subDays((int) config('social-intelligence.sync_days', 90))->startOfDay();
        $accounts = $site->socialAccounts()->where('is_active', true)->whereIn('provider', config('social-intelligence.supported_providers'))->when($sources, fn ($q) => $q->whereIn('provider', $sources))->get();
        foreach ($accounts as $account) {
            $summary['accounts']++;
            try {
                $summary = $this->syncAccount($site, $account, $from, $to, $summary);
            } catch (\Throwable $exception) {
                $summary['failed']++;
                Log::error('Social Intelligence account sync failed', ['site_id' => $site->id, 'account_id' => $account->id, 'error' => $exception->getMessage()]);
            }
        }
        return $summary;
    }

    private function syncAccount(Site $site, SocialAccount $account, Carbon $from, Carbon $to, array $summary): array
    {
        $provider = $this->providers->for($account);
        $providerKey = $this->providerValue($account);
        $run = SocialSyncRun::create(['account_id' => $site->account_id, 'site_id' => $site->id, 'social_account_id' => $account->id, 'provider' => $providerKey, 'status' => 'running', 'started_at' => now(), 'capabilities' => $provider->capabilities($account)]);
        try {
            $payload = $provider->sync($account, $from, $to);
            $contentsWritten = $metricsWritten = 0;
            DB::transaction(function () use ($site, $account, $providerKey, $payload, &$contentsWritten, &$metricsWritten) {
                $contentMap = [];
                foreach ($payload['contents'] as $content) {
                    if (empty($content['external_content_id'])) continue;
                    $model = SocialContent::firstOrNew([
                        'social_account_id' => $account->id,
                        'provider' => $providerKey,
                        'external_content_id' => $content['external_content_id'],
                    ]);
                    $model->fill([
                        'account_id' => $site->account_id,
                        'site_id' => $site->id,
                        ...$content,
                        'last_synced_at' => now(),
                        'availability_status' => 'available',
                    ]);
                    $model->first_seen_at ??= $content['published_at'] ?? now();
                    $model->save();
                    $contentMap[$content['external_content_id']] = $model->id;
                    $contentsWritten++;
                }
                foreach ($payload['metrics'] as $metric) {
                    $observedAt = isset($metric['observed_at']) ? Carbon::parse($metric['observed_at'])->startOfDay() : now()->startOfDay();
                    $contentId = $metric['external_content_id'] ?? null;
                    $dedupe = hash('sha256', implode('|', [(string) $account->id, $providerKey, (string) ($contentId ?? '-'), (string) $metric['metric_key'], $observedAt->toDateString()]));
                    SocialContentMetric::updateOrCreate(['dedupe_key' => $dedupe], ['account_id' => $site->account_id, 'site_id' => $site->id, 'social_account_id' => $account->id, 'social_content_id' => $contentMap[$contentId] ?? SocialContent::where('social_account_id', $account->id)->where('external_content_id', $contentId)->value('id'), 'provider' => $providerKey, 'metric_key' => $metric['metric_key'], 'metric_definition' => $metric['metric_definition'], 'value' => $metric['value'] ?? null, 'unit' => $metric['unit'] ?? 'count', 'period_from' => $metric['period_from'] ?? null, 'period_to' => $metric['period_to'] ?? null, 'observed_at' => $observedAt, 'source' => $metric['source'] ?? 'social_provider', 'availability_status' => $metric['availability_status'] ?? 'available', 'comparable' => (bool) ($metric['comparable'] ?? false), 'metadata' => $metric['metadata'] ?? []]);
                    $metricsWritten++;
                }
            });
            $limitations = array_values(array_unique($payload['limitations'] ?? []));
            $run->update(['status' => $limitations ? 'partial' : 'completed', 'finished_at' => now(), 'contents_seen' => count($payload['contents'] ?? []), 'contents_written' => $contentsWritten, 'metrics_written' => $metricsWritten, 'interactions_seen' => (int) ($payload['interactions_seen'] ?? 0), 'data_quality' => ['status' => $limitations ? 'partial' : 'complete', 'limitations' => $limitations]]);
            $summary['contents'] += $contentsWritten; $summary['metrics'] += $metricsWritten; $summary['interactions'] += (int) ($payload['interactions_seen'] ?? 0); $summary['limitations'] = array_merge($summary['limitations'], $limitations);
        } catch (\Throwable $exception) {
            $run->update(['status' => 'failed', 'finished_at' => now(), 'error' => Str::limit($exception->getMessage(), 1000), 'data_quality' => ['status' => 'partial', 'limitations' => ['Synchronisation échouée pour ce compte.']]]);
            throw $exception;
        }
        return $summary;
    }

    private function providerValue(SocialAccount $account): string
    {
        return $account->provider instanceof \BackedEnum ? $account->provider->value : (string) $account->provider;
    }
}
