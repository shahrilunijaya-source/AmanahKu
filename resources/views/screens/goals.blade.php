@extends('layouts.app')

@php
    use App\Support\Amanahku;
    $catLabel = ['growth' => 'Growth', 'delivery' => 'Delivery', 'culture' => 'Culture'];
    $catLabelMs = ['growth' => 'Pertumbuhan', 'delivery' => 'Penyampaian', 'culture' => 'Budaya'];
    $statusTint = [
        'active' => ['bg' => 'var(--hairline-soft)', 'fg' => 'var(--muted)'],
        'achieved' => ['bg' => '#e7f4ee', 'fg' => 'var(--success)'],
        'missed' => ['bg' => 'var(--red-tint)', 'fg' => 'var(--red)'],
        'archived' => ['bg' => 'var(--hairline-soft)', 'fg' => 'var(--muted-soft)'],
    ];
    $barColor = fn (int $p) => $p >= 80 ? 'var(--success)' : ($p >= 50 ? 'var(--amber)' : 'var(--red)');
    $addOpen = old('form') === 'goal';
@endphp

@section('screen')
@include('partials.guide', [
    'key' => 'goals',
    'en'  => [
        'title' => 'Objectives & key results (OKR)',
        'body'  => 'OKRs are about ambition and direction — what you want to achieve and how you will measure getting there. An Objective is the goal you are aiming for; each Key Result is a measurable step that proves progress. This is different from KPI, which tracks ongoing job performance.',
        'who'   => 'You set & update your own · Managers see the team',
        'steps' => [
            'Create an Objective — a clear, ambitious goal for the period (e.g. "2026 H1").',
            'Add 2–4 Key Results under it — each measurable, so progress is obvious.',
            'Update each Key Result\'s progress % as work moves along.',
            'The Objective\'s overall % is the average of its Key Results.',
        ],
    ],
    'ms'  => [
        'title' => 'Objektif & key result (OKR)',
        'body'  => 'OKR adalah tentang aspirasi dan hala tuju — apa yang anda mahu capai dan bagaimana anda akan mengukur pencapaiannya. Objektif ialah matlamat yang anda sasarkan; setiap Key Result ialah langkah yang boleh diukur yang membuktikan kemajuan. Ini berbeza daripada KPI, yang menjejak prestasi kerja yang berterusan.',
        'who'   => 'Anda tetap & kemas kini sendiri · Pengurus lihat pasukan',
        'steps' => [
            'Cipta satu Objektif — matlamat yang jelas dan beraspirasi untuk tempoh tersebut (cth. "2026 H1").',
            'Tambah 2–4 Key Result di bawahnya — setiap satu boleh diukur, supaya kemajuan jelas.',
            'Kemas kini progress % setiap Key Result apabila kerja berjalan.',
            'Peratus keseluruhan Objektif ialah purata Key Result-nya.',
        ],
    ],
])
@if (session('ok'))
    <div style="background:#e7f4ee;border:1px solid var(--success);color:var(--success);font-size:12.5px;border-radius:8px;padding:10px 14px;margin-bottom:16px;">{{ session('ok') }}</div>
@endif

{{-- Summary --}}
<div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:16px;">
    <div class="uj-card uj-stat" style="flex:1;min-width:160px;"><div class="uj-stat-label" x-text="$store.ui.lang==='en' ? 'Overall progress' : 'Kemajuan keseluruhan'">Overall progress</div><div class="uj-stat-value">{{ $overallProgress }}%</div></div>
    <div class="uj-card uj-stat" style="flex:1;min-width:160px;"><div class="uj-stat-label" x-text="$store.ui.lang==='en' ? 'Active objectives' : 'Objektif aktif'">Active objectives</div><div class="uj-stat-value">{{ $myGoals->where('status', 'active')->count() }}</div></div>
    <div class="uj-card uj-stat" style="flex:1;min-width:160px;"><div class="uj-stat-label" x-text="$store.ui.lang==='en' ? 'Achieved' : 'Dicapai'">Achieved</div><div class="uj-stat-value">{{ $myGoals->where('status', 'achieved')->count() }}</div></div>
</div>

