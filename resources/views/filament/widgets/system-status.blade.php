<x-filament-widgets::widget>
    <x-filament::section heading="This copy of the app">
        <div style="display:flex;flex-wrap:wrap;gap:.75rem 1.5rem;align-items:center">
            @if ($isLive)
                <x-filament::badge color="success">Live: approved posts are published</x-filament::badge>
            @else
                <x-filament::badge color="gray">Dry run: nothing is posted</x-filament::badge>
            @endif

            <x-filament::badge :color="$timedJobsStale ? 'warning' : 'success'">{{ $timedJobs }}</x-filament::badge>

            <span style="font-size:.85rem;opacity:.8">Data folder: <code>{{ $dataDir }}</code></span>
        </div>
        @if ($timedJobsStale)
            <p style="font-size:.85rem;margin-top:.6rem;opacity:.85">The daily jobs check account access and make the backup. They run at 09:00, or as soon as the laptop is on after that. If this stays orange for more than a day, tell Claude.</p>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
