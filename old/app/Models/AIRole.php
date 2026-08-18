<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AIRole extends BaseModel
{
    protected $table = 'ai_roles';

    public function settings()
    {
        return $this->hasMany(WidgetSetting::class);
    }

    // Scope pour récupérer le rôle par défaut
    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }
}
