@extends('layouts.wizard')

@php
    $groups = collect($completion['groups']);
    $essentialDone = $completion['essentialDone'];
    $bankGroup = $groups->firstWhere('key', 'bank');
    $bankDone = $bankGroup['done'] ?? true;          // absent when payroll is off → treat as done
    $certDone = $certificates->isNotEmpty();

    // Resume at the first outstanding step.
    $start = 'personal';
    if ($essentialDone) {
        if ($payrollEnabled && ! $bankDone)      $start = 'bank';
        elseif (! $certDone)                      $start = 'cert';
        elseif (! $personalityDone)               $start = 'personality';
        else                                      $start = 'done';
    }

    $inp = 'width:100%;padding:9px 12px;border:1px solid var(--hairline);border-radius:8px;font-size:13.5px;color:var(--ink);background:#fff;';
    $lbl = 'display:block;font-size:12.5px;font-weight:600;color:var(--ink);margin:0 0 5px;';

    // Step index for the checklist rail.
    $steps = [['key' => 'personal', 'n' => 1, 'en' => 'Your details', 'ms' => 'Butiran anda', 'done' => $essentialDone]];
    if ($payrollEnabled) $steps[] = ['key' => 'bank', 'n' => count($steps) + 1, 'en' => 'Bank & statutory', 'ms' => 'Bank & berkanun', 'done' => $bankDone];
    $steps[] = ['key' => 'cert', 'n' => count($steps) + 1, 'en' => 'Certificates', 'ms' => 'Sijil', 'done' => $certDone];
    $steps[] = ['key' => 'personality', 'n' => count($steps) + 1, 'en' => 'Personality', 'ms' => 'Personaliti', 'done' => $personalityDone];
@endphp

