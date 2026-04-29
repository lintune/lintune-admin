<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class SetupComplete
{
    public function handle(Request $request, Closure $next)
    {
        $complete = config('setup.complete');

        // Setup done — block access to /setup
        if ($complete && $request->routeIs('super.setup*')) {
            abort(404);
        }

        // Setup not done — redirect everything except /setup (repair mode) to /install
        if (!$complete && !$request->routeIs('super.setup*')) {
            return redirect()->route('install.welcome');
        }

        return $next($request);
    }
}
