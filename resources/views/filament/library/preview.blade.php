<div style="display:grid;gap:.5rem">
    @if ($item->kind->isVideo())
        <video src="{{ $url }}" controls preload="metadata" style="max-width:100%;max-height:70vh;border-radius:.5rem"></video>
    @else
        <img src="{{ $url }}" alt="{{ $item->description ?: $item->fileName() }}" style="max-width:100%;max-height:70vh;border-radius:.5rem">
    @endif
    <p style="font-size:.8rem;opacity:.7;overflow-wrap:anywhere">{{ $item->fullPath() }}</p>
    @if ($item->description)
        <p style="font-size:.9rem">{{ $item->description }}</p>
    @endif
</div>
