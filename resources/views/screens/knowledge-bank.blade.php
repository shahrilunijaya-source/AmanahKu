@extends('layouts.app')

@php
    $readSet = collect($readIds);
    $starSet = collect($starredIds);
    $activeSeg = $filters['seg'] ?? null;
    $activeSub = $filters['subseg'] ?? null;
    $activeSegModel = $activeSeg ? $segments->firstWhere('id', (int) $activeSeg) : null;
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
            'Filter by segment with the chips, or search to find a past lesson fast.',
            'Tap "helpful" on entries that saved you time so the best ones stand out.',
        ],
    ],
    'ms'  => [
        'title' => 'Bank Pengetahuan',
        'body'  => 'Setiap bulan, kongsi satu pengajaran yang anda pelajari — penyelesaian, jebakan, jalan pintas, apa sahaja yang orang seterusnya akan hargai. Layari apa yang dikongsi seluruh syarikat dan tandakan yang membantu.',
        'who'   => 'Semua kongsi setiap bulan · HR nampak siapa telah menyumbang',
        'steps' => [
            'Guna "Add a lesson" (atas kanan) — pilih segmen, beri tajuk jelas dan penerangan ringkas.',
            'Tapis ikut segmen dengan cip, atau cari untuk jumpa pengajaran lampau dengan pantas.',
            'Tekan "helpful" pada entri yang menjimatkan masa anda supaya yang terbaik menonjol.',
        ],
    ],
])

{{-- ── Hero + contributions carousel ─────────────────────────────────────────
     Two full-width slides you swipe between: the culture stats, then (for HR /
     management) the monthly compliance grid. Dots drive it on desktop where
     there is no touch swipe. Single slide for everyone else — no dots.
     The track height follows the slide you are on (`fit()`), so a long
     compliance grid never stretches the Overview slide with it. --}}
