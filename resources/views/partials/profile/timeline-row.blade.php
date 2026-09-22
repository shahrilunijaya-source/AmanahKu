{{-- One timeline card for an EmployeeProgression $row. Expects $row, $canSeeSalary, $open (bool). --}}
@php
    $L = fn ($en, $ms) => '<span x-text="'.e("\$store.ui.lang==='en' ? ".json_encode($en).' : '.json_encode($ms)).'">'.e($en).'</span>';
    $titles = ['hired' => ['Hired', 'Diambil Bekerja'], 'confirmed' => ['Confirmed', 'Disahkan'], 'updated' => ['Updated', 'Dikemas kini'], 'resigned' => ['Resigned', 'Berhenti'], 'rehired' => ['Rehired', 'Diambil Semula']];
    $labels = ['status' => 'Status', 'department' => 'Department', 'division' => 'Division', 'section' => 'Section', 'position' => 'Position', 'job_grade' => 'Job Grade', 'category' => 'Category', 'line' => 'Line', 'branch' => 'Branch', 'reports_to' => 'Reporting To', 'employment_type' => 'Employment Type', 'probation_months' => 'Probation (months)', 'probation_days' => 'Probation (days)', 'basic_salary' => 'Basic Salary', 'pay_mode' => 'Pay Mode', 'payment_term' => 'Payment Term', 'payment_method' => 'Payment Method', 'reason' => 'Reason', 'last_working_day' => 'Last Working Day'];
    $fmt = function (string $k, $v) {
        if ($v === null || $v === '') {
            return '—';
        }
        if ($k === 'reports_to') {
            return is_array($v) ? ($v['name'] ?? '—') : (string) $v;
        }
        if ($k === 'basic_salary') {
            return 'MYR '.number_format((float) $v, 2);
        }

        return is_scalar($v) ? (string) $v : json_encode($v);
    };
    $snap = $row->snapshot ?? [];
    if (! ($canSeeSalary ?? false)) {
        unset($snap['basic_salary']);
    }
    $changed = array_flip($row->changed_fields ?? []);
    $updateType = \App\Services\EmploymentRecordService::UPDATE_TYPES[$snap['update_type'] ?? ''] ?? null;
    unset($snap['update_type']);
@endphp
<div x-data="{ open: {{ ($open ?? false) ? 'true' : 'false' }} }" style="border-left:2px solid var(--info);padding-left:16px;margin-left:6px;position:relative;">
    <span style="position:absolute;left:-7px;top:8px;width:12px;height:12px;border-radius:50%;background:#fff;border:2px solid var(--info);"></span>
    <span style="display:inline-block;background:var(--info);color:#fff;font-size:11.5px;font-weight:600;border-radius:6px;padding:4px 10px;">{{ $row->effective_on->format('D, jS F Y') }}</span>
    <div class="uj-card" style="margin-top:8px;padding:16px;">
        <button type="button" @click="open = !open" style="display:flex;justify-content:space-between;align-items:center;width:100%;background:transparent;border:0;padding:0;cursor:pointer;font-size:17px;font-weight:600;color:var(--ink);">{!! $L(...($titles[$row->type] ?? [ucfirst($row->type), ucfirst($row->type)])) !!}@if ($updateType) <span style="font-size:12px;font-weight:500;color:var(--muted);margin-left:8px;">· {!! $L(...$updateType) !!}</span>@endif<span x-text="open ? '▴' : '▾'" style="font-size:13px;color:var(--muted);"></span></button>
        <div x-show="open" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px 32px;margin-top:12px;">
            {{-- Walk $labels, not $snap: MySQL JSON columns reorder object keys on write. --}}
            @foreach ($labels as $k => $label)
                @continue(! array_key_exists($k, $snap))
                @php $val = $snap[$k]; @endphp
                <div style="{{ isset($changed[$k]) ? 'background:var(--amber-tint,#fff7e6);border-radius:6px;padding:6px 8px;' : '' }}">
                    <div style="font-size:11px;color:var(--muted);">{{ $label }}</div>
                    <div style="font-size:13px;color:var(--ink);">{{ $fmt($k, $val) }}</div>
                </div>
            @endforeach
            @if ($row->remark)<div style="grid-column:1/-1;font-size:12.5px;color:var(--body);">{{ $row->remark }}</div>@endif
            @if ($editable ?? false)
                <div style="grid-column:1/-1;" x-data="{ editing: false }">
                    <div x-show="!editing"><button type="button" @click="editing = true" style="background:transparent;border:0;padding:0;cursor:pointer;font-size:12px;color:var(--info);">{!! $L('Correct note or date', 'Betulkan catatan atau tarikh') !!}</button></div>
                    <div x-show="editing" x-cloak>
                        <form method="post" action="{{ route('progression.record.update', $row) }}" style="display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end;">
                            @csrf
                            <div><label style="display:block;font-size:11px;color:var(--muted);margin-bottom:4px;">{!! $L('Effective date', 'Tarikh berkuat kuasa') !!}</label><input type="date" name="effective_on" required value="{{ $row->effective_on->toDateString() }}" style="height:34px;border:1px solid var(--line);border-radius:8px;padding:0 10px;font-size:12.5px;" /></div>
                            @if ($row->type === 'resigned')
                                <div><label style="display:block;font-size:11px;color:var(--muted);margin-bottom:4px;">{!! $L('Last Working Day', 'Hari Terakhir Bekerja') !!}</label><input type="date" name="last_working_day" value="{{ $snap['last_working_day'] ?? '' }}" style="height:34px;border:1px solid var(--line);border-radius:8px;padding:0 10px;font-size:12.5px;" /></div>
                            @endif
                            <div style="flex:1;min-width:200px;"><label style="display:block;font-size:11px;color:var(--muted);margin-bottom:4px;">{!! $L('Remark', 'Catatan') !!}</label><input name="remark" maxlength="2000" value="{{ $row->remark }}" style="width:100%;height:34px;border:1px solid var(--line);border-radius:8px;padding:0 10px;font-size:12.5px;" /></div>
                            <button type="submit" class="uj-btn-primary" style="height:34px;font-size:12.5px;padding:0 16px;">{!! $L('Save', 'Simpan') !!}</button>
                            <button type="button" @click="editing = false" style="height:34px;background:transparent;border:0;cursor:pointer;font-size:12.5px;color:var(--muted);">{!! $L('Cancel', 'Batal') !!}</button>
                        </form>
                    </div>
                </div>
            @endif
            <div style="grid-column:1/-1;font-size:11.5px;color:var(--muted);margin-top:4px;">{!! $L('Recorded on', 'Direkod pada') !!} {{ $row->created_at->format('jS F Y') }}@if ($row->recordedBy), {!! $L('by', 'oleh') !!} {{ $row->recordedBy->name }}@endif</div>
        </div>
    </div>
</div>
