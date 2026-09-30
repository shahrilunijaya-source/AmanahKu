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
        {{-- Salary listing import: upload, review every row, then save. A name that did not match
             is either confirmed against a suggested staff member, picked in the search, or left
             out on purpose; a cell that fails a check is marked where it sits and can be fixed in
             place. The server repeats every check on save. --}}
        <div class="uj-card to-card" x-data="takeOnImport({ fields: @js(\App\Services\Payroll\TakeOnImport::COLUMNS), previewUrl: @js(route('payroll.opening.preview')), importUrl: @js(route('payroll.opening.import')) })"
             @scroll.window="placePick()" @resize.window="placePick()">
            <div class="to-head">
                <div>
                    <p class="to-title"><span x-text="t('Import from salary listing', 'Import daripada senarai gaji')">Import from salary listing</span> · <span x-text="year">{{ $takeOnYear }}</span></p>
                    <p class="to-sub" x-show="!rows" x-text="t('Summary tab of the salary listing, months before AmanahKu only: File > Download > CSV. You review every row before anything is saved.', 'Tab Summary senarai gaji, bulan sebelum AmanahKu sahaja: File > Download > CSV. Anda semak setiap baris sebelum apa-apa disimpan.')"></p>
                    <p class="to-sub" x-show="rows" x-cloak x-text="t('Unpaid leave comes off salary. Medical counts toward the medical claim limit. Mileage, others and advance are left out.', 'Cuti tanpa gaji ditolak daripada gaji. Perubatan dikira dalam had tuntutan perubatan. Mileage, others dan advance tidak diambil.')"></p>
                </div>
            </div>

            <div x-show="!rows" class="to-upload">
                <input type="file" x-ref="file" accept=".csv,text/csv" class="to-file-in" @change="err = ''" :aria-label="t('Salary listing CSV', 'CSV senarai gaji')" />
                <button type="button" @click="check()" :disabled="busy" class="uj-btn-primary to-btn" x-text="busy ? t('Checking…', 'Menyemak…') : t('Check file', 'Semak fail')">Check file</button>
            </div>
            <p x-show="err" x-cloak x-text="err" role="alert" class="to-err"></p>

            <template x-if="rows">
                <div>
                    <div class="to-bar">
                        <div class="uj-seg" role="tablist" :aria-label="t('Show rows', 'Papar baris')">
                            <template x-for="v in views" :key="v.key">
                                <button type="button" role="tab" :aria-selected="view === v.key ? 'true' : 'false'" :data-on="view === v.key ? '' : null" @click="view = v.key">
                                    <span x-text="t(v.en, v.ms)"></span><span class="to-count" :data-hot="v.key === 'needs' && tally().needs ? '' : null" x-text="tally()[v.key]"></span>
                                </button>
                            </template>
                        </div>
                        <div class="to-acts">
                            <span class="to-why" x-show="tally().needs" x-text="t(`Resolve ${tally().needs} ${tally().needs === 1 ? 'row' : 'rows'} to import`, `Selesaikan ${tally().needs} baris untuk import`)"></span>
                            <button type="button" @click="reset()" :disabled="busy" class="uj-btn-ghost to-btn" x-text="t('Start over', 'Mula semula')">Start over</button>
                            <button type="button" @click="save()" :disabled="busy || tally().needs > 0 || staffCount() === 0" class="uj-btn-primary to-btn"
                                    x-text="busy ? t('Importing…', 'Mengimport…') : t(`Import ${staffCount()} staff`, `Import ${staffCount()} staf`)">Import</button>
                        </div>
                    </div>

                    <div class="to-notice" x-show="notPicked().length" role="status">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 9v4M12 17h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/></svg>
                        <div>
                            <p class="to-notice-t" x-text="t(`${notPicked().length} current staff have no row yet`, `${notPicked().length} staf semasa belum ada baris`)"></p>
                            <p class="to-notice-s" x-text="t('Without take-on figures their PCB for the rest of the year will be wrong. If one of them is in the file under another name, pick them on that row.', 'Tanpa angka take-on, PCB mereka untuk baki tahun akan salah. Jika salah seorang ada dalam fail dengan nama lain, pilih mereka pada baris itu.')"></p>
                            <p class="to-notice-names"><span x-text="notPicked().slice(0, allNames ? undefined : 6).map(s => s.name).join(' · ')"></span><button type="button" class="to-link to-link-quiet" x-show="notPicked().length > 6" @click="allNames = !allNames" x-text="allNames ? t('Show fewer', 'Kurangkan') : t(`and ${notPicked().length - 6} more`, `dan ${notPicked().length - 6} lagi`)"></button></p>
                        </div>
                    </div>

                    <div class="to-wrap" @scroll="placePick()">
                        <table class="to-table">
                            <thead>
                                <tr>
                                    <th class="to-who" scope="col" x-text="t('Row in file · staff in AmanahKu', 'Baris fail · staf dalam AmanahKu')">Row in file · staff in AmanahKu</th>
                                    <template x-for="(h, f) in fields" :key="f"><th class="to-num" scope="col" x-text="h.toUpperCase()"></th></template>
                                </tr>
                            </thead>
                            <template x-for="r in rows" :key="r.line">
                                <tbody x-show="visible(r)" :data-st="status(r)">
                                    <tr>
                                        <td class="to-who">
                                            <div class="to-line">
                                                <span class="to-ln" x-text="r.line"></span>
                                                <span class="to-name" x-text="r.name" :title="r.name"></span>
                                                <span class="uj-stamp" :data-tone="stamp(r).tone" x-text="t(stamp(r).en, stamp(r).ms)"></span>
                                            </div>
                                            <div class="to-combo">
                                                <input type="text" role="combobox" autocomplete="off" aria-autocomplete="list" aria-controls="to-pop"
                                                       :aria-expanded="pk.row === r ? 'true' : 'false'" :aria-invalid="problems(r).employee_id ? 'true' : 'false'"
                                                       :aria-label="t(`Staff member for row ${r.line}`, `Staf untuk baris ${r.line}`)"
                                                       :value="pk.row === r ? pk.q : (r.employee_id ? label(r.employee_id) : '')"
                                                       :placeholder="r.skipped ? t('Skipped, search to include', 'Dilangkau, cari untuk masukkan') : t('Search staff by name or ID', 'Cari staf ikut nama atau ID')"
                                                       @focus="openPick(r, $el)" @click="openPick(r, $el)" @input="pk.q = $event.target.value; pk.idx = 0" @keydown="pickKey($event)" @blur="closePick()" />
                                                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                                            </div>
                                        </td>
                                        <template x-for="(h, f) in fields" :key="f">
                                            <td class="to-num">
                                                <input x-show="r.employee_id" x-model="r.values[f]" @input="r.server = {}" inputmode="decimal"
                                                       :aria-label="`${h.toUpperCase()}, row ${r.line}`" :aria-invalid="problems(r)[f] ? 'true' : 'false'" :title="problems(r)[f] || ''" />
                                                <span x-show="!r.employee_id" x-text="r.values[f] || '0.00'"></span>
                                            </td>
                                        </template>
                                    </tr>
                                    <tr class="to-msgrow" x-show="status(r) !== 'ready'">
                                        <td :colspan="Object.keys(fields).length + 1">
                                            <div class="to-msg">
                                                <template x-if="status(r) === 'check' && r.suggestion">
                                                    <p><span x-text="t('Closest staff member:', 'Staf paling hampir:')"></span> <strong x-text="label(r.suggestion)"></strong>
                                                        <button type="button" class="to-link" @click="choose(r, r.suggestion)" x-text="t('Use this', 'Guna ini')"></button>
                                                        <button type="button" class="to-link to-link-quiet" @click="skip(r)" x-text="t('Skip row', 'Langkau baris')"></button></p>
                                                </template>
                                                <template x-if="status(r) === 'check' && r.ambiguous">
                                                    <p><span x-text="t(`More than one staff member is called ${r.name}. Search and pick the right one.`, `Lebih daripada seorang staf bernama ${r.name}. Cari dan pilih yang betul.`)"></span>
                                                        <button type="button" class="to-link to-link-quiet" @click="skip(r)" x-text="t('Skip row', 'Langkau baris')"></button></p>
                                                </template>
                                                <p x-show="status(r) === 'unmatched'" x-text="t('No staff member by this name, so this row stays out. Leavers and interns not set up in AmanahKu are expected here. Search above if it is someone on file.', 'Tiada staf dengan nama ini, jadi baris ini tidak diambil. Staf yang sudah berhenti dan pelatih yang tiada dalam AmanahKu memang dijangka di sini. Cari di atas jika ia staf yang ada.')"></p>
                                                <p x-show="status(r) === 'skipped'" x-text="t('Skipped. Search above to include this row after all.', 'Dilangkau. Cari di atas untuk masukkan baris ini.')"></p>
                                                <template x-for="(msg, f) in (status(r) === 'fix' ? problems(r) : {})" :key="f">
                                                    <p class="to-bad"><strong x-text="(f === 'employee_id' ? t('STAFF', 'STAF') : fields[f].toUpperCase())"></strong> <span x-text="msg"></span></p>
                                                </template>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </template>
                            <tbody x-show="!rows.some(r => visible(r))">
                                <tr><td :colspan="Object.keys(fields).length + 1" class="to-empty"><div
                                        x-text="view === 'needs' ? t('Nothing needs you. Look over Ready, then Import.', 'Tiada yang perlu tindakan. Semak Sedia, kemudian Import.') : t('No rows here.', 'Tiada baris di sini.')"></div></td></tr>
                            </tbody>
                        </table>
                    </div>

                    <div id="to-pop" class="to-pop" role="listbox" x-show="pk.row" x-cloak :style="pk.style">
                        <button type="button" role="option" class="to-opt to-opt-skip" :data-active="pk.idx === -1 ? '' : null" @mouseenter="pk.idx = -1" @mousedown.prevent="choose(pk.row, null)">
                            <span class="t" x-text="t('Leave this row out', 'Jangan ambil baris ini')"></span>
                        </button>
                        <template x-for="(s, i) in hits()" :key="s.id">
                            <button type="button" role="option" class="to-opt" :aria-selected="pk.row && String(s.id) === pk.row.employee_id ? 'true' : 'false'"
                                    :data-active="i === pk.idx ? '' : null" @mouseenter="pk.idx = i" @mousedown.prevent="choose(pk.row, s.id)">
                                <span class="t" x-text="s.name"></span>
                                <span class="m" x-text="[s.staff_id, !s.current ? t('Resigned', 'Berhenti') : (unpickedIds().has(s.id) ? t('No row yet', 'Belum ada baris') : '')].filter(Boolean).join(' · ')"></span>
                            </button>
                        </template>
                        <p class="to-none" x-show="!hits().length" x-text="t('No staff member matches that.', 'Tiada staf sepadan.')"></p>
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

