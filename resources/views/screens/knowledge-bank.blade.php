@extends('layouts.app')

@php
    $readSet = collect($readIds);
    $starSet = collect($starredIds);
    $activeSeg = $filters['seg'] ?? null;
    $activeSub = $filters['subseg'] ?? null;
@endphp

@section('screen')
@include('partials.guide', [
    'key' => 'knowledge-bank',
    'en'  => [
        'title' => 'Knowledge Bank',
        'body'  => 'Every month, share one lesson you learned — a fix, a pitfall, a shortcut, anything the next person would thank you for. Browse what the rest of the company has shared and mark the ones that helped.',
        'who'   => 'Everyone shares monthly · HR sees who has contributed',
        'steps' => [
            'Use "Add a lesson" (top right) — pick a segment, give it a clear title and a short write-up.',
            'Filter by segment on the left, or search to find a past lesson fast.',
            'Tap "helpful" on entries that saved you time so the best ones stand out.',
        ],
    ],
    'ms'  => [
        'title' => 'Bank Pengetahuan',
        'body'  => 'Setiap bulan, kongsi satu pengajaran yang anda pelajari — penyelesaian, jebakan, jalan pintas, apa sahaja yang orang seterusnya akan hargai. Layari apa yang dikongsi seluruh syarikat dan tandakan yang membantu.',
        'who'   => 'Semua kongsi setiap bulan · HR nampak siapa telah menyumbang',
        'steps' => [
            'Guna "Add a lesson" (atas kanan) — pilih segmen, beri tajuk jelas dan penerangan ringkas.',
            'Tapis ikut segmen di sebelah kiri, atau cari untuk jumpa pengajaran lampau dengan pantas.',
            'Tekan "helpful" pada entri yang menjimatkan masa anda supaya yang terbaik menonjol.',
        ],
    ],
])

{{-- ── Culture stats ─────────────────────────────────────────────────────── --}}
<div style="display:flex;gap:16px;margin-bottom:18px;flex-wrap:wrap;">
    <div class="uj-card" style="flex:1;min-width:170px;padding:18px 20px;">
        <div style="font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;" x-text="$store.ui.lang==='en' ? 'Total lessons' : 'Jumlah pengajaran'">Total lessons</div>
        <div style="font-family:var(--font-mono);font-size:28px;font-weight:600;color:var(--ink);margin-top:6px;">{{ $total }}</div>
    </div>
    <div class="uj-card" style="flex:1;min-width:170px;padding:18px 20px;">
        <div style="font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;" x-text="$store.ui.lang==='en' ? 'Segments' : 'Segmen'">Segments</div>
        <div style="font-family:var(--font-mono);font-size:28px;font-weight:600;color:var(--ink);margin-top:6px;">{{ $segCount }}</div>
    </div>
    <div class="uj-card" style="flex:1;min-width:170px;padding:18px 20px;">
        <div style="font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;" x-text="$store.ui.lang==='en' ? '% Contributed' : '% Menyumbang'">% Contributed</div>
        <div style="font-family:var(--font-mono);font-size:28px;font-weight:600;color:var(--success);margin-top:6px;">{{ $contribPct }}%</div>
    </div>
    <div style="flex:1;min-width:170px;padding:18px 20px;border-radius:12px;background:var(--sidebar);">
        <div style="font-size:11px;font-weight:600;color:var(--sidebar-text);text-transform:uppercase;letter-spacing:.5px;" x-text="$store.ui.lang==='en' ? 'Team streak' : 'Streak pasukan'">Team streak</div>
        <div style="font-family:var(--font-mono);font-size:28px;font-weight:600;color:#fff;margin-top:6px;">{{ $teamStreak }} <span style="font-size:13px;color:var(--sidebar-text);font-weight:500;" x-text="$store.ui.lang==='en' ? ({{ $teamStreak }}===1?'month':'months') : 'bulan'">months</span></div>
    </div>
</div>

