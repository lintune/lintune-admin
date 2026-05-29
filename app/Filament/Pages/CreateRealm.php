<?php

namespace App\Filament\Pages;

use App\Models\DomainRealmMap;
use App\Models\Setting;
use App\Services\AuditLogger;
use App\Services\VaultwardenService;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class CreateRealm extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationLabel = 'Create Realm';
    protected static bool    $shouldRegisterNavigation = false;
    protected string $view = 'filament.pages.create-realm';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make('Realm')->schema([
                TextInput::make('realm')
                    ->label('Realm domain (e.g. company.com)')
                    ->required()
                    ->regex('/^[a-zA-Z0-9_.\-]+$/')
                    ->helperText('Must be a valid domain. This becomes the tenant identifier.'),
            ]),
            Section::make('Initial Admin Account')->columns(2)->schema([
                TextInput::make('admin_firstname')->label('First name')->required(),
                TextInput::make('admin_lastname')->label('Last name')->required(),
                TextInput::make('admin_local_part')
                    ->label('Email local part')
                    ->required()
                    ->regex('/^[a-zA-Z0-9_.\-]+$/')
                    ->helperText('Will become local_part@{realm}'),
                TextInput::make('admin_password')
                    ->label('Password')
                    ->password()->revealable()
                    ->required()->minLength(8),
            ]),
        ])->statePath('data');
    }

    public function create(): void
    {
        $data = $this->form->getState();

        $realm      = strtolower(trim($data['realm']));
        $adminEmail = strtolower(trim($data['admin_local_part'])) . '@' . $realm;
        $base       = rtrim(config('keycloak.base_url'), '/');
        $dashUrl    = rtrim(config('keycloak.dash_url', ''), '/');

        try {
            $token = $this->kcToken();
        } catch (\RuntimeException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
            return;
        }

        // Create realm
        $realmRes = \Http::withToken($token)->post("{$base}/admin/realms", [
            'realm'      => $realm,
            'enabled'    => true,
            'loginTheme' => 'lintune',
        ]);
        if ($realmRes->failed()) {
            Notification::make()->title('Failed to create realm: ' . $realmRes->body())->danger()->send();
            return;
        }

        // Create lintune-dash client in the tenant realm
        \Http::withToken($token)->post("{$base}/admin/realms/{$realm}/clients", [
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

        // Create admin user
        $userRes = \Http::withToken($token)->post("{$base}/admin/realms/{$realm}/users", [
            'username'      => $adminEmail,
            'email'         => $adminEmail,
            'firstName'     => trim($data['admin_firstname']),
            'lastName'      => trim($data['admin_lastname']),
            'enabled'       => true,
            'emailVerified' => true,
            'credentials'   => [['type' => 'password', 'value' => $data['admin_password'], 'temporary' => false]],
        ]);

        if ($userRes->successful()) {
            $userId  = basename($userRes->header('Location'));
            $clients = \Http::withToken($token)->get("{$base}/admin/realms/{$realm}/clients")->json();
            $mgmt    = collect($clients)->firstWhere('clientId', 'realm-management');
            if ($mgmt) {
                $roles     = \Http::withToken($token)->get("{$base}/admin/realms/{$realm}/clients/{$mgmt['id']}/roles")->json();
                $adminRole = collect($roles)->firstWhere('name', 'realm-admin');
                if ($adminRole) {
                    \Http::withToken($token)->post(
                        "{$base}/admin/realms/{$realm}/users/{$userId}/role-mappings/clients/{$mgmt['id']}",
                        [$adminRole]
                    );
                }
            }
        }

        // DB record
        DomainRealmMap::updateOrCreate(
            ['domain' => $realm],
            ['realm' => $realm, 'mailcow_enabled' => false, 'nextcloud_enabled' => false]
        );

        // Broker federation
        $brokerRealm = config('keycloak.broker_realm');
        if ($brokerRealm) {
            $this->setupBrokerFederation($base, $token, $realm, $brokerRealm);
        }

        // Vaultwarden whitelist
        if (Setting::get('vaultwarden.sso_wired') === '1') {
            try { (new VaultwardenService())->addDomainToWhitelist($realm); } catch (\Throwable) {}
        }

        AuditLogger::log('realm.created', $realm);
        Notification::make()->title("Realm '{$realm}' created.")->success()->send();

        $this->redirect(route('filament.super.pages.realms'));
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
        $secret    = \Http::withToken($token)->get("{$base}/admin/realms/{$realm}/clients/{$clientId}/client-secret")->json()['value'] ?? null;
        if (! $secret) return;

        \Http::withToken($token)->post("{$base}/admin/realms/{$realm}/groups", ['name' => 'nextcloud']);

        \Http::withToken($token)->post("{$base}/admin/realms/{$realm}/clients/{$clientId}/protocol-mappers/models", [
            'name' => 'groups', 'protocol' => 'openid-connect', 'protocolMapper' => 'oidc-group-membership-mapper',
            'config' => ['full.path' => 'false', 'id.token.claim' => 'true', 'access.token.claim' => 'true', 'userinfo.token.claim' => 'true', 'claim.name' => 'groups'],
        ]);

        $orgRes = \Http::withToken($token)->post("{$base}/admin/realms/{$brokerRealm}/organizations", [
            'name' => $realm, 'alias' => $realm,
            'domains' => [['name' => $realm, 'verified' => false]],
            'enabled' => true, 'redirectMode' => 'EMAIL_DOMAIN',
        ]);
        $orgId = null;
        if ($orgRes->successful()) {
            $orgId = basename(rtrim($orgRes->header('Location'), '/'));
            if (! $orgId || strlen($orgId) < 10) {
                $orgs  = \Http::withToken($token)->get("{$base}/admin/realms/{$brokerRealm}/organizations", ['search' => $realm])->json();
                $orgId = collect((array) $orgs)->firstWhere('alias', $realm)['id'] ?? null;
            }
        }

        $idpPayload = [
            'alias' => $realm, 'displayName' => $realm, 'providerId' => 'oidc',
            'enabled' => true, 'trustEmail' => true, 'hideOnLogin' => true,
            'firstBrokerLoginFlowAlias' => 'first broker login',
            'config' => [
                'clientId' => 'broker-realm-client', 'clientSecret' => $secret,
                'authorizationUrl' => "{$base}/realms/{$realm}/protocol/openid-connect/auth",
                'tokenUrl'         => "{$base}/realms/{$realm}/protocol/openid-connect/token",
                'jwksUrl'          => "{$base}/realms/{$realm}/protocol/openid-connect/certs",
                'logoutUrl'        => "{$base}/realms/{$realm}/protocol/openid-connect/logout",
                'userInfoUrl'      => "{$base}/realms/{$realm}/protocol/openid-connect/userinfo",
                'issuer'           => "{$base}/realms/{$realm}",
                'validateSignature' => 'true', 'useJwksUrl' => 'true', 'pkceEnabled' => 'false',
                'syncMode' => 'IMPORT', 'loginHint' => 'true', 'disableUserInfo' => 'true',
                'kc.org.domain' => $realm, 'kc.org.broker.redirect.mode.email-matches' => 'true',
            ],
        ];

        $idpRes = \Http::withToken($token)->post("{$base}/admin/realms/{$brokerRealm}/identity-provider/instances", $idpPayload);
        if ($idpRes->failed()) return;

        if ($orgId) {
            \Http::withToken($token)->withBody(json_encode($realm), 'application/json')
                ->post("{$base}/admin/realms/{$brokerRealm}/organizations/{$orgId}/identity-providers");

            $idpCurrent = \Http::withToken($token)->get("{$base}/admin/realms/{$brokerRealm}/identity-provider/instances/{$realm}")->json();
            if (! empty($idpCurrent)) {
                $idpCurrent['config']['kc.org.domain']                             = $realm;
                $idpCurrent['config']['kc.org.broker.redirect.mode.email-matches'] = 'true';
                $idpCurrent['config']['clientSecret']                               = $secret;
                \Http::withToken($token)->put("{$base}/admin/realms/{$brokerRealm}/identity-provider/instances/{$realm}", $idpCurrent);
            }
        }

        $upConfig = \Http::withToken($token)->get("{$base}/admin/realms/{$brokerRealm}/users/profile")->json();
        $upAttrs  = $upConfig['attributes'] ?? [];
        if (! collect($upAttrs)->contains('name', 'nc_groups')) {
            $upAttrs[]              = ['name' => 'nc_groups', 'multivalued' => true, 'permissions' => ['view' => ['admin'], 'edit' => ['admin']]];
            $upConfig['attributes'] = $upAttrs;
            \Http::withToken($token)->put("{$base}/admin/realms/{$brokerRealm}/users/profile", $upConfig);
        }

        \Http::withToken($token)->post("{$base}/admin/realms/{$brokerRealm}/identity-provider/instances/{$realm}/mappers", [
            'name' => 'nc_groups', 'identityProviderAlias' => $realm,
            'identityProviderMapper' => 'oidc-user-attribute-idp-mapper',
            'config' => ['syncMode' => 'FORCE', 'claim' => 'groups', 'user.attribute' => 'nc_groups', 'are.claim.values.regex' => 'false'],
        ]);
    }

    private function kcToken(): string
    {
        $base = rtrim(config('keycloak.base_url'), '/');
        $res  = \Http::timeout(10)->asForm()->post("{$base}/realms/master/protocol/openid-connect/token", [
            'grant_type' => 'password', 'client_id' => 'admin-cli',
            'username'   => config('keycloak.admin_user'),
            'password'   => decrypt(base64_decode(config('keycloak.admin_password'))),
        ]);
        if ($res->failed() || empty($res->json()['access_token'])) {
            throw new \RuntimeException('Cannot reach Keycloak: check KEYCLOAK_ADMIN_USER/PASSWORD in .env.');
        }
        return $res->json()['access_token'];
    }
}
