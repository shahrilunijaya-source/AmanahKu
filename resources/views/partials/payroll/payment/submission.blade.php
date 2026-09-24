@php
    $money = fn ($v) => 'RM '.number_format((float) $v, 2);
@endphp
<form method="get" action="{{ route('app.screen', 'payroll-payment') }}" style="display:flex;align-items:center;gap:8px;margin-bottom:14px;">
    <input type="hidden" name="tab" value="submission">
    <label style="font-size:12.5px;color:var(--muted);">Run</label>
    <select name="run" onchange="this.form.submit()" style="height:34px;padding:0 10px;border:1px solid var(--hairline);border-radius:8px;font-size:12.5px;">
        @foreach ($runs as $r)<option value="{{ $r->id }}" @selected($activeRun?->id === $r->id)>{{ $r->label }} · {{ $r->status }}</option>@endforeach
    </select>
</form>
@if (! $activeRun)
    <div class="uj-card" style="padding:22px;color:var(--muted);font-size:13px;" x-text="$store.ui.lang==='en' ? 'No payroll run yet. Create one under Process.' : 'Belum ada run gaji. Buat satu di bawah Proses.'">No payroll run yet. Create one under Process.</div>
@else
@php $ps = $activeRun->payslips; @endphp
<div class="uj-card" style="max-width:820px;padding:20px;">
    @if ($activeRun->status !== 'finalized')
        <div style="font-size:12px;color:var(--muted);margin-bottom:10px;" x-text="$store.ui.lang==='en' ? 'Files are available once this run is finalized.' : 'Fail tersedia setelah run ini dimuktamadkan.'">Files are available once this run is finalized.</div>
    @endif
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:12px;margin-bottom:16px;">
        @foreach (['Net' => $ps->sum('net_pay'), 'EPF' => $ps->sum('epf_employee') + $ps->sum('epf_employer'), 'SOCSO' => $ps->sum('socso_employee') + $ps->sum('socso_employer'), 'EIS' => $ps->sum('eis_employee') + $ps->sum('eis_employer'), 'PCB' => $ps->sum('pcb') + $ps->sum('pcb_additional')] as $k => $v)
            <div><div style="font-size:11px;color:var(--muted);text-transform:uppercase;">{{ $k }}</div><div style="font-family:var(--font-mono);font-size:14px;color:var(--ink);">{{ $money($v) }}</div></div>
        @endforeach
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
        @if ($activeRun->status === 'finalized')
            <form method="get" action="{{ route('payroll.export.bank', $activeRun) }}" style="display:inline-flex;align-items:center;gap:6px;">
                <select name="format" style="height:36px;padding:0 8px;border:1px solid var(--hairline);border-radius:8px;font-size:12px;background:#fff;color:var(--ink);">
                    @foreach (\App\Services\Payroll\BankFile\BankFileRegistry::options() as $k => $lbl)<option value="{{ $k }}">{{ $lbl }}</option>@endforeach
                </select>
                <button type="submit" class="uj-btn-ghost" style="height:36px;padding:0 12px;font-size:12px;" x-text="$store.ui.lang==='en' ? 'Bank file' : 'Fail bank'">Bank file</button>
            </form>
            <a href="{{ route('payroll.export.statutory', $activeRun) }}" class="uj-btn-ghost" style="height:36px;padding:0 12px;font-size:12px;display:inline-flex;align-items:center;text-decoration:none;" x-text="$store.ui.lang==='en' ? 'Statutory report' : 'Laporan berkanun'">Statutory report</a>
            <a href="{{ route('payroll.export.journal', $activeRun) }}" class="uj-btn-ghost" style="height:36px;padding:0 12px;font-size:12px;display:inline-flex;align-items:center;text-decoration:none;" x-text="$store.ui.lang==='en' ? 'Accounting journal' : 'Jurnal perakaunan'">Accounting journal</a>
        @endif
        @php
            $hrdfOn = \App\Services\Payroll\HrdCorpLevy::rate((string) app(\App\Services\FeatureManager::class)->value(app(\App\Tenancy\CurrentTenant::class)->get(), 'payroll.hrdf')) > 0;
            $fileLabels = ['kwsp-form-a' => ['EPF file', 'Fail KWSP'], 'perkeso-8a' => ['SOCSO/EIS file', 'Fail PERKESO/SIP'], 'cp39' => ['PCB file', 'Fail PCB'], 'hrdcorp' => ['HRD Corp levy worksheet', 'Lembaran levi HRD Corp']];
            $company = app(\App\Tenancy\CurrentTenant::class)->get();
        @endphp
        @foreach (\App\Services\Payroll\Statutory\StatutoryFileRegistry::all() as $key => $file)
            @continue($key === 'hrdcorp' && ! $hrdfOn)
            @php $gap = $file->missingEmployerNumber($company); @endphp
            @if ($gap !== null)
                <a href="{{ route('app.screen', ['screen' => 'settings', 'section' => 'statutory']) }}" title="{{ $gap }}" data-testid="missing-{{ $key }}" class="uj-btn-ghost" style="height:36px;padding:0 12px;font-size:12px;display:inline-flex;align-items:center;gap:6px;text-decoration:none;color:var(--muted);"><span style="opacity:.6;text-decoration:line-through;" x-text="$store.ui.lang==='en' ? @js($fileLabels[$key][0]) : @js($fileLabels[$key][1])">{{ $fileLabels[$key][0] }}</span><span class="uj-pill" style="font-size:10px;background:var(--red-tint);color:var(--red);">{{ $gap }}</span></a>
            @elseif ($activeRun->status === 'finalized')
                <a href="{{ route('payroll.export.statutory-file', [$activeRun, $key]) }}" class="uj-btn-ghost" style="height:36px;padding:0 12px;font-size:12px;display:inline-flex;align-items:center;text-decoration:none;"><span x-text="$store.ui.lang==='en' ? @js($fileLabels[$key][0]) : @js($fileLabels[$key][1])">{{ $fileLabels[$key][0] }}</span>@unless ($file->verified())<span class="uj-pill" style="margin-left:6px;font-size:10px;" x-text="$store.ui.lang==='en' ? 'check layout' : 'semak susun atur'">check layout</span>@endunless</a>
            @else
                <button type="button" disabled title="Available once this run is finalized" class="uj-btn-ghost" style="height:36px;padding:0 12px;font-size:12px;opacity:.55;cursor:not-allowed;" x-text="$store.ui.lang==='en' ? @js($fileLabels[$key][0]) : @js($fileLabels[$key][1])">{{ $fileLabels[$key][0] }}</button>
            @endif
        @endforeach
    </div>
    @include('partials.hint', ['tone' => 'warn', 'en' => "All four are due by the 15th of next month. The PCB file follows LHDN's published layout. The SOCSO/EIS file follows PERKESO's ASSIST 2.0 layout and carries SOCSO, EIS and SKBBK together. A file marked 'check layout' has not been checked against the agency's current upload specification yet: try it on the portal's validator before relying on it. HRD Corp has no upload file; the worksheet is what to key into eTRiS.", 'ms' => "Keempat-empatnya perlu dihantar sebelum 15 haribulan bulan hadapan. Fail PCB mengikut susun atur rasmi LHDN. Fail PERKESO/SIP mengikut susun atur ASSIST 2.0 dan membawa PERKESO, SIP dan SKBBK bersama. Fail bertanda 'semak susun atur' belum disemak dengan spesifikasi muat naik terkini agensi: cuba pada penyemak portal sebelum bergantung padanya. HRD Corp tiada fail muat naik; lembaran ini untuk dimasukkan ke eTRiS."])
</div>
@endif
