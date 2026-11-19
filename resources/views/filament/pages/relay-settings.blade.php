<x-filament-panels::page>
    <x-filament::section heading="Folder details">
        @if ($host)
            <p>FTP server: <b>{{ $host }}</b> (port {{ $port }}, {{ $ftps ? 'encrypted' : 'not encrypted' }})</p>
            <p>FTP user: <b>{{ $username }}</b> &middot; password {{ $passwordSaved ? 'saved' : 'not saved yet' }}</p>
            <p>Public address: <code>{{ $publicUrl }}</code></p>
        @else
            <p>Not set up yet. Make the FTP account in hPanel together with Claude, then press <b>Folder details</b>.</p>
        @endif
    </x-filament::section>

    @if ($lastTest)
        <x-filament::section heading="Last test">
            <p style="margin-bottom:.4rem">{{ \Carbon\CarbonImmutable::parse($lastTest['at'])->setTimezone($zone)->format('j M Y, g:i a') }}:
                <b style="color:{{ $lastTest['ok'] ? 'var(--success-600, #15803d)' : 'var(--danger-600, #b91c1c)' }}">{{ $lastTest['ok'] ? 'the folder works' : 'failed' }}</b>
            </p>
            <ul style="margin:0;padding-left:1.1rem;display:grid;gap:.3rem;font-size:.9rem">
                @foreach ($lastTest['steps'] as $step)
                    <li>{{ $step['ok'] ? '✓' : '✗' }} {{ $step['label'] }}@if ($step['note']) <span style="opacity:.8">: {{ $step['note'] }}</span>@endif</li>
                @endforeach
            </ul>
        </x-filament::section>
    @endif

    <x-filament::section heading="What this folder is for">
        <ul style="margin:0;padding-left:1.1rem;display:grid;gap:.35rem;font-size:.9rem">
            <li>Instagram only takes photos and videos from a web address, and this laptop has none. Just before an Instagram post the app puts the file in this hidden folder on moggiesinternational.com, and deletes it straight after.</li>
            <li>The folder can't be listed or found by search engines, and its FTP account can reach nothing else on the website.</li>
            <li>Don't put other files in it.</li>
        </ul>
    </x-filament::section>
</x-filament-panels::page>
