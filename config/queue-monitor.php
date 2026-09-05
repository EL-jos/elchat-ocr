<?php

return [
    'table' => 'queue_monitor',
    'connection' => null,
    'model' => \romanzipp\QueueMonitor\Models\Monitor::class,
    'monitor_queued_jobs' => true,
    'db_max_length_exception' => 4294967295,
    'db_max_length_exception_message' => 65535,
    'ui' => [
        'enabled' => true,
        'route' => [
            'prefix' => 'jobs',
        ],
        'per_page' => 35,
        'show_custom_data' => false,
        'allow_deletion' => true,
        'allow_retry' => true,
        'allow_purge' => true,
        'show_metrics' => true,
        'metrics_time_frame' => 14,
        'refresh_interval' => null,
        'order_queued_first' => false,
    ],
];
