@extends('layouts.app')

@php
    $catStyle = [
        'Award'       => ['var(--red)', 'var(--red-tint)'],
        'Milestone'   => ['var(--info)', '#eaf1f8'],
        'Recognition' => ['var(--success)', '#e7f4ee'],
        'Spot Award'  => ['var(--amber)', '#fbf3e6'],
    ];
    $icons = [
        'trophy' => 'M8 21h8M12 17v4M6 4h12v4a6 6 0 0 1-12 0zM6 4H4v2a3 3 0 0 0 3 3M18 4h2v2a3 3 0 0 1-3 3',
        'medal'  => 'M12 15a6 6 0 1 0 0-12 6 6 0 0 0 0 12zM8.5 13.4L7 22l5-3 5 3-1.5-8.6',
        'star'   => 'M12 2l3 6.9 7 .6-5.3 4.6L18.2 22 12 18.2 5.8 22l1.5-7.3L2 9.5l7-.6z',
        'zap'    => 'M13 2L3 14h8l-1 8 10-12h-8z',
    ];
    $canRecognise = in_array($role, ['manager', 'management', 'hr'], true);
@endphp

@section('screen')
@include('partials.guide', [
    'key' => 'achievements',
    'en'  => [
        'title' => 'Recognition & kudos',
        'body'  => 'A public feed for celebrating good work — awards, milestones, and spot recognitions. Each recognition can carry points that build a leaderboard, making appreciation visible and encouraging a positive culture. Everyone sees the feed; managers and HR give recognition.',
        'who'   => 'Everyone sees · Managers & HR give recognition',
        'steps' => [
            'Pick the team member you want to recognise.',
            'Describe in one line exactly what they did well — be specific.',
            'Choose a category and how many points it is worth.',
            'Record it — it appears in the feed and updates the leaderboard.',
        ],
    ],
    'ms'  => [
        'title' => 'Recognition & kudos',
        'body'  => 'Satu feed terbuka untuk meraikan kerja yang baik — award, milestone, dan spot recognition. Setiap recognition boleh membawa mata yang membina leaderboard, menjadikan penghargaan kelihatan dan menggalakkan budaya yang positif. Semua orang nampak feed ini; pengurus dan HR beri recognition.',
        'who'   => 'Semua orang nampak · Pengurus & HR beri recognition',
        'steps' => [
            'Pilih ahli pasukan yang anda mahu beri recognition.',
            'Terangkan dalam satu baris apa sebenarnya yang mereka buat dengan baik — jadilah spesifik.',
            'Pilih satu kategori dan berapa mata ia bernilai.',
            'Rekodkan — ia muncul dalam feed dan mengemas kini leaderboard.',
        ],
    ],
])
<div style="display:flex;gap:16px;margin-bottom:16px;flex-wrap:wrap;">
    <div class="uj-card uj-stat" style="flex:1;min-width:170px;"><div class="uj-stat-label" x-text="$store.ui.lang==='en' ? 'Recognitions' : 'Recognition'">Recognitions</div><div class="uj-stat-value">{{ $totalCount }}</div></div>
    <div class="uj-card uj-stat" style="flex:1;min-width:170px;"><div class="uj-stat-label" x-text="$store.ui.lang==='en' ? 'Points awarded' : 'Mata diberikan'">Points awarded</div><div class="uj-stat-value">{{ number_format($totalPoints) }}</div></div>
    <div class="uj-card uj-stat" style="flex:1;min-width:170px;"><div class="uj-stat-label" x-text="$store.ui.lang==='en' ? 'This month' : 'Bulan ini'">This month</div><div class="uj-stat-value">{{ $thisMonth }}</div></div>
</div>

