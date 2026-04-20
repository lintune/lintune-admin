<?php

namespace App\Http\Controllers\Super;

use App\Http\Controllers\Controller;
use App\Models\DomainRealmMap;
use App\Models\RealmConfig;
use App\Models\Setting;
use App\Services\AuditLogger;
use Illuminate\Http\Request;

class SuperRealmController extends Controller
{
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

    private function mailcowHeaders(?string $realm = null): array
    {
        $key = $realm
            ? (RealmConfig::get($realm, 'mailcow.api_key') ?? Setting::get('mailcow.api_key', config('mailcow.api_key')))
            : Setting::get('mailcow.api_key', config('mailcow.api_key'));
        return ['X-API-Key' => $key, 'Accept' => 'application/json'];
    }

    private function mailcowBase(?string $realm = null): string
    {
        $url = $realm
            ? (RealmConfig::get($realm, 'mailcow.url') ?? Setting::get('mailcow.url', config('mailcow.url')))
            : Setting::get('mailcow.url', config('mailcow.url'));
        return rtrim($url, '/') . '/api/v1';
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

        return view('super.realms', compact('realms', 'domainMaps'))->with(
            'mailcowConfigured',
            (bool) Setting::get('mailcow.url', config('mailcow.url'))
        );
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

        // 4. Assign realm-admin role
        $userId     = basename($userRes->header('Location'));
        $clients    = \Http::withToken($token)->get("{$base}/admin/realms/{$realm}/clients")->json();
        $mgmtClient = collect($clients)->firstWhere('clientId', 'realm-management');

        if ($mgmtClient) {
            $mgmtId    = $mgmtClient['id'];
            $rolesData = \Http::withToken($token)->get("{$base}/admin/realms/{$realm}/clients/{$mgmtId}/roles")->json();
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

        // 6. Set up broker-realm federation
        $brokerRealm = config('keycloak.broker_realm');
        if ($brokerRealm) {
            $this->setupBrokerFederation($base, $token, $realm, $brokerRealm);
        }

        AuditLogger::log('realm.created', $realm);

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
                'clientId'          => 'broker-realm-client',
                'clientSecret'      => $secret,
                'authorizationUrl'  => "{$base}/realms/{$realm}/protocol/openid-connect/auth",
                'tokenUrl'          => "{$base}/realms/{$realm}/protocol/openid-connect/token",
                'jwksUrl'           => "{$base}/realms/{$realm}/protocol/openid-connect/certs",
                'logoutUrl'         => "{$base}/realms/{$realm}/protocol/openid-connect/logout",
                'userInfoUrl'       => "{$base}/realms/{$realm}/protocol/openid-connect/userinfo",
                'issuer'            => "{$base}/realms/{$realm}",
                'validateSignature' => 'true',
                'useJwksUrl'        => 'true',
                'pkceEnabled'       => 'false',
                'syncMode'          => 'IMPORT',
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

    public function mailcowSettings(string $realm)
    {
        $map    = DomainRealmMap::where('realm', $realm)->firstOrFail();
        $exists = false;
        $domain = null;

        if (Setting::get('mailcow.url', config('mailcow.url')) && Setting::get('mailcow.api_key', config('mailcow.api_key'))) {
            $res    = \Http::withHeaders($this->mailcowHeaders($realm))->get("{$this->mailcowBase($realm)}/get/domain/{$realm}");
            $exists = $res->successful() && !empty($res->json()) && !isset($res->json()['type']);
            $domain = $exists ? $res->json() : null;
        }

        return response()->json([
            'mailcow_enabled' => (bool) $map->mailcow_enabled,
            'exists'          => $exists,
            'mailboxes'       => $domain['max_num_mboxes_for_domain'] ?? Setting::get('mailcow.default_mailboxes', 10),
            'aliases'         => $domain['max_num_aliases_for_domain'] ?? Setting::get('mailcow.default_aliases', 10),
            'maxquota'        => isset($domain['max_quota_for_mbox']) ? (int) round($domain['max_quota_for_mbox'] / 1048576) : (int) Setting::get('mailcow.default_maxquota', 10240),
            'quota'           => isset($domain['max_quota_for_domain']) ? (int) round($domain['max_quota_for_domain'] / 1048576) : (int) Setting::get('mailcow.default_quota', 102400),
        ]);
    }

    public function updateMailcowLimits(Request $request, string $realm)
    {
        $request->validate([
            'mailboxes' => 'required|integer|min:1',
            'aliases'   => 'required|integer|min:0',
            'maxquota'  => 'required|integer|min:1',
            'quota'     => 'required|integer|min:1',
            'enabled'   => 'required|boolean',
            'exists'    => 'required|boolean',
        ]);

        $map     = DomainRealmMap::where('realm', $realm)->firstOrFail();
        $payload = [
            'domain'       => $realm,
            'active'       => '1',
            'restart_sogo' => '1',
            'mailboxes'    => $request->mailboxes,
            'aliases'      => $request->aliases,
            'maxquota'     => $request->maxquota,
            'quota'        => $request->quota,
        ];

        if (!$request->boolean('exists')) {
            $res = \Http::withHeaders($this->mailcowHeaders($realm))->post("{$this->mailcowBase($realm)}/add/domain", $payload);
            if ($res->failed() || ($res->json()[0]['type'] ?? '') === 'error') {
                return back()->withErrors(['realm' => 'Mailcow error: ' . ($res->json()[0]['msg'] ?? $res->body())]);
            }
            // Snapshot which Mailcow server this realm was provisioned on
            RealmConfig::set($realm, 'mailcow.url', Setting::get('mailcow.url', config('mailcow.url')));
            RealmConfig::set($realm, 'mailcow.api_key', Setting::get('mailcow.api_key', config('mailcow.api_key')), true);
            AuditLogger::log('mailcow.created', $realm);
        } else {
            $res = \Http::withHeaders($this->mailcowHeaders($realm))->post("{$this->mailcowBase($realm)}/edit/domain", [
                'attr'  => $payload,
                'items' => [$realm],
            ]);
            if ($res->failed() || ($res->json()[0]['type'] ?? '') === 'error') {
                return back()->withErrors(['realm' => 'Mailcow error: ' . ($res->json()[0]['msg'] ?? $res->body())]);
            }
            AuditLogger::log('mailcow.limits_updated', $realm);
        }

        $map->update(['mailcow_enabled' => $request->boolean('enabled')]);
        return redirect()->route('super.realms')->with('success', "Mailcow settings saved for '{$realm}'.");
    }

    public function removeMailcow(string $realm)
    {
        $res = \Http::withHeaders($this->mailcowHeaders($realm))->post("{$this->mailcowBase($realm)}/delete/domain", [$realm]);

        if ($res->failed() || ($res->json()[0]['type'] ?? '') === 'error') {
            return back()->withErrors(['realm' => 'Failed to remove Mailcow domain: ' . ($res->json()[0]['msg'] ?? $res->body())]);
        }

        DomainRealmMap::where('realm', $realm)->update(['mailcow_enabled' => false]);
        AuditLogger::log('mailcow.removed', $realm);
        return redirect()->route('super.realms')->with('success', "Mailcow domain '{$realm}' removed.");
    }

    public function destroy(Request $request, string $realm)
    {
        $res = \Http::withToken($this->adminToken())->delete("{$this->baseUrl()}/admin/realms/{$realm}");

        if ($res->failed()) {
            return back()->withErrors(['realm' => 'Failed to delete realm.']);
        }

        DomainRealmMap::where('realm', $realm)->delete();
        AuditLogger::log('realm.deleted', $realm, $request->boolean('delete_mailcow') ? 'Mailcow domain also deleted' : null);

        if ($request->boolean('delete_mailcow') && Setting::get('mailcow.url', config('mailcow.url')) && Setting::get('mailcow.api_key', config('mailcow.api_key'))) {
            \Http::withHeaders($this->mailcowHeaders())->post("{$this->mailcowBase()}/delete/domain", [$realm]);
        }

        return redirect()->route('super.realms')->with('success', "Realm '{$realm}' deleted.");
    }
}
