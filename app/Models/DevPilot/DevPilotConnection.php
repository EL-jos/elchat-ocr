<?php

namespace App\Models\DevPilot;

use App\Models\Site;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class DevPilotConnection extends Model
{
    use HasUuids;

    protected $table = 'devpilot_connections';

    protected $fillable = [
        'site_id', 'name', 'protocol', 'site_url', 'host', 'port', 'username', 'database_name', 'auth_method',
        'credentials_encrypted', 'host_key_sha256', 'root_path', 'private_network_authorized',
        'can_read', 'can_write', 'can_delete', 'can_run_commands', 'probe_sudo', 'command_execution_mode', 'can_manage_databases', 'allowed_commands',
        'capabilities', 'last_test_result', 'last_tested_at', 'status',
    ];

    protected $hidden = ['credentials_encrypted'];

    protected $casts = [
        'private_network_authorized' => 'boolean', 'can_read' => 'boolean', 'can_write' => 'boolean',
        'can_delete' => 'boolean', 'can_run_commands' => 'boolean', 'probe_sudo' => 'boolean', 'can_manage_databases' => 'boolean', 'allowed_commands' => 'array',
        'capabilities' => 'array', 'last_test_result' => 'array', 'last_tested_at' => 'datetime',
    ];

    public function site() { return $this->belongsTo(Site::class); }
    public function projects() { return $this->hasMany(DevPilotProject::class, 'connection_id'); }
}
