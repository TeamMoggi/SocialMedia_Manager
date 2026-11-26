<x-filament-panels::page>
    <x-filament::section heading="LinkedIn app">
        <p>Client ID: <b>{{ $clientId }}</b> (Moggies Social Manager, app 265142813)</p>
        <p>Client Secret:
            @if ($secretSavedAt)
                Saved on {{ $secretSavedAt->setTimezone($zone)->format('j M Y, g:i a') }}
            @else
                <span style="color:var(--warning-600, #b45309)">not saved yet</span>
            @endif
        </p>
        @unless ($hasSecret)
            <p style="margin-top:.4rem">Save the Client Secret first (Save app details), then the Connect LinkedIn button appears.</p>
        @endunless
    </x-filament::section>

    <x-filament::section heading="Connected page">
        @if ($account)
            <p><b>{{ $account->name }}</b> <span style="opacity:.7">(id {{ $account->external_id }})</span> &middot; {{ $account->status->label() }}</p>
            <p>Key valid until <b>{{ $account->token_expires_at ? $account->token_expires_at->setTimezone($zone)->format('j M Y') : 'unknown' }}</b>.
                LinkedIn keys last 2 months: the app warns you 7 days before, then press Reconnect LinkedIn.</p>
            @if ($account->last_error)
                <p style="color:var(--danger-600, #b91c1c)">{{ $account->last_error }}</p>
            @endif
        @else
            <p>Nothing connected yet.@if ($orgId) The app will connect the company page {{ $orgId }}.@endif</p>
        @endif
    </x-filament::section>

    <x-filament::section heading="How to connect" collapsible collapsed>
        <ol style="margin:0;padding-left:1.1rem;display:grid;gap:.35rem;font-size:.9rem">
            <li>Press <b>Save app details</b> and paste the Client Secret from linkedin.com/developers → My apps → Moggies Social Manager → Auth. Paste it only here, never into a chat or an email.</li>
            <li>Press <b>Connect LinkedIn</b>. The browser moves to <b>localhost</b> (the address LinkedIn knows) and opens LinkedIn.</li>
            <li>Sign in on LinkedIn with the account that is an <b>Administrator</b> of the company page, and press <b>Allow</b>.</li>
            <li>You come back here with "Connected". Every 2 months, when the app reminds you, press <b>Reconnect LinkedIn</b>.</li>
        </ol>
    </x-filament::section>
</x-filament-panels::page>
