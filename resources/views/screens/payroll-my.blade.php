@extends('layouts.app')

@php
    $tabs = [
        'payslip' => ['Payslip', 'Slip Gaji'],
        'ea-form' => ['EA Form', 'EA Form'],
        'tp1' => ['Personal Tax Relief (TP1)', 'Pelepasan Cukai (TP1)'],
    ];
    $tab = array_key_exists((string) request('tab'), $tabs) ? (string) request('tab') : 'payslip';
@endphp

@section('screen')
@include('partials.guide', [
    'key' => 'payroll-my',
    'en' => [
        'title' => 'My Payroll',
        'body' => 'Your issued payslips live here. Each one shows your earnings, the EPF / SOCSO / EIS / PCB deducted, and your final net pay. Payslips only appear once HR has finalized payroll for that month.',
        'who' => 'Your own payslips',
        'steps' => [],
    ],
    'ms' => [
        'title' => 'Gaji Saya',
        'body' => 'Slip gaji yang dikeluarkan untuk anda ada di sini. Setiap satu tunjuk pendapatan anda, potongan EPF / SOCSO / EIS / PCB, dan gaji bersih akhir anda. Slip gaji hanya muncul setelah HR memuktamadkan gaji bagi bulan tersebut.',
        'who' => 'Slip gaji anda sendiri',
        'steps' => [],
    ],
])
<div x-data="{ tab: @js($tab) }" x-init="$watch('tab', t => { const u = new URL(location.href); u.searchParams.set('tab', t); history.replaceState(null, '', u); })">
    @include('partials.payroll.tabs', ['tabs' => $tabs])

    <div x-show="tab === 'payslip'" x-cloak>
        @include('partials.payroll.my.payslip')
    </div>
    <div x-show="tab === 'ea-form'" x-cloak>
        @include('partials.payroll.my.ea-form')
    </div>
    <div x-show="tab === 'tp1'" x-cloak>
        @include('partials.payroll.stub', ['title' => 'Personal Tax Relief (TP1)', 'body' => 'Declare your personal tax reliefs so PCB is computed on the right base.', 'bodyMs' => 'Isytihar pelepasan cukai peribadi supaya PCB dikira atas asas yang betul.', 'pill' => 'Spec F8'])
    </div>
</div>
@endsection
