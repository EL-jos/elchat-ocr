<?php

namespace App\SocialIntelligence\Services;

use App\Models\Site;
use App\Models\Social\{SocialAnalysis, SocialContent, SocialInsight, SocialRecommendation};

class SocialIntelligenceQueryService
{
    public function overview(Site $site, array $filters = []): array
    {
        $provider = $filters['provider'] ?? null;
        $analysisQuery = SocialAnalysis::query()->where('site_id', $site->id)->latest('created_at');
        if ($provider) {
            // A provider view only reads an analysis created for that one
            // provider; combined scheduled analyses remain separate records.
            $analysis = SocialAnalysis::query()->where('site_id', $site->id)
                ->whereJsonLength('configuration->sources', 1)
                ->whereJsonContains('configuration->sources', $provider)
                ->latest('created_at')->first();
        } else {
            $analysis = $analysisQuery->first();
        }

        $accounts = $site->socialAccounts()
            ->whereIn('provider', $provider ? [$provider] : config('social-intelligence.supported_providers'))
            ->where('is_active', true)
            ->get()
            ->map(fn ($account) => [
                'id' => $account->id,
                'provider' => $account->provider instanceof \BackedEnum ? $account->provider->value : $account->provider,
                'account_name' => $account->account_name,
                'last_sync' => $account->syncRuns()->latest('created_at')->first()?->created_at?->toISOString(),
            ])->values()->all();

        $insights = $analysis
            ? $analysis->insights()->with('recommendations')->latest()->limit(20)->get()
            : collect();
        $recommendations = $analysis
            ? $analysis->recommendations()->latest()->limit(20)->get()
            : collect();

        return [
            'analysis' => $analysis ? [
                'id' => $analysis->id,
                'status' => $analysis->status,
                'progress' => $analysis->progress,
                'phase' => $analysis->phase,
                'created_at' => $analysis->created_at,
                'completed_at' => $analysis->completed_at,
                'configuration' => $analysis->configuration,
                'data_quality' => $analysis->data_quality,
                'result' => $analysis->result,
            ] : null,
            'accounts' => $accounts,
            'insights' => $insights,
            'recommendations' => $recommendations,
            'data_quality' => $analysis?->data_quality ?? [
                'status' => 'no_data',
                'limitations' => [$provider
                    ? "Aucune analyse indépendante de {$provider} n’a encore été exécutée."
                    : 'Aucune analyse n’a encore été exécutée.'],
            ],
        ];
    }

    public function platforms(Site $site, ?string $analysisId = null): array
    {
        $analysis = $analysisId ? SocialAnalysis::where('site_id', $site->id)->findOrFail($analysisId) : SocialAnalysis::where('site_id', $site->id)->latest()->first();
        return ['analysis_id' => $analysis?->id, 'data' => $analysis?->result['platforms'] ?? [], 'data_quality' => $analysis?->data_quality ?? ['status' => 'no_data']];
    }

    public function contents(Site $site, array $filters = [])
    {
        return SocialContent::query()->where('site_id', $site->id)->when($filters['provider'] ?? null, fn ($q, $provider) => $q->where('provider', $provider))->when($filters['from'] ?? null, fn ($q, $from) => $q->where('published_at', '>=', $from))->when($filters['to'] ?? null, fn ($q, $to) => $q->where('published_at', '<=', $to))->with(['metrics' => fn ($q) => $q->latest('observed_at')->limit(20)])->latest('published_at')->paginate(min(100, max(10, (int) ($filters['per_page'] ?? 25))));
    }

    public function insights(Site $site, array $filters = [])
    {
        return SocialInsight::query()->where('site_id', $site->id)->when($filters['analysis_id'] ?? null, fn ($q, $id) => $q->where('social_analysis_id', $id))->when($filters['type'] ?? null, fn ($q, $type) => $q->where('type', $type))->with('recommendations')->latest()->paginate(min(100, max(10, (int) ($filters['per_page'] ?? 25))));
    }

    public function insight(Site $site, string $insightId): SocialInsight
    {
        return SocialInsight::query()->where('site_id', $site->id)->with(['analysis', 'recommendations'])->findOrFail($insightId);
    }

    public function analyses(Site $site)
    {
        return SocialAnalysis::query()->where('site_id', $site->id)->latest()->paginate(20);
    }
}
