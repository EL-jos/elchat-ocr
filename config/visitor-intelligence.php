<?php

return [
    'enabled' => env('VISITOR_INTELLIGENCE_ENABLED', true),
    // Visitor Intelligence is intentionally ephemeral: every journey artifact
    // shares one fixed two-day retention boundary.
    'session_retention_days' => 2,
    'summary_retention_days' => 2,
    'ingestion_max_batch' => (int) env('VISITOR_INTELLIGENCE_INGESTION_MAX_BATCH', 100),
    'pointer_tracking_enabled' => env('VISITOR_INTELLIGENCE_POINTER_TRACKING_ENABLED', true),
    // Le pipeline de capture d'écran a été retiré (rrweb est l'unique canal de
    // replay visuel). Cette clé n'est conservée que pour purger, via
    // VisitorIntelligenceFrameService, les screenshots déjà stockés avant ce
    // changement lors d'une suppression RGPD explicite d'une session.
    'frame_storage_disk' => env('VISITOR_INTELLIGENCE_FRAME_STORAGE_DISK', 'public'),
    'replay_chunk_max_events' => (int) env('VISITOR_INTELLIGENCE_REPLAY_CHUNK_MAX_EVENTS', 500),
    'replay_chunk_max_bytes' => (int) env('VISITOR_INTELLIGENCE_REPLAY_CHUNK_MAX_BYTES', 1572864),
    'replay_max_events' => (int) env('VISITOR_INTELLIGENCE_REPLAY_MAX_EVENTS', 100000),
    'bot_detection' => [
        // Enabled with a unique-per-session job so frequent browser/replay
        // batches cannot flood the shared analytics queue.
        'enabled' => env('VISITOR_INTELLIGENCE_BOT_DETECTION_ENABLED', true),
        'score_delay_seconds' => (int) env('VISITOR_INTELLIGENCE_BOT_SCORE_DELAY_SECONDS', 20),
    ],
    'geo' => [
        'enabled' => env('VISITOR_INTELLIGENCE_GEO_ENABLED', true),
        'endpoint' => env('VISITOR_INTELLIGENCE_GEO_ENDPOINT', 'https://ipwho.is/{ip}'),
        'connect_timeout' => (int) env('VISITOR_INTELLIGENCE_GEO_CONNECT_TIMEOUT', 2),
        'timeout' => (int) env('VISITOR_INTELLIGENCE_GEO_TIMEOUT', 5),
        // Null uses the queue connection's default queue, which is consumed by
        // a standard Laravel worker. A dedicated queue remains opt-in.
        'queue' => env('VISITOR_INTELLIGENCE_GEO_QUEUE'),
        'retry_after_seconds' => (int) env('VISITOR_INTELLIGENCE_GEO_RETRY_AFTER_SECONDS', 300),
        'queue_stale_after_seconds' => (int) env('VISITOR_INTELLIGENCE_GEO_QUEUE_STALE_AFTER_SECONDS', 900),
    ],
    'ai' => [
        'enabled' => env('VISITOR_INTELLIGENCE_AI_ENABLED', true),
        'analysis_delay_seconds' => (int) env('VISITOR_INTELLIGENCE_AI_ANALYSIS_DELAY_SECONDS', 20),
        'max_timeline_events' => (int) env('VISITOR_INTELLIGENCE_AI_MAX_TIMELINE_EVENTS', 180),
        'max_moments' => (int) env('VISITOR_INTELLIGENCE_AI_MAX_MOMENTS', 12),
        'max_visual_captures' => (int) env('VISITOR_INTELLIGENCE_AI_MAX_VISUAL_CAPTURES', 3),
        'request_timeout' => (int) env('VISITOR_INTELLIGENCE_AI_REQUEST_TIMEOUT', 45),
    ],
    'rrweb_context' => [
        'enabled' => env('VISITOR_INTELLIGENCE_RRWEB_CONTEXT_ENABLED', true),
        'node_binary' => env('VISITOR_INTELLIGENCE_RRWEB_NODE_BINARY', 'node'),
        'worker_script' => env('VISITOR_INTELLIGENCE_RRWEB_WORKER_SCRIPT', base_path('rrweb-renderer/render.mjs')),
        'replay_umd_path' => env(
            'VISITOR_INTELLIGENCE_RRWEB_REPLAY_UMD_PATH',
            is_file(base_path('rrweb-renderer'.DIRECTORY_SEPARATOR.'node_modules'.DIRECTORY_SEPARATOR.'@rrweb'.DIRECTORY_SEPARATOR.'replay'.DIRECTORY_SEPARATOR.'dist'.DIRECTORY_SEPARATOR.'replay.umd.cjs'))
                ? base_path('rrweb-renderer'.DIRECTORY_SEPARATOR.'node_modules'.DIRECTORY_SEPARATOR.'@rrweb'.DIRECTORY_SEPARATOR.'replay'.DIRECTORY_SEPARATOR.'dist'.DIRECTORY_SEPARATOR.'replay.umd.cjs')
                : dirname(base_path()).DIRECTORY_SEPARATOR.'frontend'.DIRECTORY_SEPARATOR.'dashboard'.DIRECTORY_SEPARATOR.'node_modules'.DIRECTORY_SEPARATOR.'@rrweb'.DIRECTORY_SEPARATOR.'replay'.DIRECTORY_SEPARATOR.'dist'.DIRECTORY_SEPARATOR.'replay.umd.cjs',
        ),
        'chromium_path' => env('VISITOR_INTELLIGENCE_RRWEB_CHROMIUM_PATH'),
        'timeout' => (int) env('VISITOR_INTELLIGENCE_RRWEB_CONTEXT_TIMEOUT', 90),
        'max_payload_bytes' => (int) env('VISITOR_INTELLIGENCE_RRWEB_CONTEXT_MAX_PAYLOAD_BYTES', 33554432),
    ],
];
