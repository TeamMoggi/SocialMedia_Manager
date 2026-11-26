<x-filament-panels::page>
    <p style="opacity:.8;font-size:.9rem">
        A post that was not sent on time is tried again on each of the next three days. Only when those days run out does it
        stay here as <strong>Missed</strong>. Nothing is ever sent late without a try you can see here; choose
        <strong>Post now</strong> or a <strong>New time</strong> for any of them.
    </p>

    {{ $this->table }}
</x-filament-panels::page>
