{{-- Wizard step 3, Selected Employees. Posts include_employee_ids[] (switched-on rows plus
     Additional Selections) and carries the readiness gate that used to be its own panel.
     A Final Pay run is that one leaver, so it posts employee_id and no include list. --}}
@php
    $gapPills = <<<'HTML'
        <template x-for="g in p.blocking" :key="'b' + g"><span class="uj-pill" style="background:var(--red-tint);color:var(--error);margin:2px 4px 0 0;display:inline-block;" x-text="g"></span></template>
        <template x-for="g in p.warnings" :key="'w' + g"><span class="uj-pill" style="background:#fff7e6;color:var(--amber);margin:2px 4px 0 0;display:inline-block;" x-text="g + ' · ' + t('warning', 'amaran')"></span></template>
    HTML;
@endphp
<div x-show="step === 3">
    <template x-if="kind !== 'final'">
        <div><template x-for="id in includedIds()" :key="id"><input type="hidden" name="include_employee_ids[]" :value="id"></template></div>
    </template>

    <div style="border:1px solid var(--hairline);border-radius:12px;padding:18px 20px;margin-bottom:16px;display:grid;grid-template-columns:auto 1fr auto;gap:14px;align-items:center;">
        <button type="button" class="pw-pillbtn" style="padding:0 26px;" @click="step = kind === 'final' ? 1 : 2" x-text="t('Back', 'Kembali')">Back</button>
        <div style="text-align:center;">
            <div style="font-size:14px;font-weight:600;color:var(--ink);margin-bottom:12px;" x-text="t('Your Selection Summary', 'Ringkasan Pilihan Anda')">Your Selection Summary</div>
            <div class="pw-row" style="justify-content:center;">
                <div class="pw-summary"><div style="font-size:15px;font-weight:600;color:var(--red);" x-text="t(...methodLabel())"></div><div style="font-size:12px;color:var(--muted);" x-text="t('Method', 'Kaedah')">Method</div></div>
                <div class="pw-summary"><div style="font-size:15px;font-weight:600;color:var(--red);font-family:var(--font-mono);" x-text="selection.length"></div><div style="font-size:12px;color:var(--muted);" x-text="t('Your Selection', 'Pilihan Anda')">Your Selection</div></div>
                <div class="pw-summary"><div style="font-size:15px;font-weight:600;color:var(--red);font-family:var(--font-mono);" x-text="additional.length"></div><div style="font-size:12px;color:var(--muted);" x-text="t('Additional Selection', 'Pilihan Tambahan')">Additional Selection</div></div>
            </div>
        </div>
        <button type="submit" class="uj-btn-primary" style="height:34px;padding:0 22px;font-size:13px;border-radius:9999px;" :disabled="! canProcess()" :style="canProcess() ? {} : { opacity: .5, cursor: 'not-allowed' }"
                :title="canProcess() ? '' : t('Switch someone on, and fix or switch off everyone with a red gap below.', 'Hidupkan sekurang-kurangnya seorang, dan betulkan atau matikan setiap orang yang ada jurang merah di bawah.')"
                x-text="t('Process Payroll', 'Proses Gaji')">Process Payroll</button>
    </div>

    {{-- Readiness (spec F2): what still blocks a run. The server gate still decides. --}}
    @error('readiness')<div role="alert" style="margin-bottom:12px;background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12.5px;border-radius:8px;padding:9px 12px;">{{ $message }}</div>@enderror
    @if ($readinessEmployer)
        <div style="margin-bottom:12px;background:var(--red-tint);border:1px solid var(--red);font-size:12.5px;border-radius:8px;padding:9px 12px;"><b x-text="t('Company', 'Syarikat')">Company</b>: <span style="color:var(--error);">{{ implode(', ', $readinessEmployer) }}</span> · <a href="{{ route('app.screen', ['screen' => 'settings']) }}" style="color:var(--red);" x-text="t('Fix in Settings', 'Betulkan di Tetapan')">Fix in Settings</a></div>
    @endif
    @foreach ($readinessCompanyWarnings as $w)
        @php preg_match('/\d+/', $w, $m); $n = $m[0] ?? '0'; @endphp
        <div style="margin-bottom:12px;background:#fff7e6;border:1px solid var(--amber);color:var(--amber);font-size:12.5px;border-radius:8px;padding:9px 12px;"
             x-text="t(@js($w), @js('Levi HRD Corp dimatikan tetapi syarikat mempunyai '.$n.' pekerja warganegara Malaysia; pendaftaran adalah wajib pada 10.'))">{{ $w }}</div>
    @endforeach
    <div x-show="(kind === 'monthly' || kind === 'mid_month') && outside.length" style="margin-bottom:12px;background:#fff7e6;border:1px solid var(--amber);font-size:12.5px;border-radius:8px;padding:9px 12px;">
        <b style="color:var(--amber);" x-text="t('Currently employed but not in this list, so not paid:', 'Masih bekerja tetapi tiada dalam senarai ini, jadi tidak dibayar:')"></b>
        <template x-for="o in outside" :key="o.name"><span style="margin-left:6px;"><span x-text="o.name"></span> (<span style="color:var(--error);" x-text="o.blocking.join(', ')"></span>)</span></template>
    </div>
    <div x-show="blockers().length" style="margin-bottom:12px;background:var(--red-tint);border:1px solid var(--red);color:var(--error);font-size:12.5px;border-radius:8px;padding:9px 12px;"
         x-text="blockers().length + ' ' + t('switched-on employees have a red gap. Fix their profile, or switch them off to leave them out of this run.', 'pekerja yang dihidupkan ada jurang merah. Betulkan profil mereka, atau matikan untuk mengecualikan daripada run ini.')"></div>

    {{-- Your Selections --}}
    <div style="border:1px solid var(--hairline);border-radius:12px;padding:18px 20px;margin-bottom:16px;">
        <div style="font-size:16px;font-weight:600;color:var(--ink);margin-bottom:12px;"><span x-text="t('Your Selections', 'Pilihan Anda')">Your Selections</span> (<span x-text="selection.length"></span>)</div>
        <div style="overflow-x:auto;">
            <table class="pw-table">
                <thead><tr>
                    <th x-text="t('Employee', 'Pekerja')">Employee</th>
                    <th x-text="t('Employee Number', 'No. Pekerja')">Employee Number</th>
                    <th x-text="t('Company', 'Syarikat')">Company</th>
                    <th x-text="t('Department', 'Jabatan')">Department</th>
                    <th style="text-align:right;">
                        <label style="display:inline-flex;align-items:center;gap:8px;text-transform:none;letter-spacing:0;font-size:12px;">
                            <span x-text="t('Exclude/Include', 'Kecuali/Masuk')">Exclude/Include</span>
                            <span class="pw-switch"><input type="checkbox" :checked="allOn()" @change="toggleAll($event.target.checked)" :disabled="! selection.length"><span></span></span>
                        </label>
                    </th>
                </tr></thead>
                <tbody>
                    <tr><td colspan="5" style="border-top:0;padding:4px 0 8px;"><input type="search" x-model="q" @input="page = 1" :placeholder="t('Search...', 'Cari...')" class="pw-input uj-field" style="height:38px;font-size:13px;" /></td></tr>
                    <template x-for="p in pageRows()" :key="p.id">
                        <tr :style="on[p.id] ? {} : { opacity: .55 }">
                            <td>
                                <div style="display:flex;gap:10px;align-items:center;">
                                    <template x-if="p.photo"><img :src="p.photo" alt="" width="34" height="34" style="width:34px;height:34px;border-radius:50%;object-fit:cover;flex-shrink:0;"></template><template x-if="! p.photo"><span class="pw-av" x-text="initials(p.name)"></span></template>
                                    <div>
                                        <div style="font-weight:500;color:var(--ink);" x-text="p.name"></div>
                                        <div style="font-size:11.5px;color:var(--muted);" x-text="p.position || ''"></div>
                                        <div>{!! $gapPills !!}</div>
                                    </div>
                                </div>
                            </td>
                            <td style="font-family:var(--font-mono);" x-text="p.staff_id || ''"></td>
                            <td x-text="company"></td>
                            <td x-text="p.department || ''"></td>
                            <td style="text-align:right;"><label class="pw-switch" :aria-label="t('Include', 'Masukkan') + ' ' + p.name"><input type="checkbox" x-model="on[p.id]"><span></span></label></td>
                        </tr>
                    </template>
                    <tr x-show="! rows().length"><td colspan="5" style="color:var(--muted);text-align:center;padding:18px;" x-text="t('No records show.', 'Tiada rekod.')"></td></tr>
                </tbody>
            </table>
        </div>
        <div class="pw-pager" style="display:flex;justify-content:flex-end;align-items:center;gap:6px;margin-top:10px;font-size:12.5px;color:var(--muted);">
            <span style="margin-right:6px;" x-text="Math.min(page * 50, rows().length) + ' ' + t('of', 'daripada') + ' ' + rows().length"></span>
            <button type="button" @click="page = 1" :disabled="page === 1" :aria-label="t('First page', 'Halaman pertama')">&laquo;</button>
            <button type="button" @click="page--" :disabled="page === 1" :aria-label="t('Previous page', 'Halaman sebelum')">&lsaquo;</button>
            <span x-text="t('Page', 'Halaman') + ' ' + page + ' ' + t('of', 'daripada') + ' ' + pages()"></span>
            <button type="button" @click="page++" :disabled="page >= pages()" :aria-label="t('Next page', 'Halaman seterusnya')">&rsaquo;</button>
            <button type="button" @click="page = pages()" :disabled="page >= pages()" :aria-label="t('Last page', 'Halaman terakhir')">&raquo;</button>
        </div>
    </div>

    {{-- Additional Selections: add anyone eligible for this cycle who is not selected yet. --}}
    <div x-show="kind !== 'final'" style="border:1px solid var(--hairline);border-radius:12px;padding:18px 20px;">
        <div style="font-size:16px;font-weight:600;color:var(--ink);margin-bottom:12px;"><span x-text="t('Additional Selections', 'Pilihan Tambahan')">Additional Selections</span> (<span x-text="additional.length"></span>)</div>
        <div style="display:flex;gap:10px;">
            <select x-model="addPick" class="pw-input uj-field" style="flex:1;" :aria-label="t('Select Employee', 'Pilih Pekerja')">
                <option value="" x-text="t('Select Employee', 'Pilih Pekerja')">Select Employee</option>
                <template x-for="p in addable()" :key="p.id"><option :value="p.id" x-text="p.name + (p.staff_id ? ' · ' + p.staff_id : '')"></option></template>
            </select>
            <button type="button" class="uj-btn-primary" style="height:42px;padding:0 18px;font-size:13px;white-space:nowrap;" :disabled="! addPick" @click="addEmployee()" x-text="t('Add Employee', 'Tambah Pekerja')">Add Employee</button>
        </div>
        <table class="pw-table" x-show="additional.length" style="margin-top:10px;">
            <tbody>
                <template x-for="p in additional.map(id => person(id))" :key="p.id">
                    <tr>
                        <td>
                            <div style="display:flex;gap:10px;align-items:center;">
                                <template x-if="p.photo"><img :src="p.photo" alt="" width="34" height="34" style="width:34px;height:34px;border-radius:50%;object-fit:cover;flex-shrink:0;"></template><template x-if="! p.photo"><span class="pw-av" x-text="initials(p.name)"></span></template>
                                <div>
                                    <div style="font-weight:500;color:var(--ink);" x-text="p.name"></div>
                                    <div style="font-size:11.5px;color:var(--muted);" x-text="p.position || ''"></div>
                                    <div>{!! $gapPills !!}</div>
                                </div>
                            </div>
                        </td>
                        <td style="font-family:var(--font-mono);" x-text="p.staff_id || ''"></td>
                        <td x-text="company"></td>
                        <td x-text="p.department || ''"></td>
                        <td style="text-align:right;"><button type="button" class="pw-pillbtn" @click="additional = additional.filter(id => id !== p.id)" x-text="t('Remove', 'Buang')">Remove</button></td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>
</div>
