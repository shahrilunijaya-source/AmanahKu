<div class="uj-dw-body">
    @forelse ($w['rows'] ?? [] as $r)
        <div class="uj-dw-notice">
            <span class="when">{{ $r['meta'] ?? '' }}</span>
            <span class="txt">
                <span class="t">{{ $r['title'] ?? '' }}</span>
                <span class="s">{{ \Illuminate\Support\Str::limit((string) ($r['sub'] ?? ''), 120) }}</span>
            </span>
            @if (! empty($r['flag']))
                <span class="tag" data-req>{{ $r['flag'] }}</span>
            @elseif (! empty($r['tag']))
                <span class="tag">{{ $r['tag'] }}</span>
            @endif
        </div>
    @empty
        <p class="uj-dw-empty" x-text="$store.ui.lang==='en'
            ? 'No announcements yet.'
            : 'Tiada pengumuman lagi.'">No announcements yet.</p>
    @endforelse
</div>
