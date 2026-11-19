<x-filament-panels::page>
    <x-filament::section heading="Meta app">
        <p>App ID: <b>{{ $appId }}</b> (Moggies Social Manager)</p>
        <p>App Secret:
            @if ($secretSavedAt)
                Saved on {{ $secretSavedAt->setTimezone($zone)->format('j M Y, g:i a') }}
            @else
                <span style="color:var(--warning-600, #b45309)">not saved yet</span>
            @endif
        </p>
    </x-filament::section>

    <x-filament::section heading="Permanent key">
        <p>Key:
            @if ($keySavedAt)
                Saved on {{ $keySavedAt->setTimezone($zone)->format('j M Y, g:i a') }}
            @else
                <span style="color:var(--warning-600, #b45309)">not saved yet</span>
            @endif
        </p>
        @if ($check)
            <p style="margin-top:.4rem">Last check {{ $check->checkedAt->setTimezone($zone)->format('j M Y, g:i a') }}:
                @if ($check->problem)
                    <span style="color:var(--danger-600, #b91c1c)">{{ $check->problem }}</span>
                @else
                    <b>works</b>, {{ $check->expiresAt ? 'expires on '.$check->expiresAt->setTimezone($zone)->format('j M Y') : 'never expires' }}.
                @endif
            </p>
            @if ($check->missing)
                <p style="color:var(--danger-600, #b91c1c)">Missing permissions (needed to post): {{ implode(', ', $check->missing) }}</p>
            @endif
            @if ($check->missingWanted)
                <p style="color:var(--warning-600, #b45309)">Missing permissions (needed later for statistics and comments): {{ implode(', ', $check->missingWanted) }}</p>
            @endif
            @if ($check->extra)
                <p style="color:var(--warning-600, #b45309)">More access than needed: {{ implode(', ', $check->extra) }}</p>
            @endif
        @endif
    </x-filament::section>

    <x-filament::section heading="Connected">
        @forelse ($accounts as $account)
            <p><b>{{ $account->network->label() }}:</b> {{ $account->name }} <span style="opacity:.7">(id {{ $account->external_id }})</span> &middot; {{ $account->status->label() }}</p>
        @empty
            <p>Nothing connected yet. Save the App Secret, then the key.</p>
        @endforelse
    </x-filament::section>

    <x-filament::section heading="How to make the key" collapsible collapsed>
        <ol style="margin:0;padding-left:1.1rem;display:grid;gap:.35rem;font-size:.9rem">
            <li>Open business.facebook.com/settings, portfolio <b>Moggies Social Manager</b> → Users → <b>System users</b> → <b>Moggies SMM App</b>.</li>
            <li>Press <b>Generate token</b>, choose the app <b>Moggies Social Manager</b> and expiry <b>Never</b>.</li>
            <li>Tick: pages_show_list, pages_read_engagement, pages_manage_posts, pages_manage_engagement, pages_read_user_content, read_insights, instagram_basic, instagram_content_publish, instagram_manage_insights, instagram_manage_comments, business_management. Nothing for ads or messages.</li>
            <li>Copy the key and press <b>Save Meta key</b> above. Paste it only here, never into a chat or an email.</li>
        </ol>
    </x-filament::section>
</x-filament-panels::page>
