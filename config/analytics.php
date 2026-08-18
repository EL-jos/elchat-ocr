<?php

return [
    'enabled' => env('ANALYTICS_ENABLED', true),
    'async' => env('ANALYTICS_ASYNC', true),
    'queue' => env('ANALYTICS_QUEUE', 'analytics'),
    'raw_event_retention_days' => (int) env('ANALYTICS_RAW_RETENTION_DAYS', 180),
    'daily_aggregation_enabled' => env('ANALYTICS_DAILY_AGGREGATION_ENABLED', true),
    'default_period_days' => (int) env('ANALYTICS_DEFAULT_PERIOD_DAYS', 30),
    'max_period_days' => (int) env('ANALYTICS_MAX_PERIOD_DAYS', 366),
    'anomaly_relative_threshold' => (float) env('ANALYTICS_ANOMALY_RELATIVE_THRESHOLD', 0.25),
    'insight_minimum_sample' => (int) env('ANALYTICS_INSIGHT_MINIMUM_SAMPLE', 10),
    'execution_failure_rate_threshold' => (float) env('ANALYTICS_EXECUTION_FAILURE_RATE_THRESHOLD', 0.15),
    'sensitive_metadata_keys' => [
        'authorization', 'password', 'password_confirmation', 'token', 'access_token',
        'refresh_token', 'secret', 'client_secret', 'api_key', 'credentials',
        'email', 'phone', 'telephone', 'ip', 'ip_address', 'content', 'message',
    ],
];
