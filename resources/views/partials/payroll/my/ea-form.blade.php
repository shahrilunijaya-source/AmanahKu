@php $me = request()->attributes->get('employee'); @endphp
<div class="uj-card" style="max-width:680px;">
    <div class="uj-card-head"><h3 class="uj-card-title">EA Form</h3></div>
    @forelse ($myEaYears ?? [] as $year)
        <div style="display:flex;align-items:center;justify-content:space-between;gap:14px;padding:14px 20px;border-bottom:1px solid var(--hairline-soft);">
            <div style="font-size:13.5px;color:var(--ink);font-weight:500;">{{ $year }}</div>
            <div style="display:flex;gap:8px;">
                <a href="{{ route('payroll.ea-form.show', ['employee' => $me?->id, 'year' => $year]) }}" class="uj-btn-ghost" style="height:32px;padding:0 12px;font-size:12px;display:inline-flex;align-items:center;text-decoration:none;" x-text="$store.ui.lang==='en' ? 'View' : 'Lihat'">View</a>
                <a href="{{ route('payroll.ea-form.pdf', ['employee' => $me?->id, 'year' => $year]) }}" class="uj-btn-ghost" style="height:32px;padding:0 12px;font-size:12px;display:inline-flex;align-items:center;text-decoration:none;">PDF</a>
            </div>
        </div>
    @empty
        <div style="padding:28px 20px;text-align:center;color:var(--muted);font-size:12.5px;" x-text="$store.ui.lang==='en' ? 'Your EA form appears here once a payslip has been issued for the year.' : 'Borang EA anda muncul di sini setelah slip gaji dikeluarkan untuk tahun itu.'">Your EA form appears here once a payslip has been issued for the year.</div>
    @endforelse
</div>
