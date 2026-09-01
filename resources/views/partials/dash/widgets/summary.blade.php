{{-- Current month summary: six figures, colour-coded by what they mean.
     The tiles carry the dashboard's one authored entrance (CSS stagger). --}}
<div class="uj-dw-body">
    <div class="uj-dw-tiles">
        @foreach ($w['tiles'] ?? [] as $t)
            <div class="uj-dw-tile" data-k="{{ $t['k'] }}">
                <span class="v">{{ $t['v'] }}<small>{{ $t['unit'] }}</small></span>
                <span class="l"><span class="dot"></span>{{ $t['label'] }}</span>
            </div>
        @endforeach
    </div>
</div>
