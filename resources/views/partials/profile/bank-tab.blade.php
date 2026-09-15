{{-- Bank & Statutory: SalaryStructure read grid + edit modal posting to payroll.salary (back() returns here).
     Expects $p, $canEditSalaryStructure, $fs. --}}
@php
    use App\Support\StatutoryOptions;
    $L = fn ($en, $ms) => '<span x-text="'.e("\$store.ui.lang==='en' ? ".json_encode($en).' : '.json_encode($ms)).'">'.e($en).'</span>';
    $s = $p->salaryStructure;
    $canEdit = $canEditSalaryStructure ?? false;
    $v = fn ($x) => filled($x) ? $x : '—';
    $yn = fn ($b) => $b ? 'Yes' : 'No';
    $acct = $s?->bank_account_no;
    $acctShown = $acct ? ($canEdit ? $acct : '•••• '.substr($acct, -4)) : '—';
    $relief = $s?->child_relief_breakdown ?? [];
    $sections = [
        ['Bank', 'Bank', [
            ['Bank', 'Bank', $v($s?->bank_name)], ['Account No', 'No. Akaun', $acctShown], ['Account Holder', 'Pemegang Akaun', $v($s?->bank_holder_name ?: $p->name)],
        ]],
        ['Income Tax', 'Cukai Pendapatan', [
            ['Tax No', 'No. Cukai', $v($s?->tax_no)], ['Resident', 'Pemastautin', $s ? $yn($s->tax_resident) : '—'],
            ['Employee Status', 'Status Pekerja', StatutoryOptions::EMPLOYEE_TAX_STATUS[$s?->employee_tax_status] ?? '—'],
            ['Tax Category', 'Kategori Cukai', StatutoryOptions::TAX_CATEGORIES[$s?->tax_category] ?? '—'],
            ['Spouse Working', 'Pasangan Bekerja', $s ? $yn($s->spouse_working) : '—'], ['Child Relief Units', 'Unit Pelepasan Anak', $v($s?->children_relief_count)],
            ['Disabled (self)', 'OKU (sendiri)', $s ? $yn($s->disabled_self) : '—'], ['Disabled (spouse)', 'OKU (pasangan)', $s ? $yn($s->disabled_spouse) : '—'],
        ]],
        ['EPF', 'KWSP', [['EPF No', 'No. KWSP', $v($s?->epf_no)], ['Scheme', 'Skim', StatutoryOptions::EPF_SCHEMES[$s?->epf_scheme] ?? '—']]],
        ['SOCSO / EIS', 'PERKESO / SIP', [['SOCSO No', 'No. PERKESO', $v($s?->socso_no)], ['Category', 'Kategori', StatutoryOptions::SOCSO_CATEGORIES[$s?->socso_category] ?? '—']]],
        ['Zakat / CP38 / SKBBK', 'Zakat / CP38 / SKBBK', [
            ['Zakat (monthly)', 'Zakat (bulanan)', $s ? 'RM '.number_format($s->zakat_monthly, 2) : '—'], ['CP38 (monthly)', 'CP38 (bulanan)', $s ? 'RM '.number_format($s->cp38_monthly, 2) : '—'], ['SKBBK', 'SKBBK', $s ? $yn($s->skbbk_opt_in) : '—'],
        ]],
    ];
    $lbl = 'display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;';
    $head = 'font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:0.6px;';
    $grid = 'display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px 16px;';
    $old = fn (string $k, $default = null) => old($k, $s?->{$k} ?? $default);
    $sel = function (string $name, array $options, $current, bool $keyed) use ($fs) {
        $h = '<select name="'.$name.'" style="'.$fs.'"><option value="">—</option>';
        $seen = false;
        foreach ($options as $k => $o) {
            $val = $keyed ? (string) $k : $o;
            $on = (string) $current === $val;
            $seen = $seen || $on;
            $h .= '<option value="'.e($val).'"'.($on ? ' selected' : '').'>'.e($o).'</option>';
        }
        if (! $seen && filled($current)) { // a value outside the list (free-text history) stays selectable
            $h .= '<option value="'.e((string) $current).'" selected>'.e((string) $current).'</option>';
        }
        return $h.'</select>';
    };
    $chk = fn (string $name, bool $on) => '<input type="hidden" name="'.$name.'" value="0" /><input type="checkbox" name="'.$name.'" value="1"'.(old($name, $on) ? ' checked' : '').' />';
    $chkRow = 'display:flex;align-items:center;gap:8px;font-size:12.5px;color:var(--body);';
@endphp

@if (! $s)
    <p style="font-size:12.5px;color:var(--muted);margin:0;">{!! $L('No salary structure set yet.', 'Struktur gaji belum ditetapkan.') !!}</p>
@endif
@if ($canEdit)
    <div style="display:flex;justify-content:flex-end;"><button type="button" @click="editBank = true" class="uj-btn-ghost" style="height:32px;padding:0 14px;font-size:12.5px;">{!! $L($s ? 'Edit' : 'Set up', $s ? 'Sunting' : 'Tetapkan') !!}</button></div>