{{-- My objectives --}}
<div class="uj-card" style="padding:0;margin-bottom:16px;" x-data="{ add: {{ $addOpen ? 'true' : 'false' }} }">
    <div class="uj-card-head">
        <h3 class="uj-card-title"><span x-text="$store.ui.lang==='en' ? 'My objectives' : 'Objektif saya'">My objectives</span></h3>
        @if ($canManage)
            <button @click="add = ! add" class="uj-btn-ghost" style="height:34px;padding:0 13px;font-size:12.5px;"><span x-text="add ? ($store.ui.lang==='en' ? 'Cancel' : 'Batal') : ($store.ui.lang==='en' ? 'New objective' : 'Objektif baru')"></span></button>
        @endif
    </div>

    {{-- Add-goal form --}}
    @if ($canManage)
        <form method="post" action="{{ route('goals.store') }}" x-show="add" x-cloak style="padding:16px 20px;border-bottom:1px solid var(--hairline-soft);background:var(--hairline-soft);">
            @csrf
            <input type="hidden" name="form" value="goal" />
            @if ($errors->any() && old('form') === 'goal')<div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12px;border-radius:8px;padding:9px 12px;margin-bottom:12px;">{{ $errors->first() }}</div>@endif
            <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
                <div style="flex:2;min-width:240px;">
                    <label style="display:block;font-size:11px;color:var(--muted);margin-bottom:4px;" x-text="$store.ui.lang==='en' ? 'Objective' : 'Objektif'">Objective</label>
                    <input name="title" value="{{ old('form') === 'goal' ? old('title') : '' }}" required maxlength="160" placeholder="e.g. Ship the OKR module" :placeholder="$store.ui.lang==='en' ? 'e.g. Ship the OKR module' : 'cth. Siapkan modul OKR'" style="width:100%;height:38px;padding:0 11px;border:1px solid var(--hairline);border-radius:7px;font-size:13px;outline:none;" />
                </div>
                <div style="flex:1;min-width:130px;">
                    <label style="display:block;font-size:11px;color:var(--muted);margin-bottom:4px;" x-text="$store.ui.lang==='en' ? 'Period' : 'Tempoh'">Period</label>
                    <input name="period" value="{{ old('form') === 'goal' ? old('period', '2026 H1') : '2026 H1' }}" required maxlength="40" placeholder="2026 H1" style="width:100%;height:38px;padding:0 11px;border:1px solid var(--hairline);border-radius:7px;font-size:13px;font-family:var(--font-mono);outline:none;margin-bottom:4px;" />
                    @include('partials.hint', ['en' => 'The time window this objective covers, e.g. "2026 H1" (first half) or a quarter.', 'ms' => 'Tempoh masa yang diliputi objektif ini, cth. "2026 H1" (separuh pertama) atau satu suku tahun.'])
                </div>
                <div style="flex:1;min-width:130px;">
                    <label style="display:block;font-size:11px;color:var(--muted);margin-bottom:4px;" x-text="$store.ui.lang==='en' ? 'Category' : 'Kategori'">Category</label>
                    <select name="category" style="width:100%;height:38px;padding:0 11px;border:1px solid var(--hairline);border-radius:7px;font-size:13px;background:#fff;color:var(--ink);outline:none;">
                        <option value="">—</option>
                        @foreach ($categories as $c)
                            <option value="{{ $c }}" @selected(old('form') === 'goal' && old('category') === $c) x-text="$store.ui.lang==='en' ? @js($catLabel[$c] ?? ucfirst($c)) : @js($catLabelMs[$c] ?? ucfirst($c))">{{ $catLabel[$c] ?? ucfirst($c) }}</option>
                        @endforeach
                    </select>
                    @include('partials.hint', ['en' => 'Optional grouping: Growth (learning/career), Delivery (shipping work), or Culture (team & values).', 'ms' => 'Pengelompokan pilihan: Growth (pembelajaran/kerjaya), Delivery (menyiapkan kerja), atau Culture (pasukan & nilai).'])
                </div>
            </div>
            <div style="margin-top:12px;">
                <label style="display:block;font-size:11px;color:var(--muted);margin-bottom:4px;"><span x-text="$store.ui.lang==='en' ? 'Description' : 'Penerangan'">Description</span> <span x-text="$store.ui.lang==='en' ? '(optional)' : '(pilihan)'">(optional)</span></label>
                <textarea name="description" maxlength="1000" rows="2" style="width:100%;padding:9px 11px;border:1px solid var(--hairline);border-radius:7px;font-size:13px;resize:vertical;outline:none;font-family:inherit;">{{ old('form') === 'goal' ? old('description') : '' }}</textarea>
            </div>
            <button type="submit" class="uj-btn-primary" style="height:38px;padding:0 18px;font-size:13px;margin-top:12px;" x-text="$store.ui.lang==='en' ? 'Create objective' : 'Cipta objektif'">Create objective</button>
        </form>
    @endif

    @forelse ($myGoals as $goal)
        @php $st = $statusTint[$goal->status] ?? $statusTint['active']; @endphp
        <div style="padding:18px 20px;border-bottom:1px solid var(--hairline-soft);">
            <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:6px;">
                <div>
                    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                        <span style="font-size:14px;font-weight:600;color:var(--ink);">{{ $goal->title }}</span>
                        @if ($goal->category)<span class="uj-pill" style="background:var(--hairline-soft);color:var(--muted);" x-text="$store.ui.lang==='en' ? @js($catLabel[$goal->category] ?? ucfirst($goal->category)) : @js($catLabelMs[$goal->category] ?? ucfirst($goal->category))">{{ $catLabel[$goal->category] ?? ucfirst($goal->category) }}</span>@endif
                        <span class="uj-pill" style="background:{{ $st['bg'] }};color:{{ $st['fg'] }};">{{ ucfirst($goal->status) }}</span>
                    </div>
                    @if ($goal->description)<p style="font-size:12.5px;color:var(--muted);margin:5px 0 0;">{{ $goal->description }}</p>@endif
                    <div style="font-size:11px;color:var(--muted-soft);margin-top:4px;font-family:var(--font-mono);">{{ $goal->period }}</div>
                </div>
                <div style="text-align:right;flex-shrink:0;">
                    <div style="font-size:20px;font-weight:700;color:var(--ink);font-family:var(--font-mono);">{{ $goal->progress }}%</div>
                    <div style="font-size:10.5px;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;" x-text="$store.ui.lang==='en' ? 'overall' : 'keseluruhan'">overall</div>
                </div>
            </div>

            {{-- Key results --}}
            <div style="margin-top:10px;display:flex;flex-direction:column;gap:8px;">
                @forelse ($goal->keyResults as $kr)
                    @php $reopen = old('row') == $kr->id; @endphp
                    <div x-data="{ ed: {{ $reopen ? 'true' : 'false' }} }" style="border:1px solid var(--hairline-soft);border-radius:8px;padding:10px 12px;">
                        <div style="display:flex;align-items:center;gap:12px;">
                            <div style="flex:2;min-width:0;">
                                <div style="font-size:12.5px;color:var(--ink);font-weight:500;">{{ $kr->title }}</div>
                                @if ($kr->target_label)<div style="font-size:11px;color:var(--muted-soft);font-family:var(--font-mono);">{{ $kr->target_label }}</div>@endif
                            </div>
                            <div style="flex:1.4;display:flex;align-items:center;gap:8px;">
                                <div class="uj-progress" style="flex:1;"><span style="width:{{ $kr->progress }}%;background:{{ $barColor((int) $kr->progress) }};"></span></div>
                                <span style="font-size:11px;color:var(--muted);font-family:var(--font-mono);width:34px;text-align:right;">{{ $kr->progress }}%</span>
                            </div>
                            @if ($canManage)
                                <button @click="ed = ! ed" class="uj-btn-ghost" style="height:28px;padding:0 10px;font-size:11px;"><span x-text="ed ? ($store.ui.lang==='en' ? 'Close' : 'Tutup') : ($store.ui.lang==='en' ? 'Update' : 'Kemas kini')"></span></button>
                            @endif
                        </div>
                        @if ($canManage)
                            <form method="post" action="{{ route('goals.kr.progress', $kr) }}" x-show="ed" x-cloak style="margin-top:10px;display:flex;gap:12px;align-items:flex-end;">
                                @csrf
                                <input type="hidden" name="row" value="{{ $kr->id }}" />
                                <div>
                                    <label style="display:block;font-size:11px;color:var(--muted);margin-bottom:4px;" x-text="$store.ui.lang==='en' ? 'Progress %' : 'Kemajuan %'">Progress %</label>
                                    <input name="progress" type="number" min="0" max="100" value="{{ $reopen ? old('progress', $kr->progress) : $kr->progress }}" required style="width:104px;height:34px;padding:0 11px;border:1px solid var(--hairline);border-radius:7px;font-size:13px;font-family:var(--font-mono);outline:none;" />
                                </div>
                                <button type="submit" class="uj-btn-primary" style="height:34px;padding:0 16px;font-size:13px;" x-text="$store.ui.lang==='en' ? 'Save' : 'Simpan'">Save</button>
                            </form>
                        @endif
                    </div>
                @empty
                    <div style="font-size:12px;color:var(--muted-soft);padding:4px 0;">@if ($canManage)<span x-text="$store.ui.lang==='en' ? 'No key results yet — add one below to start measuring progress.' : 'Belum ada key result — tambah satu di bawah untuk mula mengukur kemajuan.'"></span>@else<span x-text="$store.ui.lang==='en' ? 'No key results yet — measurable steps will show here once added.' : 'Belum ada key result — langkah yang boleh diukur akan dipaparkan di sini sebaik sahaja ditambah.'"></span>@endif</div>
                @endforelse
            </div>

            {{-- Add-key-result form --}}
            @if ($canManage)
                @php $krOpen = old('form') === 'kr' && old('goal') == $goal->id; @endphp
                <div x-data="{ kr: {{ $krOpen ? 'true' : 'false' }} }" style="margin-top:10px;">
                    <button @click="kr = ! kr" class="uj-btn-ghost" style="height:28px;padding:0 10px;font-size:11px;"><span x-text="kr ? ($store.ui.lang==='en' ? 'Cancel' : 'Batal') : ($store.ui.lang==='en' ? '+ Add key result' : '+ Tambah key result')"></span></button>
                    <form method="post" action="{{ route('goals.kr.store', $goal) }}" x-show="kr" x-cloak style="margin-top:10px;display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
                        @csrf
                        <input type="hidden" name="form" value="kr" />
                        <input type="hidden" name="goal" value="{{ $goal->id }}" />
                        @if ($errors->any() && $krOpen)<div style="flex-basis:100%;background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12px;border-radius:8px;padding:9px 12px;">{{ $errors->first() }}</div>@endif
                        <div style="flex:2;min-width:220px;">
                            <label style="display:block;font-size:11px;color:var(--muted);margin-bottom:4px;" x-text="$store.ui.lang==='en' ? 'Key result' : 'Hasil utama'">Key result</label>
                            <input name="title" value="{{ $krOpen ? old('title') : '' }}" required maxlength="160" placeholder="e.g. Close all P1 bugs" :placeholder="$store.ui.lang==='en' ? 'e.g. Close all P1 bugs' : 'cth. Tutup semua pepijat P1'" style="width:100%;height:34px;padding:0 11px;border:1px solid var(--hairline);border-radius:7px;font-size:13px;outline:none;margin-bottom:4px;" />
                            @include('partials.hint', ['en' => 'Make it measurable — something you can clearly mark done or not, not a vague activity.', 'ms' => 'Jadikan ia boleh diukur — sesuatu yang anda boleh tanda siap atau tidak dengan jelas, bukan aktiviti yang kabur.'])
                        </div>
                        <div style="flex:1;min-width:140px;">
                            <label style="display:block;font-size:11px;color:var(--muted);margin-bottom:4px;"><span x-text="$store.ui.lang==='en' ? 'Target' : 'Sasaran'">Target</span> <span x-text="$store.ui.lang==='en' ? '(optional)' : '(pilihan)'">(optional)</span></label>
                            <input name="target_label" value="{{ $krOpen ? old('target_label') : '' }}" maxlength="80" placeholder="e.g. 0 open P1" :placeholder="$store.ui.lang==='en' ? 'e.g. 0 open P1' : 'cth. 0 P1 terbuka'" style="width:100%;height:34px;padding:0 11px;border:1px solid var(--hairline);border-radius:7px;font-size:13px;font-family:var(--font-mono);outline:none;" />
                        </div>
                        <div>
                            <label style="display:block;font-size:11px;color:var(--muted);margin-bottom:4px;" x-text="$store.ui.lang==='en' ? 'Progress %' : 'Kemajuan %'">Progress %</label>
                            <input name="progress" type="number" min="0" max="100" value="{{ $krOpen ? old('progress', 0) : 0 }}" style="width:90px;height:34px;padding:0 11px;border:1px solid var(--hairline);border-radius:7px;font-size:13px;font-family:var(--font-mono);outline:none;" />
                        </div>
                        <button type="submit" class="uj-btn-primary" style="height:34px;padding:0 16px;font-size:13px;" x-text="$store.ui.lang==='en' ? 'Add' : 'Tambah'">Add</button>
                    </form>
                </div>
            @endif
        </div>
    @empty
        <div style="padding:40px 24px;text-align:center;">
            <div style="font-size:13.5px;color:var(--ink);font-weight:500;margin-bottom:3px;"><span x-text="$store.ui.lang==='en' ? 'No objectives set yet' : 'Belum ada objektif ditetapkan'"></span></div>
            <div style="font-size:12.5px;color:var(--muted);line-height:1.5;">@if ($canManage)<span x-text="$store.ui.lang==='en' ? 'Click &quot;New objective&quot; above to set your first goal for this period, then add key results to track progress.' : 'Klik &quot;New objective&quot; di atas untuk menetapkan matlamat pertama anda bagi tempoh ini, kemudian tambah key result untuk menjejak kemajuan.'"></span>@else<span x-text="$store.ui.lang==='en' ? 'Once objectives are set for you, they will appear here with their progress.' : 'Sebaik sahaja objektif ditetapkan untuk anda, ia akan muncul di sini dengan kemajuannya.'"></span>@endif</div>
        </div>
    @endforelse
