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
<div x-data="{ tab: @js($tab) }" x-init="$watch('tab', t => { const u = new URL(location.href); u.searchParams.set('tab', t); history.replaceState(null, '', u); })">
    @include('partials.payroll.tabs', ['tabs' => $tabs])

    <div x-show="tab === 'payslip'" x-cloak>
        @include('partials.payroll.stub', ['title' => 'Payslip', 'body' => 'Filled in Task 3.', 'bodyMs' => 'Diisi dalam Task 3.', 'pill' => 'Follow-up'])
    </div>
    <div x-show="tab === 'ea-form'" x-cloak>
        @include('partials.payroll.stub', ['title' => 'EA Form', 'body' => 'Filled in Task 3.', 'bodyMs' => 'Diisi dalam Task 3.', 'pill' => 'Follow-up'])
    </div>
    <div x-show="tab === 'tp1'" x-cloak>
        @include('partials.payroll.stub', ['title' => 'Personal Tax Relief (TP1)', 'body' => 'Declare your personal tax reliefs so PCB is computed on the right base.', 'bodyMs' => 'Isytihar pelepasan cukai peribadi supaya PCB dikira atas asas yang betul.', 'pill' => 'Spec F8'])
    </div>
</div>
@endsection
