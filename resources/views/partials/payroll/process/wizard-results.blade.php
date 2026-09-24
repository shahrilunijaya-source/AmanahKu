{{-- Wizard step 4, Results: the run createRun just made (?step=results&run=). Server-rendered. --}}
@php
    $money = fn ($v) => 'RM '.number_format((float) $v, 2);
    [$cycleEn, $cycleMs] = $cycleLabels[$run->kind] ?? [$run->kind, $run->kind];
    $totals = $run->totals ?? [];
@endphp
<div style="max-width:860px;margin:0 auto;">
    @if (session('ok'))
        <div role="status" style="margin-bottom:16px;background:#eef9f1;border:1px solid var(--success);color:var(--success);font-size:12.5px;border-radius:8px;padding:9px 12px;">{{ session('ok') }}</div>
    @endif
    <div style="text-align:center;margin-bottom:18px;">
        <div style="font-size:18px;font-weight:600;color:var(--ink);">{{ $run->label }}</div>
        <div style="font-size:13px;color:var(--muted);"><span x-text="t(@js($cycleEn), @js($cycleMs))">{{ $cycleEn }}</span> · {{ $run->period }}</div>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:16px;">
        @foreach ([
            [['Payslips', 'Slip gaji'], $run->payslips->count()],
            [['Gross', 'Kasar'], $money($totals['gross'] ?? 0)],
            [['Net', 'Bersih'], $money($totals['net'] ?? 0)],
            [['Employer cost', 'Kos majikan'], $money($totals['employer_cost'] ?? 0)],
        ] as [[$en, $ms], $value])
            <div class="pw-summary"><div style="font-size:16px;font-weight:600;color:var(--ink);font-family:var(--font-mono);">{{ $value }}</div><div style="font-size:12px;color:var(--muted);" x-text="t(@js($en), @js($ms))">{{ $en }}</div></div>
        @endforeach
    </div>
    @if (filled($run->remarks ?? null))
        <div style="margin-bottom:16px;font-size:13px;color:var(--body);"><b style="color:var(--ink);" x-text="t('Remarks', 'Catatan')">Remarks</b>: {{ $run->remarks }}</div>
    @endif
    <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap;">
        <a href="{{ route('app.screen', ['screen' => 'payroll-review', 'tab' => 'individual', 'run' => $run->id]) }}" class="uj-btn-primary" style="height:38px;padding:0 22px;font-size:13px;display:inline-flex;align-items:center;text-decoration:none;" x-text="t('Review payroll', 'Semak gaji')">Review payroll</a>
        <a href="{{ route('app.screen', ['screen' => 'payroll-process', 'tab' => 'monthly']) }}" class="uj-btn-ghost" style="height:38px;padding:0 22px;font-size:13px;display:inline-flex;align-items:center;text-decoration:none;" x-text="t('Process another', 'Proses lagi')">Process another</a>
    </div>
</div>
