<?php

namespace App\Http\Controllers\Super;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\AuditLogger;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function show()
    {
        return view('super.settings', [
            'keycloak_url'       => Setting::get('keycloak.url', config('keycloak.base_url', '')),
            'mailcow_url'        => Setting::get('mailcow.url', config('mailcow.url')),
            'mailcow_api_key'    => Setting::get('mailcow.api_key') ? '••••••••' : '',
            'mailcow_mailboxes'  => Setting::get('mailcow.default_mailboxes', 10),
            'mailcow_aliases'    => Setting::get('mailcow.default_aliases', 10),
            'mailcow_maxquota'   => round(Setting::get('mailcow.default_maxquota', 10240) / 1024, 2),
            'mailcow_quota'      => round(Setting::get('mailcow.default_quota', 102400) / 1024, 2),
            'nextcloud_url'      => Setting::get('nextcloud.url', ''),
            'nextcloud_user'     => Setting::get('nextcloud.service_user', ''),
            'nextcloud_password' => Setting::get('nextcloud.service_password') ? '••••••••' : '',
            'nextcloud_quota'    => round(Setting::get('nextcloud.default_quota', 10) * 1, 2),
        ]);
    }

    public function update(Request $request)
    {
        $request->validate([
            'keycloak_url'       => 'required|url',
            'mailcow_url'        => 'required|url',
            'mailcow_api_key'    => 'nullable|string',
            'mailcow_mailboxes'  => 'required|integer|min:1',
            'mailcow_aliases'    => 'required|integer|min:0',
            'mailcow_maxquota'   => 'required|numeric|min:0.1',
            'mailcow_quota'      => 'required|numeric|min:0.1',
            'nextcloud_url'      => 'nullable|url',
            'nextcloud_user'     => 'nullable|string|max:255',
            'nextcloud_password' => 'nullable|string',
            'nextcloud_quota'    => 'required|numeric|min:0.1',
        ]);

        Setting::set('keycloak.url', rtrim($request->keycloak_url, '/'));
        Setting::set('mailcow.url', rtrim($request->mailcow_url, '/'));
        if ($request->filled('mailcow_api_key') && $request->mailcow_api_key !== '••••••••') {
            Setting::set('mailcow.api_key', $request->mailcow_api_key, true);
        }
        // Store Mailcow quotas in MB internally
        Setting::set('mailcow.default_mailboxes', $request->mailcow_mailboxes);
        Setting::set('mailcow.default_aliases',   $request->mailcow_aliases);
        Setting::set('mailcow.default_maxquota',  (int) round($request->mailcow_maxquota * 1024));
        Setting::set('mailcow.default_quota',     (int) round($request->mailcow_quota * 1024));

        if ($request->filled('nextcloud_url')) {
            Setting::set('nextcloud.url', rtrim($request->nextcloud_url, '/'));
        }
        if ($request->filled('nextcloud_user')) {
            Setting::set('nextcloud.service_user', trim($request->nextcloud_user));
        }
        if ($request->filled('nextcloud_password') && $request->nextcloud_password !== '••••••••') {
            Setting::set('nextcloud.service_password', $request->nextcloud_password, true);
        }
        // Store Nextcloud quota in GB internally
        Setting::set('nextcloud.default_quota', $request->nextcloud_quota);

        AuditLogger::log('settings.updated');
        return back()->with('success', 'Settings saved.');
    }
}
