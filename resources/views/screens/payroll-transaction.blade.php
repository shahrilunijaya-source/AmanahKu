@extends('layouts.app')

@php
    $tabs = [
        'fixed' => ['Fixed Transaction', 'Transaksi Tetap'],
        'individual' => ['Individual Transaction', 'Transaksi Individu'],
        'cp38' => ['CP38', 'CP38'],
        'rebate' => ['Tax Rebate', 'Rebat Cukai'],
        'takeon' => ['Payroll Figures Take On', 'Angka Pembukaan Gaji'],
        'tp1' => ['Personal Tax Relief (TP1)', 'Pelepasan Cukai (TP1)'],
        'items' => ['Payroll Items', 'Item Gaji'],
    ];
    $tab = array_key_exists((string) request('tab'), $tabs) ? (string) request('tab') : (request()->filled('itx_period') ? 'individual' : 'fixed');
@endphp

@section('screen')
{{-- Fixed and Individual Transaction tabs, laid out like Worksy's in Amanahku's colours. --}}
<style>
    .tx-split { display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap; }
    .tx-side { flex:1;min-width:240px;max-width:300px;padding:0; }
    .tx-main { flex:3;min-width:min(420px,100%); }
    .tx-head { display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin:0 0 14px; }
    .tx-title { font-size:18px;font-weight:600;color:var(--ink);margin:0; }
    .tx-sub { font-size:12.5px;color:var(--muted);margin:2px 0 0; }
    .tx-back { width:36px;height:36px;border:1px solid var(--hairline);border-radius:8px;background:#fff;color:var(--ink);cursor:pointer;display:inline-flex;align-items:center;justify-content:center; }
    .tx-back:hover { border-color:var(--red);color:var(--red); }
    .tx-form { padding:24px; }
    .tx-grid { display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:20px 28px; }
    .tx-full { grid-column:1 / -1; }
    @media (max-width:760px) { .tx-grid { grid-template-columns:1fr; } }
    .tx-label { display:block;font-size:12.5px;font-weight:500;color:var(--ink);margin:0 0 7px; }
    .tx-label em { font-style:normal;color:var(--red); }
    .tx-input { width:100%;height:40px;padding:0 12px;border:1px solid var(--hairline);border-radius:8px;font-size:13.5px;background:#fff;color:var(--ink);outline:none; }
    .tx-input:focus { border-color:var(--red); }
    textarea.tx-input { height:96px;padding:10px 12px;resize:vertical;font-family:inherit; }
    .tx-note { font-size:11.5px;color:var(--muted);margin-top:5px; }
    .tx-type { display:flex;height:40px;border:1px solid var(--red);border-radius:8px;overflow:hidden; }
    .tx-type button { flex:1;border:0;background:#fff;color:var(--red);font-size:13px;font-weight:500;cursor:pointer;transition:background .15s,color .15s; }
    .tx-type button + button { border-left:1px solid var(--red); }
    .tx-type button[aria-pressed="true"] { background:var(--red);color:#fff; }
    .tx-switch { position:relative;display:inline-block;width:42px;height:24px;flex-shrink:0; }
    .tx-switch input { position:absolute;opacity:0;width:0;height:0; }
    .tx-switch span { position:absolute;inset:0;background:var(--hairline);border-radius:9999px;cursor:pointer;transition:background .15s; }
    .tx-switch span::after { content:'';position:absolute;top:3px;left:3px;width:18px;height:18px;border-radius:50%;background:#fff;box-shadow:0 1px 2px rgba(38,37,30,.2);transition:transform .15s var(--ease); }
    .tx-switch input:checked + span { background:var(--red); }
    .tx-switch input:checked + span::after { transform:translateX(18px); }
    .tx-switch input:focus-visible + span { outline:2px solid var(--red);outline-offset:2px; }
    .tx-table { width:100%;border-collapse:collapse;font-size:12.5px; }
    .tx-table th { text-align:left;padding:10px 12px;color:var(--muted);font-size:11px;font-weight:500;text-transform:uppercase;letter-spacing:.5px;background:var(--canvas);border-bottom:1px solid var(--hairline); }
    .tx-table td { padding:10px 12px;border-bottom:1px solid var(--hairline-soft);vertical-align:middle;color:var(--ink); }
    .tx-table .num { text-align:right;font-family:var(--font-mono); }
    .tx-tablewrap { border:1px solid var(--hairline);border-radius:10px;overflow-x:auto; }
    .tx-toolbar { display:flex;align-items:center;gap:6px;padding:8px 10px;border-bottom:1px solid var(--hairline); }
    .tx-tool { height:30px;padding:0 10px;border:0;border-radius:6px;background:none;color:var(--ink);font-size:12.5px;font-weight:500;cursor:pointer;display:inline-flex;align-items:center;gap:6px; }
    .tx-tool:hover { background:var(--red-tint);color:var(--red); }
    .tx-tool.danger { color:var(--error); }
    .tx-tiles { display:flex;gap:12px;flex-wrap:wrap;margin:22px 0; }
    .tx-tile { width:140px;height:84px;border:1px solid var(--hairline);border-radius:10px;background:#fff;color:var(--ink);display:flex;flex-direction:column;align-items:center;justify-content:center;gap:4px;font-size:13px;font-weight:500;cursor:pointer;transition:border-color .15s,background .15s; }
    .tx-tile b { font-size:22px;font-weight:600;line-height:1; }
    .tx-tile:hover:not(:disabled) { border-color:var(--red); }
    .tx-tile[aria-pressed="true"] { background:var(--red);border-color:var(--red);color:#fff; }
    .tx-tile:disabled { cursor:not-allowed;color:var(--muted-soft);background:var(--canvas); }
    .tx-pill { display:inline-block;padding:2px 8px;border-radius:9999px;font-size:11px;font-weight:500;background:var(--canvas);border:1px solid var(--hairline);color:var(--body);white-space:nowrap; }
    .tx-pill.mm { background:var(--red-tint);border-color:var(--red-line, #f3ced0);color:var(--red-active); }
</style>
<div x-data="{ tab: @js($tab) }" x-init="$watch('tab', t => { const u = new URL(location.href); u.searchParams.set('tab', t); history.replaceState(null, '', u); })">
    <p style="font-size:12.5px;color:var(--muted);margin:0 0 14px;">
        <span x-text="$store.ui.lang==='en' ? 'Bank account, EPF, SOCSO and tax numbers are on each staff member\'s profile, Bank & Statutory tab.' : 'Akaun bank, nombor EPF, SOCSO dan cukai ada pada profil setiap staf, tab Bank & Berkanun.'">Bank account, EPF, SOCSO and tax numbers are on each staff member's profile, Bank &amp; Statutory tab.</span>
        <a href="{{ route('app.screen', 'directory') }}" style="color:var(--red);" x-text="$store.ui.lang==='en' ? 'Open the directory' : 'Buka direktori'">Open the directory</a>
    </p>
    @include('partials.payroll.tabs', ['tabs' => $tabs])

    <div x-show="tab === 'fixed'" x-cloak>
        @include('partials.payroll.transaction.fixed')
    </div>
    <div x-show="tab === 'individual'" x-cloak>
        @include('partials.payroll.transaction.individual')
    </div>
    <div x-show="tab === 'cp38'" x-cloak>
        @include('partials.payroll.transaction.cp38')
    </div>
    <div x-show="tab === 'rebate'" x-cloak>
        @include('partials.payroll.transaction.rebate')
    </div>
    <div x-show="tab === 'takeon'" x-cloak>
        @include('partials.payroll.transaction.takeon')
    </div>
    <div x-show="tab === 'tp1'" x-cloak>
        @include('partials.payroll.transaction.tp1')
    </div>
    <div x-show="tab === 'items'" x-cloak>
        @include('partials.payroll.transaction.items')
    </div>
</div>
@endsection
