<x-filament-panels::page>
    <div
        wire:poll.30000ms="load"
        class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10"
    >
        @if(empty($monitors))
            <div class="p-8 text-center text-gray-500 dark:text-gray-400">
                <x-heroicon-o-question-mark-circle class="mx-auto mb-3 h-8 w-8" />
                Status information is not available right now. Is Uptime Kuma running?
            </div>
        @else
            <ul class="divide-y divide-gray-100 dark:divide-white/5">
                @foreach($monitors as $m)
                    @php
                        $color = match((int)($m['status'] ?? -1)) {
                            1 => 'text-success-600 dark:text-success-400',
                            0 => 'text-danger-600 dark:text-danger-400',
                            3 => 'text-warning-600 dark:text-warning-400',
                            default => 'text-gray-400',
                        };
                        $badge = match((int)($m['status'] ?? -1)) {
                            1 => ['label' => 'Operational', 'class' => 'bg-success-100 text-success-700 dark:bg-success-500/20 dark:text-success-400'],
                            0 => ['label' => 'Down',        'class' => 'bg-danger-100 text-danger-700 dark:bg-danger-500/20 dark:text-danger-400'],
                            3 => ['label' => 'Maintenance', 'class' => 'bg-warning-100 text-warning-700 dark:bg-warning-500/20 dark:text-warning-400'],
                            default => ['label' => 'Unknown', 'class' => 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400'],
                        };
                        $icon = match((int)($m['status'] ?? -1)) {
                            1 => 'heroicon-s-check-circle',
                            0 => 'heroicon-s-x-circle',
                            3 => 'heroicon-s-wrench-screwdriver',
                            default => 'heroicon-s-question-mark-circle',
                        };
                    @endphp
                    <li class="flex items-center gap-3 px-6 py-4">
                        <x-dynamic-component :component="$icon" class="h-5 w-5 shrink-0 {{ $color }}" />
                        <span class="flex-1 font-medium text-gray-900 dark:text-white">{{ $m['name'] }}</span>
                        @if(!empty($m['url']))
                            <span class="text-xs text-gray-400 dark:text-gray-500 font-mono hidden sm:block">{{ $m['url'] }}</span>
                        @endif
                        <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $badge['class'] }}">{{ $badge['label'] }}</span>
                    </li>
                @endforeach
            </ul>
            <div class="px-6 py-3 text-right text-xs text-gray-400 dark:text-gray-500">Refreshed every 30 seconds</div>
        @endif
    </div>
</x-filament-panels::page>
