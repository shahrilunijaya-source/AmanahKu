@php
    $today = $w['today'] ?? null;
    $total = (int) ($w['totalMinutes'] ?? 0);

    // Same short codes and labels the HR-facing Attendance Reports ledger uses
    // (partials/attendance-report/ledger-body.blade.php) — kept in step by hand,
    // see BuildsDashboardWidgets::clockPunchFlags().
    $flagLabel = [
        'off' => ['Off-site', 'Luar lokasi'],
        'visit' => ['Site visit', 'Lawatan tapak'],
        'short' => ['Short hours', 'Jam kurang'],
        'early' => ['Left early', 'Balik awal'],
        'noloc' => ['No location', 'Tiada lokasi'],
        'amended' => ['Clock-out amended', 'Clock out dipinda'],
        'auto' => ['Auto clock-out', 'Clock out automatik'],
    ];
@endphp
<div class="uj-dw-body uj-dw-flush">
    @if ($today && $today->clock_in)
        <div class="uj-dw-shift">
            <span class="uj-dw-swatch" style="background:var(--red)"></span>
            <span class="txt">
                <span class="t">{{ $today->location ?: ucfirst((string) $today->type) }}</span>
                <span class="s">{{ $today->clock_in }}{{ $today->clock_out ? ' – '.$today->clock_out : '' }}</span>
            </span>
            @unless ($today->clock_out)
                <a class="uj-dw-btn uj-dw-btn-red" href="{{ route('app.screen', 'attendance') }}"
                   x-text="$store.ui.lang==='en' ? 'Clock out' : 'Keluar'">Clock out</a>
            @endunless
        </div>
    @endif
    @forelse ($w['punches'] ?? [] as $p)
        <div class="uj-dw-punch">
            <span class="dow">{{ $p['day'] }}</span>
            <span class="times">{{ $p['times'] }}</span>
            <span style="display:flex;flex-wrap:wrap;justify-content:flex-end;align-items:center;gap:6px;">
                <span class="uj-dw-pill" data-k="{{ $p['status'] }}">{{ $p['label'] }}</span>
                @foreach ($p['flags'] ?? [] as $flag)
                    @php $fl = $flagLabel[$flag] ?? [$flag, $flag]; @endphp
                    <span class="uj-ar-flag" data-t="{{ $flag }}"
                          x-text="$store.ui.lang==='en' ? @js($fl[0]) : @js($fl[1])">{{ $fl[0] }}</span>
                @endforeach
            </span>
        </div>
    @empty
        <p class="uj-dw-empty" x-text="$store.ui.lang==='en'
            ? 'No punches recorded yet.'
            : 'Belum ada rekod masuk.'">No punches recorded yet.</p>
    @endforelse
</div>
@if (! empty($w['punches']))
    <div class="uj-dw-foot">
        <span x-text="$store.ui.lang==='en' ? 'Hours, last 5 working days' : 'Jam, 5 hari bekerja terakhir'">Hours, last 5 working days</span>
        <b>{{ intdiv($total, 60) }}h {{ str_pad((string) ($total % 60), 2, '0', STR_PAD_LEFT) }}m</b>
    </div>
@endif