@php $hasCompliance = $canSeeCompliance && $compliance; @endphp
<div x-data="{ i: 0, h: 0,
        go(n) { this.$refs.kbTrack.scrollTo({ left: n * this.$refs.kbTrack.clientWidth, behavior: 'smooth' }); },
        fit() { this.h = this.$refs.kbTrack.children[this.i]?.offsetHeight ?? 0; } }"
     x-init="$nextTick(() => fit())" @resize.window.debounce.100ms="fit()" style="margin-bottom:20px;">
    <div x-ref="kbTrack" @scroll.passive.debounce.60ms="i = Math.round($el.scrollLeft / $el.clientWidth); fit()"
         :style="{ height: h ? h + 'px' : null }"
         style="display:flex;align-items:flex-start;overflow-x:auto;overflow-y:hidden;scroll-snap-type:x mandatory;scrollbar-width:none;-webkit-overflow-scrolling:touch;transition:height .2s ease;">

        {{-- Slide 1 — culture stats --}}
        <div style="flex:0 0 100%;scroll-snap-align:start;">
            <div style="background:var(--shelf);border:1px solid var(--shelf-line);border-radius:14px;padding:24px 26px 20px;">
                <div style="font-size:11px;letter-spacing:.19em;text-transform:uppercase;color:var(--muted);font-weight:600;" x-text="$store.ui.lang==='en' ? 'Knowledge bank' : 'Bank pengetahuan'">Knowledge bank</div>
                <div style="font:600 54px/1 var(--font-mono);color:var(--ink);margin-top:8px;letter-spacing:-.03em;">{{ $total }}</div>
                <div style="font-size:13px;color:var(--body);margin-top:7px;">
                    <span x-text="$store.ui.lang==='en'
                        ? 'One lesson from everyone, every month.{{ $teamStreak > 0 ? ' '.$teamStreak.' '.($teamStreak === 1 ? 'month' : 'months').' unbroken.' : '' }}'
                        : 'Satu pengajaran daripada semua, setiap bulan.{{ $teamStreak > 0 ? ' '.$teamStreak.' bulan berturut.' : '' }}'">One lesson from everyone, every month.</span>
                </div>
                <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:18px;">
                    <div style="background:#fff;border:1px solid var(--shelf-line);border-radius:9px;padding:8px 13px;display:flex;align-items:baseline;gap:7px;">
                        <b style="font:600 18px var(--font-mono);color:var(--ink);line-height:1;">{{ $segCount }}</b>
                        <span style="font-size:11px;letter-spacing:.04em;color:var(--body);font-weight:500;" x-text="$store.ui.lang==='en' ? 'Segments' : 'Segmen'">Segments</span>
                    </div>
                    <div style="background:#fff;border:1px solid var(--shelf-line);border-radius:9px;padding:8px 13px;display:flex;align-items:baseline;gap:7px;">
                        <b style="font:600 18px var(--font-mono);color:var(--success);line-height:1;">{{ $contribPct }}%</b>
                        <span style="font-size:11px;letter-spacing:.04em;color:var(--body);font-weight:500;" x-text="$store.ui.lang==='en' ? 'Contributed' : 'Menyumbang'">Contributed</span>
                    </div>
                    <div style="background:#fff;border:1px solid var(--shelf-line);border-radius:9px;padding:8px 13px;display:flex;align-items:baseline;gap:7px;">
                        <b style="font:600 18px var(--font-mono);color:var(--ink);line-height:1;">{{ $teamStreak }}</b>
                        <span style="font-size:11px;letter-spacing:.04em;color:var(--body);font-weight:500;" x-text="$store.ui.lang==='en' ? ({{ $teamStreak }}===1 ? 'month streak' : 'months streak') : 'bulan berturut'">months streak</span>
                    </div>
                </div>
            </div>
        </div>

        @if ($hasCompliance)
        {{-- Slide 2 — monthly compliance grid (HR / management) --}}
        <div style="flex:0 0 100%;scroll-snap-align:start;">
            <div style="background:#fff;border:1px solid var(--hairline);border-radius:14px;padding:20px;">
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
                        <div style="display:flex;align-items:center;gap:9px;padding:9px 11px;border-radius:9px;background:var(--red-tint);">
                            <span style="width:28px;height:28px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;color:#fff;font-size:11px;font-weight:600;background:{{ $m['color'] ?? '#3a6ea5' }};">{{ $m['initials'] }}</span>
                            <div style="min-width:0;flex:1;">
                                <div style="font-size:12px;font-weight:500;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ $m['name'] }}</div>
                                <div style="font-size:10.5px;font-weight:600;color:var(--error);" x-text="$store.ui.lang==='en' ? 'Not yet' : 'Belum'">Not yet</div>
                            </div>
                        </div>
                    @endforeach
                </div>
                @endif
            </div>
        </div>
        @endif
    </div>

    @if ($hasCompliance)
    {{-- Labeled tab switcher — clearer than dots about what each slide holds.
         Arrows flank it so a swipe is never the only way across. --}}
    @php $tab = 'height:30px;padding:0 15px;border:0;border-radius:999px;font-size:12.5px;font-weight:500;cursor:pointer;transition:background .15s,color .15s;'; @endphp
    <div style="display:flex;align-items:center;justify-content:center;gap:8px;margin-top:14px;">
        <button type="button" @click="go(Math.max(0, i - 1))" :disabled="i === 0"
                :style="'width:30px;height:30px;border:1px solid var(--shelf-line);border-radius:50%;background:#fff;display:flex;align-items:center;justify-content:center;transition:opacity .15s;'+(i === 0 ? 'opacity:.35;cursor:default;' : 'cursor:pointer;')"
                :aria-label="$store.ui.lang==='en' ? 'Previous' : 'Sebelum'">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--muted)" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg>
        </button>

        <div style="display:inline-flex;background:var(--shelf);border:1px solid var(--shelf-line);border-radius:999px;padding:3px;">
            <button type="button" @click="go(0)" :style="'{{ $tab }}'+(i === 0 ? 'background:var(--ink);color:#fff;' : 'background:none;color:var(--muted);')"
                    x-text="$store.ui.lang==='en' ? 'Overview' : 'Ringkasan'">Overview</button>
            <button type="button" @click="go(1)" :style="'{{ $tab }}'+(i === 1 ? 'background:var(--ink);color:#fff;' : 'background:none;color:var(--muted);')"
                    x-text="$store.ui.lang==='en' ? @js($monthEn.' contributions') : @js($monthMs.' sumbangan')">{{ $monthEn }} contributions</button>
        </div>

        <button type="button" @click="go(Math.min(1, i + 1))" :disabled="i === 1"
                :style="'width:30px;height:30px;border:1px solid var(--shelf-line);border-radius:50%;background:#fff;display:flex;align-items:center;justify-content:center;transition:opacity .15s;'+(i === 1 ? 'opacity:.35;cursor:default;' : 'cursor:pointer;')"
                :aria-label="$store.ui.lang==='en' ? 'Next' : 'Seterusnya'">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--muted)" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6"/></svg>
        </button>
    </div>
    @endif
