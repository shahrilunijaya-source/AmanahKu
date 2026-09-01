<div class="uj-dw-body">
    <div class="uj-dw-pulse">
        @foreach ($w['stats'] ?? [] as $s)
            <div>
                <span class="v" @if ($s['hot']) data-hot @endif>{{ $s['v'] }}</span>
                <span class="l">{{ $s['label'] }}</span>
            </div>
        @endforeach
    </div>
</div>