@endif

@foreach ($sections as [$en, $ms, $rows])
    <div>
        <div style="{{ $head }}margin-bottom:12px;">{!! $L($en, $ms) !!}</div>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px 32px;">
            @foreach ($rows as [$ren, $rms, $val])
                <div><div style="font-size:11px;color:var(--muted);margin-bottom:2px;">{!! $L($ren, $rms) !!}</div><div style="font-size:13px;color:var(--ink);">{{ $val }}</div></div>
            @endforeach
        </div>
        @if ($en === 'Income Tax' && $relief)
            <div style="margin-top:10px;font-size:12px;color:var(--body);">
                @foreach (StatutoryOptions::CHILD_RELIEF_CATEGORIES as $key => [$cen, $cms])
                    <div>{!! $L($cen, $cms) !!}: {{ $relief[$key]['100'] ?? 0 }} × 100%, {{ $relief[$key]['50'] ?? 0 }} × 50%</div>
                @endforeach
            </div>
        @endif
    </div>
@endforeach

@if ($canEdit)
    <template x-teleport="body">
    <div x-show="editBank" x-cloak @click.self="editBank = false" @keydown.escape.window="editBank = false"
         style="position:fixed;inset:0;z-index:120;display:flex;padding:40px 16px;background:rgba(18,18,30,.42);overflow-y:auto;">
        <form method="post" action="{{ route('payroll.salary') }}" class="uj-card" style="width:100%;max-width:760px;margin:auto;padding:20px;display:flex;flex-direction:column;gap:14px;max-height:calc(100vh - 80px);overflow-y:auto;">
            @csrf
            <input type="hidden" name="employee_id" value="{{ $p->id }}" />
            <div style="font-size:13px;font-weight:600;color:var(--ink);">{!! $L('Bank & statutory details', 'Butiran bank & statutori') !!} · {{ $p->name }}</div>
            @if ($errors->any() && session('form') === 'bank')<div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12px;border-radius:8px;padding:8px 11px;">{{ $errors->first() }}</div>@endif
            <div style="{{ $grid }}">
                <div><label style="{{ $lbl }}">{!! $L('Basic salary (RM / month)', 'Gaji pokok (RM / bulan)') !!}</label><input name="basic_salary" type="number" step="0.01" min="0" required value="{{ old('basic_salary', $s ? number_format($s->basic_salary, 2, '.', '') : '') }}" style="{{ $fs }}" /></div>
                <div><label style="{{ $lbl }}">{!! $L('Effective from', 'Berkuat kuasa dari') !!}</label><input name="effective_from" type="date" value="{{ old('effective_from', $s?->effective_from?->toDateString() ?? now()->toDateString()) }}" style="{{ $fs }}" /></div>
            </div>
            <div style="{{ $head }}">{!! $L('Bank', 'Bank') !!}</div>
            <div style="{{ $grid }}">
                <div><label style="{{ $lbl }}">{!! $L('Bank', 'Bank') !!}</label>{!! $sel('bank_name', StatutoryOptions::BANKS, $old('bank_name'), false) !!}</div>
                <div><label style="{{ $lbl }}">{!! $L('Account No', 'No. Akaun') !!}</label><input name="bank_account_no" value="{{ $old('bank_account_no') }}" maxlength="40" style="{{ $fs }}" /></div>
                <div x-data="{ custom: {{ $old('bank_holder_name') ? 'true' : 'false' }} }" style="grid-column:1/-1;">
                    <label style="{{ $chkRow }}margin-bottom:6px;"><input type="checkbox" x-model="custom" /> {!! $L('Account holder name differs from employee name', 'Nama pemegang akaun berbeza daripada nama pekerja') !!}</label>
                    <input name="bank_holder_name" x-show="custom" :disabled="!custom" value="{{ $old('bank_holder_name') }}" maxlength="160" placeholder="{{ $p->name }}" style="{{ $fs }}" />
                </div>
            </div>
            <div style="{{ $head }}">{!! $L('Income Tax', 'Cukai Pendapatan') !!}</div>
            <div style="{{ $grid }}">
                <div><label style="{{ $lbl }}">{!! $L('Tax No', 'No. Cukai') !!}</label><input name="tax_no" value="{{ $old('tax_no') }}" maxlength="40" style="{{ $fs }}" /></div>
                <div><label style="{{ $lbl }}">{!! $L('Resident', 'Pemastautin') !!}</label>{!! $sel('tax_resident', ['1' => 'Yes', '0' => 'No'], old('tax_resident', $s ? ($s->tax_resident ? '1' : '0') : '1'), true) !!}</div>
                <div><label style="{{ $lbl }}">{!! $L('Employee Status', 'Status Pekerja') !!}</label>{!! $sel('employee_tax_status', StatutoryOptions::EMPLOYEE_TAX_STATUS, $old('employee_tax_status'), true) !!}</div>
                <div><label style="{{ $lbl }}">{!! $L('Tax Category', 'Kategori Cukai') !!}</label>{!! $sel('tax_category', StatutoryOptions::TAX_CATEGORIES, $old('tax_category'), true) !!}</div>
                <div><label style="{{ $lbl }}">{!! $L('Child relief units (PCB)', 'Unit pelepasan anak (PCB)') !!}</label><input name="children_relief_count" type="number" min="0" max="20" value="{{ $old('children_relief_count', 0) }}" style="{{ $fs }}" /></div>
                <label style="{{ $chkRow }}">{!! $chk('spouse_working', (bool) $s?->spouse_working) !!} {!! $L('Spouse working', 'Pasangan bekerja') !!}</label>
                <label style="{{ $chkRow }}">{!! $chk('disabled_self', (bool) $s?->disabled_self) !!} {!! $L('Disabled (self)', 'OKU (sendiri)') !!}</label>
                <label style="{{ $chkRow }}">{!! $chk('disabled_spouse', (bool) $s?->disabled_spouse) !!} {!! $L('Disabled (spouse)', 'OKU (pasangan)') !!}</label>
            </div>
            <div style="font-size:12px;color:var(--muted);">{!! $L('Dependent children by LHDN category (count at 100% and at 50% shared relief). Reference only; PCB uses the relief units above.', 'Anak tanggungan mengikut kategori LHDN (bilangan pada 100% dan 50%). Rujukan sahaja; PCB menggunakan unit pelepasan di atas.') !!}</div>
            <div style="display:grid;grid-template-columns:1fr 90px 90px;gap:8px 12px;align-items:center;font-size:12.5px;">
                <div></div><div style="{{ $head }}">100%</div><div style="{{ $head }}">50%</div>
                @foreach (StatutoryOptions::CHILD_RELIEF_CATEGORIES as $key => [$cen, $cms])
                    <div>{!! $L($cen, $cms) !!}</div>
                    <input type="number" min="0" max="20" name="child_relief[{{ $key }}][100]" value="{{ old("child_relief.$key.100", $relief[$key]['100'] ?? 0) }}" style="{{ $fs }}" />
                    <input type="number" min="0" max="20" name="child_relief[{{ $key }}][50]" value="{{ old("child_relief.$key.50", $relief[$key]['50'] ?? 0) }}" style="{{ $fs }}" />
                @endforeach
            </div>
            <div style="{{ $head }}">EPF · SOCSO / EIS</div>
            <div style="{{ $grid }}">
                <div><label style="{{ $lbl }}">{!! $L('EPF No', 'No. KWSP') !!}</label><input name="epf_no" value="{{ $old('epf_no') }}" maxlength="40" style="{{ $fs }}" /></div>
                <div><label style="{{ $lbl }}">{!! $L('EPF Scheme', 'Skim KWSP') !!}</label>{!! $sel('epf_scheme', StatutoryOptions::EPF_SCHEMES, $old('epf_scheme'), true) !!}</div>
                <div><label style="{{ $lbl }}">{!! $L('SOCSO No', 'No. PERKESO') !!}</label><input name="socso_no" value="{{ $old('socso_no') }}" maxlength="40" style="{{ $fs }}" /></div>
                <div><label style="{{ $lbl }}">{!! $L('SOCSO Category', 'Kategori PERKESO') !!}</label>{!! $sel('socso_category', StatutoryOptions::SOCSO_CATEGORIES, $old('socso_category'), true) !!}</div>
                <div><label style="{{ $lbl }}">{!! $L('Nationality (statutory)', 'Kewarganegaraan (statutori)') !!}</label>{!! $sel('nationality', ['citizen' => 'Citizen', 'pr' => 'Permanent resident', 'foreign' => 'Foreign'], $old('nationality', 'citizen'), true) !!}</div>
            </div>
            <div style="{{ $head }}">Zakat · CP38 · SKBBK</div>
            <div style="{{ $grid }}">
                <div><label style="{{ $lbl }}">{!! $L('Zakat (RM / month)', 'Zakat (RM / bulan)') !!}</label><input name="zakat_monthly" type="number" step="0.01" min="0" value="{{ $old('zakat_monthly', 0) }}" style="{{ $fs }}" /></div>
                <div><label style="{{ $lbl }}">{!! $L('CP38 (RM / month)', 'CP38 (RM / bulan)') !!}</label><input name="cp38_monthly" type="number" step="0.01" min="0" value="{{ $old('cp38_monthly', 0) }}" style="{{ $fs }}" /></div>
                <label style="{{ $chkRow }}">{!! $chk('skbbk_opt_in', (bool) $s?->skbbk_opt_in) !!} SKBBK</label>
            </div>
            <div style="display:flex;gap:8px;justify-content:flex-end;">
                <button type="button" @click="editBank = false" class="uj-btn-ghost" style="height:40px;padding:0 16px;font-size:13px;">{!! $L('Cancel', 'Batal') !!}</button>
                <button type="submit" class="uj-btn-primary" style="height:40px;padding:0 18px;font-size:13px;">{!! $L('Save changes', 'Simpan perubahan') !!}</button>
            </div>
        </form>
    </div>
    </template>
@endif
