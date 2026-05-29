<?php

namespace App\Filament\Pages;

use App\Models\DomainRealmMap;
use App\Models\NextcloudUser;
use App\Models\RealmConfig;
use App\Models\Setting;
use App\Services\AuditLogger;
use App\Services\VaultwardenService;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class EditRealm extends Page implements HasForms
{
    use InteractsWithForms;

    protected static bool   $shouldRegisterNavigation = false;
    protected string $view = 'filament.pages.edit-realm';

    public string $realm = '';
    public ?array $limitsData    = [];
    public ?array $mailcowData   = [];
    public ?array $nextcloudData = [];

    // Runtime state loaded from KC + Mailcow + NC APIs
    public bool $mailcowConfigured   = false;
    public bool $nextcloudConfigured = false;
    public bool $mailcowExists       = false;
    public bool $nextcloudExists     = false;

    public function mount(string $realm): void
    {
        $this->realm = $realm;
        $map = DomainRealmMap::where('realm', $realm)->firstOrFail();

        $this->mailcowConfigured   = (bool) Setting::get('mailcow.url', config('mailcow.url'));
        $this->nextcloudConfigured = (bool) Setting::get('nextcloud.url');

        // Limits form
        $this->limitsForm->fill([
            'max_users'           => $map->max_users,
            'max_mailbox_users'   => $map->max_mailbox_users,
            'max_nextcloud_users' => $map->max_nextcloud_users,
        ]);

        // Mailcow form
        $mc = $this->mailcowValues($realm);
        $this->mailcowExists = $mc['exists'];
        $this->mailcowForm->fill([
            'mailcow_enabled'  => (bool) $map->mailcow_enabled,
            'mailboxes'        => $mc['mailboxes'],
            'aliases'          => $mc['aliases'],
            'maxquota'         => $mc['maxquota'],
            'quota'            => $mc['quota'],
            'custom_url'       => $mc['custom_url'],
            'custom_api_key'   => $mc['custom_api_key'],
        ]);

        // Nextcloud form
        $nc = $this->nextcloudValues($realm);
        $this->nextcloudExists = $nc['exists'];
        $this->nextcloudForm->fill([
            'nextcloud_enabled'       => (bool) $map->nextcloud_enabled,
            'custom_url'              => $nc['custom_url'],
            'custom_service_user'     => $nc['custom_service_user'],
            'custom_service_password' => '',
        ]);
    }

    // ── forms ─────────────────────────────────────────────────────────────────

    protected function getForms(): array
    {
        return ['limitsForm', 'mailcowForm', 'nextcloudForm'];
    }

    public function limitsForm(Schema $schema): Schema
    {
        return $schema->schema([
            TextInput::make('max_users')->label('Max users')->numeric()->minValue(1)->nullable(),
            TextInput::make('max_mailbox_users')->label('Max mailboxes')->numeric()->minValue(0)->nullable(),
            TextInput::make('max_nextcloud_users')->label('Max Nextcloud users')->numeric()->minValue(0)->nullable(),
        ])->columns(3)->statePath('limitsData');
    }

    public function mailcowForm(Schema $schema): Schema
    {
        return $schema->schema([
            Toggle::make('mailcow_enabled')->label('Mailcow enabled'),
            TextInput::make('mailboxes')->label('Max mailboxes')->numeric()->minValue(1)->required(),
            TextInput::make('aliases')->label('Max aliases')->numeric()->minValue(0)->required(),
            TextInput::make('maxquota')->label('Per-mailbox quota (GB)')->numeric()->minValue(0.1)->required(),
            TextInput::make('quota')->label('Domain quota (GB)')->numeric()->minValue(0.1)->required(),
            TextInput::make('custom_url')->label('Custom Mailcow URL')->url()->nullable(),
            TextInput::make('custom_api_key')->label('Custom API key')->password()->revealable()
                ->placeholder('Leave blank to keep current')->nullable(),
        ])->columns(2)->statePath('mailcowData');
    }

    public function nextcloudForm(Schema $schema): Schema
    {
        return $schema->schema([
            Toggle::make('nextcloud_enabled')->label('Nextcloud enabled'),
            TextInput::make('custom_url')->label('Custom Nextcloud URL')->url()->nullable(),
            TextInput::make('custom_service_user')->label('Custom service user')->nullable(),
            TextInput::make('custom_service_password')->label('Custom service password')
                ->password()->revealable()->placeholder('Leave blank to keep current')->nullable(),
        ])->columns(2)->statePath('nextcloudData');
    }

    // ── save limits ───────────────────────────────────────────────────────────

    public function saveLimits(): void
    {
        $data = $this->limitsForm->getState();
        DomainRealmMap::where('realm', $this->realm)->update([
            'max_users'           => $data['max_users']           ?: null,
            'max_mailbox_users'   => $data['max_mailbox_users']   ?: null,
            'max_nextcloud_users' => $data['max_nextcloud_users'] ?: null,
        ]);
        AuditLogger::log('realm.limits_updated', $this->realm);
        Notification::make()->title('Limits saved.')->success()->send();
    }

    // ── save mailcow ──────────────────────────────────────────────────────────

    public function saveMailcow(): void
    {
        $data   = $this->mailcowForm->getState();
        $realm  = $this->realm;
        $map    = DomainRealmMap::where('realm', $realm)->firstOrFail();
        $mcBase = $this->mailcowBase($realm);
        $mcHead = $this->mailcowHeaders($realm);

        if (filled($data['custom_url'])) {
            RealmConfig::set($realm, 'mailcow.url', rtrim($data['custom_url'], '/'));
        }
        if (filled($data['custom_api_key']) && $data['custom_api_key'] !== '••••••••') {
            RealmConfig::set($realm, 'mailcow.api_key', $data['custom_api_key'], true);
        }

        if ($data['mailcow_enabled']) {
            $payload = [
                'domain' => $realm, 'active' => '1', 'restart_sogo' => '1',
                'mailboxes' => $data['mailboxes'], 'aliases' => $data['aliases'],
                'maxquota'  => (int) round($data['maxquota'] * 1024),
                'quota'     => (int) round($data['quota'] * 1024),
            ];

            if (! $this->mailcowExists) {
                $res = \Http::withHeaders($mcHead)->post("{$mcBase}/add/domain", $payload);
                if ($res->failed() || ($res->json()[0]['type'] ?? '') === 'error') {
                    Notification::make()->title('Mailcow error: ' . ($res->json()[0]['msg'] ?? $res->body()))->danger()->send();
                    return;
                }
                if (! filled($data['custom_url'])) {
                    RealmConfig::set($realm, 'mailcow.url', Setting::get('mailcow.url', config('mailcow.url')));
                    RealmConfig::set($realm, 'mailcow.api_key', Setting::get('mailcow.api_key', config('mailcow.api_key')), true);
                }
                AuditLogger::log('mailcow.created', $realm);
            } else {
                $res = \Http::withHeaders($mcHead)->post("{$mcBase}/edit/domain", ['attr' => $payload, 'items' => [$realm]]);
                if ($res->failed() || ($res->json()[0]['type'] ?? '') === 'error') {
                    Notification::make()->title('Mailcow error: ' . ($res->json()[0]['msg'] ?? $res->body()))->danger()->send();
                    return;
                }
                AuditLogger::log('mailcow.limits_updated', $realm);
            }
        }

        $map->update(['mailcow_enabled' => $data['mailcow_enabled']]);
        Notification::make()->title('Mailcow settings saved.')->success()->send();
        $this->mount($realm);
    }

    // ── save nextcloud ────────────────────────────────────────────────────────

    public function saveNextcloud(): void
    {
        $data  = $this->nextcloudForm->getState();
        $realm = $this->realm;
        $map   = DomainRealmMap::where('realm', $realm)->firstOrFail();

        if (filled($data['custom_url'])) {
            RealmConfig::set($realm, 'nextcloud.url', rtrim($data['custom_url'], '/'));
        }
        if (filled($data['custom_service_user'])) {
            RealmConfig::set($realm, 'nextcloud.service_user', trim($data['custom_service_user']));
        }
        if (filled($data['custom_service_password'])) {
            RealmConfig::set($realm, 'nextcloud.service_password', $data['custom_service_password'], true);
        }

        if ($data['nextcloud_enabled'] && ! $this->nextcloudExists) {
            [$user, $pass] = $this->nextcloudAuth($realm);
            $ncBase = $this->nextcloudBase($realm);
            $res = \Http::withBasicAuth($user, $pass)
                ->withHeaders(['OCS-APIRequest' => 'true', 'Accept' => 'application/json'])
                ->post("{$ncBase}/cloud/groups", ['groupid' => $realm]);
            if (($res->json()['ocs']['meta']['statuscode'] ?? 0) !== 100) {
                Notification::make()->title('Nextcloud error: ' . ($res->json()['ocs']['meta']['message'] ?? $res->body()))->danger()->send();
                return;
            }
            if (! filled($data['custom_url'])) {
                RealmConfig::set($realm, 'nextcloud.url', Setting::get('nextcloud.url', ''));
            }
            AuditLogger::log('nextcloud.created', $realm);
        }

        $map->update(['nextcloud_enabled' => $data['nextcloud_enabled']]);
        AuditLogger::log('nextcloud.settings_updated', $realm);
        Notification::make()->title('Nextcloud settings saved.')->success()->send();
        $this->mount($realm);
    }

    // ── danger zone actions ───────────────────────────────────────────────────

    protected function getHeaderActions(): array
    {
        return [
            Action::make('repairFederation')
                ->label('Repair Federation')
                ->icon('heroicon-o-wrench-screwdriver')
                ->color('warning')
                ->requiresConfirmation()
                ->action(fn () => $this->repairFederation()),

            Action::make('removeMailcow')
                ->label('Remove Mailcow Domain')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->visible($this->mailcowExists)
                ->requiresConfirmation()
                ->action(fn () => $this->removeMailcow()),

            Action::make('removeNextcloud')
                ->label('Remove Nextcloud Group')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->visible($this->nextcloudExists)
                ->requiresConfirmation()
                ->action(fn () => $this->removeNextcloud()),

            Action::make('deleteRealm')
                ->label('Delete Realm')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->requiresConfirmation()
                ->form([
                    Checkbox::make('delete_mailcow')->label('Also delete Mailcow domain'),
                ])
                ->action(fn (array $data) => $this->deleteRealm($data['delete_mailcow'] ?? false)),
        ];
    }

    private function repairFederation(): void
    {
        // Delegate to the existing SuperRealmController logic via a redirect
        // so we don't duplicate the 10-step repair logic here.
        $this->redirect(route('super.realms.repair-federation', $this->realm));
    }

    private function removeMailcow(): void
    {
        $realm  = $this->realm;
        $mcBase = $this->mailcowBase($realm);
        $mcHead = $this->mailcowHeaders($realm);

        $res = \Http::withHeaders($mcHead)->post("{$mcBase}/delete/domain", [$realm]);
        if ($res->failed() || ($res->json()[0]['type'] ?? '') === 'error') {
            Notification::make()->title('Failed to remove Mailcow domain: ' . ($res->json()[0]['msg'] ?? $res->body()))->danger()->send();
            return;
        }

        DomainRealmMap::where('realm', $realm)->update(['mailcow_enabled' => false]);
        AuditLogger::log('mailcow.removed', $realm);
        Notification::make()->title("Mailcow domain '{$realm}' removed.")->success()->send();
        $this->mount($realm);
    }

    private function removeNextcloud(): void
    {
        $realm = $this->realm;
        [$user, $pass] = $this->nextcloudAuth($realm);
        $ncBase = $this->nextcloudBase($realm);

        $res = \Http::withBasicAuth($user, $pass)
            ->withHeaders(['OCS-APIRequest' => 'true', 'Accept' => 'application/json'])
            ->delete("{$ncBase}/cloud/groups/{$realm}");
        if (($res->json()['ocs']['meta']['statuscode'] ?? 0) !== 100) {
            Notification::make()->title('Failed to remove Nextcloud group: ' . ($res->json()['ocs']['meta']['message'] ?? $res->body()))->danger()->send();
            return;
        }

        DomainRealmMap::where('realm', $realm)->update(['nextcloud_enabled' => false]);
        NextcloudUser::where('realm', $realm)->delete();
        AuditLogger::log('nextcloud.removed', $realm);
        Notification::make()->title("Nextcloud group '{$realm}' removed.")->success()->send();
        $this->mount($realm);
    }

    private function deleteRealm(bool $deleteMailcow): void
    {
        $realm = $this->realm;
        try {
            $token = $this->kcToken();
        } catch (\RuntimeException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
            return;
        }

        $base = rtrim(config('keycloak.base_url'), '/');
        $res  = \Http::withToken($token)->delete("{$base}/admin/realms/{$realm}");
        if ($res->failed()) {
            Notification::make()->title('Failed to delete realm.')->danger()->send();
            return;
        }

        DomainRealmMap::where('realm', $realm)->delete();

        if (Setting::get('vaultwarden.sso_wired') === '1') {
            try { (new VaultwardenService())->removeDomainFromWhitelist($realm); } catch (\Throwable) {}
        }

        AuditLogger::log('realm.deleted', $realm, $deleteMailcow ? 'Mailcow domain also deleted' : null);

        if ($deleteMailcow) {
            $mcBase = $this->mailcowBase($realm);
            $mcHead = $this->mailcowHeaders($realm);
            \Http::withHeaders($mcHead)->post("{$mcBase}/delete/domain", [$realm]);
        }

        $brokerRealm = config('keycloak.broker_realm');
        if ($brokerRealm) {
            $orgs = \Http::withToken($token)->get("{$base}/admin/realms/{$brokerRealm}/organizations", ['search' => $realm])->json();
            $org  = collect((array) $orgs)->firstWhere('alias', $realm);
            if ($org) {
                \Http::withToken($token)->delete("{$base}/admin/realms/{$brokerRealm}/organizations/{$org['id']}");
            }
            \Http::withToken($token)->delete("{$base}/admin/realms/{$brokerRealm}/identity-provider/instances/{$realm}");
        }

        Notification::make()->title("Realm '{$realm}' deleted.")->success()->send();
        $this->redirect(route('filament.super.pages.realms'));
    }

    // ── private helpers ───────────────────────────────────────────────────────

    private function kcToken(): string
    {
        $base = rtrim(config('keycloak.base_url'), '/');
        $res  = \Http::timeout(10)->asForm()->post("{$base}/realms/master/protocol/openid-connect/token", [
            'grant_type' => 'password', 'client_id' => 'admin-cli',
            'username'   => config('keycloak.admin_user'),
            'password'   => decrypt(base64_decode(config('keycloak.admin_password'))),
        ]);
        if ($res->failed() || empty($res->json()['access_token'])) {
            throw new \RuntimeException('Cannot reach Keycloak.');
        }
        return $res->json()['access_token'];
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

    private function mailcowValues(string $realm): array
    {
        $domain = null;
        if ($this->mailcowConfigured) {
            try {
                $res    = \Http::withHeaders($this->mailcowHeaders($realm))->get("{$this->mailcowBase($realm)}/get/domain/{$realm}");
                $exists = $res->successful() && ! empty($res->json()) && ! isset($res->json()['type']);
                $domain = $exists ? $res->json() : null;
            } catch (\Throwable) {}
        }
        return [
            'exists'         => $domain !== null,
            'mailboxes'      => $domain['max_num_mboxes_for_domain']  ?? Setting::get('mailcow.default_mailboxes', 10),
            'aliases'        => $domain['max_num_aliases_for_domain'] ?? Setting::get('mailcow.default_aliases', 10),
            'maxquota'       => isset($domain['max_quota_for_mbox'])    ? round($domain['max_quota_for_mbox']    / 1073741824, 2) : round(Setting::get('mailcow.default_maxquota', 10240) / 1024, 2),
            'quota'          => isset($domain['max_quota_for_domain']) ? round($domain['max_quota_for_domain'] / 1073741824, 2) : round(Setting::get('mailcow.default_quota', 102400) / 1024, 2),
            'custom_url'     => RealmConfig::get($realm, 'mailcow.url')     ?? '',
            'custom_api_key' => RealmConfig::get($realm, 'mailcow.api_key') ? '••••••••' : '',
        ];
    }

    private function nextcloudValues(string $realm): array
    {
        $exists = false;
        if ($this->nextcloudConfigured) {
            try {
                [$user, $pass] = $this->nextcloudAuth($realm);
                $base = $this->nextcloudBase($realm);
                if ($base && $user && $pass) {
                    $res    = \Http::withBasicAuth($user, $pass)->withHeaders(['OCS-APIRequest' => 'true', 'Accept' => 'application/json'])->get("{$base}/cloud/groups/{$realm}");
                    $exists = ($res->json()['ocs']['meta']['statuscode'] ?? 0) === 100;
                }
            } catch (\Throwable) {}
        }
        return [
            'exists'              => $exists,
            'custom_url'          => RealmConfig::get($realm, 'nextcloud.url')          ?? '',
            'custom_service_user' => RealmConfig::get($realm, 'nextcloud.service_user') ?? '',
        ];
    }
}
