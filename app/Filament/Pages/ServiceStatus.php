<?php

namespace App\Filament\Pages;

use BackedEnum;

use App\Services\KumaService;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Cache;

class ServiceStatus extends Page
{
    protected static string|BackedEnum|null $navigationIcon  = 'heroicon-o-signal';
    protected static ?string $navigationLabel = 'Services';
    protected static ?string $title           = 'Service Status';
    protected static ?int    $navigationSort  = 2;
    protected string $view            = 'filament.pages.service-status';

    public array $monitors = [];

    public function mount(): void
    {
        $this->load();
    }

    public function load(): void
    {
        try {
            $this->monitors = Cache::remember('kuma.status.full', 30, fn () => (new KumaService())->getStatus());
        } catch (\Throwable) {
            $this->monitors = [];
        }
    }
}
