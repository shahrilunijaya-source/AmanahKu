@extends('layouts.app')

@php
    $tag = ['assignment' => ['Assignment', 'var(--red)'], 'task' => ['Task', 'var(--info)'], 'adhoc' => ['Adhoc', 'var(--amber)']];
    $pri = ['high' => 'var(--error)', 'medium' => 'var(--amber)', 'low' => 'var(--muted)'];
    $priLabel = ['high' => 'High', 'medium' => 'Medium', 'low' => 'Low'];
@endphp

@section('screen')
{{-- Reciprocal of the "see all staff" icon on the personal board screen: this board
     is reached by that one-way shortcut, so offer a one-tap way back to My tasks
     rather than leaving the browser Back button as the only exit. --}}
<div style="display:flex;justify-content:flex-end;margin-bottom:14px;">
    <a href="{{ route('app.screen', 'board') }}" class="uj-btn-ghost" style="font-size:12px;padding:7px 12px;text-decoration:none;">
        <span x-text="$store.ui.lang==='en' ? '← My tasks' : '← Tugasan saya'">← My tasks</span>
    </a>
</div>
@include('partials.guide', [
    'key' => 'team-board',
    'en'  => [
        'title' => 'Team board — all tasks',
        'body'  => 'A read-only, company-wide view of every staff member\'s work. Each person has a lane with the same four columns as their own board. Use it to see who is carrying what at a glance.',
        'who'   => 'Management · HR · Immediate superiors',
        'steps' => [
            'Search a name to jump to one person\'s lane.',
            'Scan the four columns — To Do, In Progress, In Review, Done — for each person.',
            'Open a staff member\'s profile to assign them a task, or the personal board to move your own cards.',
        ],
    ],
    'ms'  => [
        'title' => 'Papan pasukan — semua tugasan',
        'body'  => 'Paparan baca-sahaja seluruh syarikat bagi kerja setiap staf. Setiap orang mempunyai lorong dengan empat lajur yang sama seperti papan mereka sendiri. Guna untuk lihat siapa memikul apa dengan pantas.',
        'who'   => 'Pengurusan · HR · Penyelia terdekat',
        'steps' => [
            'Cari nama untuk terus ke lorong seseorang.',
            'Imbas empat lajur — To Do, In Progress, In Review, Done — bagi setiap orang.',
            'Buka profil staf untuk menugaskan tugasan, atau papan peribadi untuk gerak kad anda sendiri.',
        ],
    ],
])

