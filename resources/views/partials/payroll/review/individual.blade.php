@php
    $statusColor = ['draft' => 'var(--amber)', 'approved' => 'var(--info)', 'finalized' => 'var(--success)'];
    $statusMs = ['draft' => 'Draf', 'approved' => 'Diluluskan', 'finalized' => 'Difinalize'];
    $money = fn ($v) => 'RM '.number_format((float) $v, 2);
    $latest = $activeRun?->totals ?? [];
    $payslipRows = $activeRun ? $activeRun->payslips->sortBy('employee.name')->values() : collect();
    $openSlip = (int) request('payslip', $payslipRows->first()?->id ?? 0);
@endphp
<form method="get" action="{{ route('app.screen', 'payroll-review') }}" style="display:flex;align-items:center;gap:8px;margin-bottom:14px;">
    <input type="hidden" name="tab" value="individual">
    <label style="font-size:12.5px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'Run' : 'Run'">Run</label>
    <select name="run" onchange="this.form.submit()" style="height:34px;padding:0 10px;border:1px solid var(--hairline);border-radius:8px;font-size:12.5px;">
        @foreach ($runs as $r)
            <option value="{{ $r->id }}" @selected($activeRun?->id === $r->id)>{{ $r->label }} · {{ $r->status }}</option>
        @endforeach
    </select>
</form>

@if (!$activeRun)
    <div class="uj-card" style="padding:22px;color:var(--muted);font-size:13px;" x-text="$store.ui.lang==='en' ? 'No payroll run yet. Create one under Process.' : 'Belum ada run gaji. Buat satu di bawah Proses.'">No payroll run yet. Create one under Process.</div>
