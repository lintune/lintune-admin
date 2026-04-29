<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;

class WizardComplete
{
    public function handle(Request $request, Closure $next)
    {
        if (!Setting::get('wizard.complete')) {
            return redirect()->route('super.wizard');
        }
        return $next($request);
    }
}
