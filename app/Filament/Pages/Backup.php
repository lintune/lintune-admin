<?php

namespace App\Filament\Pages;

use BackedEnum;

use App\Services\AuditLogger;
use App\Services\BackupService;
use Filament\Actions\Action;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class Backup extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon  = 'heroicon-o-archive-box';
    protected static ?string $navigationLabel = 'Backup';
    protected static ?string $title           = 'Backup';
    protected static ?int    $navigationSort  = 6;
    protected string $view            = 'filament.pages.backup';

    public ?array $data = [];
    public string $publicKey   = '';
    public string $lastStatus  = '';

    public function mount(): void
    {
        $backup = new BackupService();

        $this->publicKey  = $backup->getPublicKey() ?? '';
        $this->lastStatus = $backup->getLastStatus() ?? 'No backup run yet.';

        $this->form->fill([
            'enabled'  => $backup->isEnabled(),
            'schedule' => $backup->getSchedule() ?? '0 3 * * *',
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $form
            ->schema([
                Section::make('Schedule')->schema([
                    Toggle::make('enabled')->label('Enable automated backups'),
                    TextInput::make('schedule')
                        ->label('Cron schedule')
                        ->helperText('Standard cron format: minute hour day month weekday')
                        ->regex('/^(\S+\s+){4}\S+$/')
                        ->required(),
                ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data   = $this->form->getState();
        $backup = new BackupService();

        $backup->setEnabled((bool) $data['enabled']);
        $backup->setSchedule($data['schedule']);

        AuditLogger::log('backup.settings_updated');
        Notification::make()->title('Backup settings saved.')->success()->send();
    }

    public function triggerNow(): void
    {
        (new BackupService())->triggerNow();
        AuditLogger::log('backup.triggered_manually');
        Notification::make()->title('Backup started.')->success()->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('triggerNow')
                ->label('Run Backup Now')
                ->icon('heroicon-o-play')
                ->color('warning')
                ->requiresConfirmation()
                ->action(fn () => $this->triggerNow()),
        ];
    }
}