{{-- ── Search + Add ──────────────────────────────────────────────────────── --}}
<div style="display:flex;gap:12px;margin-bottom:16px;flex-wrap:wrap;align-items:center;">
    <form method="get" action="{{ route('app.screen', 'knowledge-bank') }}" style="flex:1;min-width:260px;position:relative;">
        @if ($activeSeg)<input type="hidden" name="seg" value="{{ $activeSeg }}">@endif
        @if ($activeSub)<input type="hidden" name="subseg" value="{{ $activeSub }}">@endif
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--muted-soft)" stroke-width="2" stroke-linecap="round" style="position:absolute;left:13px;top:50%;transform:translateY(-50%);pointer-events:none;"><circle cx="11" cy="11" r="8"></circle><path d="M21 21l-4-4"></path></svg>
        <input name="q" value="{{ $filters['q'] }}" @input.debounce.500ms="$el.form.submit()" :placeholder="$store.ui.lang==='en' ? 'Search lessons…' : 'Cari pengajaran…'"
               style="width:100%;height:42px;padding:0 14px 0 38px;background:var(--canvas);border:1px solid var(--hairline);border-radius:8px;font-size:13.5px;color:var(--ink);outline:none;" />
    </form>
    @if ($canSubmit)
        <button @click="kb = true; kbView = 'add'" class="uj-btn-primary" style="height:42px;padding:0 18px;font-size:13.5px;display:flex;align-items:center;gap:7px;flex-shrink:0;">
            <span style="font-size:17px;line-height:1;">＋</span><span x-text="$store.ui.lang==='en' ? 'Add a lesson' : 'Tambah pengajaran'">Add a lesson</span>
        </button>
    @endif
</div>