</div>

{{-- Privileged: team-goals overview --}}
@if ($privileged)
    <div class="uj-card" style="padding:0;">
        <div class="uj-card-head"><h3 class="uj-card-title"><span x-text="$store.ui.lang==='en' ? 'Team objectives' : 'Objektif pasukan'">Team objectives</span></h3><span style="font-size:12.5px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'Read-only overview' : 'Gambaran baca-sahaja'">Read-only overview</span></div>
        @forelse ($teamGoals as $goal)
            @php $st = $statusTint[$goal->status] ?? $statusTint['active']; @endphp
            <div style="padding:14px 20px;border-bottom:1px solid var(--hairline-soft);display:flex;align-items:center;gap:14px;">
                <div style="flex:2;min-width:0;">
                    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                        <span style="font-size:13px;font-weight:600;color:var(--ink);">{{ $goal->title }}</span>
                        <span class="uj-pill" style="background:{{ $st['bg'] }};color:{{ $st['fg'] }};">{{ ucfirst($goal->status) }}</span>
                    </div>
                    <div style="font-size:11.5px;color:var(--muted);margin-top:2px;">{{ $goal->employee?->name }} · {{ $goal->period }}</div>
                </div>
                <div style="flex:1.2;display:flex;align-items:center;gap:8px;">
                    <div class="uj-progress" style="flex:1;"><span style="width:{{ $goal->progress }}%;background:{{ $barColor((int) $goal->progress) }};"></span></div>
                    <span style="font-size:11px;color:var(--muted);font-family:var(--font-mono);width:34px;text-align:right;">{{ $goal->progress }}%</span>
                </div>
            </div>
        @empty
            <div style="padding:40px 24px;text-align:center;font-size:13.5px;color:var(--muted);line-height:1.5;"><span x-text="$store.ui.lang==='en' ? 'No team objectives to show yet.' : 'Belum ada objektif pasukan untuk dipaparkan.'"></span><br><span x-text="$store.ui.lang==='en' ? 'Once your team members set their objectives, you will see their progress here.' : 'Sebaik sahaja ahli pasukan anda menetapkan objektif mereka, anda akan lihat kemajuan mereka di sini.'"></span></div>
        @endforelse
    </div>
@endif
@endsection
