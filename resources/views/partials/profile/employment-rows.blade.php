{{-- Read-only grid of the Worksy employment record. Expects $p (Employee), $canSeeSalary. Shared by the
     profile Employment tab and the Progression "Current" column. --}}
@php
    $L = fn ($en, $ms) => '<span x-text="'.e("\$store.ui.lang==='en' ? ".json_encode($en).' : '.json_encode($ms)).'">'.e($en).'</span>';
    $d = fn ($v) => $v ? $v->format('d/m/Y') : '—';
    $period = fn ($m, $dd) => ($m || $dd) ? trim(($m ? "{$m}M " : '').($dd ? "{$dd}D" : '')) : '—';
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
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax({{ $minCol ?? '260px' }},1fr));gap:14px 32px;">
    @foreach ($rows as [$en, $ms, $val])
        <div><div style="font-size:11px;color:var(--muted);margin-bottom:2px;">{!! $L($en, $ms) !!}</div><div style="font-size:13px;color:var(--ink);">{{ $val }}</div></div>
    @endforeach
</div>
