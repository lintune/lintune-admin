<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class SetupComplete
{
    public function handle(Request $request, Closure $next)
    {
        // config('setup.complete') reads env() which is frozen at PHP-FPM worker
        // spawn time. Read the file directly so a SETUP_COMPLETE written during
        // this container run is visible without restarting the container.
        $complete = $this->isSetupComplete();

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

    private function isSetupComplete(): bool
    {
        if (config('setup.complete')) {
            return true;
        }

        $env = @file_get_contents(base_path('.env'));
        return $env !== false && (bool) preg_match('/^SETUP_COMPLETE=true$/m', $env);
    }
}
