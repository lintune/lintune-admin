<x-filament-panels::page>
    <form wire:submit="save" class="space-y-6">
        {{ $this->form }}
        <div class="flex justify-end">
            <x-filament::button type="submit">Save</x-filament::button>
        </div>
    </form>

    @if($publicKey)
        <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-6 mt-6">
            <h3 class="text-base font-semibold text-gray-950 dark:text-white mb-3">SSH Public Key</h3>
            <p class="text-sm text-gray-500 dark:text-gray-400 mb-3">Add this to your backup destination's <code>~/.ssh/authorized_keys</code>.</p>
            <pre class="rounded-lg bg-gray-100 dark:bg-gray-800 p-4 text-xs font-mono text-gray-800 dark:text-gray-200 overflow-x-auto whitespace-pre-wrap break-all">{{ $publicKey }}</pre>
        </div>
    @endif

    <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-6 mt-6">
        <h3 class="text-base font-semibold text-gray-950 dark:text-white mb-2">Last Backup Status</h3>
        <p class="text-sm text-gray-600 dark:text-gray-400">{{ $lastStatus }}</p>
    </div>
</x-filament-panels::page>
