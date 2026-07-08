@extends('layouts.app')

@php
    $typeLabel = ['medical' => 'Medical', 'dental' => 'Dental', 'life' => 'Life', 'other' => 'Other'];
    $typeColor = ['medical' => 'var(--info)', 'dental' => 'var(--success)', 'life' => 'var(--amber)', 'other' => 'var(--muted)'];
@endphp

@section('screen')
@include('partials.guide', [
    'key' => 'benefits',
    'en'  => [
        'title' => 'Benefits enrolment',
        'body'  => 'Review the insurance and benefit plans the company offers, then choose to enrol or waive each one. These are extra benefits on top of statutory EPF/SOCSO — they are optional and yours to decide.',
        'who'   => 'Staff enrol or waive · HR add plans',
        'steps' => [
            'Read each plan on the left — its type, provider, monthly cost and what it covers.',
            'For each plan, pick Enrol (you want it) or Waive (you do not). You make one choice per plan.',
            'If enrolling, add how many dependents (family members) to cover.',
            'Click Save — your choice is recorded and can be updated later.',
        ],
    ],
    'ms'  => [
        'title' => 'Pendaftaran manfaat',
        'body'  => 'Semak pelan insurans dan manfaat yang ditawarkan syarikat, kemudian pilih untuk daftar atau tolak setiap satu. Ini manfaat tambahan di atas EPF/SOCSO berkanun — ia pilihan dan terpulang kepada anda.',
        'who'   => 'Staf daftar atau tolak · HR tambah pelan',
        'steps' => [
            'Baca setiap pelan di sebelah kiri — jenisnya, pembekal, kos bulanan dan apa yang dilindungi.',
            'Bagi setiap pelan, pilih Enrol (anda mahu) atau Waive (anda tidak mahu). Satu pilihan setiap pelan.',
            'Jika mendaftar, masukkan berapa tanggungan (ahli keluarga) untuk dilindungi.',
            'Klik Save — pilihan anda direkodkan dan boleh dikemas kini kemudian.',
        ],
    ],
])
@if (session('ok'))
    <div style="background:#e7f4ee;border:1px solid var(--success);color:var(--success);font-size:12.5px;border-radius:8px;padding:10px 14px;margin-bottom:16px;">{{ session('ok') }}</div>
@endif

