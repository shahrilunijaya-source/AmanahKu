{{-- Process Payroll wizard (spec docs/superpowers/specs/2026-09-24-payroll-process-wizard-design.md).
     Worksy's four steps, Period → Condition → Selected Employees → Results, on one page.
     Steps 1 to 3 are one Alpine component around one form; "Process Payroll" posts it to
     payroll.runs.create. Step 4 is server-rendered from ?step=results&run= after the redirect.
     Expects $payrollWizard (BuildsWorkData::payrollWizardData), $wizardResultRun, the readiness
     vars and $errors. --}}
@php
    $wizardSeed = [
        ...$payrollWizard,
        'employerGaps' => $readinessEmployer,
        'results' => $wizardResultRun !== null,
        'hasOld' => $errors->any() || old('kind') !== null,
        'old' => [
            'period' => old('period', now()->format('Y-m')),
            'kind' => old('kind', ''),
            'payment_date' => old('payment_date', now()->toDateString()),
            'employee_id' => (string) old('employee_id', ''),
            'remarks' => old('remarks', ''),
            'mid_month_basis' => old('mid_month_basis', 'cutoff'),
            'mid_month_value' => (string) old('mid_month_value', old('mid_month_basis') === 'percentage' ? 50 : 15),
        ],
    ];
    $wizardSteps = [['Period', 'Tempoh'], ['Condition', 'Syarat'], ['Selected Employees', 'Pekerja Dipilih'], ['Results', 'Keputusan']];
