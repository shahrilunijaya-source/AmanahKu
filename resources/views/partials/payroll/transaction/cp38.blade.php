{{-- Transaction CP38 tab, as Worksy has it: pick a staff member on the left, then a
     12-month grid for the year on the right. Edit types each month's CP38; a pay run
     deducts that month's figure. A month with a finalized run shows what it took and
     stays locked. --}}
@php
    $cp38Rows = \App\Models\PayrollCp38Month::get(['employee_id', 'period', 'amount'])->groupBy('employee_id')
        ->map(fn ($rows) => $rows->mapWithKeys(fn ($r) => [$r->period => $r->amount])->all());
    $cp38Taken = \App\Models\Payslip::whereHas('payrollRun', fn ($q) => $q->where('status', 'finalized')->where('kind', '<>', 'bonus'))
        ->with('payrollRun:id,period')->get(['id', 'employee_id', 'payroll_run_id', 'cp38'])
        ->groupBy('employee_id')->map(fn ($slips) => $slips->groupBy(fn ($s) => $s->payrollRun->period)->map(fn ($g) => (float) $g->sum('cp38'))->all());
    $thisYear = (int) now()->format('Y');
    $rowYears = $cp38Rows->flatMap(fn ($m) => array_keys($m))->map(fn ($p) => (int) substr($p, 0, 4))->all();
    $cp38Years = range(min([$thisYear - 1, ...$rowYears]), max([$thisYear + 1, ...$rowYears]));
    $cp38Year = in_array((int) request('cp38_year'), $cp38Years, true) ? (int) request('cp38_year') : $thisYear;
    $monthNames = [['January', 'Januari'], ['February', 'Februari'], ['March', 'Mac'], ['April', 'April'], ['May', 'Mei'], ['June', 'Jun'], ['July', 'Julai'], ['August', 'Ogos'], ['September', 'September'], ['October', 'Oktober'], ['November', 'November'], ['December', 'Disember']];
    $box = 'width:100%;height:34px;padding:0 10px;border:1px solid var(--hairline);border-radius:6px;font-family:var(--font-mono);font-size:12.5px;';
