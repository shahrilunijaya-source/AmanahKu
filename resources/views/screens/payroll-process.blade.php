@extends('layouts.app')

@php
    $tabs = [
        'monthly' => ['Monthly', 'Bulanan'],
        'bonus' => ['Bonus', 'Bonus'],
        'control' => ['Payroll Control', 'Kawalan Gaji'],
    ];
    $tab = array_key_exists((string) request('tab'), $tabs) ? (string) request('tab') : 'monthly';
@endphp

@section('screen')
<div x-data="{ tab: @js($tab) }" x-init="$watch('tab', t => { const u = new URL(location.href); u.searchParams.set('tab', t); history.replaceState(null, '', u); })">
    @include('partials.payroll.tabs', ['tabs' => $tabs])

    <div x-show="tab === 'monthly'" x-cloak>
        @include('partials.payroll.stub', ['title' => 'Monthly', 'body' => 'Filled in Task 5.', 'bodyMs' => 'Diisi dalam Task 5.', 'pill' => 'Follow-up'])
    </div>
    <div x-show="tab === 'bonus'" x-cloak>
        @include('partials.payroll.stub', ['title' => 'Bonus', 'body' => 'A separate bonus run with its own PCB treatment.', 'bodyMs' => 'Run bonus berasingan dengan layanan PCB tersendiri.', 'pill' => 'Spec F10'])
    </div>
    <div x-show="tab === 'control'" x-cloak>
        @include('partials.payroll.stub', ['title' => 'Payroll Control', 'body' => 'Lock or unlock a pay month with a remark and attachment, and see who changed what.', 'bodyMs' => 'Kunci atau buka bulan gaji dengan catatan dan lampiran, dan lihat siapa mengubah apa.', 'pill' => 'Follow-up'])
    </div>
</div>
@endsection
