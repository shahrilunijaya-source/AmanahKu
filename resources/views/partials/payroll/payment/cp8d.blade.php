@php $year = (int) request('year', now()->year); @endphp
<div class="uj-card" style="max-width:520px;padding:22px;">
    <h3 class="uj-card-title" style="margin-bottom:12px;">LHDN CP8D</h3>
    <form method="get" action="{{ route('payroll.form-e.cp8d', ['year' => $year]) }}" x-data="{ y: {{ $year }} }" @submit.prevent="location.href = '{{ url('/app/payroll/form-e') }}/' + y + '/cp8d'">
        <label style="display:block;font-size:12.5px;color:var(--muted);margin-bottom:4px;" x-text="$store.ui.lang==='en' ? 'Year' : 'Tahun'">Year</label>
        <input x-model="y" type="number" min="2020" max="2100" style="width:120px;height:36px;padding:0 10px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;margin-bottom:12px;">
        <button class="uj-btn-primary" style="height:36px;padding:0 16px;font-size:13px;" x-text="$store.ui.lang==='en' ? 'Generate' : 'Jana'">Generate</button>
    </form>
</div>
