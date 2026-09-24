{{--
    Worksy's "Add Transaction" / "Edit Transaction" form for one Fixed Transaction.
    $e: the employee. $ft: the transaction being edited, or null to add one. Shown while
    the parent's `mode` is 'add' (new) or the transaction's id (edit).
--}}
@php
    $formId = 'ft-form-'.$e->id.'-'.($ft?->id ?? 'new');
    $num = fn ($v) => $v === null ? '' : number_format((float) $v, 2, '.', '');
@endphp
<div x-show="mode === @js($ft?->id ?? 'add')" x-cloak x-data="{ type: @js($ft?->payrollItem?->type ?? 'earning') }">
    <div class="tx-head">
        <div>
            <h3 class="tx-title" x-text="$store.ui.lang==='en' ? @js($ft ? 'Edit Transaction' : 'Add Transaction') : @js($ft ? 'Sunting Transaksi' : 'Tambah Transaksi')">{{ $ft ? 'Edit Transaction' : 'Add Transaction' }}</h3>
            <p class="tx-sub">{{ $e->name }}</p>
        </div>
        <div style="display:flex;gap:8px;">
            <button type="button" class="tx-back" @click="mode = null" :aria-label="$store.ui.lang==='en' ? 'Back' : 'Kembali'"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M15 18l-6-6 6-6"/></svg></button>
            <button type="submit" form="{{ $formId }}" class="uj-btn-primary" style="height:36px;padding:0 18px;" x-text="$store.ui.lang==='en' ? 'Save' : 'Simpan'">Save</button>
        </div>
    </div>

    <form id="{{ $formId }}" method="post" action="{{ $ft ? route('payroll.fixed-transactions.update', $ft) : route('payroll.fixed-transactions.store') }}" class="uj-card tx-form">
        @csrf
        @unless ($ft)
            <input type="hidden" name="employee_id" value="{{ $e->id }}" />
        @endunless
        <div class="tx-grid">
            <div>
                <span class="tx-label"><span x-text="$store.ui.lang==='en' ? 'Payroll Item Type' : 'Jenis Item Gaji'">Payroll Item Type</span> <em>*</em></span>
                <div class="tx-type" role="group">
                    <button type="button" :aria-pressed="type === 'earning'" @click="type = 'earning'; $refs.item.value = ''" x-text="$store.ui.lang==='en' ? 'Earning' : 'Pendapatan'">Earning</button>
                    <button type="button" :aria-pressed="type === 'deduction'" @click="type = 'deduction'; $refs.item.value = ''" x-text="$store.ui.lang==='en' ? 'Deduction' : 'Potongan'">Deduction</button>
                </div>
            </div>
            <div>
                <label class="tx-label" for="{{ $formId }}-item"><span x-text="$store.ui.lang==='en' ? 'Payroll Item' : 'Item Gaji'">Payroll Item</span> <em>*</em></label>
                <select id="{{ $formId }}-item" name="payroll_item_id" x-ref="item" required class="tx-input">
                    <option value="" x-text="$store.ui.lang==='en' ? 'Select Payroll Item' : 'Pilih Item Gaji'">Select Payroll Item</option>
                    @foreach ($fixedTransactionItems as $item)
                        <option value="{{ $item->id }}" @selected($ft?->payroll_item_id === $item->id) :hidden="type !== @js($item->type)" :disabled="type !== @js($item->type)">{{ $item->name }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="tx-label" for="{{ $formId }}-start"><span x-text="$store.ui.lang==='en' ? 'Start Date' : 'Tarikh Mula'">Start Date</span> <em>*</em></label>
                <input id="{{ $formId }}-start" name="start_period" type="month" required value="{{ $ft?->start_period ?? $currentPeriod }}" class="tx-input" />
            </div>
            <div>
                <label class="tx-label" for="{{ $formId }}-end" x-text="$store.ui.lang==='en' ? 'End Date' : 'Tarikh Tamat'">End Date</label>
                <input id="{{ $formId }}-end" name="end_period" type="month" value="{{ $ft?->end_period }}" class="tx-input" />
                <div class="tx-note" x-text="$store.ui.lang==='en' ? 'Leave blank to keep it running with no end.' : 'Biarkan kosong supaya ia berterusan tanpa tarikh tamat.'">Leave blank to keep it running with no end.</div>
            </div>

            <div>
                <label class="tx-label" for="{{ $formId }}-amount"><span x-text="$store.ui.lang==='en' ? 'Regular Amount (RM)' : 'Amaun Biasa (RM)'">Regular Amount (RM)</span> <em>*</em></label>
                <input id="{{ $formId }}-amount" name="amount" type="number" step="0.01" min="0.01" required value="{{ $num($ft?->amount) }}" placeholder="0.00" class="tx-input" style="font-family:var(--font-mono);" />
            </div>
            <div>
                <label class="tx-label" for="{{ $formId }}-cycle"><span x-text="$store.ui.lang==='en' ? 'Regular Pay Cycle' : 'Kitaran Bayaran Biasa'">Regular Pay Cycle</span> <em>*</em></label>
                <select id="{{ $formId }}-cycle" name="payroll_cycle" required class="tx-input">
                    <option value="month_end" @selected(($ft?->payroll_cycle ?? 'month_end') === 'month_end') x-text="$store.ui.lang==='en' ? 'Month End (ME)' : 'Hujung Bulan (ME)'">Month End (ME)</option>
                    <option value="mid_month" @selected($ft?->payroll_cycle === 'mid_month') x-text="$store.ui.lang==='en' ? 'Mid Month (MM)' : 'Pertengahan Bulan (MM)'">Mid Month (MM)</option>
                </select>
            </div>

            <div>
                <label class="tx-label" for="{{ $formId }}-last" x-text="$store.ui.lang==='en' ? 'Last Amount (RM)' : 'Amaun Terakhir (RM)'">Last Amount (RM)</label>
                <input id="{{ $formId }}-last" name="last_amount" type="number" step="0.01" min="0" value="{{ $num($ft?->last_amount) }}" placeholder="0.00" class="tx-input" style="font-family:var(--font-mono);" />
                <div class="tx-note" x-text="$store.ui.lang==='en' ? 'Only if the end month pays a different amount.' : 'Hanya jika bulan tamat membayar amaun berbeza.'">Only if the end month pays a different amount.</div>
            </div>
            <div>
                <label class="tx-label" for="{{ $formId }}-lastcycle" x-text="$store.ui.lang==='en' ? 'Last Amount Pay Cycle' : 'Kitaran Bayaran Amaun Terakhir'">Last Amount Pay Cycle</label>
                <select id="{{ $formId }}-lastcycle" name="last_payroll_cycle" class="tx-input">
                    <option value="" x-text="$store.ui.lang==='en' ? 'Same as regular' : 'Sama seperti biasa'">Same as regular</option>
                    <option value="month_end" @selected($ft?->last_payroll_cycle === 'month_end') x-text="$store.ui.lang==='en' ? 'Month End (ME)' : 'Hujung Bulan (ME)'">Month End (ME)</option>
                    <option value="mid_month" @selected($ft?->last_payroll_cycle === 'mid_month') x-text="$store.ui.lang==='en' ? 'Mid Month (MM)' : 'Pertengahan Bulan (MM)'">Mid Month (MM)</option>
                </select>
            </div>

            <div class="tx-full">
                <label style="display:inline-flex;align-items:center;gap:12px;cursor:pointer;">
                    <span class="tx-switch"><input type="checkbox" name="prorate" value="1" @checked($ft?->prorate) /><span></span></span>
                    <span style="font-size:13px;font-weight:500;color:var(--ink);" x-text="$store.ui.lang==='en' ? 'Enable Prorate' : 'Dayakan Prorata'">Enable Prorate</span>
                </label>
                <div class="tx-note" style="margin-left:54px;" x-text="$store.ui.lang==='en' ? 'A joiner or leaver is paid for the days they were employed, counted on the real days in that month.' : 'Pekerja baru atau yang keluar dibayar mengikut hari mereka bekerja, dikira atas hari sebenar dalam bulan itu.'">A joiner or leaver is paid for the days they were employed, counted on the real days in that month.</div>
            </div>

            <div class="tx-full" x-show="type === 'deduction'">
                <label class="tx-label" for="{{ $formId }}-consent" x-text="$store.ui.lang==='en' ? 'Written consent reference' : 'Rujukan kebenaran bertulis'">Written consent reference</label>
                <input id="{{ $formId }}-consent" name="consent_reference" maxlength="160" value="{{ $ft?->consent_reference }}" class="tx-input" />
                <div class="tx-note" x-text="$store.ui.lang==='en' ? 'EA s.24: a non-statutory deduction needs the employee\'s written consent. Put the date signed or the agreement reference.' : 'AK s.24: potongan bukan statutori perlu kebenaran bertulis pekerja. Isikan tarikh ditandatangani atau rujukan perjanjian.'"></div>
            </div>

            <div class="tx-full">
                <label class="tx-label" for="{{ $formId }}-remarks" x-text="$store.ui.lang==='en' ? 'Remarks' : 'Catatan'">Remarks</label>
                <textarea id="{{ $formId }}-remarks" name="remarks" maxlength="255" class="tx-input">{{ $ft?->remarks }}</textarea>
            </div>
        </div>
    </form>
</div>
