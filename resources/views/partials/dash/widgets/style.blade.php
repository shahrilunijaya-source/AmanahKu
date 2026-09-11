{{-- My working style (CR-15): the Profile Test result, or the invitation to take it. --}}
<div class="uj-dw-body">
    @if ($w['archetype'] ?? null)
        <div style="display:flex;align-items:center;gap:14px;margin-bottom:14px;">
            <span style="font-size:40px;line-height:1;" aria-hidden="true">{{ $w['emoji'] }}</span>
            <div>
                <div style="font-size:var(--t-lg);font-weight:600;color:var(--ink);">{{ $w['label'] }}</div>
                <div style="font-size:var(--t-sm);color:var(--muted);">{{ $w['tagline'] }}</div>
            </div>
        </div>
        <div style="display:flex;flex-direction:column;gap:9px;">
            @foreach ($w['bars'] as $bar)
                <div>
                    <div style="display:flex;justify-content:space-between;margin-bottom:3px;font-size:var(--t-sm);">
                        <span style="color:var(--body);"><span aria-hidden="true">{{ $bar['emoji'] }}</span> {{ $bar['label'] }}</span>
                        <span style="font-family:var(--font-mono);color:var(--muted);">{{ $bar['pct'] }}%</span>
                    </div>
                    <div class="uj-progress"><span style="width:{{ $bar['pct'] }}%;background:{{ $bar['accent'] }};"></span></div>
                </div>
            @endforeach
        </div>
    @else
        <p class="uj-dw-empty" x-text="$store.ui.lang==='en'
            ? 'A short working-style check. No right or wrong answers.'
            : 'Semakan gaya kerja ringkas. Tiada jawapan betul atau salah.'">A short working-style check. No right or wrong answers.</p>
    @endif
</div>
<div class="uj-dw-foot">
    <a class="uj-dw-link" style="margin-left:auto" href="{{ $w['url'] }}"
       x-text="$store.ui.lang==='en'
           ? @js(($w['archetype'] ?? null) ? 'Retake the test' : 'Discover your working style')
           : @js(($w['archetype'] ?? null) ? 'Ambil semula ujian' : 'Kenali gaya kerja anda')">{{ ($w['archetype'] ?? null) ? 'Retake the test' : 'Discover your working style' }}</a>
</div>
