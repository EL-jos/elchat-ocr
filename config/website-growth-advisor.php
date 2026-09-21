<?php

return [
    'queue' => env('WEBSITE_GROWTH_ADVISOR_QUEUE', 'website-growth-advisor'),
    'retention_days' => (int) env('WEBSITE_GROWTH_ADVISOR_RETENTION_DAYS', 90),
];
