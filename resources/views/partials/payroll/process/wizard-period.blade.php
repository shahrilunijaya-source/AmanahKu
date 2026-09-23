{{-- Wizard step 1, Period. Inside the payrollWizard component and #create-run-form. --}}
@php
    $pulls = [
        ['attendance', 'Pull Attendance Data', 'Tarik Data Kehadiran', false],
        ['fixed', 'Pull Monthly Allowance/Deduction', 'Tarik Elaun/Potongan Bulanan', true],
        ['overtime', 'Pull Monthly Overtime Allowance', 'Tarik Elaun Kerja Lebih Masa Bulanan', true],
        ['claims', 'Pull Claim Data', 'Tarik Data Tuntutan', true],
        ['unpaid', 'Pull Unpaid Data', 'Tarik Data Tanpa Gaji', true],
        ['absent', 'Pull Absent As Unpaid', 'Tarik Tidak Hadir Sebagai Tanpa Gaji', false],
        ['noshift', 'Pull None-Shift As Unpaid', 'Tarik Tanpa Syif Sebagai Tanpa Gaji', false],
        ['overwrite', 'Overwrite Pulled Transactions', 'Tulis Ganti Transaksi Ditarik', false],
    ];
@endphp
<div x-show="step === 1" style="max-width:860px;margin:0 auto;">
    <div style="margin-bottom:18px;">
        <label class="pw-label" for="pw-period" x-text="t('Payroll Period', 'Tempoh Gaji')">Payroll Period</label>
        <input id="pw-period" name="period" type="month" x-model="period" required class="pw-input uj-field" />
        <div class="pw-err" x-show="errs.period" x-text="errs.period && t(...errs.period)"></div>
        @error('period')<div class="pw-err">{{ $message }}</div>@enderror
    </div>

    <div style="margin-bottom:18px;">
        <label class="pw-label" for="pw-kind" x-text="t('Payroll Cycle', 'Kitaran Gaji')">Payroll Cycle</label>
        <select id="pw-kind" name="kind" x-model="kind" class="pw-input uj-field" :style="kind === '' ? { color: 'var(--muted-soft)' } : {}">
            <option value="" disabled x-text="t('Select Payroll Cycle', 'Pilih Kitaran Gaji')">Select Payroll Cycle</option>
            <option value="bonus">Bonus (Bonus)</option>
            <option value="mid_month" x-text="t('Mid Month (MM)', 'Pertengahan Bulan (MM)')">Mid Month (MM)</option>
            <option value="monthly" x-text="t('Month End (ME)', 'Akhir Bulan (ME)')">Month End (ME)</option>
            <option value="final" x-text="t('Final Pay', 'Gaji Akhir')">Final Pay</option>
        </select>
        <div class="pw-err" x-show="errs.kind" x-text="errs.kind && t(...errs.kind)"></div>
        @error('kind')<div class="pw-err">{{ $message }}</div>@enderror
        <div x-show="kind === 'bonus'" style="margin-top:8px;">
            @include('partials.hint', ['en' => 'A bonus run pays only the individual transactions ticked "pay in the bonus run" for that month. It carries no salary, no allowances and no SOCSO/EIS, and its tax is worked out as additional remuneration on top of the monthly pay.', 'ms' => 'Run bonus hanya membayar transaksi individu yang ditanda "bayar dalam run bonus" bagi bulan itu. Tiada gaji, tiada elaun dan tiada PERKESO/SIP, dan cukainya dikira sebagai saraan tambahan di atas gaji bulanan.'])
        </div>
    </div>

    {{-- Mid Month: how the advance is worked out. Disabled (so not posted) for every other cycle. --}}
    <div x-show="kind === 'mid_month'" style="margin-bottom:18px;">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;">
            <div>
                <label class="pw-label" for="pw-mm-basis" x-text="t('Mid Month Salary Calculation Based On', 'Pengiraan Gaji Pertengahan Bulan Berdasarkan')">Mid Month Salary Calculation Based On</label>
                <select id="pw-mm-basis" name="mid_month_basis" x-model="mmBasis" :disabled="kind !== 'mid_month'" @change="mmValue = mmBasis === 'cutoff' ? '15' : '50'" class="pw-input uj-field">
                    <option value="cutoff" x-text="t('Cutoff', 'Tarikh Potong')">Cutoff</option>
                    <option value="percentage" x-text="t('Percentage of Full Salary', 'Peratus Gaji Penuh')">Percentage of Full Salary</option>
                </select>
                @error('mid_month_basis')<div class="pw-err">{{ $message }}</div>@enderror
            </div>
            <div>
                <label class="pw-label" for="pw-mm-value" x-text="mmBasis === 'cutoff' ? t('Cutoff day of the month', 'Hari potong dalam bulan') : t('Percentage of full salary (%)', 'Peratus gaji penuh (%)')">Cutoff day of the month</label>
                <input id="pw-mm-value" name="mid_month_value" type="number" x-model="mmValue" :disabled="kind !== 'mid_month'" :min="1" :max="mmBasis === 'cutoff' ? 28 : 100" class="pw-input uj-field" />
                @error('mid_month_value')<div class="pw-err">{{ $message }}</div>@enderror
            </div>
        </div>
        @include('partials.hint', ['en' => 'Pays basic salary only as an advance: by calendar days up to the cutoff day, or a fixed percentage. No statutory deductions; the Month End run takes the advance back from net pay.', 'ms' => 'Membayar gaji pokok sahaja sebagai pendahuluan: mengikut hari kalendar sehingga hari potong, atau peratus tetap. Tiada potongan berkanun; run Akhir Bulan menolak semula pendahuluan daripada gaji bersih.'])
    </div>

    <div x-show="kind === 'final'" style="margin-bottom:18px;">
        <label class="pw-label" for="pw-leaver" x-text="t('Leaver', 'Pekerja Berhenti')">Leaver</label>
        <select id="pw-leaver" name="employee_id" x-model="employeeId" :disabled="kind !== 'final'" @change="pickLeaver()" class="pw-input uj-field">
            <option value="" x-text="t('Choose an employee', 'Pilih pekerja')">Choose an employee</option>
            <template x-for="l in leavers" :key="l.id">
                <option :value="String(l.id)" :selected="String(l.id) === employeeId" x-text="l.name + (l.last_working_day ? ' · ' + l.last_working_day : '')"></option>
            </template>
        </select>
        <div class="pw-err" x-show="errs.employee_id" x-text="errs.employee_id && t(...errs.employee_id)"></div>
        @error('employee_id')<div class="pw-err">{{ $message }}</div>@enderror
        <div style="margin-top:8px;">
            @include('partials.hint', ['en' => 'Only staff with a last working day recorded and no final pay yet. The last month is paid by calendar days up to that date (EA s.18A) and the pay date defaults to the last working day (EA s.20).', 'ms' => 'Hanya staf yang ada tarikh kerja terakhir dan belum menerima gaji akhir. Bulan terakhir dibayar mengikut hari kalendar sehingga tarikh itu (AK s.18A) dan tarikh bayaran lalai ialah hari kerja terakhir (AK s.20).'])
        </div>
    </div>

    <div style="margin-bottom:18px;">
        <label class="pw-label" for="pw-paydate" x-text="t('Payment Date', 'Tarikh Bayaran')">Payment Date</label>
        <input id="pw-paydate" name="payment_date" type="date" x-model="paymentDate" class="pw-input uj-field" />
        @error('payment_date')<div class="pw-err">{{ $message }}</div>@enderror
    </div>

    {{-- Bonus and Mid Month pull nothing, so the ticks are hidden for them (Worksy does the same). --}}
    <div x-show="kind !== 'bonus' && kind !== 'mid_month'" style="margin-bottom:18px;">
        <div style="display:flex;flex-wrap:wrap;gap:10px 22px;">
            @foreach ($pulls as [$key, $en, $ms, $real])
                <label style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:500;{{ $real ? 'color:var(--ink);cursor:pointer;' : 'color:var(--muted-soft);cursor:not-allowed;' }}" @unless ($real) title="Not available yet / Belum tersedia" @endunless>
                    @if ($real)
                        <input type="hidden" name="pull_{{ $key }}" value="0">
                        <input type="checkbox" name="pull_{{ $key }}" value="1" @checked(old('pull_'.$key) === '1') style="accent-color:var(--red);width:16px;height:16px;">
                    @else
                        <input type="checkbox" disabled style="width:16px;height:16px;">
                    @endif
                    <span x-text="t(@js($en), @js($ms))">{{ $en }}</span>
                </label>
            @endforeach
        </div>
        <div style="margin-top:8px;">
            @include('partials.hint', ['en' => 'Greyed options are not available yet. Anything left unticked stays waiting for the next run.', 'ms' => 'Pilihan kelabu belum tersedia. Apa yang tidak ditanda kekal menunggu run seterusnya.'])
        </div>
    </div>

    {{-- Payroll Policies: Worksy's exclude/include dual list. Only Monthly exists here. --}}
    <div style="margin-bottom:18px;">
        <button type="button" @click="policiesOpen = ! policiesOpen" :aria-expanded="policiesOpen"
                style="width:100%;display:flex;justify-content:space-between;align-items:center;padding:12px 14px;border-radius:8px;border:0;background:var(--canvas);font-size:13px;font-weight:600;color:var(--ink);cursor:pointer;">
            <span x-text="t('Payroll Policies', 'Polisi Gaji')">Payroll Policies</span>
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" :style="{ transform: policiesOpen ? 'rotate(180deg)' : 'none' }"><path d="m6 9 6 6 6-6"/></svg>
        </button>
        <div x-show="policiesOpen" style="padding:14px 4px 0;">
            <div style="display:grid;grid-template-columns:1fr auto 1fr;gap:12px;align-items:stretch;">
                @foreach (['out' => ['Exclude Payroll Policies', 'Kecualikan Polisi Gaji'], 'in' => ['Include Payroll Policies', 'Masukkan Polisi Gaji']] as $side => [$en, $ms])
                    <div @if ($side === 'in') style="order:3;" @endif>
                        <div style="font-size:12.5px;font-weight:500;color:var(--ink);margin-bottom:6px;"><span x-text="t(@js($en), @js($ms))">{{ $en }}</span> (<span x-text="policyList('{{ $side }}').length"></span>)</div>
                        <input type="search" x-model="policyQ.{{ $side }}" :placeholder="t('Search...', 'Cari...')" class="pw-input uj-field" style="height:34px;font-size:13px;margin-bottom:6px;" />
                        <div class="pw-box">
                            <template x-for="p in policyList('{{ $side }}')" :key="p.key">
                                <label class="pw-opt" :class="p.off ? 'pw-opt-off' : ''" :title="p.off ? 'Not available yet / Belum tersedia' : ''">
                                    <input type="checkbox" :value="p.key" x-model="policyPick.{{ $side }}" :disabled="p.off" style="accent-color:var(--red);">
                                    <span x-text="t(p.en, p.ms)"></span>
                                    <span class="pw-soon" x-show="p.off" x-text="t('Not available yet', 'Belum tersedia')"></span>
                                </label>
                            </template>
                        </div>
                    </div>
                @endforeach
                <div style="order:2;display:flex;flex-direction:column;justify-content:center;gap:8px;">
                    <button type="button" class="pw-arrow" @click="movePolicies('in', true)" :aria-label="t('Include all', 'Masukkan semua')">&raquo;</button>
                    <button type="button" class="pw-arrow" @click="movePolicies('in', false)" :aria-label="t('Include selected', 'Masukkan dipilih')">&rsaquo;</button>
                    <button type="button" class="pw-arrow" @click="movePolicies('out', false)" :aria-label="t('Exclude selected', 'Kecualikan dipilih')" style="margin-top:18px;">&lsaquo;</button>
                    <button type="button" class="pw-arrow" @click="movePolicies('out', true)" :aria-label="t('Exclude all', 'Kecualikan semua')">&laquo;</button>
                </div>
            </div>
        </div>
        <div class="pw-err" x-show="errs.policies" x-text="errs.policies && t(...errs.policies)"></div>
    </div>

    <div style="margin-bottom:18px;">
        <label class="pw-label" for="pw-remarks" x-text="t('Remarks', 'Catatan')">Remarks</label>
        <textarea id="pw-remarks" name="remarks" x-model="remarks" maxlength="1000" rows="4" class="uj-field" style="width:100%;padding:10px 12px;border:1px solid var(--hairline);border-radius:8px;font-size:14px;outline:none;resize:vertical;"></textarea>
        @error('remarks')<div class="pw-err">{{ $message }}</div>@enderror
    </div>

    <div style="text-align:right;">
        <button type="button" class="uj-btn-primary" style="height:38px;padding:0 28px;font-size:13.5px;" @click="nextFromPeriod()" x-text="t('Next', 'Seterusnya')">Next</button>
    </div>
</div>
