<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class AuditLog extends Model
{
    use SoftDeletes;

    protected $table = 'audit_logs';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id', 'user_id', 'action', 'entity', 'entity_id',
        'before_data', 'after_data', 'ip_address', 'user_agent', 'route'
    ];
    protected $casts = [
        'before_data' => 'array',
        'after_data' => 'array',
    ];


    protected static function booted()
    {
        static::creating(function ($model) {

            $model->id = (string) Str::uuid();

        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function rollback()
    {
        $modelClass = "App\\Models\\{$this->entity}";
        $model = $modelClass::find($this->entity_id);

        if (!$model) {
            return false;
        }

        $model->update($this->before_data);
        return true;
    }

}