{{-- ── Two-column layout ─────────────────────────────────────────────────── --}}
<div style="display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap;">
    {{-- Left: segment tree --}}
    <div class="uj-card" style="width:220px;flex-shrink:0;padding:8px;min-width:200px;">
        <div style="display:flex;align-items:center;justify-content:space-between;padding:8px 10px 6px;">
            <span style="font-size:10.5px;font-weight:700;letter-spacing:.7px;text-transform:uppercase;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'Segments' : 'Segmen'">Segments</span>
            <span style="font-size:11px;font-family:var(--font-mono);color:var(--muted-soft);">{{ $segments->count() }}</span>
        </div>

        <a href="{{ route('app.screen', 'knowledge-bank') }}"
           style="display:flex;align-items:center;width:100%;padding:9px 11px;border-radius:8px;text-decoration:none;font-size:13px;font-weight:500;margin-bottom:2px;color:{{ ! $activeSeg ? '#fff' : 'var(--body)' }};background:{{ ! $activeSeg ? 'var(--red)' : 'transparent' }};"
           x-text="$store.ui.lang==='en' ? 'All lessons' : 'Semua pengajaran'">All lessons</a>

        @foreach ($segments as $seg)
            @php $segActive = (int) $activeSeg === $seg->id; @endphp
            <div x-data="{ open: {{ $segActive && $seg->children->count() ? 'true' : 'false' }} }" style="margin-bottom:1px;">
                <div style="display:flex;align-items:center;gap:2px;">
                    <a href="{{ route('app.screen', ['screen' => 'knowledge-bank', 'seg' => $seg->id]) }}"
                       style="flex:1;display:flex;align-items:center;justify-content:space-between;padding:8px 11px;border-radius:8px;text-decoration:none;font-size:13px;font-weight:500;color:{{ $segActive && ! $activeSub ? '#fff' : 'var(--body)' }};background:{{ $segActive && ! $activeSub ? 'var(--red)' : 'transparent' }};">
                        <span style="min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ $seg->label }}</span>
                        <span style="font-size:11px;font-family:var(--font-mono);color:{{ $segActive && ! $activeSub ? 'rgba(255,255,255,.7)' : 'var(--muted-soft)' }};">{{ $seg->entries()->count() }}</span>
                    </a>
                    @if ($seg->children->count())
                        <button @click="open = !open" type="button" style="width:30px;height:32px;flex-shrink:0;display:flex;align-items:center;justify-content:center;background:none;color:var(--muted-soft);font-size:11px;border-radius:7px;cursor:pointer;" x-text="open ? '▾' : '▸'"></button>
                    @endif
                </div>
                @if ($seg->children->count())
                    <div x-show="open" x-cloak style="margin:1px 0 4px;">
                        @foreach ($seg->children as $child)
                            @php $subActive = (int) $activeSub === $child->id; @endphp
                            <a href="{{ route('app.screen', ['screen' => 'knowledge-bank', 'seg' => $seg->id, 'subseg' => $child->id]) }}"
                               style="display:block;padding:6px 11px 6px 28px;border-radius:7px;text-decoration:none;font-size:12.5px;color:{{ $subActive ? 'var(--red)' : 'var(--muted)' }};font-weight:{{ $subActive ? '600' : '400' }};">{{ $child->label }}</a>
                        @endforeach
                    </div>
                @endif
            </div>
        @endforeach

        @if ($canSubmit)
            <button @click="kb = true; kbView = 'newseg'" type="button"
                    style="width:100%;margin-top:6px;padding:9px 11px;border-top:1px solid var(--hairline-soft);background:none;color:var(--muted);font-size:12.5px;font-weight:500;text-align:left;cursor:pointer;">
                <span x-text="$store.ui.lang==='en' ? '+ Add segment' : '+ Tambah segmen'">+ Add segment</span>
            </button>
        @endif
    </div>

    {{-- Right: compliance + entries --}}
    <div style="flex:1;min-width:340px;display:flex;flex-direction:column;gap:16px;">
        @if ($privileged && $compliance)
            <div class="uj-card" style="padding:20px;">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px;">
                    <h3 class="uj-card-title"><span x-text="$store.ui.lang==='en' ? @js($monthEn.' contributions') : @js($monthMs.' sumbangan')">{{ $monthEn }} contributions</span></h3>
                    <span style="font-size:12.5px;font-weight:600;font-family:var(--font-mono);color:var(--success);">{{ $compliance['progressPct'] }}%</span>
                </div>
                <p style="font-size:12.5px;color:var(--muted);margin:0 0 12px;" x-text="$store.ui.lang==='en' ? 'Who has shared a lesson this month.' : 'Siapa telah kongsi pengajaran bulan ini.'">Who has shared a lesson this month.</p>
                <div style="height:5px;background:var(--hairline);border-radius:9999px;overflow:hidden;margin-bottom:16px;"><div style="height:100%;width:{{ $compliance['progressPct'] }}%;background:var(--success);"></div></div>
                @php $pending = collect($compliance['members'])->reject(fn ($m) => $m['submitted']); @endphp
                @if ($pending->isEmpty())
                    <p style="font-size:12.5px;color:var(--success);font-weight:600;margin:0;" x-text="$store.ui.lang==='en' ? 'Everyone has shared this month.' : 'Semua telah kongsi bulan ini.'">Everyone has shared this month.</p>
                @else
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:8px;">
                    @foreach ($compliance['members'] as $m)
                        @continue($m['submitted'])
                        <div style="display:flex;align-items:center;gap:9px;padding:9px 11px;border-radius:9px;background:{{ $m['submitted'] ? '#e9f5ef' : 'var(--red-tint)' }};">
                            <span style="width:28px;height:28px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;color:#fff;font-size:10.5px;font-weight:600;background:{{ $m['color'] ?? '#3a6ea5' }};">{{ $m['initials'] }}</span>
                            <div style="min-width:0;flex:1;">
                                <div style="font-size:12px;font-weight:500;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ $m['name'] }}</div>
                                <div style="font-size:10.5px;font-weight:600;color:{{ $m['submitted'] ? 'var(--success)' : 'var(--error)' }};">
                                    @if ($m['submitted'])<span x-text="$store.ui.lang==='en' ? 'Submitted' : 'Dihantar'">Submitted</span>@else<span x-text="$store.ui.lang==='en' ? 'Not yet' : 'Belum'">Not yet</span>@endif
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
                @endif
            </div>
        @endif

        {{-- Entry cards --}}
        @forelse ($entries as $e)
            @php
                $isNew = ! $readSet->contains($e->id) && $e->created_at && $e->created_at->gte($newCutoff);
                $starred = $starSet->contains($e->id);
            @endphp
            <div class="uj-card" style="padding:16px 18px;" x-data="{ openC: false }">
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:8px;">
                    <span style="font-size:11px;font-weight:500;color:var(--body);background:var(--canvas);border:1px solid var(--hairline);padding:3px 9px;border-radius:9999px;">{{ $e->segment?->label }}</span>
                    @if ($e->subSegment)
                        <span style="font-size:11px;font-weight:500;color:var(--muted);background:var(--canvas);border:1px solid var(--hairline);padding:3px 9px;border-radius:9999px;">{{ $e->subSegment->label }}</span>
                    @endif
                    @if ($isNew)
                        <span style="display:inline-flex;align-items:center;gap:5px;font-size:10.5px;font-weight:600;color:var(--red);"><span style="width:7px;height:7px;border-radius:50%;background:var(--red);"></span><span x-text="$store.ui.lang==='en' ? 'New' : 'Baharu'">New</span></span>
                    @endif
                    <span style="margin-left:auto;font-size:11px;font-family:var(--font-mono);color:var(--muted-soft);">{{ $e->created_at?->format('d M Y') }}</span>
                </div>
                <h3 style="font-size:15px;font-weight:600;color:var(--ink);margin:0 0 6px;">{{ $e->title }}</h3>
                <div style="font-size:13px;color:var(--body);line-height:1.6;margin:0 0 12px;">{!! \App\Support\Amanahku::linkify($e->body) !!}</div>
                @if (! empty($e->tags))
                    <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px;">
                        @foreach ($e->tags as $tag)
                            <span style="font-size:11px;color:var(--muted);background:var(--hairline-soft);padding:2px 8px;border-radius:6px;">#{{ $tag }}</span>
                        @endforeach
                    </div>
                @endif
                <div style="display:flex;align-items:center;gap:10px;padding-top:10px;border-top:1px solid var(--hairline-soft);">
                    <span style="width:32px;height:32px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;color:#fff;font-size:11px;font-weight:600;background:{{ $e->employee?->avatar_color ?? '#3a6ea5' }};">{{ $e->employee?->initials ?? '–' }}</span>
                    <div style="flex:1;min-width:0;">
                        <div style="font-size:12.5px;font-weight:600;color:var(--ink);">{{ $e->employee?->name ?? 'Unknown' }}</div>
                        @if ($e->employee?->position)<div style="font-size:10.5px;color:var(--muted);">{{ $e->employee->position }}</div>@endif
                    </div>

                    {{-- Star (one per person; toggle) --}}
                    @if ($canSubmit)
                        <form method="post" action="{{ route('knowledge.star', $e) }}">
                            @csrf
                            <button type="submit" :title="$store.ui.lang==='en' ? @js($starred ? 'Remove star' : 'Star this lesson') : @js($starred ? 'Buang bintang' : 'Bintangkan')" style="display:flex;align-items:center;gap:6px;font-size:12px;font-family:var(--font-mono);background:none;border:1px solid {{ $starred ? 'var(--amber)' : 'var(--hairline)' }};border-radius:8px;padding:5px 10px;cursor:pointer;color:{{ $starred ? 'var(--amber)' : 'var(--muted)' }};">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="{{ $starred ? 'var(--amber)' : 'none' }}" stroke="{{ $starred ? 'var(--amber)' : 'currentColor' }}" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                                <span>{{ $e->stars_count }}</span>
                            </button>
                        </form>
                    @else
                        <span style="display:flex;align-items:center;gap:6px;font-size:12px;font-family:var(--font-mono);color:var(--muted);"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>{{ $e->stars_count }}</span>
                    @endif

                    {{-- Comments toggle --}}
                    <button @click="openC = !openC" style="display:flex;align-items:center;gap:6px;font-size:12px;font-family:var(--font-mono);background:none;border:1px solid var(--hairline);border-radius:8px;padding:5px 10px;cursor:pointer;color:var(--muted);">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                        <span>{{ $e->comments_count }}</span>
                    </button>
                </div>

                {{-- Comments thread --}}
                <div x-show="openC" x-cloak style="margin-top:12px;padding-top:12px;border-top:1px solid var(--hairline-soft);display:flex;flex-direction:column;gap:12px;">
                    @forelse ($e->comments as $c)
                        <div style="display:flex;gap:10px;">
                            <span style="width:28px;height:28px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;color:#fff;font-size:10px;font-weight:600;background:{{ $c->employee?->avatar_color ?? '#3a6ea5' }};">{{ $c->employee?->initials ?? '–' }}</span>
                            <div style="flex:1;min-width:0;">
                                <div style="display:flex;align-items:center;gap:8px;">
                                    <span style="font-size:12px;font-weight:600;color:var(--ink);">{{ $c->employee?->name ?? 'Unknown' }}</span>
                                    <span style="font-size:10.5px;font-family:var(--font-mono);color:var(--muted-soft);">{{ $c->created_at?->diffForHumans() }}</span>
                                    @if ($privileged || ($employee && $c->employee_id === $employee->id))
                                        <form method="post" action="{{ route('knowledge.comments.delete', $c) }}" style="margin-left:auto;">
                                            @csrf @method('DELETE')
                                            <button type="submit" :title="$store.ui.lang==='en' ? 'Delete' : 'Padam'" style="font-size:11px;color:var(--muted-soft);background:none;cursor:pointer;">×</button>
                                        </form>
                                    @endif
                                </div>
                                <div style="font-size:12.5px;color:var(--body);line-height:1.5;margin-top:2px;">{!! \App\Support\Amanahku::linkify($c->body) !!}</div>
                            </div>
                        </div>
                    @empty
                        <div style="font-size:12px;color:var(--muted-soft);" x-text="$store.ui.lang==='en' ? 'No comments yet — start the discussion.' : 'Tiada komen lagi — mulakan perbincangan.'">No comments yet.</div>
                    @endforelse

                    @if ($canSubmit)
                        <form method="post" action="{{ route('knowledge.comments', $e) }}" style="display:flex;gap:8px;align-items:flex-end;">
                            @csrf
                            <textarea name="body" required maxlength="2000" rows="1" :placeholder="$store.ui.lang==='en' ? 'Add a comment…' : 'Tambah komen…'" style="flex:1;padding:8px 11px;border:1px solid var(--hairline);border-radius:8px;font-size:12.5px;resize:vertical;outline:none;font-family:inherit;line-height:1.4;"></textarea>
                            <button type="submit" class="uj-btn-primary" style="height:36px;padding:0 14px;font-size:12.5px;flex-shrink:0;"><span x-text="$store.ui.lang==='en' ? 'Post' : 'Hantar'">Post</span></button>
                        </form>
                    @endif
                </div>
            </div>
        @empty
            <div class="uj-card" style="padding:40px 24px;text-align:center;">
                <div style="font-size:14px;color:var(--ink);font-weight:500;margin-bottom:4px;"><span x-text="$store.ui.lang==='en' ? 'No lessons here yet' : 'Belum ada pengajaran di sini'"></span></div>
                <div style="font-size:12.5px;color:var(--muted);line-height:1.5;max-width:420px;margin:0 auto;"><span x-text="$store.ui.lang==='en' ? 'Be the first to share — use \'Add a lesson\'. Every shared lesson becomes part of the company\'s collective knowledge.' : 'Jadilah yang pertama berkongsi — guna \'Add a lesson\'. Setiap pengajaran menjadi sebahagian pengetahuan kolektif syarikat.'"></span></div>
            </div>
        @endforelse

        @if ($entries->hasPages())
            <div style="padding:6px 2px;">{{ $entries->onEachSide(1)->links() }}</div>
        @endif
    </div>
</div>
@endsection
