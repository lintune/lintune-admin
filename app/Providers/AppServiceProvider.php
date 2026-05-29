<?php

namespace App\Providers;

use App\Auth\SuperSessionGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Auth::extend('super_session', function ($app, $name, array $config) {
            return new SuperSessionGuard();
        });

        // Allow settings table to override config values set in .env so they can
        // be managed from the web UI without touching container environment.
        try {
            $url = \App\Models\Setting::get('keycloak.url');
            if ($url) {
                config(['keycloak.base_url' => $url]);
            }
        } catch (\Throwable) {
            // DB not yet migrated (first boot) — fall back to config.
        }
    }
}
