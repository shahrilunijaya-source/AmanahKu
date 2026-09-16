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
    $tab = array_key_exists((string) request('tab'), $tabs) ? (string) request('tab') : 'fixed';
@endphp

@section('screen')
<div x-data="{ tab: @js($tab) }" x-init="$watch('tab', t => { const u = new URL(location.href); u.searchParams.set('tab', t); history.replaceState(null, '', u); })">
    @include('partials.payroll.tabs', ['tabs' => $tabs])

    <div x-show="tab === 'fixed'" x-cloak>
        @include('partials.payroll.stub', ['title' => 'Fixed Transaction', 'body' => 'Filled in Task 4.', 'bodyMs' => 'Diisi dalam Task 4.', 'pill' => 'Follow-up'])
    </div>
    <div x-show="tab === 'individual'" x-cloak>
        @include('partials.payroll.stub', ['title' => 'Individual Transaction', 'body' => 'Filled in Task 4.', 'bodyMs' => 'Diisi dalam Task 4.', 'pill' => 'Follow-up'])
    </div>
    <div x-show="tab === 'cp38'" x-cloak>
        @include('partials.payroll.stub', ['title' => 'CP38', 'body' => 'Extra monthly tax instalment ordered by LHDN for a staff member.', 'bodyMs' => 'Ansuran cukai tambahan bulanan yang diarahkan LHDN untuk seorang staf.', 'pill' => 'Spec F9'])
    </div>
    <div x-show="tab === 'rebate'" x-cloak>
        @include('partials.payroll.stub', ['title' => 'Tax Rebate', 'body' => 'Zakat and levy offsets against PCB.', 'bodyMs' => 'Tolakan zakat dan levi terhadap PCB.', 'pill' => 'Follow-up'])
    </div>
    <div x-show="tab === 'takeon'" x-cloak>
        @include('partials.payroll.stub', ['title' => 'Payroll Figures Take On', 'body' => 'Filled in Task 4.', 'bodyMs' => 'Diisi dalam Task 4.', 'pill' => 'Follow-up'])
    </div>
    <div x-show="tab === 'tp1'" x-cloak>
        @include('partials.payroll.stub', ['title' => 'Personal Tax Relief (TP1)', 'body' => 'Declare your personal tax reliefs so PCB is computed on the right base.', 'bodyMs' => 'Isytihar pelepasan cukai peribadi supaya PCB dikira atas asas yang betul.', 'pill' => 'Spec F8'])
    </div>
    <div x-show="tab === 'items'" x-cloak>
        @include('partials.payroll.stub', ['title' => 'Payroll Items', 'body' => 'Filled in Task 4.', 'bodyMs' => 'Diisi dalam Task 4.', 'pill' => 'Follow-up'])
    </div>
</div>
@endsection
