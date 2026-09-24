{{-- Worksy's SIP 2 wizard: step 1 picks who (all staff hired in a range, or a manual pick,
     narrowed by department and employment type), step 2 lists them for a PDF of PERKESO
     Borang SIP 2 (EIS registration of new hires, Dec 2017 form). --}}
@php
    $mine = request('tab') === 'sip2';
    $from = $mine && request('from') ? (string) request('from') : now()->startOfYear()->toDateString();
    $to = $mine && request('to') ? (string) request('to') : now()->toDateString();
    $mode = $mine && request('mode') === 'manual' ? 'manual' : 'all';
    $dept = $mine ? (int) request('department') : 0;
    $type = $mine ? (int) request('employment_type') : 0;
    $staff = \App\Models\Employee::active()->whereBetween('joined_at', [$from, $to])
        ->when($dept, fn ($q) => $q->where('department_id', $dept))
        ->when($type, fn ($q) => $q->where('employment_type_id', $type))
        ->orderBy('joined_at')->orderBy('name')->get();
    $fs = 'height:34px;padding:0 8px;border:1px solid var(--hairline);border-radius:8px;font-size:12.5px;background:#fff;';
    $lbl = 'display:block;font-size:12px;color:var(--muted);margin-bottom:4px;';
    $step = 'font-size:12px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;margin:0 0 10px;';
@endphp
<div class="uj-card" data-testid="sip2-wizard" style="padding:20px;">
    <h3 class="uj-card-title" style="margin:0 0 14px;">Borang SIP 2</h3>

    <form method="get" action="{{ route('app.screen', 'payroll-form') }}" style="border-bottom:1px solid var(--hairline);padding-bottom:16px;margin-bottom:16px;">
        <input type="hidden" name="tab" value="sip2">
        <p style="{{ $step }}">1 · <span x-text="$store.ui.lang==='en' ? 'Conditions' : 'Syarat'">Conditions</span></p>
        <div style="display:flex;gap:16px;flex-wrap:wrap;font-size:13px;margin-bottom:12px;">
            <label><input type="radio" name="mode" value="all" @checked($mode === 'all') onchange="this.form.submit()"> All Available Users</label>
            <label style="opacity:.45;" title="Not available yet"><input type="radio" disabled> Teams</label>
            <label><input type="radio" name="mode" value="manual" @checked($mode === 'manual') onchange="this.form.submit()"> Manual Selections</label>
            <label style="opacity:.45;" title="Not available yet"><input type="radio" disabled> Import</label>
        </div>
        <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
            <div><label style="{{ $lbl }}">Date hired from</label><input type="date" name="from" value="{{ $from }}" style="{{ $fs }}"></div>
            <div><label style="{{ $lbl }}">to</label><input type="date" name="to" value="{{ $to }}" style="{{ $fs }}"></div>
            <div><label style="{{ $lbl }}">Department</label>
                <select name="department" style="{{ $fs }}"><option value="">All</option>
                    @foreach (\App\Models\Department::orderBy('name')->get(['id', 'name']) as $d)<option value="{{ $d->id }}" @selected($dept === $d->id)>{{ $d->name }}</option>@endforeach
                </select></div>
            <div><label style="{{ $lbl }}">Employment type</label>
                <select name="employment_type" style="{{ $fs }}"><option value="">All</option>
                    @foreach (\App\Models\EmploymentType::orderBy('name')->get(['id', 'name']) as $t)<option value="{{ $t->id }}" @selected($type === $t->id)>{{ $t->name }}</option>@endforeach
                </select></div>
            <button type="submit" class="uj-btn-ghost" style="height:34px;padding:0 14px;font-size:12.5px;" x-text="$store.ui.lang==='en' ? 'Apply' : 'Guna'">Apply</button>
        </div>
    </form>

    <form method="get" action="{{ route('payroll.sip2.pdf') }}" x-data="{ n: {{ $mode === 'all' ? $staff->count() : 0 }} }" @change="n = $el.querySelectorAll('input[name=\'employees[]\']:checked').length">
        <p style="{{ $step }}">2 · <span x-text="$store.ui.lang==='en' ? 'Selected Employee' : 'Pekerja Dipilih'">Selected Employee</span></p>
        @if ($staff->isEmpty())
            <div style="padding:20px 0;font-size:13px;color:var(--muted);">No one hired between {{ \Carbon\Carbon::parse($from)->format('j M Y') }} and {{ \Carbon\Carbon::parse($to)->format('j M Y') }}.</div>
        @else
            <table style="width:100%;border-collapse:collapse;font-size:12.5px;">
                @foreach ($staff as $e)
                    <tr style="border-bottom:1px solid var(--hairline-soft);">
                        <td style="padding:7px 6px;width:28px;"><input type="checkbox" name="employees[]" value="{{ $e->id }}" @checked($mode === 'all') aria-label="{{ $e->name }}"></td>
                        <td style="padding:7px 6px;color:var(--ink);">{{ $e->name }}</td>
                        <td style="padding:7px 6px;color:var(--muted);">{{ $e->staff_id }}</td>
                        <td style="padding:7px 6px;color:var(--muted);">{{ $e->joined_at?->format('j M Y') }}</td>
                    </tr>
                @endforeach
            </table>
            <div style="display:flex;align-items:center;gap:10px;margin-top:12px;">
                <span style="font-size:12.5px;color:var(--muted);"><span x-text="n">0</span> selected</span>
                <button type="submit" class="uj-btn-primary" :disabled="n === 0" style="margin-left:auto;height:34px;padding:0 18px;font-size:13px;" x-text="$store.ui.lang==='en' ? 'Generate PDF' : 'Jana PDF'">Generate PDF</button>
            </div>
        @endif
    </form>
</div>
