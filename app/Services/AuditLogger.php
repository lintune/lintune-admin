<?php

namespace App\Services;

use App\Models\AuditLog;

class AuditLogger
{
    public static function log(string $action, ?string $realm = null, ?string $detail = null): void
    {
        AuditLog::create([
            'app'      => 'admin',
            'username' => session('super_username', 'unknown'),
            'action'   => $action,
            'realm'    => $realm,
            'detail'   => $detail,
        ]);
    }
}
