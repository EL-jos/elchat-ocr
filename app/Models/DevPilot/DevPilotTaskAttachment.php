<?php

namespace App\Models\DevPilot;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class DevPilotTaskAttachment extends Model
{
    use HasUuids;

    protected $table = 'devpilot_task_attachments';

    protected $fillable = [
        'task_id', 'parent_attachment_id', 'kind', 'original_name', 'extension', 'mime_type',
        'size_bytes', 'sha256', 'storage_path', 'extracted_content', 'metadata',
    ];

    protected $casts = ['metadata' => 'array'];

    protected $hidden = ['storage_path', 'extracted_content'];

    public function task() { return $this->belongsTo(DevPilotTask::class, 'task_id'); }
    public function parent() { return $this->belongsTo(self::class, 'parent_attachment_id'); }
    public function embeddedMedia() { return $this->hasMany(self::class, 'parent_attachment_id'); }
}
