<?php

namespace App\Models\DevPilot;

use App\Models\Site;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class DevPilotWordPressSiteProfile extends Model
{
    use HasUuids;

    protected $table = 'devpilot_wordpress_site_profiles';

    protected $fillable = [
        'site_id', 'name', 'canonical_site_url', 'rest_connection_id', 'file_connection_id',
        'ssh_connection_id', 'rest_root_form',
    ];

    protected static function booted(): void
    {
        static::saving(static function (self $profile): void {
            if (! $profile->rest_connection_id && ! $profile->file_connection_id && ! $profile->ssh_connection_id) {
                throw new RuntimeException('Sélectionnez au moins un canal WordPress : REST, fichiers ou SSH.');
            }
        });
    }

    public function site() { return $this->belongsTo(Site::class); }
    public function restConnection() { return $this->belongsTo(DevPilotConnection::class, 'rest_connection_id'); }
    public function fileConnection() { return $this->belongsTo(DevPilotConnection::class, 'file_connection_id'); }
    public function sshConnection() { return $this->belongsTo(DevPilotConnection::class, 'ssh_connection_id'); }
    public function projects() { return $this->hasMany(DevPilotProject::class, 'wordpress_profile_id'); }
}
