<?php

namespace App\Http\Controllers\Super;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\AuditLogger;
use App\Services\KumaService;
use App\Services\VaultwardenService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class VaultwardenController extends Controller
{
    public function show()
    {
        $vwUrl     = config('vaultwarden.url');
        $ssoWired  = Setting::get('vaultwarden.sso_wired') === '1';
        $kcClient  = Setting::get('vaultwarden.sso_client_id', 'vaultwarden');
        $authority = Setting::get('vaultwarden.sso_authority', '');

        $reachable = false;
        try {
            $internalUrl = Setting::get('vaultwarden.internal_url', config('vaultwarden.internal_url', 'http://vaultwarden:80'));
            $reachable   = Http::timeout(5)->get(rtrim($internalUrl, '/') . '/')->successful();
        } catch (\Throwable) {}

        return view('super.vaultwarden', compact('vwUrl', 'ssoWired', 'kcClient', 'authority', 'reachable'));
    }

    public function wireSso(Request $request)
    {
        $request->validate(['admin_token' => 'required|string']);

        Setting::set('vaultwarden.admin_token', $request->admin_token, true);
        Setting::set('vaultwarden.internal_url', 'http://vaultwarden:80');

        try {
            $kcBase      = rtrim(config('keycloak.base_url'), '/');
            $kcToken     = $this->kcAdminToken();
            $brokerRealm = config('keycloak.broker_realm');
            $vwUrl       = config('vaultwarden.url');
            $secret      = bin2hex(random_bytes(20));

            // Remove any existing vaultwarden client in the broker realm
            $clients  = Http::withToken($kcToken)->get("{$kcBase}/admin/realms/{$brokerRealm}/clients")->json();
            $existing = collect((array) $clients)->firstWhere('clientId', 'vaultwarden');
            if ($existing) {
                Http::withToken($kcToken)->delete("{$kcBase}/admin/realms/{$brokerRealm}/clients/{$existing['id']}");
            }

            // Create confidential OIDC client in the broker realm
            $res = Http::withToken($kcToken)->post("{$kcBase}/admin/realms/{$brokerRealm}/clients", [
                'clientId'                  => 'vaultwarden',
                'enabled'                   => true,
                'publicClient'              => false,
                'standardFlowEnabled'       => true,
                'directAccessGrantsEnabled' => false,
                'secret'                    => $secret,
                'redirectUris'              => ["{$vwUrl}/identity/connect/oidc-signin"],
                'webOrigins'                => [$vwUrl],
            ]);

            if ($res->failed()) {
                return back()->withErrors(['admin_token' => 'Keycloak client creation failed: ' . $res->body()]);
            }

            // Push SSO config to Vaultwarden via admin API (no restart needed — these fields are runtime-editable)
            (new VaultwardenService())->pushConfig([
                'sso_authority'     => "{$kcBase}/realms/{$brokerRealm}",
                'sso_client_id'     => 'vaultwarden',
                'sso_client_secret' => $secret,
            ]);

            Setting::set('vaultwarden.sso_wired', '1');
            Setting::set('vaultwarden.sso_client_id', 'vaultwarden');
            Setting::set('vaultwarden.sso_authority', "{$kcBase}/realms/{$brokerRealm}");

            try {
                (new KumaService())->addMonitor('Vaultwarden', $vwUrl);
            } catch (\Throwable) {}

            AuditLogger::log('vaultwarden.sso_wired');
            return back()->with('success', 'Vaultwarden SSO wired to Keycloak broker realm.');

        } catch (\Throwable $e) {
            return back()->withErrors(['admin_token' => 'Failed: ' . $e->getMessage()]);
        }
    }

    private function kcAdminToken(): string
    {
        $base = rtrim(config('keycloak.base_url'), '/');
        $res  = Http::timeout(10)->asForm()->post("{$base}/realms/master/protocol/openid-connect/token", [
            'grant_type' => 'password',
            'client_id'  => 'admin-cli',
            'username'   => config('keycloak.admin_user'),
            'password'   => decrypt(base64_decode(config('keycloak.admin_password'))),
        ]);

        if ($res->failed() || empty($res->json()['access_token'])) {
            throw new \RuntimeException('Cannot obtain Keycloak admin token: HTTP ' . $res->status());
        }

        return $res->json('access_token');
    }
}
