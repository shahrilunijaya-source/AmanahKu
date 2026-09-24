@extends('layouts.app')

@php
    // Worksy's order, Statutory Notices last (it tracks hires and leavers, no Worksy page).
    $tabs = [
        'sip2' => ['Borang SIP 2', 'Borang SIP 2'],
        'borang-a' => ['EPF Borang A', 'EPF Borang A'],
        'bbcd' => ['EPF BBCD Form', 'Borang BBCD KWSP'],
        'cp21' => ['LHDN CP21', 'LHDN CP21'],
        'cp22' => ['LHDN CP22', 'LHDN CP22'],
        'cp22a' => ['LHDN CP22A', 'LHDN CP22A'],
        'form-e' => ['LHDN Form E', 'LHDN Borang E'],
        'pcb2' => ['LHDN PCB II Form', 'Borang PCB II LHDN'],
        'cp39' => ['LHDN CP39', 'LHDN CP39'],
        'perkeso-sip' => ['Perkeso Monthly SIP (EIS) Form', 'Borang SIP Bulanan Perkeso'],
        'borang-8a' => ['Perkeso Borang 8A', 'Perkeso Borang 8A'],
        'zakat' => ['Zakat Form', 'Borang Zakat'],
        'hrdf' => ['HRDF Form', 'Borang HRDF'],
        'notices' => ['Statutory Notices', 'Notis Berkanun'],
    ];
    $hrdfOn = \App\Services\Payroll\HrdCorpLevy::rate((string) app(\App\Services\FeatureManager::class)->value(app(\App\Tenancy\CurrentTenant::class)->get(), 'payroll.hrdf')) > 0;
    $tab = array_key_exists((string) request('tab'), $tabs) ? (string) request('tab') : 'form-e';
@endphp

@section('screen')
<div x-data="{ tab: @js($tab) }" x-init="$watch('tab', t => { const u = new URL(location.href); u.searchParams.set('tab', t); history.replaceState(null, '', u); })">
    @include('partials.payroll.tabs', ['tabs' => $tabs])

    <div x-show="tab === 'form-e'" x-cloak>
        @include('partials.payroll.form.form-e')
    </div>
    <div x-show="tab === 'notices'" x-cloak>
        @include('partials.payroll.form.notices')
    </div>
    <div x-show="tab === 'borang-a'" x-cloak>
        @include('partials.payroll.form.monthly-file', ['tab' => 'borang-a', 'fileKey' => 'kwsp-form-a', 'title' => 'EPF Borang A', 'refLabel' => 'EPF no.'])
    </div>
    <div x-show="tab === 'borang-8a'" x-cloak>
        @include('partials.payroll.form.monthly-file', ['tab' => 'borang-8a', 'fileKey' => 'perkeso-8a', 'title' => 'Perkeso Borang 8A', 'refLabel' => 'SOCSO no.', 'note' => 'One ASSIST 2.0 upload carries SOCSO, EIS and SKBBK together.', 'noteMs' => 'Satu muat naik ASSIST 2.0 membawa PERKESO, SIP dan SKBBK bersama.'])
    </div>
    <div x-show="tab === 'cp39'" x-cloak>
        @include('partials.payroll.form.monthly-file', ['tab' => 'cp39', 'fileKey' => 'cp39', 'title' => 'LHDN CP39', 'pattern' => 'D'])
    </div>
    <div x-show="tab === 'cp21'" x-cloak>
        @include('partials.payroll.form.staff-form', ['form' => 'cp21'])
    </div>
    <div x-show="tab === 'cp22'" x-cloak>
        @include('partials.payroll.form.staff-form', ['form' => 'cp22'])
    </div>
    <div x-show="tab === 'cp22a'" x-cloak>
        @include('partials.payroll.form.staff-form', ['form' => 'cp22a'])
    </div>
    <div x-show="tab === 'sip2'" x-cloak>
        @include('partials.payroll.form.sip2')
    </div>
    <div x-show="tab === 'pcb2'" x-cloak>
        @include('partials.payroll.form.staff-form', ['form' => 'pcb2'])
    </div>
    <div x-show="tab === 'zakat'" x-cloak>
        @include('partials.payroll.form.zakat')
    </div>
    <div x-show="tab === 'perkeso-sip'" x-cloak>
        {{-- Same combined v2.1 file as Borang 8A: ASSIST takes SOCSO and EIS in one upload. --}}
        @include('partials.payroll.form.monthly-file', ['tab' => 'perkeso-sip', 'fileKey' => 'perkeso-8a', 'title' => 'Perkeso Monthly SIP (EIS) Form', 'pattern' => 'D', 'note' => 'ASSIST 2.0 takes SOCSO and EIS in one file, so this is the same file as Borang 8A.', 'noteMs' => 'ASSIST 2.0 menerima PERKESO dan SIP dalam satu fail, jadi ini fail yang sama dengan Borang 8A.'])
    </div>
    <div x-show="tab === 'bbcd'" x-cloak>
        @include('partials.payroll.stub', ['title' => 'EPF BBCD Form', 'body' => 'KWSP 6A cover sheet for Form A. Waiting on the KWSP 6A form.', 'bodyMs' => 'Helaian penutup KWSP 6A untuk Borang A. Menunggu borang KWSP 6A.', 'pill' => 'Follow-up'])
    </div>
    <div x-show="tab === 'hrdf'" x-cloak>
        @if ($hrdfOn)
            @include('partials.payroll.form.monthly-file', ['tab' => 'hrdf', 'fileKey' => 'hrdcorp', 'title' => 'HRDF Form', 'downloadLabel' => 'Download levy worksheet', 'downloadLabelMs' => 'Muat turun lembaran levi', 'note' => 'HRD Corp has no upload file: pay the levy in eTRiS by month, using these totals.', 'noteMs' => 'HRD Corp tiada fail muat naik: bayar levi di eTRiS mengikut bulan, guna jumlah ini.'])
        @else
            <div class="uj-card" style="max-width:640px;padding:22px;font-size:13px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'HRD Corp levy is switched off for this company. Turn it on in Company Settings, Features.' : 'Levi HRD Corp dimatikan untuk syarikat ini. Hidupkan di Tetapan Syarikat, Ciri.'">HRD Corp levy is switched off for this company.</div>
        @endif
    </div>
</div>
@endsection