@endphp
<style>
    .pw-label { display:block;font-size:13px;font-weight:500;color:var(--ink);margin:0 0 6px; }
    .pw-input { width:100%;height:42px;padding:0 12px;border:1px solid var(--hairline);border-radius:8px;font-size:14px;outline:none;background:#fff;color:var(--ink); }
    .pw-err { font-size:12px;color:var(--error);margin-top:6px; }
    .pw-soon { font-size:10.5px;color:var(--muted-soft);font-weight:400;margin-left:4px; }
    .pw-row { display:flex;gap:12px;align-items:center;flex-wrap:wrap; }
    .pw-tile { width:150px;height:78px;border-radius:10px;border:1px solid var(--hairline);background:#fff;color:var(--ink);font-size:12.5px;font-weight:500;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;cursor:pointer;transition:border-color .15s,background .15s; }
    .pw-tile:hover:not(:disabled) { border-color:var(--red); }
    .pw-tile[aria-pressed="true"] { background:var(--red);border-color:var(--red);color:#fff; }
    .pw-tile:disabled { cursor:not-allowed;color:var(--muted-soft);background:var(--canvas); }
    .pw-fbtn { width:100%;height:36px;border:1px solid var(--hairline);border-radius:8px;background:#fff;color:var(--ink);font-size:12.5px;font-weight:500;cursor:pointer;margin-bottom:7px; }
    .pw-fbtn:hover:not(:disabled) { border-color:var(--red);color:var(--red); }
    .pw-fbtn[aria-pressed="true"] { background:var(--red-tint);border-color:var(--red);color:var(--red); }
    .pw-fbtn:disabled { cursor:not-allowed;color:var(--muted-soft);background:var(--canvas); }
    .pw-box { border:1px solid var(--hairline);border-radius:8px;min-height:180px;max-height:240px;overflow-y:auto;padding:6px; }
    .pw-opt { display:flex;align-items:center;gap:9px;padding:7px 8px;border-radius:6px;font-size:13px;color:var(--ink);cursor:pointer;background:var(--canvas);margin-bottom:5px; }
    .pw-opt-off { cursor:not-allowed;color:var(--muted-soft); }
    .pw-arrow { width:34px;height:34px;border:1px solid var(--hairline);border-radius:8px;background:#fff;color:var(--ink);cursor:pointer;font-size:14px; }
    .pw-arrow:hover { border-color:var(--red);color:var(--red); }
    .pw-pillbtn { height:32px;padding:0 16px;border-radius:9999px;border:1px solid var(--hairline);background:#fff;color:var(--ink);font-size:12.5px;font-weight:500;cursor:pointer; }
    .pw-pillbtn:hover { border-color:var(--red);color:var(--red); }
    .pw-summary { min-width:150px;padding:12px 16px;border-radius:10px;background:var(--canvas);text-align:center; }
    .pw-av { width:34px;height:34px;border-radius:50%;background:var(--info);color:#fff;font-size:12px;font-weight:600;display:flex;align-items:center;justify-content:center;flex-shrink:0; }
    .pw-switch { position:relative;display:inline-block;width:38px;height:22px;flex-shrink:0; }
    .pw-switch input { position:absolute;opacity:0;width:0;height:0; }
    .pw-switch span { position:absolute;inset:0;background:var(--hairline);border-radius:9999px;cursor:pointer;transition:background .15s; }
    .pw-switch span::after { content:'';position:absolute;top:3px;left:3px;width:16px;height:16px;border-radius:50%;background:#fff;transition:transform .15s var(--ease); }
    .pw-switch input:checked + span { background:var(--red); }
    .pw-switch input:checked + span::after { transform:translateX(16px); }
    .pw-switch input:focus-visible + span { outline:2px solid var(--red);outline-offset:2px; }
    .pw-table { width:100%;border-collapse:collapse;font-size:12.5px; }
    .pw-table th { text-align:left;padding:8px 10px;color:var(--muted);font-size:11px;font-weight:500;text-transform:uppercase;letter-spacing:.5px; }
    .pw-table td { padding:9px 10px;border-top:1px solid var(--hairline-soft);vertical-align:middle; }
    .pw-pager button { width:28px;height:28px;border:1px solid var(--hairline);border-radius:6px;background:#fff;cursor:pointer;color:var(--ink); }
    .pw-pager button:disabled { color:var(--muted-soft);cursor:not-allowed; }
</style>
<script>
/**
 * The Process Payroll wizard. seed = BuildsWorkData::payrollWizardData() plus old input.
 * Filters, selection and the include toggles live here; only the form fields are posted.
 */
function payrollWizard(seed) {
    const FILTERS = [
        ['gender', 'Gender', 'Jantina', 'list', 'gender'],
        ['marital_status', 'Marital Status', 'Status Perkahwinan', 'list', 'marital_status'],
        ['children', 'No of Children', 'Bilangan Anak', 'off'],
        ['service_year', 'Service Year', 'Tahun Perkhidmatan', 'range', 'joined_at'],
        ['age', 'Age', 'Umur', 'range', 'date_of_birth'],
        ['date_offered', 'Date Offered', 'Tarikh Tawaran', 'off'],
        ['date_hired', 'Date Hired', 'Tarikh Diambil Bekerja', 'date', 'joined_at'],
        ['date_confirmed', 'Date Confirmed', 'Tarikh Disahkan', 'date', 'confirmed_at'],
        ['date_resigned', 'Date Resigned', 'Tarikh Berhenti', 'date', 'resigned_at'],
        ['salary', 'Basic Salary Range Criteria', 'Julat Gaji Pokok', 'range', 'salary'],
        ['pay_mode', 'Pay Mode', 'Mod Gaji', 'list', 'pay_mode'],
        ['payment_method', 'Payment Method', 'Kaedah Bayaran', 'list', 'payment_method'],
        ['employment_type', 'Employment Type', 'Jenis Pekerjaan', 'list', 'employment_type'],
        ['status', 'Employee Status', 'Status Pekerja', 'list', 'status'],
        ['department', 'Department', 'Jabatan', 'list', 'department'],
        ['job_grade', 'Grade', 'Gred', 'list', 'job_grade'],
        ['position', 'Position', 'Jawatan', 'list', 'position'],
        ['direct_report', 'Direct Report', 'Pelapor Terus', 'list', 'direct_report'],
        ['custom', 'Custom Fields', 'Medan Tersuai', 'off'],
        ['company', 'Company', 'Syarikat', 'off'],
        ['location', 'Location', 'Lokasi', 'list', 'location'],
        ['primary_location', 'Primary Location', 'Lokasi Utama', 'off'],
        ['branch', 'Branches', 'Cawangan', 'list', 'branch'],
        ['category', 'Categories', 'Kategori', 'list', 'category'],
        ['cost_centre', 'Cost Centres', 'Pusat Kos', 'off'],
        ['division', 'Divisions', 'Bahagian', 'list', 'division'],
        ['schedule', 'Schedules', 'Jadual', 'off'],
        ['nationality', 'Nationalities', 'Kewarganegaraan', 'list', 'nationality'],
        ['race', 'Races', 'Bangsa', 'list', 'race'],
        ['religion', 'Religions', 'Agama', 'list', 'religion'],
        ['line', 'Lines', 'Barisan', 'list', 'line'],
        ['section', 'Sections', 'Seksyen', 'list', 'section'],
    ].map(([key, en, ms, type, field]) => ({ key, en, ms, type, field }));
    const PER_PAGE = 50;
    const byId = Object.fromEntries([...seed.people, ...seed.leavers].map(p => [p.id, p]));

    return {
        filterDefs: FILTERS,
        people: seed.people,
        leavers: seed.leavers,
        outside: seed.outside,
        company: seed.company,
        employerGaps: seed.employerGaps,
        step: seed.results ? 5 : 1,
        period: seed.old.period,
        kind: seed.old.kind,
        paymentDate: seed.old.payment_date,
        employeeId: seed.old.employee_id,
        remarks: seed.old.remarks,
        mmBasis: seed.old.mid_month_basis,
        mmValue: seed.old.mid_month_value,
        policies: [
            { key: 'daily', en: 'Daily Rated Policy (Daily)', ms: 'Polisi Kadar Harian (Harian)', off: true },
            { key: 'hourly', en: 'Hourly Rated Policy (Hourly)', ms: 'Polisi Kadar Sejam (Sejam)', off: true },
            { key: 'monthly', en: 'Monthly Rated Policy (Monthly)', ms: 'Polisi Kadar Bulanan (Bulanan)', off: false },
        ],
        // Coming back with old input means Monthly was already included on the way out.
        policyIn: seed.hasOld ? ['monthly'] : [],
        policyPick: { out: [], in: [] },
        policyQ: { out: '', in: '' },
        policiesOpen: true,
        errs: {},
        method: 'all',
        filterQ: '',
        filters: [],
        selection: [],
        on: {},
        additional: [],
        addPick: '',
        q: '',
        page: 1,

        t(en, ms) { return this.$store.ui.lang === 'en' ? en : ms; },
        initials(name) { return (name || '?').trim().split(/\s+/).slice(0, 2).map(w => w[0]).join('').toUpperCase(); },
        person(id) { return byId[id]; },

        // ── Step 1 ──
        policyList(side) {
            const q = this.policyQ[side].toLowerCase();
            return this.policies.filter(p => (side === 'in') === this.policyIn.includes(p.key)
                && (p.en + p.ms).toLowerCase().includes(q));
        },
        movePolicies(to, all) {
            const from = to === 'in' ? 'out' : 'in';
            const keys = (all ? this.policyList(from).map(p => p.key) : this.policyPick[from])
                .filter(k => ! this.policies.find(p => p.key === k).off);
            this.policyIn = to === 'in' ? [...new Set([...this.policyIn, ...keys])] : this.policyIn.filter(k => ! keys.includes(k));
            this.policyPick[from] = [];
        },
        pickLeaver() {
            const l = this.leavers.find(x => String(x.id) === String(this.employeeId));
            if (l && l.last_working_day) {
                // createRun needs the last working day inside the period, and pays on it (EA s.20).
                this.period = l.last_working_day.slice(0, 7);
                this.paymentDate = l.last_working_day;
            }
        },
        nextFromPeriod() {
            const e = {};
            if (! this.period) e.period = ['Choose the payroll period.', 'Pilih tempoh gaji.'];
            if (! this.kind) e.kind = ['Choose the payroll cycle.', 'Pilih kitaran gaji.'];
            if (this.kind === 'final' && ! this.employeeId) e.employee_id = ['Choose the leaver.', 'Pilih pekerja yang berhenti.'];
            if (! this.policyIn.includes('monthly')) e.policies = ['Include the Monthly Rated Policy to continue.', 'Masukkan Polisi Kadar Bulanan untuk teruskan.'];
            this.errs = e;
            if (Object.keys(e).length) return;
            // Final Pay is one named leaver: no condition to choose.
            if (this.kind === 'final') this.select('final'); else this.step = 2;
        },

        // ── Step 2 ──
        eligible() {
            if (this.kind === 'final') return this.leavers.filter(l => String(l.id) === String(this.employeeId));
            if (this.kind === 'bonus') {
                const ids = seed.bonusByPeriod[this.period] || [];
                return this.people.filter(p => ids.includes(p.id));
            }
            return this.people;
        },
        options(f) {
            return [...new Set(this.eligible().map(p => p[f.field]).filter(v => v !== null && v !== ''))].sort();
        },
        addFilter(def) {
            if (def.type === 'off') return;
            const open = this.filters.find(f => f.key === def.key);
            if (open) { open.open = true; return; }
            this.filters.push({ key: def.key, def, open: true, included: [], qa: '', qi: '', pa: [], pi: [], min: '', max: '', from: '', to: '' });
        },
        hasFilter(key) { return this.filters.some(f => f.key === key); },
        removeFilter(key) { this.filters = this.filters.filter(f => f.key !== key); },
        avail(f) { return this.options(f.def).filter(o => ! f.included.includes(o) && String(o).toLowerCase().includes(f.qa.toLowerCase())); },
        incl(f) { return f.included.filter(o => String(o).toLowerCase().includes(f.qi.toLowerCase())); },
        moveFilter(f, toIncluded) {
            if (toIncluded) { f.included = [...new Set([...f.included, ...f.pa])]; f.pa = []; }
            else { f.included = f.included.filter(o => ! f.pi.includes(o)); f.pi = []; }
        },
        years(d) { return d ? Math.floor((Date.now() - new Date(d)) / 31557600000) : null; },
        matches(p) {
            return this.filters.every(f => {
                const d = f.def;
                if (d.type === 'list') return ! f.included.length || f.included.includes(p[d.field]);
                if (d.type === 'range') {
                    const v = d.key === 'salary' ? p.salary : this.years(p[d.field]);
                    return (f.min === '' || (v !== null && v >= +f.min)) && (f.max === '' || (v !== null && v <= +f.max));
                }
                const v = p[d.field];
                return (! f.from || (v && v >= f.from)) && (! f.to || (v && v <= f.to));
            });
        },
        select(method) {
            this.method = method;
            const ids = method === 'manual' ? [] : this.eligible().filter(p => method === 'final' || this.matches(p)).map(p => p.id);
            this.selection = ids;
            this.on = Object.fromEntries(ids.map(id => [id, true]));
            this.additional = [];
            this.addPick = '';
            this.q = '';
            this.page = 1;
            this.step = 3;
        },

        // ── Step 3 ──
        methodLabel() {
            return { all: ['All Available Users', 'Semua Pengguna Tersedia'], manual: ['Manual Selections', 'Pilihan Manual'], final: ['Final Pay', 'Gaji Akhir'] }[this.method];
        },
        rows() {
            const q = this.q.toLowerCase();
            return this.selection.map(id => byId[id]).filter(p => ! q || [p.name, p.position, p.staff_id, p.department].join(' ').toLowerCase().includes(q));
        },
        pages() { return Math.max(1, Math.ceil(this.rows().length / PER_PAGE)); },
        pageRows() { return this.rows().slice((this.page - 1) * PER_PAGE, this.page * PER_PAGE); },
        allOn() { return this.selection.length > 0 && this.selection.every(id => this.on[id]); },
        toggleAll(value) { this.selection.forEach(id => { this.on[id] = value; }); },
        addable() { return this.eligible().filter(p => ! this.selection.includes(p.id) && ! this.additional.includes(p.id)); },
        addEmployee() {
            if (! this.addPick) return;
            this.additional.push(+this.addPick);
            this.addPick = '';
        },
        includedIds() { return [...this.selection.filter(id => this.on[id]), ...this.additional]; },
        blockers() { return this.includedIds().map(id => byId[id]).filter(p => p && p.blocking.length); },
        canProcess() { return this.includedIds().length > 0 && this.blockers().length === 0 && this.employerGaps.length === 0; },
    };
}
</script>

<div class="uj-card" style="padding:24px 24px 28px;margin-bottom:16px;" x-data="payrollWizard(@js($wizardSeed))">
    {{-- Stepper --}}
    <ol style="display:flex;justify-content:center;list-style:none;margin:0 auto 26px;padding:0;max-width:720px;">
        @foreach ($wizardSteps as $i => [$en, $ms])
            @php $n = $i + 1; @endphp
            <li style="flex:1;position:relative;text-align:center;">
                @if ($n > 1)
                    <span aria-hidden="true" style="position:absolute;top:15px;right:50%;width:100%;height:2px;" :style="{ background: step >= {{ $n }} ? 'var(--red)' : 'var(--hairline)' }"></span>
                @endif
                <span style="position:relative;z-index:1;width:32px;height:32px;border-radius:50%;margin:0 auto 7px;font-size:13px;font-weight:600;display:flex;align-items:center;justify-content:center;"
                      :style="step > {{ $n }} ? { background: 'var(--red)', color: '#fff' } : (step === {{ $n }} ? { background: 'var(--red)', color: '#fff', boxShadow: '0 0 0 4px var(--red-tint)' } : { background: 'var(--canvas)', color: 'var(--muted)' })">
                    <template x-if="step > {{ $n }}"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg></template>
                    <template x-if="step <= {{ $n }}"><span>{{ $n }}</span></template>
                </span>
                <span style="font-size:12.5px;" :style="{ color: step === {{ $n }} ? 'var(--ink)' : 'var(--muted)', fontWeight: step === {{ $n }} ? 600 : 400 }" x-text="t(@js($en), @js($ms))">{{ $en }}</span>
            </li>
        @endforeach
    </ol>

    @if ($wizardResultRun)
        @include('partials.payroll.process.wizard-results', ['run' => $wizardResultRun])
    @else
        @if ($errors->any())
            <div role="alert" style="max-width:860px;margin:0 auto 16px;background:var(--red-tint);border:1px solid var(--red);color:var(--error);font-size:12.5px;border-radius:8px;padding:9px 12px;">
                <b x-text="t('The run was not created.', 'Run tidak dicipta.')">The run was not created.</b>
                @foreach ($errors->all() as $message)<div>{{ $message }}</div>@endforeach
            </div>
        @endif
        <form method="post" action="{{ route('payroll.runs.create') }}" id="create-run-form"
              @keydown.enter="if ($event.target.tagName !== 'TEXTAREA') $event.preventDefault()">
            @csrf
            @include('partials.payroll.process.wizard-period')
            @include('partials.payroll.process.wizard-condition')
            @include('partials.payroll.process.wizard-selected')
        </form>
    @endif
</div>