</div>

{{-- ── Search ────────────────────────────────────────────────────────────── --}}
<form method="get" action="{{ route('app.screen', 'knowledge-bank') }}" style="position:relative;margin-bottom:16px;">
    @if ($activeSeg)<input type="hidden" name="seg" value="{{ $activeSeg }}">@endif
    @if ($activeSub)<input type="hidden" name="subseg" value="{{ $activeSub }}">@endif
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--muted-soft)" stroke-width="2" stroke-linecap="round" style="position:absolute;left:13px;top:50%;transform:translateY(-50%);pointer-events:none;"><circle cx="11" cy="11" r="8"></circle><path d="M21 21l-4-4"></path></svg>
    <input name="q" value="{{ $filters['q'] }}" @input.debounce.500ms="$el.form.submit()" :placeholder="$store.ui.lang==='en' ? 'Search lessons…' : 'Cari pengajaran…'"
           style="width:100%;height:42px;padding:0 14px 0 38px;background:var(--canvas);border:1px solid var(--hairline);border-radius:8px;font-size:13.5px;color:var(--ink);outline:none;" />
</form>

{{-- ── Segment chips + Add ───────────────────────────────────────────────── --}}
@php $chipBase = 'height:32px;padding:0 15px;border-radius:999px;font-size:12.5px;font-weight:500;cursor:pointer;white-space:nowrap;text-decoration:none;display:inline-flex;align-items:center;gap:7px;flex-shrink:0;'; @endphp
<div style="display:flex;align-items:center;gap:8px;" x-data="{ addSeg: {{ $errors->any() && old('kbform') === 'newseg' ? 'true' : 'false' }} }">
    <div style="display:flex;align-items:center;gap:8px;overflow-x:auto;scrollbar-width:none;-webkit-overflow-scrolling:touch;flex:1;min-width:0;">
        <a href="{{ route('app.screen', 'knowledge-bank') }}"
           style="{{ $chipBase }}border:1px solid {{ ! $activeSeg ? 'var(--ink)' : 'var(--shelf-line)' }};background:{{ ! $activeSeg ? 'var(--ink)' : 'transparent' }};color:{{ ! $activeSeg ? '#fff' : 'var(--muted)' }};"
           x-text="$store.ui.lang==='en' ? 'All lessons' : 'Semua'">All lessons</a>
        @foreach ($segments as $seg)
            @php $segActive = (int) $activeSeg === $seg->id; @endphp
            <a href="{{ route('app.screen', ['screen' => 'knowledge-bank', 'seg' => $seg->id]) }}"
               style="{{ $chipBase }}border:1px solid {{ $segActive ? 'var(--ink)' : 'var(--shelf-line)' }};background:{{ $segActive ? 'var(--ink)' : 'transparent' }};color:{{ $segActive ? '#fff' : 'var(--muted)' }};">
                <span>{{ $seg->label }}</span>
            </a>
        @endforeach
        @if ($canSubmit)
            <button @click="addSeg = ! addSeg" type="button" style="{{ $chipBase }}border:1px dashed var(--shelf-line);background:transparent;color:var(--muted);">
                <span style="font-size:14px;line-height:1;">＋</span><span x-text="$store.ui.lang==='en' ? 'Segment' : 'Segmen'">Segment</span>
            </button>
        @endif
    </div>
    @if ($canSubmit)
        <button @click="kb = true; kbView = 'add'" type="button" style="height:32px;padding:0 15px;background:var(--red);color:#fff;border:0;border-radius:999px;font-size:12.5px;font-weight:500;cursor:pointer;display:inline-flex;align-items:center;gap:6px;flex-shrink:0;">
            <span style="font-size:15px;line-height:1;">＋</span><span x-text="$store.ui.lang==='en' ? 'Add a lesson' : 'Tambah pengajaran'">Add a lesson</span>
        </button>
    @endif
</div>

