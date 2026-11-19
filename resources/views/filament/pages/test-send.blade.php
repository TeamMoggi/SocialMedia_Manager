<x-filament-panels::page>
    <x-filament::section heading="What Test send does">
        <ul style="margin:0;padding-left:1.1rem;display:grid;gap:.35rem;font-size:.9rem">
            <li>Takes one photo and one video from <code>{{ $source }}</code> through every step to Instagram and Facebook, and stops just before publishing: nothing is published.</li>
            <li>Instagram: the file goes to the Hostinger folder, Instagram downloads and prepares it, then the file is removed. Facebook: the file is uploaded unpublished, checked, then deleted.</li>
            <li>Your files in the folder are only read, never changed.</li>
        </ul>
    </x-filament::section>

    @if ($last)
        <x-filament::section heading="Last test send">
            <p style="margin-bottom:.4rem">{{ \Carbon\CarbonImmutable::parse($last['at'])->setTimezone($zone)->format('j M Y, g:i a') }} ({{ $last['copy'] }} copy):
                <b style="color:{{ $last['ok'] ? 'var(--success-600, #15803d)' : 'var(--danger-600, #b91c1c)' }}">{{ $last['ok'] ? 'passed' : 'failed' }}</b>
            </p>
            <ul style="margin:0;padding-left:1.1rem;display:grid;gap:.3rem;font-size:.9rem">
                @foreach ($last['steps'] as $step)
                    <li>{{ $step['ok'] ? '✓' : '✗' }} {{ $step['label'] }}@if ($step['note']) <span style="opacity:.8">: {{ $step['note'] }}</span>@endif</li>
                @endforeach
            </ul>
        </x-filament::section>
    @endif
</x-filament-panels::page>