@section('screen')
<div x-data="{ step: @js($start) }">

    {{-- Heading + overall progress --}}
    <div style="margin-bottom:20px;">
        <h1 style="font-size:23px;font-weight:600;color:var(--ink);margin:0 0 6px;"
            x-text="$store.ui.lang==='en' ? 'Welcome, {{ $employee->name }}' : 'Selamat datang, {{ $employee->name }}'">Welcome, {{ $employee->name }}</h1>
        <p style="font-size:13.5px;color:var(--muted);margin:0 0 12px;"
           x-text="$store.ui.lang==='en'
                ? 'Let\'s finish setting up your profile. It only takes a few minutes.'
                : 'Mari lengkapkan profil anda. Ia hanya mengambil beberapa minit.'">Let's finish setting up your profile. It only takes a few minutes.</p>
        <div style="display:flex;align-items:center;gap:12px;max-width:420px;">
            <div class="uj-progress" style="flex:1;"><span style="width:{{ $completion['pct'] }}%;background:{{ $completion['complete'] ? 'var(--success)' : 'var(--red)' }};"></span></div>
            <span style="font-size:13px;font-weight:600;color:var(--ink);font-family:var(--font-mono);">{{ $completion['pct'] }}%</span>
        </div>
    </div>

    <div class="wz-layout">

        {{-- Step rail --}}
        <nav class="uj-card" style="padding:10px;position:sticky;top:0;">
            @foreach ($steps as $s)
                <button type="button" @click="step=@js($s['key'])"
                        :style="step===@js($s['key']) ? { background:'var(--canvas)' } : { background:'transparent' }"
                        style="width:100%;display:flex;align-items:center;gap:10px;padding:10px 11px;border-radius:9px;cursor:pointer;text-align:left;">
                    <span style="width:22px;height:22px;flex-shrink:0;border-radius:9999px;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;{{ $s['done'] ? 'background:var(--success);color:#fff;' : 'background:var(--hairline-soft);color:var(--muted);' }}">
                        @if ($s['done'])
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"></path></svg>
                        @else{{ $s['n'] }}@endif
                    </span>
                    <span style="font-size:13px;font-weight:500;color:var(--ink);" x-text="$store.ui.lang==='en' ? @js($s['en']) : @js($s['ms'])">{{ $s['en'] }}</span>
                </button>
            @endforeach
        </nav>

        {{-- Panels --}}
        <div>
            {{-- 1 · Personal details (essential: identity + contact/emergency) --}}
            <section x-show="step==='personal'" x-cloak class="uj-card" style="padding:20px 22px;">
                <h2 style="font-size:15px;font-weight:600;color:var(--ink);margin:0 0 3px;" x-text="$store.ui.lang==='en' ? 'Your details' : 'Butiran anda'">Your details</h2>
                <p style="font-size:12.5px;color:var(--muted);margin:0 0 16px;" x-text="$store.ui.lang==='en' ? 'Required before you can start using Amanahku.' : 'Diperlukan sebelum anda boleh mula guna Amanahku.'">Required before you can start using Amanahku.</p>
                <form method="POST" action="{{ route('welcome.personal') }}">
                    @csrf
                    <div class="wz-grid2">
                        <div><label style="{{ $lbl }}" x-text="$store.ui.lang==='en' ? 'NRIC / IC no.' : 'No. K/P (NRIC)'">NRIC / IC no.</label><input name="nric" value="{{ old('nric', $employee->nric) }}" style="{{ $inp }}" required></div>
                        <div><label style="{{ $lbl }}" x-text="$store.ui.lang==='en' ? 'Date of birth' : 'Tarikh lahir'">Date of birth</label><input type="date" name="date_of_birth" value="{{ old('date_of_birth', optional($employee->date_of_birth)->format('Y-m-d')) }}" style="{{ $inp }}" required></div>
                        <div>
                            <label style="{{ $lbl }}" x-text="$store.ui.lang==='en' ? 'Gender' : 'Jantina'">Gender</label>
                            <select name="gender" style="{{ $inp }}" required>
                                <option value="">—</option>
                                <option value="male" @selected(old('gender', $employee->gender)==='male') x-text="$store.ui.lang==='en' ? 'Male' : 'Lelaki'">Male</option>
                                <option value="female" @selected(old('gender', $employee->gender)==='female') x-text="$store.ui.lang==='en' ? 'Female' : 'Perempuan'">Female</option>
                            </select>
                        </div>
                        <div>
                            <label style="{{ $lbl }}" x-text="$store.ui.lang==='en' ? 'Marital status' : 'Status perkahwinan'">Marital status</label>
                            <select name="marital_status" style="{{ $inp }}" required>
                                <option value="">—</option>
                                @foreach (['single' => ['Single', 'Bujang'], 'married' => ['Married', 'Berkahwin'], 'divorced' => ['Divorced', 'Bercerai'], 'widowed' => ['Widowed', 'Balu / Duda']] as $v => $t)
                                    <option value="{{ $v }}" @selected(old('marital_status', $employee->marital_status)===$v) x-text="$store.ui.lang==='en' ? @js($t[0]) : @js($t[1])">{{ $t[0] }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div><label style="{{ $lbl }}" x-text="$store.ui.lang==='en' ? 'Phone' : 'Telefon'">Phone</label><input name="phone" value="{{ old('phone', $employee->phone) }}" style="{{ $inp }}" required></div>
                        <div style="grid-column:1 / -1;"><label style="{{ $lbl }}" x-text="$store.ui.lang==='en' ? 'Home address' : 'Alamat rumah'">Home address</label><textarea name="address" rows="2" style="{{ $inp }}" required>{{ old('address', $employee->address) }}</textarea></div>
                        <div><label style="{{ $lbl }}" x-text="$store.ui.lang==='en' ? 'Emergency contact name' : 'Nama waris kecemasan'">Emergency contact name</label><input name="emergency_contact_name" value="{{ old('emergency_contact_name', $employee->emergency_contact_name) }}" style="{{ $inp }}" required></div>
                        <div><label style="{{ $lbl }}" x-text="$store.ui.lang==='en' ? 'Emergency contact phone' : 'Telefon waris kecemasan'">Emergency contact phone</label><input name="emergency_contact_phone" value="{{ old('emergency_contact_phone', $employee->emergency_contact_phone) }}" style="{{ $inp }}" required></div>
                    </div>
                    @include('partials.hint', ['en' => 'Your NRIC is stored encrypted and only used for payroll and statutory reports.', 'ms' => 'NRIC anda disimpan tersulit dan hanya digunakan untuk gaji dan laporan berkanun.'])
                    <div style="margin-top:16px;display:flex;justify-content:flex-end;">
                        <button type="submit" class="uj-btn-primary" style="padding:9px 18px;" x-text="$store.ui.lang==='en' ? 'Save & continue' : 'Simpan & teruskan'">Save & continue</button>
                    </div>
                </form>
            </section>

            {{-- 2 · Bank & statutory (payroll only) --}}
            @if ($payrollEnabled)
            <section x-show="step==='bank'" x-cloak class="uj-card" style="padding:20px 22px;">
                <h2 style="font-size:15px;font-weight:600;color:var(--ink);margin:0 0 3px;" x-text="$store.ui.lang==='en' ? 'Bank & statutory' : 'Bank & berkanun'">Bank & statutory</h2>
                <p style="font-size:12.5px;color:var(--muted);margin:0 0 16px;" x-text="$store.ui.lang==='en' ? 'Needed before your first payroll run.' : 'Diperlukan sebelum gaji pertama anda dijalankan.'">Needed before your first payroll run.</p>
                @unless ($essentialDone)
                    <p style="font-size:12.5px;color:var(--amber);margin:0 0 12px;" x-text="$store.ui.lang==='en' ? 'Finish “Your details” first.' : 'Lengkapkan “Butiran anda” dahulu.'">Finish “Your details” first.</p>
                @else
                <form method="POST" action="{{ route('welcome.bank') }}">
                    @csrf
                    <div class="wz-grid2">
                        <div><label style="{{ $lbl }}" x-text="$store.ui.lang==='en' ? 'Bank name' : 'Nama bank'">Bank name</label><input name="bank_name" value="{{ old('bank_name', $salary->bank_name ?? '') }}" style="{{ $inp }}" required></div>
                        <div><label style="{{ $lbl }}" x-text="$store.ui.lang==='en' ? 'Bank account no.' : 'No. akaun bank'">Bank account no.</label><input name="bank_account_no" value="{{ old('bank_account_no', $salary->bank_account_no ?? '') }}" style="{{ $inp }}" required></div>
                        <div><label style="{{ $lbl }}" x-text="$store.ui.lang==='en' ? 'EPF no.' : 'No. KWSP'">EPF no.</label><input name="epf_no" value="{{ old('epf_no', $salary->epf_no ?? '') }}" style="{{ $inp }}" required></div>
                        <div><label style="{{ $lbl }}" x-text="$store.ui.lang==='en' ? 'SOCSO no.' : 'No. PERKESO'">SOCSO no.</label><input name="socso_no" value="{{ old('socso_no', $salary->socso_no ?? '') }}" style="{{ $inp }}" required></div>
                    </div>
                    <div style="margin-top:16px;display:flex;justify-content:flex-end;">
                        <button type="submit" class="uj-btn-primary" style="padding:9px 18px;" x-text="$store.ui.lang==='en' ? 'Save & continue' : 'Simpan & teruskan'">Save & continue</button>
                    </div>
                </form>
                @endunless
            </section>
            @endif

            {{-- 3 · Certificates --}}
            <section x-show="step==='cert'" x-cloak class="uj-card" style="padding:20px 22px;">
                <h2 style="font-size:15px;font-weight:600;color:var(--ink);margin:0 0 3px;" x-text="$store.ui.lang==='en' ? 'Certificates' : 'Sijil'">Certificates</h2>
                <p style="font-size:12.5px;color:var(--muted);margin:0 0 16px;" x-text="$store.ui.lang==='en' ? 'Upload your qualifications (degree, professional certs).' : 'Muat naik kelayakan anda (ijazah, sijil profesional).'">Upload your qualifications.</p>
                @if ($certificates->isNotEmpty())
                    <div style="margin-bottom:14px;display:flex;flex-direction:column;gap:6px;">
                        @foreach ($certificates as $c)
                            <div style="display:flex;align-items:center;gap:8px;font-size:12.5px;color:var(--body);background:var(--canvas);border-radius:8px;padding:8px 11px;">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--success)" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"></path></svg>
                                <span>{{ $c->title }}</span>
                                <span style="color:var(--muted-soft);">· {{ $c->original_name }}</span>
                            </div>
                        @endforeach
                    </div>
                @endif
                <form method="POST" action="{{ route('welcome.certificate') }}" enctype="multipart/form-data">
                    @csrf
                    <div class="wz-grid2">
                        <div><label style="{{ $lbl }}" x-text="$store.ui.lang==='en' ? 'Title' : 'Tajuk'">Title</label><input name="title" value="{{ old('title') }}" placeholder="e.g. Bachelor of Computer Science" style="{{ $inp }}" required></div>
                        <div><label style="{{ $lbl }}" x-text="$store.ui.lang==='en' ? 'File' : 'Fail'">File</label><input type="file" name="file" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" style="{{ $inp }}" required></div>
                    </div>
                    @include('partials.hint', ['en' => 'PDF, image or Word document, up to 8 MB.', 'ms' => 'PDF, imej atau dokumen Word, sehingga 8 MB.'])
                    <div style="margin-top:16px;display:flex;justify-content:space-between;align-items:center;gap:10px;">
                        <button type="button" @click="step='personality'" class="uj-btn-ghost" style="padding:9px 16px;" x-text="$store.ui.lang==='en' ? 'Skip for now' : 'Langkau dahulu'">Skip for now</button>
                        <button type="submit" class="uj-btn-primary" style="padding:9px 18px;" x-text="$store.ui.lang==='en' ? 'Upload' : 'Muat naik'">Upload</button>
                    </div>
                </form>
            </section>

            {{-- 4 · Personality --}}
            <section x-show="step==='personality'" x-cloak class="uj-card" style="padding:20px 22px;">
                <h2 style="font-size:15px;font-weight:600;color:var(--ink);margin:0 0 3px;" x-text="$store.ui.lang==='en' ? 'Personality test' : 'Ujian personaliti'">Personality test</h2>
                <p style="font-size:12.5px;color:var(--muted);margin:0 0 16px;" x-text="$store.ui.lang==='en' ? 'A short instrument that helps your team understand how you work best.' : 'Ujian ringkas yang membantu pasukan memahami cara kerja terbaik anda.'">A short instrument that helps your team understand how you work best.</p>
                @if ($personalityDone)
                    <div style="display:flex;align-items:center;gap:8px;font-size:13px;color:#176e51;background:#e7f4ee;border-radius:9px;padding:11px 14px;">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"></path></svg>
                        <span x-text="$store.ui.lang==='en' ? 'Completed. You can retake it any time from your profile.' : 'Selesai. Anda boleh ambil semula bila-bila masa dari profil.'">Completed.</span>
                    </div>
                @else
                    <a href="{{ route('app.screen', 'profile-test') }}" class="uj-btn-primary" style="display:inline-block;padding:9px 18px;text-decoration:none;" x-text="$store.ui.lang==='en' ? 'Take the test' : 'Ambil ujian'">Take the test</a>
                @endif
                <div style="margin-top:16px;">
                    <button type="button" @click="step='done'" class="uj-btn-ghost" style="padding:9px 16px;" x-text="$store.ui.lang==='en' ? 'Continue' : 'Teruskan'">Continue</button>
                </div>
            </section>

            {{-- 5 · Done --}}
            <section x-show="step==='done'" x-cloak class="uj-card" style="padding:26px 22px;text-align:center;">
                <div style="width:52px;height:52px;border-radius:9999px;background:{{ $completion['complete'] ? '#e7f4ee' : 'var(--red-tint)' }};display:flex;align-items:center;justify-content:center;margin:0 auto 14px;">
                    <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="{{ $completion['complete'] ? 'var(--success)' : 'var(--red)' }}" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"></path></svg>
                </div>
                @if ($completion['complete'])
                    <h2 style="font-size:17px;font-weight:600;color:var(--ink);margin:0 0 6px;" x-text="$store.ui.lang==='en' ? 'All done!' : 'Semua selesai!'">All done!</h2>
                    <p style="font-size:13px;color:var(--muted);margin:0 0 18px;" x-text="$store.ui.lang==='en' ? 'Your profile is 100% complete.' : 'Profil anda 100% lengkap.'">Your profile is 100% complete.</p>
                @else
                    <h2 style="font-size:17px;font-weight:600;color:var(--ink);margin:0 0 6px;" x-text="$store.ui.lang==='en' ? 'You\'re good to go' : 'Anda boleh mula'">You're good to go</h2>
                    <p style="font-size:13px;color:var(--muted);margin:0 0 18px;" x-text="$store.ui.lang==='en' ? 'You can finish the remaining items any time — we\'ll remind you on your dashboard.' : 'Anda boleh lengkapkan item berbaki bila-bila masa — kami akan ingatkan di papan pemuka.'">You can finish the rest any time.</p>
                @endif
                <form method="POST" action="{{ route('welcome.finish') }}">
                    @csrf
                    <button type="submit" class="uj-btn-primary" style="padding:10px 22px;" x-text="$store.ui.lang==='en' ? 'Go to dashboard' : 'Ke papan pemuka'">Go to dashboard</button>
                </form>
            </section>
        </div>
    </div>
</div>
@endsection