@endphp
<div x-data="{
        q: '',
        year: {{ $cp38Year }},
        years: @js($cp38Years),
        pick: {{ (int) request('emp', $salaryEmployees->first()?->id ?? 0) }},
        rows: @js($salaryEmployees->map(fn ($e) => mb_strtolower(trim($e->display_name.' '.$e->name.' '.$e->position.' '.$e->staff_id)))->values()),
        hit(h) { return this.q.trim() === '' || h.includes(this.q.trim().toLowerCase()); },
     }" x-init="$watch('pick', v => { const u = new URL(location.href); u.searchParams.set('emp', v); history.replaceState(null, '', u); })" style="display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap;">
    <div class="uj-card" style="flex:1;min-width:240px;max-width:300px;padding:0;">
        <div style="padding:12px;border-bottom:1px solid var(--hairline);">
            <input type="search" x-model="q" @keydown.escape="q = ''" :placeholder="$store.ui.lang==='en' ? 'Search name or ID' : 'Cari nama atau ID'" style="width:100%;height:32px;padding:0 12px;border:1px solid var(--hairline);border-radius:8px;font-size:12.5px;outline:none;background:var(--surface,#fff);color:var(--ink);">
        </div>
        <div style="max-height:560px;overflow:auto;">
            @foreach ($salaryEmployees as $e)
                <div x-show="hit(rows[{{ $loop->index }}])"><button type="button" @click="pick = {{ $e->id }}" :style="{ background: pick === {{ $e->id }} ? 'var(--canvas)' : 'none' }" style="display:flex;width:100%;text-align:left;align-items:center;gap:10px;padding:10px 14px;border:0;border-bottom:1px solid var(--hairline-soft);background:none;cursor:pointer;">
                    <div style="width:28px;height:28px;border-radius:50%;background:{{ $e->avatar_color ?? '#3a6ea5' }};color:#fff;display:flex;align-items:center;justify-content:center;font-size:10.5px;font-weight:600;flex-shrink:0;">{{ $e->initials }}</div>
                    <div style="min-width:0;"><div style="font-size:12.5px;color:var(--ink);font-weight:500;">{{ $e->name }}</div><div style="font-size:11px;color:var(--muted);">{{ $e->position }}{{ $e->staff_id ? ' · '.$e->staff_id : '' }}</div></div>
                </button></div>
            @endforeach
        </div>
    </div>

    <div style="flex:2;min-width:min(380px,100%);">
        @foreach ($salaryEmployees as $e)
            @php
                $set = $cp38Rows->get($e->id, []);
                $taken = $cp38Taken->get($e->id, []);
                $grid = collect($cp38Years)->mapWithKeys(fn ($y) => [$y => collect(range(1, 12))->map(function ($m) use ($y, $set, $taken) {
                    $period = sprintf('%04d-%02d', $y, $m);
                    $locked = array_key_exists($period, $taken);

                    return ['amount' => round($locked ? $taken[$period] : ($set[$period] ?? 0.0), 2), 'locked' => $locked];
                })->all()]);
            @endphp
            <div x-show="pick === {{ $e->id }}" x-cloak class="uj-card" style="padding:20px;"
                 x-data="{ grid: @js($grid), editing: false, vals: [], start() { this.vals = this.grid[this.year].map(m => m.amount.toFixed(2)); this.editing = true; } }">
                <form method="post" action="{{ route('payroll.cp38.update') }}">
                    @csrf
                    <input type="hidden" name="employee_id" value="{{ $e->id }}" />
                    <input type="hidden" name="year" :value="year" />
                    <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                        <button type="button" @click="year--" :disabled="editing || year <= years[0]" class="uj-btn-ghost" style="height:30px;width:32px;padding:0;font-size:14px;" aria-label="Previous year">‹</button>
                        <button type="button" @click="year++" :disabled="editing || year >= years[years.length - 1]" class="uj-btn-ghost" style="height:30px;width:32px;padding:0;font-size:14px;" aria-label="Next year">›</button>
                        <span style="font-size:15px;font-weight:600;color:var(--ink);margin-left:6px;" x-text="year">{{ $cp38Year }}</span>
                        <span style="font-size:12px;color:var(--muted);margin-left:10px;">{{ $e->name }}</span>
                        <div style="margin-left:auto;display:flex;gap:6px;">
                            <button type="button" x-show="!editing" @click="start()" class="uj-btn-primary" style="height:30px;padding:0 16px;font-size:12px;" x-text="$store.ui.lang==='en' ? 'Edit' : 'Sunting'">Edit</button>
                            <button type="button" x-show="editing" x-cloak @click="editing = false" class="uj-btn-ghost" style="height:30px;padding:0 12px;font-size:12px;" x-text="$store.ui.lang==='en' ? 'Cancel' : 'Batal'">Cancel</button>
                            <button type="submit" x-show="editing" x-cloak class="uj-btn-primary" style="height:30px;padding:0 16px;font-size:12px;" x-text="$store.ui.lang==='en' ? 'Save' : 'Simpan'">Save</button>
                        </div>
                    </div>
                    <div style="border-top:1px solid var(--hairline-soft);margin:12px 0 14px;"></div>
                    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px 18px;">
                        @foreach ($monthNames as $i => [$men, $mms])
                            <div>
                                <label style="display:block;font-size:11px;color:var(--muted);margin-bottom:4px;" x-text="$store.ui.lang==='en' ? '{{ $men }} (RM)' : '{{ $mms }} (RM)'">{{ $men }} (RM)</label>
                                <div x-show="!editing || grid[year][{{ $i }}].locked" style="{{ $box }}line-height:32px;background:var(--canvas);color:var(--ink);"
                                     x-text="grid[year][{{ $i }}].amount.toLocaleString('en-MY', { minimumFractionDigits: 2, maximumFractionDigits: 2 })">0.00</div>
                                <template x-if="editing">
                                    <input :type="grid[year][{{ $i }}].locked ? 'hidden' : 'number'" name="amounts[]" x-model="vals[{{ $i }}]" step="0.01" min="0" style="{{ $box }}background:var(--surface,#fff);color:var(--ink);" />
                                </template>
                            </div>
                        @endforeach
                    </div>
                    <p x-show="grid[year].some(m => m.locked)" style="font-size:11px;color:var(--muted);margin:12px 0 0;" x-text="$store.ui.lang==='en' ? 'Months with a finalized pay run show what that run deducted and cannot be changed.' : 'Bulan yang larian gajinya telah dimuktamadkan menunjukkan potongan larian itu dan tidak boleh diubah.'">Months with a finalized pay run show what that run deducted and cannot be changed.</p>
                </form>
            </div>
        @endforeach
        @if ($salaryEmployees->isEmpty())
            <div class="uj-card" style="padding:22px;color:var(--muted);font-size:13px;" x-text="$store.ui.lang==='en' ? 'No active staff yet.' : 'Belum ada staf aktif.'">No active staff yet.</div>
        @endif
    </div>
</div>
