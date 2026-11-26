{{-- Salary Listing: one run laid out like HR's monthly salary sheet, read only. Figures come
     from the payroll register's own line, so this grid and the register CSV always agree. --}}
@php
    // [key, EN, MS]. Grouped to match the sheet: earnings, deductions, take home, employer share.
    $groups = [
        ['Earnings', 'Pendapatan', [
            ['basic', 'Basic', 'Asas'], ['bonus', 'Bonus', 'Bonus'], ['allowances', 'Allowance', 'Elaun'],
            ['overtime', 'Overtime', 'Lebih masa'], ['other_earnings', 'Others', 'Lain-lain'],
            ['unpaid', 'Unpaid leave', 'Cuti tanpa gaji'], ['gross', 'Gross', 'Kasar'],
        ]],
        ['Deductions', 'Potongan', [
            ['epf', 'EPF', 'KWSP'], ['pcb', 'Tax (PCB)', 'Cukai (PCB)'], ['socso', 'SOCSO', 'PERKESO'],
            ['eis', 'EIS', 'SIP'], ['skbbk', 'SKBBK', 'SKBBK'], ['zakat', 'Zakat', 'Zakat'],
            ['advance', 'Advance', 'Pendahuluan'], ['other_deductions', 'Deduction', 'Potongan lain'], ['cp38', 'CP38', 'CP38'],
        ]],
        ['Take home', 'Dibawa pulang', [['claims', 'Claims', 'Tuntutan'], ['net', 'Net salary', 'Gaji bersih']]],
        ['Employer', 'Majikan', [['epf_er', 'EPF', 'KWSP'], ['socso_er', 'SOCSO', 'PERKESO'], ['eis_er', 'EIS', 'SIP']]],
    ];
    // The first column of each group draws the heavier rule that separates groups, like a ruled ledger.
    $cols = collect($groups)->flatMap(fn ($g) => collect($g[2])->map(fn ($c, $i) => [...$c, $i === 0]));
    // Blank for zero, like the sheet; a negative (unpaid leave) keeps its minus sign.
    $cell = fn (float $v) => abs($v) < 0.005 ? '' : ($v < 0 ? '−' : '').number_format(abs($v), 2);
@endphp
<style>
    .sl-bar { display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:14px; }
    .sl-search { position:relative;margin-left:auto; }
    .sl-search svg { position:absolute;left:11px;top:50%;transform:translateY(-50%);color:var(--muted-soft);pointer-events:none; }
    .sl-search input { height:34px;width:220px;padding:0 12px 0 32px;border:1px solid var(--hairline);border-radius:9px;background:var(--card);font-size:var(--t-sm);color:var(--ink);transition:border-color 140ms var(--ease); }
    .sl-search input:hover { border-color:var(--muted-soft); }
    .sl-search input::placeholder { color:var(--muted-soft); }

    .sl-note { display:flex;align-items:center;gap:8px;font-size:var(--t-sm);color:var(--body);margin-bottom:10px; }

    .sl-wrap { --sl-cross:color-mix(in srgb, var(--info) 10%, var(--card));--sl-head:color-mix(in srgb, var(--info) 20%, var(--card));--sl-hit:color-mix(in srgb, var(--info) 28%, var(--card));--sl-ring:color-mix(in srgb, var(--info) 60%, var(--card));border:1px solid var(--hairline);border-radius:12px;overflow:auto;max-height:max(380px, calc(100vh - 330px));background:var(--card);overscroll-behavior:contain; }
    .sl-table { border-collapse:separate;border-spacing:0;width:max-content;min-width:100%;font-size:var(--t-sm); }
    .sl-table th, .sl-table td { padding:0 12px;height:36px;white-space:nowrap;border-bottom:1px solid var(--hairline-soft);background:var(--card); }
    .sl-table .rule { border-left:1px solid var(--hairline); }
    .sl-table .num { text-align:right;font-family:var(--font-mono);font-variant-numeric:tabular-nums;color:var(--ink); }
    .sl-table .num.ded { color:var(--body); }
    .sl-table .num.key { font-weight:600; }
    .sl-table td.num.net { background:color-mix(in srgb, var(--shelf) 55%, var(--card)); }

    .sl-table thead th { position:sticky;z-index:2;background:var(--canvas);color:var(--muted); }
    .sl-table thead tr:first-child th { top:0;height:30px;font-size:var(--t-micro);font-weight:700;letter-spacing:.13em;text-transform:uppercase;text-align:left;border-bottom:0; }
    .sl-table thead tr:last-child th { top:30px;height:32px;font-size:var(--t-micro);font-weight:600;font-family:var(--font-sans);border-bottom:1px solid var(--hairline); }
    .sl-table thead th.num { color:var(--muted); }

    .sl-table .name { position:sticky;left:0;z-index:1;min-width:300px;max-width:340px;text-align:left;border-right:1px solid var(--hairline);transition:box-shadow 160ms var(--ease); }
    .sl-table thead .name { z-index:3; }
    .sl-name { display:flex;align-items:baseline;gap:10px;min-width:0; }
    .sl-no { font-family:var(--font-mono);font-variant-numeric:tabular-nums;font-size:var(--t-micro);color:var(--muted-soft);min-width:18px;text-align:right; }
    .sl-who { min-width:0;overflow:hidden;text-overflow:ellipsis;color:var(--ink);font-weight:500; }
    .sl-id { font-family:var(--font-mono);font-size:var(--t-micro);color:var(--muted-soft);flex:none; }
    .sl-table .remark { color:var(--body);max-width:260px;overflow:hidden;text-overflow:ellipsis;font-family:var(--font-sans); }

    /* Lift the frozen edges only once something is actually passing under them. */
    .sl-wrap[data-sx] .name { box-shadow:6px 0 10px -6px rgba(31,30,26,.16); }
    .sl-wrap[data-sy] thead tr:last-child th { box-shadow:0 6px 10px -8px rgba(31,30,26,.16); }

    /* Crosshair: the row and column under the pointer tint, their name and header cells
       tint harder, and the cell where they meet gets a ring, so every figure reads as
       "this person, this column" at a glance. */
    @media (hover: hover) and (pointer: fine) {
        .sl-wrap .sl-table tbody tr:hover > * { background:var(--sl-cross); }
        .sl-wrap .sl-table td.is-col, .sl-wrap .sl-table th.is-col { background:var(--sl-cross); }
        .sl-wrap .sl-table tbody tr:hover > .name, .sl-wrap .sl-table thead th.is-col, .sl-wrap .sl-table tfoot td.is-col { background:var(--sl-head);color:var(--ink); }
        .sl-wrap .sl-table tbody tr:hover > .is-col { background:var(--sl-hit);color:var(--ink);box-shadow:inset 0 0 0 1.5px var(--sl-ring); }
    }

    /* Accounting totals: a single rule above, a double rule beneath. */
    .sl-table tfoot td { position:sticky;bottom:0;z-index:2;height:40px;background:var(--canvas);font-weight:600;border-top:1px solid var(--ink);border-bottom:3px double var(--ink); }
    .sl-table tfoot .name { z-index:3; }
    .sl-table tfoot td.net { background:color-mix(in srgb, var(--shelf) 80%, var(--canvas)); }
    .sl-total { font-size:var(--t-micro);font-weight:700;letter-spacing:.13em;text-transform:uppercase;color:var(--ink); }
    .sl-total small { font-weight:500;letter-spacing:0;text-transform:none;color:var(--muted);font-size:var(--t-micro);margin-left:6px; }
    .sl-none td { height:64px;text-align:center;color:var(--muted); }

    @media (max-width: 640px) {
        .sl-search { margin-left:0;flex-basis:100%; }
        .sl-search input { width:100%; }
        .sl-table .name { min-width:160px;max-width:180px; }
        .sl-id { display:none; }
    }
    @media (prefers-reduced-motion: reduce) {
        .sl-table th, .sl-table td, .sl-table .name, .sl-search input { transition:none; }
    }
</style>
@if (! $activeRun)
    @include('partials.payroll.run-picker', ['screen' => 'payroll-payment', 'tab' => 'listing'])
    <div class="uj-card" style="padding:22px;color:var(--muted);font-size:13px;" x-text="$store.ui.lang==='en' ? 'No payroll run yet. Create one under Process.' : 'Belum ada run gaji. Buat satu di bawah Proses.'">No payroll run yet. Create one under Process.</div>
@else
    @php
        $payslips = $activeRun->payslips->sortBy(fn ($p) => $p->employee?->name)->values();
        $rows = $payslips->map(fn ($p) => array_combine(\App\Http\Controllers\PayrollExportController::REGISTER_KEYS, \App\Http\Controllers\PayrollExportController::registerLine($p)));
        $sum = fn (string $k) => (float) $rows->sum($k);
        $names = $payslips->map(fn ($p) => mb_strtolower(($p->employee?->name ?? '').' '.($p->employee?->staff_id ?? '')))->all();
    @endphp
    <div x-data="{
            q: '',
            names: @js($names),
            sx: false,
            sy: false,
            col: -1,
            /** Mark every cell in column i (header, body, totals) so CSS can tint the whole column. */
            paint(i) {
                if (i === this.col) { return; }
                this.$refs.table.querySelectorAll('.is-col').forEach(c => c.classList.remove('is-col'));
                this.col = i;
                if (i < 1) { return; }
                this.$refs.table.querySelectorAll('thead tr:last-child, tbody tr, tfoot tr').forEach(r => r.cells[i]?.classList.add('is-col'));
            },
            shows(i) { const q = this.q.trim().toLowerCase(); return q === '' || this.names[i].includes(q); },
            get hits() { return this.names.filter((n, i) => this.shows(i)).length; },
         }">
        <div class="sl-bar">
            @include('partials.payroll.run-picker', ['screen' => 'payroll-payment', 'tab' => 'listing'])
            <span class="uj-stamp" x-text="$store.ui.lang==='en' ? @js($payslips->count().' staff') : @js($payslips->count().' staf')">{{ $payslips->count() }} staff</span>
            @if ($payslips->isNotEmpty())
                <label class="sl-search">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                    <input type="search" x-model="q" placeholder="Find staff" :placeholder="$store.ui.lang==='en' ? 'Find staff by name or ID' : 'Cari staf ikut nama atau ID'" :aria-label="$store.ui.lang==='en' ? 'Find staff' : 'Cari staf'" aria-label="Find staff">
                </label>
            @endif
            @if ($activeRun->status === 'finalized')
                <a href="{{ route('payroll.export.register', $activeRun) }}" class="uj-btn-ghost" style="height:34px;padding:0 12px;font-size:var(--t-sm);display:inline-flex;align-items:center;gap:6px;text-decoration:none;color:var(--ink);">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 4v11"/><path d="m7 10 5 5 5-5"/><path d="M5 20h14"/></svg>
                    <span x-text="$store.ui.lang==='en' ? 'Download CSV' : 'Muat turun CSV'">Download CSV</span>
                </a>
            @endif
        </div>

        @if ($payslips->isEmpty())
            <div class="uj-card" style="padding:22px;color:var(--muted);font-size:13px;" x-text="$store.ui.lang==='en' ? 'This run has no payslips.' : 'Run ini tiada slip gaji.'">This run has no payslips.</div>
        @else
            @if ($activeRun->status !== 'finalized')
                <div class="sl-note">
                    <span class="uj-stamp" data-tone="amber" x-text="$store.ui.lang==='en' ? 'Not finalized' : 'Belum dimuktamadkan'">Not finalized</span>
                    <span x-text="$store.ui.lang==='en' ? 'These figures can still change until the run is finalized.' : 'Angka ini masih boleh berubah sehingga run dimuktamadkan.'">These figures can still change until the run is finalized.</span>
                </div>
            @endif

            <div class="sl-wrap" data-testid="salary-listing" tabindex="0" :aria-label="$store.ui.lang==='en' ? 'Salary listing' : 'Senarai gaji'" aria-label="Salary listing"
                 @scroll.passive="sx = $el.scrollLeft > 0; sy = $el.scrollTop > 0" :data-sx="sx || null" :data-sy="sy || null">
                <table class="sl-table" x-ref="table" @mouseover="const c = $event.target.closest('tbody td, tbody th, tfoot td'); paint(c ? c.cellIndex : -1)" @mouseleave="paint(-1)">
                    <thead>
                        <tr>
                            <th class="name"></th>
                            @foreach ($groups as [$en, $ms, $groupCols])
                                <th class="rule" colspan="{{ count($groupCols) }}" x-text="$store.ui.lang==='en' ? @js($en) : @js($ms)">{{ $en }}</th>
                            @endforeach
                            <th class="rule"></th>
                        </tr>
                        <tr>
                            <th class="name" scope="col"><span class="sl-name"><span class="sl-no">#</span><span x-text="$store.ui.lang==='en' ? 'Staff name' : 'Nama staf'">Staff name</span></span></th>
                            @foreach ($cols as [$key, $en, $ms, $first])
                                <th scope="col" @class(['num', 'rule' => $first]) x-text="$store.ui.lang==='en' ? @js($en) : @js($ms)">{{ $en }}</th>
                            @endforeach
                            <th scope="col" class="rule" style="text-align:left;" x-text="$store.ui.lang==='en' ? 'Remarks' : 'Catatan'">Remarks</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($payslips as $i => $p)
                            <tr x-show="shows({{ $i }})">
                                <th scope="row" class="name" style="font-weight:400;">
                                    <span class="sl-name">
                                        <span class="sl-no">{{ $i + 1 }}</span>
                                        <span class="sl-who" title="{{ $p->employee?->name }}">{{ $p->employee?->name }}</span>
                                        <span class="sl-id">{{ $p->employee?->staff_id }}</span>
                                    </span>
                                </th>
                                @foreach ($cols as [$key, , , $first])
                                    <td @class(['num', 'rule' => $first, 'key' => in_array($key, ['gross', 'net'], true), 'net' => $key === 'net', 'ded' => in_array($key, ['epf', 'pcb', 'socso', 'eis', 'skbbk', 'zakat', 'advance', 'other_deductions', 'cp38'], true)])>{{ $cell($rows[$i][$key]) }}</td>
                                @endforeach
                                <td class="remark rule" title="{{ $p->notes }}">{{ $p->notes }}</td>
                            </tr>
                        @endforeach
                        <tr class="sl-none" x-show="hits === 0" x-cloak>
                            <td colspan="{{ $cols->count() + 2 }}"><span x-text="$store.ui.lang==='en' ? 'No staff match that search.' : 'Tiada staf sepadan dengan carian itu.'">No staff match that search.</span></td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td class="name"><span class="sl-total"><span x-text="$store.ui.lang==='en' ? 'Total' : 'Jumlah'">Total</span><small x-show="q.trim() !== ''" x-cloak x-text="$store.ui.lang==='en' ? 'whole run' : 'seluruh run'">whole run</small></span></td>
                            @foreach ($cols as [$key, , , $first])
                                <td @class(['num', 'rule' => $first, 'net' => $key === 'net'])>{{ $cell($sum($key)) }}</td>
                            @endforeach
                            <td class="rule"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        @endif
    </div>
@endif
