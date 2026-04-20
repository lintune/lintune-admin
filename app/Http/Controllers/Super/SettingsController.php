<?php

namespace App\Http\Controllers\Super;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function show()
    {
        return view('super.settings', [
            'mailcow_url'            => Setting::get('mailcow.url', config('mailcow.url')),
            'mailcow_api_key'        => Setting::get('mailcow.api_key') ? '••••••••' : '',
            'mailcow_mailboxes'      => Setting::get('mailcow.default_mailboxes', 10),
            'mailcow_aliases'        => Setting::get('mailcow.default_aliases', 10),
            'mailcow_maxquota'       => Setting::get('mailcow.default_maxquota', 10240),
            'mailcow_quota'          => Setting::get('mailcow.default_quota', 102400),
            'nextcloud_url'          => Setting::get('nextcloud.url', ''),
            'nextcloud_user'         => Setting::get('nextcloud.service_user', ''),
            'nextcloud_password'     => Setting::get('nextcloud.service_password') ? '••••••••' : '',
        ]);
    }

    public function update(Request $request)
    {
        $request->validate([
            'mailcow_url'            => 'required|url',
            'mailcow_api_key'        => 'nullable|string',
            'mailcow_mailboxes'      => 'required|integer|min:1',
            'mailcow_aliases'        => 'required|integer|min:0',
            'mailcow_maxquota'       => 'required|integer|min:1',
            'mailcow_quota'          => 'required|integer|min:1',
            'nextcloud_url'          => 'nullable|url',
            'nextcloud_user'         => 'nullable|string|max:255',
            'nextcloud_password'     => 'nullable|string',
        ]);

        Setting::set('mailcow.url', rtrim($request->mailcow_url, '/'));
        if ($request->filled('mailcow_api_key') && $request->mailcow_api_key !== '••••••••') {
            Setting::set('mailcow.api_key', $request->mailcow_api_key, true);
        }
        Setting::set('mailcow.default_mailboxes', $request->mailcow_mailboxes);
        Setting::set('mailcow.default_aliases',   $request->mailcow_aliases);
        Setting::set('mailcow.default_maxquota',  $request->mailcow_maxquota);
        Setting::set('mailcow.default_quota',     $request->mailcow_quota);

        if ($request->filled('nextcloud_url')) {
            Setting::set('nextcloud.url', rtrim($request->nextcloud_url, '/'));
        }
        if ($request->filled('nextcloud_user')) {
            Setting::set('nextcloud.service_user', trim($request->nextcloud_user));
        }
        if ($request->filled('nextcloud_password') && $request->nextcloud_password !== '••••••••') {
            Setting::set('nextcloud.service_password', $request->nextcloud_password, true);
        }

        return back()->with('success', 'Settings saved.');
    }
}
