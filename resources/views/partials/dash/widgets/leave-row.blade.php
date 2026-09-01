{{-- One leave-type row. Shared by the always-visible top three and the rows
     hidden behind "Show more", so the two never drift apart. --}}
<div class="uj-dw-lv">
    <div class="uj-dw-lv-top">
        <span class="t">{{ $r['type'] }}</span>
        <span class="v">{{ $r['balance'] }}<small>/{{ $r['entitlement'] }}</small></span>
    </div>
    <div class="uj-dw-lv-bar"><i style="width:{{ $r['pct'] }}%"></i></div>
    <div class="uj-dw-lv-meta">
        <span x-text="$store.ui.lang==='en' ? 'Taken' : 'Diambil'">Taken</span>&nbsp;<b>{{ $r['used'] }}</b>
        <span x-text="$store.ui.lang==='en' ? 'Left' : 'Baki'">Left</span>&nbsp;<b>{{ $r['balance'] }}</b>
    </div>
</div>
