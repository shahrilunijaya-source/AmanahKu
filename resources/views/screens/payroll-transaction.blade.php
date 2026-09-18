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
        @include('partials.payroll.stub', ['title' => 'CP38', 'body' => 'Extra monthly tax instalment ordered by LHDN. Record each notice on the staff member\'s profile, Bank & Statutory tab.', 'bodyMs' => 'Ansuran cukai tambahan bulanan yang diarahkan LHDN. Rekod setiap notis pada profil staf, tab Bank & Berkanun.', 'pill' => 'Spec F9'])
    </div>
    <div x-show="tab === 'rebate'" x-cloak>
        @include('partials.payroll.stub', ['title' => 'Tax Rebate', 'body' => 'Zakat and levy offsets against PCB.', 'bodyMs' => 'Tolakan zakat dan levi terhadap PCB.', 'pill' => 'Follow-up'])
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
