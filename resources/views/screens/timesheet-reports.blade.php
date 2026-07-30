@extends('layouts.app')

@php
    $canSeeCost = $canSeeCost ?? false;
    $md = fn ($v) => rtrim(rtrim(number_format((float) $v, 2), '0'), '.');
    $rm = fn ($v) => 'RM '.number_format((float) $v, 2);
    $totals = $reportTotals ?? ['days' => 0, 'cost' => 0, 'uncostedDays' => 0, 'weeksTotal' => 0, 'weeksNotIn' => 0];

    $fromDate = \Illuminate\Support\Carbon::parse($from);
    $toDate = \Illuminate\Support\Carbon::parse($to);
    if ($fromDate->month === $toDate->month && $fromDate->year === $toDate->year) {
        $dateRange = $fromDate->format('j').' – '.$toDate->format('j M Y');
    } elseif ($fromDate->year === $toDate->year) {
        $dateRange = $fromDate->format('j M').' – '.$toDate->format('j M Y');
    } else {
        $dateRange = $fromDate->format('j M Y').' – '.$toDate->format('j M Y');
    }

    $selCatName = $selCategory ? $filterCategories->firstWhere('id', (int) $selCategory)?->name : null;
    $selProjName = $selProject ? $filterProjects->firstWhere('id', (int) $selProject)?->name : null;
    $activeFilterParts = array_filter([$selCatName, $selProjName]);
    $activeFilterName = count($activeFilterParts) > 0 ? implode(' + ', $activeFilterParts) : 'This period';
@endphp

