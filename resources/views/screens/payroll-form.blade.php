@extends('layouts.app')

@php
    $tabs = [
        'form-e' => ['LHDN Form E', 'LHDN Form E'],
        'borang-a' => ['EPF Borang A', 'EPF Borang A'],
        'borang-8a' => ['Perkeso Borang 8A', 'Perkeso Borang 8A'],
        'cp39' => ['LHDN CP39', 'LHDN CP39'],
        'cp21' => ['CP21', 'CP21'],
        'cp22' => ['CP22', 'CP22'],
        'cp22a' => ['CP22A', 'CP22A'],
        'sip2' => ['Borang SIP 2', 'Borang SIP 2'],
        'pcb2' => ['PCB II', 'PCB II'],
        'zakat' => ['Zakat', 'Zakat'],
        'hrdf' => ['HRDF', 'HRDF'],
    ];
    $tab = array_key_exists((string) request('tab'), $tabs) ? (string) request('tab') : 'form-e';
@endphp

@section('screen')
<div x-data="{ tab: @js($tab) }" x-init="$watch('tab', t => { const u = new URL(location.href); u.searchParams.set('tab', t); history.replaceState(null, '', u); })">
    @include('partials.payroll.tabs', ['tabs' => $tabs])

    <div x-show="tab === 'form-e'" x-cloak>
        @include('partials.payroll.form.form-e')
    </div>
    <div x-show="tab === 'borang-a'" x-cloak>
        @include('partials.payroll.stub', ['title' => 'EPF Borang A', 'body' => 'Monthly KWSP contribution form generated from the finalized run.', 'bodyMs' => 'Borang caruman KWSP bulanan dijana daripada run yang dimuktamadkan.', 'pill' => 'Spec F6'])
    </div>
    <div x-show="tab === 'borang-8a'" x-cloak>
        @include('partials.payroll.stub', ['title' => 'Perkeso Borang 8A', 'body' => 'Monthly SOCSO contribution form generated from the finalized run.', 'bodyMs' => 'Borang caruman PERKESO bulanan dijana daripada run yang dimuktamadkan.', 'pill' => 'Spec F6'])
    </div>
    <div x-show="tab === 'cp39'" x-cloak>
        @include('partials.payroll.stub', ['title' => 'LHDN CP39', 'body' => 'Monthly PCB remittance form generated from the finalized run.', 'bodyMs' => 'Borang remitan PCB bulanan dijana daripada run yang dimuktamadkan.', 'pill' => 'Spec F6'])
    </div>
    <div x-show="tab === 'cp21'" x-cloak>
        @include('partials.payroll.stub', ['title' => 'CP21', 'body' => 'Notice for a staff member leaving Malaysia.', 'bodyMs' => 'Notis untuk staf yang meninggalkan Malaysia.', 'pill' => 'Spec F11'])
    </div>
    <div x-show="tab === 'cp22'" x-cloak>
        @include('partials.payroll.stub', ['title' => 'CP22', 'body' => 'Notice of a new employee to LHDN.', 'bodyMs' => 'Notis pekerja baharu kepada LHDN.', 'pill' => 'Spec F11'])
    </div>
    <div x-show="tab === 'cp22a'" x-cloak>
        @include('partials.payroll.stub', ['title' => 'CP22A', 'body' => 'Notice of cessation of employment to LHDN.', 'bodyMs' => 'Notis pemberhentian kerja kepada LHDN.', 'pill' => 'Spec F11'])
    </div>
    <div x-show="tab === 'sip2'" x-cloak>
        @include('partials.payroll.stub', ['title' => 'Borang SIP 2', 'body' => 'EIS notice of loss of employment.', 'bodyMs' => 'Notis SIP kehilangan pekerjaan.', 'pill' => 'Spec F11'])
    </div>
    <div x-show="tab === 'pcb2'" x-cloak>
        @include('partials.payroll.stub', ['title' => 'PCB II', 'body' => 'Annual PCB statement per staff member.', 'bodyMs' => 'Penyata PCB tahunan setiap staf.', 'pill' => 'Follow-up'])
    </div>
    <div x-show="tab === 'zakat'" x-cloak>
        @include('partials.payroll.stub', ['title' => 'Zakat', 'body' => 'Zakat deduction listing for the year.', 'bodyMs' => 'Senarai potongan zakat untuk tahun ini.', 'pill' => 'Follow-up'])
    </div>
    <div x-show="tab === 'hrdf'" x-cloak>
        @include('partials.payroll.stub', ['title' => 'HRDF', 'body' => 'HRD Corp levy return from the finalized run.', 'bodyMs' => 'Penyata levi HRD Corp daripada run yang dimuktamadkan.', 'pill' => 'Spec F7'])
    </div>
</div>
@endsection
