<x-filament-panels::page>
    @if ($warnings)
        <x-filament::section>
            <ul style="margin:0;padding-left:1.1rem;display:grid;gap:.35rem">
                @foreach ($warnings as $warning)
                    <li style="color:var(--warning-600, #b45309)">{{ $warning }}</li>
                @endforeach
            </ul>
        </x-filament::section>
    @endif

    <x-filament::section heading="Last backup">
        @if ($status->lastBackupAt)
            <p><b>{{ $status->lastBackupAt->setTimezone($zone)->format('j M Y, g:i a') }}</b> &middot; {{ $status->lastBackupFile }}</p>
        @else
            <p>No backup has been made yet.</p>
        @endif
        <p style="font-size:.85rem;opacity:.8;margin-top:.4rem">Folder: <code>{{ $status->folder }}</code></p>
        <p style="font-size:.85rem;opacity:.8">Backup password: {{ $status->passwordSet ? 'set' : 'not set yet' }}</p>
    </x-filament::section>

    <x-filament::section heading="How backups work">
        <ul style="margin:0;padding-left:1.1rem;display:grid;gap:.35rem;font-size:.9rem">
            <li>Once a day (when the laptop is on) the app saves a copy of your posts, numbers, photos, videos and settings into the Backups folder, locked with your backup password. The newest 14 are kept.</li>
            <li>About once a week, copy the whole Backups folder to your USB drive, then press <b>I copied Backups to my USB drive today</b>.</li>
            <li>Opening a backup needs the password and 7-Zip (free), or ask Claude to restore it.</li>
        </ul>
    </x-filament::section>
</x-filament-panels::page>
