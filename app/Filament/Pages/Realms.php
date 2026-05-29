<?php

namespace App\Filament\Pages;

use BackedEnum;

use App\Models\DomainRealmMap;
use App\Models\Mailbox;
use App\Models\Setting;
use App\Services\AuditLogger;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Actions\Action as TableAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Collection;

class Realms extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon  = 'heroicon-o-globe-alt';
    protected static ?string $navigationLabel = 'Realms';
    protected static ?string $title           = 'Realms';
    protected static ?int    $navigationSort  = 1;
    protected string $view            = 'filament.pages.realms';

    // ── helpers ───────────────────────────────────────────────────────────────

    private function kcToken(): string
    {
        $base = rtrim(config('keycloak.base_url'), '/');
        $res  = \Http::timeout(10)->asForm()->post("{$base}/realms/master/protocol/openid-connect/token", [
            'grant_type' => 'password',
            'client_id'  => 'admin-cli',
            'username'   => config('keycloak.admin_user'),
            'password'   => decrypt(base64_decode(config('keycloak.admin_password'))),
        ]);
        if ($res->failed() || empty($res->json()['access_token'])) {
            throw new \RuntimeException('Cannot reach Keycloak: check KEYCLOAK_ADMIN_USER/PASSWORD in .env.');
        }
        return $res->json()['access_token'];
    }

    private function fetchRealms(): Collection
    {
        try {
            $token = $this->kcToken();
        } catch (\RuntimeException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
            return collect();
        }

        $base         = rtrim(config('keycloak.base_url'), '/');
        $systemRealms = ['master', config('keycloak.broker_realm')];

        $kcRealms = collect(\Http::withToken($token)->get("{$base}/admin/realms")->json() ?? [])
            ->filter(fn ($r) => ! in_array($r['realm'], $systemRealms))
            ->values();

        $realmNames = $kcRealms->pluck('realm')->all();
        $domainMaps = DomainRealmMap::whereIn('realm', $realmNames)->get()->keyBy('realm');
        $mailboxCounts = Mailbox::whereIn('realm', $realmNames)
            ->selectRaw('realm, count(*) as total')
            ->groupBy('realm')
            ->pluck('total', 'realm');

        $keycloakCounts = [];
        foreach ($realmNames as $r) {
            $res = \Http::withToken($token)->get("{$base}/admin/realms/{$r}/users/count");
            $keycloakCounts[$r] = $res->successful() ? (int) $res->body() : 0;
        }

        $nextcloudCounts = [];
        foreach ($realmNames as $r) {
            if (! $domainMaps->get($r)?->nextcloud_enabled) continue;
            try {
                $groups  = \Http::withToken($token)->get("{$base}/admin/realms/{$r}/groups", ['search' => 'nextcloud'])->json();
                $ncGroup = collect((array) $groups)->firstWhere('name', 'nextcloud');
                if ($ncGroup) {
                    $members             = \Http::withToken($token)->get("{$base}/admin/realms/{$r}/groups/{$ncGroup['id']}/members", ['max' => 1000])->json();
                    $nextcloudCounts[$r] = count((array) $members);
                }
            } catch (\Throwable) {}
        }

        return $kcRealms->map(fn ($r) => array_merge($r, [
            'map'             => $domainMaps->get($r['realm']),
            'user_count'      => $keycloakCounts[$r['realm']] ?? 0,
            'mailbox_count'   => $mailboxCounts[$r['realm']] ?? 0,
            'nextcloud_count' => $nextcloudCounts[$r['realm']] ?? null,
        ]));
    }

    // ── table ─────────────────────────────────────────────────────────────────

    public function table(Table $table): Table
    {
        return $table
            ->records($this->fetchRealms())
            ->recordKey('realm')
            ->columns([
                TextColumn::make('realm')->label('Realm')->searchable(isIndividual: false),
                IconColumn::make('enabled')->boolean(),
                TextColumn::make('user_count')->label('Users'),
                TextColumn::make('mailbox_count')->label('Mailboxes'),
                TextColumn::make('nextcloud_count')->label('Nextcloud')->placeholder('—'),
                IconColumn::make('map.mailcow_enabled')->label('Mailcow')->boolean(),
                IconColumn::make('map.nextcloud_enabled')->label('Nextcloud enabled')->boolean(),
            ])
            ->actions([
                TableAction::make('edit')
                    ->label('Edit')
                    ->icon('heroicon-o-pencil-square')
                    ->url(fn (array $record) => route('filament.super.pages.edit-realm', ['realm' => $record['realm']])),
                TableAction::make('toggle')
                    ->label(fn (array $record) => ($record['enabled'] ?? false) ? 'Disable' : 'Enable')
                    ->icon(fn (array $record) => ($record['enabled'] ?? false) ? 'heroicon-o-pause' : 'heroicon-o-play')
                    ->requiresConfirmation()
                    ->action(fn (array $record) => $this->toggleRealm($record['realm'], $record['enabled'] ?? false)),
            ])
            ->headerActions([
                \Filament\Tables\Actions\Action::make('create')
                    ->label('New Realm')
                    ->icon('heroicon-o-plus')
                    ->url(route('filament.super.pages.create-realm')),
            ]);
    }

    // ── actions ───────────────────────────────────────────────────────────────

    private function toggleRealm(string $realm, bool $currentlyEnabled): void
    {
        try {
            $token   = $this->kcToken();
            $base    = rtrim(config('keycloak.base_url'), '/');
            $enabled = ! $currentlyEnabled;

            $res = \Http::withToken($token)->put("{$base}/admin/realms/{$realm}", ['enabled' => $enabled]);

            if ($res->failed()) {
                Notification::make()->title('Failed to update realm status.')->danger()->send();
                return;
            }

            $status = $enabled ? 'enabled' : 'disabled';
            AuditLogger::log("realm.{$status}", $realm);
            Notification::make()->title("Realm '{$realm}' {$status}.")->success()->send();
        } catch (\RuntimeException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }
}