@once
<style>
    .to-card { padding:18px 20px;margin-bottom:16px; }
    .to-head { display:flex;align-items:flex-start;justify-content:space-between;gap:12px; }
    .to-title { margin:0;font-size:14px;font-weight:600;color:var(--ink); }
    .to-sub { margin:3px 0 0;font-size:12.5px;color:var(--muted);max-width:72ch;line-height:1.5; }
    .to-upload { display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-top:14px; }
    .to-file-in { font-size:12.5px;color:var(--muted); }
    .to-file-in::file-selector-button { height:32px;padding:0 14px;margin-right:10px;border:1px solid var(--hairline);border-radius:9px;background:var(--card,#fff);color:var(--ink);font:inherit;font-weight:500;cursor:pointer;transition:border-color .14s ease; }
    .to-file-in::file-selector-button:hover { border-color:var(--muted-soft); }
    .to-btn { height:32px;padding:0 16px;font-size:12.5px; }
    .to-err { margin:10px 0 0;font-size:12.5px;color:var(--error); }

    .to-bar { display:flex;align-items:center;justify-content:space-between;gap:10px 16px;flex-wrap:wrap;margin:14px 0 12px; }
    .to-bar .uj-seg { max-width:100%;overflow-x:auto;scrollbar-width:none; }
    .to-bar .uj-seg > * { gap:6px;flex-shrink:0; }
    .to-bar .uj-seg > template { display:none; }
    .to-acts .uj-btn-primary:disabled { opacity:.45;cursor:not-allowed;transform:none; }
    .to-count { min-width:18px;height:18px;padding:0 5px;border-radius:9999px;background:var(--hairline-soft);color:var(--muted);font-size:11px;font-weight:600;display:inline-flex;align-items:center;justify-content:center;font-variant-numeric:tabular-nums; }
    .to-count[data-hot] { background:color-mix(in srgb, var(--amber) 16%, #fff);color:var(--amber-ink); }
    .to-acts { display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-left:auto; }
    .to-why { font-size:12px;color:var(--amber-ink); }

    .to-notice { display:flex;gap:10px;padding:11px 14px;margin:0 0 12px;border:1px solid color-mix(in srgb, var(--amber) 30%, #fff);border-radius:10px;background:color-mix(in srgb, var(--amber) 8%, #fff);color:var(--amber-ink); }
    .to-notice svg { width:16px;height:16px;flex-shrink:0;margin-top:1px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round; }
    .to-notice p { margin:0; }
    .to-notice-t { font-size:12.5px;font-weight:600; }
    .to-notice-s { font-size:12px;margin-top:2px !important;line-height:1.45; }
    .to-notice-names { font-size:12px;margin-top:6px !important;color:var(--ink);line-height:1.6; }

    .to-wrap { container-type:inline-size;overflow:auto;max-height:min(620px,70vh);border:1px solid var(--hairline);border-radius:10px;background:var(--card,#fff);overscroll-behavior:contain; }
    .to-table { border-collapse:separate;border-spacing:0;font-size:12px;min-width:100%; }
    .to-table thead th { position:sticky;top:0;z-index:2;padding:9px 10px;background:var(--canvas);border-bottom:1px solid var(--hairline);color:var(--muted);font-size:11px;font-weight:500;letter-spacing:.04em;white-space:nowrap;text-align:right; }
    .to-table thead th.to-who { text-align:left;left:0;z-index:3; }
    .to-table td { padding:6px 6px;border-top:1px solid var(--hairline-soft);vertical-align:middle;background:var(--card,#fff); }
    .to-table tbody:first-of-type tr:first-child td { border-top:0; }
    .to-table .to-msgrow td { border-top:0;padding:0 10px 10px; }
    .to-who { position:sticky;left:0;z-index:1;width:300px;min-width:300px;max-width:300px;padding:8px 12px !important;text-align:left;box-shadow:1px 0 0 var(--hairline-soft); }
    tbody[data-st="check"] td { background:color-mix(in srgb, var(--amber) 6%, #fff); }
    tbody[data-st="fix"] td { background:color-mix(in srgb, var(--error) 4%, #fff); }
    tbody[data-st="unmatched"] .to-name, tbody[data-st="skipped"] .to-name { color:var(--muted); }

    .to-line { display:flex;align-items:center;gap:8px;min-width:0;margin-bottom:6px; }
    .to-ln { font-size:11px;color:var(--muted-soft, var(--muted));font-variant-numeric:tabular-nums;min-width:20px; }
    .to-name { flex:1;min-width:0;font-size:12.5px;font-weight:500;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis; }
    .to-line .uj-stamp { flex-shrink:0; }
    .to-combo { position:relative; }
    .to-combo input { width:100%;height:32px;padding:0 10px 0 30px;border:1px solid var(--hairline);border-radius:9px;background:var(--card,#fff);color:var(--ink);font-size:12.5px;outline:none;transition:border-color .14s ease, box-shadow .14s ease; }
    .to-combo input::placeholder { color:var(--muted); }
    .to-combo svg { position:absolute;left:10px;top:50%;width:13px;height:13px;transform:translateY(-50%);fill:none;stroke:var(--muted);stroke-width:2;stroke-linecap:round;pointer-events:none; }
    .to-combo input:focus, .to-num input:focus { border-color:var(--red);box-shadow:0 0 0 3px var(--red-tint); }
    tbody[data-st="check"] .to-combo input { border-color:color-mix(in srgb, var(--amber) 55%, var(--hairline)); }
    .to-combo input[aria-invalid="true"] { border-color:var(--error); }

    .to-num { text-align:right;white-space:nowrap; }
    .to-num input { width:96px;height:30px;padding:0 8px;border:1px solid var(--hairline);border-radius:7px;background:var(--card,#fff);color:var(--ink);font-family:var(--font-mono);font-size:11.5px;font-variant-numeric:tabular-nums;text-align:right;outline:none;transition:border-color .14s ease, box-shadow .14s ease; }
    .to-num input[aria-invalid="true"] { border-color:var(--error);background:color-mix(in srgb, var(--error) 7%, #fff);color:var(--error); }
    .to-num span { display:inline-block;padding:0 9px;font-family:var(--font-mono);font-size:11.5px;color:var(--muted);font-variant-numeric:tabular-nums; }

    .to-msg { position:sticky;left:10px;max-width:min(640px, calc(100cqi - 24px));font-size:12px;line-height:1.5;color:var(--muted); }
    .to-msg p { margin:0; }
    .to-msg p + p { margin-top:2px; }
    tbody[data-st="check"] .to-msg { color:var(--amber-ink); }
    .to-msg strong { color:var(--ink);font-weight:600; }
    .to-bad { color:var(--error); }
    .to-bad strong { color:var(--error);margin-right:4px; }
    .to-link { margin-left:10px;padding:0;border:0;background:none;color:var(--red);font:inherit;font-weight:600;cursor:pointer;text-decoration:underline;text-underline-offset:3px;text-decoration-color:color-mix(in srgb, var(--red) 35%, transparent); }
    .to-link:hover { text-decoration-color:currentColor; }
    .to-link-quiet { color:var(--muted);font-weight:500;text-decoration-color:color-mix(in srgb, var(--muted) 35%, transparent); }
    .to-empty { padding:28px 0 !important;color:var(--muted);font-size:12.5px; }
    .to-empty div { position:sticky;left:0;width:100cqi;text-align:center; }

    .to-pop { position:fixed;z-index:60;background:var(--card,#fff);border:1px solid var(--hairline);border-radius:10px;box-shadow:var(--shadow-menu);padding:4px;max-height:280px;overflow-y:auto;overscroll-behavior:contain; }
    .to-opt { width:100%;display:flex;flex-direction:column;align-items:flex-start;gap:1px;text-align:left;border:0;background:none;cursor:pointer;padding:7px 9px;border-radius:7px; }
    .to-opt[data-active] { background:var(--canvas); }
    .to-opt[aria-selected="true"] .t { color:var(--red); }
    .to-opt .t { font-size:12.5px;color:var(--ink);line-height:1.35; }
    .to-opt .m { font-size:11px;color:var(--muted); }
    .to-opt .m:empty { display:none; }
    .to-opt-skip { border-bottom:1px solid var(--hairline-soft);border-radius:7px 7px 0 0;margin-bottom:3px; }
    .to-opt-skip .t { color:var(--muted); }
    .to-none { margin:0;padding:9px 10px;font-size:12.5px;color:var(--muted); }
    @media (max-width:560px) { .to-who { width:230px;min-width:230px;max-width:230px; } .to-acts { margin-left:0; } }
</style>
<script>
    /**
     * Salary listing import review. Rows arrive from the preview endpoint; a row is "ready"
     * (staff picked, every check passes), "fix" (picked, a check fails), "check" (a name that
     * needs HR's decision: a close match was found, or two staff share it), "unmatched"
     * (nobody close, stays out) or "skipped" (HR chose to leave it out). Import is blocked
     * while any row is fix or check. The checks mirror TakeOnImport::problems(); the server
     * runs them again on save.
     *
     * Partial navigation re-runs this script after Alpine has started, when alpine:init
     * will not fire again, so it registers straight away in that case.
     */
    (() => {
        const register = () => Alpine.data('takeOnImport', (cfg) => ({
            fields: cfg.fields, allNames: false, rows: null, staff: [], byId: {}, tp3: [], err: '', busy: false, view: 'all',
            pk: { row: null, el: null, q: '', idx: 0, style: '' },
            views: [
                { key: 'needs', en: 'Needs you', ms: 'Perlu tindakan' },
                { key: 'ready', en: 'Ready', ms: 'Sedia' },
                { key: 'out', en: 'Left out', ms: 'Tidak diambil' },
                { key: 'all', en: 'All', ms: 'Semua' },
            ],
            t(en, ms) { return this.$store.ui.lang === 'en' ? en : ms; },
            money(s) { s = String(s ?? '').replace(/[,\s]/g, ''); if (s === '' || s === '-') return 0; const n = Number(s); return Number.isFinite(n) ? Math.round(n * 100) / 100 : null; },
            fmt(n) { return n.toLocaleString('en-MY', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
            label(id) { const s = this.byId[id]; return s ? s.name + (s.staff_id ? ' · ' + s.staff_id : '') : ''; },
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
            status(r) {
                if (r.employee_id) return Object.keys(this.problems(r)).length ? 'fix' : 'ready';
                if (r.skipped) return 'skipped';
                return r.suggestion || r.ambiguous ? 'check' : 'unmatched';
            },
            stamp(r) {
                return {
                    ready: { tone: 'success', en: 'Ready', ms: 'Sedia' },
                    fix: { tone: 'error', en: 'Fix', ms: 'Betulkan' },
                    check: { tone: 'amber', en: 'Check name', ms: 'Semak nama' },
                    unmatched: { tone: '', en: 'Not on file', ms: 'Tiada rekod' },
                    skipped: { tone: '', en: 'Skipped', ms: 'Dilangkau' },
                }[this.status(r)];
            },
            tally() {
                const n = { needs: 0, ready: 0, out: 0, all: this.rows.length };
                for (const r of this.rows) { const s = this.status(r); if (s === 'fix' || s === 'check') n.needs++; else if (s === 'ready') n.ready++; else n.out++; }
                return n;
            },
            visible(r) {
                const s = this.status(r);
                return this.view === 'all' || (this.view === 'needs' && (s === 'fix' || s === 'check')) || (this.view === 'ready' && s === 'ready') || (this.view === 'out' && (s === 'unmatched' || s === 'skipped'));
            },
            staffCount() { return new Set(this.rows.filter(r => r.employee_id).map(r => r.employee_id)).size; },
            unpickedIds() { const used = new Set(this.rows.map(r => Number(r.employee_id))); return new Set(this.staff.filter(s => s.current && !used.has(s.id)).map(s => s.id)); },
            notPicked() { const ids = this.unpickedIds(); return this.staff.filter(s => ids.has(s.id)); },
            choose(r, id) { r.employee_id = id ? String(id) : ''; r.skipped = !id; r.server = {}; this.pk.row = null; },
            skip(r) { this.choose(r, null); },

            openPick(r, el) {
                if (this.pk.row !== r) { this.pk = { ...this.pk, row: r, el, q: '', idx: 0 }; }
                this.placePick();
            },
            /** Keeps the list under (or, near the bottom of the window, above) its input. */
            placePick() {
                if (!this.pk.row || !this.pk.el?.isConnected) return;
                const b = this.pk.el.getBoundingClientRect(), w = Math.max(b.width, 300), below = innerHeight - b.bottom;
                const left = Math.min(b.left, innerWidth - w - 8);
                this.pk.style = `left:${left}px;width:${w}px;` + (below < 300 && b.top > below ? `bottom:${innerHeight - b.top + 4}px;` : `top:${b.bottom + 4}px;`);
            },
            closePick() { this.pk.row = null; this.pk.el = null; },
            hits() {
                const q = this.pk.q.trim().toLowerCase(), free = this.unpickedIds();
                return this.staff
                    .filter(s => !q || (s.name + ' ' + (s.staff_id || '')).toLowerCase().includes(q))
                    .sort((a, b) => (free.has(b.id) - free.has(a.id)) || (b.current - a.current))
                    .slice(0, 60);
            },
            pickKey(e) {
                const hits = this.hits();
                if (e.key === 'ArrowDown') { e.preventDefault(); this.pk.idx = Math.min(this.pk.idx + 1, hits.length - 1); }
                else if (e.key === 'ArrowUp') { e.preventDefault(); this.pk.idx = Math.max(this.pk.idx - 1, -1); }
                else if (e.key === 'Enter') { e.preventDefault(); if (this.pk.row) this.choose(this.pk.row, this.pk.idx === -1 ? null : hits[this.pk.idx]?.id ?? this.pk.row.employee_id); }
                else if (e.key === 'Escape') { this.closePick(); e.target.blur(); }
            },

            async send(url, body) {
                this.busy = true; this.err = '';
                try {
                    const res = await fetch(url, { method: 'POST', body, headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, ...(body instanceof FormData ? {} : { 'Content-Type': 'application/json' }) } });
                    const j = await res.json().catch(() => ({}));
                    if (!res.ok) this.err = j.message || this.t('Something went wrong. Try again.', 'Ada masalah. Cuba lagi.');
                    return { ok: res.ok, j };
                } catch { this.err = this.t('Could not reach the server. Check the connection and try again.', 'Tidak dapat menghubungi pelayan. Semak sambungan dan cuba lagi.'); return { ok: false, j: {} }; }
                finally { this.busy = false; }
            },
            async check() {
                const f = this.$refs.file.files[0];
                if (!f) { this.err = this.t('Choose the CSV file first.', 'Pilih fail CSV dahulu.'); return; }
                const body = new FormData(); body.append('file', f); body.append('year', this.year);
                const { ok, j } = await this.send(cfg.previewUrl, body);
                if (!ok) return;
                this.staff = j.staff; this.tp3 = j.tp3; this.byId = Object.fromEntries(j.staff.map(s => [s.id, s]));
                this.rows = j.rows.map(r => ({ ...r, employee_id: r.employee_id ? String(r.employee_id) : '', skipped: false, server: {} }));
                this.view = this.tally().needs ? 'needs' : 'all';
            },
            async save() {
                const rows = this.rows.map(r => ({ line: r.line, employee_id: r.employee_id ? Number(r.employee_id) : null, values: r.values }));
                const { ok, j } = await this.send(cfg.importUrl, JSON.stringify({ year: this.year, rows }));
                if (ok) { location.reload(); return; }
                for (const r of this.rows) r.server = (j.problems || {})[r.line] || {};
                if (j.problems) this.view = 'needs';
            },
            reset() { this.rows = null; this.err = ''; this.pk.row = null; this.$nextTick(() => { if (this.$refs.file) this.$refs.file.value = ''; }); },
        }));
        window.Alpine ? register() : document.addEventListener('alpine:init', register);
    })();
</script>
@endonce
