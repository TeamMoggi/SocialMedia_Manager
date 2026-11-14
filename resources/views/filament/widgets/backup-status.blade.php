<x-filament-widgets::widget>
    <x-filament::section heading="Backups">
        @if ($warnings)
            <ul style="margin:0;padding-left:1.1rem;display:grid;gap:.35rem">
                @foreach ($warnings as $warning)
                    <li>{{ $warning }}</li>
                @endforeach
            </ul>
            <p style="margin-top:.6rem"><x-filament::link :href="$backupsUrl">Open Backups</x-filament::link></p>
        @else
            <x-filament::badge color="success">Backups are up to date</x-filament::badge>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
