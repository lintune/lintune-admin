<?php

namespace App\Http\Controllers\Super;

use App\Http\Controllers\Controller;
use App\Models\DomainRealmMap;
use App\Models\Mailbox;
use App\Models\NextcloudUser;
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

    private function nextcloudBase(?string $realm = null): string
    {
        $url = $realm
            ? (RealmConfig::get($realm, 'nextcloud.url') ?? Setting::get('nextcloud.url', ''))
            : Setting::get('nextcloud.url', '');
        return rtrim($url, '/') . '/ocs/v1.php';
    }

    private function nextcloudAuth(?string $realm = null): array
    {
        $user = $realm
            ? (RealmConfig::get($realm, 'nextcloud.service_user') ?? Setting::get('nextcloud.service_user', ''))
            : Setting::get('nextcloud.service_user', '');
        $pass = $realm
            ? (RealmConfig::get($realm, 'nextcloud.service_password') ?? Setting::get('nextcloud.service_password', ''))
            : Setting::get('nextcloud.service_password', '');
        return [$user, $pass];
    }

    public function index()
    {
        $base  = $this->baseUrl();
        $token = $this->adminToken();

        $response = \Http::withToken($token)->get("{$base}/admin/realms");

        $realms = $response->successful() ? $response->json() : [];

        $systemRealms = ['master', config('keycloak.broker_realm')];
        $realms = array_values(array_filter($realms, fn($r) => !in_array($r['realm'], $systemRealms)));

        $realmNames = array_column($realms, 'realm');
        $domainMaps = DomainRealmMap::whereIn('realm', $realmNames)->get()->keyBy('realm');

        // Keycloak user counts — one API call per realm (unavoidable)
        $keycloakCounts = [];
        foreach ($realmNames as $r) {
            $res = \Http::withToken($token)->get("{$base}/admin/realms/{$r}/users/count");
            $keycloakCounts[$r] = $res->successful() ? (int) $res->body() : 0;
        }

        // DB counts
        $mailboxCounts  = Mailbox::whereIn('realm', $realmNames)
            ->selectRaw('realm, count(*) as total')->groupBy('realm')
            ->pluck('total', 'realm');
        $nextcloudCounts = NextcloudUser::whereIn('realm', $realmNames)
            ->selectRaw('realm, count(*) as total')->groupBy('realm')
            ->pluck('total', 'realm');

        return view('super.realms', [
            'realms'             => $realms,
            'domainMaps'         => $domainMaps,
            'keycloakCounts'     => $keycloakCounts,
            'mailboxCounts'      => $mailboxCounts,
            'nextcloudCounts'    => $nextcloudCounts,
            'mailcowConfigured'  => (bool) Setting::get('mailcow.url', config('mailcow.url')),
            'nextcloudConfigured' => (bool) Setting::get('nextcloud.url'),
        ]);
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

        $realmRes = \Http::withToken($token)->post("{$base}/admin/realms", [
            'realm'   => $realm,
            'enabled' => true,
        ]);

        if ($realmRes->failed()) {
            return back()->withErrors(['realm' => 'Failed to create realm: ' . $realmRes->body()]);
        }

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

        DomainRealmMap::updateOrCreate(
            ['domain' => $realm],
            ['realm' => $realm, 'mailcow_enabled' => false, 'nextcloud_enabled' => false]
        );

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

    public function updateLimits(Request $request, string $realm)
    {
        $request->validate([
            'max_users'           => 'nullable|integer|min:1',
            'max_mailbox_users'   => 'nullable|integer|min:0',
            'max_nextcloud_users' => 'nullable|integer|min:0',
        ]);

        DomainRealmMap::where('realm', $realm)->update([
            'max_users'           => $request->max_users ?: null,
            'max_mailbox_users'   => $request->max_mailbox_users ?: null,
            'max_nextcloud_users' => $request->max_nextcloud_users ?: null,
        ]);

        AuditLogger::log('realm.limits_updated', $realm);
        return redirect()->route('super.realms')->with('success', "User limits updated for '{$realm}'.");
    }

    // ── Mailcow ──────────────────────────────────────────────────────────────

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
            'maxquota'        => isset($domain['max_quota_for_mbox']) ? round($domain['max_quota_for_mbox'] / 1073741824, 2) : round(Setting::get('mailcow.default_maxquota', 10240) / 1024, 2),
            'quota'           => isset($domain['max_quota_for_domain']) ? round($domain['max_quota_for_domain'] / 1073741824, 2) : round(Setting::get('mailcow.default_quota', 102400) / 1024, 2),
            'custom_url'      => RealmConfig::get($realm, 'mailcow.url') ?? '',
            'custom_api_key'  => RealmConfig::get($realm, 'mailcow.api_key') ? '••••••••' : '',
        ]);
    }

    public function updateMailcowLimits(Request $request, string $realm)
    {
        $request->validate([
            'mailboxes'      => 'required|integer|min:1',
            'aliases'        => 'required|integer|min:0',
            'maxquota'       => 'required|numeric|min:0.1',
            'quota'          => 'required|numeric|min:0.1',
            'enabled'        => 'required|boolean',
            'exists'         => 'required|boolean',
            'custom_url'     => 'nullable|url',
            'custom_api_key' => 'nullable|string',
        ]);

        // Save custom URL/key overrides if provided
        if ($request->filled('custom_url')) {
            RealmConfig::set($realm, 'mailcow.url', rtrim($request->custom_url, '/'));
        }
        if ($request->filled('custom_api_key') && $request->custom_api_key !== '••••••••') {
            RealmConfig::set($realm, 'mailcow.api_key', $request->custom_api_key, true);
        }

        $map     = DomainRealmMap::where('realm', $realm)->firstOrFail();
        $payload = [
            'domain'       => $realm,
            'active'       => '1',
            'restart_sogo' => '1',
            'mailboxes'    => $request->mailboxes,
            'aliases'      => $request->aliases,
            'maxquota'     => (int) round($request->maxquota * 1024),
            'quota'        => (int) round($request->quota * 1024),
        ];

        if (!$request->boolean('exists')) {
            $res = \Http::withHeaders($this->mailcowHeaders($realm))->post("{$this->mailcowBase($realm)}/add/domain", $payload);
            if ($res->failed() || ($res->json()[0]['type'] ?? '') === 'error') {
                return back()->withErrors(['realm' => 'Mailcow error: ' . ($res->json()[0]['msg'] ?? $res->body())]);
            }
            // Snapshot server if no custom URL was set
            if (!$request->filled('custom_url')) {
                RealmConfig::set($realm, 'mailcow.url', Setting::get('mailcow.url', config('mailcow.url')));
                RealmConfig::set($realm, 'mailcow.api_key', Setting::get('mailcow.api_key', config('mailcow.api_key')), true);
            }
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

    // ── Nextcloud ─────────────────────────────────────────────────────────────

    public function nextcloudSettings(string $realm)
    {
        $map = DomainRealmMap::where('realm', $realm)->firstOrFail();

        [$user, $pass] = $this->nextcloudAuth($realm);
        $base  = $this->nextcloudBase($realm);
        $exists = false;

        if ($base && $user && $pass) {
            $res    = \Http::withBasicAuth($user, $pass)
                ->withHeaders(['OCS-APIRequest' => 'true', 'Accept' => 'application/json'])
                ->get("{$base}/cloud/groups/{$realm}");
            $exists = ($res->json()['ocs']['meta']['statuscode'] ?? 0) === 100;
        }

        return response()->json([
            'nextcloud_enabled'   => (bool) $map->nextcloud_enabled,
            'exists'              => $exists,
            'custom_url'          => RealmConfig::get($realm, 'nextcloud.url') ?? '',
            'custom_service_user' => RealmConfig::get($realm, 'nextcloud.service_user') ?? '',
            'default_quota'       => Setting::get('nextcloud.default_quota', 10),
            'max_users'           => $map->max_users,
            'max_mailbox_users'   => $map->max_mailbox_users,
            'max_nextcloud_users' => $map->max_nextcloud_users,
        ]);
    }

    public function updateNextcloud(Request $request, string $realm)
    {
        $request->validate([
            'enabled'               => 'required|boolean',
            'exists'                => 'required|boolean',
            'custom_url'            => 'nullable|url',
            'custom_service_user'   => 'nullable|string|max:255',
            'custom_service_password' => 'nullable|string',
        ]);

        if ($request->filled('custom_url')) {
            RealmConfig::set($realm, 'nextcloud.url', rtrim($request->custom_url, '/'));
        }
        if ($request->filled('custom_service_user')) {
            RealmConfig::set($realm, 'nextcloud.service_user', trim($request->custom_service_user));
        }
        if ($request->filled('custom_service_password')) {
            RealmConfig::set($realm, 'nextcloud.service_password', $request->custom_service_password, true);
        }

        $map = DomainRealmMap::where('realm', $realm)->firstOrFail();

        if (!$request->boolean('exists')) {
            [$user, $pass] = $this->nextcloudAuth($realm);
            $base = $this->nextcloudBase($realm);

            $res = \Http::withBasicAuth($user, $pass)
                ->withHeaders(['OCS-APIRequest' => 'true', 'Accept' => 'application/json'])
                ->post("{$base}/cloud/groups", ['groupid' => $realm]);

            if (($res->json()['ocs']['meta']['statuscode'] ?? 0) !== 100) {
                $msg = $res->json()['ocs']['meta']['message'] ?? $res->body();
                return back()->withErrors(['realm' => "Nextcloud error: {$msg}"]);
            }

            if (!$request->filled('custom_url')) {
                RealmConfig::set($realm, 'nextcloud.url', Setting::get('nextcloud.url', ''));
            }

            AuditLogger::log('nextcloud.created', $realm);
        }

        $map->update(['nextcloud_enabled' => $request->boolean('enabled')]);
        AuditLogger::log('nextcloud.settings_updated', $realm);
        return redirect()->route('super.realms')->with('success', "Nextcloud settings saved for '{$realm}'.");
    }

    public function removeNextcloud(string $realm)
    {
        [$user, $pass] = $this->nextcloudAuth($realm);
        $base = $this->nextcloudBase($realm);

        $res = \Http::withBasicAuth($user, $pass)
            ->withHeaders(['OCS-APIRequest' => 'true', 'Accept' => 'application/json'])
            ->delete("{$base}/cloud/groups/{$realm}");

        if (($res->json()['ocs']['meta']['statuscode'] ?? 0) !== 100) {
            $msg = $res->json()['ocs']['meta']['message'] ?? $res->body();
            return back()->withErrors(['realm' => "Failed to remove Nextcloud group: {$msg}"]);
        }

        DomainRealmMap::where('realm', $realm)->update(['nextcloud_enabled' => false]);
        NextcloudUser::where('realm', $realm)->delete();
        AuditLogger::log('nextcloud.removed', $realm);
        return redirect()->route('super.realms')->with('success', "Nextcloud group for '{$realm}' removed.");
    }

    // ── Realm delete ──────────────────────────────────────────────────────────

    public function destroy(Request $request, string $realm)
    {
        $res = \Http::withToken($this->adminToken())->delete("{$this->baseUrl()}/admin/realms/{$realm}");

        if ($res->failed()) {
            return back()->withErrors(['realm' => 'Failed to delete realm.']);
        }

        DomainRealmMap::where('realm', $realm)->delete();
        AuditLogger::log('realm.deleted', $realm, $request->boolean('delete_mailcow') ? 'Mailcow domain also deleted' : null);

        if ($request->boolean('delete_mailcow') && Setting::get('mailcow.url', config('mailcow.url')) && Setting::get('mailcow.api_key', config('mailcow.api_key'))) {
            \Http::withHeaders($this->mailcowHeaders($realm))->post("{$this->mailcowBase($realm)}/delete/domain", [$realm]);
        }

        return redirect()->route('super.realms')->with('success', "Realm '{$realm}' deleted.");
    }
}
