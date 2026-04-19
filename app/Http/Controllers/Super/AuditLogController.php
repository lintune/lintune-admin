<?php

namespace App\Http\Controllers\Super;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request)
    {
        $query = AuditLog::query()->latest('created_at');

        if ($request->filled('username')) {
            $query->where('username', 'like', '%' . $request->username . '%');
        }

        if ($request->filled('action')) {
            $query->where('action', 'like', '%' . $request->action . '%');
        }

        if ($request->filled('date')) {
            $query->whereDate('created_at', $request->date);
        }

        if (in_array($request->app, ['admin', 'dash'])) {
            $query->where('app', $request->app);
        }

        $logs = $query->paginate(50)->withQueryString();

        return view('super.audit-logs', compact('logs'));
    }
}