@if ($canSubmit)
    {{-- Inline segment/sub-segment creation — the only place to add either, top-level or nested. --}}
    <div x-show="addSeg" x-cloak x-transition.opacity.duration.150ms style="margin-top:10px;padding:14px 16px;background:var(--shelf);border:1px solid var(--shelf-line);border-radius:12px;">
        @if ($errors->any() && old('kbform') === 'newseg')
            <div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12px;border-radius:8px;padding:9px 12px;margin-bottom:12px;">{{ $errors->first() }}</div>
        @endif
        <form method="post" action="{{ route('knowledge.segments') }}" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">
            @csrf
            <input type="hidden" name="kbform" value="newseg">
            <div style="flex:1;min-width:160px;">
                <label style="display:block;font-size:11.5px;font-weight:500;color:var(--muted);margin-bottom:5px;" x-text="$store.ui.lang==='en' ? 'Segment name' : 'Nama segmen'">Segment name</label>
                <input name="label" value="{{ old('label') }}" required maxlength="80" :placeholder="$store.ui.lang==='en' ? 'e.g. Vendor Management' : 'cth. Pengurusan Vendor'" style="width:100%;height:38px;padding:0 12px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;background:#fff;outline:none;" />
            </div>
            <div style="min-width:150px;">
                <label style="display:block;font-size:11.5px;font-weight:500;color:var(--muted);margin-bottom:5px;" x-text="$store.ui.lang==='en' ? 'Parent (optional)' : 'Induk (pilihan)'">Parent (optional)</label>
                <select name="parent_id" style="width:100%;height:38px;padding:0 10px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;background:#fff;outline:none;">
                    <option value="" x-text="$store.ui.lang==='en' ? 'Top-level segment' : 'Segmen peringkat atas'">Top-level segment</option>
                    @foreach ($segments as $seg)
                        <option value="{{ $seg->id }}" @selected((string) old('parent_id') === (string) $seg->id)>{{ $seg->label }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="uj-btn-primary" style="height:38px;padding:0 16px;font-size:12.5px;"><span x-text="$store.ui.lang==='en' ? 'Create' : 'Cipta'">Create</span></button>
            <button type="button" @click="addSeg = false" style="height:38px;padding:0 14px;border:1px solid var(--hairline);border-radius:8px;background:#fff;color:var(--body);font-size:12.5px;font-weight:500;cursor:pointer;" x-text="$store.ui.lang==='en' ? 'Cancel' : 'Batal'">Cancel</button>
        </form>
    </div>
@endif

{{-- Sub-segment chips (only when the active segment has children) --}}
@if ($activeSegModel && $activeSegModel->children->count())
    <div style="display:flex;align-items:center;gap:7px;flex-wrap:wrap;margin-top:10px;padding-left:2px;">
        @foreach ($activeSegModel->children as $child)
            @php $subActive = (int) $activeSub === $child->id; @endphp
            <a href="{{ route('app.screen', ['screen' => 'knowledge-bank', 'seg' => $activeSegModel->id, 'subseg' => $child->id]) }}"
               style="height:27px;padding:0 12px;border-radius:999px;font-size:11.5px;font-weight:500;text-decoration:none;display:inline-flex;align-items:center;border:1px solid {{ $subActive ? 'var(--red)' : 'var(--hairline)' }};background:{{ $subActive ? 'var(--red-tint)' : 'transparent' }};color:{{ $subActive ? 'var(--red)' : 'var(--muted)' }};">{{ $child->label }}</a>
        @endforeach
    </div>
@endif

{{-- ── Entries — each opens a slide-over detail panel ────────────────────────── --}}
<div style="margin-top:20px;">
    @forelse ($entries as $e)
        @php
            $isNew = ! $readSet->contains($e->id) && $e->created_at && $e->created_at->gte($newCutoff);
            $starred = $starSet->contains($e->id);
        @endphp
        <div style="border-top:1px solid var(--hairline);"
             x-data="kbCard({
                 id: {{ $e->id }},
                 title: @js($e->title),
                 body: @js($e->body),
                 bodyHtml: @js(\App\Support\Amanahku::linkify($e->body)),
                 canEdit: {{ $employee && $employee->id === $e->employee_id ? 'true' : 'false' }},
                 reactions: @js((object) ($reactionCounts[$e->id] ?? [])),
                 mine: @js($myReactions[$e->id] ?? []),
                 stars: {{ $e->stars_count }},
                 starred: {{ $starred ? 'true' : 'false' }},
                 commentsCount: {{ $e->comments_count }},
                 attachments: @js($e->attachments->map(fn ($a) => [
                     'id' => $a->id,
                     'url' => route('knowledge.attachments.show', $a),
                     'caption' => $a->caption,
                 ])->values()),
             })">
            {{-- Summary row — click opens the detail panel (drawer) --}}
            <div @click="openDrawer()" style="display:grid;grid-template-columns:56px minmax(0,1fr) auto;gap:18px;align-items:start;padding:22px 4px;cursor:pointer;">
                <div style="border-radius:9px;padding:6px 0;text-align:center;margin-top:2px;">
                    <div style="font:600 10px 'Poppins',sans-serif;letter-spacing:.14em;text-transform:uppercase;color:var(--muted);">{{ $e->created_at?->format('M') }}</div>
                    <div style="font:600 22px/1 var(--font-mono);margin-top:2px;letter-spacing:-.03em;color:var(--ink);">{{ $e->created_at?->format('d') }}</div>
                </div>
                <div style="min-width:0;">
                    <div style="display:flex;align-items:center;gap:9px;flex-wrap:wrap;">
                        <span style="font-size:11.5px;color:var(--muted);">{{ $e->segment?->label }}</span>
                        @if ($e->subSegment)
                            <span style="width:3px;height:3px;border-radius:50%;background:#c9c6bc;"></span>
                            <span style="font-size:11.5px;color:var(--muted);">{{ $e->subSegment->label }}</span>
                        @endif
                        @if ($isNew)
                            <span style="display:inline-flex;align-items:center;gap:5px;font-size:10.5px;font-weight:600;color:var(--red);"><span style="width:7px;height:7px;border-radius:50%;background:var(--red);"></span><span x-text="$store.ui.lang==='en' ? 'New' : 'Baharu'">New</span></span>
                        @endif
                    </div>
                    <div style="font-size:18px;font-weight:600;line-height:1.35;margin-top:6px;color:var(--ink);">{{ $e->title }}</div>
                    <div style="font-size:13px;color:var(--muted-soft);margin-top:6px;">{{ $e->employee?->name ?? 'Unknown' }}@if ($e->employee?->position) · {{ $e->employee->position }}@endif</div>
                </div>
                <div style="display:flex;align-items:center;gap:14px;flex-shrink:0;padding-top:4px;">
                    <span x-show="reactionTotal" class="kb-reaction-pill" style="align-items:center;gap:4px;font-size:12.5px;color:var(--muted-soft);white-space:nowrap;">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1-1.1a5.5 5.5 0 0 0-7.8 7.8l1.1 1L12 21l7.7-7.6 1.1-1a5.5 5.5 0 0 0 0-7.8z"/></svg>
                        <span x-text="reactionTotal"></span>
                    </span>
                    <span style="display:inline-flex;align-items:center;gap:4px;font-size:12.5px;color:var(--muted-soft);white-space:nowrap;">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2l3.1 6.3 6.9 1-5 4.9 1.2 6.9L12 17.8 5.8 21l1.2-6.9-5-4.9 6.9-1z"/></svg>
                        <span x-text="stars"></span>
                    </span>
                    <span style="display:inline-flex;align-items:center;gap:4px;font-size:12.5px;color:var(--muted-soft);white-space:nowrap;">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                        <span x-text="commentsCount"></span>
                    </span>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--muted)" stroke-width="2" style="flex-shrink:0;"><path d="M9 18l6-6-6-6"/></svg>
                </div>
            </div>

            @include('partials.knowledge-comments-drawer', ['e' => $e])
        </div>
    @empty
        <div class="uj-card" style="padding:40px 24px;text-align:center;border-top:1px solid var(--hairline);">
            <div style="font-size:14px;color:var(--ink);font-weight:500;margin-bottom:4px;"><span x-text="$store.ui.lang==='en' ? 'No lessons here yet' : 'Belum ada pengajaran di sini'"></span></div>
            <div style="font-size:12.5px;color:var(--muted);line-height:1.5;max-width:420px;margin:0 auto;"><span x-text="$store.ui.lang==='en' ? 'Be the first to share — use \'Add a lesson\'. Every shared lesson becomes part of the company\'s collective knowledge.' : 'Jadilah yang pertama berkongsi — guna \'Add a lesson\'. Setiap pengajaran menjadi sebahagian pengetahuan kolektif syarikat.'"></span></div>
        </div>
    @endforelse

    @if ($entries->hasPages())
        <div style="padding:16px 2px 6px;border-top:1px solid var(--hairline);">{{ $entries->onEachSide(1)->links() }}</div>
    @endif
</div>
@endsection
