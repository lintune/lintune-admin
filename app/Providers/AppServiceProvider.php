<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
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
