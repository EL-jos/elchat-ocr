<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

class AuditLogger
{
    public static function event(string $action, string $entity, string $entityId = null, array $before = [], array $after = [])
    {
        AuditLog::create([
            'user_id'     => Auth::id(),
            'action'      => $action,
            'entity'      => $entity,
            'entity_id'   => $entityId,
            'before_data' => $before,
            'after_data'  => $after,
            'ip_address'  => Request::ip(),
            'user_agent'  => Request::header('User-Agent'),
            'route'       => Request::path(),
        ]);
    }
}
