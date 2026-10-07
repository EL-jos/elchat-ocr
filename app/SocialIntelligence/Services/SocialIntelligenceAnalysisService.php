<?php

namespace App\SocialIntelligence\Services;

use App\Models\Site;
use App\Models\Social\{SocialAnalysis, SocialContent, SocialContentMetric, SocialInsight, SocialMessage, SocialRecommendation, SocialTopic};
use App\SocialIntelligence\Providers\ProviderRegistry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SocialIntelligenceAnalysisService
{
    public function __construct(private readonly SocialTopicExtractor $topics, private readonly SocialMetricCalculator $calculator, private readonly SocialIntelligenceAiService $ai, private readonly SocialIntelligenceConfigurationService $configuration, private readonly ProviderRegistry $providers) {}

    public function run(SocialAnalysis $analysis): SocialAnalysis
    {
        $analysis->loadMissing('site');
        $analysis->update(['status' => 'running', 'phase' => 'collecting', 'progress' => 5, 'started_at' => now(), 'error' => null]);
        try {
            $config = $analysis->configuration ?? [];
            $sources = array_values($config['sources'] ?? config('social-intelligence.supported_providers'));
            $from = Carbon::parse($analysis->period_from); $to = Carbon::parse($analysis->period_to);
            $previousFrom = $analysis->comparison_from ? Carbon::parse($analysis->comparison_from) : null;
            $previousTo = $analysis->comparison_to ? Carbon::parse($analysis->comparison_to) : null;
            $accountIds = $analysis->site->socialAccounts()->whereIn('provider', $sources)->where('is_active', true)->pluck('id');
            $contents = SocialContent::query()->where('site_id', $analysis->site_id)->whereIn('social_account_id', $accountIds)->whereBetween('published_at', [$from, $to])->get();
            $previousContents = $previousFrom && $previousTo ? SocialContent::query()->where('site_id', $analysis->site_id)->whereIn('social_account_id', $accountIds)->whereBetween('published_at', [$previousFrom, $previousTo])->get() : collect();
            $metrics = SocialContentMetric::query()->where('site_id', $analysis->site_id)->whereIn('social_account_id', $accountIds)->whereBetween('observed_at', [$from, $to])->get();
            $previousMetrics = $previousFrom && $previousTo ? SocialContentMetric::query()->where('site_id', $analysis->site_id)->whereIn('social_account_id', $accountIds)->whereBetween('observed_at', [$previousFrom, $previousTo])->get() : collect();
            $messages = SocialMessage::query()->with('conversation')->where('direction', 'incoming')->whereHas('conversation', fn ($conversation) => $conversation->where('site_id', $analysis->site_id)->whereIn('provider', $sources))->where(function ($date) use ($from, $to) { $date->whereBetween('published_at', [$from, $to])->orWhere(fn ($null) => $null->whereNull('published_at')->whereBetween('created_at', [$from, $to])); })->get();
            $previousMessages = $previousFrom && $previousTo ? SocialMessage::query()->with('conversation')->where('direction', 'incoming')->whereHas('conversation', fn ($conversation) => $conversation->where('site_id', $analysis->site_id)->whereIn('provider', $sources))->where(function ($date) use ($previousFrom, $previousTo) { $date->whereBetween('published_at', [$previousFrom, $previousTo])->orWhere(fn ($null) => $null->whereNull('published_at')->whereBetween('created_at', [$previousFrom, $previousTo])); })->get() : collect();
            $analysis->update(['phase' => 'signals', 'progress' => 30]);
            $quality = $this->quality($analysis->site, $sources, $contents, $messages, $metrics);
            $platforms = $this->platforms($contents, $messages, $metrics, $previousContents, $previousMessages, $previousMetrics);
            $currentTopics = $this->topics->extract($this->topicRecords($contents, $messages), ($config['depth'] ?? 'standard') === 'deep' ? 30 : 20);
            $previousTopics = $this->topics->extract($this->topicRecords($previousContents, $previousMessages), 30);
            $topicRows = $this->topicRows($analysis, $currentTopics, $previousTopics, $quality['status'] !== 'complete');
            $analysis->update(['phase' => 'insights', 'progress' => 55, 'data_quality' => $quality]);
            $deterministic = $this->deterministicInsights($quality, $platforms, $topicRows, $messages);
            $dataset = [
                'period' => ['from' => $from->toISOString(), 'to' => $to->toISOString()], 'data_quality' => $quality,
                'platforms' => $platforms, 'topics' => $topicRows->map(fn ($topic) => $topic->toArray())->values()->all(),
                'representative_contents' => $contents->take(($config['depth'] ?? 'standard') === 'deep' ? 30 : 10)->map(fn ($content) => ['platform' => $content->provider, 'type' => $content->content_type, 'title' => $content->title, 'body_excerpt' => mb_substr((string) $content->body, 0, 240), 'published_at' => $content->published_at?->toISOString()])->all(),
                'question_count' => $messages->filter(fn ($message) => str_contains((string) $message->content, '?'))->count(),
            ];
            $ai = $this->ai->interpret($dataset, $config['depth'] ?? 'standard');
            foreach ($ai['insights'] as $item) $deterministic[] = $this->sanitizeAiInsight($item, $quality);
            $quality['limitations'] = array_values(array_unique(array_merge($quality['limitations'], $ai['limitations'])));
            $this->persistInsights($analysis, $deterministic);
            $result = ['summary' => ['contents' => $contents->count(), 'interactions' => $messages->count(), 'metrics' => $metrics->count(), 'platforms' => count($platforms)], 'platforms' => $platforms, 'topics' => $topicRows->map(fn ($topic) => $topic->toArray())->values()->all(), 'data_quality' => $quality, 'ai' => ['used' => $ai['insights'] !== [], 'limitations' => $ai['limitations']], 'period' => ['from' => $from->toISOString(), 'to' => $to->toISOString(), 'comparison_from' => $previousFrom?->toISOString(), 'comparison_to' => $previousTo?->toISOString()]];
            $analysis->update(['status' => 'completed', 'phase' => 'completed', 'progress' => 100, 'result' => $result, 'data_quality' => $quality, 'completed_at' => now()]);
            return $analysis->fresh(['topics', 'insights', 'recommendations']);
        } catch (\Throwable $exception) {
            $analysis->update(['status' => 'failed', 'phase' => 'failed', 'error' => mb_substr($exception->getMessage(), 0, 2000), 'completed_at' => now()]);
            throw $exception;
        }
    }

    private function platforms($contents, $messages, $metrics, $previousContents, $previousMessages, $previousMetrics): array
    {
        $providers = collect([$contents->pluck('provider'), $messages->map(fn ($m) => $m->conversation?->provider)->filter(), $metrics->pluck('provider')])->flatten()->unique()->values();
        return $providers->mapWithKeys(function ($provider) use ($contents, $messages, $metrics, $previousContents, $previousMessages, $previousMetrics) {
            $currentContents = $contents->where('provider', $provider); $previousContentCount = $previousContents->where('provider', $provider)->count();
            $currentMessages = $messages->filter(fn ($m) => $m->conversation?->provider === $provider); $previousMessageCount = $previousMessages->filter(fn ($m) => $m->conversation?->provider === $provider)->count();
            $currentMetrics = $metrics->where('provider', $provider); $previousMetricSet = $previousMetrics->where('provider', $provider);
            $metricSummary = $currentMetrics->groupBy('metric_key')->map(fn ($rows) => ['value' => round((float) $rows->sum('value'), 2), 'definition' => $rows->first()->metric_definition, 'unit' => $rows->first()->unit, 'comparable' => $rows->every(fn ($row) => $row->comparable), 'samples' => $rows->count()])->all();
            $previousSummary = $previousMetricSet->groupBy('metric_key')->map(fn ($rows) => (float) $rows->sum('value'))->all();
            foreach ($metricSummary as $key => &$metric) { $metric['previous_value'] = $previousSummary[$key] ?? null; $metric['change_percent'] = $this->calculator->change($metric['value'], $metric['previous_value']); }
            return [$provider => ['contents' => $currentContents->count(), 'previous_contents' => $previousContentCount, 'interactions' => $currentMessages->count(), 'previous_interactions' => $previousMessageCount, 'metrics' => $metricSummary]];
        })->all();
    }

    private function topicRecords($contents, $messages): array
    {
        return [...$contents->map(fn ($content) => ['text' => trim(($content->title ?? '') . ' ' . ($content->body ?? '')), 'provider' => $content->provider, 'source_type' => 'content', 'interaction_count' => 0])->filter(fn ($record) => $record['text'] !== '')->all(), ...$messages->map(fn ($message) => ['text' => (string) $message->content, 'provider' => $message->conversation?->provider, 'source_type' => 'interaction', 'interaction_count' => 1])->filter(fn ($record) => trim($record['text']) !== '')->all()];
    }

    private function topicRows(SocialAnalysis $analysis, array $current, array $previous, bool $partial)
    {
        $previousByKey = collect($previous)->keyBy(fn ($topic) => "{$topic['source_type']}|{$topic['provider']}|{$topic['topic_key']}");
        return collect($current)->map(function ($topic) use ($analysis, $previousByKey, $partial) {
            $old = $previousByKey->get("{$topic['source_type']}|{$topic['provider']}|{$topic['topic_key']}");
            $change = $old ? $this->calculator->change((float) $topic['frequency'], (float) $old['frequency']) : null;
            $status = $this->calculator->trend($change, (int) $topic['frequency'], (int) ($old['frequency'] ?? 0), (int) config('social-intelligence.min_samples.standard', 3));
            $confidence = $this->calculator->confidence((int) $topic['frequency'], $partial);
            return SocialTopic::create(['account_id' => $analysis->account_id, 'site_id' => $analysis->site_id, 'social_analysis_id' => $analysis->id, 'provider' => $topic['provider'], 'source_type' => $topic['source_type'], 'topic_key' => $topic['topic_key'], 'label' => $topic['label'], 'frequency' => $topic['frequency'], 'interaction_count' => $topic['interaction_count'], 'change_percent' => $change, 'status' => $status, 'confidence' => $confidence, 'evidence' => ['frequency' => $topic['frequency'], 'previous_frequency' => $old['frequency'] ?? null, 'comparison' => $change !== null ? 'observed' : 'not_available']]);
        });
    }

    private function quality(Site $site, array $sources, $contents, $messages, $metrics): array
    {
        $accounts = $site->socialAccounts()->whereIn('provider', $sources)->where('is_active', true)->get();
        $limitations = []; $capabilities = [];
        foreach ($accounts as $account) {
            $provider = $account->provider instanceof \BackedEnum ? $account->provider->value : (string) $account->provider;
            $capabilities[$provider][] = $this->providers->capabilities($account);
            $latest = $account->syncRuns()->latest('created_at')->first();
            if (!$latest) $limitations[] = "{$provider}: aucune synchronisation enregistrée.";
            elseif ($latest->status === 'failed') $limitations[] = "{$provider}: dernière synchronisation échouée.";
            $limitations = array_merge($limitations, ($latest?->data_quality ?? [])['limitations'] ?? []);
        }
        $inputCount = $contents->count() + $messages->count(); $minimum = (int) config('social-intelligence.min_samples.standard', 3);
        $status = $inputCount === 0 ? 'no_data' : ($inputCount < $minimum ? 'insufficient_data' : ($limitations ? 'partial' : 'complete'));
        return ['status' => $status, 'selected_sources' => $sources, 'connected_accounts' => $accounts->count(), 'content_count' => $contents->count(), 'interaction_count' => $messages->count(), 'metric_count' => $metrics->count(), 'missing_sources' => array_values(array_diff($sources, $accounts->pluck('provider')->unique()->all())), 'limitations' => array_values(array_unique($limitations)), 'capabilities' => $capabilities];
    }

    private function deterministicInsights(array $quality, array $platforms, $topics, $messages): array
    {
        $items = [];
        foreach ($platforms as $provider => $summary) {
            if ($summary['contents'] > 0) $items[] = ['type' => 'observation', 'title' => "Activité {$provider} observée", 'importance' => 'medium', 'observation' => "{$summary['contents']} contenu(s) et {$summary['interactions']} interaction(s) ont été observés sur la période.", 'evidence' => ['platform' => $provider, 'period' => ['contents' => $summary['contents'], 'interactions' => $summary['interactions']], 'source' => 'social_intelligence'], 'correlation' => null, 'interpretation' => 'Ce constat décrit le volume observé sans conclure sur sa cause.', 'diagnosis' => null, 'opportunity' => null, 'recommendation' => null, 'implementation' => null, 'measurement' => null, 'confidence' => $this->calculator->confidence($summary['contents'], $quality['status'] !== 'complete'), 'limitations' => $quality['limitations']];
            foreach ($summary['metrics'] as $key => $metric) {
                if ($metric['change_percent'] !== null && abs($metric['change_percent']) >= 20 && $metric['samples'] >= 3) $items[] = ['type' => $metric['change_percent'] > 0 ? 'trend' : 'diagnostic', 'title' => "Évolution {$key} sur {$provider}", 'importance' => abs($metric['change_percent']) >= 50 ? 'high' : 'medium', 'observation' => "La métrique native {$key} évolue de {$metric['change_percent']}% par rapport à la période comparable.", 'evidence' => ['platform' => $provider, 'metric' => $key, 'metric_definition' => $metric['definition'], 'value' => $metric['value'], 'previous_value' => $metric['previous_value'], 'samples' => $metric['samples'], 'comparable' => $metric['comparable']], 'correlation' => null, 'interpretation' => 'L’évolution est observée dans la source native ; elle ne prouve pas une causalité.', 'diagnosis' => null, 'opportunity' => $metric['change_percent'] > 0 ? "Examiner les contenus associés à la progression {$key}." : null, 'recommendation' => $metric['change_percent'] > 0 ? 'Tester une série de contenus reprenant les caractéristiques observées, puis mesurer la même métrique native.' : 'Comparer les formats et sujets des contenus concernés avant de modifier la cadence.', 'implementation' => 'Sélectionner un petit échantillon de contenus, documenter le format et le sujet, puis lancer un test contrôlé.', 'measurement' => "Suivre {$key} sur la période suivante avec la même définition native.", 'confidence' => $this->calculator->confidence($metric['samples'], $quality['status'] !== 'complete'), 'limitations' => $quality['limitations']];
            }
        }
        $growing = $topics->whereIn('status', ['growing', 'emerging'])->sortByDesc('frequency')->first();
        if ($growing) $items[] = ['type' => 'opportunity', 'title' => "Sujet en progression : {$growing->label}", 'importance' => 'medium', 'observation' => "Le sujet {$growing->label} apparaît {$growing->frequency} fois dans les contenus/interactions sélectionnés.", 'evidence' => ['topic_id' => $growing->id, 'topic' => $growing->label, 'frequency' => $growing->frequency, 'change_percent' => $growing->change_percent, 'platform' => $growing->provider], 'correlation' => $growing->interaction_count > 0 ? 'Le sujet est observé dans des contenus et/ou interactions ; cette relation est corrélative.' : null, 'interpretation' => 'Le volume justifie un examen éditorial, sans garantir une performance future.', 'diagnosis' => null, 'opportunity' => 'Structurer un test de contenus autour de ce sujet.', 'recommendation' => 'Créer une série limitée autour du sujet puis comparer les métriques natives et les questions reçues.', 'implementation' => 'Définir un format, un CTA et une période de test ; conserver la plateforme comme dimension de mesure.', 'measurement' => 'Comparer fréquence, interactions et métriques disponibles à la période comparable.', 'confidence' => $growing->confidence, 'limitations' => $quality['limitations']];
        $questions = $messages->filter(fn ($message) => str_contains((string) $message->content, '?'))->count();
        if ($questions >= 3) $items[] = ['type' => 'diagnostic', 'title' => 'Questions récurrentes détectées', 'importance' => 'medium', 'observation' => "{$questions} interactions entrantes contiennent une question sur la période.", 'evidence' => ['question_count' => $questions, 'platforms' => $messages->map(fn ($message) => $message->conversation?->provider)->filter()->unique()->values()->all()], 'correlation' => null, 'interpretation' => 'Les questions sont un signal de besoin d’explication ou d’assistance ; leur intention doit être validée par échantillonnage.', 'diagnosis' => 'Le contenu ou le support peut nécessiter une réponse récurrente.', 'opportunity' => 'Transformer les questions fréquentes en contenu d’aide ou FAQ.', 'recommendation' => 'Regrouper les questions par sujet et produire une réponse réutilisable dans le canal concerné.', 'implementation' => 'Faire valider un échantillon par un responsable avant toute publication ou automatisation.', 'measurement' => 'Suivre la fréquence des questions et le délai/résolution des interactions.', 'confidence' => $this->calculator->confidence($questions, $quality['status'] !== 'complete'), 'limitations' => $quality['limitations']];
        if ($quality['status'] !== 'complete') $items[] = ['type' => 'data_quality', 'title' => 'Analyse sociale partielle', 'importance' => 'high', 'observation' => 'Les résultats reposent sur un périmètre ou des permissions incomplets.', 'evidence' => $quality, 'correlation' => null, 'interpretation' => 'Les tendances fortes doivent être validées après synchronisation complète.', 'diagnosis' => null, 'opportunity' => null, 'recommendation' => 'Reconnecter les comptes ou accorder les permissions signalées, puis relancer une analyse comparable.', 'implementation' => 'Corriger chaque limitation listée dans la qualité des données avant de comparer les plateformes.', 'measurement' => 'Vérifier la réduction des sources manquantes et l’augmentation de l’historique disponible.', 'confidence' => 'low', 'limitations' => $quality['limitations']];
        return $items;
    }

    private function sanitizeAiInsight(array $item, array $quality): array
    {
        return array_merge(['type' => 'interpretation', 'title' => 'Interprétation sociale', 'importance' => 'medium', 'observation' => null, 'evidence' => [], 'correlation' => null, 'interpretation' => null, 'diagnosis' => null, 'opportunity' => null, 'recommendation' => null, 'implementation' => null, 'measurement' => null, 'confidence' => 'low', 'limitations' => []], $item, ['limitations' => array_values(array_unique(array_merge($quality['limitations'], $item['limitations'] ?? []))) ]);
    }

    private function persistInsights(SocialAnalysis $analysis, array $items): void
    {
        DB::transaction(function () use ($analysis, $items) {
            foreach ($items as $item) {
                $insight = SocialInsight::create(['account_id' => $analysis->account_id, 'site_id' => $analysis->site_id, 'social_analysis_id' => $analysis->id, ...collect($item)->only(['type','title','importance','observation','evidence','correlation','interpretation','diagnosis','opportunity','recommendation','implementation','measurement','confidence','limitations'])->all()]);
                if (!empty($item['recommendation']) && !empty($item['measurement'])) SocialRecommendation::create(['account_id' => $analysis->account_id, 'site_id' => $analysis->site_id, 'social_analysis_id' => $analysis->id, 'social_insight_id' => $insight->id, 'title' => $item['title'], 'problem' => $item['diagnosis'], 'why' => $item['interpretation'], 'action' => $item['recommendation'], 'how' => $item['implementation'], 'expected_signal' => $item['opportunity'], 'measurement' => $item['measurement'], 'action_proposal' => ['requires_human_approval' => true, 'execution' => 'existing_agent_or_workflow']]);
            }
        });
    }
}
