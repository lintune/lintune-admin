<?php

namespace App\Http\Controllers\Super;

use App\Http\Controllers\Controller;
use App\Models\DomainRealmMap;
use Illuminate\Http\Request;

class SuperRealmController extends Controller
{
    private function token(): string
    {
        return session('super_access_token');
    }

    private function baseUrl(): string
    {
        return config('keycloak.base_url');
    }

    public function index()
    {
        $response = \Http::withToken($this->token())
            ->get("{$this->baseUrl()}/admin/realms");

        $realms = $response->successful() ? $response->json() : [];

        $domainMaps = DomainRealmMap::whereIn('realm', array_column($realms, 'realm'))
            ->pluck('mailcow_enabled', 'realm');

        return view('super.realms', compact('realms', 'domainMaps'));
    }

    public function create()
    {
        return view('super.create-realm');
    }

    public function store(Request $request)
    {
        $request->validate([
            'realm'          => ['required', 'regex:/^[a-zA-Z0-9_.\-]+$/'],
            'admin_email'    => 'required|email',
            'admin_password' => 'required|min:8',
            'admin_firstname'=> 'required',
            'admin_lastname' => 'required',
        ]);

        $realm   = $request->realm;
        $base    = $this->baseUrl();
        $token   = $this->token();
        $appUrl  = rtrim(config('keycloak.frontend_url'), '/');

        // 1. Create realm
        $realmRes = \Http::withToken($token)->post("{$base}/admin/realms", [
            'realm'   => $realm,
            'enabled' => true,
        ]);

        if ($realmRes->failed()) {
            return back()->withErrors(['realm' => 'Failed to create realm: ' . $realmRes->body()]);
        }

        // 2. Create client
        $clientRes = \Http::withToken($token)->post("{$base}/admin/realms/{$realm}/clients", [
            'clientId'                     => config('keycloak.client_id'),
            'enabled'                      => true,
            'publicClient'                 => true,
            'standardFlowEnabled'          => true,
            'directAccessGrantsEnabled'    => false,
            'redirectUris'                 => ["{$appUrl}/auth/callback"],
            'webOrigins'                   => [$appUrl],
            'attributes'                   => [
                'pkce.code.challenge.method'  => 'S256',
                'post.logout.redirect.uris'   => "{$appUrl}/login",
            ],
        ]);

        if ($clientRes->failed()) {
            return back()->withErrors(['realm' => 'Realm created but client setup failed: ' . $clientRes->body()]);
        }

        // 3. Create first admin user
        $userRes = \Http::withToken($token)->post("{$base}/admin/realms/{$realm}/users", [
            'username'      => $request->admin_email,
            'email'         => $request->admin_email,
            'firstName'     => $request->admin_firstname,
            'lastName'      => $request->admin_lastname,
            'enabled'       => true,
            'emailVerified' => true,
            'credentials'   => [[
                'type'      => 'password',
                'value'     => $request->admin_password,
                'temporary' => false,
            ]],
        ]);

        if ($userRes->failed()) {
            return back()->withErrors(['realm' => 'Realm created but user creation failed: ' . $userRes->body()]);
        }

        // 4. Assign realm-admin role to the user
        $userId = basename($userRes->header('Location'));

        $rolesRes = \Http::withToken($token)
            ->get("{$base}/admin/realms/{$realm}/clients");

        $clients    = $rolesRes->json();
        $mgmtClient = collect($clients)->firstWhere('clientId', 'realm-management');

        if ($mgmtClient) {
            $mgmtId    = $mgmtClient['id'];
            $rolesData = \Http::withToken($token)
                ->get("{$base}/admin/realms/{$realm}/clients/{$mgmtId}/roles")
                ->json();

            $adminRole = collect($rolesData)->firstWhere('name', 'realm-admin');

            if ($adminRole) {
                \Http::withToken($token)->post(
                    "{$base}/admin/realms/{$realm}/users/{$userId}/role-mappings/clients/{$mgmtId}",
                    [$adminRole]
                );
            }
        }

        // 5. Insert domain mapping
        DomainRealmMap::updateOrCreate(
            ['domain' => $realm],
            ['realm'  => $realm, 'mailcow_enabled' => false]
        );

        // 6. Create Mailcow domain if requested
        $mailcowEnabled = false;
        if ($request->boolean('enable_mailcow') && config('mailcow.url') && config('mailcow.api_key')) {
            $mailcowRes = \Http::withHeaders([
                'X-API-Key' => config('mailcow.api_key'),
                'Accept'    => 'application/json',
            ])->post(rtrim(config('mailcow.url'), '/') . '/api/v1/add/domain', [
                'domain'       => $realm,
                'active'       => '1',
                'restart_sogo' => '1',
            ]);

            if ($mailcowRes->failed() || ($mailcowRes->json()[0]['type'] ?? '') === 'error') {
                $detail = $mailcowRes->json()[0]['msg'] ?? $mailcowRes->body();
                return redirect()->route('super.realms')
                    ->with('success', "Realm '{$realm}' created successfully.")
                    ->with('warning', "Mailcow domain creation failed: {$detail}");
            }

            $mailcowEnabled = true;
        }

        DomainRealmMap::where('domain', $realm)->update(['mailcow_enabled' => $mailcowEnabled]);

        return redirect()->route('super.realms')->with('success', "Realm '{$realm}' created successfully.");
    }

