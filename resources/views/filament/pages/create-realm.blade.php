<x-filament-panels::page>
    <form wire:submit="create" class="space-y-6">
        {{ $this->form }}
        <div class="flex gap-3 justify-end">
            <a href="{{ route('filament.super.pages.realms') }}"
               class="fi-btn fi-color-gray fi-btn-color-gray fi-size-md fi-btn-size-md rounded-lg px-4 py-2 text-sm font-semibold">
                Cancel
            </a>
            <x-filament::button type="submit">Create Realm</x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
