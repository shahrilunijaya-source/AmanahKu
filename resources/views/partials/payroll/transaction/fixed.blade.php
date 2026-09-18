@php
    $money = fn ($v) => 'RM '.number_format((float) $v, 2);
@endphp
{{-- Transaction, Fixed Transaction tab: pick a staff member on the left, manage their recurring lines on the right. --}}
<div x-data="{
        q: '',
        pick: {{ (int) request('emp', $salaryEmployees->first()?->id ?? 0) }},
        rows: @js($salaryEmployees->map(fn ($e) => mb_strtolower(trim($e->display_name.' '.$e->name.' '.$e->position.' '.$e->staff_id)))->values()),
        hit(h) { return this.q.trim() === '' || h.includes(this.q.trim().toLowerCase()); },
     }" style="display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap;">
    <div class="uj-card" style="flex:1;min-width:240px;max-width:300px;padding:0;">
        <div style="padding:12px;border-bottom:1px solid var(--hairline);">
            <input type="search" x-model="q" @keydown.escape="q = ''" :placeholder="$store.ui.lang==='en' ? 'Search name or ID' : 'Cari nama atau ID'" style="width:100%;height:32px;padding:0 12px;border:1px solid var(--hairline);border-radius:8px;font-size:12.5px;outline:none;background:var(--surface,#fff);color:var(--ink);">
        </div>
        <div style="max-height:560px;overflow:auto;">
            @foreach ($salaryEmployees as $e)
                <div x-show="hit(rows[{{ $loop->index }}])"><button type="button" @click="pick = {{ $e->id }}" :style="{ background: pick === {{ $e->id }} ? 'var(--canvas)' : 'none' }" style="display:flex;width:100%;text-align:left;align-items:center;gap:10px;padding:10px 14px;border:0;border-bottom:1px solid var(--hairline-soft);background:none;cursor:pointer;">
                    <div style="width:28px;height:28px;border-radius:50%;background:{{ $e->avatar_color ?? '#3a6ea5' }};color:#fff;display:flex;align-items:center;justify-content:center;font-size:10.5px;font-weight:600;flex-shrink:0;">{{ $e->initials }}</div>
                    <div style="min-width:0;"><div style="font-size:12.5px;color:var(--ink);font-weight:500;">{{ $e->name }}</div><div style="font-size:11px;color:var(--muted);">{{ $e->position }}</div></div>
                    @php $ftCount = $fixedTransactions->get($e->id, collect())->count(); @endphp
                    @if ($ftCount > 0)<span style="margin-left:auto;font-size:10.5px;color:var(--muted);">{{ $ftCount }}</span>@endif
                </button></div>
            @endforeach
        </div>
    </div>

    <div style="flex:2;min-width:min(380px,100%);">
        @foreach ($salaryEmployees as $e)
            @php $s = $e->salaryStructure; $empFt = $fixedTransactions->get($e->id, collect()); @endphp
            <div x-show="pick === {{ $e->id }}" x-cloak class="uj-card" style="padding:20px;">
                <h3 class="uj-card-title" style="margin-bottom:4px;">{{ $e->name }}</h3>
                <div style="font-size:12px;color:var(--muted);">{{ $e->position }} · <span x-text="$store.ui.lang==='en' ? 'Basic' : 'Asas'">Basic</span> {{ $money((float) ($e->salary ?? 0)) }}</div>
                <div x-data="{ ftAdding: false, ftEditing: null, ftEnding: null }" style="margin-top:14px;">
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;">
                        <div style="font-size:11.5px;font-weight:600;color:var(--ink);" x-text="$store.ui.lang==='en' ? 'Fixed transactions' : 'Transaksi tetap'">Fixed transactions</div>
                        <button type="button" @click="ftAdding = !ftAdding" class="uj-btn-ghost" style="height:28px;padding:0 10px;font-size:11.5px;" x-text="$store.ui.lang==='en' ? (ftAdding ? 'Cancel' : '+ Add') : (ftAdding ? 'Batal' : '+ Tambah')">+ Add</button>
                    </div>
                    <p style="font-size:11px;color:var(--muted);margin:0 0 8px;" x-text="$store.ui.lang==='en' ? 'A recurring allowance or deduction that appears on every payslip between its start and end months.' : 'Elaun atau potongan berulang yang muncul pada setiap payslip antara bulan mula dan tamatnya.'">A recurring allowance or deduction that appears on every payslip between its start and end months.</p>

                    @forelse ($empFt as $ft)
                        <div style="border:1px solid var(--hairline);border-radius:8px;padding:8px 10px;margin-bottom:6px;">
                            <div style="display:flex;align-items:center;gap:10px;">
                                <div style="flex:1;min-width:0;">
                                    <div style="font-size:12.5px;color:var(--ink);font-weight:500;">{{ $ft->payrollItem?->name }} <span style="font-weight:400;color:var(--muted);">({{ $ft->payrollItem?->type }})</span></div>
                                    <div style="font-size:10.5px;color:var(--muted);">
                                        {{ $ft->start_period }} →
                                        @if($ft->end_period)
                                            {{ $ft->end_period }}
                                        @else
                                            <span x-text="$store.ui.lang==='en' ? 'open-ended' : 'tiada had'">open-ended</span>
                                        @endif
                                        @if($ft->prorate)
                                            · <span x-text="$store.ui.lang==='en' ? 'prorated' : 'prorata'">prorated</span>
                                        @endif
                                        @if($ft->remarks)
                                            · {{ $ft->remarks }}
                                        @endif
                                        @if($ft->consent_reference)
                                            · <span x-text="$store.ui.lang==='en' ? 'consent' : 'kebenaran'">consent</span> {{ $ft->consent_reference }}
                                        @endif
                                    </div>
                                </div>
                                <div style="font-family:var(--font-mono);font-size:12.5px;color:var(--ink);">{{ $money($ft->amount) }}</div>
                                <button type="button" @click="ftEditing = ftEditing === {{ $ft->id }} ? null : {{ $ft->id }}" class="uj-btn-ghost" style="height:26px;padding:0 8px;font-size:11px;" x-text="$store.ui.lang==='en' ? 'Edit' : 'Sunting'">Edit</button>
                                <button type="button" @click="ftEnding = ftEnding === {{ $ft->id }} ? null : {{ $ft->id }}" class="uj-btn-ghost" style="height:26px;padding:0 8px;font-size:11px;" x-text="$store.ui.lang==='en' ? 'End' : 'Tamat'">End</button>
                            </div>
                            <div x-show="ftEditing === {{ $ft->id }}" x-cloak style="margin-top:8px;padding-top:8px;border-top:1px solid var(--hairline-soft);">
                                <form method="post" action="{{ route('payroll.fixed-transactions.update', $ft) }}">
                                    @csrf
                                    <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:flex-end;">
                                        <div><label style="display:block;font-size:10px;color:var(--muted);">RM</label><input name="amount" type="number" step="0.01" min="0.01" required value="{{ number_format($ft->amount, 2, '.', '') }}" style="width:100px;height:30px;padding:0 7px;border:1px solid var(--hairline);border-radius:6px;font-size:12px;font-family:var(--font-mono);" /></div>
                                        <div><label style="display:block;font-size:10px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'Start' : 'Mula'">Start</label><input name="start_period" type="month" required value="{{ $ft->start_period }}" style="width:110px;height:30px;padding:0 7px;border:1px solid var(--hairline);border-radius:6px;font-size:12px;" /></div>
                                        <div><label style="display:block;font-size:10px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'End (blank = open)' : 'Tamat (kosong = tiada had)'">End (blank = open)</label><input name="end_period" type="month" value="{{ $ft->end_period }}" style="width:110px;height:30px;padding:0 7px;border:1px solid var(--hairline);border-radius:6px;font-size:12px;" /></div>
                                        <div><label style="display:block;font-size:10px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'Last month RM (optional)' : 'RM bulan akhir (pilihan)'">Last month RM (optional)</label><input name="last_amount" type="number" step="0.01" min="0" value="{{ $ft->last_amount !== null ? number_format($ft->last_amount, 2, '.', '') : '' }}" placeholder="—" style="width:100px;height:30px;padding:0 7px;border:1px solid var(--hairline);border-radius:6px;font-size:12px;font-family:var(--font-mono);" /></div>
                                        <label style="display:flex;align-items:center;gap:5px;font-size:11.5px;color:var(--ink);height:30px;"><input type="checkbox" name="prorate" value="1" @checked($ft->prorate) /> <span x-text="$store.ui.lang==='en' ? 'Prorate part-months' : 'Prorata bulan separuh'">Prorate part-months</span></label>
                                        <input name="remarks" value="{{ $ft->remarks }}" placeholder="Remarks" :placeholder="$store.ui.lang==='en' ? 'Remarks' : 'Catatan'" style="flex:1;min-width:120px;height:30px;padding:0 7px;border:1px solid var(--hairline);border-radius:6px;font-size:12px;" />
                                        <input name="consent_reference" value="{{ $ft->consent_reference }}" maxlength="160" placeholder="Written consent reference (deductions)" :placeholder="$store.ui.lang==='en' ? 'Written consent reference (deductions)' : 'Rujukan kebenaran bertulis (potongan)'" style="flex:1;min-width:120px;height:30px;padding:0 7px;border:1px solid var(--hairline);border-radius:6px;font-size:12px;" />
                                        <button type="submit" class="uj-btn-primary" style="height:30px;padding:0 12px;font-size:11.5px;" x-text="$store.ui.lang==='en' ? 'Save' : 'Simpan'">Save</button>
                                    </div>
                                </form>
                            </div>
                            <div x-show="ftEnding === {{ $ft->id }}" x-cloak style="margin-top:8px;padding-top:8px;border-top:1px solid var(--hairline-soft);">
                                <form method="post" action="{{ route('payroll.fixed-transactions.end', $ft) }}" style="display:flex;gap:8px;align-items:flex-end;">
                                    @csrf
                                    <div><label style="display:block;font-size:10px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'Last period it still applies' : 'Tempoh terakhir ia masih terpakai'">Last period it still applies</label><input name="end_period" type="month" required value="{{ $currentPeriod }}" style="width:130px;height:30px;padding:0 7px;border:1px solid var(--hairline);border-radius:6px;font-size:12px;" /></div>
                                    <button type="submit" class="uj-btn-primary" style="height:30px;padding:0 12px;font-size:11.5px;background:var(--error);border-color:var(--error);" x-text="$store.ui.lang==='en' ? 'Confirm end' : 'Sahkan tamat'">Confirm end</button>
                                </form>
                                @include('partials.hint', ['en' => 'This never deletes the row — it sets the last period it still applies, so past payslips stay explainable.', 'ms' => 'Ini tidak memadam rekod — ia menetapkan tempoh terakhir ia masih terpakai, supaya payslip lepas kekal boleh dijelaskan.'])
                            </div>
                        </div>
                    @empty
                        <div style="font-size:11.5px;color:var(--muted);padding:6px 0;" x-text="$store.ui.lang==='en' ? 'No fixed transactions.' : 'Tiada transaksi tetap.'">No fixed transactions.</div>
                    @endforelse

                    <div x-show="ftAdding" x-cloak style="border:1px dashed var(--hairline);border-radius:8px;padding:10px;margin-top:6px;">
                        <form method="post" action="{{ route('payroll.fixed-transactions.store') }}">
                            @csrf
                            <input type="hidden" name="employee_id" value="{{ $e->id }}" />
                            <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:flex-end;">
                                <div style="flex:1;min-width:160px;"><label style="display:block;font-size:10px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'Payroll item' : 'Item payroll'">Payroll item</label>
                                    <select name="payroll_item_id" required style="width:100%;height:30px;padding:0 7px;border:1px solid var(--hairline);border-radius:6px;font-size:12px;">
                                        @foreach ($fixedTransactionItems as $item)
                                            <option value="{{ $item->id }}">{{ $item->name }} ({{ $item->type }})</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div><label style="display:block;font-size:10px;color:var(--muted);">RM</label><input name="amount" type="number" step="0.01" min="0.01" required placeholder="0.00" style="width:100px;height:30px;padding:0 7px;border:1px solid var(--hairline);border-radius:6px;font-size:12px;font-family:var(--font-mono);" /></div>
                                <div><label style="display:block;font-size:10px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'Start' : 'Mula'">Start</label><input name="start_period" type="month" required value="{{ $currentPeriod }}" style="width:110px;height:30px;padding:0 7px;border:1px solid var(--hairline);border-radius:6px;font-size:12px;" /></div>
                                <div><label style="display:block;font-size:10px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'End (blank = open)' : 'Tamat (kosong = tiada had)'">End (blank = open)</label><input name="end_period" type="month" style="width:110px;height:30px;padding:0 7px;border:1px solid var(--hairline);border-radius:6px;font-size:12px;" /></div>
                                <div><label style="display:block;font-size:10px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'Last month RM (optional)' : 'RM bulan akhir (pilihan)'">Last month RM (optional)</label><input name="last_amount" type="number" step="0.01" min="0" placeholder="—" style="width:100px;height:30px;padding:0 7px;border:1px solid var(--hairline);border-radius:6px;font-size:12px;font-family:var(--font-mono);" /></div>
                                <label style="display:flex;align-items:center;gap:5px;font-size:11.5px;color:var(--ink);height:30px;"><input type="checkbox" name="prorate" value="1" /> <span x-text="$store.ui.lang==='en' ? 'Prorate part-months' : 'Prorata bulan separuh'">Prorate part-months</span></label>
                                <input name="remarks" placeholder="Remarks" :placeholder="$store.ui.lang==='en' ? 'Remarks' : 'Catatan'" style="flex:1;min-width:120px;height:30px;padding:0 7px;border:1px solid var(--hairline);border-radius:6px;font-size:12px;" />
                                <input name="consent_reference" maxlength="160" placeholder="Written consent reference (deductions)" :placeholder="$store.ui.lang==='en' ? 'Written consent reference (deductions)' : 'Rujukan kebenaran bertulis (potongan)'" style="flex:1;min-width:120px;height:30px;padding:0 7px;border:1px solid var(--hairline);border-radius:6px;font-size:12px;" />
                                <button type="submit" class="uj-btn-primary" style="height:30px;padding:0 12px;font-size:11.5px;" x-text="$store.ui.lang==='en' ? 'Add' : 'Tambah'">Add</button>
                            </div>
                            @include('partials.hint', [
                                'en' => 'A joiner or leaver is paid for the days they were employed, counted on the real days in that month. (Unpaid leave and overtime use the 26-day rule instead — that difference is deliberate.)',
                                'ms' => 'Pekerja baru atau yang keluar dibayar mengikut hari mereka bekerja, dikira atas hari sebenar dalam bulan itu. (Cuti tanpa gaji dan kerja lebih masa guna peraturan 26 hari — perbezaan itu memang disengajakan.)',
                            ])
                            @include('partials.hint', [
                                'en' => 'EA s.24: a non-statutory deduction needs the employee\'s written consent. Put the date signed or the agreement reference.',
                                'ms' => 'AK s.24: potongan bukan statutori perlu kebenaran bertulis pekerja. Isikan tarikh ditandatangani atau rujukan perjanjian.',
                            ])
                        </form>
                    </div>
                </div>
            </div>
        @endforeach
        @if ($salaryEmployees->isEmpty())
            <div class="uj-card" style="padding:22px;color:var(--muted);font-size:13px;" x-text="$store.ui.lang==='en' ? 'No active staff yet.' : 'Belum ada staf aktif.'">No active staff yet.</div>
        @endif
    </div>
</div>
