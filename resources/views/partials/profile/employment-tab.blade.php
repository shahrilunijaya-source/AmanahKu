{{-- Employment tab on the profile: read-only Worksy employment record + edit modal for HR/management.
     Expects $p (Employee), $employmentGate, $canEditEmployment, $canSeeSalary, $fs and the org option lists. --}}
@php
    $L = fn ($en, $ms) => '<span x-text="$store.ui.lang===\'en\' ? '.json_encode($en).' : '.json_encode($ms).'">'.e($en).'</span>';
    $d = fn ($v) => $v ? $v->format('d/m/Y') : '—';
    $period = fn ($m, $dd) => ($m || $dd) ? trim(($m ? "{$m}M " : '').($dd ? "{$dd}D" : '')) : '—';
    $end = $p->last_working_day ?? now();
    $service = $p->joined_at ? $p->joined_at->diff($end) : null;
    $due = ($p->joined_at && ($p->probation_months || $p->probation_days)) ? $p->joined_at->copy()->addMonths((int) $p->probation_months)->addDays((int) $p->probation_days) : null;
    $payModeL = ['monthly' => 'Monthly Rate', 'daily' => 'Daily Rate', 'hourly' => 'Hourly Rate'];
    $termL = ['daily' => 'Daily', 'weekly' => 'Weekly', 'biweekly' => 'Bi-Weekly', 'monthly' => 'Monthly'];
    $methodL = ['cash' => 'Cash', 'bank' => 'Bank', 'cheque' => 'Cheque'];
    $rows = [
        ['Hire Date', 'Tarikh Mula', $d($p->joined_at)],
        ['Probation Period', 'Tempoh Percubaan', $period($p->probation_months, $p->probation_days)],
        ['Confirmation Date', 'Tarikh Pengesahan', $d($p->confirmed_at)],
        ['Resign Notice Period', 'Tempoh Notis Berhenti', $period($p->resign_notice_months, $p->resign_notice_days)],
        ['Resigned Date', 'Tarikh Berhenti', $d($p->resigned_at)],
        ['Short Notice Period', 'Tempoh Notis Singkat', $period($p->short_notice_months, $p->short_notice_days)],
        ['Branch', 'Cawangan', $p->branch?->name ?? '—'],
        ['Department', 'Jabatan', $p->department?->name ?? '—'],
        ['Division', 'Bahagian', $p->division ?? '—'],
        ['Position', 'Jawatan', $p->positionBand?->title ?? $p->position ?? '—'],
        ['Reporting To', 'Melapor Kepada', $p->reportsTo?->name ?? '—'],
        ['Category', 'Kategori', $p->category ?? '—'],
        ['Job Grade', 'Gred', $p->job_grade ?? '—'],
        ['Line', 'Barisan', $p->line ?? '—'],
        ['Section', 'Seksyen', $p->section ?? '—'],
        ['Employment Type', 'Jenis Pekerjaan', $p->employmentType?->name ?? '—'],
    ];
    if ($canSeeSalary ?? false) {
        $rows[] = ['Basic Salary', 'Gaji Pokok', $p->salary === null ? '—' : 'MYR '.number_format((float) $p->salary, 2).' · '.($payModeL[$p->pay_mode] ?? $p->pay_mode)];
    }
    $rows[] = ['Payment Term', 'Tempoh Bayaran', $termL[$p->payment_term] ?? '—'];
    $rows[] = ['Payment Method', 'Kaedah Bayaran', $methodL[$p->payment_method] ?? '—'];
    $rows[] = ['Remark', 'Catatan', $p->employment_remark ?? '—'];
@endphp

<div style="display:flex;flex-wrap:wrap;gap:16px 32px;font-size:12.5px;color:var(--muted);align-items:center;">
    <div>{!! $L('Date Hired', 'Tarikh Mula') !!}: <b style="color:var(--ink);">{{ $d($p->joined_at) }}</b></div>
    <div>{!! $L('Years of Service', 'Tempoh Perkhidmatan') !!}: <b style="color:var(--ink);">{{ $service ? "{$service->y}Y {$service->m}M {$service->d}D" : '—' }}</b></div>
    <div>{!! $L('Due for Confirmation', 'Tarikh Pengesahan Dijangka') !!}: <b style="color:var(--ink);">{{ $d($due) }}</b></div>
    @if ($canEditEmployment ?? false)
        <button type="button" @click="editEmployment = true" class="uj-btn-ghost" style="margin-left:auto;height:32px;padding:0 14px;font-size:12.5px;">{!! $L('Edit', 'Sunting') !!}</button>
    @endif
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px 32px;">
    @foreach ($rows as [$en, $ms, $val])
        <div><div style="font-size:11px;color:var(--muted);margin-bottom:2px;">{!! $L($en, $ms) !!}</div><div style="font-size:13px;color:var(--ink);">{{ $val }}</div></div>
    @endforeach
</div>

@if ($canEditEmployment ?? false)
    <template x-teleport="body">
    <div x-show="editEmployment" x-cloak @click.self="editEmployment = false" @keydown.escape.window="editEmployment = false"
         style="position:fixed;inset:0;z-index:120;display:flex;padding:40px 16px;background:rgba(18,18,30,.42);overflow-y:auto;">
        <form method="post" action="{{ route('employees.employment.update', $p) }}" class="uj-card" style="width:100%;max-width:720px;margin:auto;padding:20px;display:flex;flex-direction:column;gap:12px;max-height:calc(100vh - 80px);overflow-y:auto;">
            @csrf
            <div style="font-size:13px;font-weight:600;color:var(--ink);">{!! $L('Edit employment', 'Sunting pekerjaan') !!} · {{ $p->name }}</div>
            @if ($errors->any())<div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12px;border-radius:8px;padding:8px 11px;">{{ $errors->first() }}</div>@endif
            <div><label style="display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;">{!! $L('Effective date', 'Tarikh berkuat kuasa') !!}</label><input type="date" name="effective_on" value="{{ old('effective_on', now()->toDateString()) }}" required style="{{ $fs }}" /></div>
            @include('partials.profile.employment-form-fields', ['e' => $p, 'canSeeSalary' => $canSeeSalary ?? false])
            <div style="display:flex;gap:8px;justify-content:flex-end;">
                <button type="button" @click="editEmployment = false" class="uj-btn-ghost" style="height:40px;padding:0 16px;font-size:13px;">{!! $L('Cancel', 'Batal') !!}</button>
                <button type="submit" class="uj-btn-primary" style="height:40px;padding:0 18px;font-size:13px;">{!! $L('Save changes', 'Simpan perubahan') !!}</button>
            </div>
        </form>
    </div>
    </template>
@endif
