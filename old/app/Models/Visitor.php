<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Visitor extends BaseModel
{
    use HasUuids;

    public function site()
    {
        return $this->belongsTo(Site::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function conversations()
    {
        return $this->hasMany(Conversation::class);
    }

    public function sessions()
    {
        return $this->hasMany(VisitorSession::class);
    }

    public function opportunities()
    {
        return $this->hasMany(VisitorOpportunity::class);
    }
}
