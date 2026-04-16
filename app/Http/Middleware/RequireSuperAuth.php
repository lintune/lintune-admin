<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RequireSuperAuth
{
    public function handle(Request $request, Closure $next)
    {
        if (!session('super_access_token')) {
            return redirect()->route('super.login');
        }
        return $next($request);
    }
}
