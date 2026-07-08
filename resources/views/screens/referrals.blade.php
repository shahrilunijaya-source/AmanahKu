@extends('layouts.app')

@php
    // Pipeline status colours — submitted=amber, reviewing/interviewing=info, hired=success, rejected=error.
    $sc = [
        'submitted' => 'var(--amber)',
        'reviewing' => 'var(--info)',
        'interviewing' => 'var(--info)',
        'hired' => 'var(--success)',
        'rejected' => 'var(--error)',
    ];
    $bc = ['none' => 'var(--muted)', 'pending' => 'var(--amber)', 'paid' => 'var(--success)'];
    $hiredCount = $myReferrals->where('status', 'hired')->count();
@endphp

@section('screen')
@include('partials.guide', [
    'key' => 'referrals',
    'en'  => [
        'title' => 'Employee referrals',
        'body'  => 'Refer someone you know for an open role. HR reviews each referral and moves it through the hiring pipeline. If your candidate is hired and the referral qualifies, a referral bonus is tracked here.',
        'who'   => 'Staff refer · HR & management manage status and bonus',
        'steps' => [
            'Enter the candidate\'s name and email, and optionally pick an open role and add a resume link.',
            'Submit — it shows as "Submitted" until HR starts reviewing it.',
            'Track your referral as it moves through reviewing, interviewing, and a final decision.',
            'If hired and eligible, the referral bonus status (pending or paid) appears against your referral.',
        ],
    ],
    'ms'  => [
        'title' => 'Rujukan pekerja (referral)',
        'body'  => 'Rujuk seseorang yang anda kenal untuk jawatan yang dibuka. HR semak setiap referral dan gerakkannya melalui proses pengambilan. Jika calon anda diambil bekerja dan referral layak, bonus referral akan direkod di sini.',
        'who'   => 'Staf rujuk · HR & pengurusan urus status dan bonus',
        'steps' => [
            'Masukkan nama dan email calon, dan jika ada pilih jawatan yang dibuka serta tambah pautan resume.',
            'Hantar — ia akan kekal "Submitted" sehingga HR mula menyemak.',
            'Jejak referral anda sepanjang ia bergerak melalui semakan, temuduga, dan keputusan akhir.',
            'Jika diambil dan layak, status bonus referral (pending atau paid) muncul pada referral anda.',
        ],
    ],
])
<div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:16px;">
    <div class="uj-card uj-stat" style="flex:1;min-width:180px;"><div class="uj-stat-label"><span x-text="$store.ui.lang==='en' ? 'My referrals' : 'Rujukan saya'">My referrals</span></div><div class="uj-stat-value">{{ $myReferrals->count() }}</div></div>
    <div class="uj-card uj-stat" style="flex:1;min-width:180px;"><div class="uj-stat-label"><span x-text="$store.ui.lang==='en' ? 'Hired' : 'Diambil'">Hired</span></div><div class="uj-stat-value" style="color:var(--success);">{{ $hiredCount }}</div></div>
    <div class="uj-card uj-stat" style="flex:1;min-width:180px;"><div class="uj-stat-label"><span x-text="$store.ui.lang==='en' ? 'Open roles' : 'Jawatan dibuka'">Open roles</span></div><div class="uj-stat-value">{{ $openRoles->count() }}</div></div>
</div>

