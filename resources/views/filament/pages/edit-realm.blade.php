<x-filament-panels::page>

    {{-- Limits --}}
    <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <div class="fi-section-header px-6 py-4 border-b border-gray-200 dark:border-white/10">
            <h3 class="text-base font-semibold text-gray-950 dark:text-white">User Limits</h3>
        </div>
        <div class="px-6 py-4">
            <form wire:submit="saveLimits" class="space-y-4">
                {{ $this->limitsForm }}
                <div class="flex justify-end">
                    <x-filament::button type="submit">Save Limits</x-filament::button>
                </div>
            </form>
        </div>
    </div>

    {{-- Mailcow --}}
    @if($mailcowConfigured)
    <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <div class="fi-section-header px-6 py-4 border-b border-gray-200 dark:border-white/10">
            <h3 class="text-base font-semibold text-gray-950 dark:text-white">Mailcow</h3>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">
                Domain: <span class="{{ $mailcowExists ? 'text-success-600' : 'text-gray-400' }}">{{ $mailcowExists ? 'Provisioned' : 'Not provisioned' }}</span>
            </p>
        </div>
        <div class="px-6 py-4">
            <form wire:submit="saveMailcow" class="space-y-4">
                {{ $this->mailcowForm }}
                <div class="flex justify-end">
                    <x-filament::button type="submit">Save Mailcow</x-filament::button>
                </div>
            </form>
        </div>
    </div>
    @endif

    {{-- Nextcloud --}}
    @if($nextcloudConfigured)
    <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <div class="fi-section-header px-6 py-4 border-b border-gray-200 dark:border-white/10">
            <h3 class="text-base font-semibold text-gray-950 dark:text-white">Nextcloud</h3>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">
                Group: <span class="{{ $nextcloudExists ? 'text-success-600' : 'text-gray-400' }}">{{ $nextcloudExists ? 'Provisioned' : 'Not provisioned' }}</span>
            </p>
        </div>
        <div class="px-6 py-4">
            <form wire:submit="saveNextcloud" class="space-y-4">
                {{ $this->nextcloudForm }}
                <div class="flex justify-end">
                    <x-filament::button type="submit">Save Nextcloud</x-filament::button>
                </div>
            </form>
        </div>
    </div>
    @endif

    <div class="mt-2">
        <a href="{{ route('filament.super.pages.realms') }}" class="text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">
            ← Back to Realms
        </a>
    </div>

</x-filament-panels::page>