<div style="display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap;">
    {{-- Recognition feed --}}
    <div class="uj-card" style="flex:2;min-width:340px;">
        <div class="uj-card-head"><h3 class="uj-card-title"><span x-text="$store.ui.lang==='en' ? 'Recognition feed' : 'Feed recognition'">Recognition feed</span></h3><span style="font-size:12.5px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'Most recent first' : 'Terkini dahulu'">Most recent first</span></div>
        @forelse ($feed as $a)
            @php [$c, $bg] = $catStyle[$a->category] ?? ['var(--muted)', 'var(--hairline-soft)']; $path = $icons[$a->icon] ?? $icons['star']; @endphp
            <div class="uj-row" style="display:flex;gap:14px;padding:15px 20px;border-bottom:1px solid var(--hairline-soft);align-items:center;">
                <div style="width:42px;height:42px;border-radius:11px;background:{{ $bg }};display:flex;align-items:center;justify-content:center;flex-shrink:0;"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="{{ $c }}" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $path }}"></path></svg></div>
                <div style="flex:1;min-width:0;">
                    <div style="font-size:14px;color:var(--ink);font-weight:500;">{{ $a->title }}</div>
                    <div style="display:flex;align-items:center;gap:8px;margin-top:5px;">
                        <span style="width:22px;height:22px;border-radius:50%;background:{{ $a->employee?->avatar_color ?? '#3a6ea5' }};color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:10px;font-weight:600;flex-shrink:0;">{{ $a->employee?->initials ?? '★' }}</span>
                        <span style="font-size:12px;color:var(--muted);">{{ $a->who ?? $a->employee?->name }} · {{ $a->date?->diffForHumans() ?? $a->date_label }}</span>
                    </div>
                </div>
                <div style="text-align:right;flex-shrink:0;">
                    <span class="uj-pill" style="background:{{ $bg }};color:{{ $c }};">{{ $a->category ?? 'Recognition' }}</span>
                    @if ($a->points)<div style="font-size:12px;color:var(--muted);font-family:var(--font-mono);margin-top:6px;">+{{ $a->points }} <span x-text="$store.ui.lang==='en' ? 'pts' : 'mata'">pts</span></div>@endif
                </div>
            </div>
        @empty
            <div style="padding:44px 24px;text-align:center;">
                <div style="font-size:13.5px;color:var(--ink);font-weight:500;margin-bottom:3px;"><span x-text="$store.ui.lang==='en' ? 'No recognition recorded yet' : 'Belum ada recognition direkodkan'"></span></div>
                <div style="font-size:12.5px;color:var(--muted);line-height:1.5;">@if ($canRecognise)<span x-text="$store.ui.lang==='en' ? 'Use the &quot;Give recognition&quot; form to celebrate a team member — it will appear here for everyone to see.' : 'Guna borang &quot;Give recognition&quot; untuk meraikan ahli pasukan — ia akan muncul di sini untuk semua orang lihat.'"></span>@else<span x-text="$store.ui.lang==='en' ? 'When your manager or HR recognises good work, it will show up in this feed.' : 'Apabila pengurus atau HR anda memberi recognition atas kerja yang baik, ia akan dipaparkan dalam feed ini.'"></span>@endif</div>
            </div>
        @endforelse
    </div>

    {{-- Leaderboard + give recognition --}}
    <div style="flex:1;min-width:280px;display:flex;flex-direction:column;gap:16px;">
        <div class="uj-card" style="padding:20px;">
            <h3 class="uj-card-title" style="margin-bottom:3px;"><span x-text="$store.ui.lang==='en' ? 'Leaderboard' : 'Papan pendahulu'">Leaderboard</span></h3>
            <p style="font-size:12px;color:var(--muted);margin:0 0 12px;" x-text="$store.ui.lang==='en' ? 'Points earned this cycle.' : 'Mata diperoleh kitaran ini.'">Points earned this cycle.</p>
            @forelse ($leaders as $i => $l)
                <div style="display:flex;align-items:center;gap:11px;padding:9px 0;border-bottom:1px solid var(--hairline-soft);">
                    <span style="width:22px;height:22px;border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;font-family:var(--font-mono);flex-shrink:0;color:{{ $i === 0 ? '#fff' : 'var(--muted)' }};background:{{ $i === 0 ? 'var(--red)' : 'var(--hairline-soft)' }};">{{ $i + 1 }}</span>
                    <div style="width:30px;height:30px;border-radius:50%;background:{{ $l->avatar_color }};color:#fff;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:600;flex-shrink:0;">{{ $l->initials }}</div>
                    <div style="flex:1;min-width:0;"><div style="font-size:13px;color:var(--ink);font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ $l->name }}</div><div style="font-size:11px;color:var(--muted-soft);">{{ $l->achievements_count }} <span x-text="$store.ui.lang==='en' ? @js(\Illuminate\Support\Str::plural('recognition', $l->achievements_count)) : 'recognition'">{{ \Illuminate\Support\Str::plural('recognition', $l->achievements_count) }}</span></div></div>
                    <span style="font-size:13px;color:var(--ink);font-weight:600;font-family:var(--font-mono);">{{ (int) $l->recognition_points }}</span>
                </div>
            @empty
                <div style="font-size:13px;color:var(--muted);line-height:1.5;"><span x-text="$store.ui.lang==='en' ? 'No points awarded yet. As recognitions are given, top earners will rank here.' : 'Belum ada mata diberikan. Apabila recognition diberi, pemperoleh tertinggi akan disenaraikan di sini.'"></span></div>
            @endforelse
        </div>

        @if ($canRecognise)
            <div class="uj-card" style="padding:20px;">
                <h3 class="uj-card-title" style="margin-bottom:14px;"><span x-text="$store.ui.lang==='en' ? 'Give recognition' : 'Beri recognition'">Give recognition</span></h3>
                <form method="post" action="{{ route('achievements.store') }}">
                    @csrf
                    @if ($errors->any())<div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12px;border-radius:8px;padding:9px 12px;margin-bottom:14px;">{{ $errors->first() }}</div>@endif

                    <label style="display:block;font-size:12.5px;font-weight:500;color:var(--ink);margin-bottom:5px;" x-text="$store.ui.lang==='en' ? 'Team member' : 'Ahli pasukan'">Team member</label>
                    <select name="employee_id" required style="width:100%;height:40px;padding:0 11px;border:1px solid var(--hairline);border-radius:8px;font-size:13.5px;background:#fff;color:var(--ink);margin-bottom:13px;">
                        <option value="" x-text="$store.ui.lang==='en' ? 'Select…' : 'Pilih…'">Select…</option>
                        @foreach ($recipients as $r)<option value="{{ $r->id }}" @selected(old('employee_id') == $r->id)>{{ $r->name }}</option>@endforeach
                    </select>

                    <label style="display:block;font-size:12.5px;font-weight:500;color:var(--ink);margin-bottom:5px;" x-text="$store.ui.lang==='en' ? 'What did they do?' : 'Apa yang mereka buat?'">What did they do?</label>
                    <input name="title" value="{{ old('title') }}" required maxlength="160" placeholder="e.g. Resolved a P1 outage in under 30 minutes" :placeholder="$store.ui.lang==='en' ? 'e.g. Resolved a P1 outage in under 30 minutes' : 'cth. Selesaikan gangguan P1 dalam masa kurang 30 minit'" style="width:100%;height:40px;padding:0 11px;border:1px solid var(--hairline);border-radius:8px;font-size:13.5px;margin-bottom:6px;outline:none;" />
                    @include('partials.hint', ['en' => 'Name the specific action and its impact — "helped onboard the new hire" beats "good work". This text shows publicly in the feed.', 'ms' => 'Nyatakan tindakan spesifik dan kesannya — "bantu onboard pekerja baharu" lebih baik daripada "kerja bagus". Teks ini dipaparkan secara terbuka dalam feed.'])

                    <div style="display:flex;gap:10px;margin-bottom:6px;">
                        <div style="flex:1.4;"><label style="display:block;font-size:12.5px;font-weight:500;color:var(--ink);margin-bottom:5px;" x-text="$store.ui.lang==='en' ? 'Category' : 'Kategori'">Category</label>
                            <select name="category" required style="width:100%;height:40px;padding:0 11px;border:1px solid var(--hairline);border-radius:8px;font-size:13.5px;background:#fff;color:var(--ink);">
                                @foreach (['Recognition', 'Milestone', 'Award', 'Spot Award'] as $cat)<option @selected(old('category') === $cat)>{{ $cat }}</option>@endforeach
                            </select>
                        </div>
                        <div style="flex:1;"><label style="display:block;font-size:12.5px;font-weight:500;color:var(--ink);margin-bottom:5px;" x-text="$store.ui.lang==='en' ? 'Points' : 'Mata'">Points</label>
                            <input name="points" type="number" min="0" max="1000" value="{{ old('points', 50) }}" style="width:100%;height:40px;padding:0 11px;border:1px solid var(--hairline);border-radius:8px;font-size:13.5px;font-family:var(--font-mono);outline:none;" />
                        </div>
                    </div>
                    @include('partials.hint', ['en' => 'Category sets the tone — Spot Award for quick wins, Award for big ones. Points add to their leaderboard score; keep them fair and consistent across the team.', 'ms' => 'Kategori menentukan nada — Spot Award untuk kemenangan kecil, Award untuk yang besar. Mata menambah skor leaderboard mereka; pastikan ia adil dan konsisten merentas pasukan.'])

                    <button type="submit" class="uj-btn-primary" style="width:100%;height:42px;font-size:13.5px;" x-text="$store.ui.lang==='en' ? 'Record recognition' : 'Rekod recognition'">Record recognition</button>
                </form>
            </div>
        @endif
    </div>
</div>
@endsection
