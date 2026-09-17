@php
    $money = fn ($v) => 'RM '.number_format((float) $v, 2);
@endphp
<div x-data="{ itxAdding: false, itxEditing: null }">
    <div class="uj-card" style="max-width:820px;">
        <div class="uj-card-head" style="padding:16px 22px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
            <h3 class="uj-card-title" x-text="$store.ui.lang==='en' ? 'Individual transactions' : 'Transaksi individu'">Individual transactions</h3>
            <form method="get" action="{{ route('app.screen', 'payroll-transaction') }}" style="display:flex;align-items:center;gap:8px;">
                <input type="hidden" name="tab" value="individual" />
                <input name="itx_period" type="month" value="{{ $itxPeriod }}" style="height:34px;padding:0 9px;border:1px solid var(--hairline);border-radius:7px;font-size:12.5px;" />
                <button type="submit" class="uj-btn-ghost" style="height:34px;padding:0 12px;font-size:12px;" x-text="$store.ui.lang==='en' ? 'Go' : 'Pergi'">Go</button>
            </form>
        </div>

        <div style="padding:14px 22px;border-bottom:1px solid var(--hairline-soft);">
            @include('partials.hint', [
                'en' => 'A one-off earning or deduction for one person in this month only — the payroll run picks it up automatically when it is created (or on the next recalculate, if a draft run for this month already exists). A recurring one belongs on Salary structures → Fixed transactions instead.',
                'ms' => 'Pendapatan atau potongan sekali sahaja untuk seorang bagi bulan ini sahaja — payroll run akan menariknya secara automatik apabila dijana (atau pada kira semula seterusnya, jika draft run bagi bulan ini sudah wujud). Yang berulang patut di Struktur gaji → Transaksi tetap.',
            ])
            @if ($itxPeriodFinalized)
                <div style="margin-top:8px;font-size:12px;color:var(--error);" x-text="$store.ui.lang==='en' ? 'Payroll for this month has been finalized — individual transactions can no longer be added, edited or deleted for it.' : 'Payroll bagi bulan ini telah difinalize — transaksi individu tidak boleh ditambah, disunting atau dipadam lagi untuknya.'"></div>
            @elseif ($itxPeriodHasDraftRun)
                <div style="margin-top:8px;font-size:12px;color:#9a5b14;" x-text="$store.ui.lang==='en' ? 'A draft run already exists for this month. Changes here do not touch it automatically — open the affected payslip and Recalculate & save to apply them.' : 'Draft run bagi bulan ini sudah wujud. Perubahan di sini tidak menyentuhnya secara automatik — buka payslip berkenaan dan Kira semula & simpan untuk menerapkannya.'"></div>
            @endif
        </div>

        @if (!$itxPeriodFinalized)
            <div style="padding:12px 22px;border-bottom:1px solid var(--hairline-soft);display:flex;justify-content:flex-end;">
                <button type="button" @click="itxAdding = !itxAdding" class="uj-btn-ghost" style="height:30px;padding:0 12px;font-size:12px;" x-text="$store.ui.lang==='en' ? (itxAdding ? 'Cancel' : '+ Add') : (itxAdding ? 'Batal' : '+ Tambah')">+ Add</button>
            </div>
            <div x-show="itxAdding" x-cloak
                 x-data="{
                     q: '',
                     rows: @js($salaryEmployees->map(fn ($e) => mb_strtolower(trim($e->display_name.' '.$e->name.' '.$e->position)))->values()),
                     hit(h) { return this.q.trim() === '' || h.includes(this.q.trim().toLowerCase()); },
                 }"
                 style="padding:12px 22px;border-bottom:1px solid var(--hairline-soft);background:var(--canvas);">
                <form method="post" action="{{ route('payroll.individual-transactions.store') }}">
                    @csrf
                    <input type="hidden" name="period" value="{{ $itxPeriod }}" />
                    <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:flex-end;">
                        <div style="flex:1;min-width:160px;position:relative;">
                            <label style="display:block;font-size:10px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'Search name or nickname' : 'Cari nama atau gelaran'">Search name or nickname</label>
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="var(--muted)" stroke-width="2" stroke-linecap="round" style="position:absolute;left:7px;bottom:9px;pointer-events:none;"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
                            <input type="search" x-model="q" @keydown.escape="q = ''"
                                   :placeholder="$store.ui.lang==='en' ? 'Search name or nickname' : 'Cari nama atau gelaran'"
                                   style="width:100%;height:30px;padding:0 7px 0 24px;border:1px solid var(--hairline);border-radius:6px;font-size:12px;outline:none;" />
                        </div>
                        <div style="flex:1;min-width:160px;"><label style="display:block;font-size:10px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'Employee' : 'Pekerja'">Employee</label>
                            <select name="employee_id" required style="width:100%;height:30px;padding:0 7px;border:1px solid var(--hairline);border-radius:6px;font-size:12px;">
                                @foreach ($salaryEmployees as $e)
                                    <option value="{{ $e->id }}" x-show="hit(rows[{{ $loop->index }}])">{{ $e->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div style="flex:1;min-width:160px;"><label style="display:block;font-size:10px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'Payroll item' : 'Item payroll'">Payroll item</label>
                            <select name="payroll_item_id" required style="width:100%;height:30px;padding:0 7px;border:1px solid var(--hairline);border-radius:6px;font-size:12px;">
                                @foreach ($fixedTransactionItems as $item)
                                    <option value="{{ $item->id }}">{{ $item->name }} ({{ $item->type }})</option>
                                @endforeach
                            </select>
                        </div>
                        <div><label style="display:block;font-size:10px;color:var(--muted);">RM</label><input name="amount" type="number" step="0.01" min="0.01" required placeholder="0.00" style="width:100px;height:30px;padding:0 7px;border:1px solid var(--hairline);border-radius:6px;font-size:12px;font-family:var(--font-mono);" /></div>
                        <input name="remarks" placeholder="Remarks" :placeholder="$store.ui.lang==='en' ? 'Remarks' : 'Catatan'" style="flex:1;min-width:120px;height:30px;padding:0 7px;border:1px solid var(--hairline);border-radius:6px;font-size:12px;" />
                        <button type="submit" class="uj-btn-primary" style="height:30px;padding:0 12px;font-size:11.5px;" x-text="$store.ui.lang==='en' ? 'Add' : 'Tambah'">Add</button>
                    </div>
                </form>
            </div>
        @endif

        @forelse ($itxTransactions as $employeeId => $rows)
            <div style="border-bottom:1px solid var(--hairline-soft);padding:12px 22px;">
                <div style="font-size:12.5px;font-weight:600;color:var(--ink);margin-bottom:6px;">{{ $rows->first()->employee?->name }}</div>
                @foreach ($rows as $itx)
                    <div style="border:1px solid var(--hairline);border-radius:8px;padding:8px 10px;margin-bottom:6px;">
                        <div style="display:flex;align-items:center;gap:10px;">
                            <div style="flex:1;min-width:0;">
                                <div style="font-size:12.5px;color:var(--ink);font-weight:500;">{{ $itx->payrollItem?->name }} <span style="font-weight:400;color:var(--muted);">({{ $itx->payrollItem?->type }})</span></div>
                                @if ($itx->remarks)<div style="font-size:10.5px;color:var(--muted);">{{ $itx->remarks }}</div>@endif
                            </div>
                            <div style="font-family:var(--font-mono);font-size:12.5px;color:var(--ink);">{{ $money($itx->amount) }}</div>
                            @if (!$itxPeriodFinalized)
                                <button type="button" @click="itxEditing = itxEditing === {{ $itx->id }} ? null : {{ $itx->id }}" class="uj-btn-ghost" style="height:26px;padding:0 8px;font-size:11px;" x-text="$store.ui.lang==='en' ? 'Edit' : 'Sunting'">Edit</button>
                                <form method="post" action="{{ route('payroll.individual-transactions.delete', $itx) }}" onsubmit="return confirm(window.Alpine && Alpine.store('ui').lang==='ms' ? 'Padam transaksi individu ini?' : 'Delete this individual transaction?');">@csrf<button type="submit" class="uj-btn-ghost" style="height:26px;padding:0 8px;font-size:11px;color:var(--error);" x-text="$store.ui.lang==='en' ? 'Delete' : 'Padam'">Delete</button></form>
                            @endif
                        </div>
                        @if (!$itxPeriodFinalized)
                            <div x-show="itxEditing === {{ $itx->id }}" x-cloak style="margin-top:8px;padding-top:8px;border-top:1px solid var(--hairline-soft);">
                                <form method="post" action="{{ route('payroll.individual-transactions.update', $itx) }}">
                                    @csrf
                                    <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:flex-end;">
                                        <div style="flex:1;min-width:160px;"><label style="display:block;font-size:10px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'Payroll item' : 'Item payroll'">Payroll item</label>
                                            <select name="payroll_item_id" required style="width:100%;height:30px;padding:0 7px;border:1px solid var(--hairline);border-radius:6px;font-size:12px;">
                                                @foreach ($fixedTransactionItems as $item)
                                                    <option value="{{ $item->id }}" @selected($itx->payroll_item_id === $item->id)>{{ $item->name }} ({{ $item->type }})</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div><label style="display:block;font-size:10px;color:var(--muted);">RM</label><input name="amount" type="number" step="0.01" min="0.01" required value="{{ number_format($itx->amount, 2, '.', '') }}" style="width:100px;height:30px;padding:0 7px;border:1px solid var(--hairline);border-radius:6px;font-size:12px;font-family:var(--font-mono);" /></div>
                                        <input name="remarks" value="{{ $itx->remarks }}" placeholder="Remarks" :placeholder="$store.ui.lang==='en' ? 'Remarks' : 'Catatan'" style="flex:1;min-width:120px;height:30px;padding:0 7px;border:1px solid var(--hairline);border-radius:6px;font-size:12px;" />
                                        <button type="submit" class="uj-btn-primary" style="height:30px;padding:0 12px;font-size:11.5px;" x-text="$store.ui.lang==='en' ? 'Save' : 'Simpan'">Save</button>
                                    </div>
                                </form>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @empty
            <div style="padding:20px 22px;font-size:12.5px;color:var(--muted);" x-text="$store.ui.lang==='en' ? @js('No individual transactions queued for '.$itxPeriod.'.') : @js('Tiada transaksi individu untuk '.$itxPeriod.'.')">No individual transactions queued.</div>
        @endforelse
    </div>
</div>
