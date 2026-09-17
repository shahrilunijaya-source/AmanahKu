@extends('layouts.app')

@php
    $tabs = [
        'individual' => ['Individual Payroll', 'Gaji Individu'],
        'batch-remove' => ['Batch Remove Payslip', 'Buang Slip Berkelompok'],
        'ea-form' => ['EA Form', 'EA Form'],
        'bulk-ea' => ['Bulk EA Form', 'EA Form Pukal'],
    ];
    $tab = array_key_exists((string) request('tab'), $tabs) ? (string) request('tab') : 'individual';
@endphp

@section('screen')
<div x-data="{ tab: @js($tab) }" x-init="$watch('tab', t => { const u = new URL(location.href); u.searchParams.set('tab', t); history.replaceState(null, '', u); })">
    @include('partials.payroll.tabs', ['tabs' => $tabs])

    <div x-show="tab === 'individual'" x-cloak>
        @include('partials.payroll.review.individual')
    </div>
    <div x-show="tab === 'batch-remove'" x-cloak>
        @include('partials.payroll.stub', ['title' => 'Batch Remove Payslip', 'body' => 'Drop several staff from a draft run at once.', 'bodyMs' => 'Keluarkan beberapa staf daripada run draf sekali gus.', 'pill' => 'Follow-up'])
    </div>
    <div x-show="tab === 'ea-form'" x-cloak>
        @include('partials.payroll.review.ea-form')
    </div>
    <div x-show="tab === 'bulk-ea'" x-cloak>
        @include('partials.payroll.review.bulk-ea')
    </div>
</div>
@endsection