    public function toggle(string $realm)
    {
        $base  = $this->baseUrl();
        $token = $this->token();

        $current = \Http::withToken($token)->get("{$base}/admin/realms/{$realm}")->json();
        $enabled = !($current['enabled'] ?? false);

        $res = \Http::withToken($token)->put("{$base}/admin/realms/{$realm}", ['enabled' => $enabled]);

        if ($res->failed()) {
            return back()->withErrors(['realm' => 'Failed to update realm status.']);
        }

        $status = $enabled ? 'enabled' : 'disabled';
        return redirect()->route('super.realms')->with('success', "Realm '{$realm}' {$status}.");
    }

    public function checkMailcow(string $realm)
    {
        if (!config('mailcow.url') || !config('mailcow.api_key')) {
            return response()->json(['configured' => false]);
        }

        $apiBase = rtrim(config('mailcow.url'), '/') . '/api/v1';
        $headers = ['X-API-Key' => config('mailcow.api_key'), 'Accept' => 'application/json'];

        $res = \Http::withHeaders($headers)->get("{$apiBase}/get/domain/{$realm}");

        // Mailcow returns the domain object if found, or an empty array / 404 if not
        $exists = $res->successful() && !empty($res->json()) && !isset($res->json()['type']);

        return response()->json(['exists' => $exists]);
    }

    public function toggleMailcow(Request $request, string $realm)
    {
        $map = DomainRealmMap::where('realm', $realm)->firstOrFail();

        if (!config('mailcow.url') || !config('mailcow.api_key')) {
            return back()->withErrors(['realm' => 'Mailcow is not configured.']);
        }

        $apiBase = rtrim(config('mailcow.url'), '/') . '/api/v1';
        $headers = ['X-API-Key' => config('mailcow.api_key'), 'Accept' => 'application/json'];

        if ($map->mailcow_enabled) {
            $res = \Http::withHeaders($headers)->delete("{$apiBase}/delete/domain", [$realm]);
            if ($res->failed() || ($res->json()[0]['type'] ?? '') === 'error') {
                $detail = $res->json()[0]['msg'] ?? $res->body();
                return back()->withErrors(['realm' => "Failed to remove Mailcow domain: {$detail}"]);
            }
            $map->update(['mailcow_enabled' => false]);
            return redirect()->route('super.realms')->with('success', "Mailcow domain '{$realm}' removed.");
        }

        // Link only — domain already exists in Mailcow, just update the DB
        if ($request->boolean('link_only')) {
            $map->update(['mailcow_enabled' => true]);
            return redirect()->route('super.realms')->with('success', "Mailcow domain '{$realm}' linked.");
        }

        // Create the domain in Mailcow
        $res = \Http::withHeaders($headers)->post("{$apiBase}/add/domain", [
            'domain'       => $realm,
            'active'       => '1',
            'restart_sogo' => '1',
        ]);

        if ($res->failed() || ($res->json()[0]['type'] ?? '') === 'error') {
            $detail = $res->json()[0]['msg'] ?? $res->body();
            return back()->withErrors(['realm' => "Failed to create Mailcow domain: {$detail}"]);
        }

        $map->update(['mailcow_enabled' => true]);
        return redirect()->route('super.realms')->with('success', "Mailcow domain '{$realm}' created.");
    }

    public function destroy(string $realm)
    {
        $res = \Http::withToken($this->token())->delete("{$this->baseUrl()}/admin/realms/{$realm}");

        if ($res->failed()) {
            return back()->withErrors(['realm' => 'Failed to delete realm.']);
        }

        DomainRealmMap::where('realm', $realm)->delete();

        return redirect()->route('super.realms')->with('success', "Realm '{$realm}' deleted.");
    }
}
