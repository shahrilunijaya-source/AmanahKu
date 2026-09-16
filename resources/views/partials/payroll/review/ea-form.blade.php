@php $year = (int) request('year', now()->year); @endphp
<div class="uj-card" style="max-width:820px;padding:0;">
    <div class="uj-card-head" style="gap:10px;">
        <h3 class="uj-card-title">EA Form</h3>
        <form method="get" action="{{ route('app.screen', 'payroll-review') }}" style="margin-left:auto;display:flex;gap:8px;align-items:center;">
            <input type="hidden" name="tab" value="ea-form">
            <input name="year" type="number" min="2020" max="2100" value="{{ $year }}" style="width:90px;height:32px;padding:0 10px;border:1px solid var(--hairline);border-radius:8px;font-size:12.5px;">
            <button class="uj-btn-ghost" style="height:32px;padding:0 12px;font-size:12px;" x-text="$store.ui.lang==='en' ? 'Show' : 'Papar'">Show</button>
        </form>
    </div>
    @foreach ($salaryEmployees as $e)
        <div style="display:flex;align-items:center;justify-content:space-between;gap:14px;padding:12px 20px;border-top:1px solid var(--hairline-soft);">
            <div><div style="font-size:13px;color:var(--ink);font-weight:500;">{{ $e->name }}</div><div style="font-size:11px;color:var(--muted);">{{ $e->position }}</div></div>
            <div style="display:flex;gap:8px;">
                <a href="{{ route('payroll.ea-form.show', ['employee' => $e->id, 'year' => $year]) }}" class="uj-btn-ghost" style="height:30px;padding:0 10px;font-size:12px;display:inline-flex;align-items:center;text-decoration:none;" x-text="$store.ui.lang==='en' ? 'View' : 'Lihat'">View</a>
                <a href="{{ route('payroll.ea-form.pdf', ['employee' => $e->id, 'year' => $year]) }}" class="uj-btn-ghost" style="height:30px;padding:0 10px;font-size:12px;display:inline-flex;align-items:center;text-decoration:none;">PDF</a>
            </div>
        </div>
    @endforeach
</div>
