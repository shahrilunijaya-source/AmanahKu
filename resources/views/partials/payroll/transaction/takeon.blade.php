{{-- Transaction Payroll Figures Take On, as Worksy has it: pick a staff member on the left,
     then Form EA laid out line by line on the right, one year-to-date amount per line, with
     year arrows and Edit. It is what this company paid before its payroll moved here; PCB
     for the rest of the year and the year-end EA form both count it. --}}
@php
    $columns = ['gross', 'additional_gross', 'pcb_paid', 'zakat_paid', 'optional_deductions', 'epf', 'exempt_allowances', 'socso', 'eis', 'medical_claimed'];
    $thisYear = (int) now()->format('Y');
    $rowYears = $openingFigures->collapse()->pluck('year')->all();
    $takeOnYears = range(min([$thisYear - 1, ...$rowYears]), max([$thisYear + 1, ...$rowYears]));
    $takeOnYear = in_array((int) request('takeon_year'), $takeOnYears, true) ? (int) request('takeon_year') : $thisYear;
    $takeOnData = $openingFigures->map(fn ($rows) => $rows->keyBy('year')->map(fn (\App\Models\PayrollOpeningFigure $o) => collect($columns)
        ->mapWithKeys(fn ($c) => [$c => number_format((float) $o->{$c}, 2, '.', '')])->all() + ($o->ea_lines ?? []))->all());
    $name = fn (string $key) => in_array($key, [...\App\Models\PayrollOpeningFigure::EA_AMOUNTS, ...\App\Models\PayrollOpeningFigure::EA_TEXT], true) ? 'ea['.$key.']' : $key;

    // [no., EN, MS, amount key or null, notes [[key, EN, MS]], computed JS for a read-only line]
    $sections = [
        ['B.', 'Employment income, benefits and living accommodation', 'Pendapatan penggajian, manfaat dan tempat kediaman', 'Excluding tax exempt allowances/perquisites/gifts/benefits', 'Tidak termasuk elaun/perkuisit/pemberian/manfaat yang dikecualikan cukai', [
            ['1(a)', 'Gross salary, wages or leave pay (including overtime pay)', 'Gaji kasar, upah atau gaji cuti (termasuk gaji lebih masa)', 'gross'],
            ['1(b)', 'Fees (including director fees), commission or bonus', 'Fi (termasuk fi pengarah), komisen atau bonus', 'additional_gross'],
            ['1(c)', 'Gross tips, perquisites, awards/rewards or other allowances', 'Tip kasar, perkuisit, penerimaan sagu hati atau elaun-elaun lain', 'b1c', [['b1c_details', 'Details of payment', 'Butiran bayaran']]],
            ['1(d)', 'Income tax borne by the employer in respect of his employee', 'Cukai pendapatan yang dibayar oleh majikan bagi pihak pekerja', 'b1d'],
            ['1(e)', 'Employee Share Option Scheme (ESOS) benefit', 'Manfaat Skim Opsyen Saham Pekerja (ESOS)', 'b1e'],
            ['1(f)', 'Gratuity', 'Ganjaran', 'b1f', [['b1f_from', 'For the period from', 'Bagi tempoh dari'], ['b1f_to', 'To', 'Hingga']]],
            ['2.', 'Arrears and others for preceding years paid in the current year', 'Tunggakan dan lain-lain bagi tahun-tahun terdahulu yang dibayar dalam tahun semasa', 'b2', [['b2_type_a', 'Type of income (a)', 'Jenis pendapatan (a)'], ['b2_type_b', 'Type of income (b)', 'Jenis pendapatan (b)']]],
            ['3.', 'Benefits in kind', 'Manfaat berupa barangan', 'b3', [['b3_details', 'Specify', 'Nyatakan']]],
            ['4.', 'Value of living accommodation provided', 'Nilai tempat kediaman yang disediakan', 'b4', [['b4_address', 'Address', 'Alamat']]],
            ['5.', 'Refund from unapproved provident/pension fund', 'Bayaran balik daripada kumpulan wang simpanan/pencen yang tidak diluluskan', 'b5'],
            ['6.', 'Compensation for loss of employment', 'Pampasan kerana kehilangan pekerjaan', 'b6'],
        ]],
        ['C.', 'Pension and others', 'Pencen dan lain-lain', null, null, [
            ['1.', 'Pension', 'Pencen', 'c1'],
            ['2.', 'Annuities or other periodical payments', 'Anuiti atau bayaran berkala yang lain', 'c2'],
            ['', 'Total', 'Jumlah', null, [], "num('c1') + num('c2')"],
        ]],
        ['D.', 'Total deduction', 'Jumlah potongan', null, null, [
            ['1.', 'Monthly tax deductions (MTD) remitted to LHDNM', 'Potongan cukai bulanan (PCB) yang dibayar kepada LHDNM', 'pcb_paid'],
            ['2.', 'CP38 deductions remitted to LHDNM', 'Potongan CP38 yang dibayar kepada LHDNM', 'd2'],
            ['3.', 'Zakat paid via salary deduction', 'Zakat yang dibayar melalui potongan gaji', 'zakat_paid'],
            ['4.', 'Approved donations/gifts/contributions via salary deduction', 'Derma/hadiah/sumbangan diluluskan yang dibayar melalui potongan gaji', 'd4'],
            ['5(a)', 'Total claim for deduction via Form TP1: relief', 'Jumlah tuntutan potongan melalui Borang TP1: pelepasan', 'optional_deductions'],
            ['5(b)', 'Total claim for deduction via Form TP1: zakat other than via salary', 'Jumlah tuntutan potongan melalui Borang TP1: zakat selain melalui potongan gaji', 'd5b'],
            ['6.', 'Total qualifying child relief', 'Jumlah pelepasan bagi anak yang layak', 'd6'],
        ]],
        ['E.', 'Contributions paid by employee to approved provident/pension fund and SOCSO', 'Caruman yang dibayar oleh pekerja kepada KWSP/kumpulan wang pencen yang diluluskan dan PERKESO', null, null, [
            ['1.', 'EPF (employee\'s share only)', 'KWSP (bahagian pekerja sahaja)', 'epf'],
            ['2.', 'SOCSO and EIS (employee\'s share only, from H below)', 'PERKESO dan SIP (bahagian pekerja sahaja, daripada H di bawah)', null, [], "num('socso') + num('eis')"],
        ]],
        ['F.', 'Tax exempt allowances / perquisites / gifts / benefits', 'Elaun / perkuisit / pemberian / manfaat yang dikecualikan cukai', null, null, [
            ['1.', 'Petrol card, travel allowance or toll', 'Kad petrol, elaun perjalanan atau tol', 'exempt_allowances'],
            ['2.', 'Child care allowance', 'Elaun penjagaan anak', 'f2'],
            ['3.', 'Perquisites and awards (long service, excellence and the like)', 'Perkuisit dan anugerah (perkhidmatan lama, kecemerlangan dan seumpamanya)', 'f3'],
            ['4.', 'Others', 'Lain-lain', 'f4'],
            ['', 'Total', 'Jumlah', null, [], "num('exempt_allowances') + num('f2') + num('f3') + num('f4')"],
        ]],
        ['G.', 'Employer contribution', 'Caruman majikan', null, null, [
            ['1.', 'EPF', 'KWSP', 'employer_epf'],
            ['2.', 'SOCSO', 'PERKESO', 'employer_socso'],
            ['3.', 'EIS', 'SIP', 'employer_eis'],
            ['4.', 'HRDF', 'HRDF', 'hrdf'],
        ]],
        ['H.', 'Employee contribution', 'Caruman pekerja', null, null, [
            ['1.', 'SOCSO', 'PERKESO', 'socso'],
            ['2.', 'EIS', 'SIP', 'eis'],
            ['3.', 'SKBBK', 'SKBBK', 'skbbk'],
        ]],
        // Not on Form EA and never taxed: only counts toward the yearly medical claim cap.
        ['', 'Not on Form EA', 'Tiada dalam Borang EA', null, null, [
            ['1.', 'Medical claimed this year (counts toward the medical claim limit)', 'Tuntutan perubatan tahun ini (dikira dalam had tuntutan perubatan)', 'medical_claimed'],
        ]],
    ];
    $cell = 'padding:8px 12px;border-bottom:1px solid var(--hairline-soft);font-size:12.5px;color:var(--ink);vertical-align:top;';
    $amountBox = 'width:140px;height:30px;padding:0 8px;border:1px solid var(--hairline);border-radius:6px;font-family:var(--font-mono);font-size:12.5px;text-align:right;background:var(--surface,#fff);color:var(--ink);';
    $noteBox = 'flex:1;min-width:140px;height:28px;padding:0 8px;border:1px solid var(--hairline);border-radius:6px;font-size:12px;background:var(--surface,#fff);color:var(--ink);';
