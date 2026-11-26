@php
    $post = $getRecord();
    $targets = $post->targets->sortBy(fn ($t) => $t->network->value);
@endphp
@if ($targets->contains(fn ($t) => $t->state !== \App\Enums\TargetState::Waiting))
    <div>
        <h3 style="font-weight:600;font-size:1.05rem">Sending</h3>
        <ul style="display:grid;gap:.25rem;font-size:.9rem;margin-top:.5rem">
            @foreach ($targets as $target)
                <li>
                    <strong>{{ $target->network === \App\Enums\Network::FacebookPage ? 'Facebook' : 'Instagram' }}:</strong>
                    {{ $target->state->label() }}
                    @if ($target->permalink)
                        · <a href="{{ $target->permalink }}" target="_blank" rel="noopener" style="text-decoration:underline">open the post</a>
                    @endif
                    @if ($target->last_error)
                        · <span style="color:#dc2626">{{ $target->last_error }}</span>
                    @endif
                </li>
            @endforeach
        </ul>
    </div>
@endif
