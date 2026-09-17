{{-- Filters + tick table, shared by both batch forms. Sits inside the parent form's x-data,
     which must define ids (selected ids as strings), f {dept, branch, pos, status}, show(row), visible(). --}}
<div class="uj-section-head">{!! $L('1 · Pick staff', '1 · Pilih staf') !!}</div>
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:8px 12px;">
    <input type="search" x-model="f.q" placeholder="Search name…" style="{{ $fs }}" />
    <select x-model="f.dept" style="{{ $fs }}"><option value="">All departments</option>@foreach ($allDepartments as $o)<option value="{{ $o->id }}">{{ $o->name }}</option>@endforeach</select>
    <select x-model="f.branch" style="{{ $fs }}"><option value="">All branches</option>@foreach ($allBranches as $o)<option value="{{ $o->id }}">{{ $o->name }}</option>@endforeach</select>
    <select x-model="f.pos" style="{{ $fs }}"><option value="">All positions</option>@foreach ($allPositions as $o)<option value="{{ $o->id }}">{{ $o->title }}</option>@endforeach</select>
    <select x-model="f.status" style="{{ $fs }}"><option value="">All statuses</option>@foreach ($stL as $k => [$en, $ms])<option value="{{ $k }}">{{ $en }}</option>@endforeach</select>
</div>
<div style="display:flex;gap:8px;align-items:center;font-size:12.5px;color:var(--muted);flex-wrap:wrap;">
    <button type="button" class="uj-btn-ghost" style="height:30px;padding:0 12px;font-size:12px;" @click="ids = [...new Set([...ids, ...visible()])]">{!! $L('Select all shown', 'Pilih semua yang dipapar') !!}</button>
    <button type="button" class="uj-btn-ghost" style="height:30px;padding:0 12px;font-size:12px;" @click="ids = []">{!! $L('Clear', 'Kosongkan') !!}</button>
    <span><span x-text="ids.length"></span> {!! $L('selected', 'dipilih') !!}</span>
    <span x-show="ids.length > 200" x-cloak style="color:var(--red);">· {!! $L('maximum 200 per run', 'maksimum 200 setiap larian') !!}</span>
</div>
<div style="max-height:340px;overflow:auto;border:1px solid var(--hairline-soft);border-radius:8px;">
    <table style="width:100%;border-collapse:collapse;font-size:12.5px;">
        <thead><tr style="background:var(--canvas);color:var(--muted);font-size:11px;text-align:left;">
            <th style="padding:8px;width:32px;"></th><th style="padding:8px;">{!! $L('Name', 'Nama') !!}</th><th style="padding:8px;">{!! $L('Department', 'Jabatan') !!}</th><th style="padding:8px;">{!! $L('Branch', 'Cawangan') !!}</th><th style="padding:8px;">{!! $L('Position', 'Jawatan') !!}</th><th style="padding:8px;">Status</th>
        </tr></thead>
        <tbody>
        @foreach ($batchStaff as $s)
            <tr data-emp="{{ $s->id }}" data-dept="{{ $s->department_id }}" data-branch="{{ $s->branch_id }}" data-pos="{{ $s->position_id }}" data-status="{{ $s->status }}" data-name="{{ $s->name }}"
                data-cur-department_id="{{ $s->department?->name ?? '—' }}" data-cur-branch_id="{{ $s->branch?->name ?? '—' }}" data-cur-position_id="{{ $s->positionBand?->title ?? '—' }}"
                data-cur-reports_to_id="{{ $s->reportsTo?->name ?? '—' }}" data-cur-employment_type_id="{{ $s->employmentType?->name ?? '—' }}" data-cur-division="{{ $s->division ?? '—' }}" data-cur-section="{{ $s->section ?? '—' }}"
                data-cur-payment_term="{{ $s->payment_term ?? '—' }}" data-cur-payment_method="{{ $s->payment_method ?? '—' }}" data-salary="{{ number_format((float) ($s->salary ?? 0), 2, '.', '') }}"
                x-show="show($el)" style="border-top:1px solid var(--hairline-soft);">
                <td style="padding:6px 8px;"><input type="checkbox" name="employee_ids[]" value="{{ $s->id }}" x-model="ids" /></td>
                <td style="padding:6px 8px;color:var(--ink);font-weight:500;">{{ $s->name }}</td>
                <td style="padding:6px 8px;">{{ $s->department?->name ?? '—' }}</td>
                <td style="padding:6px 8px;">{{ $s->branch?->name ?? '—' }}</td>
                <td style="padding:6px 8px;">{{ $s->positionBand?->title ?? '—' }}</td>
                <td style="padding:6px 8px;"><span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:{{ $stColor[$s->status] ?? 'var(--muted)' }};margin-right:6px;"></span>{!! $L(...($stL[$s->status] ?? [ucfirst($s->status), ucfirst($s->status)])) !!}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
