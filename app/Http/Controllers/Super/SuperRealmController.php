<?php

namespace App\Http\Controllers\Super;

use App\Http\Controllers\Controller;
use App\Models\DomainRealmMap;
use App\Services\AuditLogger;
use Illuminate\Http\Request;

class SuperRealmController extends Controller
{
    private function token(): string
    {
        return session('super_access_token');
    }

    private function adminToken(): string
    {
        $base = $this->baseUrl();
        $res  = \Http::asForm()->post("{$base}/realms/master/protocol/openid-connect/token", [
            'grant_type' => 'password',
            'client_id'  => 'admin-cli',
            'username'   => config('keycloak.admin_user'),
            'password'   => decrypt(base64_decode(config('keycloak.admin_password'))),
        ]);

        if ($res->failed() || empty($res->json()['access_token'])) {
            abort(500, 'Could not obtain admin token from Keycloak. Check KEYCLOAK_ADMIN_USER and KEYCLOAK_ADMIN_PASSWORD in .env.');
        }

        return $res->json()['access_token'];
    }

    private function baseUrl(): string
    {
        return rtrim(config('keycloak.base_url'), '/');
    }

    public function index()
    {
        $response = \Http::withToken($this->adminToken())
            ->get("{$this->baseUrl()}/admin/realms");

        $realms = $response->successful() ? $response->json() : [];

        $systemRealms = ['master', config('keycloak.broker_realm')];
        $realms = array_values(array_filter($realms, fn($r) => !in_array($r['realm'], $systemRealms)));

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
            'realm'            => ['required', 'regex:/^[a-zA-Z0-9_.\-]+$/'],
            'admin_local_part' => ['required', 'regex:/^[a-zA-Z0-9_.\-]+$/'],
            'admin_password'   => 'required|min:8',
            'admin_firstname'  => 'required|string|max:255',
            'admin_lastname'   => 'required|string|max:255',
        ]);

        $realm      = strtolower(trim($request->realm));
        $adminEmail = strtolower(trim($request->admin_local_part)) . '@' . $realm;
        $base       = $this->baseUrl();
        $token      = $this->adminToken();
        $dashUrl    = rtrim(config('keycloak.dash_url'), '/');

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
            'clientId'                  => config('keycloak.client_id'),
            'enabled'                   => true,
            'publicClient'              => true,
            'standardFlowEnabled'       => true,
            'directAccessGrantsEnabled' => false,
            'redirectUris'              => ["{$dashUrl}/auth/callback"],
            'webOrigins'                => [$dashUrl],
            'attributes'                => [
                'pkce.code.challenge.method' => 'S256',
                'post.logout.redirect.uris'  => "{$dashUrl}/login",
            ],
        ]);

        if ($clientRes->failed()) {
            return back()->withErrors(['realm' => 'Realm created but client setup failed: ' . $clientRes->body()]);
        }

        // 3. Create first admin user
        $userRes = \Http::withToken($token)->post("{$base}/admin/realms/{$realm}/users", [
            'username'      => $adminEmail,
            'email'         => $adminEmail,
            'firstName'     => trim($request->admin_firstname),
            'lastName'      => trim($request->admin_lastname),
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
        $userId  = basename($userRes->header('Location'));
        $clients = \Http::withToken($token)->get("{$base}/admin/realms/{$realm}/clients")->json();
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

        // 7. Set up broker-realm federation
        $brokerRealm = config('keycloak.broker_realm');
        if ($brokerRealm) {
            $this->setupBrokerFederation($base, $token, $realm, $brokerRealm);
        }

        AuditLogger::log('realm.created', $realm, "Mailcow: " . ($mailcowEnabled ? 'enabled' : 'disabled'));

        return redirect()->route('super.realms')->with('success', "Realm '{$realm}' created successfully.");
    }

    private function setupBrokerFederation(string $base, string $token, string $realm, string $brokerRealm): void
    {
        $clientRes = \Http::withToken($token)->post("{$base}/admin/realms/{$realm}/clients", [
            'clientId'                  => 'broker-realm-client',
            'enabled'                   => true,
            'publicClient'              => false,
            'standardFlowEnabled'       => true,
            'directAccessGrantsEnabled' => false,
            'redirectUris'              => ["{$base}/realms/{$brokerRealm}/broker/{$realm}/endpoint"],
        ]);

        if ($clientRes->failed()) return;

        $clientId  = basename($clientRes->header('Location'));
        $secretRes = \Http::withToken($token)->get("{$base}/admin/realms/{$realm}/clients/{$clientId}/client-secret");
        $secret    = $secretRes->json()['value'] ?? null;

        if (!$secret) return;

        $idpRes = \Http::withToken($token)->post("{$base}/admin/realms/{$brokerRealm}/identity-provider/instances", [
            'alias'                     => $realm,
            'displayName'               => $realm,
            'providerId'                => 'oidc',
            'enabled'                   => true,
            'trustEmail'                => true,
            'firstBrokerLoginFlowAlias' => 'first broker login',
            'config' => [
                'clientId'         => 'broker-realm-client',
                'clientSecret'     => $secret,
                'authorizationUrl' => "{$base}/realms/{$realm}/protocol/openid-connect/auth",
                'tokenUrl'         => "{$base}/realms/{$realm}/protocol/openid-connect/token",
                'jwksUrl'          => "{$base}/realms/{$realm}/protocol/openid-connect/certs",
                'logoutUrl'        => "{$base}/realms/{$realm}/protocol/openid-connect/logout",
                'userInfoUrl'      => "{$base}/realms/{$realm}/protocol/openid-connect/userinfo",
                'issuer'           => "{$base}/realms/{$realm}",
                'validateSignature' => 'true',
                'useJwksUrl'       => 'true',
                'pkceEnabled'      => 'false',
                'syncMode'         => 'IMPORT',
            ],
        ]);

        if ($idpRes->failed()) return;

        \Http::withToken($token)->post("{$base}/admin/realms/{$brokerRealm}/identity-provider/instances/{$realm}/mappers", [
            'name'                   => 'email',
            'identityProviderAlias'  => $realm,
            'identityProviderMapper' => 'oidc-user-attribute-idp-mapper',
            'config' => [
                'syncMode'       => 'INHERIT',
                'claim'          => 'email',
                'user.attribute' => 'email',
            ],
        ]);
    }

    public function toggle(string $realm)
    {
        $base    = $this->baseUrl();
        $token   = $this->adminToken();
        $current = \Http::withToken($token)->get("{$base}/admin/realms/{$realm}")->json();
        $enabled = !($current['enabled'] ?? false);

        $res = \Http::withToken($token)->put("{$base}/admin/realms/{$realm}", ['enabled' => $enabled]);

        if ($res->failed()) {
            return back()->withErrors(['realm' => 'Failed to update realm status.']);
        }

        $status = $enabled ? 'enabled' : 'disabled';
        AuditLogger::log("realm.{$status}", $realm);
        return redirect()->route('super.realms')->with('success', "Realm '{$realm}' {$status}.");
    }

    public function checkMailcow(string $realm)
    {
        if (!config('mailcow.url') || !config('mailcow.api_key')) {
            return response()->json(['configured' => false]);
        }

        $apiBase = rtrim(config('mailcow.url'), '/') . '/api/v1';
        $headers = ['X-API-Key' => config('mailcow.api_key'), 'Accept' => 'application/json'];
        $res     = \Http::withHeaders($headers)->get("{$apiBase}/get/domain/{$realm}");
        $exists  = $res->successful() && !empty($res->json()) && !isset($res->json()['type']);

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
            $res = \Http::withHeaders($headers)->post("{$apiBase}/delete/domain", [$realm]);
            if ($res->failed() || ($res->json()[0]['type'] ?? '') === 'error') {
                $detail = $res->json()[0]['msg'] ?? $res->body();
                return back()->withErrors(['realm' => "Failed to remove Mailcow domain: {$detail}"]);
            }
            $map->update(['mailcow_enabled' => false]);
            AuditLogger::log('mailcow.removed', $realm);
            return redirect()->route('super.realms')->with('success', "Mailcow domain '{$realm}' removed.");
        }

        if ($request->boolean('link_only')) {
            $map->update(['mailcow_enabled' => true]);
            AuditLogger::log('mailcow.linked', $realm);
            return redirect()->route('super.realms')->with('success', "Mailcow domain '{$realm}' linked.");
        }

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
        AuditLogger::log('mailcow.created', $realm);
        return redirect()->route('super.realms')->with('success', "Mailcow domain '{$realm}' created.");
    }

    public function destroy(Request $request, string $realm)
    {
        $res = \Http::withToken($this->adminToken())->delete("{$this->baseUrl()}/admin/realms/{$realm}");

        if ($res->failed()) {
            return back()->withErrors(['realm' => 'Failed to delete realm.']);
        }

        DomainRealmMap::where('realm', $realm)->delete();
        AuditLogger::log('realm.deleted', $realm, $request->boolean('delete_mailcow') ? 'Mailcow domain also deleted' : null);

        if ($request->boolean('delete_mailcow') && config('mailcow.url') && config('mailcow.api_key')) {
            $apiBase = rtrim(config('mailcow.url'), '/') . '/api/v1';
            \Http::withHeaders(['X-API-Key' => config('mailcow.api_key'), 'Accept' => 'application/json'])
                ->post("{$apiBase}/delete/domain", [$realm]);
        }

        return redirect()->route('super.realms')->with('success', "Realm '{$realm}' deleted.");
    }
}