<div x-data="{ q: '' }">
    {{-- Summary + name filter --}}
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:18px;flex-wrap:wrap;">
        <div style="display:flex;align-items:center;gap:8px;">
            <span style="font-size:13px;font-weight:600;color:var(--ink);">{{ $teamPeople }}</span>
            <span style="font-size:12.5px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'people' : 'orang'">people</span>
            <span style="color:var(--hairline);">·</span>
            <span style="font-size:13px;font-weight:600;color:{{ $teamOpenTotal > 0 ? 'var(--amber)' : 'var(--ink)' }};">{{ $teamOpenTotal }}</span>
            <span style="font-size:12.5px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'open items' : 'item terbuka'">open items</span>
        </div>
        <div style="flex:1;"></div>
        <input x-model="q" type="search"
               :placeholder="$store.ui.lang==='en' ? 'Search a name…' : 'Cari nama…'"
               style="width:240px;max-width:100%;height:38px;padding:0 12px;border:1px solid var(--hairline);border-radius:9px;font-size:13px;background:#fff;color:var(--ink);outline:none;" />
    </div>

    @forelse ($teamLanes as $lane)
        @php $e = $lane['emp']; @endphp
        <div x-show="q === '' || @js(mb_strtolower($e->name)).includes(q.toLowerCase())"
             style="background:#fff;border:1px solid var(--hairline);border-radius:14px;padding:16px 16px 6px;margin-bottom:16px;box-shadow:0 2px 10px rgba(20,20,40,.04);">

            {{-- Lane header: who + how much open --}}
            <div style="display:flex;align-items:center;gap:11px;margin-bottom:14px;">
                <span style="flex-shrink:0;width:38px;height:38px;border-radius:9999px;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:600;color:#fff;background:{{ $e->avatar_color ?? 'var(--muted)' }};">{{ $e->initials }}</span>
                <div style="min-width:0;">
                    <a href="{{ route('app.screen', ['screen' => 'profile', 'emp' => $e->id]) }}"
                       style="font-size:14px;font-weight:600;color:var(--ink);text-decoration:none;">{{ $e->name }}</a>
                    <div style="font-size:11.5px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ trim(($e->positionBand?->title ?? '').' · '.($e->department?->name ?? ''), ' ·') }}</div>
                </div>
                <div style="flex:1;"></div>
                <span style="font-size:11px;font-weight:600;color:{{ $lane['open'] > 0 ? 'var(--amber)' : 'var(--muted)' }};background:{{ $lane['open'] > 0 ? '#fbf3e6' : 'var(--hairline-soft)' }};padding:3px 10px;border-radius:9999px;">
                    {{ $lane['open'] }} <span x-text="$store.ui.lang==='en' ? 'open' : 'terbuka'">open</span>
                </span>
            </div>

            {{-- Four read-only columns --}}
            <div style="display:flex;gap:12px;align-items:flex-start;overflow-x:auto;padding-bottom:10px;">
                @foreach ($lane['cols'] as $key => $col)
                    <div style="flex:1;min-width:220px;">
                        <div style="display:flex;align-items:center;gap:7px;padding:0 4px 10px;">
                            <span style="font-size:12px;font-weight:600;color:var(--muted);">{{ $col['title'] }}</span>
                            <span style="font-size:10.5px;font-weight:600;color:var(--muted);background:var(--hairline-soft);padding:1px 7px;border-radius:9999px;">{{ $col['cards']->count() }}</span>
                        </div>

                        <div style="display:flex;flex-direction:column;gap:9px;min-height:20px;">
                            @forelse ($col['cards'] as $c)
                                @php [$tlabel, $tcolor] = $tag[$c->type] ?? ['Task', 'var(--info)']; @endphp
                                <div class="wi-sm" @if ($key === 'done') style="opacity:.72;" @endif>
                                    <div class="wi-head">
                                        <span class="wi-tag" style="--wi-tag:{{ $tcolor }};">{{ $tlabel }}</span>
                                        @if ($c->priority)<span class="wi-pri-txt" style="--wi-pri:{{ $pri[$c->priority] }};">{{ $priLabel[$c->priority] ?? ucfirst($c->priority) }}</span>@endif
                                    </div>
                                    @if ($c->assigned_by_id)
                                        <div class="wi-assigned"><span x-text="$store.ui.lang==='en' ? 'Assigned by' : 'Ditugaskan oleh'">Assigned by</span> {{ $c->assignedBy?->name ?? '—' }}</div>
                                    @endif
                                    <div class="wi-title">{{ $c->title }}</div>
                                    <div class="wi-foot">
                                        <span>{{ $c->dueText() }}</span>
                                        <span class="wi-meta">
                                            @if (($c->comments_count ?? 0) > 0)
                                                <span class="wi-comment-chip"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>{{ $c->comments_count }}</span>
                                            @endif
                                            <span class="wi-est">{{ $c->estimate_hours ? $c->estimate_hours.'h' : '' }}</span>
                                        </span>
                                    </div>
                                </div>
                            @empty
                                <div style="border:1px dashed var(--hairline);border-radius:9px;padding:12px;text-align:center;font-size:11px;color:var(--muted-soft);">—</div>
                            @endforelse
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @empty
        <div style="border:1px dashed var(--hairline);border-radius:12px;padding:40px;text-align:center;color:var(--muted);">
            <div style="font-size:14px;font-weight:600;color:var(--ink);margin-bottom:6px;" x-text="$store.ui.lang==='en' ? 'No tasks yet' : 'Belum ada tugasan'">No tasks yet</div>
            <div style="font-size:12.5px;" x-text="$store.ui.lang==='en' ? 'Nobody has any work items on their board.' : 'Tiada sesiapa mempunyai item kerja pada papan mereka.'">Nobody has any work items on their board.</div>
        </div>
    @endforelse
</div>
@endsection
