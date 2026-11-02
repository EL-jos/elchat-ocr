<?php

namespace App\Models\DevPilot;

use App\Models\Mcp\McpAgent;
use App\Models\Site;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class DevPilotProject extends Model
{
    use HasUuids;

    protected $table = 'devpilot_projects';

    protected $fillable = ['site_id', 'connection_id', 'wordpress_profile_id', 'name', 'environment', 'root_path', 'database_connection_id', 'settings'];

    protected $casts = ['settings' => 'array'];

    public function site() { return $this->belongsTo(Site::class); }
    public function connection() { return $this->belongsTo(DevPilotConnection::class, 'connection_id'); }
    public function wordpressProfile() { return $this->belongsTo(DevPilotWordPressSiteProfile::class, 'wordpress_profile_id'); }
    public function databaseConnection() { return $this->belongsTo(DevPilotConnection::class, 'database_connection_id'); }
    public function tasks() { return $this->hasMany(DevPilotTask::class, 'project_id'); }
}