@if ($privileged && $allReferrals->isNotEmpty())
    <div class="uj-card" style="margin-bottom:16px;">
        <div class="uj-card-head"><h3 class="uj-card-title"><span x-text="$store.ui.lang==='en' ? 'All referrals' : 'Semua rujukan'">All referrals</span></h3><span class="uj-pill" style="background:var(--red-tint);color:var(--red);">{{ $allReferrals->count() }}</span></div>
        @foreach ($allReferrals as $r)
            <div style="padding:13px 20px;border-bottom:1px solid var(--hairline-soft);">
                <div style="display:flex;align-items:center;gap:14px;margin-bottom:10px;">
                    <div style="flex:1;min-width:0;">
                        <div style="font-size:13.5px;color:var(--ink);font-weight:500;">{{ $r->candidate_name }} · {{ $r->candidate_email }}</div>
                        <div style="font-size:12px;color:var(--muted);"><span x-text="$store.ui.lang==='en' ? 'Referred by' : 'Dirujuk oleh'">Referred by</span> {{ $r->referrer?->name ?? '—' }}@if ($r->jobRequisition) · {{ $r->jobRequisition->title }} @else · <span x-text="$store.ui.lang==='en' ? 'General' : 'Umum'">General</span> @endif</div>
                    </div>
                    <div style="font-size:11px;font-weight:600;color:{{ $sc[$r->status] }};">{{ ucfirst($r->status) }}</div>
                    @if ($r->bonus_eligible)
                        <span class="uj-pill" style="background:var(--canvas);color:{{ $bc[$r->bonus_status] }};"><span x-text="$store.ui.lang==='en' ? 'Bonus' : 'Bonus'">Bonus</span> · {{ ucfirst($r->bonus_status) }}</span>
                    @endif
                </div>
                <form method="post" action="{{ route('referrals.status', $r) }}" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                    @csrf
                    <select name="status" style="height:34px;padding:0 10px;border:1px solid var(--hairline);border-radius:8px;font-size:12.5px;background:#fff;color:var(--ink);">
                        @foreach ($statuses as $s)
                            <option value="{{ $s }}" @selected($r->status === $s)>{{ ucfirst($s) }}</option>
                        @endforeach
                    </select>
                    <label style="display:flex;align-items:center;gap:5px;font-size:12.5px;color:var(--ink);">
                        <input type="checkbox" name="bonus_eligible" value="1" @checked($r->bonus_eligible) /> <span x-text="$store.ui.lang==='en' ? 'Bonus eligible' : 'Layak bonus'">Bonus eligible</span>
                    </label>
                    <select name="bonus_status" style="height:34px;padding:0 10px;border:1px solid var(--hairline);border-radius:8px;font-size:12.5px;background:#fff;color:var(--ink);">
                        @foreach ($bonusStatuses as $b)
                            <option value="{{ $b }}" @selected($r->bonus_status === $b)>{{ ucfirst($b) }}</option>
                        @endforeach
                    </select>
                    <button class="uj-btn-primary" style="height:34px;padding:0 14px;font-size:12.5px;"><span x-text="$store.ui.lang==='en' ? 'Update' : 'Kemas kini'">Update</span></button>
                </form>
            </div>
        @endforeach
    </div>
@endif