<div style="display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap;">
    {{-- Available plans + enroll/waive --}}
    <div style="flex:2;min-width:340px;display:flex;flex-direction:column;gap:16px;">
        <div class="uj-card" style="padding:0;">
            <div class="uj-card-head">
                <h3 class="uj-card-title"><span x-text="$store.ui.lang==='en' ? 'Available benefit plans' : 'Pelan manfaat tersedia'">Available benefit plans</span></h3>
                <span style="font-size:12.5px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'Enroll or waive — one choice per plan' : 'Daftar atau tolak — satu pilihan setiap pelan'">Enroll or waive — one choice per plan</span>
            </div>
            @forelse ($plans as $plan)
                @php $mine = $myEnrollments->get($plan->id); @endphp
                <div style="padding:18px 20px;border-bottom:1px solid var(--hairline-soft);">
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:5px;flex-wrap:wrap;">
                        <span class="uj-pill" style="background:var(--hairline-soft);color:{{ $typeColor[$plan->type] ?? 'var(--muted)' }};">{{ $typeLabel[$plan->type] ?? ucfirst($plan->type) }}</span>
                        <span style="font-size:13.5px;font-weight:600;color:var(--ink);">{{ $plan->name }}</span>
                        @if ($plan->provider)
                            <span style="font-size:12px;color:var(--muted);">· {{ $plan->provider }}</span>
                        @endif
                        @if (! is_null($plan->monthly_cost))
                            <span style="margin-left:auto;font-size:13px;font-weight:600;font-family:var(--font-mono);color:var(--ink);">RM {{ number_format((float) $plan->monthly_cost, 2) }}<span style="font-size:11px;color:var(--muted);font-weight:400;">/mo</span></span>
                        @endif
                    </div>
                    @if ($plan->coverage)
                        <p style="font-size:13px;color:var(--muted);margin:0 0 12px;">{{ $plan->coverage }}</p>
                    @endif

                    @if ($mine)
                        @if ($mine->status === 'enrolled')
                            <div style="font-size:12.5px;color:var(--success);font-weight:500;margin-bottom:10px;"><span x-text="$store.ui.lang==='en' ? '✓ Enrolled' : '✓ Didaftarkan'">✓ Enrolled</span>{{ $mine->dependents > 0 ? ' · '.$mine->dependents.' dependent'.($mine->dependents === 1 ? '' : 's') : '' }}</div>
                        @else
                            <div style="font-size:12.5px;color:var(--muted);font-weight:500;margin-bottom:10px;" x-text="$store.ui.lang==='en' ? 'Waived' : 'Ditolak'">Waived</div>
                        @endif
                    @endif

                    @if (! $canEnroll)
                        <div style="font-size:12.5px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'No employee profile in this workspace — enrollment is disabled.' : 'Tiada profil pekerja dalam ruang kerja ini — pendaftaran dimatikan.'">No employee profile in this workspace — enrollment is disabled.</div>
                    @else
                        <form method="post" action="{{ route('benefits.enroll', $plan) }}" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
                            @csrf
                            <div>
                                <label style="display:block;font-size:11.5px;font-weight:500;color:var(--muted);margin-bottom:4px;" x-text="$store.ui.lang==='en' ? 'Decision' : 'Keputusan'">Decision</label>
                                <select name="status" style="height:38px;padding:0 11px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;background:#fff;color:var(--ink);">
                                    <option value="enrolled" @selected(old('status', $mine?->status ?? 'enrolled') === 'enrolled') x-text="$store.ui.lang==='en' ? 'Enroll' : 'Daftar'">Enroll</option>
                                    <option value="waived" @selected(old('status', $mine?->status) === 'waived') x-text="$store.ui.lang==='en' ? 'Waive' : 'Tolak'">Waive</option>
                                </select>
                            </div>
                            <div>
                                <label style="display:block;font-size:11.5px;font-weight:500;color:var(--muted);margin-bottom:4px;" x-text="$store.ui.lang==='en' ? 'Dependents' : 'Tanggungan'">Dependents</label>
                                <input type="number" name="dependents" min="0" max="20" value="{{ old('dependents', $mine->dependents ?? 0) }}" style="width:90px;height:38px;padding:0 11px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;outline:none;" />
                            </div>
                            <button type="submit" class="uj-btn-primary" style="height:38px;padding:0 18px;font-size:13px;" x-text="@js($mine ? true : false) ? ($store.ui.lang==='en' ? 'Update' : 'Kemas kini') : ($store.ui.lang==='en' ? 'Save' : 'Simpan')">{{ $mine ? 'Update' : 'Save' }}</button>
                            <div style="flex-basis:100%;">@include('partials.hint', ['en' => 'Choose Enrol to take this plan or Waive to skip it. Dependents = family members (spouse/children) you want covered — leave at 0 if none.', 'ms' => 'Pilih Enrol untuk ambil pelan ini atau Waive untuk langkau. Tanggungan = ahli keluarga (pasangan/anak) yang anda mahu dilindungi — biar 0 jika tiada.'])</div>
                        </form>
                    @endif
                </div>
            @empty
                <div style="padding:28px 20px;text-align:center;">
                    <div style="font-size:13px;color:var(--ink);font-weight:500;margin-bottom:3px;"><span x-text="$store.ui.lang==='en' ? 'No benefit plans yet' : 'Belum ada pelan manfaat'"></span></div>
                    <div style="font-size:12px;color:var(--muted);line-height:1.5;">@if ($privileged)<span x-text="$store.ui.lang==='en' ? 'Use \'Add benefit plan\' on the right to create the first one. Plans you add show up here for staff to enrol.' : 'Guna \'Add benefit plan\' di sebelah kanan untuk cipta yang pertama. Pelan yang ditambah muncul di sini untuk staf daftar.'"></span>@else <span x-text="$store.ui.lang==='en' ? 'No plans have been set up yet. Once HR adds them, you can enrol from here.' : 'Belum ada pelan disediakan. Sebaik HR menambahnya, anda boleh daftar dari sini.'"></span>@endif</div>
                </div>
            @endforelse
        </div>
    </div>

    {{-- Privileged: add a plan + enrollment counts --}}
    <div style="flex:1;min-width:300px;display:flex;flex-direction:column;gap:16px;">
        @if ($privileged)
            <div class="uj-card" style="padding:20px;">
                <h3 class="uj-card-title" style="margin-bottom:14px;"><span x-text="$store.ui.lang==='en' ? 'Add benefit plan' : 'Tambah pelan manfaat'">Add benefit plan</span></h3>
                <form method="post" action="{{ route('benefits.plans') }}">
                    @csrf
                    @if ($errors->any())
                        <div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12px;border-radius:8px;padding:9px 12px;margin-bottom:14px;">{{ $errors->first() }}</div>
                    @endif

                    <label style="display:block;font-size:12.5px;font-weight:500;color:var(--ink);margin-bottom:5px;" x-text="$store.ui.lang==='en' ? 'Plan name' : 'Nama pelan'">Plan name</label>
                    <input name="name" value="{{ old('name') }}" required maxlength="120" placeholder="e.g. AIA Medical Premier" :placeholder="$store.ui.lang==='en' ? 'e.g. AIA Medical Premier' : 'cth. AIA Medical Premier'" style="width:100%;height:40px;padding:0 11px;border:1px solid var(--hairline);border-radius:8px;font-size:13.5px;margin-bottom:13px;outline:none;" />

                    <label style="display:block;font-size:12.5px;font-weight:500;color:var(--ink);margin-bottom:5px;" x-text="$store.ui.lang==='en' ? 'Type' : 'Jenis'">Type</label>
                    <select name="type" required style="width:100%;height:40px;padding:0 11px;border:1px solid var(--hairline);border-radius:8px;font-size:13.5px;background:#fff;color:var(--ink);margin-bottom:13px;">
                        <option value="medical" @selected(old('type') === 'medical') x-text="$store.ui.lang==='en' ? 'Medical' : 'Perubatan'">Medical</option>
                        <option value="dental" @selected(old('type') === 'dental') x-text="$store.ui.lang==='en' ? 'Dental' : 'Pergigian'">Dental</option>
                        <option value="life" @selected(old('type') === 'life') x-text="$store.ui.lang==='en' ? 'Life insurance' : 'Insurans nyawa'">Life insurance</option>
                        <option value="other" @selected(old('type') === 'other') x-text="$store.ui.lang==='en' ? 'Other' : 'Lain-lain'">Other</option>
                    </select>

                    <label style="display:block;font-size:12.5px;font-weight:500;color:var(--ink);margin-bottom:5px;" x-text="$store.ui.lang==='en' ? 'Provider' : 'Penyedia'">Provider</label>
                    <input name="provider" value="{{ old('provider') }}" maxlength="120" placeholder="Optional — e.g. AIA, Great Eastern" :placeholder="$store.ui.lang==='en' ? 'Optional — e.g. AIA, Great Eastern' : 'Pilihan — cth. AIA, Great Eastern'" style="width:100%;height:40px;padding:0 11px;border:1px solid var(--hairline);border-radius:8px;font-size:13.5px;margin-bottom:13px;outline:none;" />

                    <label style="display:block;font-size:12.5px;font-weight:500;color:var(--ink);margin-bottom:5px;" x-text="$store.ui.lang==='en' ? 'Monthly cost (RM)' : 'Kos bulanan (RM)'">Monthly cost (RM)</label>
                    <input type="number" name="monthly_cost" value="{{ old('monthly_cost') }}" step="0.01" min="0" placeholder="Optional" :placeholder="$store.ui.lang==='en' ? 'Optional' : 'Pilihan'" style="width:100%;height:40px;padding:0 11px;border:1px solid var(--hairline);border-radius:8px;font-size:13.5px;margin-bottom:13px;outline:none;" />

                    <label style="display:block;font-size:12.5px;font-weight:500;color:var(--ink);margin-bottom:5px;" x-text="$store.ui.lang==='en' ? 'Coverage' : 'Perlindungan'">Coverage</label>
                    <textarea name="coverage" maxlength="1000" rows="2" placeholder="Optional — what the plan covers" :placeholder="$store.ui.lang==='en' ? 'Optional — what the plan covers' : 'Pilihan — apa yang dilindungi pelan ini'" style="width:100%;padding:9px 11px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;resize:vertical;outline:none;margin-bottom:16px;font-family:inherit;">{{ old('coverage') }}</textarea>

                    <button type="submit" class="uj-btn-primary" style="width:100%;height:42px;font-size:13.5px;" x-text="$store.ui.lang==='en' ? 'Add plan' : 'Tambah pelan'">Add plan</button>
                </form>
            </div>

            <div class="uj-card" style="padding:20px;">
                <h3 class="uj-card-title" style="margin-bottom:3px;"><span x-text="$store.ui.lang==='en' ? 'Enrollment counts' : 'Bilangan pendaftaran'">Enrollment counts</span></h3>
                <p style="font-size:12px;color:var(--muted);margin:0 0 14px;" x-text="$store.ui.lang==='en' ? 'Employees actively enrolled per plan.' : 'Pekerja yang aktif mendaftar setiap pelan.'">Employees actively enrolled per plan.</p>
                @forelse ($allPlans as $plan)
                    <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;padding:11px 0;border-bottom:1px solid var(--hairline-soft);">
                        <div style="min-width:0;">
                            <div style="font-size:13px;font-weight:600;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ $plan->name }}</div>
                            <div style="font-size:11.5px;color:var(--muted);">{{ $typeLabel[$plan->type] ?? ucfirst($plan->type) }}@unless ($plan->active) · <span x-text="$store.ui.lang==='en' ? 'inactive' : 'tidak aktif'">inactive</span> @endunless</div>
                        </div>
                        <span style="font-size:14px;font-weight:600;font-family:var(--font-mono);color:var(--ink);flex-shrink:0;">{{ $plan->enrolled_count }}</span>
                    </div>
                @empty
                    <div style="font-size:12.5px;color:var(--muted);line-height:1.5;"><span x-text="$store.ui.lang==='en' ? 'No plans created yet. Add one with the form above — enrolment numbers will appear here as staff sign up.' : 'Belum ada pelan dicipta. Tambah satu dengan borang di atas — bilangan pendaftaran akan muncul di sini apabila staf mendaftar.'"></span></div>
                @endforelse
            </div>
        @endif
    </div>
</div>
@endsection
