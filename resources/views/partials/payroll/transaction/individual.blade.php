@php
    $money = fn ($v) => number_format((float) $v, 2);
    $periodLabel = \Illuminate\Support\Carbon::createFromFormat('Y-m', $itxPeriod)->format('M Y');
    $isBonus = $itxCycle === 'bonus';
    // A bonus run pays bonus items only (EA B1(b)); deductions are open to every cycle.
    $itemsFor = fn (string $type) => $fixedTransactionItems->filter(fn ($i) => $i->type === $type && ($type === 'deduction' || ! $isBonus || $i->ea_box === 'B1(b)'));
    $cycleHidden = $isBonus ? ['for_bonus_run' => 1] : ['payroll_cycle' => $itxCycle];
@endphp
{{-- Transaction, Individual Transaction tab: one-offs for a month and pay cycle, per staff member (Worksy layout). --}}
<div x-data="{
        q: '',
        pick: {{ (int) request('emp', $salaryEmployees->first()?->id ?? 0) }},
        rows: @js($salaryEmployees->map(fn ($e) => mb_strtolower(trim($e->display_name.' '.$e->name.' '.$e->position.' '.$e->staff_id)))->values()),
        hit(h) { return this.q.trim() === '' || h.includes(this.q.trim().toLowerCase()); },
     }"
     x-init="$watch('pick', p => { const u = new URL(location.href); u.searchParams.set('emp', p); history.replaceState(null, '', u); })"
     class="tx-split">
    @include('partials.payroll.transaction.staff-picker', ['counts' => $itxTransactions->map->count()])

    <div class="tx-main">
        <div class="uj-card" style="padding:20px 22px;">
            <form method="get" action="{{ route('app.screen', 'payroll-transaction') }}" class="tx-grid" style="max-width:640px;">
                <input type="hidden" name="tab" value="individual" />
                <input type="hidden" name="emp" :value="pick" />
                <div>
                    <label class="tx-label" for="itx-period" x-text="$store.ui.lang==='en' ? 'Transaction Month' : 'Bulan Transaksi'">Transaction Month</label>
                    <input id="itx-period" name="itx_period" type="month" value="{{ $itxPeriod }}" required @change="$el.form.requestSubmit()" class="tx-input" />
                </div>
                <div>
                    <label class="tx-label" for="itx-cycle" x-text="$store.ui.lang==='en' ? 'Pay Cycle' : 'Kitaran Bayaran'">Pay Cycle</label>
                    <select id="itx-cycle" name="itx_cycle" @change="$el.form.requestSubmit()" class="tx-input">
                        <option value="bonus" @selected($isBonus)>Bonus (Bonus)</option>
                        <option value="mid_month" @selected($itxCycle === 'mid_month') x-text="$store.ui.lang==='en' ? 'Mid Month (MM)' : 'Pertengahan Bulan (MM)'">Mid Month (MM)</option>
                        <option value="month_end" @selected($itxCycle === 'month_end') x-text="$store.ui.lang==='en' ? 'Month End (ME)' : 'Hujung Bulan (ME)'">Month End (ME)</option>
                    </select>
                </div>
                <noscript><button type="submit">Go</button></noscript>
            </form>

            @if ($itxCycleLocked)
                <div style="margin-top:14px;font-size:12.5px;color:var(--error);" x-text="$store.ui.lang==='en' ? 'This cycle\'s payroll for {{ $periodLabel }} has been finalized, so its transactions can no longer be added, edited or deleted.' : 'Payroll kitaran ini bagi {{ $periodLabel }} telah difinalize, jadi transaksinya tidak boleh lagi ditambah, disunting atau dipadam.'"></div>
            @elseif (! $isBonus && $itxPeriodHasDraftRun)
                <div style="margin-top:14px;font-size:12.5px;color:var(--amber-ink);" x-text="$store.ui.lang==='en' ? 'A draft month-end run already exists for this month. Changes here do not touch it automatically: open the affected payslip and Recalculate & save to apply them.' : 'Draft run hujung bulan bagi bulan ini sudah wujud. Perubahan di sini tidak menyentuhnya secara automatik: buka payslip berkenaan dan Kira semula & simpan untuk menerapkannya.'"></div>
            @endif
            <div style="margin-top:14px;">
                @include('partials.hint', [
                    'en' => 'A one-off earning or deduction for one person in one month. The run for the chosen pay cycle picks it up when it is created. Something that repeats every month belongs on Fixed Transaction instead.',
                    'ms' => 'Pendapatan atau potongan sekali sahaja untuk seorang dalam satu bulan. Run bagi kitaran bayaran yang dipilih akan menariknya apabila dijana. Yang berulang setiap bulan patut di Transaksi Tetap.',
                ])
            </div>

            @foreach ($salaryEmployees as $e)
                @php $empTx = $itxTransactions->get($e->id, collect()); @endphp
                <div x-show="pick === {{ $e->id }}" x-cloak x-data="{ view: 'earning', adding: false, editing: null }">
                    <div class="tx-tiles">
                        <button type="button" class="tx-tile" :aria-pressed="view === 'earning'" @click="view = 'earning'; adding = false; editing = null">
                            <span x-text="$store.ui.lang==='en' ? 'Earnings' : 'Pendapatan'">Earnings</span><b>{{ $empTx->filter(fn ($t) => $t->payrollItem?->type === 'earning')->count() }}</b>
                        </button>
                        <button type="button" class="tx-tile" :aria-pressed="view === 'deduction'" @click="view = 'deduction'; adding = false; editing = null">
                            <span x-text="$store.ui.lang==='en' ? 'Deductions' : 'Potongan'">Deductions</span><b>{{ $empTx->filter(fn ($t) => $t->payrollItem?->type === 'deduction')->count() }}</b>
                        </button>
                        @foreach ([['Overtime', 'Lebih Masa'], ['Unpaid', 'Tanpa Gaji'], ['Leave', 'Cuti']] as [$en, $ms])
                            <button type="button" class="tx-tile" disabled :title="$store.ui.lang==='en' ? 'Pulled into the run automatically from approved requests' : 'Ditarik ke dalam run secara automatik daripada permohonan yang diluluskan'">
                                <span x-text="$store.ui.lang==='en' ? @js($en) : @js($ms)">{{ $en }}</span><b>–</b>
                            </button>
                        @endforeach
                    </div>

                    <div style="font-size:13.5px;font-weight:600;color:var(--ink);margin:0 0 10px;" x-text="$store.ui.lang==='en' ? 'Transaction Details' : 'Butiran Transaksi'">Transaction Details</div>
                    <div class="tx-tablewrap">
                        @unless ($itxCycleLocked)
                            <div class="tx-toolbar">
                                <button type="button" class="tx-tool" @click="adding = !adding; editing = null">
                                    <svg x-show="!adding" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
                                    <span x-text="adding ? ($store.ui.lang==='en' ? 'Cancel' : 'Batal') : ($store.ui.lang==='en' ? 'Add' : 'Tambah')">Add</span>
                                </button>
                            </div>
                        @endunless
                        <table class="tx-table">
                            <thead><tr>
                                <th x-text="$store.ui.lang==='en' ? 'Date' : 'Tarikh'">Date</th>
                                <th x-text="$store.ui.lang==='en' ? 'Payroll item' : 'Item gaji'">Payroll item</th>
                                <th x-text="$store.ui.lang==='en' ? 'Currency' : 'Mata wang'">Currency</th>
                                <th class="num" x-text="$store.ui.lang==='en' ? 'Amount' : 'Amaun'">Amount</th>
                                <th x-text="$store.ui.lang==='en' ? 'Remarks' : 'Catatan'">Remarks</th>
                                <th></th>
                            </tr></thead>
                            <tbody>
                                @unless ($itxCycleLocked)
                                    @foreach (['earning', 'deduction'] as $type)
                                        <tr x-show="adding && view === '{{ $type }}'" x-cloak>
                                            <td colspan="6" style="background:var(--canvas);">
                                                <form method="post" action="{{ route('payroll.individual-transactions.store') }}" style="display:grid;grid-template-columns:minmax(180px,2fr) minmax(110px,1fr) minmax(160px,2fr) auto;gap:10px;align-items:end;">
                                                    @csrf
                                                    <input type="hidden" name="employee_id" value="{{ $e->id }}" />
                                                    <input type="hidden" name="period" value="{{ $itxPeriod }}" />
                                                    @foreach ($cycleHidden as $name => $value)<input type="hidden" name="{{ $name }}" value="{{ $value }}" />@endforeach
                                                    <div><label class="tx-label" x-text="$store.ui.lang==='en' ? 'Payroll item' : 'Item gaji'">Payroll item</label>
                                                        <select name="payroll_item_id" required class="tx-input">
                                                            @foreach ($itemsFor($type) as $item)
                                                                <option value="{{ $item->id }}">{{ $item->name }}</option>
                                                            @endforeach
                                                        </select>
                                                    </div>
                                                    <div><label class="tx-label">RM</label><input name="amount" type="number" step="0.01" min="0.01" required placeholder="0.00" class="tx-input" style="font-family:var(--font-mono);" /></div>
                                                    <div><label class="tx-label" x-text="$store.ui.lang==='en' ? 'Remarks' : 'Catatan'">Remarks</label><input name="remarks" maxlength="255" class="tx-input" /></div>
                                                    <button type="submit" class="uj-btn-primary" style="height:40px;padding:0 18px;" x-text="$store.ui.lang==='en' ? 'Save' : 'Simpan'">Save</button>
                                                </form>
                                            </td>
                                        </tr>
                                    @endforeach
                                @endunless
                                @foreach ($empTx as $itx)
                                    @php $type = $itx->payrollItem?->type ?? 'earning'; @endphp
                                    <tr x-show="view === '{{ $type }}'">
                                        <td style="white-space:nowrap;">{{ $periodLabel }}</td>
                                        <td style="font-weight:500;">{{ $itx->payrollItem?->name }}</td>
                                        <td>MYR</td>
                                        <td class="num">{{ $money($itx->amount) }}</td>
                                        <td style="color:var(--body);">{{ $itx->remarks }}</td>
                                        <td style="white-space:nowrap;text-align:right;">
                                            @unless ($itxCycleLocked)
                                                <button type="button" class="tx-tool" @click="editing = editing === {{ $itx->id }} ? null : {{ $itx->id }}; adding = false" x-text="$store.ui.lang==='en' ? 'Edit' : 'Sunting'">Edit</button>
                                                <form method="post" action="{{ route('payroll.individual-transactions.delete', $itx) }}" style="display:inline;" onsubmit="return confirm(window.Alpine && Alpine.store('ui').lang==='ms' ? 'Padam transaksi individu ini?' : 'Delete this individual transaction?');">@csrf<button type="submit" class="tx-tool danger" x-text="$store.ui.lang==='en' ? 'Delete' : 'Padam'">Delete</button></form>
                                            @endunless
                                        </td>
                                    </tr>
                                    @unless ($itxCycleLocked)
                                        <tr x-show="editing === {{ $itx->id }}" x-cloak>
                                            <td colspan="6" style="background:var(--canvas);">
                                                <form method="post" action="{{ route('payroll.individual-transactions.update', $itx) }}" style="display:grid;grid-template-columns:minmax(180px,2fr) minmax(110px,1fr) minmax(160px,2fr) auto;gap:10px;align-items:end;">
                                                    @csrf
                                                    <div><label class="tx-label" x-text="$store.ui.lang==='en' ? 'Payroll item' : 'Item gaji'">Payroll item</label>
                                                        <select name="payroll_item_id" required class="tx-input">
                                                            @foreach ($itemsFor($type) as $item)
                                                                <option value="{{ $item->id }}" @selected($itx->payroll_item_id === $item->id)>{{ $item->name }}</option>
                                                            @endforeach
                                                        </select>
                                                    </div>
                                                    <div><label class="tx-label">RM</label><input name="amount" type="number" step="0.01" min="0.01" required value="{{ number_format($itx->amount, 2, '.', '') }}" class="tx-input" style="font-family:var(--font-mono);" /></div>
                                                    <div><label class="tx-label" x-text="$store.ui.lang==='en' ? 'Remarks' : 'Catatan'">Remarks</label><input name="remarks" maxlength="255" value="{{ $itx->remarks }}" class="tx-input" /></div>
                                                    <button type="submit" class="uj-btn-primary" style="height:40px;padding:0 18px;" x-text="$store.ui.lang==='en' ? 'Update' : 'Kemas kini'">Update</button>
                                                </form>
                                            </td>
                                        </tr>
                                    @endunless
                                @endforeach
                                @foreach (['earning', 'deduction'] as $type)
                                    @if ($empTx->filter(fn ($t) => ($t->payrollItem?->type ?? 'earning') === $type)->isEmpty())
                                        <tr x-show="view === '{{ $type }}' && !adding"><td colspan="6" style="color:var(--muted);padding:18px 12px;" x-text="$store.ui.lang==='en' ? 'No records to display' : 'Tiada rekod untuk dipaparkan'">No records to display</td></tr>
                                    @endif
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endforeach
            @if ($salaryEmployees->isEmpty())
                <div style="padding:22px 0 0;color:var(--muted);font-size:13px;" x-text="$store.ui.lang==='en' ? 'No active staff yet.' : 'Belum ada staf aktif.'">No active staff yet.</div>
            @endif
        </div>
    </div>
</div>
