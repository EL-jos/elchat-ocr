<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WidgetSetting extends BaseModel
{
    protected $table = 'widget_settings';

    protected $casts = [
        'widget_enabled' => 'boolean',
        'ai_enabled' => 'boolean',
        'auto_open_enabled' => 'boolean',
        'auto_open_delay' => 'integer',
        'require_authentication' => 'boolean',
        'ai_engagement_enabled' => 'boolean',
        'ai_engagement_max_per_session' => 'integer',
        'ai_engagement_max_per_visitor' => 'integer',
        'ai_engagement_visitor_window_seconds' => 'integer',
        'ai_engagement_cooldown_seconds' => 'integer',
        'ai_engagement_close_cooldown_seconds' => 'integer',
        'ai_engagement_refusal_cooldown_seconds' => 'integer',
        'ai_engagement_min_session_seconds' => 'integer',
        'ai_engagement_min_pages' => 'integer',
        'ai_engagement_min_score' => 'integer',
        'ai_engagement_strategies' => 'array',
        'content_translations' => 'array',
    ];

    public function site(){
        return $this->belongsTo(Site::class);
    }

    public function aiRole(){
        return $this->belongsTo(AIRole::class);
    }
}
