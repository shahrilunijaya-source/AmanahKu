@php
    /** "My staff" is the direct reporting line for every role. HR and the
        directors get the company-wide picture from Company pulse instead. */
    $swatch = ['in' => 'var(--success)', 'late' => 'var(--amber)', 'done' => 'var(--info)', 'leave' => 'var(--leave)', 'absent' => 'var(--red)'];
@endphp
<div class="uj-dw-body">
    @if (empty($w['people']))
        <p class="uj-dw-empty" x-text="$store.ui.lang==='en'
            ? 'Nobody reports to you yet.'
            : 'Belum ada sesiapa melapor kepada anda.'">Nobody reports to you yet.</p>
    @else
        <div class="uj-dw-att">
            @foreach ($w['counts'] as $c)
                <div>
                    <span class="v">{{ $c['v'] }}</span>
                    <span class="l"><span class="uj-dw-swatch" style="background:{{ $swatch[$c['k']] }}"></span>{{ $c['label'] }}</span>
                </div>
            @endforeach
        </div>
        <div class="uj-dw-people">
            @foreach ($w['people'] as $p)
                <div class="uj-dw-person">
                    <span class="av" style="background:{{ $p['color'] }}">{{ $p['initials'] }}</span>
                    <span class="txt">
                        <span class="t">{{ $p['name'] }}</span>
                        <span class="s">{{ $p['label'] }}</span>
                    </span>
                </div>
            @endforeach
        </div>
    @endif
</div>
