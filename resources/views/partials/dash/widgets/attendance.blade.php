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
        @php
            $top = array_slice($w['people'], 0, 3);
            $rest = array_slice($w['people'], 3);
        @endphp
        <div class="uj-dw-people" @if ($rest) x-data="{ more: false }" @endif>
            @foreach ($top as $p)
                @include('partials.dash.widgets.person-row', ['p' => $p])
            @endforeach
            @if ($rest)
                <div x-show="more" x-cloak>
                    @foreach ($rest as $p)
                        @include('partials.dash.widgets.person-row', ['p' => $p])
                    @endforeach
                </div>
                <button type="button" class="uj-dw-more uj-dw-more--inline" @click="more = ! more"
                        :aria-expanded="more ? 'true' : 'false'"
                        x-text="more
                            ? ($store.ui.lang==='en' ? 'Show less' : 'Tunjuk kurang')
                            : ($store.ui.lang==='en' ? 'Show {{ count($rest) }} more' : 'Tunjuk {{ count($rest) }} lagi')">Show {{ count($rest) }} more</button>
            @endif
        </div>
    @endif
</div>