@endphp
<div x-data="{
        q: '',
        year: {{ $takeOnYear }},
        years: @js($takeOnYears),
        pick: {{ (int) request('emp', $openingEmployees->first()?->id ?? 0) }},
        rows: @js($openingEmployees->map(fn ($e) => mb_strtolower(trim($e->display_name.' '.$e->name.' '.$e->position.' '.$e->staff_id)))->values()),
        data: @js($takeOnData),
        editing: false,
        vals: {},
        hit(h) { return this.q.trim() === '' || h.includes(this.q.trim().toLowerCase()); },
        cur() { return (this.data[this.pick] || {})[this.year] || {}; },
        num(k) { return parseFloat((this.editing ? this.vals : this.cur())[k]) || 0; },
        fmt(v) { return (parseFloat(v) || 0).toLocaleString('en-MY', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
        start() { this.vals = { ...this.cur() }; this.editing = true; },
        sync() { const u = new URL(location.href); u.searchParams.set('emp', this.pick); u.searchParams.set('takeon_year', this.year); history.replaceState(null, '', u); },
     }" x-init="$watch('pick', () => { editing = false; sync(); }); $watch('year', () => sync())" style="display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap;">
    <div class="uj-card" style="flex:1;min-width:240px;max-width:300px;padding:0;">
        <div style="padding:12px;border-bottom:1px solid var(--hairline);">
            <input type="search" x-model="q" @keydown.escape="q = ''" :placeholder="$store.ui.lang==='en' ? 'Search name or ID' : 'Cari nama atau ID'" style="width:100%;height:32px;padding:0 12px;border:1px solid var(--hairline);border-radius:8px;font-size:12.5px;outline:none;background:var(--surface,#fff);color:var(--ink);">
        </div>
        <div style="max-height:720px;overflow:auto;">
            @foreach ($openingEmployees as $e)
                <div x-show="hit(rows[{{ $loop->index }}])"><button type="button" @click="pick = {{ $e->id }}" :style="{ background: pick === {{ $e->id }} ? 'var(--canvas)' : 'none' }" style="display:flex;width:100%;text-align:left;align-items:center;gap:10px;padding:10px 14px;border:0;border-bottom:1px solid var(--hairline-soft);background:none;cursor:pointer;">
                    <div style="width:28px;height:28px;border-radius:50%;background:{{ $e->avatar_color ?? '#3a6ea5' }};color:#fff;display:flex;align-items:center;justify-content:center;font-size:10.5px;font-weight:600;flex-shrink:0;">{{ $e->initials }}</div>
                    <div style="min-width:0;"><div style="font-size:12.5px;color:var(--ink);font-weight:500;">{{ $e->name }}</div><div style="font-size:11px;color:var(--muted);">{{ $e->position }}{{ $e->staff_id ? ' · '.$e->staff_id : '' }}</div></div>
                </button></div>
            @endforeach
        </div>
    </div>

    <div style="flex:3;min-width:min(480px,100%);">
        {{-- Salary listing import: upload, review every row (pick staff for names that did not
             match, fix cells marked red), then save. The server checks it all again on save. --}}
        <div class="uj-card" style="padding:16px 20px;margin-bottom:16px;" x-data="{
                fields: @js(\App\Services\Payroll\TakeOnImport::COLUMNS),
                rows: null, staff: [], tp3: [], err: '', busy: false,
                money(s) { s = String(s ?? '').replace(/[,\s]/g, ''); if (s === '' || s === '-') return 0; const n = Number(s); return Number.isFinite(n) ? Math.round(n * 100) / 100 : null; },
                problems(r) {
                    if (!r.employee_id) return {};
                    const e = {}, v = {};
                    for (const f in this.fields) { v[f] = this.money(r.values[f]); if (v[f] === null) e[f] = `'${r.values[f]}' is not a number.`; }
                    if (!Object.keys(e).length) {
                        const parts = Math.round((v.basic + v.bonus + v.allowance + v.medical + v.mileage + v.others) * 100) / 100;
                        if (Math.abs(parts - v.gross) > 0.005) e.gross = `BASIC + BONUS + ALLOWANCE + MEDICAL + MILEAGE + OTHERS = ${this.fmt(parts)}, but GROSS is ${this.fmt(v.gross)} (${this.fmt(Math.abs(parts - v.gross))} apart).`;
                        if (v.unpaid > v.basic) e.unpaid = 'UNPAID LEAVE is more than BASIC.';
                    }
                    if (this.tp3.includes(Number(r.employee_id))) e.employee_id = `Already has a previous employer's Form TP3 for ${this.year}. Enter their figures by hand.`;
                    return { ...e, ...r.server };
                },
                fmt(n) { return n.toLocaleString('en-MY', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
                toFix() { return (this.rows || []).filter(r => Object.keys(this.problems(r)).length).length; },
                picked() { return (this.rows || []).filter(r => r.employee_id).length; },
                notPicked() { const ids = (this.rows || []).map(r => Number(r.employee_id)); return this.staff.filter(s => s.current && !ids.includes(s.id)); },
                label(s) { return s.name + (s.staff_id ? ' (' + s.staff_id + ')' : '') + (s.current ? '' : ' (resigned)'); },
                async send(url, body) {
                    this.busy = true; this.err = '';
                    try {
                        const res = await fetch(url, { method: 'POST', body, headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, ...(body instanceof FormData ? {} : { 'Content-Type': 'application/json' }) } });
                        const j = await res.json().catch(() => ({}));
                        if (!res.ok) { this.err = j.message || 'Something went wrong. Try again.'; }
                        return { ok: res.ok, j };
                    } catch { this.err = 'Could not reach the server. Try again.'; return { ok: false, j: {} }; }
                    finally { this.busy = false; }
                },
                async check() {
                    const f = this.$refs.file.files[0]; if (!f) { this.err = 'Choose the CSV file first.'; return; }
                    const body = new FormData(); body.append('file', f); body.append('year', this.year);
                    const { ok, j } = await this.send(@js(route('payroll.opening.preview')), body);
                    if (!ok) return;
                    this.rows = j.rows.map(r => ({ ...r, employee_id: r.employee_id ? String(r.employee_id) : '', server: {} }));
                    this.staff = j.staff; this.tp3 = j.tp3;
                },
                async save() {
                    const rows = this.rows.map(r => ({ line: r.line, employee_id: r.employee_id ? Number(r.employee_id) : null, values: r.values }));
                    const { ok, j } = await this.send(@js(route('payroll.opening.import')), JSON.stringify({ year: this.year, rows }));
                    if (ok) { location.reload(); return; }
                    for (const r of this.rows) r.server = (j.problems || {})[r.line] || {};
                },
             }">
            <div style="font-size:13px;font-weight:600;color:var(--ink);margin-bottom:4px;"><span x-text="$store.ui.lang==='en' ? 'Import from salary listing' : 'Import daripada senarai gaji'">Import from salary listing</span> (<span x-text="year">{{ $takeOnYear }}</span>)</div>
            <p style="font-size:12px;color:var(--muted);margin:0 0 10px;" x-text="$store.ui.lang==='en' ? @js('Open the Summary tab of the salary listing, check it covers only the months paid before AmanahKu, then File > Download > CSV and upload it here. You can review every row before anything is saved: pick the staff member for a name that did not match, and fix any cell marked red. Unpaid leave comes off salary, medical counts toward the medical claim limit, and mileage, others and advance are left out.') : @js('Buka tab Summary senarai gaji, pastikan ia hanya meliputi bulan yang dibayar sebelum AmanahKu, kemudian File > Download > CSV dan muat naik di sini. Anda boleh semak setiap baris sebelum apa-apa disimpan: pilih staf bagi nama yang tidak sepadan, dan betulkan sel yang bertanda merah. Cuti tanpa gaji ditolak daripada gaji, perubatan dikira dalam had tuntutan perubatan, dan mileage, others serta advance tidak diambil.')">Open the Summary tab of the salary listing, then File > Download > CSV and upload it here.</p>
            <div x-show="!rows" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                <input type="file" x-ref="file" accept=".csv,text/csv" style="font-size:12.5px;" />
                <button type="button" @click="check()" :disabled="busy" class="uj-btn-primary" style="height:30px;padding:0 16px;font-size:12px;" x-text="$store.ui.lang==='en' ? 'Check file' : 'Semak fail'">Check file</button>
            </div>
            <div x-show="err" x-cloak x-text="err" role="alert" style="margin-top:10px;font-size:12.5px;color:var(--danger,#b42318);"></div>

            <template x-if="rows">
                <div>
                    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin:6px 0 10px;font-size:12.5px;">
                        <span x-text="`${picked()} of ${rows.length} rows will be imported`"></span>
                        <span x-show="toFix()" style="color:var(--danger,#b42318);font-weight:600;" x-text="`${toFix()} to fix`"></span>
                        <div style="margin-left:auto;display:flex;gap:6px;">
                            <button type="button" @click="rows = null; err = ''" :disabled="busy" class="uj-btn-ghost" style="height:30px;padding:0 12px;font-size:12px;" x-text="$store.ui.lang==='en' ? 'Start over' : 'Mula semula'">Start over</button>
                            <button type="button" @click="save()" :disabled="busy || toFix() > 0 || picked() === 0" class="uj-btn-primary" style="height:30px;padding:0 16px;font-size:12px;" x-text="$store.ui.lang==='en' ? 'Import' : 'Import'">Import</button>
                        </div>
                    </div>
                    <details x-show="notPicked().length" style="font-size:12px;color:var(--muted);margin-bottom:10px;">
                        <summary style="cursor:pointer;" x-text="$store.ui.lang==='en' ? `${notPicked().length} current staff have no row yet (their PCB will be wrong without take-on figures)` : `${notPicked().length} staf semasa belum ada baris (PCB mereka akan salah tanpa angka take-on)`"></summary>
                        <div style="margin-top:6px;" x-text="notPicked().map(s => s.name).join(', ')"></div>
                    </details>
                    <div style="overflow:auto;max-height:560px;border:1px solid var(--hairline);border-radius:8px;">
                        <table style="border-collapse:collapse;font-size:12px;min-width:100%;">
                            <thead style="position:sticky;top:0;background:var(--canvas);z-index:1;">
                                <tr>
                                    <th style="padding:6px 8px;text-align:left;" x-text="$store.ui.lang==='en' ? 'Row' : 'Baris'">Row</th>
                                    <th style="padding:6px 8px;text-align:left;min-width:180px;" x-text="$store.ui.lang==='en' ? 'Name in file' : 'Nama dalam fail'">Name in file</th>
                                    <th style="padding:6px 8px;text-align:left;min-width:220px;" x-text="$store.ui.lang==='en' ? 'Staff in AmanahKu' : 'Staf dalam AmanahKu'">Staff in AmanahKu</th>
                                    <template x-for="(h, f) in fields" :key="f"><th style="padding:6px 8px;text-align:right;white-space:nowrap;" x-text="h.toUpperCase()"></th></template>
                                </tr>
                            </thead>
                            <template x-for="r in rows" :key="r.line">
                                <tbody :style="{ opacity: r.employee_id ? 1 : .55 }" style="border-top:1px solid var(--hairline-soft);">
                                    <tr>
                                        <td style="padding:6px 8px;color:var(--muted);" x-text="r.line"></td>
                                        <td style="padding:6px 8px;" x-text="r.name"></td>
                                        <td style="padding:6px 8px;">
                                            <select x-model="r.employee_id" @change="r.server = {}" :style="{ borderColor: problems(r).employee_id ? 'var(--danger,#b42318)' : 'var(--hairline)' }" style="width:100%;height:28px;border:1px solid;border-radius:6px;font-size:12px;background:var(--surface,#fff);color:var(--ink);">
                                                <option value="" x-text="$store.ui.lang==='en' ? 'Skip (not imported)' : 'Langkau (tidak diimport)'"></option>
                                                <template x-for="s in staff" :key="s.id"><option :value="String(s.id)" :selected="String(s.id) === r.employee_id" x-text="label(s)"></option></template>
                                            </select>
                                        </td>
                                        <template x-for="(h, f) in fields" :key="f">
                                            <td style="padding:4px;">
                                                <input x-model="r.values[f]" @input="r.server = {}" :disabled="!r.employee_id" :title="problems(r)[f] || ''" :aria-invalid="problems(r)[f] ? 'true' : 'false'" :style="problems(r)[f] ? { borderColor: 'var(--danger,#b42318)', background: 'rgba(180,35,24,.08)' } : {}" style="width:92px;height:26px;padding:0 6px;border:1px solid var(--hairline);border-radius:5px;font-family:var(--font-mono);font-size:11.5px;text-align:right;background:var(--surface,#fff);color:var(--ink);" />
                                            </td>
                                        </template>
                                    </tr>
                                    <tr x-show="r.note && !r.employee_id || Object.keys(problems(r)).length">
                                        <td></td>
                                        <td :colspan="Object.keys(fields).length + 2" style="padding:0 8px 8px;font-size:11.5px;">
                                            <div x-show="r.note && !r.employee_id" style="color:var(--muted);" x-text="r.note"></div>
                                            <template x-for="(msg, f) in problems(r)" :key="f">
                                                <div style="color:var(--danger,#b42318);"><strong x-text="(f === 'employee_id' ? 'STAFF' : fields[f].toUpperCase()) + ':'"></strong> <span x-text="msg"></span></div>
                                            </template>
                                        </td>
                                    </tr>
                                </tbody>
                            </template>
                        </table>
                    </div>
                </div>
            </template>
        </div>
        @if ($openingEmployees->isEmpty())
            <div class="uj-card" style="padding:22px;color:var(--muted);font-size:13px;" x-text="$store.ui.lang==='en' ? 'No active staff yet.' : 'Belum ada staf aktif.'">No active staff yet.</div>
        @else
            <form method="post" action="{{ route('payroll.opening') }}" class="uj-card" style="padding:20px;">
                @csrf
                <input type="hidden" name="employee_id" :value="pick" />
                <input type="hidden" name="year" :value="year" />
                <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                    <button type="button" @click="year--" :disabled="editing || year <= years[0]" class="uj-btn-ghost" style="height:30px;width:32px;padding:0;font-size:14px;" aria-label="Previous year">‹</button>
                    <button type="button" @click="year++" :disabled="editing || year >= years[years.length - 1]" class="uj-btn-ghost" style="height:30px;width:32px;padding:0;font-size:14px;" aria-label="Next year">›</button>
                    <span style="font-size:15px;font-weight:600;color:var(--ink);margin-left:6px;" x-text="year">{{ $takeOnYear }}</span>
                    @foreach ($openingEmployees as $e)
                        <span x-show="pick === {{ $e->id }}" style="font-size:12px;color:var(--muted);margin-left:10px;">{{ $e->name }}{{ $e->position ? ' · '.$e->position : '' }}</span>
                    @endforeach
                    <div style="margin-left:auto;display:flex;gap:6px;">
                        <button type="button" x-show="!editing" @click="start()" class="uj-btn-primary" style="height:30px;padding:0 16px;font-size:12px;" x-text="$store.ui.lang==='en' ? 'Edit' : 'Sunting'">Edit</button>
                        <button type="button" x-show="editing" x-cloak @click="editing = false" class="uj-btn-ghost" style="height:30px;padding:0 12px;font-size:12px;" x-text="$store.ui.lang==='en' ? 'Cancel' : 'Batal'">Cancel</button>
                        <button type="submit" x-show="editing" x-cloak class="uj-btn-primary" style="height:30px;padding:0 16px;font-size:12px;" x-text="$store.ui.lang==='en' ? 'Save' : 'Simpan'">Save</button>
                    </div>
                </div>
                <div style="margin:14px 0;">
                    @include('partials.hint', [
                        'tone' => 'warn',
                        'en' => 'Enter what this person was paid this year before AmanahKu took over, ONE year-to-date total per line (not month by month). Example: if AmanahKu runs payroll from September, enter the January to August totals from Worksy (the same screen there, or its year-to-date report). Enter it before their first AmanahKu pay run, or their monthly tax (PCB) will be wrong. Leave it empty for anyone paid only through AmanahKu this year. Someone who joined from another company this year: use Form TP3 on their profile\'s Experience tab instead.',
                        'ms' => 'Masukkan apa yang staf ini sudah dibayar tahun ini sebelum AmanahKu mengambil alih, SATU jumlah terkumpul bagi setiap baris (bukan ikut bulan). Contoh: jika AmanahKu mula buat gaji pada September, masukkan jumlah Januari hingga Ogos daripada Worksy (skrin yang sama di sana, atau laporan year-to-date). Masukkan sebelum larian gaji AmanahKu pertama staf itu, jika tidak cukai bulanan (PCB) akan salah. Biarkan kosong bagi staf yang dibayar melalui AmanahKu sahaja tahun ini. Staf yang masuk dari syarikat lain tahun ini: gunakan Borang TP3 di tab Pengalaman profil mereka.',
                    ])
                </div>
                @foreach ($sections as [$letter, $en, $ms, $subEn, $subMs, $lines])
                    <table style="width:100%;border-collapse:collapse;border:1px solid var(--hairline);margin-bottom:16px;">
                        <thead>
                            <tr style="background:var(--canvas);">
                                <th style="{{ $cell }}width:48px;text-align:left;">{{ $letter }}</th>
                                <th style="{{ $cell }}text-align:left;"><span style="text-transform:uppercase;" x-text="$store.ui.lang==='en' ? @js($en) : @js($ms)">{{ $en }}</span>
                                    @if ($subEn)<div style="font-weight:400;font-size:11.5px;color:var(--muted);" x-text="$store.ui.lang==='en' ? @js('('.$subEn.')') : @js('('.$subMs.')')">({{ $subEn }})</div>@endif
                                </th>
                                <th style="{{ $cell }}width:170px;text-align:right;" x-text="$store.ui.lang==='en' ? 'AMOUNT (RM)' : 'AMAUN (RM)'">AMOUNT (RM)</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($lines as $line)
                                @php [$no, $len, $lms, $key] = $line; $notes = $line[4] ?? []; $calc = $line[5] ?? null; @endphp
                                <tr>
                                    <td style="{{ $cell }}color:var(--muted);">{{ $no }}</td>
                                    <td style="{{ $cell }}{{ $calc ? 'font-weight:600;' : '' }}">
                                        <span x-text="$store.ui.lang==='en' ? @js($len) : @js($lms)">{{ $len }}</span>
                                        @foreach ($notes as [$nk, $nen, $nms])
                                            <div style="display:flex;align-items:center;gap:8px;margin-top:6px;flex-wrap:wrap;">
                                                <span style="font-size:11.5px;color:var(--muted);" x-text="$store.ui.lang==='en' ? @js($nen.':') : @js($nms.':')">{{ $nen }}:</span>
                                                <span x-show="!editing" style="font-size:12px;" x-text="cur()[@js($nk)] || '-'"></span>
                                                <template x-if="editing"><input name="{{ $name($nk) }}" x-model="vals[@js($nk)]" maxlength="200" style="{{ $noteBox }}" /></template>
                                            </div>
                                        @endforeach
                                    </td>
                                    <td style="{{ $cell }}text-align:right;font-family:var(--font-mono);{{ $calc ? 'font-weight:600;' : '' }}">
                                        @if ($calc)
                                            <span x-text="fmt({{ $calc }})">0.00</span>
                                        @else
                                            <span x-show="!editing" x-text="fmt(cur()[@js($key)])">0.00</span>
                                            <template x-if="editing"><input type="number" name="{{ $name($key) }}" x-model="vals[@js($key)]" step="0.01" min="0" placeholder="0.00" style="{{ $amountBox }}" /></template>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endforeach
            </form>
        @endif
    </div>
</div>
