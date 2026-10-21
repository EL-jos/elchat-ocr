<?php

return [
    'content_extraction' => [
        // Bounds scoring work on pages with very large DOM trees.
        'max_div_candidates' => (int) env('CRAWL_CONTENT_MAX_DIV_CANDIDATES', 300),

        // Confidence thresholds are configurable and should be tuned against
        // the reviewed crawl corpus before changing production defaults.
        'high_confidence_chars' => (int) env('CRAWL_CONTENT_HIGH_CONFIDENCE_CHARS', 500),
        'medium_confidence_chars' => (int) env('CRAWL_CONTENT_MEDIUM_CONFIDENCE_CHARS', 120),
        'minimum_usable_chars' => (int) env('CRAWL_CONTENT_MINIMUM_USABLE_CHARS', 120),
    ],
];
