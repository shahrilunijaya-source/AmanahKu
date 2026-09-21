@extends('layouts.app')

@php
    $tabs = [
        'payout' => ['Payout Management', 'Pengurusan Bayaran'],
        'submission' => ['Bank/Statutory Submission', 'Penyerahan Bank/Berkanun'],
        'payslip' => ['Individual Pay Slip', 'Slip Gaji Individu'],
        'bulk-payslip' => ['Bulk Pay Slip', 'Slip Gaji Pukal'],
        'cp8d' => ['LHDN CP8D', 'LHDN CP8D'],
        'deadlines' => ['Deadlines', 'Tarikh Akhir'],
        'audit' => ['IRB Audit Files', 'Fail Audit LHDN'],
    ];
    $tab = array_key_exists((string) request('tab'), $tabs) ? (string) request('tab') : 'payout';
@endphp

@section('screen')
<div x-data="{ tab: @js($tab) }" x-init="$watch('tab', t => { const u = new URL(location.href); u.searchParams.set('tab', t); history.replaceState(null, '', u); })">
    @include('partials.payroll.tabs', ['tabs' => $tabs])

    <div x-show="tab === 'payout'" x-cloak>
        @include('partials.payroll.payment.payout')
    </div>
    <div x-show="tab === 'submission'" x-cloak>
        @include('partials.payroll.payment.submission')
    </div>
    <div x-show="tab === 'payslip'" x-cloak>
        @include('partials.payroll.payment.payslip')
    </div>
    <div x-show="tab === 'bulk-payslip'" x-cloak>
        @include('partials.payroll.payment.bulk-payslip')
    </div>
    <div x-show="tab === 'cp8d'" x-cloak>
        @include('partials.payroll.payment.cp8d')
    </div>
    <div x-show="tab === 'deadlines'" x-cloak>
        @include('partials.payroll.payment.deadlines')
    </div>
    <div x-show="tab === 'audit'" x-cloak>
        @include('partials.payroll.stub', ['title' => 'IRB Audit Files', 'body' => 'Audit file export in LHDN\'s format.', 'bodyMs' => 'Eksport fail audit dalam format LHDN.', 'pill' => 'Follow-up'])
    </div>
</div>
@endsection
