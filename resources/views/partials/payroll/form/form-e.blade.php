@php $year = (int) request('year', now()->year); @endphp
<div class="uj-card" style="max-width:520px;padding:22px;">
    <h3 class="uj-card-title" style="margin-bottom:12px;">LHDN Form E</h3>
    <form method="get" action="{{ route('app.screen', 'payroll-form') }}" style="display:flex;gap:8px;align-items:flex-end;margin-bottom:14px;">
        <input type="hidden" name="tab" value="form-e">
        <div><label style="display:block;font-size:12.5px;color:var(--muted);margin-bottom:4px;" x-text="$store.ui.lang==='en' ? 'Year' : 'Tahun'">Year</label>
        <input name="year" type="number" min="2020" max="2100" value="{{ $year }}" style="width:120px;height:36px;padding:0 10px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;"></div>
        <button class="uj-btn-ghost" style="height:36px;padding:0 12px;font-size:12px;" x-text="$store.ui.lang==='en' ? 'Show' : 'Papar'">Show</button>
    </form>
    <div style="display:flex;gap:8px;">
        <a href="{{ route('payroll.form-e.show', ['year' => $year]) }}" class="uj-btn-primary" style="height:36px;padding:0 14px;font-size:13px;display:inline-flex;align-items:center;text-decoration:none;" x-text="$store.ui.lang==='en' ? 'View' : 'Lihat'">View</a>
        <a href="{{ route('payroll.form-e.pdf', ['year' => $year]) }}" class="uj-btn-ghost" style="height:36px;padding:0 14px;font-size:13px;display:inline-flex;align-items:center;text-decoration:none;">PDF</a>
    </div>
</div>
