{{-- One leave-type row. Shared by the always-visible top three and the rows
     hidden behind "Show more", so the two never drift apart.

     Types with no yearly entitlement have nothing for "taken" or a bar-fill %
     to be measured against — see BuildsDashboardWidgets::leaveWidget(). Two
     different reasons, two different fallbacks:
       - Replacement (an ad-hoc HR-granted quota) shows its balance alone.
       - Emergency (deductsFrom) has no balance of its own at all — every day
         spends Annual's, or is unpaid once Annual runs dry — so showing ITS
         balance number here would just be a second, unrelated figure. The
         row explains the rule instead. --}}
<div class="uj-dw-lv">
    <div class="uj-dw-lv-top">
        <span class="t">{{ $r['type'] }}</span>
        @if ($r['hasEntitlement'])
            <span class="v">{{ $r['balance'] }}<small>/{{ $r['entitlement'] }}</small></span>
        @elseif ($r['deductsFrom'])
            <span class="v" x-text="$store.ui.lang==='en' ? 'Takes from Annual' : 'Ambil dari Tahunan'">Takes from Annual</span>
        @else
            <span class="v">{{ $r['balance'] }}
                <small x-text="$store.ui.lang==='en' ? 'days' : 'hari'">days</small></span>
        @endif
    </div>
    @if ($r['hasEntitlement'])
        <div class="uj-dw-lv-bar"><i style="width:{{ $r['pct'] }}%"></i></div>
    @endif
    <div class="uj-dw-lv-meta">
        @if ($r['hasEntitlement'])
            <span x-text="$store.ui.lang==='en' ? 'Taken' : 'Diambil'">Taken</span>&nbsp;<b>{{ $r['used'] }}</b>
            <span x-text="$store.ui.lang==='en' ? 'Left' : 'Baki'">Left</span>&nbsp;<b>{{ $r['balance'] }}</b>
        @elseif ($r['deductsFrom'])
            <span x-text="$store.ui.lang==='en' ? 'runs out as Unpaid' : 'habis jadi Tanpa Gaji'">runs out as Unpaid</span>
        @else
            <span x-text="$store.ui.lang==='en' ? 'Available' : 'Tersedia'">Available</span>&nbsp;<b>{{ $r['balance'] }}</b>
        @endif
    </div>
</div>