<div style="display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap;">
    <div class="uj-card" style="flex:1.3;min-width:340px;padding:24px;">
        <h3 class="uj-card-title" style="margin-bottom:16px;"><span x-text="$store.ui.lang==='en' ? 'Refer someone' : 'Rujuk seseorang'">Refer someone</span></h3>
        <form method="post" action="{{ route('referrals.store') }}">
            @csrf
            <div style="display:flex;gap:16px;margin-bottom:16px;">
                <div style="flex:1;">
                    <label style="display:block;font-size:13px;font-weight:500;color:var(--ink);margin-bottom:6px;"><span x-text="$store.ui.lang==='en' ? 'Candidate name' : 'Nama calon'">Candidate name</span></label>
                    <input name="candidate_name" type="text" required value="{{ old('candidate_name') }}" :placeholder="$store.ui.lang==='en' ? 'Full name' : 'Nama penuh'" style="width:100%;height:42px;padding:0 12px;border:1px solid var(--hairline);border-radius:8px;font-size:14px;outline:none;" />
                    @error('candidate_name')<div style="font-size:12px;color:var(--error);margin-top:6px;">{{ $message }}</div>@enderror
                </div>
                <div style="flex:1;">
                    <label style="display:block;font-size:13px;font-weight:500;color:var(--ink);margin-bottom:6px;"><span x-text="$store.ui.lang==='en' ? 'Candidate email' : 'Emel calon'">Candidate email</span></label>
                    <input name="candidate_email" type="email" required value="{{ old('candidate_email') }}" placeholder="name@example.com" style="width:100%;height:42px;padding:0 12px;border:1px solid var(--hairline);border-radius:8px;font-size:14px;outline:none;" />
                    @error('candidate_email')<div style="font-size:12px;color:var(--error);margin-top:6px;">{{ $message }}</div>@enderror
                </div>
            </div>
            <div style="display:flex;gap:16px;margin-bottom:16px;">
                <div style="flex:1;">
                    <label style="display:block;font-size:13px;font-weight:500;color:var(--ink);margin-bottom:6px;"><span x-text="$store.ui.lang==='en' ? 'Phone (optional)' : 'Telefon (pilihan)'">Phone (optional)</span></label>
                    <input name="candidate_phone" type="text" value="{{ old('candidate_phone') }}" placeholder="012-3456789" style="width:100%;height:42px;padding:0 12px;border:1px solid var(--hairline);border-radius:8px;font-size:14px;font-family:var(--font-mono);outline:none;" />
                </div>
                <div style="flex:1;">
                    <label style="display:block;font-size:13px;font-weight:500;color:var(--ink);margin-bottom:6px;"><span x-text="$store.ui.lang==='en' ? 'Open role (optional)' : 'Jawatan dibuka (pilihan)'">Open role (optional)</span></label>
                    <select name="job_requisition_id" style="width:100%;height:42px;padding:0 12px;border:1px solid var(--hairline);border-radius:8px;font-size:14px;background:#fff;color:var(--ink);">
                        <option value="" x-text="$store.ui.lang==='en' ? 'General referral' : 'Rujukan umum'">General referral</option>
                        @foreach ($openRoles as $role_)
                            <option value="{{ $role_->id }}" @selected((string) old('job_requisition_id') === (string) $role_->id)>{{ $role_->title }}</option>
                        @endforeach
                    </select>
                    @include('partials.hint', ['en' => 'Pick the role you\'re referring this candidate for, or leave as a general referral for HR to place.', 'ms' => 'Pilih jawatan yang anda rujuk calon ini, atau biarkan sebagai referral umum untuk HR tempatkan.'])
                </div>
            </div>
            <label style="display:block;font-size:13px;font-weight:500;color:var(--ink);margin-bottom:6px;"><span x-text="$store.ui.lang==='en' ? 'Resume link (optional)' : 'Pautan resume (pilihan)'">Resume link (optional)</span></label>
            <input name="resume_url" type="url" value="{{ old('resume_url') }}" placeholder="https://..." style="width:100%;height:42px;padding:0 14px;border:1px solid var(--hairline);border-radius:8px;font-size:14px;font-family:var(--font-mono);margin-bottom:16px;outline:none;" />
            @error('resume_url')<div style="font-size:12px;color:var(--error);margin-top:-10px;margin-bottom:14px;">{{ $message }}</div>@enderror
            <label style="display:block;font-size:13px;font-weight:500;color:var(--ink);margin-bottom:6px;"><span x-text="$store.ui.lang==='en' ? 'Note (optional)' : 'Nota (pilihan)'">Note (optional)</span></label>
            <textarea name="note" rows="2" :placeholder="$store.ui.lang==='en' ? 'Why they would be a good fit' : 'Kenapa mereka sesuai'" style="width:100%;padding:12px 14px;border:1px solid var(--hairline);border-radius:8px;font-size:13.5px;margin-bottom:18px;outline:none;resize:vertical;">{{ old('note') }}</textarea>
            <button type="submit" class="uj-btn-primary" style="height:42px;padding:0 20px;font-size:13.5px;"><span x-text="$store.ui.lang==='en' ? 'Submit referral' : 'Hantar rujukan'">Submit referral</span></button>
        </form>
    </div>

    <div class="uj-card" style="flex:1;min-width:300px;">
        <div class="uj-card-head"><h3 class="uj-card-title"><span x-text="$store.ui.lang==='en' ? 'My referrals' : 'Rujukan saya'">My referrals</span></h3></div>
        @forelse ($myReferrals as $r)
            <div style="display:flex;align-items:center;justify-content:space-between;padding:13px 20px;border-bottom:1px solid var(--hairline-soft);">
                <div style="min-width:0;">
                    <div style="font-size:13.5px;color:var(--ink);font-weight:500;">{{ $r->candidate_name }}</div>
                    <div style="font-size:11.5px;color:var(--muted);">@if ($r->jobRequisition){{ $r->jobRequisition->title }}@else <span x-text="$store.ui.lang==='en' ? 'General referral' : 'Rujukan umum'">General referral</span> @endif</div>
                </div>
                <div style="text-align:right;">
                    <div style="font-size:11px;font-weight:600;color:{{ $sc[$r->status] }};">{{ ucfirst($r->status) }}</div>
                    @if ($r->bonus_eligible)
                        <div style="font-size:10.5px;font-weight:600;color:{{ $bc[$r->bonus_status] }};"><span x-text="$store.ui.lang==='en' ? 'Bonus' : 'Bonus'">Bonus</span> · {{ ucfirst($r->bonus_status) }}</div>
                    @endif
                </div>
            </div>
        @empty
            <div style="padding:28px 20px;text-align:center;">
                <div style="font-size:13px;color:var(--ink);font-weight:500;margin-bottom:3px;"><span x-text="$store.ui.lang==='en' ? 'No referrals yet' : 'Belum ada rujukan'">No referrals yet</span></div>
                <div style="font-size:12px;color:var(--muted);line-height:1.5;"><span x-text="$store.ui.lang==='en' ? 'Use the form on the left to refer someone you know for an open role. Your referrals and their progress will appear here.' : 'Guna borang di sebelah kiri untuk merujuk seseorang yang anda kenal bagi jawatan yang dibuka. Rujukan anda dan kemajuannya akan muncul di sini.'">Use the form on the left to refer someone you know for an open role. Your referrals and their progress will appear here.</span></div>
            </div>
        @endforelse
    </div>
</div>
@endsection
