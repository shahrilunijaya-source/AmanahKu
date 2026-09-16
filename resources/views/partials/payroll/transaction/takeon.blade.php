@php
    $money = fn ($v) => 'RM '.number_format((float) $v, 2);
@endphp
<div
     x-data="{
         openFor: null,
         q: '',
         rows: @js($openingEmployees->map(fn ($e) => mb_strtolower(trim($e->display_name.' '.$e->name.' '.$e->position)))->values()),
         hit(h) { return this.q.trim() === '' || h.includes(this.q.trim().toLowerCase()); },
         get shown() { return this.rows.filter(h => this.hit(h)).length; },
     }">
    <div class="uj-card" style="max-width:820px;">
        <div class="uj-card-head" style="padding:16px 22px;display:flex;align-items:center;flex-wrap:wrap;gap:10px;">
            <div>
                    <h3 class="uj-card-title" x-text="$store.ui.lang==='en' ? 'Payroll Figures Take On' : 'Angka Pembukaan Gaji'">Payroll Figures Take On</h3>
                    <p style="font-size:12px;color:var(--muted);margin:2px 0 0;" x-text="$store.ui.lang==='en' ? 'Opening figures from a previous employer this year (TP3), so PCB for the rest of the year is right.' : 'Angka pembukaan daripada majikan terdahulu tahun ini (TP3), supaya PCB untuk baki tahun betul.'">Opening figures from a previous employer this year (TP3), so PCB for the rest of the year is right.</p>
                </div>
            <span style="font-size:12px;color:var(--muted);">{{ $openingYear }}</span>
            <div style="margin-left:auto;position:relative;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--muted)" stroke-width="2" stroke-linecap="round" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);pointer-events:none;"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
                <input type="search" x-model="q" @keydown.escape="q = ''"
                       :placeholder="$store.ui.lang==='en' ? 'Search name or nickname' : 'Cari nama atau gelaran'"
                       style="width:230px;height:32px;padding:0 12px 0 30px;border:1px solid var(--hairline);border-radius:8px;font-size:12.5px;outline:none;background:var(--surface,#fff);color:var(--ink);" />
            </div>
        </div>
        <div style="padding:14px 22px;border-bottom:1px solid var(--hairline-soft);">
            @include('partials.hint', [
                'tone' => 'warn',
                'en' => 'Gross, PCB, EPF, zakat and the optional-deductions figures come from the employee\'s Form TP3 (or the payroll system the company used earlier this year) and must be entered before that person\'s first payroll run — getting them wrong makes both the monthly tax and the year-end EA form wrong. SOCSO and EIS are not part of Form TP3 itself; they come from the previous payroll system\'s own take-on screen and are kept here for the EA form and your own reconciliation. Leave everything at 0 for anyone who has been paid through AmanahKu since January.',
                'ms' => 'Angka kasar, PCB, EPF, zakat dan potongan pilihan datang daripada Borang TP3 pekerja (atau sistem payroll yang syarikat guna lebih awal tahun ini) dan mesti dimasukkan sebelum payroll run pertama pekerja itu — jika salah, cukai bulanan dan Borang EA akhir tahun turut salah. SOCSO dan EIS bukan sebahagian daripada Borang TP3 itu sendiri; ia datang daripada skrin take-on sistem payroll sebelumnya dan disimpan di sini untuk Borang EA dan rekonsiliasi anda sendiri. Biarkan semua pada 0 bagi sesiapa yang telah dibayar melalui AmanahKu sejak Januari.',
            ])
        </div>
        @foreach ($openingEmployees as $e)
            @php $o = $openingFigures->get($e->id); @endphp
            <div x-show="hit(rows[{{ $loop->index }}])" style="border-bottom:1px solid var(--hairline-soft);">
                <div style="display:flex;align-items:center;gap:12px;padding:12px 22px;">
                    <div style="width:30px;height:30px;border-radius:50%;background:{{ $e->avatar_color ?? '#3a6ea5' }};color:#fff;display:flex;align-items:center;justify-content:center;font-size:10.5px;font-weight:600;flex-shrink:0;">{{ $e->initials }}</div>
                    <div style="flex:1;min-width:0;"><div style="font-size:13px;color:var(--ink);font-weight:500;">{{ $e->name }}</div><div style="font-size:11px;color:var(--muted);">{{ $e->position }}</div></div>
                    <div style="text-align:right;">
                        @if ($o)<div style="font-size:12.5px;color:var(--ink);">{{ $money($o->gross) }} <span style="color:var(--muted);" x-text="$store.ui.lang==='en' ? 'gross' : 'kasar'">gross</span></div>
                        @else<span class="uj-pill" style="background:var(--canvas);color:var(--muted);" x-text="$store.ui.lang==='en' ? 'None (0)' : 'Tiada (0)'">None (0)</span>@endif
                    </div>
                    <a href="{{ route('app.screen', 'profile') }}?emp={{ $e->id }}&tab=experience" class="uj-btn-ghost" style="height:32px;padding:0 12px;font-size:12px;text-decoration:none;display:inline-flex;align-items:center;" x-text="$store.ui.lang==='en' ? 'Edit on profile' : 'Sunting di profil'">Edit on profile</a>
                    <button @click="openFor === {{ $e->id }} ? openFor = null : openFor = {{ $e->id }}" class="uj-btn-ghost" style="height:32px;padding:0 12px;font-size:12px;" x-text="$store.ui.lang==='en' ? 'Edit' : 'Sunting'">Edit</button>
                </div>
                <div x-show="openFor === {{ $e->id }}" x-cloak style="padding:4px 22px 18px 64px;">
                    <form method="post" action="{{ route('payroll.opening') }}" style="background:var(--canvas);border:1px solid var(--hairline);border-radius:10px;padding:16px;">
                        @csrf
                        <input type="hidden" name="employee_id" value="{{ $e->id }}" />
                        <input type="hidden" name="year" value="{{ $openingYear }}" />
                        <div style="font-size:11.5px;font-weight:600;color:var(--ink);margin-bottom:6px;" x-text="$store.ui.lang==='en' ? 'Pay & statutory (feeds PCB)' : 'Gaji & berkanun (mempengaruhi PCB)'">Pay &amp; statutory (feeds PCB)</div>
                        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;max-width:520px;margin-bottom:12px;">
                            @foreach ([
                                ['gross', 'Gross paid (RM)', 'Kasar dibayar (RM)', $o?->gross],
                                ['pcb_paid', 'PCB (income tax) paid (RM)', 'PCB (cukai pendapatan) dibayar (RM)', $o?->pcb_paid],
                                ['epf', 'EPF paid (RM)', 'EPF dibayar (RM)', $o?->epf],
                                ['socso', 'SOCSO paid (RM)', 'SOCSO dibayar (RM)', $o?->socso],
                                ['eis', 'EIS paid (RM)', 'EIS dibayar (RM)', $o?->eis],
                                ['zakat_paid', 'Zakat paid (RM)', 'Zakat dibayar (RM)', $o?->zakat_paid],
                            ] as $f)
                                <div><label style="display:block;font-size:10.5px;color:var(--muted);margin-bottom:3px;" x-text="$store.ui.lang==='en' ? @js($f[1]) : @js($f[2])">{{ $f[1] }}</label><input name="{{ $f[0] }}" type="number" step="0.01" min="0" value="{{ $f[3] !== null ? number_format((float) $f[3], 2, '.', '') : '' }}" placeholder="0.00" style="width:100%;height:34px;padding:0 9px;border:1px solid var(--hairline);border-radius:7px;font-size:12.5px;font-family:var(--font-mono);outline:none;" /></div>
                            @endforeach
                        </div>
                        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;max-width:520px;margin-bottom:12px;">
                            @foreach ([
                                ['additional_gross', 'Additional (bonus) gross (RM)', 'Kasar tambahan (bonus) (RM)', $o?->additional_gross],
                                ['additional_epf', 'EPF on additional (RM)', 'EPF atas tambahan (RM)', $o?->additional_epf],
                                ['optional_deductions', 'Optional deductions claimed (RM)', 'Potongan pilihan dituntut (RM)', $o?->optional_deductions],
                            ] as $f)
                                <div><label style="display:block;font-size:10.5px;color:var(--muted);margin-bottom:3px;" x-text="$store.ui.lang==='en' ? @js($f[1]) : @js($f[2])">{{ $f[1] }}</label><input name="{{ $f[0] }}" type="number" step="0.01" min="0" value="{{ $f[3] !== null ? number_format((float) $f[3], 2, '.', '') : '' }}" placeholder="0.00" style="width:100%;height:34px;padding:0 9px;border:1px solid var(--hairline);border-radius:7px;font-size:12.5px;font-family:var(--font-mono);outline:none;" /></div>
                            @endforeach
                        </div>
                        <div style="font-size:11.5px;font-weight:600;color:var(--ink);margin-bottom:6px;" x-text="$store.ui.lang==='en' ? 'Record-keeping only (EA form)' : 'Untuk rekod sahaja (Borang EA)'">Record-keeping only (EA form)</div>
                        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;max-width:520px;margin-bottom:12px;">
                            <div><label style="display:block;font-size:10.5px;color:var(--muted);margin-bottom:3px;" x-text="$store.ui.lang==='en' ? 'Exempt allowances (RM)' : 'Elaun dikecualikan cukai (RM)'">Exempt allowances (RM)</label><input name="exempt_allowances" type="number" step="0.01" min="0" value="{{ $o?->exempt_allowances !== null ? number_format((float) $o?->exempt_allowances, 2, '.', '') : '' }}" placeholder="0.00" style="width:100%;height:34px;padding:0 9px;border:1px solid var(--hairline);border-radius:7px;font-size:12.5px;font-family:var(--font-mono);outline:none;" /></div>
                            <div style="grid-column:span 2;"><label style="display:block;font-size:10.5px;color:var(--muted);margin-bottom:3px;" x-text="$store.ui.lang==='en' ? 'Previous employer' : 'Majikan sebelum ini'">Previous employer</label><input name="previous_employer" value="{{ $o?->previous_employer }}" placeholder="Company name" :placeholder="$store.ui.lang==='en' ? 'Company name' : 'Nama syarikat'" style="width:100%;height:34px;padding:0 9px;border:1px solid var(--hairline);border-radius:7px;font-size:12.5px;outline:none;" /></div>
                            <div><label style="display:block;font-size:10.5px;color:var(--muted);margin-bottom:3px;" x-text="$store.ui.lang==='en' ? 'Previous employer TIN' : 'TIN majikan sebelum ini'">Previous employer TIN</label><input name="previous_employer_tin" value="{{ $o?->previous_employer_tin }}" placeholder="Tax ID no." :placeholder="$store.ui.lang==='en' ? 'Tax ID no.' : 'No. rujukan cukai'" style="width:100%;height:34px;padding:0 9px;border:1px solid var(--hairline);border-radius:7px;font-size:12.5px;font-family:var(--font-mono);outline:none;" /></div>
                        </div>
                        <div style="margin-top:12px;"><button type="submit" class="uj-btn-primary" style="height:36px;padding:0 16px;font-size:12.5px;" x-text="$store.ui.lang==='en' ? 'Save opening figures' : 'Simpan angka permulaan'">Save opening figures</button><button type="button" @click="openFor = null" class="uj-btn-ghost" style="height:36px;padding:0 14px;font-size:12.5px;" x-text="$store.ui.lang==='en' ? 'Cancel' : 'Batal'">Cancel</button></div>
                    </form>
                </div>
            </div>
        @endforeach
        <div x-show="shown === 0" x-cloak style="padding:22px;text-align:center;color:var(--muted);font-size:13px;">
            <span x-text="$store.ui.lang==='en' ? 'Nobody matches that name.' : 'Tiada nama yang sepadan.'">Nobody matches that name.</span>
        </div>
    </div>
</div>