@else
<div x-data="{
        q: '',
        editing: null,
        pick: {{ $openSlip }},
        rows: @js($payslipRows->map(fn ($p) => mb_strtolower(trim($p->employee?->display_name.' '.$p->employee?->name.' '.$p->employee?->position)))->values()),
        hit(h) { return this.q.trim() === '' || h.includes(this.q.trim().toLowerCase()); },
     }" style="display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap;">
    {{-- Staff picker --}}
    <div class="uj-card" style="flex:1;min-width:240px;max-width:300px;padding:0;">
        <div style="padding:12px;border-bottom:1px solid var(--hairline);">
            <input type="search" x-model="q" @keydown.escape="q = ''" :placeholder="$store.ui.lang==='en' ? 'Search name or nickname' : 'Cari nama atau gelaran'" style="width:100%;height:32px;padding:0 12px;border:1px solid var(--hairline);border-radius:8px;font-size:12.5px;outline:none;">
        </div>
        <div style="max-height:560px;overflow:auto;">
            @foreach ($payslipRows as $p)
                <div x-show="hit(rows[{{ $loop->index }}])"><button type="button" @click="pick = {{ $p->id }}" :style="{ background: pick === {{ $p->id }} ? 'var(--canvas)' : 'none' }" style="display:flex;width:100%;text-align:left;align-items:center;gap:10px;padding:10px 14px;border:0;border-bottom:1px solid var(--hairline-soft);background:none;cursor:pointer;">
                    <div style="min-width:0;flex:1;"><div style="font-size:12.5px;color:var(--ink);font-weight:500;">{{ $p->employee?->name }}</div><div style="font-size:11px;color:var(--muted);">{{ $p->employee?->position }}</div></div>
                    <span style="font-size:12px;font-family:var(--font-mono);color:var(--ink);">{{ $money($p->net_pay) }}</span>
                </button></div>
            @endforeach
        </div>
    </div>

    {{-- Chosen payslip --}}
    <div class="uj-card" style="flex:2;min-width:min(420px,100%);padding:0;">
                <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:18px 22px;border-bottom:1px solid var(--hairline);">
                    <div>
                        <h3 class="uj-card-title">{{ $activeRun->label }}</h3>
                        <div style="font-size:12px;color:var(--muted);margin-top:2px;"><span x-text="$store.ui.lang==='en' ? 'Gross' : 'Kasar'">Gross</span> {{ $money($latest['gross'] ?? 0) }} · <span x-text="$store.ui.lang==='en' ? 'Deductions' : 'Potongan'">Deductions</span> {{ $money($latest['deductions'] ?? 0) }} · <span x-text="$store.ui.lang==='en' ? 'Net' : 'Bersih'">Net</span> {{ $money($latest['net'] ?? 0) }}</div>
                        @if ($activeRun->status === 'finalized')
                            @php $ps = $activeRun->payslips; @endphp
                            <div style="font-size:11.5px;color:var(--muted);margin-top:3px;"><span x-text="$store.ui.lang==='en' ? 'Employer' : 'Majikan'">Employer</span> — EPF {{ $money($ps->sum('epf_employer')) }} · SOCSO {{ $money($ps->sum('socso_employer')) }} · EIS {{ $money($ps->sum('eis_employer')) }} · <span x-text="$store.ui.lang==='en' ? 'PCB collected' : 'PCB dikutip'">PCB collected</span> {{ $money($ps->sum('pcb') + $ps->sum('pcb_additional')) }}</div>
                        @endif
                    </div>
            </div>

                @if ($activeRun->status !== 'finalized')
                    <div style="padding:10px 22px;background:#fff7ed;border-bottom:1px solid var(--hairline-soft);font-size:11.5px;color:#9a5b14;" x-text="$store.ui.lang==='en' ? 'Draft figures. PCB (income tax) is computed automatically and can be overridden per employee if needed. Verify statutory amounts before finalizing.' : 'Angka draf. PCB (cukai pendapatan) dikira automatik dan boleh ditindih bagi setiap pekerja jika perlu. Sahkan jumlah berkanun sebelum finalize.'">Draft figures. PCB (income tax) is computed automatically and can be overridden per employee if needed. Verify statutory amounts before finalizing.</div>
                @endif
        @foreach ($payslipRows as $p)
            <div x-show="pick === {{ $p->id }}" x-cloak>
                                <div style="display:flex;align-items:center;gap:12px;padding:12px 22px;">
                                    <div style="width:30px;height:30px;border-radius:50%;background:{{ $p->employee?->avatar_color ?? '#3a6ea5' }};color:#fff;display:flex;align-items:center;justify-content:center;font-size:10.5px;font-weight:600;flex-shrink:0;">{{ $p->employee?->initials }}</div>
                                    <div style="flex:1;min-width:0;">
                                        <div style="font-size:13px;color:var(--ink);font-weight:500;">{{ $p->employee?->name }}</div>
                                        <div style="font-size:11px;color:var(--muted);"><span x-text="$store.ui.lang==='en' ? 'Gross' : 'Kasar'">Gross</span> {{ $money($p->gross) }} · <span x-text="$store.ui.lang==='en' ? 'Deduct' : 'Potong'">Deduct</span> {{ $money($p->total_deductions) }}@if ($p->pcb_override !== null) · <span style="color:var(--info);" x-text="$store.ui.lang==='en' ? 'PCB overridden' : 'PCB ditindih'">PCB overridden</span>@endif</div>
                                    </div>
                                    <div style="text-align:right;"><div style="font-size:13.5px;font-weight:600;color:var(--ink);font-family:var(--font-mono);">{{ $money($p->net_pay) }}</div><div style="font-size:10.5px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'net' : 'bersih'">net</div></div>
                                    @if ($activeRun->status !== 'finalized')
                                        <button @click="editing === {{ $p->id }} ? editing = null : editing = {{ $p->id }}" class="uj-btn-ghost" style="height:32px;padding:0 12px;font-size:12px;" x-text="$store.ui.lang==='en' ? 'Edit' : 'Sunting'">Edit</button>
                                    @endif
                                </div>

                                {{-- Spec F4 flags: s.24 deduction cap, negative net and the 104-hour overtime limit. --}}
                                @if ($p->deduction_cap_exceeded || $p->net_pay < 0 || $p->carried_forward_amount > 0 || $p->pulled_overtime_hours > \App\Services\Payroll\PayrollCalculator::OVERTIME_HOURS_CAP)
                                    <div style="display:flex;flex-wrap:wrap;align-items:center;gap:8px;padding:0 22px 12px 64px;">
                                        @if ($p->deduction_cap_exceeded)
                                            <form method="post" action="{{ route('payroll.payslips.consent', $p) }}" style="display:flex;align-items:center;gap:6px;">@csrf
                                                <span class="uj-pill" style="background:{{ $p->deduction_consent_confirmed ? 'var(--red-tint)' : '#fff7e6' }};color:{{ $p->deduction_consent_confirmed ? 'var(--success)' : 'var(--amber)' }};font-size:10.5px;" x-text="$store.ui.lang==='en' ? 'Deductions over 50% (s.24)' : 'Potongan melebihi 50% (s.24)'">Deductions over 50% (s.24)</span>
                                                @if ($activeRun->status !== 'finalized')
                                                    @if ($p->deduction_consent_confirmed)
                                                        <button type="submit" class="uj-btn-ghost" style="height:26px;padding:0 8px;font-size:11px;" x-text="$store.ui.lang==='en' ? 'Consent recorded · withdraw' : 'Kebenaran direkod · tarik balik'">Consent recorded · withdraw</button>
                                                    @else
                                                        <button type="submit" class="uj-btn-ghost" style="height:26px;padding:0 8px;font-size:11px;" x-text="$store.ui.lang==='en' ? 'Employee consented in writing' : 'Pekerja beri kebenaran bertulis'">Employee consented in writing</button>
                                                    @endif
                                                @endif
                                            </form>
                                        @endif
                                        @if ($p->net_pay < 0)
                                            <form method="post" action="{{ route('payroll.payslips.carry-forward', $p) }}" style="display:flex;align-items:center;gap:6px;">@csrf
                                                <span class="uj-pill" style="background:var(--red-tint);color:var(--error);font-size:10.5px;" x-text="$store.ui.lang==='en' ? 'Net pay negative' : 'Gaji bersih negatif'">Net pay negative</span>
                                                @if ($activeRun->status !== 'finalized')
                                                    <button type="submit" class="uj-btn-ghost" style="height:26px;padding:0 8px;font-size:11px;" x-text="$store.ui.lang==='en' ? 'Carry to next month' : 'Bawa ke bulan depan'">Carry to next month</button>
                                                @endif
                                            </form>
                                        @endif
                                        @if ($p->carried_forward_amount > 0)
                                            <span class="uj-pill" style="background:#fff7e6;color:var(--amber);font-size:10.5px;">{{ $money($p->carried_forward_amount) }} <span x-text="$store.ui.lang==='en' ? 'carried to next month' : 'dibawa ke bulan depan'">carried to next month</span></span>
                                        @endif
                                        @if ($p->pulled_overtime_hours > \App\Services\Payroll\PayrollCalculator::OVERTIME_HOURS_CAP)
                                            <span class="uj-pill" style="background:#fff7e6;color:var(--amber);font-size:10.5px;" x-text="$store.ui.lang==='en' ? 'Overtime above 104h' : 'Kerja lebih masa melebihi 104j'">Overtime above 104h</span>
                                        @endif
                                    </div>
                                @endif

                                {{-- Inline variable-input editor. A bonus payslip has no
                                     variable inputs of its own — it is exactly the
                                     individual transactions flagged for the bonus run, so
                                     it is changed there and the run regenerated. --}}
                                @if ($activeRun->status !== 'finalized' && ! $activeRun->isBonus() && ! $activeRun->isMidMonth())
                                    <div x-show="editing === {{ $p->id }}" x-cloak style="padding:4px 22px 18px 64px;">
                                        <form method="post" action="{{ route('payroll.payslips.update', $p) }}" style="background:var(--canvas);border:1px solid var(--hairline);border-radius:10px;padding:16px;">
                                            @csrf
                                            @php
                                                $otPulled = rtrim(rtrim(number_format($p->pulled_overtime_hours, 2), '0'), '.') ?: '0';
                                                $unpaidPulled = rtrim(rtrim(number_format($p->pulled_unpaid_days, 2), '0'), '.') ?: '0';
                                            @endphp
                                            <div style="display:grid;grid-template-columns:repeat(5, 1fr);gap:12px;margin-bottom:4px;">
                                                <div><label style="display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;" x-text="$store.ui.lang==='en' ? 'Overtime hours (override)' : 'Jam OT (tindihan)'">Overtime hours (override)</label><input name="overtime_hours" type="number" step="0.5" min="0" value="{{ $p->overtime_overridden ? rtrim(rtrim(number_format($p->overtime_hours, 2), '0'), '.') : '' }}" placeholder="{{ $otPulled }}" style="width:100%;height:36px;padding:0 10px;border:1px solid var(--hairline);border-radius:7px;font-size:13px;font-family:var(--font-mono);outline:none;" /></div>
                                                {{-- Same unit as the pulled figure's per-rate lines above — hours here always need a
                                                     multiplier alongside them, never a bare number that could be mistaken for one
                                                     unit or the other. Offered as the three Employment Act minimums (1.5x normal
                                                     day, 2x rest day, 3x public holiday) via the datalist, but not restricted to
                                                     them — the day type isn't known here, and a company may pay above the minimum. --}}
                                                <div><label style="display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;" x-text="$store.ui.lang==='en' ? 'Multiplier (×)' : 'Gandaan (×)'">Multiplier (×)</label><input name="overtime_multiplier" type="number" step="0.1" min="1" list="ot-mult-{{ $p->id }}" value="{{ $p->overtime_overridden && $p->overtime_multiplier !== null ? rtrim(rtrim(number_format($p->overtime_multiplier, 2), '0'), '.') : '' }}" placeholder="1.5" style="width:100%;height:36px;padding:0 10px;border:1px solid var(--hairline);border-radius:7px;font-size:13px;font-family:var(--font-mono);outline:none;" /><datalist id="ot-mult-{{ $p->id }}"><option value="1.5"></option><option value="2.0"></option><option value="3.0"></option></datalist></div>
                                                <div><label style="display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;">Bonus (RM)</label><input name="bonus" type="number" step="0.01" min="0" value="{{ $p->bonus > 0 ? number_format($p->bonus, 2, '.', '') : '' }}" placeholder="0.00" style="width:100%;height:36px;padding:0 10px;border:1px solid var(--hairline);border-radius:7px;font-size:13px;font-family:var(--font-mono);outline:none;" /></div>
                                                <div><label style="display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;" x-text="$store.ui.lang==='en' ? 'Unpaid days override' : 'Tindihan hari tanpa gaji'">Unpaid days override</label><input name="unpaid_days" type="number" step="0.5" min="0" max="31" value="{{ $p->unpaid_days_overridden ? rtrim(rtrim(number_format($p->unpaid_days, 2), '0'), '.') : '' }}" placeholder="{{ $unpaidPulled }}" style="width:100%;height:36px;padding:0 10px;border:1px solid var(--hairline);border-radius:7px;font-size:13px;font-family:var(--font-mono);outline:none;" /></div>
                                                <div><label style="display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;" x-text="$store.ui.lang==='en' ? 'Basic override (RM)' : 'Tindihan gaji pokok (RM)'">Basic override (RM)</label><input name="basic" type="number" step="0.01" min="0" value="{{ old('basic', $p->basic_overridden ? number_format($p->basic, 2, '.', '') : '') }}" placeholder="{{ number_format($p->basic, 2, '.', '') }}" style="width:100%;height:36px;padding:0 10px;border:1px solid var(--hairline);border-radius:7px;font-size:13px;font-family:var(--font-mono);outline:none;" /><div style="font-size:10.5px;color:var(--muted);margin-top:3px;">@if ($p->days_employed !== null && $p->days_in_month !== null && $p->days_employed < $p->days_in_month)<span x-text="$store.ui.lang==='en' ? 'Prorated {{ $p->days_employed }}/{{ $p->days_in_month }} days' : 'Prorata {{ $p->days_employed }}/{{ $p->days_in_month }} hari'">Prorated {{ $p->days_employed }}/{{ $p->days_in_month }} days</span>@endif @if ($p->basic_overridden)<span class="uj-pill" style="background:var(--red-tint);color:var(--info);font-size:10px;" x-text="$store.ui.lang==='en' ? 'overridden' : 'ditindih'">overridden</span>@endif</div></div>
                                                <div><label style="display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;" x-text="$store.ui.lang==='en' ? 'PCB override (RM)' : 'Tindihan PCB (RM)'">PCB override (RM)</label><input name="pcb_override" type="number" step="0.01" min="0" value="{{ $p->pcb_override !== null ? number_format($p->pcb_override, 2, '.', '') : '' }}" placeholder="{{ number_format($p->pcb, 2, '.', '') }}" style="width:100%;height:36px;padding:0 10px;border:1px solid var(--hairline);border-radius:7px;font-size:13px;font-family:var(--font-mono);outline:none;" /></div>
                                            </div>
                                            @include('partials.hint', ['en' => 'Basic pay is split by calendar days for anyone who joined or left mid-month (Employment Act s.18A) — leave the basic override blank to keep that figure. Overtime and unpaid days are pulled automatically from approved OvertimeRequests/unpaid LeaveRequests for this month (shown as the placeholder) — leave the override blank to use the pulled figure. Overtime is entered as hours plus the rate beside it (1.5× if left blank) — the same units the pulled lines show, so the two can never be confused. PCB (income tax) is computed automatically from the LHDN method — leave that override blank too, to use it. Any override sticks until cleared.', 'ms' => 'Gaji pokok dibahagi ikut hari kalendar untuk sesiapa yang masuk atau berhenti pertengahan bulan (Akta Kerja s.18A) — biarkan tindihan gaji pokok kosong untuk kekalkan angka itu. Overtime dan hari tanpa gaji ditarik automatik daripada OvertimeRequest/LeaveRequest tanpa gaji yang diluluskan bagi bulan ini (ditunjukkan sebagai placeholder) — biarkan tindihan kosong untuk guna angka yang ditarik. Overtime dimasukkan sebagai jam campur kadar di sebelahnya (1.5× jika kosong) — unit yang sama seperti baris yang ditarik, jadi kedua-duanya tidak boleh dikelirukan. PCB (cukai pendapatan) dikira automatik mengikut kaedah LHDN — biarkan tindihan itu kosong juga untuk guna nilai itu. Sebarang tindihan kekal sehingga dikosongkan.'])
                                            {{-- Pre-filled from the live individual_transactions table for this employee+period
                                                 (not this payslip's own last-generated lines) — so a one-off added or removed via
                                                 the standalone "Individual transactions" tab is never silently reverted by
                                                 submitting this form for an unrelated reason (e.g. changing the bonus). See
                                                 PayrollController::syncIndividualTransactions. --}}
                                            @php $individualTxLines = ($individualTransactionsForActiveRun->get($p->employee_id) ?? collect())->values(); @endphp
                                            <div style="margin-top:10px;">
                                                <div style="font-size:11.5px;font-weight:600;color:var(--ink);margin-bottom:6px;" x-text="$store.ui.lang==='en' ? 'Individual transactions (one-off)' : 'Transaksi individu (sekali sahaja)'">Individual transactions (one-off)</div>
                                                {{-- tx_known_ids: every row id this form was rendered with — the save only ever
                                                     updates/deletes an id in this list; a row created elsewhere after the page
                                                     loaded is never touched (see PayrollController::syncIndividualTransactions).
                                                     Rendered even with zero rows so the key itself is always present — its
                                                     absence on the request is what tells the controller to skip the sync
                                                     entirely rather than treat "no rows" as "delete everything". --}}
                                                @forelse ($individualTxLines as $knownTx)
                                                    <input type="hidden" name="tx_known_ids[]" value="{{ $knownTx->id }}" />
                                                @empty
                                                    <input type="hidden" name="tx_known_ids[]" value="" />
                                                @endforelse
                                                @for ($i = 0; $i < max(2, $individualTxLines->count()); $i++)
                                                    @php $existingTx = $individualTxLines->get($i); @endphp
                                                    <div style="display:flex;gap:6px;margin-bottom:6px;align-items:center;">
                                                        <input type="hidden" name="tx_id[]" value="{{ $existingTx?->id }}" />
                                                        <select name="tx_item_id[]" style="flex:2;height:34px;padding:0 7px;border:1px solid var(--hairline);border-radius:7px;font-size:12.5px;background:#fff;">
                                                            <option value="" x-text="$store.ui.lang==='en' ? '— none —' : '— tiada —'">— none —</option>
                                                            @foreach ($fixedTransactionItems as $item)
                                                                <option value="{{ $item->id }}" @selected($existingTx?->payroll_item_id === $item->id)>{{ $item->name }} ({{ $item->type }})</option>
                                                            @endforeach
                                                        </select>
                                                        <input name="tx_amount[]" type="number" step="0.01" min="0" value="{{ $existingTx ? number_format($existingTx->amount, 2, '.', '') : '' }}" placeholder="0.00" style="flex:1;height:34px;padding:0 9px;border:1px solid var(--hairline);border-radius:7px;font-size:12.5px;font-family:var(--font-mono);outline:none;" />
                                                        <input name="tx_remark[]" value="{{ $existingTx?->remarks }}" placeholder="Remark" :placeholder="$store.ui.lang==='en' ? 'Remark' : 'Catatan'" style="flex:2;height:34px;padding:0 9px;border:1px solid var(--hairline);border-radius:7px;font-size:12.5px;outline:none;" />
                                                    </div>
                                                @endfor
                                                @include('partials.hint', ['en' => 'Pick a Payroll Item, an amount, and an optional remark — its own EPF/SOCSO/EIS flags drive the statutory bases, same as a Fixed Transaction. All rows here are re-saved together on Recalculate. A one-off added elsewhere (another tab, or the Individual transactions screen) since this page loaded is untouched by this save.', 'ms' => 'Pilih satu Item Payroll, jumlah, dan catatan pilihan — penanda EPF/SOCSO/EIS item itu sendiri menentukan asas berkanun, sama seperti Transaksi Tetap. Semua baris di sini disimpan semula bersama apabila Kira semula. Transaksi individu yang ditambah di tempat lain (tab lain, atau skrin Transaksi individu) sejak halaman ini dimuatkan tidak akan disentuh oleh simpanan ini.'])
                                            </div>
                                            <button type="submit" class="uj-btn-primary" style="height:36px;padding:0 16px;font-size:12.5px;margin-top:8px;" x-text="$store.ui.lang==='en' ? 'Recalculate & save' : 'Kira semula & simpan'">Recalculate & save</button>
                                            <button type="button" @click="editing = null" class="uj-btn-ghost" style="height:36px;padding:0 14px;font-size:12.5px;" x-text="$store.ui.lang==='en' ? 'Cancel' : 'Batal'">Cancel</button>
                                        </form>
                                    </div>
                                @endif
                <div style="padding:0 22px 22px;">@include('partials.payroll.payslip-detail', ['p' => $p, 'ackable' => false])</div>
            </div>
        @endforeach
    </div>
</div>
@endif
