{{--
    Left-hand staff list shared by the Fixed and Individual Transaction tabs. Sits inside
    an x-data that owns `pick`, `q`, `rows` and `hit()`. $counts: employee id => number
    of transactions to show beside the name.
--}}
<div class="uj-card tx-side">
    <div style="padding:12px;border-bottom:1px solid var(--hairline);">
        <input type="search" x-model="q" @keydown.escape="q = ''" :placeholder="$store.ui.lang==='en' ? 'Search by name or ID' : 'Cari nama atau ID'" class="tx-input" style="height:34px;font-size:12.5px;">
    </div>
    <div style="max-height:620px;overflow:auto;">
        @foreach ($salaryEmployees as $e)
            <div x-show="hit(rows[{{ $loop->index }}])"><button type="button" @click="pick = {{ $e->id }}" :style="{ background: pick === {{ $e->id }} ? 'var(--red-tint)' : 'none', boxShadow: pick === {{ $e->id }} ? 'inset 3px 0 0 var(--red)' : 'none' }" style="display:flex;width:100%;text-align:left;align-items:center;gap:10px;padding:10px 14px;border:0;border-bottom:1px solid var(--hairline-soft);background:none;cursor:pointer;">
                <div style="width:30px;height:30px;border-radius:50%;background:{{ $e->avatar_color ?? '#3a6ea5' }};color:#fff;display:flex;align-items:center;justify-content:center;font-size:10.5px;font-weight:600;flex-shrink:0;">{{ $e->initials }}</div>
                <div style="min-width:0;"><div style="font-size:12.5px;color:var(--ink);font-weight:500;">{{ $e->name }}</div><div style="font-size:11px;color:var(--muted);">{{ $e->position }}@if ($e->staff_id) · {{ $e->staff_id }}@endif</div></div>
                @if (($counts[$e->id] ?? 0) > 0)<span class="tx-pill" style="margin-left:auto;">{{ $counts[$e->id] }}</span>@endif
            </button></div>
        @endforeach
    </div>
</div>
