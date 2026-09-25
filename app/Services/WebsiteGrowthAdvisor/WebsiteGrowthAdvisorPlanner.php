<?php

namespace App\Services\WebsiteGrowthAdvisor;

/**
 * Converts tenant preferences into bounded collection decisions. Keeping this
 * separate makes it testable and prevents the UI from becoming a cosmetic
 * settings screen.
 */
final class WebsiteGrowthAdvisorPlanner
{
    /** @param array<string, mixed> $configuration @return array<string, mixed> */
    public function plan(array $configuration): array
    {
        $depth = $configuration['analysis_depth'] ?? 'standard';
        $sources = array_values(array_unique((array) ($configuration['enabled_sources'] ?? [])));
        $journey = array_replace([
            'analyze_journeys' => true,
            'detect_conversion_paths' => true,
            'detect_abandonment_paths' => true,
            'compare_acquisition_sources' => true,
            'detect_engagement_patterns' => true,
        ], (array) ($configuration['journey_options'] ?? []));
        $conversations = array_replace(['analyze_conversations' => true], (array) ($configuration['conversation_options'] ?? []));
        $knowledge = array_replace(['use_knowledge' => true], (array) ($configuration['knowledge_options'] ?? []));
        $visualDeep = $depth === 'deep' || ($configuration['visual_analysis_mode'] ?? 'targeted') === 'deep';
        $visualEnabled = (bool) ($configuration['use_visual_evidence'] ?? true)
            && ($configuration['visual_analysis_mode'] ?? 'targeted') !== 'disabled'
            && $depth !== 'quick';

        return [
            'sources' => [
                'visitor_intelligence' => in_array('visitor_intelligence', $sources, true),
                'conversations' => in_array('conversations', $sources, true) && (bool) ($conversations['analyze_conversations'] ?? true),
                'knowledge' => in_array('knowledge', $sources, true) && (bool) ($knowledge['use_knowledge'] ?? true),
                'live_crawl' => in_array('live_crawl', $sources, true),
                'google_analytics' => in_array('google_analytics', $sources, true),
                'search_console' => in_array('search_console', $sources, true),
            ],
            'period_comparison' => (bool) ($configuration['compare_previous_period'] ?? false),
            'visitor_segments' => array_values(array_diff((array) ($configuration['visitor_segments'] ?? ['all']), ['all'])),
            'journey_options' => $journey,
            'conversation_options' => $conversations,
            'knowledge_options' => $knowledge,
            'crawl_options' => array_replace([
                'scope' => 'page',
                'urls' => [],
                'max_pages' => 8,
                'max_depth' => 1,
            ], (array) ($configuration['crawl_options'] ?? [])),
            'candidate_sessions_limit' => $depth === 'quick' ? 100 : 250,
            'representative_sessions_limit' => min(
                $depth === 'deep' ? 50 : ($depth === 'quick' ? 8 : 25),
                max(3, (int) ($configuration['max_representative_sessions'] ?? 12)),
            ),
            'visual' => [
                'enabled' => $visualEnabled,
                'sessions_limit' => $visualEnabled ? ($visualDeep ? 5 : 3) : 0,
                'moments_per_session' => $visualEnabled ? ($visualDeep ? 6 : 4) : 0,
                'mode' => $configuration['visual_analysis_mode'] ?? 'targeted',
            ],
            'include_conversations' => in_array('conversations', $sources, true) && (bool) ($conversations['analyze_conversations'] ?? true),
            'include_knowledge' => in_array('knowledge', $sources, true) && (bool) ($knowledge['use_knowledge'] ?? true),
            'external_requested' => in_array('google_analytics', $sources, true) || in_array('search_console', $sources, true),
        ];
    }
}
