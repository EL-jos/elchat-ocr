<?php

return [
    'http' => [
        'user_agent' => env('PROSPECTING_HTTP_USER_AGENT', 'ELChat-SalesHunter/1.0 (+https://elchat.io)'),
        'timeout' => (int) env('PROSPECTING_HTTP_TIMEOUT', 20),
        'retries' => (int) env('PROSPECTING_HTTP_RETRIES', 2),
    ],
    'openstreetmap' => [
        'endpoints' => array_values(array_filter(array_map('trim', explode(',', env(
            'PROSPECTING_OVERPASS_ENDPOINTS',
            'https://overpass-api.de/api/interpreter,https://overpass.private.coffee/api/interpreter,https://maps.mail.ru/osm/tools/overpass/api/interpreter'
        ))))),
        'cache_ttl' => (int) env('PROSPECTING_OVERPASS_CACHE_TTL', 21600),
        'min_interval_ms' => (int) env('PROSPECTING_OVERPASS_MIN_INTERVAL_MS', 1100),
        'timeout' => (int) env('PROSPECTING_OVERPASS_TIMEOUT', 30),
        'query_timeout' => (int) env('PROSPECTING_OVERPASS_QUERY_TIMEOUT', 25),
        'connect_timeout' => (int) env('PROSPECTING_OVERPASS_CONNECT_TIMEOUT', 5),
        'retries' => (int) env('PROSPECTING_OVERPASS_RETRIES', 3),
        'max_duration_seconds' => (int) env('PROSPECTING_OVERPASS_MAX_DURATION_SECONDS', 180),
        'cooldown_seconds' => (int) env('PROSPECTING_OVERPASS_COOLDOWN_SECONDS', 300),
    ],
    'web_discovery' => [
        'max_pages' => (int) env('PROSPECTING_WEB_MAX_PAGES', 10),
    ],
    'geocoding' => [
        'endpoint' => env('PROSPECTING_GEOCODING_ENDPOINT', 'https://nominatim.openstreetmap.org/search'),
        'timeout' => (int) env('PROSPECTING_GEOCODING_TIMEOUT', 10),
        'connect_timeout' => (int) env('PROSPECTING_GEOCODING_CONNECT_TIMEOUT', 4),
        'cache_ttl' => (int) env('PROSPECTING_GEOCODING_CACHE_TTL', 86400),
    ],
    'foursquare' => [
        'api_key' => env('FOURSQUARE_API_KEY'),
        'endpoint' => env('PROSPECTING_FOURSQUARE_ENDPOINT', 'https://places-api.foursquare.com/places/search'),
        'api_version' => env('PROSPECTING_FOURSQUARE_API_VERSION', '2025-06-17'),
        'timeout' => (int) env('PROSPECTING_FOURSQUARE_TIMEOUT', 12),
        'connect_timeout' => (int) env('PROSPECTING_FOURSQUARE_CONNECT_TIMEOUT', 4),
        'retries' => (int) env('PROSPECTING_FOURSQUARE_RETRIES', 2),
        'radius' => (int) env('PROSPECTING_FOURSQUARE_RADIUS', 30000),
    ],
    'here' => [
        'api_key' => env('HERE_API_KEY'),
        'endpoint' => env('PROSPECTING_HERE_ENDPOINT', 'https://discover.search.hereapi.com/v1/discover'),
        'timeout' => (int) env('PROSPECTING_HERE_TIMEOUT', 12),
        'connect_timeout' => (int) env('PROSPECTING_HERE_CONNECT_TIMEOUT', 4),
        'retries' => (int) env('PROSPECTING_HERE_RETRIES', 2),
        'radius' => (int) env('PROSPECTING_HERE_RADIUS', 30000),
    ],
    'tomtom' => [
        'api_key' => env('TOMTOM_API_KEY'),
        'endpoint' => env('PROSPECTING_TOMTOM_ENDPOINT', 'https://api.tomtom.com/search/2/poiSearch'),
        'timeout' => (int) env('PROSPECTING_TOMTOM_TIMEOUT', 12),
        'connect_timeout' => (int) env('PROSPECTING_TOMTOM_CONNECT_TIMEOUT', 4),
        'retries' => (int) env('PROSPECTING_TOMTOM_RETRIES', 2),
        'radius' => (int) env('PROSPECTING_TOMTOM_RADIUS', 30000),
    ],
    'web_search' => [
        'enabled' => (bool) env('PROSPECTING_WEB_SEARCH_ENABLED', true),
        'model' => env('PROSPECTING_WEB_SEARCH_MODEL', env('MCP_LLM_MODEL', 'deepseek/deepseek-chat-v3.1')),
        'engine' => env('PROSPECTING_WEB_SEARCH_ENGINE', 'auto'),
        'max_results' => (int) env('PROSPECTING_WEB_SEARCH_MAX_RESULTS', 5),
        'max_total_results' => (int) env('PROSPECTING_WEB_SEARCH_MAX_TOTAL_RESULTS', 15),
        'completion_enabled' => (bool) env('PROSPECTING_WEB_COMPLETION_ENABLED', true),
        'completion_max_results' => (int) env('PROSPECTING_WEB_COMPLETION_MAX_RESULTS', 2),
        'completion_max_total_results' => (int) env('PROSPECTING_WEB_COMPLETION_MAX_TOTAL_RESULTS', 5),
    ],
];
