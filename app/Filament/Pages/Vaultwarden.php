<?php

namespace App\Filament\Pages;

use BackedEnum;

use App\Models\Setting;
use App\Services\AuditLogger;
use App\Services\KumaService;
use App\Services\VaultwardenService;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Http;

class Vaultwarden extends Page
{
    protected static string|BackedEnum|null $navigationIcon  = 'heroicon-o-lock-closed';
    protected static ?string $navigationLabel = 'Vaultwarden';
    protected static ?string $title           = 'Vaultwarden SSO';
    protected static ?int    $navigationSort  = 7;
    protected string $view            = 'filament.pages.vaultwarden';

    public bool   $ssoWired   = false;
    public bool   $reachable  = false;
    public string $vwUrl      = '';
    public string $kcClient   = '';
    public string $authority  = '';
    public string $adminToken = '';

    public function mount(): void
    {
        $this->vwUrl    = config('vaultwarden.url', '');
        $this->ssoWired = Setting::get('vaultwarden.sso_wired') === '1';
        $this->kcClient = Setting::get('vaultwarden.sso_client_id', 'vaultwarden');
        $this->authority= Setting::get('vaultwarden.sso_authority', '');

        try {
            $internal    = Setting::get('vaultwarden.internal_url', config('vaultwarden.internal_url', 'http://vaultwarden:80'));
            $this->reachable = Http::timeout(5)->get(rtrim($internal, '/') . '/')->successful();
        } catch (\Throwable) {}
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('wireSso')
                ->label('Wire SSO to Keycloak')
                ->icon('heroicon-o-link')
                ->color('primary')
                ->visible(! $this->ssoWired)
                ->form([
                    TextInput::make('admin_token')
                        ->label('Vaultwarden admin token')
                        ->password()
                        ->revealable()
                        ->required()
                        ->helperText('Found in /admin on the Vaultwarden panel, or set via ADMIN_TOKEN env var.'),
                ])
                ->action(fn (array $data) => $this->wireSso($data['admin_token'])),
        ];
    }

    private function wireSso(string $adminToken): void
    {
        Setting::set('vaultwarden.admin_token', $adminToken, true);
        Setting::set('vaultwarden.internal_url', 'http://vaultwarden:80');

        try {
            $kcBase      = rtrim(config('keycloak.base_url'), '/');
            $kcToken     = $this->kcAdminToken();
            $brokerRealm = config('keycloak.broker_realm');
            $vwUrl       = config('vaultwarden.url');
            $secret      = bin2hex(random_bytes(20));

            $clients  = Http::withToken($kcToken)->get("{$kcBase}/admin/realms/{$brokerRealm}/clients")->json();
            $existing = collect((array) $clients)->firstWhere('clientId', 'vaultwarden');
            if ($existing) {
                Http::withToken($kcToken)->delete("{$kcBase}/admin/realms/{$brokerRealm}/clients/{$existing['id']}");
            }

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
                Notification::make()->title('Keycloak client creation failed: ' . $res->body())->danger()->send();
                return;
            }

            (new VaultwardenService())->pushConfig([
                'sso_authority'     => "{$kcBase}/realms/{$brokerRealm}",
                'sso_client_id'     => 'vaultwarden',
                'sso_client_secret' => $secret,
            ]);

            Setting::set('vaultwarden.sso_wired', '1');
            Setting::set('vaultwarden.sso_client_id', 'vaultwarden');
            Setting::set('vaultwarden.sso_authority', "{$kcBase}/realms/{$brokerRealm}");

            try { (new KumaService())->addMonitor('Vaultwarden', $vwUrl); } catch (\Throwable) {}

            AuditLogger::log('vaultwarden.sso_wired');
            Notification::make()->title('Vaultwarden SSO wired to Keycloak broker realm.')->success()->send();
            $this->mount();

        } catch (\Throwable $e) {
            Notification::make()->title('Failed: ' . $e->getMessage())->danger()->send();
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
