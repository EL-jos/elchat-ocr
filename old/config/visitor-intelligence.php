<?php

return [
    'enabled' => env('VISITOR_INTELLIGENCE_ENABLED', true),
    // Raw visitor replays are intentionally short-lived. Keep the setting
    // configurable through the environment while using 48 hours by default.
    'session_retention_days' => (int) env('VISITOR_INTELLIGENCE_SESSION_RETENTION_DAYS', 2),
    'summary_retention_days' => (int) env('VISITOR_INTELLIGENCE_SUMMARY_RETENTION_DAYS', 365),
    'ingestion_max_batch' => (int) env('VISITOR_INTELLIGENCE_INGESTION_MAX_BATCH', 100),
    'pointer_tracking_enabled' => env('VISITOR_INTELLIGENCE_POINTER_TRACKING_ENABLED', true),
    'frame_capture_enabled' => env('VISITOR_INTELLIGENCE_FRAME_CAPTURE_ENABLED', true),
    'frame_storage_disk' => env('VISITOR_INTELLIGENCE_FRAME_STORAGE_DISK', 'public'),
    'frame_max_bytes' => (int) env('VISITOR_INTELLIGENCE_FRAME_MAX_BYTES', 2097152),
    'replay_chunk_max_events' => (int) env('VISITOR_INTELLIGENCE_REPLAY_CHUNK_MAX_EVENTS', 500),
    'replay_chunk_max_bytes' => (int) env('VISITOR_INTELLIGENCE_REPLAY_CHUNK_MAX_BYTES', 1572864),
    'replay_max_events' => (int) env('VISITOR_INTELLIGENCE_REPLAY_MAX_EVENTS', 100000),
];