@section('screen')
<div class="uj-tr-wrap" x-data="{
    lens: 'category',
    category: @js($lensCategory),
    project: @js($lensProject),
    staff: @js($lensStaff),
    weeks: @js($staffWeeks),
    sel: { kind: 'slice', key: null, from: null },
    rows() {
        return this.lens === 'category' ? this.category
             : this.lens === 'project' ? this.project : this.staff;
    },
    setLens(l) { this.lens = l; this.sel = { kind: 'slice', key: null, from: null } },
    slice(key) { this.sel = { kind: 'slice', key: key, from: null } },
    openPerson(id, from) { this.sel = { kind: 'person', key: id, from: from } },
    currentSlice() {
        const rs = this.rows()
        return rs.find(r => String(r.id) === String(this.sel.key)) || rs[0] || null
    },
    currentPerson() {
        return this.staff.find(r => String(r.id) === String(this.sel.key)) || null
    },
    personToDisplay() {
        return this.currentPerson() || this.staff[0] || null
    },
    formatSliceSubline(slice) {
        if (!slice) return ''
        const memCount = slice.members ? slice.members.length : 0
        const isEn = this.$store.ui.lang === 'en'
        const pWord = isEn ? (memCount === 1 ? 'person' : 'people') : 'orang'
        const mdVal = (Math.round((slice.days || 0) * 100) / 100).toFixed(2).replace(/\.?0+$/, '') + ' md'
        const rmVal = 'RM ' + Number(slice.cost || 0).toLocaleString('en-MY', {minimumFractionDigits: 2, maximumFractionDigits: 2})
        return memCount + ' ' + pWord + ' · ' + mdVal + ' · ' + rmVal
    },
    formatMissingWeeks(p) {
        if (!p || !p.missingWeeks || p.missingWeeks.length === 0) return ''
        const mw = p.missingWeeks
        const isEn = this.$store.ui.lang === 'en'
        const names = mw.length === 1 ? mw[0] : mw.slice(0, -1).join(', ') + (isEn ? ' and ' : ' dan ') + mw[mw.length - 1]
        const verb = mw.length === 1
            ? (isEn ? 'is not here: no sheet was ever submitted.' : 'tiada di sini: tiada lembaran pernah dihantar.')
            : (isEn ? 'are not here: no sheet was ever submitted.' : 'tiada di sini: tiada lembaran pernah dihantar.')
        return names + ' ' + verb
    }
}">
    {{-- Reciprocal of the "see all staff" icon on the personal timesheet screen: this
         report is reached by that one-way shortcut, so offer a one-tap way back to My
         timesheets rather than leaving the browser Back button as the only exit. --}}
    <div style="display:flex;justify-content:flex-end;margin-bottom:14px;">
        <a href="{{ route('app.screen', 'timesheets') }}" class="uj-btn-ghost" style="font-size:12px;padding:7px 12px;text-decoration:none;">
            <span x-text="$store.ui.lang==='en' ? '← My timesheets' : '← Timesheet saya'">← My timesheets</span>
        </a>
    </div>

    @include('partials.guide', [
        'key' => 'timesheet-reports',
        'en'  => [
            'title' => 'Timesheet reports',
            'body'  => 'See where staff time and money went, drawn from submitted timesheets. "By category" answers spend questions like how much went to Study or Leave; "by project" shows cost and effort per project; "by staff" breaks one person down. Time is in person-days (a day at 100% = one person-day); cost is RM, derived from each person\'s salary band.',
            'who'   => 'Managers, Management & HR',
            'steps' => [
                'Set the period (defaults to this month). Optionally filter to one category or project, then press Apply.',
                'Read the summary strip for total person-days and total RM in the period.',
                'Switch tabs: By category, By project, or By staff. Bars show each slice as a share of its group.',
            ],
        ],
        'ms'  => [
            'title' => 'Laporan lembaran masa',
            'body'  => 'Lihat ke mana masa dan wang staf pergi, daripada timesheet yang dihantar. "Mengikut kategori" menjawab soalan perbelanjaan seperti berapa untuk Belajar atau Cuti; "mengikut projek" menunjukkan kos dan usaha setiap projek; "mengikut staf" memecahkan seorang. Masa dalam hari-orang (sehari pada 100% = satu hari-orang); kos dalam RM, diambil daripada band gaji setiap orang.',
            'who'   => 'Pengurus, Pengurusan & HR',
            'steps' => [
                'Tetapkan tempoh (lalai bulan ini). Pilihan: tapis kepada satu kategori atau projek, kemudian tekan Guna.',
                'Baca jalur ringkasan untuk jumlah hari-orang dan jumlah RM dalam tempoh.',
                'Tukar tab: Mengikut kategori, projek, atau staf. Bar menunjukkan setiap bahagian sebagai peratus kumpulannya.',
            ],
        ],
    ])

    {{-- This-week compliance roster — who still owes a sheet. Access is the screen's own
         403 gate (management/HR/superiors, see AppController::canSeeAll), not a role check
         here. Always the current week, independent of the report period below. --}}
    @php
        $tsRoster = collect($tsRoster ?? []);
        $tsDone = $tsRoster->where('status', 'done')->count();
        $tsTotal = $tsRoster->count();
        $tsPending = $tsRoster->where('status', '!=', 'done')->values();
        $tsPill = ['done' => 'var(--success)', 'pending' => 'var(--muted)', 'late' => 'var(--red)'];
    @endphp
    @if ($tsTotal)
    <div class="uj-card" style="margin-bottom:16px;padding:14px 18px;" x-data="{ open: true }">
        <div style="display:flex;align-items:center;gap:10px;cursor:pointer;" @click="open = !open">
            <strong style="flex:1;font-size:13.5px;" x-text="$store.ui.lang==='en' ? 'This week — team status' : 'Minggu ini — status pasukan'">This week — team status</strong>
            <span style="font-size:12.5px;color:var(--muted);">{{ $tsDone }} / {{ $tsTotal }} <span x-text="$store.ui.lang==='en' ? 'done' : 'selesai'">done</span></span>
            <span x-text="open ? '▾' : '▸'" style="color:var(--muted);"></span>
        </div>
        <div x-show="open" style="margin-top:12px;display:flex;flex-wrap:wrap;gap:8px;">
            @forelse ($tsPending as $row)
                <span style="display:inline-flex;align-items:center;gap:7px;padding:4px 11px;border-radius:999px;background:var(--surface-2,#f3f4f6);font-size:12px;">
                    <span style="width:8px;height:8px;border-radius:50%;background:{{ $tsPill[$row['status']] }};flex:none;"></span>
                    <span>{{ $row['employee']->name }}</span>
                    <span style="color:var(--muted);font-size:10.5px;text-transform:uppercase;letter-spacing:.04em;">
                        @if ($row['status'] === 'late')
                            <span x-text="$store.ui.lang==='en' ? 'late' : 'lewat'">late</span>
                        @else
                            <span x-text="$store.ui.lang==='en' ? 'pending' : 'belum'">pending</span>
                        @endif
                    </span>
                </span>
            @empty
                <span style="font-size:12px;color:var(--success);" x-text="$store.ui.lang==='en' ? 'Everyone is in for this week.' : 'Semua sudah hantar minggu ini.'">Everyone is in for this week.</span>
            @endforelse
        </div>
    </div>
    @endif

    {{-- Shelf --}}
    <div class="uj-tr-shelf">
        <div class="uj-tr-lede">
            <span x-text="$store.ui.lang==='en' ? 'Submitted timesheets' : 'Timesheet dihantar'">Submitted timesheets</span> · <b>{{ $dateRange }}</b>
        </div>
        <div class="uj-tr-figrow">
            <span class="uj-tr-fig">{{ $md($totals['days']) }}</span>
            <span class="uj-tr-figsub">
                <b><span x-text="$store.ui.lang==='en' ? 'person-days recorded' : 'hari-orang direkod'">person-days recorded</span></b>@if($totals['cost'] > 0), <span x-text="$store.ui.lang==='en' ? 'worth' : 'bernilai'">worth</span> {{ $rm($totals['cost']) }} <span x-text="$store.ui.lang==='en' ? 'at charge-out rates.' : 'pada kadar caj.'">at charge-out rates.</span>@else.@endif
                @if ($totals['uncostedDays'] > 0)
                    {{ $md($totals['uncostedDays']) }} md <span x-text="$store.ui.lang==='en' ? 'have no band and no cost.' : 'tiada band dan tiada kos.'">have no band dan tiada kos.</span>
                @endif
            </span>
        </div>
        <div class="uj-tr-chips">
            <div class="uj-tr-chip">
                <b>{{ $md($totals['days']) }}</b>
                <span x-text="$store.ui.lang==='en' ? 'PERSON-DAYS' : 'HARI-ORANG'">PERSON-DAYS</span>
            </div>
            @if (count($lensStaff) > 0)
                <div class="uj-tr-chip">
                    <b>{{ $md($totals['days'] / count($lensStaff)) }}</b>
                    <span x-text="$store.ui.lang==='en' ? 'PER HEAD' : 'SETIAP ORANG'">PER HEAD</span>
                </div>
            @endif
            @if ($totals['uncostedDays'] > 0)
                <div class="uj-tr-chip" data-t="warn">
                    <b>{{ $md($totals['uncostedDays']) }}</b>
                    <span x-text="$store.ui.lang==='en' ? 'UNCOSTED MD' : 'MD TANPA KOS'">UNCOSTED MD</span>
                </div>
            @endif
            @if ($totals['weeksNotIn'] > 0)
                <div class="uj-tr-chip" data-t="warn">
                    <b>{{ $totals['weeksNotIn'] }}</b>
                    <span x-text="$store.ui.lang==='en' ? 'WEEKS NOT IN' : 'MINGGU BELUM MASUK'">WEEKS NOT IN</span>
                </div>
            @endif
        </div>
    </div>

    {{-- Filter row --}}
    <form method="get" action="{{ route('app.screen', 'timesheet-reports') }}" class="uj-tr-filter">
        <div>
            <label style="display:block;font-size:12px;font-weight:500;color:var(--ink);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'From' : 'Dari'">From</span></label>
            <input type="date" name="from" value="{{ $from }}" class="uj-tr-sel" />
        </div>
        <div>
            <label style="display:block;font-size:12px;font-weight:500;color:var(--ink);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'To' : 'Hingga'">To</span></label>
            <input type="date" name="to" value="{{ $to }}" class="uj-tr-sel" />
        </div>
        <div>
            <label style="display:block;font-size:12px;font-weight:500;color:var(--ink);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Category' : 'Kategori'">Category</span></label>
            <select name="category" class="uj-tr-sel">
                <option value="" x-text="$store.ui.lang==='en' ? 'All categories' : 'Semua kategori'">All categories</option>
                @foreach ($filterCategories as $c)
                    <option value="{{ $c->id }}" @selected((string) $selCategory === (string) $c->id)>{{ $c->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label style="display:block;font-size:12px;font-weight:500;color:var(--ink);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Project' : 'Projek'">Project</span></label>
            <select name="project" class="uj-tr-sel">
                <option value="" x-text="$store.ui.lang==='en' ? 'All projects' : 'Semua projek'">All projects</option>
                @foreach ($filterProjects as $p)
                    <option value="{{ $p->id }}" @selected((string) $selProject === (string) $p->id)>{{ $p->name }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="uj-tr-btn" data-primary style="align-self:flex-end;"><span x-text="$store.ui.lang==='en' ? 'Apply' : 'Guna'">Apply</span></button>
        <span class="uj-tr-range">{{ $dateRange }}</span>
    </form>

    {{-- Lens control --}}
    <div style="margin-top:20px;">
        <div class="uj-tr-pills" role="group" aria-label="Break down by">
            <button type="button" class="uj-tr-pill" :data-on="lens==='category'" @click="setLens('category')">
                <span x-text="$store.ui.lang==='en' ? 'By category' : 'Mengikut kategori'">By category</span>
            </button>
            <button type="button" class="uj-tr-pill" :data-on="lens==='project'" @click="setLens('project')">
                <span x-text="$store.ui.lang==='en' ? 'By project' : 'Mengikut projek'">By project</span>
            </button>
            <button type="button" class="uj-tr-pill" :data-on="lens==='staff'" @click="setLens('staff')">
                <span x-text="$store.ui.lang==='en' ? 'By person' : 'Mengikut individu'">By person</span>
            </button>
        </div>
    </div>

    {{-- Bar list / Empty state --}}
    @if ($reportEmpty)
        <div class="uj-tr-card">
            <div class="uj-tr-empty">
                <b x-text="$store.ui.lang==='en' ? 'No submitted time matches this filter' : 'Tiada masa dihantar yang sepadan dengan tapisan ini'">No submitted time matches this filter</b>
                <div>{{ $activeFilterName }}</div>
                <span x-text="$store.ui.lang==='en' ? 'Clear a filter, or widen the period.' : 'Kosongkan satu tapisan, atau luaskan tempoh.'">Clear a filter, or widen the period.</span>
            </div>
        </div>
    @else
        <div class="uj-tr-lens">
            <div>
                <div class="uj-tr-card uj-tr-anim">
                    <template x-if="rows().length === 0">
                        <div class="uj-tr-empty"
                             x-text="lens === 'category' ? ($store.ui.lang === 'en' ? 'No categorised time in this period.' : 'Tiada masa berkategori dalam tempoh ini.')
                                   : (lens === 'project' ? ($store.ui.lang === 'en' ? 'No project-linked time in this period.' : 'Tiada masa berkaitan projek dalam tempoh ini.')
                                   : ($store.ui.lang === 'en' ? 'Nobody has submitted time in this period.' : 'Tiada sesiapa menghantar masa dalam tempoh ini.'))">
                            No project-linked time in this period.
                        </div>
                    </template>
                    <template x-for="(row, index) in rows()" :key="row.id || row.label || index">
                        <button type="button" class="uj-tr-lensrow"
                                :data-on="lens === 'staff'
                                    ? String(row.id) === String(personToDisplay() ? personToDisplay().id : '')
                                    : String(row.id) === String(sel.kind === 'person' && sel.from ? sel.from : (currentSlice() ? currentSlice().id : ''))"
                                @click="lens === 'staff' ? openPerson(row.id, null) : slice(row.id)">
                            <div class="uj-tr-barrow">
                                <span class="lbl">
                                    <span x-text="row.name || row.label"></span>
                                    <span class="uj-tr-sub" style="display:inline;margin-left:6px"
                                          x-text="lens === 'staff' ? (row.title || '')
                                                : ((row.members ? row.members.length : 0) + ' ' +
                                                   ($store.ui.lang === 'en'
                                                       ? ((row.members ? row.members.length : 0) === 1 ? 'person' : 'people')
                                                       : 'orang'))">
                                    </span>
                                </span>
                                <span class="val">
                                    <template x-if="(lens === 'staff' && !row.costed) || !(row.cost > 0)">
                                        <span style="color:var(--amber-ink)" x-text="$store.ui.lang==='en' ? 'uncosted' : 'tanpa kos'">uncosted</span>
                                    </template>
                                    <template x-if="row.cost > 0 && !(lens === 'staff' && !row.costed)">
                                        <b x-text="'RM ' + Number(row.cost || 0).toLocaleString('en-MY', {minimumFractionDigits: 2, maximumFractionDigits: 2})"></b>
                                    </template>
                                    <span x-text="' · ' + (Math.round((row.days || 0) * 100) / 100).toFixed(2).replace(/\.?0+$/, '') + ' md'"></span>
                                    <template x-if="lens === 'staff' && row.weeksIn < row.weeksTotal">
                                        <span class="dim" x-text="' · ' + row.weeksIn + '/' + row.weeksTotal + ' ' + ($store.ui.lang === 'en' ? 'wk' : 'mgu')"></span>
                                    </template>
                                    <span x-text="' · ' + (row.pct || 0) + '%'"></span>
                                </span>
                            </div>
                            <div class="uj-tr-bar">
                                <i :style="'width:' + Math.max(row.pct || 0, 1.5) + '%;background:' + (lens === 'category' ? 'var(--info)' : (lens === 'project' ? 'var(--success)' : (row.color || 'var(--info)'))) + ';animation-delay:' + (index * 45) + 'ms'"></i>
                            </div>
                        </button>
                    </template>
                </div>
                @if ($totals['weeksNotIn'] > 0)
                    <div class="uj-tr-note" x-text="$store.ui.lang==='en' ? 'A row short of its weeks is short a submitted sheet, not short of work.' : 'Baris yang kurang minggunya kekurangan lembaran dihantar, bukan kekurangan kerja.'">A row short of its weeks is short a submitted sheet, not short of work.</div>
                @endif
            </div>

            {{-- The rail --}}
            <aside class="uj-tr-panel" aria-label="Timesheet detail">
                <template x-if="lens !== 'staff' && sel.kind !== 'person'">
                    <div>
                        <template x-if="currentSlice()">
                            <div>
                                <div class="hd">
                                    <div style="min-width:0;flex:1">
                                        <h3 x-text="currentSlice().label || currentSlice().name"></h3>
                                        <div class="uj-tr-sub" x-text="formatSliceSubline(currentSlice())"></div>
                                    </div>
                                </div>
                                <template x-for="member in (currentSlice().members || [])" :key="member.id">
                                    <button type="button" class="uj-tr-lensrow" style="padding:11px 0;border-top:1px solid var(--hairline-soft)" @click="openPerson(member.id, currentSlice().id)">
                                        <div class="uj-tr-barrow" style="margin-bottom:0">
                                            <span class="lbl" x-text="member.name"></span>
                                            <span class="val" x-text="(Math.round((member.days || 0) * 100) / 100).toFixed(2).replace(/\.?0+$/, '') + ' md · ' + (member.pct || 0) + '%'"></span>
                                        </div>
                                    </button>
                                </template>
                                <div class="uj-tr-note" style="margin-top:12px" x-text="$store.ui.lang==='en' ? 'Pick a person to read the lines behind their share.' : 'Pilih seorang untuk membaca baris di sebalik bahagian mereka.'">Pick a person to read the lines behind their share.</div>
                            </div>
                        </template>
                    </div>
                </template>

                <template x-if="lens === 'staff' || sel.kind === 'person'">
                    <div>
                        <template x-if="personToDisplay()">
                            <div x-data="{ get p() { return personToDisplay() } }">
                                <div class="hd">
                                    <span class="uj-tr-av" :style="'background:' + (p.color || 'var(--info)')" x-text="p.initials"></span>
                                    <div style="min-width:0;flex:1">
                                        <h3 x-text="p.name"></h3>
                                        <div class="uj-tr-sub" x-text="(p.title || '') + (p.costed && p.rate ? ((p.title ? ' · ' : '') + 'RM ' + Number(p.rate).toLocaleString('en-MY', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + '/day') : '')"></div>
                                    </div>
                                    <template x-if="sel.from">
                                        <button type="button" class="uj-tr-btn" style="height:28px;padding:0 10px" @click="slice(sel.from)" :aria-label="$store.ui.lang==='en' ? 'Back' : 'Kembali'">←</button>
                                    </template>
                                </div>
                                <div style="font-size:var(--t-sm);color:var(--muted);margin-bottom:12px;" x-text="p.weeksIn + ' ' + ($store.ui.lang === 'en' ? 'of' : 'daripada') + ' ' + p.weeksTotal + ' ' + ($store.ui.lang === 'en' ? 'weeks submitted' : 'minggu dihantar')"></div>
                                <template x-for="wk in (weeks[p.id] || [])" :key="wk.label">
                                    <div class="uj-tr-wk">
                                        <div class="hdr">
                                            <span x-text="wk.label + ' · ' + wk.dates"></span>
                                            <span x-text="(Math.round((wk.days || 0) * 100) / 100).toFixed(2).replace(/\.?0+$/, '') + ' md' + (p.costed && wk.cost > 0 ? ' · RM ' + Number(wk.cost || 0).toLocaleString('en-MY', {minimumFractionDigits: 2, maximumFractionDigits: 2}) : '')"></span>
                                        </div>
                                        <template x-for="(line, lidx) in wk.lines" :key="lidx">
                                            <div class="uj-tr-ent">
                                                <div>
                                                    <span x-text="line.label"></span>
                                                    <template x-if="line.note">
                                                        <span class="n" x-html="line.note"></span>
                                                    </template>
                                                </div>
                                                <span class="d" x-text="(Math.round((line.days || 0) * 100) / 100).toFixed(2).replace(/\.?0+$/, '')"></span>
                                            </div>
                                        </template>
                                    </div>
                                </template>
                                <template x-if="p.missingWeeks && p.missingWeeks.length > 0">
                                    <div class="uj-tr-note" style="margin-top:12px" x-text="formatMissingWeeks(p)"></div>
                                </template>
                                <template x-if="!p.costed">
                                    <div class="uj-tr-note" style="margin-top:12px" x-text="$store.ui.lang==='en' ? 'You have no position band assigned, so your timesheet cost can\'t be computed. Set it in Administration → Position & Manday Rates.' : 'Anda belum ada band pangkat, jadi kos timesheet anda tidak dapat dikira. Tetapkan di Pentadbiran → Pangkat & Kadar Manday.'"></div>
                                </template>
                            </div>
                        </template>
                    </div>
                </template>
            </aside>
        </div>
    @endif
</div>
@endsection
