@php
    $post = $getRecord();
    $zone = $post->brand->timezone ?: config('smm.display_timezone');
    $entries = \App\Models\Activity::query()
        ->where('subject_type', $post->getMorphClass())
        ->where('subject_id', $post->id)
        ->latest('id')
        ->limit(30)
        ->get();
@endphp
<div>
    <h3 style="font-weight:600;font-size:1.05rem">History</h3>
    <ul style="display:grid;gap:.25rem;font-size:.85rem;margin-top:.5rem">
        @forelse ($entries as $entry)
            <li>
                {{ $entry->created_at?->copy()->setTimezone($zone)->format('j M Y, H:i') }} · {{ ucfirst($entry->action) }} · by {{ $entry->by }}
                @if (! empty($entry->details['to']))
                    ({{ $entry->details['from'] ?? 'no time' }} → {{ $entry->details['to'] }})
                @endif
                @if (! empty($entry->details['reason']))
                    ({{ $entry->details['reason'] }})
                @endif
            </li>
        @empty
            <li>Nothing yet.</li>
        @endforelse
    </ul>
</div>
