@php
    $money = fn ($v) => 'RM '.number_format((float) $v, 2);
    $month = fn (?string $p) => $p ? \Illuminate\Support\Carbon::createFromFormat('Y-m', $p)->format('M Y') : null;
@endphp
{{-- Transaction, Fixed Transaction tab: pick a staff member on the left, manage their recurring lines on the right (Worksy layout). --}}
<div x-data="{
        q: '',
        pick: {{ (int) request('emp', $salaryEmployees->first()?->id ?? 0) }},
        rows: @js($salaryEmployees->map(fn ($e) => mb_strtolower(trim($e->display_name.' '.$e->name.' '.$e->position.' '.$e->staff_id)))->values()),
        hit(h) { return this.q.trim() === '' || h.includes(this.q.trim().toLowerCase()); },
     }" class="tx-split">
    @include('partials.payroll.transaction.staff-picker', ['counts' => $fixedTransactions->map->count()])

    <div class="tx-main">
        @foreach ($salaryEmployees as $e)
            @php $empFt = $fixedTransactions->get($e->id, collect()); @endphp
            <div x-show="pick === {{ $e->id }}" x-cloak x-data="{ mode: null, ending: null }">
                <div x-show="mode === null">
                    <div class="tx-head">
                        <div>
                            <h3 class="tx-title">{{ $e->name }}</h3>
                            <p class="tx-sub">{{ $e->position }} · <span x-text="$store.ui.lang==='en' ? 'Basic' : 'Asas'">Basic</span> {{ $money((float) ($e->salary ?? 0)) }}</p>
                        </div>
                        <button type="button" @click="mode = 'add'" class="uj-btn-primary" style="height:36px;padding:0 16px;" x-text="$store.ui.lang==='en' ? '+ Add Transaction' : '+ Tambah Transaksi'">+ Add Transaction</button>
                    </div>
                    <div class="uj-card" style="padding:0;">
                        <div style="padding:14px 18px;font-size:12px;color:var(--muted);border-bottom:1px solid var(--hairline-soft);" x-text="$store.ui.lang==='en' ? 'Recurring allowances and deductions, paid on every payslip from the start month to the end month. Mid Month ones are paid early in the mid-month run.' : 'Elaun dan potongan berulang, dibayar pada setiap payslip dari bulan mula hingga bulan tamat. Yang Pertengahan Bulan dibayar awal dalam run pertengahan bulan.'">Recurring allowances and deductions, paid on every payslip from the start month to the end month. Mid Month ones are paid early in the mid-month run.</div>
                        <div style="overflow-x:auto;">
                            <table class="tx-table">
                                <thead><tr>
                                    <th x-text="$store.ui.lang==='en' ? 'Payroll item' : 'Item gaji'">Payroll item</th>
                                    <th x-text="$store.ui.lang==='en' ? 'Period' : 'Tempoh'">Period</th>
                                    <th x-text="$store.ui.lang==='en' ? 'Pay cycle' : 'Kitaran'">Pay cycle</th>
                                    <th class="num" x-text="$store.ui.lang==='en' ? 'Amount' : 'Amaun'">Amount</th>
                                    <th></th>
                                </tr></thead>
                                <tbody>
                                    @forelse ($empFt as $ft)
                                        <tr>
                                            <td>
                                                <div style="font-weight:500;">{{ $ft->payrollItem?->name }}</div>
                                                <div style="font-size:11px;color:var(--muted);">
                                                    <span x-text="$store.ui.lang==='en' ? @js(ucfirst((string) $ft->payrollItem?->type)) : @js($ft->payrollItem?->type === 'deduction' ? 'Potongan' : 'Pendapatan')">{{ ucfirst((string) $ft->payrollItem?->type) }}</span>
                                                    @if ($ft->prorate) · <span x-text="$store.ui.lang==='en' ? 'prorated' : 'prorata'">prorated</span>@endif
                                                    @if ($ft->consent_reference) · <span x-text="$store.ui.lang==='en' ? 'consent' : 'kebenaran'">consent</span> {{ $ft->consent_reference }}@endif
                                                    @if ($ft->remarks) · {{ $ft->remarks }}@endif
                                                </div>
                                            </td>
                                            <td style="white-space:nowrap;">{{ $month($ft->start_period) }} → @if ($ft->end_period){{ $month($ft->end_period) }}@else<span x-text="$store.ui.lang==='en' ? 'no end' : 'tiada had'">no end</span>@endif</td>
                                            <td>
                                                <span class="tx-pill {{ $ft->payroll_cycle === 'mid_month' ? 'mm' : '' }}">{{ $ft->payroll_cycle === 'mid_month' ? 'MM' : 'ME' }}</span>
                                            </td>
                                            <td class="num">
                                                {{ $money($ft->amount) }}
                                                @if ($ft->last_amount !== null)<div style="font-size:10.5px;color:var(--muted);"><span x-text="$store.ui.lang==='en' ? 'last' : 'akhir'">last</span> {{ $money($ft->last_amount) }}@if ($ft->last_payroll_cycle) ({{ $ft->last_payroll_cycle === 'mid_month' ? 'MM' : 'ME' }})@endif</div>@endif
                                            </td>
                                            <td style="white-space:nowrap;text-align:right;">
                                                <button type="button" @click="mode = {{ $ft->id }}" class="tx-tool" x-text="$store.ui.lang==='en' ? 'Edit' : 'Sunting'">Edit</button>
                                                <button type="button" @click="ending = ending === {{ $ft->id }} ? null : {{ $ft->id }}" class="tx-tool danger" x-text="$store.ui.lang==='en' ? 'End' : 'Tamat'">End</button>
                                            </td>
                                        </tr>
                                        <tr x-show="ending === {{ $ft->id }}" x-cloak>
                                            <td colspan="5" style="background:var(--canvas);">
                                                <form method="post" action="{{ route('payroll.fixed-transactions.end', $ft) }}" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
                                                    @csrf
                                                    <div><label class="tx-label" x-text="$store.ui.lang==='en' ? 'Last period it still applies' : 'Tempoh terakhir ia masih terpakai'">Last period it still applies</label><input name="end_period" type="month" required value="{{ $currentPeriod }}" class="tx-input" style="width:180px;" /></div>
                                                    <button type="submit" class="uj-btn-primary" style="height:40px;padding:0 16px;background:var(--error);border-color:var(--error);" x-text="$store.ui.lang==='en' ? 'Confirm end' : 'Sahkan tamat'">Confirm end</button>
                                                </form>
                                                <div style="margin-top:8px;">@include('partials.hint', ['en' => 'This never deletes the row — it sets the last period it still applies, so past payslips stay explainable.', 'ms' => 'Ini tidak memadam rekod — ia menetapkan tempoh terakhir ia masih terpakai, supaya payslip lepas kekal boleh dijelaskan.'])</div>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="5" style="color:var(--muted);padding:18px 12px;" x-text="$store.ui.lang==='en' ? 'No fixed transactions.' : 'Tiada transaksi tetap.'">No fixed transactions.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                @include('partials.payroll.transaction.fixed-form', ['ft' => null])
                @foreach ($empFt as $ft)
                    @include('partials.payroll.transaction.fixed-form', ['ft' => $ft])
                @endforeach
            </div>
        @endforeach
        @if ($salaryEmployees->isEmpty())
            <div class="uj-card" style="padding:22px;color:var(--muted);font-size:13px;" x-text="$store.ui.lang==='en' ? 'No active staff yet.' : 'Belum ada staf aktif.'">No active staff yet.</div>
        @endif
    </div>
</div>
