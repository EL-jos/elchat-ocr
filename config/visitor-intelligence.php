<?php

return [
    'enabled' => env('VISITOR_INTELLIGENCE_ENABLED', true),
    // Keep event ingestion on the analytics queue, but isolate the derived
    // Visitor Intelligence and AI work so it cannot compete with raw event
    // recording or the latency-sensitive default queue.
    'queue' => env('VISITOR_INTELLIGENCE_QUEUE', 'visitor-intelligence'),
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
    'attribution' => [
        // These registries are deliberately configuration-driven: new AI
        // assistants and search engines can be added without changing the
        // attribution algorithm or the database contract.
        'ai_domains' => [
            'chatgpt.com', 'chat.openai.com', 'openai.com',
            'perplexity.ai', 'claude.ai', 'anthropic.com',
            'gemini.google.com', 'copilot.microsoft.com', 'copilot.com',
            'you.com', 'phind.com', 'poe.com', 'grok.com',
            'meta.ai', 'mistral.ai', 'deepseek.com', 'character.ai',
        ],
        'search_domains' => [
            'google.', 'bing.com', 'search.yahoo.', 'duckduckgo.com',
            'ecosia.org', 'yandex.', 'baidu.com', 'brave.com',
        ],
        'social_domains' => [
            'facebook.com', 'instagram.com', 'linkedin.com', 'twitter.com',
            'x.com', 't.co', 'youtube.com', 'youtu.be', 'tiktok.com',
            'pinterest.com', 'reddit.com', 'threads.net',
        ],
        'paid_click_parameters' => [
            'gclid' => 'google', 'dclid' => 'google', 'gbraid' => 'google',
            'wbraid' => 'google', 'msclkid' => 'microsoft',
            'fbclid' => 'meta', 'ttclid' => 'tiktok', 'li_fat_id' => 'linkedin',
            'twclid' => 'x',
        ],
    ],
    'bot_detection' => [
        // Enabled with a unique-per-session job so frequent browser/replay
        // batches cannot flood the dedicated Visitor Intelligence queue.
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
        // Keep the legacy path untouched for existing deployments. The
        // dedicated binary path takes precedence when a distribution command
        // (for example a Snap launcher) differs from the real executable.
        'chromium_binary_path' => env('VISITOR_INTELLIGENCE_RRWEB_CHROMIUM_BINARY_PATH'),
        'chromium_path' => env('VISITOR_INTELLIGENCE_RRWEB_CHROMIUM_PATH'),
        'timeout' => (int) env('VISITOR_INTELLIGENCE_RRWEB_CONTEXT_TIMEOUT', 90),
        'max_payload_bytes' => (int) env('VISITOR_INTELLIGENCE_RRWEB_CONTEXT_MAX_PAYLOAD_BYTES', 33554432),
    ],
];
