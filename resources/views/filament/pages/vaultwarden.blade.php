<x-filament-panels::page>
    <div class="grid grid-cols-1 gap-6 md:grid-cols-2">

        <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-6">
            <h3 class="text-base font-semibold text-gray-950 dark:text-white mb-4">Status</h3>
            <dl class="space-y-3 text-sm">
                <div class="flex justify-between">
                    <dt class="text-gray-500 dark:text-gray-400">Instance</dt>
                    <dd class="font-medium text-gray-900 dark:text-white">{{ $vwUrl ?: '—' }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500 dark:text-gray-400">Reachable</dt>
                    <dd>
                        @if($reachable)
                            <span class="text-success-600 dark:text-success-400 font-medium">Yes</span>
                        @else
                            <span class="text-danger-600 dark:text-danger-400 font-medium">No</span>
                        @endif
                    </dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500 dark:text-gray-400">SSO wired</dt>
                    <dd>
                        @if($ssoWired)
                            <span class="text-success-600 dark:text-success-400 font-medium">Yes</span>
                        @else
                            <span class="text-gray-500 dark:text-gray-400">No</span>
                        @endif
                    </dd>
                </div>
            </dl>
        </div>

        @if($ssoWired)
        <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-6">
            <h3 class="text-base font-semibold text-gray-950 dark:text-white mb-4">SSO Configuration</h3>
            <dl class="space-y-3 text-sm">
                <div>
                    <dt class="text-gray-500 dark:text-gray-400 mb-1">Authority</dt>
                    <dd class="font-mono text-xs text-gray-800 dark:text-gray-200 break-all">{{ $authority }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500 dark:text-gray-400 mb-1">Client ID</dt>
                    <dd class="font-mono text-xs text-gray-800 dark:text-gray-200">{{ $kcClient }}</dd>
                </div>
            </dl>
        </div>
        @endif

    </div>
</x-filament-panels::page>
