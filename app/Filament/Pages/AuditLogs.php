<?php

namespace App\Filament\Pages;

use BackedEnum;

use App\Models\AuditLog;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AuditLogs extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon  = 'heroicon-o-clipboard-document-list';
    protected static ?string $navigationLabel = 'Audit Log';
    protected static ?string $title           = 'Audit Log';
    protected static ?int    $navigationSort  = 5;
    protected string $view            = 'filament.pages.audit-logs';

    public function table(Table $table): Table
    {
        return $table
            ->query(AuditLog::query()->latest('created_at'))
            ->columns([
                TextColumn::make('created_at')->label('Time')->dateTime('d M Y H:i')->sortable(),
                TextColumn::make('app')->badge()->color('gray'),
                TextColumn::make('username')->searchable()->label('User'),
                TextColumn::make('action')->searchable()->badge()->color('info'),
                TextColumn::make('detail')->limit(60)->tooltip(fn ($record) => $record->detail)->placeholder('—'),
            ])
            ->filters([
                Filter::make('app')
                    ->form([
                        Select::make('app')
                            ->options(['admin' => 'Admin', 'dash' => 'Dash'])
                            ->placeholder('All apps'),
                    ])
                    ->query(fn (Builder $q, array $data) =>
                        $q->when($data['app'], fn ($q, $v) => $q->where('app', $v))
                    ),
                Filter::make('username')
                    ->form([TextInput::make('username')->placeholder('Search user…')])
                    ->query(fn (Builder $q, array $data) =>
                        $q->when($data['username'], fn ($q, $v) => $q->where('username', 'like', "%{$v}%"))
                    ),
                Filter::make('action')
                    ->form([TextInput::make('action')->placeholder('e.g. realm.created')])
                    ->query(fn (Builder $q, array $data) =>
                        $q->when($data['action'], fn ($q, $v) => $q->where('action', 'like', "%{$v}%"))
                    ),
                Filter::make('date')
                    ->form([DatePicker::make('date')])
                    ->query(fn (Builder $q, array $data) =>
                        $q->when($data['date'], fn ($q, $v) => $q->whereDate('created_at', $v))
                    ),
            ])
            ->paginated([50, 100])
            ->defaultPaginationPageOption(50);
    }
}
