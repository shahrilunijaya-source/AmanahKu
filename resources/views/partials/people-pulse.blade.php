@php
    /**
     * Employee dashboard "People this month" card — two tabs:
     *   · On leave   — colleagues on approved leave, today → next 7 days
     *   · Birthdays  — this month's birthdays (real DOB), today flagged, one-tap wish
     * Wishing opens the in-app Messages screen pre-targeting the person with a draft
     * greeting (?to=<id>&draft=…) — reuses the existing DM system, no new tables.
     *
     * Expects: $onLeave, $birthdays (Collections). Viewer already excluded upstream.
     */
    $onLeave = $onLeave ?? collect();
    $birthdays = $birthdays ?? collect();
    $todayKey = now()->format('m-d');
    // Open on whichever tab actually has something; default to leave.
    $startTab = $onLeave->isEmpty() && $birthdays->isNotEmpty() ? 'bday' : 'leave';
@endphp
<div x-data="{ ptab: '{{ $startTab }}' }">
    <div style="display:flex;align-items:center;gap:6px;margin-bottom:14px;">
        <button type="button" @click="ptab='leave'"
                :style="ptab==='leave' ? { background:'var(--red)', color:'#fff' } : { background:'var(--canvas)', color:'var(--body)' }"
                style="flex:1;height:32px;border-radius:8px;font-size:12.5px;font-weight:600;border:1px solid var(--hairline);cursor:pointer;">
            <span x-text="$store.ui.lang==='en' ? 'On leave' : 'Bercuti'">On leave</span>
        </button>
        <button type="button" @click="ptab='bday'"
                :style="ptab==='bday' ? { background:'var(--red)', color:'#fff' } : { background:'var(--canvas)', color:'var(--body)' }"
                style="flex:1;height:32px;border-radius:8px;font-size:12.5px;font-weight:600;border:1px solid var(--hairline);cursor:pointer;">
            <span x-text="$store.ui.lang==='en' ? 'Birthdays' : 'Hari lahir'">Birthdays</span>
        </button>
    </div>

    {{-- ── On leave: today → next 7 days ─────────────────────────── --}}
    <div x-show="ptab==='leave'" x-cloak>
        @forelse ($onLeave as $l)
            @php
                $back = $l->date_to->copy()->addDay();
                $isOut = ! $l->date_from->isFuture();
            @endphp
            <a href="{{ route('app.screen', ['screen' => 'profile', 'emp' => $l->employee?->id]) }}"
               style="display:flex;align-items:center;gap:11px;padding:8px 0;border-bottom:1px solid var(--hairline-soft);text-decoration:none;">
                <div style="width:30px;height:30px;border-radius:50%;background:{{ $l->employee?->avatar_color ?? '#3a6ea5' }};color:#fff;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:600;flex-shrink:0;">{{ $l->employee?->initials }}</div>
                <div style="flex:1;min-width:0;">
                    <div style="font-size:13px;color:var(--ink);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ $l->employee?->name }}</div>
                    <div style="font-size:11.5px;color:var(--muted);">{{ $l->leaveType?->name ?? 'Leave' }}</div>
                </div>
                <span style="font-size:11.5px;color:var(--muted);font-family:var(--font-mono);white-space:nowrap;">
                    @if ($isOut)
                        <span x-text="$store.ui.lang==='en' ? 'back {{ $back->format('D j M') }}' : 'balik {{ $back->format('D j M') }}'">back {{ $back->format('D j M') }}</span>
                    @else
                        {{ $l->date_from->format('D j M') }}
                    @endif
                </span>
            </a>
        @empty
            <div style="padding:10px 0;">
                <div style="font-size:13px;color:var(--ink);font-weight:500;margin-bottom:2px;"><span x-text="$store.ui.lang==='en' ? 'Nobody off this week' : 'Tiada sesiapa bercuti minggu ini'"></span></div>
                <div style="font-size:12px;color:var(--muted);line-height:1.5;"><span x-text="$store.ui.lang==='en' ? 'Approved leave for the next 7 days shows here — full team in for now.' : 'Cuti diluluskan untuk 7 hari akan datang dipapar di sini — pasukan penuh buat masa ini.'"></span></div>
            </div>
        @endforelse
    </div>

    {{-- ── Birthdays this month ──────────────────────────────────── --}}
    <div x-show="ptab==='bday'" x-cloak>
        @forelse ($birthdays as $b)
            @php
                $isToday = $b->date_of_birth?->format('m-d') === $todayKey;
                $first = \Illuminate\Support\Str::of($b->name)->squish()->explode(' ')->first();
                $wishUrl = route('app.screen', ['screen' => 'messages', 'to' => $b->id, 'draft' => 'Happy birthday, '.$first.'! 🎉']);
            @endphp
            <div style="display:flex;align-items:center;gap:11px;padding:8px {{ $isToday ? '8px' : '0' }};border-bottom:1px solid var(--hairline-soft);{{ $isToday ? 'background:#fff8ef;border-radius:8px;' : '' }}">
                <div style="width:30px;height:30px;border-radius:50%;background:{{ $b->avatar_color }};color:#fff;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:600;flex-shrink:0;">{{ $b->initials }}</div>
                <div style="flex:1;min-width:0;">
                    <div style="font-size:13px;color:var(--ink);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ $b->name }}</div>
                    <div style="font-size:11.5px;color:{{ $isToday ? 'var(--red)' : 'var(--muted)' }};font-weight:{{ $isToday ? '600' : '400' }};">
                        @if ($isToday)
                            🎂 <span x-text="$store.ui.lang==='en' ? 'Today' : 'Hari ini'">Today</span>
                        @else
                            {{ $b->date_of_birth->format('j M') }}
                        @endif
                    </div>
                </div>
                <a href="{{ $wishUrl }}" class="uj-btn-ghost"
                   style="height:30px;padding:0 12px;font-size:12px;display:inline-flex;align-items:center;gap:5px;text-decoration:none;border:1px solid var(--hairline);border-radius:8px;color:var(--body);white-space:nowrap;">🎂 <span x-text="$store.ui.lang==='en' ? 'Wish' : 'Ucap'">Wish</span></a>
            </div>
        @empty
            <div style="padding:10px 0;">
                <div style="font-size:13px;color:var(--ink);font-weight:500;margin-bottom:2px;"><span x-text="$store.ui.lang==='en' ? 'No birthdays this month' : 'Tiada hari lahir bulan ini'"></span></div>
                <div style="font-size:12px;color:var(--muted);line-height:1.5;"><span x-text="$store.ui.lang==='en' ? 'Colleagues celebrating this month appear here so you can send a wish.' : 'Rakan sekerja yang menyambut bulan ini muncul di sini untuk anda ucapkan.'"></span></div>
            </div>
        @endforelse
    </div>
</div>
