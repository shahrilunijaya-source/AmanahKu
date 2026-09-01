<div class="uj-dw-body uj-dw-flush">
    @forelse ($w['rows'] ?? [] as $r)
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
    @empty
        <p class="uj-dw-empty" x-text="$store.ui.lang==='en'
            ? 'No leave entitlement set up yet.'
            : 'Kelayakan cuti belum ditetapkan.'">No leave entitlement set up yet.</p>
    @endforelse
</div>
<div class="uj-dw-foot">
    <span>{{ $w['pending'] ?? 0 }}
        <span x-text="$store.ui.lang==='en' ? 'awaiting approval' : 'menunggu kelulusan'">awaiting approval</span>
    </span>
    <a class="uj-dw-link" style="margin-left:auto" href="{{ route('app.screen', 'leave') }}"
       x-text="$store.ui.lang==='en' ? 'Apply for leave' : 'Mohon cuti'">Apply for leave</a>
</div>
