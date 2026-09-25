<?php

namespace App\Services\WebsiteGrowthAdvisor;

use App\Models\Mcp\McpAgent;
use App\Models\Site;
use App\Models\WebsiteGrowthAdvisorConfiguration;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Single source of truth for the durable, tenant-scoped Growth Advisor setup.
 * The same normalized payload is used by the API, scheduler and analysis job.
 */
final class WebsiteGrowthAdvisorConfigurationService
{
    /** @return array<string, mixed> */
    public function defaults(): array
    {
        return [
            'primary_objective' => 'lead_generation',
            'secondary_objectives' => [],
            'priority_areas' => ['conversion', 'journey', 'engagement'],
            'enabled_sources' => ['visitor_intelligence', 'conversations', 'knowledge', 'live_crawl', 'google_analytics', 'search_console'],
            'crawl_options' => [
                'scope' => 'page',
                'urls' => [],
                'max_pages' => 8,
                'max_depth' => 1,
            ],
            'period_type' => 'last_30_days',
            'custom_period_from' => null,
            'custom_period_to' => null,
            'compare_previous_period' => false,
            'visitor_segments' => ['all'],
            'analysis_depth' => 'standard',
            'journey_options' => [
                'analyze_journeys' => true,
                'detect_conversion_paths' => true,
                'detect_abandonment_paths' => true,
                'compare_acquisition_sources' => true,
                'detect_engagement_patterns' => true,
            ],
            'use_visual_evidence' => true,
            'visual_analysis_mode' => 'targeted',
            'max_representative_sessions' => 12,
            'evidence_requirement' => 'medium',
            'require_cause_analysis' => true,
            'require_evidence' => true,
            'allow_hypotheses' => true,
            'require_uncertainty_disclosure' => true,
            'recommendation_depth' => 'actionable',
            'implementation_detail' => 'practical',
            'prioritization_criteria' => ['impact', 'frequency', 'severity', 'evidence_strength', 'conversion_proximity'],
            'require_measurement_plan' => true,
            'primary_kpi' => 'conversions',
            'secondary_kpis' => ['leads', 'engagement_rate'],
            'conversation_options' => [
                'analyze_conversations' => true,
                'detect_questions' => true,
                'detect_objections' => true,
                'detect_knowledge_gaps' => true,
                'detect_conversion_intent' => true,
            ],
            'knowledge_options' => [
                'use_knowledge' => true,
                'use_knowledge_for_diagnosis' => true,
                'use_knowledge_for_recommendations' => true,
            ],
            'execution_mode' => 'manual',
            'schedule_frequency' => null,
            'schedule_time' => '09:00',
            'next_run_at' => null,
            'is_active' => true,
            'version' => 1,
            'configuration_mode' => 'preset',
            'preset_key' => 'lead_generation',
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public function presets(): array
    {
        $base = $this->defaults();

        return [
            'lead_generation' => [
                'label' => 'Générer des leads',
                'description' => 'Repérer les freins et opportunités qui peuvent augmenter les demandes de contact.',
                'configuration' => array_replace($base, [
                    'primary_objective' => 'lead_generation',
                    'priority_areas' => ['conversion', 'engagement', 'content'],
                    'primary_kpi' => 'leads',
                    'secondary_kpis' => ['conversations', 'conversions'],
                    'configuration_mode' => 'preset',
                    'preset_key' => 'lead_generation',
                ]),
            ],
            'conversion_rate' => [
                'label' => 'Améliorer la conversion',
                'description' => 'Analyser le tunnel, les abandons, les CTA et les parcours avant conversion.',
                'configuration' => array_replace($base, [
                    'primary_objective' => 'conversion_rate',
                    'priority_areas' => ['conversion', 'journey'],
                    'primary_kpi' => 'conversions',
                    'secondary_kpis' => ['leads', 'engagement_rate'],
                    'configuration_mode' => 'preset',
                    'preset_key' => 'conversion_rate',
                ]),
            ],
            'seo_growth' => [
                'label' => 'Développer le SEO',
                'description' => 'Relier les parcours aux requêtes, pages et opportunités de visibilité organique.',
                'configuration' => array_replace($base, [
                    'primary_objective' => 'seo_growth',
                    'priority_areas' => ['seo', 'content', 'acquisition'],
                    'enabled_sources' => ['visitor_intelligence', 'knowledge', 'live_crawl', 'google_analytics', 'search_console'],
                    'primary_kpi' => 'sessions',
                    'secondary_kpis' => ['engagement_rate', 'conversions'],
                    'configuration_mode' => 'preset',
                    'preset_key' => 'seo_growth',
                ]),
            ],
            'organic_acquisition' => [
                'label' => 'Accroître l’acquisition organique',
                'description' => 'Identifier les sources, contenus et parcours qui peuvent développer le trafic organique.',
                'configuration' => array_replace($base, [
                    'primary_objective' => 'organic_acquisition',
                    'priority_areas' => ['acquisition', 'seo', 'content'],
                    'enabled_sources' => ['visitor_intelligence', 'knowledge', 'live_crawl', 'google_analytics', 'search_console'],
                    'primary_kpi' => 'sessions',
                    'secondary_kpis' => ['conversions', 'engagement_rate'],
                    'configuration_mode' => 'preset',
                    'preset_key' => 'organic_acquisition',
                ]),
            ],
            'engagement' => [
                'label' => 'Améliorer l’engagement',
                'description' => 'Comprendre les interactions, questions et signaux d’intérêt des visiteurs.',
                'configuration' => array_replace($base, [
                    'primary_objective' => 'engagement',
                    'priority_areas' => ['engagement', 'journey', 'content'],
                    'primary_kpi' => 'engagement_rate',
                    'secondary_kpis' => ['conversations', 'leads'],
                    'configuration_mode' => 'preset',
                    'preset_key' => 'engagement',
                ]),
            ],
            'ecommerce_revenue' => [
                'label' => 'Augmenter le revenu e-commerce',
                'description' => 'Analyser les produits, paniers, étapes d’achat et conversions observées.',
                'configuration' => array_replace($base, [
                    'primary_objective' => 'ecommerce_revenue',
                    'priority_areas' => ['conversion', 'journey', 'engagement'],
                    'primary_kpi' => 'conversions',
                    'secondary_kpis' => ['leads', 'engagement_rate'],
                    'configuration_mode' => 'preset',
                    'preset_key' => 'ecommerce_revenue',
                ]),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function preset(string $key): array
    {
        return $this->presets()[$key]['configuration'] ?? throw ValidationException::withMessages([
            'preset_key' => 'Modèle de configuration Growth Advisor inconnu.',
        ]);
    }

    public function forAgent(Site $site, McpAgent $agent): WebsiteGrowthAdvisorConfiguration
    {
        return WebsiteGrowthAdvisorConfiguration::query()->firstOrCreate(
            ['site_id' => $site->id, 'agent_id' => $agent->id],
            array_merge($this->defaults(), [
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'account_id' => $site->account_id,
            ]),
        );
    }

    /** @return array<string, mixed> */
    public function effective(?WebsiteGrowthAdvisorConfiguration $configuration): array
    {
        $defaults = $this->defaults();
        if (! $configuration) {
            return $defaults;
        }

        $values = $configuration->toArray();
        foreach (['secondary_objectives', 'priority_areas', 'enabled_sources', 'visitor_segments', 'journey_options', 'prioritization_criteria', 'secondary_kpis', 'conversation_options', 'knowledge_options', 'crawl_options'] as $key) {
            if (! is_array($values[$key] ?? null)) {
                $values[$key] = $defaults[$key];
            }
        }

        $effective = array_replace($defaults, array_intersect_key($values, $defaults));
        if (($effective['configuration_mode'] ?? null) === 'preset' && empty($effective['preset_key'])) {
            $objective = (string) ($effective['primary_objective'] ?? 'lead_generation');
            if (array_key_exists($objective, $this->presets())) $effective['preset_key'] = $objective;
        }
        return $effective;
    }

    /** @param array<string, mixed> $input */
    public function normalize(array $input, ?WebsiteGrowthAdvisorConfiguration $current = null): array
    {
        $values = array_replace($this->effective($current), $input);
        $values['enabled_sources'] = array_values(array_unique(array_intersect(
            (array) ($values['enabled_sources'] ?? []),
            ['visitor_intelligence', 'conversations', 'knowledge', 'live_crawl', 'google_analytics', 'search_console'],
        )));
        $crawlOptions = array_replace($this->defaults()['crawl_options'], is_array($values['crawl_options'] ?? null) ? $values['crawl_options'] : []);
        $crawlOptions['scope'] = in_array($crawlOptions['scope'] ?? null, ['page', 'site'], true) ? $crawlOptions['scope'] : 'page';
        $crawlOptions['urls'] = collect(is_array($crawlOptions['urls'] ?? null) ? $crawlOptions['urls'] : [])
            ->filter(fn ($url): bool => is_string($url) && trim($url) !== '')
            ->map(fn (string $url): string => trim($url))
            ->unique()
            ->take(10)
            ->values()
            ->all();
        $crawlOptions['max_pages'] = max(1, min(20, (int) ($crawlOptions['max_pages'] ?? 8)));
        $crawlOptions['max_depth'] = max(0, min(3, (int) ($crawlOptions['max_depth'] ?? 1)));
        $values['crawl_options'] = $crawlOptions;
        $values['visitor_segments'] = array_values(array_unique(array_intersect(
            (array) ($values['visitor_segments'] ?? []),
            ['all', 'new', 'returning', 'organic', 'paid', 'direct', 'referral'],
        )));
        if ($values['visitor_segments'] === []) $values['visitor_segments'] = ['all'];
        if (in_array('all', $values['visitor_segments'], true)) $values['visitor_segments'] = ['all'];

        $values['max_representative_sessions'] = max(3, min(50, (int) ($values['max_representative_sessions'] ?? 12)));
        $values['schedule_time'] = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) ($values['schedule_time'] ?? ''))
            ? $values['schedule_time']
            : '09:00';
        if (($values['execution_mode'] ?? 'manual') === 'manual') {
            $values['schedule_frequency'] = null;
            $values['next_run_at'] = null;
        }

        return $values;
    }

    /** @return array{from: Carbon, to: Carbon} */
    public function resolvePeriod(array $configuration, ?string $from = null, ?string $to = null): array
    {
        if (($configuration['period_type'] ?? null) === 'custom' && ! empty($configuration['custom_period_from']) && ! empty($configuration['custom_period_to'])) {
            $periodFrom = Carbon::parse($configuration['custom_period_from'])->startOfDay();
            $periodTo = Carbon::parse($configuration['custom_period_to'])->endOfDay();
        } else {
            $periodTo = now()->endOfDay();
            $periodFrom = match ($configuration['period_type'] ?? 'last_30_days') {
                'last_24_hours' => $periodTo->copy()->subDay(),
                'last_7_days' => $periodTo->copy()->subDays(7)->startOfDay(),
                'last_90_days' => $periodTo->copy()->subDays(90)->startOfDay(),
                default => $periodTo->copy()->subDays(30)->startOfDay(),
            };
        }

        // Explicit API dates remain supported for backward-compatible callers.
        if ($from) $periodFrom = Carbon::parse($from)->startOfDay();
        if ($to) $periodTo = Carbon::parse($to)->endOfDay();
        if ($periodFrom->gt($periodTo)) [$periodFrom, $periodTo] = [$periodTo->copy()->startOfDay(), $periodFrom->copy()->endOfDay()];

        return ['from' => $periodFrom, 'to' => $periodTo];
    }

    /** @param array<string, mixed> $configuration */
    public function scheduleNextRun(array $configuration, ?Carbon $base = null): ?Carbon
    {
        if (($configuration['execution_mode'] ?? 'manual') !== 'scheduled') return null;
        $frequency = $configuration['schedule_frequency'] ?? null;
        if (! in_array($frequency, ['daily', 'weekly', 'monthly'], true)) return null;
        $time = (string) ($configuration['schedule_time'] ?? '09:00');
        $now = ($base ?: now())->copy();
        $next = match ($frequency) {
            'daily' => $now->addDay(),
            'weekly' => $now->addWeek(),
            'monthly' => $now->addMonth(),
        };
        return $next->setTimeFromTimeString($time);
    }
}
