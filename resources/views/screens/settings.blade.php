@extends('layouts.app')

@section('screen')
@include('partials.guide', [
    'key' => 'settings',
    'en'  => [
        'title' => 'Workspace settings',
        'body'  => 'Admin settings for the whole company — the workspace name, subscription plan, and the list of branches and departments. Changes here affect every member, so update them carefully.',
    ],
    'ms'  => [
        'title' => 'Tetapan workspace',
        'body'  => 'Tetapan admin untuk seluruh syarikat — nama workspace, pelan langganan, serta senarai cawangan dan jabatan. Perubahan di sini memberi kesan kepada setiap ahli, jadi kemas kini dengan berhati-hati.',
    ],
])
@php $only = request('section'); @endphp
<div style="{{ $only ? '' : 'display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap;' }}">
    @if (! $only || $only === 'profile')
    <div class="uj-card" style="{{ $only ? 'padding:24px;' : 'flex:1.2;min-width:340px;padding:24px;' }}">
        <h3 class="uj-card-title" style="margin-bottom:16px;" x-text="$store.ui.lang==='en' ? 'Workspace profile' : 'Profil workspace'">Workspace profile</h3>
        <form method="post" action="{{ route('admin.settings.update') }}" enctype="multipart/form-data">
            @csrf
            @if ($errors->any())<div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12.5px;border-radius:8px;padding:9px 12px;margin-bottom:14px;">{{ $errors->first() }}</div>@endif
            <label style="display:block;font-size:13px;font-weight:500;color:var(--ink);margin-bottom:6px;" x-text="$store.ui.lang==='en' ? 'Company name' : 'Nama syarikat'">Company name</label>
            <input name="name" value="{{ old('name', $company->name) }}" required style="width:100%;height:42px;padding:0 14px;border:1px solid var(--hairline);border-radius:8px;font-size:14px;margin-bottom:6px;outline:none;" />
            @include('partials.hint', ['en' => 'The name shown to everyone across the app and on documents. Changing it updates it for all members.', 'ms' => 'Nama yang dipaparkan kepada semua orang di seluruh aplikasi dan pada dokumen. Menukarnya akan mengemas kini untuk semua ahli.'])

            <label style="display:block;font-size:13px;font-weight:500;color:var(--ink);margin:14px 0 6px;" x-text="$store.ui.lang==='en' ? 'Industry' : 'Industri'">Industry</label>
            <input name="industry" value="{{ old('industry', $company->industry) }}" style="width:100%;height:42px;padding:0 14px;border:1px solid var(--hairline);border-radius:8px;font-size:14px;outline:none;" />

            <label style="display:block;font-size:13px;font-weight:500;color:var(--ink);margin:14px 0 6px;" x-text="$store.ui.lang==='en' ? 'Welcome message' : 'Mesej alu-aluan'">Welcome message</label>
            <input name="welcome_message" value="{{ old('welcome_message', $company->welcome_message) }}" placeholder="Shown on your company login page" style="width:100%;height:42px;padding:0 14px;border:1px solid var(--hairline);border-radius:8px;font-size:14px;outline:none;" />
            @include('partials.hint', ['en' => 'Greeting shown on your company-branded login page.', 'ms' => 'Ucapan yang dipaparkan pada halaman log masuk berjenama syarikat anda.'])

            <div style="display:flex;gap:12px;">
                <div style="flex:1;">
                    <label style="display:block;font-size:13px;font-weight:500;color:var(--ink);margin:14px 0 6px;" x-text="$store.ui.lang==='en' ? 'Brand colour' : 'Warna jenama'">Brand colour</label>
                    <input name="color" value="{{ old('color', $company->color) }}" placeholder="#d6232b" style="width:100%;height:42px;padding:0 14px;border:1px solid var(--hairline);border-radius:8px;font-size:14px;outline:none;" />
                </div>
                <div style="flex:1;">
                    <label style="display:block;font-size:13px;font-weight:500;color:var(--ink);margin:14px 0 6px;" x-text="$store.ui.lang==='en' ? 'Secondary colour' : 'Warna sekunder'">Secondary colour</label>
                    <input name="secondary_color" value="{{ old('secondary_color', $company->secondary_color) }}" placeholder="#1f1e1a" style="width:100%;height:42px;padding:0 14px;border:1px solid var(--hairline);border-radius:8px;font-size:14px;outline:none;" />
                </div>
            </div>

            <label style="display:block;font-size:13px;font-weight:500;color:var(--ink);margin:14px 0 6px;" x-text="$store.ui.lang==='en' ? 'Company logo' : 'Logo syarikat'">Company logo</label>
            @if ($company->logo_path)
                <img src="/storage/{{ $company->logo_path }}" alt="logo" style="height:40px;border-radius:8px;margin-bottom:8px;display:block;">
            @endif
            <input type="file" name="logo" accept="image/*" style="width:100%;font-size:13px;margin-bottom:6px;" />
            @include('partials.hint', ['en' => 'PNG or JPG up to 2 MB. Appears on your company login page.', 'ms' => 'PNG atau JPG sehingga 2 MB. Dipaparkan pada halaman log masuk syarikat anda.'])

            <div style="display:flex;gap:12px;">
                <div style="flex:1;">
                    <label style="display:block;font-size:13px;font-weight:500;color:var(--ink);margin:14px 0 6px;" x-text="$store.ui.lang==='en' ? 'Contact number' : 'Nombor telefon'">Contact number</label>
                    <input name="contact_number" value="{{ old('contact_number', $company->contact_number) }}" style="width:100%;height:42px;padding:0 14px;border:1px solid var(--hairline);border-radius:8px;font-size:14px;outline:none;" />
                </div>
                <div style="flex:1;">
                    <label style="display:block;font-size:13px;font-weight:500;color:var(--ink);margin:14px 0 6px;" x-text="$store.ui.lang==='en' ? 'Email' : 'Emel'">Email</label>
                    <input type="email" name="email" value="{{ old('email', $company->email) }}" style="width:100%;height:42px;padding:0 14px;border:1px solid var(--hairline);border-radius:8px;font-size:14px;outline:none;" />
                </div>
            </div>

            <details style="margin-top:14px;" @if ($errors->has('journal_accounts') || $errors->has('journal_accounts.*')) open @endif>
                <summary style="font-size:13px;font-weight:500;color:var(--ink);cursor:pointer;" x-text="$store.ui.lang==='en' ? 'Payroll journal account codes' : 'Kod akaun jurnal gaji'">Payroll journal account codes</summary>
                <div style="font-size:12px;color:var(--muted);margin:6px 0 10px;" x-text="$store.ui.lang==='en' ? 'Codes from your accounting software. Left blank, the journal CSV leaves the code empty.' : 'Kod daripada perisian perakaunan anda. Jika kosong, CSV jurnal membiarkan kod kosong.'">Codes from your accounting software. Left blank, the journal CSV leaves the code empty.</div>
                {{-- Laid out like the journal itself: what is charged on the left, what is owed on the right. --}}
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:20px 28px;">
                    @foreach (['debit' => ['Debit', 'Debit'], 'credit' => ['Credit', 'Kredit']] as $journalSide => [$sideEn, $sideMs])
                        <div>
                            <div style="font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;padding-bottom:6px;border-bottom:1px solid var(--hairline);" x-text="$store.ui.lang==='en' ? '{{ $sideEn }}' : '{{ $sideMs }}'">{{ $sideEn }}</div>
                            @foreach (\App\Services\Payroll\AccountingJournal::LINES as $key => [$lineName, $side])
                                @continue($side !== $journalSide)
                                <label style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:6px 0;border-bottom:1px solid var(--hairline-soft);font-size:13px;color:var(--ink);">
                                    <span>{{ $lineName }}</span>
                                    <input name="journal_accounts[{{ $key }}]" value="{{ old('journal_accounts.'.$key, $company->journal_accounts[$key] ?? '') }}" maxlength="40" placeholder="—" style="width:112px;height:32px;padding:0 10px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;font-family:var(--font-mono);font-variant-numeric:tabular-nums;outline:none;flex-shrink:0;" />
                                </label>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            </details>

            <label style="display:block;font-size:13px;font-weight:500;color:var(--ink);margin:14px 0 6px;" x-text="$store.ui.lang==='en' ? 'Website' : 'Laman web'">Website</label>
            <input name="website" value="{{ old('website', $company->website) }}" placeholder="https://" style="width:100%;height:42px;padding:0 14px;border:1px solid var(--hairline);border-radius:8px;font-size:14px;outline:none;" />

            <label style="display:block;font-size:13px;font-weight:500;color:var(--ink);margin:14px 0 6px;" x-text="$store.ui.lang==='en' ? 'Address' : 'Alamat'">Address</label>
            <input name="address" value="{{ old('address', $company->address) }}" style="width:100%;height:42px;padding:0 14px;border:1px solid var(--hairline);border-radius:8px;font-size:14px;outline:none;" />

            {{-- Plan, category, subscription, status and slug are set by the platform team
                 (super-admin) and are read-only here — shown for reference only. --}}
            <div style="display:flex;gap:24px;flex-wrap:wrap;margin:20px 0;padding:14px 16px;background:var(--canvas);border:1px solid var(--hairline-soft);border-radius:10px;">
                <div><div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;" x-text="$store.ui.lang==='en' ? 'Category' : 'Kategori'">Category</div><div style="font-size:14px;color:var(--ink);margin-top:3px;">{{ $company->companyCategory?->name ?? '—' }}</div></div>
                <div><div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;" x-text="$store.ui.lang==='en' ? 'Plan' : 'Pelan'">Plan</div><div style="font-size:14px;color:var(--ink);margin-top:3px;">{{ $company->plan }}</div></div>
                <div><div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;" x-text="$store.ui.lang==='en' ? 'Workspace ID' : 'ID workspace'">Workspace ID</div><div style="font-size:14px;color:var(--ink);font-family:var(--font-mono);margin-top:3px;">{{ $company->slug }}</div></div>
                <div><div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;" x-text="$store.ui.lang==='en' ? 'Members' : 'Ahli'">Members</div><div style="font-size:14px;color:var(--ink);font-family:var(--font-mono);margin-top:3px;">{{ $company->users()->count() }}</div></div>
            </div>

            <button type="submit" class="uj-btn-primary" style="height:42px;padding:0 20px;font-size:13.5px;"><span x-text="$store.ui.lang==='en' ? 'Save changes' : 'Simpan perubahan'">Save changes</span></button>
        </form>
    </div>
    @endif

    <div style="{{ $only ? '' : 'flex:1;min-width:280px;display:flex;flex-direction:column;gap:16px;' }}">

        @if (! $only || $only === 'statutory')
        {{-- Statutory & tax: every KWSP, PERKESO, LHDN, HRD Corp and zakat file is keyed on
             these numbers. Saved on its own so a profile save can't blank them. Shapes are
             only warned about: older registrations don't all follow today's format. --}}
        @php
            $inp = 'width:100%;height:42px;padding:0 14px;border:1px solid var(--hairline);border-radius:8px;font-size:14px;outline:none;';
            $lab = 'display:block;font-size:13px;font-weight:500;color:var(--ink);margin:14px 0 6px;';
            $blockHead = 'font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;margin-top:18px;padding-bottom:6px;border-bottom:1px solid var(--hairline);';
            $warn = 'font-size:12px;color:#9a6700;margin-top:4px;';
            $sb = $errors->statutory;
        @endphp
        <div id="statutory" class="uj-card" style="padding:20px;"
             x-data="{
                tin: @js(old('employer_tin', $company->employer_tin) ?? ''),
                epf: @js(old('epf_employer_no', $company->epf_employer_no) ?? ''),
                socso: @js(old('socso_employer_code', $company->socso_employer_code) ?? ''),
                clean(v) { return (v || '').replace(/\s+/g, '').toUpperCase(); },
             }">
            <h3 class="uj-card-title" style="margin-bottom:4px;" x-text="$store.ui.lang==='en' ? 'Statutory & tax' : 'Berkanun & cukai'">Statutory &amp; tax</h3>
            <p style="font-size:12.5px;color:var(--muted);margin:0 0 6px;" x-text="$store.ui.lang==='en' ? 'Every KWSP, PERKESO, LHDN, HRD Corp and zakat file carries these numbers. A payroll run can\'t be created while the KWSP number, PERKESO code or E number is blank.' : 'Setiap fail KWSP, PERKESO, LHDN, HRD Corp dan zakat membawa nombor ini. Run gaji tidak boleh dibuat selagi nombor KWSP, kod PERKESO atau nombor E kosong.'">Every KWSP, PERKESO, LHDN, HRD Corp and zakat file carries these numbers.</p>
            <form method="post" action="{{ route('admin.settings.statutory') }}">
                @csrf
                @if ($sb->any())<div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12.5px;border-radius:8px;padding:9px 12px;margin:10px 0;">{{ $sb->first() }}</div>@endif

                <div style="{{ $blockHead }}">LHDN</div>
                <label style="{{ $lab }}" x-text="$store.ui.lang==='en' ? 'Employer number (E)' : 'Nombor majikan (E)'">Employer number (E)</label>
                <div style="display:flex;align-items:stretch;">
                    <span style="display:flex;align-items:center;padding:0 12px;border:1px solid var(--hairline);border-right:0;border-radius:8px 0 0 8px;background:var(--canvas);font-family:var(--font-mono);font-size:14px;color:var(--muted);">E</span>
                    <input name="employer_tin" x-model="tin" maxlength="20" style="{{ $inp }}border-radius:0 8px 8px 0;font-family:var(--font-mono);" />
                </div>
                <div x-show="clean(tin) && !/^E?\d{10}$/.test(clean(tin))" x-cloak style="{{ $warn }}" x-text="$store.ui.lang==='en' ? 'An E number is usually 10 digits. Check it against your LHDN letter.' : 'Nombor E biasanya 10 digit. Semak dengan surat LHDN anda.'"></div>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:0 16px;">
                    <div><label style="{{ $lab }}" x-text="$store.ui.lang==='en' ? 'Employer category (Form E item 3)' : 'Kategori majikan (Borang E item 3)'">Employer category (Form E item 3)</label>
                        <select name="employer_category" style="{{ $inp }}"><option value="">-</option>@foreach (\App\Support\StatutoryOptions::EMPLOYER_CATEGORIES as $k => $v)<option value="{{ $k }}" @selected(old('employer_category', $company->employer_category) === $k)>{{ $k }} · {{ $v }}</option>@endforeach</select></div>
                    <div><label style="{{ $lab }}" x-text="$store.ui.lang==='en' ? 'Employer status (Form E item 4)' : 'Status majikan (Borang E item 4)'">Employer status (Form E item 4)</label>
                        <select name="employer_status" style="{{ $inp }}"><option value="">-</option>@foreach (\App\Support\StatutoryOptions::EMPLOYER_STATUSES as $k => $v)<option value="{{ $k }}" @selected(old('employer_status', $company->employer_status) === $k)>{{ $k }} · {{ $v }}</option>@endforeach</select></div>
                </div>

                <div style="{{ $blockHead }}">KWSP</div>
                <label style="{{ $lab }}" x-text="$store.ui.lang==='en' ? 'Employer number' : 'Nombor majikan'">Employer number</label>
                <input name="epf_employer_no" x-model="epf" style="{{ $inp }}font-family:var(--font-mono);" />
                <div x-show="clean(epf) && !/^\d{9}$/.test(clean(epf).replace(/-/g, ''))" x-cloak style="{{ $warn }}" x-text="$store.ui.lang==='en' ? 'A KWSP employer number is usually 9 digits.' : 'Nombor majikan KWSP biasanya 9 digit.'"></div>

                <div style="{{ $blockHead }}">PERKESO</div>
                <label style="{{ $lab }}" x-text="$store.ui.lang==='en' ? 'Employer code (SOCSO & EIS)' : 'Kod majikan (PERKESO & SIP)'">Employer code (SOCSO &amp; EIS)</label>
                <input name="socso_employer_code" x-model="socso" placeholder="A3100000000Z" style="{{ $inp }}font-family:var(--font-mono);" />
                <div x-show="clean(socso) && !/^[A-Z][A-Z0-9]{11}$/.test(clean(socso))" x-cloak style="{{ $warn }}" x-text="$store.ui.lang==='en' ? 'A PERKESO employer code is usually 12 characters starting with a letter.' : 'Kod majikan PERKESO biasanya 12 aksara bermula dengan huruf.'"></div>

                @if ($hrdfOn)
                    <div style="{{ $blockHead }}">HRD Corp</div>
                    <label style="{{ $lab }}" x-text="$store.ui.lang==='en' ? 'Registration number / MyCoID' : 'Nombor pendaftaran / MyCoID'">Registration number / MyCoID</label>
                    <input name="hrdf_registration_no" value="{{ old('hrdf_registration_no', $company->hrdf_registration_no) }}" style="{{ $inp }}font-family:var(--font-mono);" />
                @else
                    {{-- Kept so a save while the levy is off doesn't wipe a number entered earlier. --}}
                    <input type="hidden" name="hrdf_registration_no" value="{{ $company->hrdf_registration_no }}" />
                @endif

                <div style="{{ $blockHead }}">Zakat</div>
                <label style="{{ $lab }}" x-text="$store.ui.lang==='en' ? 'Employer number' : 'Nombor majikan'">Employer number</label>
                <input name="zakat_employer_no" value="{{ old('zakat_employer_no', $company->zakat_employer_no) }}" style="{{ $inp }}font-family:var(--font-mono);" />

                <div style="{{ $blockHead }}" x-text="$store.ui.lang==='en' ? 'Forms' : 'Borang'">Forms</div>
                <label style="{{ $lab }}" x-text="$store.ui.lang==='en' ? 'Signatory' : 'Penandatangan'">Signatory</label>
                <select name="statutory_signatory_employee_id" style="{{ $inp }}">
                    <option value="">-</option>
                    @foreach ($signatoryOptions as $person)
                        <option value="{{ $person->id }}" @selected((string) old('statutory_signatory_employee_id', $company->statutory_signatory_employee_id) === (string) $person->id)>{{ $person->name }}{{ $person->position ? ' · '.$person->position : '' }}</option>
                    @endforeach
                </select>
                @include('partials.hint', ['en' => 'Name and designation printed on CP21, CP22, CP22A and PCB II.', 'ms' => 'Nama dan jawatan yang dicetak pada CP21, CP22, CP22A dan PCB II.'])
                <div style="margin-top:12px;padding:12px 14px;background:var(--canvas);border:1px solid var(--hairline-soft);border-radius:10px;font-size:13px;color:var(--ink);">
                    <div style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;" x-text="$store.ui.lang==='en' ? 'Employer address and phone on the forms' : 'Alamat dan telefon majikan pada borang'">Employer address and phone on the forms</div>
                    <div style="margin-top:4px;">{{ $company->address ?: '-' }}</div>
                    <div>{{ $company->contact_number ?: '-' }}</div>
                    <a href="{{ route('app.screen', ['screen' => 'settings', 'section' => 'profile']) }}" style="display:inline-block;margin-top:6px;font-size:12.5px;color:var(--red);" x-text="$store.ui.lang==='en' ? 'Edit in Workspace profile' : 'Sunting di Profil workspace'">Edit in Workspace profile</a>
                </div>

                <div style="{{ $blockHead }}" x-text="$store.ui.lang==='en' ? 'Payment' : 'Pembayaran'">Payment</div>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:0 16px;">
                    <div><label style="{{ $lab }}" x-text="$store.ui.lang==='en' ? 'Paying bank' : 'Bank pembayar'">Paying bank</label>
                        <select name="paying_bank_code" style="{{ $inp }}"><option value="">-</option>@foreach (\App\Support\StatutoryOptions::BANK_CODES as $name => $code)<option value="{{ $code }}" @selected(old('paying_bank_code', $company->paying_bank_code) === $code)>{{ $name }}</option>@endforeach</select></div>
                    <div><label style="{{ $lab }}" x-text="$store.ui.lang==='en' ? 'Paying account number' : 'No. akaun pembayar'">Paying account number</label><input name="paying_bank_account_no" value="{{ old('paying_bank_account_no', $company->paying_bank_account_no) }}" style="{{ $inp }}" /></div>
                    <div><label style="{{ $lab }}" x-text="$store.ui.lang==='en' ? 'Payroll contact name' : 'Nama pegawai gaji'">Payroll contact name</label><input name="payroll_contact_name" value="{{ old('payroll_contact_name', $company->payroll_contact_name) }}" style="{{ $inp }}" /></div>
                    <div><label style="{{ $lab }}" x-text="$store.ui.lang==='en' ? 'Payroll contact phone' : 'Telefon pegawai gaji'">Payroll contact phone</label><input name="payroll_contact_phone" value="{{ old('payroll_contact_phone', $company->payroll_contact_phone) }}" style="{{ $inp }}" /></div>
                </div>

                <button type="submit" class="uj-btn-primary" style="margin-top:18px;height:42px;padding:0 20px;font-size:13.5px;"><span x-text="$store.ui.lang==='en' ? 'Save statutory details' : 'Simpan butiran berkanun'">Save statutory details</span></button>
            </form>
        </div>
        @endif

        @if (!empty($canManageFeatures) && (! $only || $only === 'work_week'))
        {{-- Work week: which ISO weekdays (1 = Mon .. 7 = Sun) are working days. Read by
             App\Support\WorkWeek. Forward-only; the TOT flag is Unijaya-only and has no UI. --}}
        <div class="uj-card" style="padding:20px;"
             x-data="{
                days: @js(\App\Support\WorkWeek::for()->workingDays()),
                names: { en: ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'], ms: ['Isn','Sel','Rab','Kha','Jum','Sab','Ahd'] },
                has(n) { return this.days.includes(n); },
                toggle(n) { this.has(n) ? this.days = this.days.filter(d => d !== n) : this.days.push(n); },
             }">
            <h3 class="uj-card-title" style="margin-bottom:4px;" x-text="$store.ui.lang==='en' ? 'Work week' : 'Minggu bekerja'">Work week</h3>
            <p style="font-size:13px;color:var(--muted);margin:0 0 14px;" x-text="$store.ui.lang==='en' ? 'Which days count as working days. Leave balances, timesheet capacity and attendance reports all follow this.' : 'Hari mana dikira sebagai hari bekerja. Baki cuti, kapasiti timesheet dan laporan kehadiran semuanya mengikut ini.'">Which days count as working days. Leave balances, timesheet capacity and attendance reports all follow this.</p>

            <form method="post" action="{{ route('admin.workweek.update') }}">
                @csrf
                @if ($errors->has('work_days') || $errors->has('work_days.*'))<div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12.5px;border-radius:8px;padding:9px 12px;margin-bottom:12px;" x-text="$store.ui.lang==='en' ? 'Pick at least one working day.' : 'Pilih sekurang-kurangnya satu hari bekerja.'">Pick at least one working day.</div>@endif

                <div style="display:flex;gap:6px;margin-bottom:14px;">
                    <template x-for="n in [1,2,3,4,5,6,7]" :key="n">
                        <button type="button" @click="toggle(n)" :aria-pressed="has(n)"
                                :style="has(n) ? 'border-color:var(--red);background:var(--red-tint);' : ''"
                                style="display:flex;flex-direction:column;align-items:center;gap:4px;padding:10px 0 8px;border:1px solid var(--hairline);border-radius:10px;background:#fff;cursor:pointer;flex:1;min-width:0;user-select:none;">
                            <span style="font-size:13px;font-weight:600;color:var(--ink);" x-text="names[$store.ui.lang==='en' ? 'en' : 'ms'][n-1]"></span>
                            <span style="font-size:11px;" :style="has(n) ? 'color:var(--red);' : 'color:var(--muted);'" x-text="has(n) ? ($store.ui.lang==='en' ? 'Work' : 'Kerja') : ($store.ui.lang==='en' ? 'Off' : 'Cuti')"></span>
                        </button>
                    </template>
                </div>
                <template x-for="d in days" :key="'wd'+d"><input type="hidden" name="work_days[]" :value="d"></template>

                @include('partials.hint', ['en' => 'Applies from today. Past records are not recalculated.', 'ms' => 'Berkuat kuasa dari hari ini. Rekod lepas tidak dikira semula.'])

                <div style="display:flex;align-items:center;gap:12px;margin-top:6px;">
                    <button type="submit" class="uj-btn-primary" style="height:38px;padding:0 18px;font-size:13px;" :disabled="days.length === 0"><span x-text="$store.ui.lang==='en' ? 'Save work week' : 'Simpan minggu bekerja'">Save work week</span></button>
                    <span style="font-size:12.5px;color:var(--muted);" x-text="days.length + ' ' + ($store.ui.lang==='en' ? (days.length === 1 ? 'working day' : 'working days') : 'hari bekerja')">5 working days</span>
                </div>
            </form>
        </div>
        @endif

        @if (! $only || $only === 'branches')
        {{-- Branches: name + state CRUD. Geofence/hours live on the Attendance Setup screen. --}}
        <div class="uj-card" style="padding:20px;" @if ($canManageFeatures) x-data="{ adding:false, editId:null }" @endif>
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
                <h3 class="uj-card-title" x-text="$store.ui.lang==='en' ? 'Branches' : 'Cawangan'">Branches</h3>
                @if ($canManageFeatures)
                    <button type="button" @click="adding=!adding;editId=null" class="uj-btn-ghost" style="height:30px;padding:0 12px;font-size:12.5px;">
                        <span x-text="adding ? ($store.ui.lang==='en'?'Cancel':'Batal') : ($store.ui.lang==='en'?'+ Add':'+ Tambah')">+ Add</span>
                    </button>
                @endif
            </div>
            @include('partials.coachmark', [
                'key' => 'guide-branches',
                'when' => "\$store.guide.current === 'branches'",
                'anchor' => 'button.uj-btn-ghost',
                'en' => ['title' => 'Add your first branch', 'body' => 'Click + Add, give the branch a name and address, then click Add branch. The map pin can wait; it comes up in the attendance step.'],
                'ms' => ['title' => 'Tambah cawangan pertama anda', 'body' => 'Klik + Tambah, beri nama dan alamat cawangan, kemudian klik Tambah cawangan. Pin peta boleh ditunggu; ia muncul dalam langkah kehadiran.'],
            ])

            @if ($canManageFeatures)
                @php $bfs = 'height:36px;padding:0 10px;border:1px solid var(--hairline);border-radius:8px;font-size:12.5px;outline:none;background:#fff;color:var(--ink);min-width:0;'; @endphp
                <form x-show="adding" x-cloak method="post" action="{{ route('admin.branches.store') }}" style="margin-bottom:14px;">
                    @csrf
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:8px;">
                        <input name="name" required :placeholder="$store.ui.lang==='en'?'Branch name *':'Nama cawangan *'" style="{{ $bfs }}" />
                        <input name="code" placeholder="Code" style="{{ $bfs }}" />
                        <select name="type" style="{{ $bfs }}"><option value="">Type…</option>@foreach ($locationTypes as $t)<option value="{{ $t }}">{{ $t }}</option>@endforeach</select>
                        <input name="state" :placeholder="$store.ui.lang==='en'?'State':'Negeri'" style="{{ $bfs }}" />
                        <input name="contact_number" placeholder="Contact" style="{{ $bfs }}" />
                        <input name="email" type="email" placeholder="Email" style="{{ $bfs }}" />
                        <select name="status" style="{{ $bfs }}"><option value="active">Active</option><option value="inactive">Inactive</option></select>
                        <input name="effective_date" type="date" style="{{ $bfs }}" />
                    </div>
                    <input name="address" placeholder="Address" style="{{ $bfs }}width:100%;margin-top:8px;" />
                    {{-- Geofence + working hours: the attendance clock checks a punch against these. --}}
                    <div style="display:flex;flex-wrap:wrap;align-items:end;gap:8px;margin-top:8px;">
                        <input id="lat-newbranch" name="latitude" placeholder="Latitude" style="{{ $bfs }}width:120px;font-family:var(--font-mono);" />
                        <input id="lng-newbranch" name="longitude" placeholder="Longitude" style="{{ $bfs }}width:120px;font-family:var(--font-mono);" />
                        <button type="button" x-data @click="window.dispatchEvent(new CustomEvent('open-map-picker', { detail: { latId: 'lat-newbranch', lngId: 'lng-newbranch', title: 'New branch' } }))" class="uj-btn-ghost" style="height:36px;padding:0 12px;font-size:12px;white-space:nowrap;">📍 <span x-text="$store.ui.lang==='en'?'Map':'Peta'">Map</span></button>
                        <input name="radius_m" type="number" min="20" max="5000" value="200" placeholder="Radius (m)" style="{{ $bfs }}width:96px;font-family:var(--font-mono);" />
                        <input name="work_start" type="time" style="{{ $bfs }}width:118px;" />
                        <input name="work_end" type="time" style="{{ $bfs }}width:118px;" />
                        <input name="min_hours" type="number" step="0.5" min="0" max="24" placeholder="Min hrs" style="{{ $bfs }}width:88px;font-family:var(--font-mono);" />
                    </div>
                    <button type="submit" class="uj-btn-primary" style="height:36px;padding:0 16px;font-size:12.5px;margin-top:8px;"><span x-text="$store.ui.lang==='en'?'Add branch':'Tambah cawangan'">Add branch</span></button>
                </form>
            @endif

            @forelse ($branches as $b)
                <div style="padding:8px 0;border-bottom:1px solid var(--hairline-soft);">
                    <div @if ($canManageFeatures) x-show="editId !== {{ $b->id }}" @endif style="display:flex;align-items:center;justify-content:space-between;gap:8px;">
                        <span style="font-size:13px;color:var(--ink);">{{ $b->name }}@if ($b->type || $b->code)<span style="color:var(--muted);font-size:11.5px;"> · {{ $b->type ?: 'Branch' }}@if ($b->code) ({{ $b->code }})@endif</span>@endif</span>
                        <div style="display:flex;align-items:center;gap:12px;">
                            <span style="font-size:12px;color:var(--muted);">{{ $b->state }}</span>
                            @if ($canManageFeatures)
                                <button type="button" @click="editId={{ $b->id }};adding=false" style="font-size:12px;color:var(--ink);" x-text="$store.ui.lang==='en'?'Edit':'Sunting'">Edit</button>
                                <button type="submit" form="del-branch-{{ $b->id }}" style="font-size:12px;color:var(--red);" x-text="$store.ui.lang==='en'?'Delete':'Padam'">Delete</button>
                            @endif
                        </div>
                    </div>
                    @if ($canManageFeatures)
                        <form x-show="editId === {{ $b->id }}" x-cloak method="post" action="{{ route('admin.branches.update', $b) }}">
                            @csrf
                            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:8px;">
                                <input name="name" value="{{ $b->name }}" required style="{{ $bfs }}" />
                                <input name="code" value="{{ $b->code }}" placeholder="Code" style="{{ $bfs }}" />
                                <select name="type" style="{{ $bfs }}"><option value="">Type…</option>@foreach ($locationTypes as $t)<option value="{{ $t }}" @selected($b->type === $t)>{{ $t }}</option>@endforeach</select>
                                <input name="state" value="{{ $b->state }}" placeholder="State" style="{{ $bfs }}" />
                                <input name="contact_number" value="{{ $b->contact_number }}" placeholder="Contact" style="{{ $bfs }}" />
                                <input name="email" type="email" value="{{ $b->email }}" placeholder="Email" style="{{ $bfs }}" />
                                <select name="status" style="{{ $bfs }}"><option value="active" @selected(($b->status ?? 'active')==='active')>Active</option><option value="inactive" @selected($b->status==='inactive')>Inactive</option></select>
                                <input name="effective_date" type="date" value="{{ optional($b->effective_date)->toDateString() }}" style="{{ $bfs }}" />
                            </div>
                            <input name="address" value="{{ $b->address }}" placeholder="Address" style="{{ $bfs }}width:100%;margin-top:8px;" />
                            {{-- Geofence + working hours: the attendance clock checks a punch against these. --}}
                            <div style="display:flex;flex-wrap:wrap;align-items:end;gap:8px;margin-top:8px;">
                                <input id="lat-branch-{{ $b->id }}" name="latitude" value="{{ $b->latitude }}" placeholder="Latitude" style="{{ $bfs }}width:120px;font-family:var(--font-mono);" />
                                <input id="lng-branch-{{ $b->id }}" name="longitude" value="{{ $b->longitude }}" placeholder="Longitude" style="{{ $bfs }}width:120px;font-family:var(--font-mono);" />
                                <button type="button" x-data @click="window.dispatchEvent(new CustomEvent('open-map-picker', { detail: { latId: 'lat-branch-{{ $b->id }}', lngId: 'lng-branch-{{ $b->id }}', title: @js($b->name) } }))" class="uj-btn-ghost" style="height:36px;padding:0 12px;font-size:12px;white-space:nowrap;">📍 <span x-text="$store.ui.lang==='en'?'Map':'Peta'">Map</span></button>
                                <input name="radius_m" type="number" min="20" max="5000" value="{{ $b->radius_m ?? 200 }}" placeholder="Radius (m)" style="{{ $bfs }}width:96px;font-family:var(--font-mono);" />
                                <input name="work_start" type="time" value="{{ $b->work_start ? substr($b->work_start, 0, 5) : '' }}" style="{{ $bfs }}width:118px;" />
                                <input name="work_end" type="time" value="{{ $b->work_end ? substr($b->work_end, 0, 5) : '' }}" style="{{ $bfs }}width:118px;" />
                                <input name="min_hours" type="number" step="0.5" min="0" max="24" value="{{ $b->min_hours }}" placeholder="Min hrs" style="{{ $bfs }}width:88px;font-family:var(--font-mono);" />
                            </div>
                            <div style="display:flex;gap:8px;margin-top:8px;">
                                <button type="submit" class="uj-btn-primary" style="height:34px;padding:0 14px;font-size:12px;"><span x-text="$store.ui.lang==='en'?'Save':'Simpan'">Save</span></button>
                                <button type="button" @click="editId=null" style="font-size:12px;color:var(--muted);" x-text="$store.ui.lang==='en'?'Cancel':'Batal'">Cancel</button>
                            </div>
                        </form>
                    @endif
                </div>
            @empty
                <p style="font-size:12.5px;color:var(--muted);margin:4px 0 0;" x-text="$store.ui.lang==='en'?'No branches yet.':'Tiada cawangan lagi.'">No branches yet.</p>
            @endforelse

            @if ($canManageFeatures)
                @foreach ($branches as $b)
                    <form id="del-branch-{{ $b->id }}" method="post" action="{{ route('admin.branches.delete', $b) }}" onsubmit="return confirm('Delete {{ addslashes($b->name) }}?')">@csrf</form>
                @endforeach
            @endif
        </div>
        {{-- One map-picker modal for every branch geofence row on this screen. --}}
        @if ($canManageFeatures)
            @include('partials.map-picker')
        @endif
        @endif

        @if (! $only || $only === 'departments')
        {{-- Departments: name CRUD. employees_count is shown for context; delete is blocked while in use. --}}
        <div class="uj-card" style="padding:20px;" @if ($canManageFeatures) x-data="{ adding:false, editId:null }" @endif>
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
                <h3 class="uj-card-title" x-text="$store.ui.lang==='en' ? 'Departments' : 'Jabatan'">Departments</h3>
                @if ($canManageFeatures)
                    <button type="button" @click="adding=!adding;editId=null" class="uj-btn-ghost" style="height:30px;padding:0 12px;font-size:12.5px;">
                        <span x-text="adding ? ($store.ui.lang==='en'?'Cancel':'Batal') : ($store.ui.lang==='en'?'+ Add':'+ Tambah')">+ Add</span>
                    </button>
                @endif
            </div>
            @include('partials.coachmark', [
                'key' => 'guide-departments',
                'when' => "\$store.guide.current === 'departments'",
                'anchor' => 'button.uj-btn-ghost',
                'en' => ['title' => 'Add a department', 'body' => 'Click + Add, type the department name and click Add. One is enough to start; staff are grouped under these.'],
                'ms' => ['title' => 'Tambah jabatan', 'body' => 'Klik + Tambah, taip nama jabatan dan klik Tambah. Satu sudah cukup untuk mula; staf dikumpulkan di bawah ini.'],
            ])

            @if ($canManageFeatures)
                <form x-show="adding" x-cloak method="post" action="{{ route('admin.departments.store') }}" style="display:flex;gap:8px;margin-bottom:14px;">
                    @csrf
                    <input name="name" required :placeholder="$store.ui.lang==='en'?'Department name':'Nama jabatan'" style="flex:1;min-width:0;height:38px;padding:0 12px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;outline:none;" />
                    <button type="submit" class="uj-btn-primary" style="height:38px;padding:0 14px;font-size:12.5px;flex-shrink:0;"><span x-text="$store.ui.lang==='en'?'Add':'Tambah'">Add</span></button>
                </form>
            @endif

            @forelse ($departments as $d)
                <div style="padding:8px 0;border-bottom:1px solid var(--hairline-soft);">
                    <div @if ($canManageFeatures) x-show="editId !== {{ $d->id }}" @endif style="display:flex;align-items:center;justify-content:space-between;gap:8px;">
                        <span style="font-size:13px;color:var(--ink);">{{ $d->name }}</span>
                        <div style="display:flex;align-items:center;gap:12px;">
                            <span style="font-size:12px;color:var(--muted);font-family:var(--font-mono);" title="{{ $d->employees_count }} {{ __('employees') }}">{{ $d->employees_count }}</span>
                            @if ($canManageFeatures)
                                <button type="button" @click="editId={{ $d->id }};adding=false" style="font-size:12px;color:var(--ink);" x-text="$store.ui.lang==='en'?'Edit':'Sunting'">Edit</button>
                                <button type="submit" form="del-dept-{{ $d->id }}" style="font-size:12px;color:var(--red);" x-text="$store.ui.lang==='en'?'Delete':'Padam'">Delete</button>
                            @endif
                        </div>
                    </div>
                    @if ($canManageFeatures)
                        <form x-show="editId === {{ $d->id }}" x-cloak method="post" action="{{ route('admin.departments.update', $d) }}" style="display:flex;gap:8px;align-items:center;">
                            @csrf
                            <input name="name" value="{{ $d->name }}" required style="flex:1;min-width:0;height:36px;padding:0 10px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;outline:none;" />
                            <button type="submit" class="uj-btn-primary" style="height:36px;padding:0 12px;font-size:12px;flex-shrink:0;"><span x-text="$store.ui.lang==='en'?'Save':'Simpan'">Save</span></button>
                            <button type="button" @click="editId=null" style="font-size:12px;color:var(--muted);flex-shrink:0;" x-text="$store.ui.lang==='en'?'Cancel':'Batal'">Cancel</button>
                        </form>
                    @endif
                </div>
            @empty
                <p style="font-size:12.5px;color:var(--muted);margin:4px 0 0;" x-text="$store.ui.lang==='en'?'No departments yet.':'Tiada jabatan lagi.'">No departments yet.</p>
            @endforelse

            @if ($canManageFeatures)
                @foreach ($departments as $d)
                    <form id="del-dept-{{ $d->id }}" method="post" action="{{ route('admin.departments.delete', $d) }}" onsubmit="return confirm('Delete {{ addslashes($d->name) }}?')">@csrf</form>
                @endforeach
            @endif
        </div>
        @endif

        @if (! $only || $only === 'staff-levels')
        {{-- Staff levels (grades): name + optional code. Blocked from delete while in use. --}}
        <div class="uj-card" style="padding:20px;" @if ($canManageFeatures) x-data="{ adding:false, editId:null }" @endif>
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
                <h3 class="uj-card-title" x-text="$store.ui.lang==='en' ? 'Staff levels' : 'Tahap staf'">Staff levels</h3>
                @if ($canManageFeatures)
                    <button type="button" @click="adding=!adding" class="uj-btn-ghost" style="height:30px;padding:0 12px;font-size:12.5px;">
                        <span x-text="adding ? ($store.ui.lang==='en'?'Cancel':'Batal') : ($store.ui.lang==='en'?'+ Add':'+ Tambah')">+ Add</span>
                    </button>
                @endif
            </div>
            @if ($canManageFeatures)
                <form x-show="adding" x-cloak method="post" action="{{ route('admin.staff-levels.store') }}" style="display:flex;gap:8px;margin-bottom:14px;">
                    @csrf
                    <input name="name" required :placeholder="$store.ui.lang==='en'?'Level (e.g. L3)':'Tahap (cth. L3)'" style="flex:2;min-width:0;height:38px;padding:0 12px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;outline:none;" />
                    <input name="code" :placeholder="$store.ui.lang==='en'?'Code':'Kod'" style="flex:1;min-width:0;height:38px;padding:0 12px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;outline:none;" />
                    <input name="rank" type="number" min="0" max="65535" :placeholder="$store.ui.lang==='en'?'Seniority (1=most senior)':'Kekananan (1=paling kanan)'" style="flex:1;min-width:0;height:38px;padding:0 12px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;outline:none;" />
                    <button type="submit" class="uj-btn-primary" style="height:38px;padding:0 14px;font-size:12.5px;flex-shrink:0;"><span x-text="$store.ui.lang==='en'?'Add':'Tambah'">Add</span></button>
                </form>
                <p x-show="adding" x-cloak style="font-size:11.5px;color:var(--muted);margin:-8px 0 14px;" x-text="$store.ui.lang==='en'?'A smaller number means more senior. Staff can open the full profile of anyone on a more junior level.':'Nombor lebih kecil bermaksud lebih kanan. Staf boleh membuka profil penuh sesiapa di tahap yang lebih rendah.'">A smaller number means more senior. Staff can open the full profile of anyone on a more junior level.</p>
            @endif
            @forelse ($staffLevels as $lv)
                <div style="padding:8px 0;border-bottom:1px solid var(--hairline-soft);">
                    <div @if ($canManageFeatures) x-show="editId !== {{ $lv->id }}" @endif style="display:flex;align-items:center;justify-content:space-between;gap:8px;">
                        <span style="font-size:13px;color:var(--ink);">{{ $lv->name }}@if ($lv->code)<span style="color:var(--muted);font-size:12px;"> · {{ $lv->code }}</span>@endif</span>
                        @if ($canManageFeatures)
                            <div style="display:flex;align-items:center;gap:12px;">
                                <button type="button" @click="editId={{ $lv->id }};adding=false" style="font-size:12px;color:var(--ink);" x-text="$store.ui.lang==='en'?'Edit':'Sunting'">Edit</button>
                                <button type="submit" form="del-lv-{{ $lv->id }}" style="font-size:12px;color:var(--red);" x-text="$store.ui.lang==='en'?'Delete':'Padam'">Delete</button>
                            </div>
                        @endif
                    </div>
                    @if ($canManageFeatures)
                        <form x-show="editId === {{ $lv->id }}" x-cloak method="post" action="{{ route('admin.staff-levels.update', $lv) }}" style="display:flex;gap:8px;align-items:center;">
                            @csrf
                            <input name="name" value="{{ $lv->name }}" required style="flex:2;min-width:0;height:36px;padding:0 10px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;outline:none;" />
                            <input name="code" value="{{ $lv->code }}" :placeholder="$store.ui.lang==='en'?'Code':'Kod'" style="flex:1;min-width:0;height:36px;padding:0 10px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;outline:none;" />
                            <input name="rank" type="number" min="0" max="65535" value="{{ $lv->rank }}" :placeholder="$store.ui.lang==='en'?'Seniority (1=most senior)':'Kekananan (1=paling kanan)'" style="flex:1;min-width:0;height:36px;padding:0 10px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;outline:none;" />
                            <button type="submit" class="uj-btn-primary" style="height:36px;padding:0 12px;font-size:12px;flex-shrink:0;"><span x-text="$store.ui.lang==='en'?'Save':'Simpan'">Save</span></button>
                            <button type="button" @click="editId=null" style="font-size:12px;color:var(--muted);flex-shrink:0;" x-text="$store.ui.lang==='en'?'Cancel':'Batal'">Cancel</button>
                        </form>
                        <p x-show="editId === {{ $lv->id }}" x-cloak style="font-size:11.5px;color:var(--muted);margin:6px 0 0;" x-text="$store.ui.lang==='en'?'A smaller number means more senior. Staff can open the full profile of anyone on a more junior level.':'Nombor lebih kecil bermaksud lebih kanan. Staf boleh membuka profil penuh sesiapa di tahap yang lebih rendah.'">A smaller number means more senior. Staff can open the full profile of anyone on a more junior level.</p>
                    @endif
                </div>
            @empty
                <p style="font-size:12.5px;color:var(--muted);margin:4px 0 0;" x-text="$store.ui.lang==='en'?'No staff levels yet.':'Tiada tahap staf lagi.'">No staff levels yet.</p>
            @endforelse
            @if ($canManageFeatures)
                @foreach ($staffLevels as $lv)
                    <form id="del-lv-{{ $lv->id }}" method="post" action="{{ route('admin.staff-levels.delete', $lv) }}" onsubmit="return confirm('Delete {{ addslashes($lv->name) }}?')">@csrf</form>
                @endforeach
            @endif
        </div>
        @endif

        @if (! $only || $only === 'employment-types')
        {{-- Employment types: Full-time, Contract, Part-time, etc. --}}
        <div class="uj-card" style="padding:20px;" @if ($canManageFeatures) x-data="{ adding:false, editId:null }" @endif>
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
                <h3 class="uj-card-title" x-text="$store.ui.lang==='en' ? 'Employment types' : 'Jenis pekerjaan'">Employment types</h3>
                @if ($canManageFeatures)
                    <button type="button" @click="adding=!adding" class="uj-btn-ghost" style="height:30px;padding:0 12px;font-size:12.5px;">
                        <span x-text="adding ? ($store.ui.lang==='en'?'Cancel':'Batal') : ($store.ui.lang==='en'?'+ Add':'+ Tambah')">+ Add</span>
                    </button>
                @endif
            </div>
            @if ($canManageFeatures)
                <form x-show="adding" x-cloak method="post" action="{{ route('admin.employment-types.store') }}" style="display:flex;gap:8px;margin-bottom:14px;">
                    @csrf
                    <input name="name" required :placeholder="$store.ui.lang==='en'?'Type (e.g. Full-time)':'Jenis (cth. Sepenuh masa)'" style="flex:2;min-width:0;height:38px;padding:0 12px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;outline:none;" />
                    <input name="code" :placeholder="$store.ui.lang==='en'?'Code':'Kod'" style="flex:1;min-width:0;height:38px;padding:0 12px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;outline:none;" />
                    <button type="submit" class="uj-btn-primary" style="height:38px;padding:0 14px;font-size:12.5px;flex-shrink:0;"><span x-text="$store.ui.lang==='en'?'Add':'Tambah'">Add</span></button>
                </form>
            @endif
            @forelse ($employmentTypes as $et)
                <div style="padding:8px 0;border-bottom:1px solid var(--hairline-soft);">
                    <div @if ($canManageFeatures) x-show="editId !== {{ $et->id }}" @endif style="display:flex;align-items:center;justify-content:space-between;gap:8px;">
                        <span style="font-size:13px;color:var(--ink);">{{ $et->name }}@if ($et->code)<span style="color:var(--muted);font-size:12px;"> · {{ $et->code }}</span>@endif</span>
                        @if ($canManageFeatures)
                            <div style="display:flex;align-items:center;gap:12px;">
                                <button type="button" @click="editId={{ $et->id }};adding=false" style="font-size:12px;color:var(--ink);" x-text="$store.ui.lang==='en'?'Edit':'Sunting'">Edit</button>
                                <button type="submit" form="del-et-{{ $et->id }}" style="font-size:12px;color:var(--red);" x-text="$store.ui.lang==='en'?'Delete':'Padam'">Delete</button>
                            </div>
                        @endif
                    </div>
                    @if ($canManageFeatures)
                        <form x-show="editId === {{ $et->id }}" x-cloak method="post" action="{{ route('admin.employment-types.update', $et) }}" style="display:flex;gap:8px;align-items:center;">
                            @csrf
                            <input name="name" value="{{ $et->name }}" required style="flex:2;min-width:0;height:36px;padding:0 10px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;outline:none;" />
                            <input name="code" value="{{ $et->code }}" :placeholder="$store.ui.lang==='en'?'Code':'Kod'" style="flex:1;min-width:0;height:36px;padding:0 10px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;outline:none;" />
                            <button type="submit" class="uj-btn-primary" style="height:36px;padding:0 12px;font-size:12px;flex-shrink:0;"><span x-text="$store.ui.lang==='en'?'Save':'Simpan'">Save</span></button>
                            <button type="button" @click="editId=null" style="font-size:12px;color:var(--muted);flex-shrink:0;" x-text="$store.ui.lang==='en'?'Cancel':'Batal'">Cancel</button>
                        </form>
                    @endif
                </div>
            @empty
                <p style="font-size:12.5px;color:var(--muted);margin:4px 0 0;" x-text="$store.ui.lang==='en'?'No employment types yet.':'Tiada jenis pekerjaan lagi.'">No employment types yet.</p>
            @endforelse
            @if ($canManageFeatures)
                @foreach ($employmentTypes as $et)
                    <form id="del-et-{{ $et->id }}" method="post" action="{{ route('admin.employment-types.delete', $et) }}" onsubmit="return confirm('Delete {{ addslashes($et->name) }}?')">@csrf</form>
                @endforeach
            @endif
        </div>
        @endif

        @if (! $only || $only === 'greetings')
        {{-- CR-33: rotating dashboard greeting bank. HR approves/edits/deletes; any
             employee can suggest a line from the dashboard picker. --}}
        @include('partials.line-bank', [
            'title_en' => 'Dashboard greetings', 'title_ms' => 'Ucapan papan pemuka',
            'hint_en' => 'These lines rotate on everyone\'s dashboard greeting. Use {name} where the person\'s first name should go.',
            'hint_ms' => 'Baris ini berputar pada ucapan papan pemuka semua orang. Guna {name} di tempat nama pertama orang itu patut muncul.',
            'empty_en' => 'No greeting lines yet.', 'empty_ms' => 'Tiada ucapan lagi.',
            'field' => 'trigger', 'routes' => 'admin.greetings',
            'lines' => $greetingLines, 'pending' => $greetingPending, 'categories' => $greetingTriggers,
            'buckets' => ['personal' => ['Personal', 'Peribadi'], 'situation' => ['Situation', 'Situasi'], 'day' => ['Day', 'Hari'], 'time' => ['Time', 'Masa']],
            'defaultBucket' => 'situation', 'canManage' => $canManageFeatures,
        ])
        @endif

        @if (! $only || $only === 'eggs')
        {{-- CR-31: dashboard/board easter-egg bank, same card as the greetings above. --}}
        @php
            $eggKindLabels = [
                'friday_late' => ['Friday after 5', 'Jumaat selepas 5'], 'inbox_zero' => ['Inbox zero', 'Inbox kosong'],
                'late_night' => ['Late night', 'Lewat malam'], 'tab_collector' => ['Tab collector', 'Pengumpul tab'], 'holiday_eve' => ['Holiday eve', 'Malam cuti'],
            ];
        @endphp
        @include('partials.line-bank', [
            'title_en' => 'Dashboard easter eggs', 'title_ms' => 'Telur Paskah papan pemuka',
            'hint_en' => 'Small surprises shown on the dashboard or board, at most once a day per person. Never blocks anything.',
            'hint_ms' => 'Kejutan kecil yang dipapar pada papan pemuka atau board, paling banyak sekali sehari bagi setiap orang. Tidak menyekat apa-apa.',
            'empty_en' => 'No easter eggs yet.', 'empty_ms' => 'Tiada telur Paskah lagi.',
            'field' => 'kind', 'routes' => 'admin.eggs', 'lines' => $easterEggs,
            'categories' => collect($easterEggKinds)->mapWithKeys(fn ($k) => [$k => ['label_en' => $eggKindLabels[$k][0] ?? $k, 'label_ms' => $eggKindLabels[$k][1] ?? $k]])->all(),
            'canManage' => $canManageFeatures,
        ])
        @endif

        @if (!empty($canManageFeatures) && (! $only || $only === 'reactions'))
        {{-- CR-30: the tenant's reaction set. Add or retire, never rename, ten active at most. --}}
        @php $reactionSet = \App\Models\Reaction::set(); $activeReactions = $reactionSet->whereNull('retired_at')->count(); @endphp
        <div class="uj-card" style="padding:20px;" x-data="{ adding:false }">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;">
                <h3 class="uj-card-title" x-text="$store.ui.lang==='en' ? 'Reactions' : 'Reaksi'">Reactions</h3>
                @if ($activeReactions < \App\Models\Reaction::MAX_ACTIVE)
                    <button type="button" @click="adding=!adding" style="font-size:12.5px;color:var(--red);" x-text="$store.ui.lang==='en' ? '+ Add reaction' : '+ Tambah reaksi'">+ Add reaction</button>
                @endif
            </div>
            <p style="font-size:12px;color:var(--muted);margin:0 0 10px;">
                <span x-show="$store.ui.lang==='en'">Up to ten active. Retiring one keeps it on the items it was already given; nothing is ever renamed. {{ $activeReactions }} of {{ \App\Models\Reaction::MAX_ACTIVE }} active.</span>
                <span x-show="$store.ui.lang!=='en'" x-cloak>Sehingga sepuluh aktif. Reaksi yang dibersarakan kekal pada item lama; tiada yang dinamakan semula. {{ $activeReactions }} daripada {{ \App\Models\Reaction::MAX_ACTIVE }} aktif.</span>
            </p>
            @php $rfs = 'height:36px;padding:0 10px;border:1px solid var(--hairline);border-radius:8px;font-size:12.5px;outline:none;background:#fff;color:var(--ink);min-width:0;'; @endphp
            <form x-show="adding" x-cloak method="post" action="{{ route('admin.reactions.store') }}" style="margin-bottom:14px;display:flex;flex-wrap:wrap;gap:8px;">
                @csrf
                <input name="icon" required maxlength="16" placeholder="Icon (emoji or 1-2 letters)" style="{{ $rfs }}width:200px;" />
                <input name="label" required maxlength="60" placeholder="Label, e.g. GOAT" style="{{ $rfs }}width:200px;" />
                <input name="key" required maxlength="40" pattern="[a-z][a-z0-9_]*" placeholder="key, e.g. goat" style="{{ $rfs }}width:160px;" />
                <button type="submit" class="uj-btn-primary" style="height:36px;padding:0 16px;font-size:12.5px;"><span x-text="$store.ui.lang==='en'?'Add':'Tambah'">Add</span></button>
            </form>
            @foreach ($reactionSet as $r)
                <div style="display:flex;align-items:center;gap:10px;padding:6px 0;border-bottom:1px solid var(--hairline-soft);" data-reaction-row="{{ $r->key }}">
                    <span style="font-size:18px;line-height:1;width:24px;text-align:center;" aria-hidden="true">{{ $r->icon }}</span>
                    <span style="font-size:12.5px;color:{{ $r->retired_at ? 'var(--muted)' : 'var(--ink)' }};min-width:0;flex:1;">{{ $r->label }} <span style="font-family:var(--font-mono);font-size:11px;color:var(--muted);">{{ $r->key }}</span></span>
                    @if ($r->retired_at)
                        <span style="font-size:11px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'Retired' : 'Bersara'">Retired</span>
                    @else
                        <form method="post" action="{{ route('admin.reactions.retire', $r->key) }}" onsubmit="return confirm('Retire {{ addslashes($r->label) }}? Old items keep showing it.')">@csrf<button type="submit" style="font-size:12px;color:var(--red);" x-text="$store.ui.lang==='en'?'Retire':'Bersarakan'">Retire</button></form>
                    @endif
                </div>
            @endforeach
        </div>
        @endif
    </div>
</div>

@if (!empty($canManageFeatures) && (! $only || $only === 'features'))
<div class="uj-card" style="margin-top:16px;padding:24px;">
    <h3 class="uj-card-title" style="margin-bottom:4px;" x-text="$store.ui.lang==='en' ? 'Features' : 'Ciri'">Features</h3>
    <p style="font-size:13px;color:var(--muted);margin:0 0 16px;"><span x-text="$store.ui.lang==='en' ? 'Turn modules on or off for this company and tune behavioural settings. Features marked' : 'Hidup atau matikan modul untuk syarikat ini dan laras tetapan tingkah laku. Ciri yang ditanda'">Turn modules on or off for this company and tune behavioural settings. Features marked</span> <span style="font-size:11px;font-weight:600;color:#a81820;background:#fbeaeb;border:1px solid #f3c6c8;padding:1px 7px;border-radius:9999px;" x-text="$store.ui.lang==='en' ? 'Locked' : 'Dikunci'">Locked</span> <span x-text="$store.ui.lang==='en' ? 'are set by the platform and cannot be changed here.' : 'ditetapkan oleh platform dan tidak boleh diubah di sini.'">are set by the platform and cannot be changed here.</span></p>
    @include('partials.hint', ['en' => 'Disabling a module hides it from the menu for everyone and blocks its screens. Locked features are controlled centrally by the platform team.', 'ms' => 'Mematikan modul akan menyembunyikannya dari menu untuk semua orang dan menyekat skrinnya. Ciri yang dikunci dikawal secara berpusat oleh pasukan platform.'])

    <form method="post" action="{{ route('admin.features.update') }}">
        @csrf
        <input type="hidden" name="features_present" value="1">

        <div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);margin:18px 0 10px;" x-text="$store.ui.lang==='en' ? 'Modules' : 'Modul'">Modules</div>
        {{-- Grouped by sidebar section so each toggle maps to where it lives in the
             nav. Section heading + the per-toggle "Controls:" caption come from
             AppController::navScreenIndex(). --}}
        @foreach ($featureRows['modules'] as $group)
            <div style="margin-bottom:16px;">
                <div style="font-size:12px;font-weight:600;color:var(--ink);border-bottom:1px solid var(--hairline-soft);padding-bottom:6px;margin-bottom:6px;"
                     x-text="$store.ui.lang==='en' ? @js($group['section']) : @js($group['section_ms'])">{{ $group['section'] }}</div>
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:2px 20px;">
                    @foreach ($group['rows'] as $row)
                        @php
                            $navEn = implode(' · ', array_map(fn ($n) => $n['en'], $row['nav_items']));
                            $navMs = implode(' · ', array_map(fn ($n) => $n['ms'], $row['nav_items']));
                            $showNav = count($row['nav_items']) > 1;
                        @endphp
                        <label style="display:flex;align-items:flex-start;gap:10px;padding:8px 0;cursor:{{ $row['locked'] ? 'not-allowed' : 'pointer' }};">
                            <input type="checkbox" name="features[{{ $row['key'] }}]" value="1" style="margin-top:2px;flex-shrink:0;"
                                @checked(\App\Support\Features::asBool($row['value']))
                                @disabled($row['locked'])>
                            <span style="flex:1;min-width:0;">
                                <span style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                                    <span style="font-size:13.5px;color:var(--ink);" x-text="$store.ui.lang==='en' ? @js($row['label']) : @js($row['label_ms'])">{{ $row['label'] }}</span>
                                    @if ($row['locked'])<span style="font-size:11px;font-weight:600;color:#a81820;background:#fbeaeb;border:1px solid #f3c6c8;padding:1px 7px;border-radius:9999px;" x-text="$store.ui.lang==='en' ? 'Locked' : 'Dikunci'">Locked</span>@endif
                                </span>
                                @if ($showNav)
                                    <span style="display:block;font-size:11px;color:var(--muted);margin-top:1px;line-height:1.4;"
                                          x-text="$store.ui.lang==='en' ? @js('Controls: '.$navEn) : @js('Mengawal: '.$navMs)">Controls: {{ $navEn }}</span>
                                @endif
                            </span>
                        </label>
                    @endforeach
                </div>
            </div>
        @endforeach

        <div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);margin:24px 0 10px;" x-text="$store.ui.lang==='en' ? 'Settings' : 'Tetapan'">Settings</div>
        <div style="display:flex;flex-direction:column;gap:16px;max-width:560px;">
            @foreach ($featureRows['settings'] as $row)
                <div style="display:flex;align-items:flex-start;gap:14px;">
                    <div style="flex:1;">
                        <div style="display:flex;align-items:center;gap:8px;">
                            <span style="font-size:13.5px;font-weight:500;color:var(--ink);" x-text="$store.ui.lang==='en' ? @js($row['label']) : @js($row['label_ms'])">{{ $row['label'] }}</span>
                            @if ($row['locked'])<span style="font-size:11px;font-weight:600;color:#a81820;background:#fbeaeb;border:1px solid #f3c6c8;padding:1px 7px;border-radius:9999px;" x-text="$store.ui.lang==='en' ? 'Locked' : 'Dikunci'">Locked</span>@endif
                        </div>
                        @if (!empty($row['help']))<div style="font-size:12px;color:var(--muted);margin-top:2px;" x-text="$store.ui.lang==='en' ? @js($row['help']) : @js($row['help_ms'])">{{ $row['help'] }}</div>@endif
                    </div>
                    <div style="width:200px;flex-shrink:0;">
                        @if ($row['type'] === 'enum')
                            <select name="features[{{ $row['key'] }}]" @disabled($row['locked'])
                                style="width:100%;height:38px;padding:0 10px;border:1px solid var(--hairline);border-radius:8px;font-size:13.5px;background:{{ $row['locked'] ? 'var(--hairline-soft)' : '#fff' }};color:var(--ink);">
                                @foreach ($row['options'] as $val => $optLabel)
                                    <option value="{{ $val }}" @selected((string) $row['value'] === (string) $val) x-text="$store.ui.lang==='en' ? @js($optLabel) : @js($row['options_ms'][$val] ?? $optLabel)">{{ $optLabel }}</option>
                                @endforeach
                            </select>
                        @elseif ($row['type'] === 'number')
                            <input type="number" name="features[{{ $row['key'] }}]" value="{{ $row['value'] }}" @disabled($row['locked'])
                                step="1" min="{{ $row['min'] ?? 0 }}" @if (! is_null($row['max']))max="{{ $row['max'] }}"@endif
                                style="width:100%;height:38px;padding:0 10px;border:1px solid var(--hairline);border-radius:8px;font-size:13.5px;font-family:var(--font-mono);background:{{ $row['locked'] ? 'var(--hairline-soft)' : '#fff' }};color:var(--ink);">
                        @else
                            <label style="display:inline-flex;align-items:center;gap:8px;font-size:13px;color:var(--ink);cursor:{{ $row['locked'] ? 'not-allowed' : 'pointer' }};">
                                <input type="checkbox" name="features[{{ $row['key'] }}]" value="1"
                                    @checked(\App\Support\Features::asBool($row['value']))
                                    @disabled($row['locked'])>
                                <span x-text="$store.ui.lang==='en' ? 'Enabled' : 'Dihidupkan'">Enabled</span>
                            </label>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>

        <button type="submit" class="uj-btn-primary" style="height:42px;padding:0 20px;font-size:13.5px;margin-top:22px;"><span x-text="$store.ui.lang==='en' ? 'Save features' : 'Simpan ciri'">Save features</span></button>
        @include('partials.coachmark', [
            'key' => 'guide-modules',
            'when' => "\$store.guide.current === 'modules'",
            'en' => ['title' => 'Switch on what you use', 'body' => 'Tick the modules your company uses, then click Save features. Untick the ones you don\'t need.'],
            'ms' => ['title' => 'Hidupkan yang anda guna', 'body' => 'Tandakan modul yang syarikat anda guna, kemudian klik Simpan ciri. Buang tanda pada modul yang tidak perlu.'],
        ])
    </form>
</div>
@endif
@endsection
