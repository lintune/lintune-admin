<?php

namespace App\Filament\Pages;

use BackedEnum;

use App\Models\Setting;
use App\Services\AuditLogger;
use App\Services\KumaService;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class Settings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon  = 'heroicon-o-cog-6-tooth';
    protected static ?string $navigationLabel = 'Settings';
    protected static ?string $title           = 'Settings';
    protected static ?int    $navigationSort  = 4;
    protected string $view            = 'filament.pages.settings';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'keycloak_url'      => Setting::get('keycloak.url', config('keycloak.base_url', '')),
            'mailcow_url'       => Setting::get('mailcow.url', config('mailcow.url', '')),
            'mailcow_api_key'   => Setting::get('mailcow.api_key') ? '••••••••' : '',
            'mailcow_mailboxes' => Setting::get('mailcow.default_mailboxes', 10),
            'mailcow_aliases'   => Setting::get('mailcow.default_aliases', 10),
            'mailcow_maxquota'  => round(Setting::get('mailcow.default_maxquota', 10240) / 1024, 2),
            'mailcow_quota'     => round(Setting::get('mailcow.default_quota', 102400) / 1024, 2),
            'nextcloud_url'     => Setting::get('nextcloud.url', ''),
            'nextcloud_user'    => Setting::get('nextcloud.service_user', ''),
            'nextcloud_password'=> Setting::get('nextcloud.service_password') ? '••••••••' : '',
            'nextcloud_quota'   => Setting::get('nextcloud.default_quota', 10),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $form
            ->schema([
                Section::make('Keycloak')->schema([
                    TextInput::make('keycloak_url')->label('Base URL')->url()->required(),
                ])->columns(1),

                Section::make('Mailcow')->schema([
                    TextInput::make('mailcow_url')->label('Base URL')->url()->required(),
                    TextInput::make('mailcow_api_key')->label('API Key')->password()->revealable()->placeholder('Leave blank to keep current'),
                    TextInput::make('mailcow_mailboxes')->label('Default max mailboxes')->numeric()->minValue(1)->required(),
                    TextInput::make('mailcow_aliases')->label('Default max aliases')->numeric()->minValue(0)->required(),
                    TextInput::make('mailcow_maxquota')->label('Default per-mailbox quota (GB)')->numeric()->minValue(0.1)->required(),
                    TextInput::make('mailcow_quota')->label('Default domain quota (GB)')->numeric()->minValue(0.1)->required(),
                ])->columns(2),

                Section::make('Nextcloud')->schema([
                    TextInput::make('nextcloud_url')->label('Base URL')->url(),
                    TextInput::make('nextcloud_user')->label('Service account user'),
                    TextInput::make('nextcloud_password')->label('Service account password')->password()->revealable()->placeholder('Leave blank to keep current'),
                    TextInput::make('nextcloud_quota')->label('Default user quota (GB)')->numeric()->minValue(0.1)->required(),
                ])->columns(2),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        Setting::set('keycloak.url', rtrim($data['keycloak_url'], '/'));
        Setting::set('mailcow.url', rtrim($data['mailcow_url'], '/'));

        if (filled($data['mailcow_api_key']) && $data['mailcow_api_key'] !== '••••••••') {
            Setting::set('mailcow.api_key', $data['mailcow_api_key'], true);
        }

        Setting::set('mailcow.default_mailboxes', (int) $data['mailcow_mailboxes']);
        Setting::set('mailcow.default_aliases',   (int) $data['mailcow_aliases']);
        Setting::set('mailcow.default_maxquota',  (int) round($data['mailcow_maxquota'] * 1024));
        Setting::set('mailcow.default_quota',     (int) round($data['mailcow_quota'] * 1024));

        if (filled($data['nextcloud_url']))  Setting::set('nextcloud.url', rtrim($data['nextcloud_url'], '/'));
        if (filled($data['nextcloud_user'])) Setting::set('nextcloud.service_user', trim($data['nextcloud_user']));
        if (filled($data['nextcloud_password']) && $data['nextcloud_password'] !== '••••••••') {
            Setting::set('nextcloud.service_password', $data['nextcloud_password'], true);
        }
        Setting::set('nextcloud.default_quota', $data['nextcloud_quota']);

        AuditLogger::log('settings.updated');

        try {
            $kuma = new KumaService();
            $kuma->addMonitor('Keycloak', rtrim($data['keycloak_url'], '/') . '/realms/master');
            $kuma->addMonitor('Mailcow', rtrim($data['mailcow_url'], '/'));
            if (filled($data['nextcloud_url'])) {
                $kuma->addMonitor('Nextcloud', rtrim($data['nextcloud_url'], '/'));
            }
        } catch (\Throwable) {}

        Notification::make()->title('Settings saved.')->success()->send();

        // Reload so masked fields refresh
        $this->mount();
    }
}
