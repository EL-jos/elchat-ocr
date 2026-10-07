<?php

return [
    'enabled' => (bool) env('SOCIAL_INTELLIGENCE_ENABLED', true),
    'queue' => env('SOCIAL_INTELLIGENCE_QUEUE', 'analytics'),
    'ai_enabled' => (bool) env('SOCIAL_INTELLIGENCE_AI_ENABLED', true),
    'sync_days' => (int) env('SOCIAL_INTELLIGENCE_SYNC_DAYS', 90),
    'max_contents_per_provider' => (int) env('SOCIAL_INTELLIGENCE_MAX_CONTENTS_PER_PROVIDER', 100),
    'facebook_insight_metrics' => ['page_impressions', 'page_engaged_users', 'page_fans', 'page_fan_adds', 'page_fan_removes'],
    'instagram_insight_metrics' => ['views', 'reach', 'follower_count', 'accounts_engaged', 'total_interactions', 'likes', 'comments', 'shares', 'saved'],
    'min_samples' => ['quick' => 2, 'standard' => 3, 'deep' => 5],
    'supported_providers' => ['instagram', 'facebook', 'youtube', 'telegram'],
];
