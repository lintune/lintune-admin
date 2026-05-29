<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;

class WizardComplete
{
    public function handle(Request $request, Closure $next)
    {
        // Exclude wizard panel pages to prevent a redirect loop.
        if ($request->routeIs('filament.super.pages.wizard*')) {
            return $next($request);
        }

        if (!Setting::get('wizard.complete')) {
            return redirect()->route('filament.super.pages.wizard');
        }
        return $next($request);
    }
}
