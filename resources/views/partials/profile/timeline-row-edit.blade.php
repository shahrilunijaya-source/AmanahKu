{{-- Correction form for one EmployeeProgression $row. Expects $row, $snap, $canSeeSalary, $failed, $L.
     A correction rewrites this row's snapshot only; it never changes the person's live record. --}}
@php
    // once() so the lookup lists are fetched a single time per request, not once per timeline row.
    $lists = once(fn () => [
        'departments' => \App\Models\Department::orderBy('name')->get(['id', 'name']),
        'branches' => \App\Models\Branch::orderBy('name')->get(['id', 'name']),
        'positions' => \App\Models\Position::orderBy('sort')->orderBy('title')->get(['id', 'title']),
        'employmentTypes' => \App\Models\EmploymentType::orderBy('name')->get(['id', 'name']),
        'managers' => \App\Models\Employee::active()->orderBy('name')->get(['id', 'name']),
    ]);
    $field = 'display:block;font-size:11px;color:var(--muted);margin-bottom:4px;';
    $input = 'width:100%;height:34px;border:1px solid var(--line);border-radius:8px;padding:0 10px;font-size:12.5px;background:#fff;';
    // The snapshot holds names, not ids: match the saved name back to a row so the select opens on it.
    $pick = fn ($list, $column, $name) => $list->firstWhere($column, $name)?->id;
    $was = fn (string $key, $fallback) => $failed ? old($key, $fallback) : $fallback;
@endphp
<form method="post" action="{{ route('progression.record.update', $row) }}" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px 16px;align-items:end;">
    @csrf
    <input type="hidden" name="row" value="{{ $row->id }}" />

    <div><label style="{{ $field }}">{!! $L('Effective date', 'Tarikh berkuat kuasa') !!}</label><input type="date" name="effective_on" required value="{{ $was('effective_on', $row->effective_on->toDateString()) }}" style="{{ $input }}" /></div>

    @if ($row->type === 'resigned')
        <div><label style="{{ $field }}">{!! $L('Last Working Day', 'Hari Terakhir Bekerja') !!}</label><input type="date" name="last_working_day" value="{{ $was('last_working_day', $snap['last_working_day'] ?? '') }}" style="{{ $input }}" /></div>
    @endif

    <div><label style="{{ $field }}">{!! $L('Status', 'Status') !!}</label>
        <select name="status" style="{{ $input }}">
            @foreach (['probation' => 'Probation', 'active' => 'Active', 'on_leave' => 'On Leave', 'resigned' => 'Resigned'] as $value => $label)
                <option value="{{ $value }}" @selected($was('status', $snap['status'] ?? null) === $value)>{{ $label }}</option>
            @endforeach
        </select>
    </div>

    @foreach ([
        ['department_id', 'department', 'departments', 'name', 'Department', 'Jabatan'],
        ['position_id', 'position', 'positions', 'title', 'Position', 'Jawatan'],
        ['branch_id', 'branch', 'branches', 'name', 'Branch', 'Cawangan'],
        ['employment_type_id', 'employment_type', 'employmentTypes', 'name', 'Employment Type', 'Jenis Pekerjaan'],
    ] as [$name, $key, $listKey, $column, $en, $ms])
        <div><label style="{{ $field }}">{!! $L($en, $ms) !!}</label>
            <select name="{{ $name }}" style="{{ $input }}">
                <option value="">—</option>
                @php $current = (int) $was($name, $pick($lists[$listKey], $column, $snap[$key] ?? null)); @endphp
                @foreach ($lists[$listKey] as $option)
                    <option value="{{ $option->id }}" @selected($current === $option->id)>{{ $option->{$column} }}</option>
                @endforeach
            </select>
        </div>
    @endforeach

    <div><label style="{{ $field }}">{!! $L('Reporting To', 'Melapor Kepada') !!}</label>
        @php $manager = (int) $was('reports_to_id', $snap['reports_to']['id'] ?? null); @endphp
        <select name="reports_to_id" style="{{ $input }}">
            <option value="">—</option>
            @foreach ($lists['managers'] as $option)
                <option value="{{ $option->id }}" @selected($manager === $option->id)>{{ $option->name }}</option>
            @endforeach
        </select>
    </div>

    @foreach ([['division', 'Division', 'Bahagian'], ['section', 'Section', 'Seksyen'], ['job_grade', 'Job Grade', 'Gred Jawatan'], ['category', 'Category', 'Kategori'], ['line', 'Line', 'Barisan']] as [$name, $en, $ms])
        <div><label style="{{ $field }}">{!! $L($en, $ms) !!}</label><input name="{{ $name }}" maxlength="80" value="{{ $was($name, $snap[$name] ?? '') }}" style="{{ $input }}" /></div>
    @endforeach

    <div><label style="{{ $field }}">{!! $L('Probation (months)', 'Percubaan (bulan)') !!}</label><input type="number" name="probation_months" min="0" max="24" value="{{ $was('probation_months', $snap['probation_months'] ?? '') }}" style="{{ $input }}" /></div>
    <div><label style="{{ $field }}">{!! $L('Probation (days)', 'Percubaan (hari)') !!}</label><input type="number" name="probation_days" min="0" max="31" value="{{ $was('probation_days', $snap['probation_days'] ?? '') }}" style="{{ $input }}" /></div>

    @if ($canSeeSalary ?? false)
        <div><label style="{{ $field }}">{!! $L('Basic Salary', 'Gaji Pokok') !!}</label><input type="number" step="0.01" min="0" name="salary" value="{{ $was('salary', $snap['basic_salary'] ?? '') }}" style="{{ $input }}" /></div>
    @endif

    @foreach ([
        ['pay_mode', ['monthly' => 'Monthly', 'daily' => 'Daily', 'hourly' => 'Hourly'], 'Pay Mode', 'Mod Bayaran'],
        ['payment_term', ['daily' => 'Daily', 'weekly' => 'Weekly', 'biweekly' => 'Biweekly', 'monthly' => 'Monthly'], 'Payment Term', 'Tempoh Bayaran'],
        ['payment_method', ['cash' => 'Cash', 'bank' => 'Bank', 'cheque' => 'Cheque'], 'Payment Method', 'Kaedah Bayaran'],
    ] as [$name, $choices, $en, $ms])
        <div><label style="{{ $field }}">{!! $L($en, $ms) !!}</label>
            <select name="{{ $name }}" style="{{ $input }}">
                <option value="">—</option>
                @foreach ($choices as $value => $label)
                    <option value="{{ $value }}" @selected($was($name, $snap[$name] ?? null) === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
    @endforeach

    <div style="grid-column:1/-1;"><label style="{{ $field }}">{!! $L('Remark', 'Catatan') !!}</label><input name="remark" maxlength="2000" value="{{ $was('remark', $row->remark) }}" style="{{ $input }}" /></div>

    <div style="grid-column:1/-1;display:flex;gap:8px;">
        <button type="submit" class="uj-btn-primary" style="height:34px;font-size:12.5px;padding:0 16px;">{!! $L('Save', 'Simpan') !!}</button>
        <button type="button" @click="editing = false" style="height:34px;background:transparent;border:0;cursor:pointer;font-size:12.5px;color:var(--muted);">{!! $L('Cancel', 'Batal') !!}</button>
    </div>
</form>
