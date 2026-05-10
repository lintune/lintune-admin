<?php

namespace App\Http\Controllers\Super;

use App\Http\Controllers\Controller;
use App\Models\DomainRealmMap;
use App\Models\Mailbox;
use App\Models\NextcloudUser;
use App\Models\RealmConfig;
use App\Models\Setting;
use App\Services\AuditLogger;
use App\Services\KumaService;
use Illuminate\Http\Request;

class SuperRealmController extends Controller
{
    private function adminToken(): string
    {
        $base = $this->baseUrl();

        try {
            $res = \Http::timeout(10)->asForm()->post("{$base}/realms/master/protocol/openid-connect/token", [
                'grant_type' => 'password',
                'client_id'  => 'admin-cli',
                'username'   => config('keycloak.admin_user'),
                'password'   => decrypt(base64_decode(config('keycloak.admin_password'))),
            ]);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Cannot reach Keycloak: ' . $e->getMessage());
        }

        if ($res->failed() || empty($res->json()['access_token'])) {
            throw new \RuntimeException('Could not obtain Keycloak admin token. Check KEYCLOAK_ADMIN_USER and KEYCLOAK_ADMIN_PASSWORD in .env.');
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
        try {
            $token = $this->adminToken();
        } catch (\RuntimeException $e) {
            return view('super.realms', [
                'realms'              => [],
                'domainMaps'          => collect(),
                'keycloakCounts'      => [],
                'mailboxCounts'       => collect(),
                'nextcloudCounts'     => collect(),
                'mailcowConfigured'   => false,
                'nextcloudConfigured' => false,
            ])->withErrors(['error' => $e->getMessage()]);
        }

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

        // NC counts: members of the nextcloud KC group in each NC-enabled realm
        $nextcloudCounts = collect();
        foreach ($realmNames as $r) {
            if (!$domainMaps->get($r)?->nextcloud_enabled) continue;
            try {
                $groups  = \Http::withToken($token)->get("{$base}/admin/realms/{$r}/groups", ['search' => 'nextcloud'])->json();
                $ncGroup = collect((array) $groups)->firstWhere('name', 'nextcloud');
                if ($ncGroup) {
                    $members           = \Http::withToken($token)->get("{$base}/admin/realms/{$r}/groups/{$ncGroup['id']}/members", ['max' => 1000])->json();
                    $nextcloudCounts[$r] = count((array) $members);
                }
            } catch (\Throwable) {}
        }

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
        try {
            $token = $this->adminToken();
        } catch (\RuntimeException $e) {
            return back()->withErrors(['realm' => $e->getMessage()]);
        }
        $dashUrl    = rtrim(config('keycloak.dash_url'), '/');

        $realmRes = \Http::withToken($token)->post("{$base}/admin/realms", [
            'realm'      => $realm,
            'enabled'    => true,
            'loginTheme' => 'lintune',
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
        // Create the OIDC client in the tenant realm that the broker IdP will use.
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

        // Create the nextcloud group in the tenant realm so it exists for access control.
        // 409 = already exists (idempotent).
        \Http::withToken($token)->post("{$base}/admin/realms/{$realm}/groups", ['name' => 'nextcloud']);

        // Add a groups claim mapper to broker-realm-client so the tenant realm includes
        // group names in the id_token sent to the broker IdP.
        // Must be in id_token: the broker IdP attribute importer reads from id_token, not access_token.
        \Http::withToken($token)->post(
            "{$base}/admin/realms/{$realm}/clients/{$clientId}/protocol-mappers/models",
            [
                'name'           => 'groups',
                'protocol'       => 'openid-connect',
                'protocolMapper' => 'oidc-group-membership-mapper',
                'config'         => [
                    'full.path'            => 'false',
                    'id.token.claim'       => 'true',
                    'access.token.claim'   => 'true',
                    'userinfo.token.claim' => 'true',
                    'claim.name'           => 'groups',
                ],
            ]
        );

        // Create the Organization first so we have its ID for the link step below.
        // `redirectMode: EMAIL_DOMAIN` — KC 26.1+ required for home IdP discovery auto-redirect.
        // Without it the org authenticator defaults to IMPLICIT (redirects only existing members)
        // and shows "Your email domain matches an organization but you don't have an account yet".
        $orgRes = \Http::withToken($token)->post("{$base}/admin/realms/{$brokerRealm}/organizations", [
            'name'         => $realm,
            'alias'        => $realm,
            'domains'      => [['name' => $realm, 'verified' => false]],
            'enabled'      => true,
            'redirectMode' => 'EMAIL_DOMAIN',
        ]);

        $orgId = null;
        if ($orgRes->successful()) {
            $orgId = basename(rtrim($orgRes->header('Location'), '/'));
            if (!$orgId || strlen($orgId) < 10) {
                $orgs  = \Http::withToken($token)->get("{$base}/admin/realms/{$brokerRealm}/organizations", ['search' => $realm])->json();
                $orgId = collect((array) $orgs)->firstWhere('alias', $realm)['id'] ?? null;
            }
        }

        // Create the OIDC IdP in the broker realm — WITHOUT organizationId.
        // Do NOT set organizationId here: Keycloak stores it but does NOT add the internal
        // `kc.org` config key, so GET /organizations/{id}/identity-providers returns [] and
        // the `organization` authenticator never finds the IdP for domain-based routing.
        // The real link is made below via POST /organizations/{id}/identity-providers.
        //
        // `hideOnLogin`                            — hides manual button from login page.
        // `kc.org.domain`                          — email domain this IdP handles.
        // `kc.org.broker.redirect.mode.email-matches` — triggers auto-redirect on domain match.
        // `loginHint`                              — forwards collected email to tenant realm
        //                                           so the username field is pre-filled.
        $idpPayload = [
            'alias'                     => $realm,
            'displayName'               => $realm,
            'providerId'                => 'oidc',
            'enabled'                   => true,
            'trustEmail'                => true,
            'hideOnLogin'               => true,
            'firstBrokerLoginFlowAlias' => 'first broker login',
            'config' => [
                'clientId'                               => 'broker-realm-client',
                'clientSecret'                           => $secret,
                'authorizationUrl'                       => "{$base}/realms/{$realm}/protocol/openid-connect/auth",
                'tokenUrl'                               => "{$base}/realms/{$realm}/protocol/openid-connect/token",
                'jwksUrl'                                => "{$base}/realms/{$realm}/protocol/openid-connect/certs",
                'logoutUrl'                              => "{$base}/realms/{$realm}/protocol/openid-connect/logout",
                'userInfoUrl'                            => "{$base}/realms/{$realm}/protocol/openid-connect/userinfo",
                'issuer'                                 => "{$base}/realms/{$realm}",
                'validateSignature'                      => 'true',
                'useJwksUrl'                             => 'true',
                'pkceEnabled'                            => 'false',
                'syncMode'                               => 'IMPORT',
                'loginHint'                              => 'true',
                // Disable userinfo endpoint — use ID token directly. The userinfo call
                // overrides given_name/family_name with null if not in userinfo response,
                // causing the first-broker-login Review Profile form to show empty fields.
                'disableUserInfo'                        => 'true',
                'kc.org.domain'                          => $realm,
                'kc.org.broker.redirect.mode.email-matches' => 'true',
            ],
        ];

        $idpRes = \Http::withToken($token)->post("{$base}/admin/realms/{$brokerRealm}/identity-provider/instances", $idpPayload);

        if ($idpRes->failed()) return;

        // Link the IdP to the Organization.
        // POST body must be a JSON string (the alias) — this sets both `organizationId` on
        // the IdP AND the internal `kc.org` config key that the `organization` authenticator
        // reads when routing by email domain. Setting organizationId only during IdP creation
        // (above) skips the kc.org key and leaves GET /organizations/{id}/identity-providers
        // returning [], so the authenticator never finds the IdP.
        if ($orgId) {
            \Http::withToken($token)
                ->withBody(json_encode($realm), 'application/json')
                ->post("{$base}/admin/realms/{$brokerRealm}/organizations/{$orgId}/identity-providers");

            // The org-link strips kc.org.domain and resets kc.org.broker.redirect.mode.email-matches.
            // kc.org.domain: OrganizationAuthenticator reads this to match the email domain before
            //   redirecting — if missing, redirect() returns false and "no account yet" is shown.
            // kc.org.broker.redirect.mode.email-matches: must be "true" (Boolean.parseBoolean) for
            //   IdentityProviderRedirectMode.EMAIL_MATCH.isSet() to return true.
            $idpCurrent = \Http::withToken($token)->get("{$base}/admin/realms/{$brokerRealm}/identity-provider/instances/{$realm}")->json();
            if (!empty($idpCurrent)) {
                $idpCurrent['config']['kc.org.domain']                             = $realm;
                $idpCurrent['config']['kc.org.broker.redirect.mode.email-matches'] = 'true';
                // KC GET returns clientSecret masked as "**********". Omitting it on PUT clears
                // the stored secret; writing "**********" corrupts it. Use the real $secret we
                // already have from the client-secret endpoint.
                $idpCurrent['config']['clientSecret'] = $secret;
                \Http::withToken($token)->put(
                    "{$base}/admin/realms/{$brokerRealm}/identity-provider/instances/{$realm}",
                    $idpCurrent
                );
            }
        }

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

        // KC 26.x declarative user profile silently drops attributes that are not explicitly
        // declared in the realm's user profile schema. Declare nc_groups so the IdP mapper
        // can persist it on the broker realm user.
        $upConfig = \Http::withToken($token)->get("{$base}/admin/realms/{$brokerRealm}/users/profile")->json();
        $upAttrs  = $upConfig['attributes'] ?? [];
        if (!collect($upAttrs)->contains('name', 'nc_groups')) {
            $upAttrs[]              = ['name' => 'nc_groups', 'multivalued' => true, 'permissions' => ['view' => ['admin'], 'edit' => ['admin']]];
            $upConfig['attributes'] = $upAttrs;
            \Http::withToken($token)->put("{$base}/admin/realms/{$brokerRealm}/users/profile", $upConfig);
        }

        // Import the groups claim from the tenant realm id_token into the nc_groups user
        // attribute on the broker realm user so the nextcloud KC client can pass it through.
        // syncMode FORCE: update on every login so group changes take effect immediately.
        \Http::withToken($token)->post("{$base}/admin/realms/{$brokerRealm}/identity-provider/instances/{$realm}/mappers", [
            'name'                   => 'nc_groups',
            'identityProviderAlias'  => $realm,
            'identityProviderMapper' => 'oidc-user-attribute-idp-mapper',
            'config' => [
                'syncMode'               => 'FORCE',
                'claim'                  => 'groups',
                'user.attribute'         => 'nc_groups',
                'are.claim.values.regex' => 'false',
            ],
        ]);

    }

    public function repairFederation(string $realm)
    {
        $base        = $this->baseUrl();
        $brokerRealm = config('keycloak.broker_realm');

        try {
            $token = $this->adminToken();
        } catch (\RuntimeException $e) {
            return redirect()->route('super.realms.edit', $realm)->withErrors(['error' => $e->getMessage()]);
        }

        $steps = [];

        // 1. Ensure nextcloud group in tenant realm
        $res     = \Http::withToken($token)->post("{$base}/admin/realms/{$realm}/groups", ['name' => 'nextcloud']);
        $steps[] = $res->status() === 201 ? 'Created nextcloud KC group' : 'nextcloud KC group already exists';

        // 2. Ensure broker-realm-client exists in tenant realm and retrieve its secret.
        //    The secret is needed to create/update the broker IdP.
        $clients    = \Http::withToken($token)->get("{$base}/admin/realms/{$realm}/clients", ['clientId' => 'broker-realm-client'])->json();
        $client     = collect((array) $clients)->first();
        $kcClientId = null;
        $secret     = null;

        if ($client) {
            $kcClientId = $client['id'];
            $secret     = \Http::withToken($token)->get("{$base}/admin/realms/{$realm}/clients/{$kcClientId}/client-secret")->json()['value'] ?? null;
            $steps[]    = 'broker-realm-client already exists';
        } else {
            $createRes = \Http::withToken($token)->post("{$base}/admin/realms/{$realm}/clients", [
                'clientId'                  => 'broker-realm-client',
                'enabled'                   => true,
                'publicClient'              => false,
                'standardFlowEnabled'       => true,
                'directAccessGrantsEnabled' => false,
                'redirectUris'              => ["{$base}/realms/{$brokerRealm}/broker/{$realm}/endpoint"],
            ]);
            if ($createRes->successful()) {
                $kcClientId = basename($createRes->header('Location'));
                $secret     = \Http::withToken($token)->get("{$base}/admin/realms/{$realm}/clients/{$kcClientId}/client-secret")->json()['value'] ?? null;
                $steps[]    = 'Created broker-realm-client';
            } else {
                $steps[] = 'WARNING: failed to create broker-realm-client — ' . $createRes->body();
            }
        }

        // 3. Ensure groups claim mapper on broker-realm-client
        if ($kcClientId) {
            $mappers  = \Http::withToken($token)->get("{$base}/admin/realms/{$realm}/clients/{$kcClientId}/protocol-mappers/models")->json();
            $existing = collect((array) $mappers)->firstWhere('name', 'groups');
            if (!$existing) {
                \Http::withToken($token)->post(
                    "{$base}/admin/realms/{$realm}/clients/{$kcClientId}/protocol-mappers/models",
                    [
                        'name'           => 'groups',
                        'protocol'       => 'openid-connect',
                        'protocolMapper' => 'oidc-group-membership-mapper',
                        'config'         => [
                            'full.path'            => 'false',
                            'id.token.claim'       => 'true',
                            'access.token.claim'   => 'true',
                            'userinfo.token.claim' => 'true',
                            'claim.name'           => 'groups',
                        ],
                    ]
                );
                $steps[] = 'Added groups claim mapper to broker-realm-client';
            } else {
                \Http::withToken($token)->put(
                    "{$base}/admin/realms/{$realm}/clients/{$kcClientId}/protocol-mappers/models/{$existing['id']}",
                    array_merge($existing, ['config' => array_merge($existing['config'] ?? [], [
                        'id.token.claim'       => 'true',
                        'userinfo.token.claim' => 'true',
                    ])])
                );
                $steps[] = 'Updated groups claim mapper (enabled id_token)';
            }
        }

        if (!$brokerRealm) {
            AuditLogger::log('realm.federation_repaired', $realm);
            return redirect()->route('super.realms.edit', $realm)->with('success', 'Federation repaired (no broker realm): ' . implode('; ', $steps));
        }

        // 4. Ensure Organization exists in broker realm with redirectMode EMAIL_DOMAIN.
        //    KC 26.1+ defaults to IMPLICIT which only redirects existing members — EMAIL_DOMAIN
        //    is required for home IdP discovery to auto-redirect new users based on email domain.
        $orgs  = \Http::withToken($token)->get("{$base}/admin/realms/{$brokerRealm}/organizations", ['search' => $realm])->json();
        $org   = collect((array) $orgs)->firstWhere('alias', $realm);
        $orgId = $org['id'] ?? null;

        if (!$orgId) {
            $orgRes = \Http::withToken($token)->post("{$base}/admin/realms/{$brokerRealm}/organizations", [
                'name'         => $realm,
                'alias'        => $realm,
                'domains'      => [['name' => $realm, 'verified' => false]],
                'enabled'      => true,
                'redirectMode' => 'EMAIL_DOMAIN',
            ]);
            if ($orgRes->successful()) {
                $orgId = basename(rtrim($orgRes->header('Location'), '/'));
                if (!$orgId || strlen($orgId) < 10) {
                    $orgs  = \Http::withToken($token)->get("{$base}/admin/realms/{$brokerRealm}/organizations", ['search' => $realm])->json();
                    $orgId = collect((array) $orgs)->firstWhere('alias', $realm)['id'] ?? null;
                }
                $steps[] = 'Created Organization in broker realm';
            } else {
                $steps[] = 'WARNING: failed to create Organization — ' . $orgRes->body();
            }
        } else {
            $steps[] = 'Organization already exists in broker realm';
        }

        // Ensure redirectMode is EMAIL_DOMAIN (KC 26.1+ requirement for home IdP discovery).
        if ($orgId) {
            $orgFull = \Http::withToken($token)->get("{$base}/admin/realms/{$brokerRealm}/organizations/{$orgId}")->json();
            if (!empty($orgFull) && ($orgFull['redirectMode'] ?? '') !== 'EMAIL_DOMAIN') {
                \Http::withToken($token)->put(
                    "{$base}/admin/realms/{$brokerRealm}/organizations/{$orgId}",
                    array_merge($orgFull, ['redirectMode' => 'EMAIL_DOMAIN'])
                );
                $steps[] = 'Set Organization redirectMode to EMAIL_DOMAIN (home IdP discovery fix)';
            } elseif (!empty($orgFull)) {
                $steps[] = 'Organization redirectMode already EMAIL_DOMAIN';
            }
        }

        // 5. Ensure IdP exists in broker realm; create if missing
        $idpFetch   = \Http::withToken($token)->get("{$base}/admin/realms/{$brokerRealm}/identity-provider/instances/{$realm}");
        $idpCurrent = $idpFetch->successful() ? $idpFetch->json() : null;

        if (!$idpCurrent) {
            if (!$secret) {
                $steps[] = 'WARNING: broker IdP missing and no client secret available — broker-realm-client must exist first';
            } else {
                $createIdpRes = \Http::withToken($token)->post("{$base}/admin/realms/{$brokerRealm}/identity-provider/instances", [
                    'alias'                     => $realm,
                    'displayName'               => $realm,
                    'providerId'                => 'oidc',
                    'enabled'                   => true,
                    'trustEmail'                => true,
                    'hideOnLogin'               => true,
                    'firstBrokerLoginFlowAlias' => 'first broker login',
                    'config' => [
                        'clientId'                               => 'broker-realm-client',
                        'clientSecret'                           => $secret,
                        'authorizationUrl'                       => "{$base}/realms/{$realm}/protocol/openid-connect/auth",
                        'tokenUrl'                               => "{$base}/realms/{$realm}/protocol/openid-connect/token",
                        'jwksUrl'                                => "{$base}/realms/{$realm}/protocol/openid-connect/certs",
                        'logoutUrl'                              => "{$base}/realms/{$realm}/protocol/openid-connect/logout",
                        'userInfoUrl'                            => "{$base}/realms/{$realm}/protocol/openid-connect/userinfo",
                        'issuer'                                 => "{$base}/realms/{$realm}",
                        'validateSignature'                      => 'true',
                        'useJwksUrl'                             => 'true',
                        'pkceEnabled'                            => 'false',
                        'syncMode'                               => 'IMPORT',
                        'loginHint'                              => 'true',
                        'disableUserInfo'                        => 'true',
                        'kc.org.domain'                          => $realm,
                        'kc.org.broker.redirect.mode.email-matches' => 'true',
                    ],
                ]);
                if ($createIdpRes->successful()) {
                    $idpCurrent = \Http::withToken($token)->get("{$base}/admin/realms/{$brokerRealm}/identity-provider/instances/{$realm}")->json();
                    $steps[] = 'Created IdP in broker realm';
                } else {
                    $steps[] = 'WARNING: failed to create IdP — ' . $createIdpRes->body();
                }
            }
        } else {
            $steps[] = 'IdP already exists in broker realm';
        }

        // 6. Ensure IdP is linked to Organization, then restore kc.org.broker.redirect.mode.email-matches
        //    (org-link resets this config to its default false).
        if ($orgId && $idpCurrent) {
            $linkedIdps = \Http::withToken($token)->get("{$base}/admin/realms/{$brokerRealm}/organizations/{$orgId}/identity-providers")->json();
            $isLinked   = collect((array) $linkedIdps)->contains('alias', $realm);

            if (!$isLinked) {
                \Http::withToken($token)
                    ->withBody(json_encode($realm), 'application/json')
                    ->post("{$base}/admin/realms/{$brokerRealm}/organizations/{$orgId}/identity-providers");
                $steps[] = 'Linked IdP to Organization';
            } else {
                $steps[] = 'IdP already linked to Organization';
            }

            $idpCurrent = \Http::withToken($token)->get("{$base}/admin/realms/{$brokerRealm}/identity-provider/instances/{$realm}")->json();
            $needsPut = false;
            if (($idpCurrent['config']['kc.org.domain'] ?? '') !== $realm) {
                $idpCurrent['config']['kc.org.domain'] = $realm;
                $needsPut = true;
            }
            if (($idpCurrent['config']['kc.org.broker.redirect.mode.email-matches'] ?? '') !== 'true') {
                $idpCurrent['config']['kc.org.broker.redirect.mode.email-matches'] = 'true';
                $needsPut = true;
            }
            if (($idpCurrent['config']['disableUserInfo'] ?? '') !== 'true') {
                // Disable userinfo endpoint: KC's userinfo call overrides given_name/family_name
                // with null when not returned, causing first-broker-login Review Profile to show
                // an empty form. The ID token already has all needed claims.
                $idpCurrent['config']['disableUserInfo'] = 'true';
                $needsPut = true;
            }
            if ($needsPut) {
                // KC GET returns clientSecret masked as "**********". Omitting it on PUT clears
                // the stored secret; writing "**********" corrupts it. Regenerate the client
                // secret and write the real value so IdP and client always stay in sync.
                if ($kcClientId) {
                    $freshSecret = \Http::withToken($token)->post("{$base}/admin/realms/{$realm}/clients/{$kcClientId}/client-secret")->json()['value'] ?? $secret;
                    $idpCurrent['config']['clientSecret'] = $freshSecret;
                } else {
                    unset($idpCurrent['config']['clientSecret']);
                }
                \Http::withToken($token)->put(
                    "{$base}/admin/realms/{$brokerRealm}/identity-provider/instances/{$realm}",
                    $idpCurrent
                );
                $steps[] = 'Updated broker IdP config (kc.org.domain, redirect mode, disableUserInfo)';
            } else {
                $steps[] = 'Broker IdP config already correct';
            }
        }

        // 7. Ensure organization authenticator has requiresUserMembership=false (KC 26.6+ fix).
        //    Default is true — blocks new users instead of redirecting to their IdP.
        $flowExecs  = \Http::withToken($token)->get("{$base}/admin/realms/{$brokerRealm}/authentication/flows/broker-home-idp-discovery/executions")->json();
        $orgExec    = collect((array) $flowExecs)->firstWhere('providerId', 'organization');
        if ($orgExec) {
            $configId = $orgExec['authenticationConfig'] ?? null;
            if (!$configId) {
                \Http::withToken($token)->post(
                    "{$base}/admin/realms/{$brokerRealm}/authentication/executions/{$orgExec['id']}/config",
                    ['alias' => 'lintune-org-redirect', 'config' => ['requiresUserMembership' => 'false']]
                );
                $steps[] = 'Set requiresUserMembership=false on organization authenticator';
            } else {
                $cfg = \Http::withToken($token)->get("{$base}/admin/realms/{$brokerRealm}/authentication/config/{$configId}")->json();
                if (($cfg['config']['requiresUserMembership'] ?? 'true') !== 'false') {
                    $cfg['config']['requiresUserMembership'] = 'false';
                    \Http::withToken($token)->put("{$base}/admin/realms/{$brokerRealm}/authentication/config/{$configId}", $cfg);
                    $steps[] = 'Updated requiresUserMembership to false on organization authenticator';
                } else {
                    $steps[] = 'requiresUserMembership already false on organization authenticator';
                }
            }
        }

        // 8. Ensure first-broker-login Review Profile step is set to OFF.
        //    The broker realm is a pure pass-through — user profile data comes from the tenant
        //    realm via OIDC claims automatically. Users should never see the profile form here.
        $fbFlowExecs = \Http::withToken($token)->get("{$base}/admin/realms/{$brokerRealm}/authentication/flows/first%20broker%20login/executions")->json();
        $rpExec      = collect((array) $fbFlowExecs)->firstWhere('providerId', 'idp-review-profile');
        if ($rpExec) {
            $rpConfigId = $rpExec['authenticationConfig'] ?? null;
            if ($rpConfigId) {
                $rpCfg = \Http::withToken($token)->get("{$base}/admin/realms/{$brokerRealm}/authentication/config/{$rpConfigId}")->json();
                if (($rpCfg['config']['update.profile.on.first.login'] ?? '') !== 'off') {
                    $rpCfg['config']['update.profile.on.first.login'] = 'off';
                    \Http::withToken($token)->put("{$base}/admin/realms/{$brokerRealm}/authentication/config/{$rpConfigId}", $rpCfg);
                    $steps[] = 'Set Review Profile to off in first-broker-login flow';
                } else {
                    $steps[] = 'Review Profile already off in first-broker-login flow';
                }
            }
        }

        // 9. Ensure nc_groups is declared in broker realm user profile.
        //    KC 26.x declarative user profile silently drops undeclared attributes —
        //    without this, the IdP mapper sets nc_groups but KC never persists it.
        $upConfig = \Http::withToken($token)->get("{$base}/admin/realms/{$brokerRealm}/users/profile")->json();
        $upAttrs  = $upConfig['attributes'] ?? [];
        if (!collect($upAttrs)->contains('name', 'nc_groups')) {
            $upAttrs[]              = ['name' => 'nc_groups', 'multivalued' => true, 'permissions' => ['view' => ['admin'], 'edit' => ['admin']]];
            $upConfig['attributes'] = $upAttrs;
            \Http::withToken($token)->put("{$base}/admin/realms/{$brokerRealm}/users/profile", $upConfig);
            $steps[] = 'Declared nc_groups in broker realm user profile';
        } else {
            $steps[] = 'nc_groups already declared in broker realm user profile';
        }

        // 9. Ensure nc_groups attribute importer on broker IdP (syncMode FORCE)
        $idpMappers = \Http::withToken($token)->get("{$base}/admin/realms/{$brokerRealm}/identity-provider/instances/{$realm}/mappers")->json();
        $ncMapper   = collect((array) $idpMappers)->firstWhere('name', 'nc_groups');
        if (!$ncMapper) {
            \Http::withToken($token)->post("{$base}/admin/realms/{$brokerRealm}/identity-provider/instances/{$realm}/mappers", [
                'name'                   => 'nc_groups',
                'identityProviderAlias'  => $realm,
                'identityProviderMapper' => 'oidc-user-attribute-idp-mapper',
                'config' => [
                    'syncMode'               => 'FORCE',
                    'claim'                  => 'groups',
                    'user.attribute'         => 'nc_groups',
                    'are.claim.values.regex' => 'false',
                ],
            ]);
            $steps[] = 'Added nc_groups attribute importer on broker IdP';
        } else {
            \Http::withToken($token)->put(
                "{$base}/admin/realms/{$brokerRealm}/identity-provider/instances/{$realm}/mappers/{$ncMapper['id']}",
                array_merge($ncMapper, ['config' => array_merge($ncMapper['config'] ?? [], ['syncMode' => 'FORCE'])])
            );
            $steps[] = 'Updated nc_groups attribute importer (syncMode → FORCE)';
        }

        // 10. Ensure nc_groups attribute mapper on broker nextcloud KC client
        $ncClients    = \Http::withToken($token)->get("{$base}/admin/realms/{$brokerRealm}/clients", ['clientId' => 'nextcloud'])->json();
        $ncClient     = collect((array) $ncClients)->first();
        if ($ncClient) {
            $ncClientId   = $ncClient['id'];
            $ncMappers    = \Http::withToken($token)->get("{$base}/admin/realms/{$brokerRealm}/clients/{$ncClientId}/protocol-mappers/models")->json();
            $ncAttrMapper = collect((array) $ncMappers)->firstWhere('name', 'nc_groups');
            $correctMapperConfig = [
                'user.attribute'       => 'nc_groups',
                'claim.name'           => 'groups',
                'jsonType.label'       => 'String',
                'id.token.claim'       => 'true',
                'access.token.claim'   => 'true',
                'userinfo.token.claim' => 'true',
                'multivalued'          => 'true',
                'aggregate.attrs'      => 'false',
            ];
            if (!$ncAttrMapper) {
                \Http::withToken($token)->post(
                    "{$base}/admin/realms/{$brokerRealm}/clients/{$ncClientId}/protocol-mappers/models",
                    [
                        'name'           => 'nc_groups',
                        'protocol'       => 'openid-connect',
                        'protocolMapper' => 'oidc-usermodel-attribute-mapper',
                        'config'         => $correctMapperConfig,
                    ]
                );
                $steps[] = 'Added nc_groups → groups mapper on broker nextcloud client';
            } else {
                // Fix mapper if id.token.claim or userinfo.token.claim are wrong (must be true for user_oidc)
                $existingConfig = $ncAttrMapper['config'] ?? [];
                if (($existingConfig['id.token.claim'] ?? '') !== 'true' || ($existingConfig['userinfo.token.claim'] ?? '') !== 'true') {
                    \Http::withToken($token)->put(
                        "{$base}/admin/realms/{$brokerRealm}/clients/{$ncClientId}/protocol-mappers/models/{$ncAttrMapper['id']}",
                        array_merge($ncAttrMapper, ['config' => array_merge($existingConfig, ['id.token.claim' => 'true', 'userinfo.token.claim' => 'true'])])
                    );
                    $steps[] = 'Fixed nc_groups mapper: enabled id.token.claim and userinfo.token.claim';
                } else {
                    $steps[] = 'nc_groups mapper on broker nextcloud client already correct';
                }
            }
        } else {
            $steps[] = 'WARNING: nextcloud KC client not found in broker realm (install Nextcloud first)';
        }

        AuditLogger::log('realm.federation_repaired', $realm);
        $summary = implode('; ', $steps);
        return redirect()->route('super.realms.edit', $realm)->with('success', "Federation repaired: {$summary}. Users must log out and back in for group changes to take effect.");
    }

    public function toggle(string $realm)
    {
        $base = $this->baseUrl();
        try {
            $token = $this->adminToken();
        } catch (\RuntimeException $e) {
            return redirect()->route('super.realms')->withErrors(['error' => $e->getMessage()]);
        }
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
        return redirect()->route('super.realms.edit', $realm)->with('success', "Mailcow domain '{$realm}' removed.");
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
        return redirect()->route('super.realms.edit', $realm)->with('success', "Nextcloud group for '{$realm}' removed.");
    }

    // ── Edit page ────────────────────────────────────────────────────────────

    public function edit(string $realm)
    {
        $map               = DomainRealmMap::where('realm', $realm)->firstOrFail();
        $mailcowConfigured = (bool) Setting::get('mailcow.url', config('mailcow.url'));
        $nextcloudConfigured = (bool) Setting::get('nextcloud.url');

        $mailcowExists = false;
        $mailcowDomain = null;
        if ($mailcowConfigured) {
            try {
                $res = \Http::withHeaders($this->mailcowHeaders($realm))
                    ->get("{$this->mailcowBase($realm)}/get/domain/{$realm}");
                $mailcowExists = $res->successful() && !empty($res->json()) && !isset($res->json()['type']);
                $mailcowDomain = $mailcowExists ? $res->json() : null;
            } catch (\Throwable) {}
        }

        $nextcloudExists = false;
        if ($nextcloudConfigured) {
            try {
                [$user, $pass] = $this->nextcloudAuth($realm);
                $base = $this->nextcloudBase($realm);
                if ($base && $user && $pass) {
                    $res = \Http::withBasicAuth($user, $pass)
                        ->withHeaders(['OCS-APIRequest' => 'true', 'Accept' => 'application/json'])
                        ->get("{$base}/cloud/groups/{$realm}");
                    $nextcloudExists = ($res->json()['ocs']['meta']['statuscode'] ?? 0) === 100;
                }
            } catch (\Throwable) {}
        }

        $defaultMaxquota = round(Setting::get('mailcow.default_maxquota', 10240) / 1024, 2);
        $defaultQuota    = round(Setting::get('mailcow.default_quota', 102400) / 1024, 2);

        $mailcowValues = [
            'mailboxes'      => $mailcowDomain['max_num_mboxes_for_domain'] ?? Setting::get('mailcow.default_mailboxes', 10),
            'aliases'        => $mailcowDomain['max_num_aliases_for_domain'] ?? Setting::get('mailcow.default_aliases', 10),
            'maxquota'       => isset($mailcowDomain['max_quota_for_mbox'])    ? round($mailcowDomain['max_quota_for_mbox']    / 1073741824, 2) : $defaultMaxquota,
            'quota'          => isset($mailcowDomain['max_quota_for_domain'])  ? round($mailcowDomain['max_quota_for_domain']  / 1073741824, 2) : $defaultQuota,
            'custom_url'     => RealmConfig::get($realm, 'mailcow.url')     ?? '',
            'custom_api_key' => RealmConfig::get($realm, 'mailcow.api_key') ? '••••••••' : '',
        ];

        $nextcloudValues = [
            'custom_url'          => RealmConfig::get($realm, 'nextcloud.url')          ?? '',
            'custom_service_user' => RealmConfig::get($realm, 'nextcloud.service_user') ?? '',
        ];

        return view('super.realm-edit', [
            'realm'               => $realm,
            'map'                 => $map,
            'mailcowConfigured'   => $mailcowConfigured,
            'nextcloudConfigured' => $nextcloudConfigured,
            'mailcowExists'       => $mailcowExists,
            'nextcloudExists'     => $nextcloudExists,
            'mailcowValues'       => $mailcowValues,
            'nextcloudValues'     => $nextcloudValues,
        ]);
    }

    public function update(Request $request, string $realm)
    {
        $request->validate([
            'max_users'                        => 'nullable|integer|min:1',
            'mailcow_enabled'                  => 'boolean',
            'mailcow_exists'                   => 'boolean',
            'mailcow_mailboxes'                => 'required_if:mailcow_enabled,1|nullable|integer|min:1',
            'mailcow_aliases'                  => 'required_if:mailcow_enabled,1|nullable|integer|min:0',
            'mailcow_maxquota'                 => 'required_if:mailcow_enabled,1|nullable|numeric|min:0.1',
            'mailcow_quota'                    => 'required_if:mailcow_enabled,1|nullable|numeric|min:0.1',
            'max_mailbox_users'                => 'nullable|integer|min:0',
            'mailcow_custom_url'               => 'nullable|url',
            'mailcow_custom_api_key'           => 'nullable|string',
            'nextcloud_enabled'                => 'boolean',
            'nextcloud_exists'                 => 'boolean',
            'max_nextcloud_users'              => 'nullable|integer|min:0',
            'nextcloud_custom_url'             => 'nullable|url',
            'nextcloud_custom_service_user'    => 'nullable|string|max:255',
            'nextcloud_custom_service_password'=> 'nullable|string',
        ]);

        $map              = DomainRealmMap::where('realm', $realm)->firstOrFail();
        $mailcowEnabled   = $request->boolean('mailcow_enabled');
        $nextcloudEnabled = $request->boolean('nextcloud_enabled');

        if (!$mailcowEnabled && $request->boolean('mailcow_exists')) {
            return back()->withErrors(['error' => 'Cannot disable Mailcow while the domain is still provisioned. Use "Remove Mailcow domain" in the danger zone to fully remove it.']);
        }

        if (!$nextcloudEnabled && $request->boolean('nextcloud_exists')) {
            return back()->withErrors(['error' => 'Cannot disable Nextcloud while the group is still provisioned. Use "Remove Nextcloud group" in the danger zone to fully remove it.']);
        }

        // ── Mailcow ───────────────────────────────────────────────────────────
        if ($request->filled('mailcow_custom_url')) {
            RealmConfig::set($realm, 'mailcow.url', rtrim($request->mailcow_custom_url, '/'));
        }
        if ($request->filled('mailcow_custom_api_key') && $request->mailcow_custom_api_key !== '••••••••') {
            RealmConfig::set($realm, 'mailcow.api_key', $request->mailcow_custom_api_key, true);
        }

        if ($mailcowEnabled) {
            $payload = [
                'domain'       => $realm,
                'active'       => '1',
                'restart_sogo' => '1',
                'mailboxes'    => $request->mailcow_mailboxes,
                'aliases'      => $request->mailcow_aliases,
                'maxquota'     => (int) round($request->mailcow_maxquota * 1024),
                'quota'        => (int) round($request->mailcow_quota    * 1024),
            ];

            if (!$request->boolean('mailcow_exists')) {
                $res = \Http::withHeaders($this->mailcowHeaders($realm))
                    ->post("{$this->mailcowBase($realm)}/add/domain", $payload);
                if ($res->failed() || ($res->json()[0]['type'] ?? '') === 'error') {
                    return back()->withErrors(['error' => 'Mailcow error: ' . ($res->json()[0]['msg'] ?? $res->body())]);
                }
                if (!$request->filled('mailcow_custom_url')) {
                    $mcUrl = Setting::get('mailcow.url', config('mailcow.url'));
                    $mcKey = Setting::get('mailcow.api_key', config('mailcow.api_key'));
                    if ($mcUrl) RealmConfig::set($realm, 'mailcow.url', $mcUrl);
                    if ($mcKey) RealmConfig::set($realm, 'mailcow.api_key', $mcKey, true);
                }
                AuditLogger::log('mailcow.created', $realm);
            } else {
                $res = \Http::withHeaders($this->mailcowHeaders($realm))
                    ->post("{$this->mailcowBase($realm)}/edit/domain", ['attr' => $payload, 'items' => [$realm]]);
                if ($res->failed() || ($res->json()[0]['type'] ?? '') === 'error') {
                    return back()->withErrors(['error' => 'Mailcow error: ' . ($res->json()[0]['msg'] ?? $res->body())]);
                }
                AuditLogger::log('mailcow.limits_updated', $realm);
            }
        }

        // ── Nextcloud ─────────────────────────────────────────────────────────
        if ($request->filled('nextcloud_custom_url')) {
            RealmConfig::set($realm, 'nextcloud.url', rtrim($request->nextcloud_custom_url, '/'));
        }
        if ($request->filled('nextcloud_custom_service_user')) {
            RealmConfig::set($realm, 'nextcloud.service_user', trim($request->nextcloud_custom_service_user));
        }
        if ($request->filled('nextcloud_custom_service_password')) {
            RealmConfig::set($realm, 'nextcloud.service_password', $request->nextcloud_custom_service_password, true);
        }

        if ($nextcloudEnabled && !$request->boolean('nextcloud_exists')) {
            [$user, $pass] = $this->nextcloudAuth($realm);
            $base = $this->nextcloudBase($realm);
            $res = \Http::withBasicAuth($user, $pass)
                ->withHeaders(['OCS-APIRequest' => 'true', 'Accept' => 'application/json'])
                ->post("{$base}/cloud/groups", ['groupid' => $realm]);
            if (($res->json()['ocs']['meta']['statuscode'] ?? 0) !== 100) {
                $msg = $res->json()['ocs']['meta']['message'] ?? $res->body();
                return back()->withErrors(['error' => "Nextcloud error: {$msg}"]);
            }
            if (!$request->filled('nextcloud_custom_url')) {
                RealmConfig::set($realm, 'nextcloud.url', Setting::get('nextcloud.url', ''));
            }
            AuditLogger::log('nextcloud.created', $realm);
        }

        // ── DomainRealmMap ────────────────────────────────────────────────────
        $map->update([
            'max_users'           => $request->max_users ?: null,
            'max_mailbox_users'   => $request->max_mailbox_users ?: null,
            'max_nextcloud_users' => $request->max_nextcloud_users ?: null,
            'mailcow_enabled'     => $mailcowEnabled,
            'nextcloud_enabled'   => $nextcloudEnabled,
        ]);

        AuditLogger::log('realm.updated', $realm);
        return redirect()->route('super.realms.edit', $realm)->with('success', "Settings saved for '{$realm}'.");
    }

    // ── Realm delete ──────────────────────────────────────────────────────────

    public function destroy(Request $request, string $realm)
    {
        try {
            $token = $this->adminToken();
        } catch (\RuntimeException $e) {
            return redirect()->route('super.realms.edit', $realm)->withErrors(['error' => $e->getMessage()]);
        }

        $res = \Http::withToken($token)->delete("{$this->baseUrl()}/admin/realms/{$realm}");

        if ($res->failed()) {
            return back()->withErrors(['realm' => 'Failed to delete realm.']);
        }

        DomainRealmMap::where('realm', $realm)->delete();
        AuditLogger::log('realm.deleted', $realm, $request->boolean('delete_mailcow') ? 'Mailcow domain also deleted' : null);

        if ($request->boolean('delete_mailcow') && Setting::get('mailcow.url', config('mailcow.url')) && Setting::get('mailcow.api_key', config('mailcow.api_key'))) {
            \Http::withHeaders($this->mailcowHeaders($realm))->post("{$this->mailcowBase($realm)}/delete/domain", [$realm]);
        }

        // Remove the IdP and Organization from the broker realm so @{realm} addresses no
        // longer route anywhere. Non-fatal — realm is already deleted from Keycloak.
        $brokerRealm = config('keycloak.broker_realm');
        if ($brokerRealm) {
            $kcBase = $this->baseUrl();
            // Delete the Organization (also unlinks the IdP automatically)
            $orgs = \Http::withToken($token)->get("{$kcBase}/admin/realms/{$brokerRealm}/organizations", ['search' => $realm])->json();
            $org  = collect((array) $orgs)->firstWhere('alias', $realm);
            if ($org) {
                \Http::withToken($token)->delete("{$kcBase}/admin/realms/{$brokerRealm}/organizations/{$org['id']}");
            }
            // Delete the IdP instance itself from the broker realm
            \Http::withToken($token)->delete("{$kcBase}/admin/realms/{$brokerRealm}/identity-provider/instances/{$realm}");
        }

        return redirect()->route('super.realms')->with('success', "Realm '{$realm}' deleted.");
    }
}
