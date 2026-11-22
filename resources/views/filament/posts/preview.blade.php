@php
    use App\Domain\Posts\AiLabel;
    use App\Domain\Posts\Approval;
    use App\Domain\Posts\CaptionBuilder;
    use App\Domain\Posts\CaptionStats;
    use App\Enums\Network;
    use App\Enums\PostType;

    $post = $getRecord()->fresh(['targets', 'media.mediaItem', 'brand']);
    $problems = app(Approval::class)->problems($post);
    $ai = AiLabel::applies($post);
    $card = 'border:1px solid rgba(127,127,127,.3);border-radius:.75rem;padding:1rem;min-width:0';
@endphp
<div style="display:grid;gap:1rem">
    <div>
        <h3 style="font-weight:600;font-size:1.05rem">Preview and checks</h3>
        <p style="font-size:.85rem;opacity:.7">This shows the post as last saved. Save to refresh the preview.</p>
    </div>

    @if ($post->targets->isEmpty())
        <p style="font-size:.9rem">Choose Facebook, Instagram or both above, then save.</p>
    @endif

    <div style="display:grid;gap:1rem;grid-template-columns:repeat(auto-fit,minmax(min(100%,320px),1fr))">
        @foreach ($post->targets as $target)
            @php
                $target->setRelation('post', $post);
                $caption = CaptionBuilder::for($target);
                $isInstagram = $target->network === Network::Instagram;
            @endphp
            <section style="{{ $card }}">
                <p style="font-weight:600">{{ $isInstagram ? 'Instagram' : 'Facebook' }} · {{ $target->type->label() }}</p>
                @if ($post->media->isNotEmpty() && ! in_array($target->type, [PostType::FacebookText, PostType::FacebookLink], true))
                    <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin:.5rem 0">
                        @foreach ($post->media as $media)
                            @if (str_starts_with((string) $media->mime, 'image/') && filled($media->path))
                                {{-- The post's own copy: what will be sent, even if the original has changed since. --}}
                                <img src="{{ route('filament.app.library.post-media', ['tenant' => $post->brand->slug, 'media' => $media->id]) }}" alt="{{ $media->name() }}" style="height:64px;border-radius:.4rem">
                            @else
                                <span style="font-size:.8rem;border:1px solid rgba(127,127,127,.3);border-radius:.4rem;padding:.25rem .5rem">{{ $media->name() }}</span>
                            @endif
                        @endforeach
                    </div>
                @endif
                @if ($target->type === PostType::FacebookLink && $target->link)
                    <p style="font-size:.85rem;overflow-wrap:anywhere">Link: {{ $target->link }}</p>
                @endif
                <div style="white-space:pre-wrap;font-size:.9rem;overflow-wrap:anywhere;margin-top:.5rem">{{ $caption !== '' ? $caption : ($target->type === PostType::InstagramStory ? '(Stories show no caption.)' : '(No caption yet.)') }}</div>
                @if ($ai && $isInstagram)
                    <p style="font-size:.8rem;margin-top:.5rem">Instagram's AI label will be added (an attached file is AI-made and shows people).</p>
                @endif
                @if ($ai && ! $isInstagram)
                    <p style="font-size:.8rem;margin-top:.5rem;opacity:.7">The last line is added because an attached file is AI-made and shows people.</p>
                @endif
                @if ($isInstagram && $target->type !== PostType::InstagramStory)
                    <p style="font-size:.8rem;opacity:.7;margin-top:.5rem">{{ CaptionStats::hint($caption) }}</p>
                @endif
            </section>
        @endforeach
    </div>

    <div>
        <p style="font-weight:600">Checks</p>
        <ul style="display:grid;gap:.35rem;margin-top:.35rem;font-size:.9rem">
            @forelse ($problems as $problem)
                <li style="color:{{ $problem->blocks() ? '#dc2626' : '#d97706' }}">{{ $problem->blocks() ? 'Must fix: ' : 'Note: ' }}{{ $problem->message }}</li>
            @empty
                <li style="color:#16a34a">All checks pass.</li>
            @endforelse
        </ul>
    </div>
</div>
