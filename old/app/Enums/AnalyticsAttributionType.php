<?php

namespace App\Enums;

enum AnalyticsAttributionType: string
{
    case DIRECT = 'direct';
    case ASSISTED = 'assisted';
    case UNKNOWN = 'unknown';
}
