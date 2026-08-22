<?php

return [
    'enabled' => env('PROACTIVE_ENGAGEMENT_ENABLED', true),
    'queue' => env('PROACTIVE_QUEUE', 'proactive'),
    'scan_batch_size' => (int) env('PROACTIVE_SCAN_BATCH_SIZE', 200),
    'max_site_daily_messages' => (int) env('PROACTIVE_MAX_SITE_DAILY', 2000),
    'max_tenant_daily_messages' => (int) env('PROACTIVE_MAX_TENANT_DAILY', 10000),
    'max_visitor_daily_messages' => (int) env('PROACTIVE_MAX_VISITOR_DAILY', 5),
    'stale_lock_minutes' => (int) env('PROACTIVE_STALE_LOCK_MINUTES', 15),
    'decision_model' => env('PROACTIVE_DECISION_MODEL'),
    'outcome_window_hours' => (int) env('PROACTIVE_OUTCOME_WINDOW_HOURS', 168),
    'channels' => [
        'website' => ['enabled' => true, 'window_hours' => null],
        'facebook' => ['enabled' => true, 'window_hours' => (int) env('PROACTIVE_FACEBOOK_WINDOW_HOURS', 24)],
        'instagram' => ['enabled' => true, 'window_hours' => (int) env('PROACTIVE_INSTAGRAM_WINDOW_HOURS', 24)],
        'telegram' => ['enabled' => true, 'window_hours' => null],
        'youtube' => ['enabled' => true, 'window_hours' => null],
        'email' => ['enabled' => true, 'window_hours' => null],
        // Aucun job sortant WhatsApp n'existe dans ELChat aujourd'hui : fail closed.
        'whatsapp' => ['enabled' => false, 'window_hours' => 24],
    ],
    'conversion_events' => [
        'cta_conversion', 'purchase_completed', 'lead_created', 'opportunity_won', 'meeting_booked',
    ],
    'human_handoff_events' => ['human_handoff'],
    'refusal_events' => ['proactive_refused', 'proactive_unsubscribed'],
];
