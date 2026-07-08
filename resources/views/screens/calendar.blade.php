@extends('layouts.app')

@section('screen')
@php
    $prevUrl = route('app.screen', 'calendar').'?month='.$prevMonth;
    $nextUrl = route('app.screen', 'calendar').'?month='.$nextMonth;
@endphp

@include('partials.guide', [
    'key' => 'calendar',
    'en'  => [
        'title' => 'Company calendar',
        'body'  => 'A month-at-a-glance view of who is out, public holidays and company events — so you can see coverage before approving leave or planning work. Use the ‹ › arrows to move between months. This screen only shows information; leave and events are managed on their own screens.',
    ],
    'ms'  => [
        'title' => 'Kalendar syarikat',
        'body'  => 'Pandangan sepintas lalu sebulan tentang siapa yang bercuti, cuti umum dan acara syarikat — supaya anda boleh lihat liputan staf sebelum meluluskan cuti atau merancang kerja. Guna anak panah ‹ › untuk berpindah antara bulan. Skrin ini hanya memaparkan maklumat; cuti dan acara diuruskan pada skrin tersendiri.',
    ],
])

<div style="display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap;">
    {{-- ── Calendar grid ─────────────────────────────────────────── --}}
    <div class="uj-card" style="flex:2.4;min-width:520px;padding:20px;">
        <div class="uj-card-head" style="padding:0 0 14px;border:0;">
            <div style="display:flex;align-items:center;gap:14px;">
                <a href="{{ $prevUrl }}" class="uj-btn-ghost" style="height:34px;width:34px;display:inline-flex;align-items:center;justify-content:center;padding:0;font-size:16px;text-decoration:none;" :aria-label="$store.ui.lang==='en' ? 'Previous month' : 'Bulan sebelum'">‹</a>
                <h3 class="uj-card-title" style="margin:0;min-width:160px;text-align:center;">{{ $month }}</h3>
                <a href="{{ $nextUrl }}" class="uj-btn-ghost" style="height:34px;width:34px;display:inline-flex;align-items:center;justify-content:center;padding:0;font-size:16px;text-decoration:none;" :aria-label="$store.ui.lang==='en' ? 'Next month' : 'Bulan seterusnya'">›</a>
            </div>
            <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;font-size:11.5px;color:var(--muted);">
                <span style="display:inline-flex;align-items:center;gap:6px;"><span style="width:8px;height:8px;border-radius:50%;background:var(--success);"></span><span x-text="$store.ui.lang==='en' ? 'On leave' : 'Bercuti'">On leave</span></span>
                <span style="display:inline-flex;align-items:center;gap:6px;"><span style="width:8px;height:8px;border-radius:50%;background:var(--amber);"></span><span x-text="$store.ui.lang==='en' ? 'Holiday' : 'Cuti umum'">Holiday</span></span>
                <span style="display:inline-flex;align-items:center;gap:6px;"><span style="width:8px;height:8px;border-radius:50%;background:#3a6ea5;"></span><span x-text="$store.ui.lang==='en' ? 'Event' : 'Acara'">Event</span></span>
                <span style="display:inline-flex;align-items:center;gap:6px;"><span style="width:8px;height:8px;border-radius:50%;background:#c026d3;"></span><span x-text="$store.ui.lang==='en' ? 'Birthday' : 'Hari lahir'">Birthday</span></span>
            </div>
        </div>

        <div style="display:grid;grid-template-columns:repeat(7,1fr);gap:6px;margin-bottom:6px;">
            @foreach ($weekdays as $wd)
                <div style="font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;text-align:center;padding:4px 0;">{{ $wd }}</div>
            @endforeach
        </div>

        <div style="display:flex;flex-direction:column;gap:6px;">
            @foreach ($weeks as $week)
                <div style="display:grid;grid-template-columns:repeat(7,1fr);gap:6px;">
                    @foreach ($week as $cell)
                        @include('partials.calendar-day', ['cell' => $cell, 'maxItems' => 3])
                    @endforeach
                </div>
            @endforeach
        </div>
    </div>

    {{-- ── Side summary ──────────────────────────────────────────── --}}
    <div style="flex:1;min-width:280px;display:flex;flex-direction:column;gap:16px;">
        <div class="uj-card" style="padding:20px;">
            <h3 class="uj-card-title" style="margin-bottom:12px;"><span x-text="$store.ui.lang==='en' ? 'Who is out this month' : 'Siapa bercuti bulan ini'">Who's out this month</span></h3>
            @forelse ($outThisMonth as $l)
                <div style="display:flex;align-items:center;gap:11px;padding:8px 0;border-bottom:1px solid var(--hairline-soft);">
                    <div style="width:30px;height:30px;border-radius:50%;background:{{ $l->employee?->avatar_color ?? '#3a6ea5' }};color:#fff;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:600;flex-shrink:0;">{{ $l->employee?->initials }}</div>
                    <div style="flex:1;min-width:0;">
                        <div style="font-size:13px;color:var(--ink);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ $l->employee?->name }}</div>
                        <div style="font-size:11.5px;color:var(--muted);">{{ $l->leaveType?->name }}</div>
                    </div>
                    <span style="font-size:11.5px;color:var(--muted);font-family:var(--font-mono);white-space:nowrap;">{{ $l->date_from->format('j') }}–{{ $l->date_to->format('j M') }}</span>
                </div>
            @empty
                <div style="font-size:13px;color:var(--ink);font-weight:500;margin-bottom:2px;"><span x-text="$store.ui.lang==='en' ? 'Nobody on leave this month' : 'Tiada sesiapa bercuti bulan ini'"></span></div>
                <div style="font-size:12px;color:var(--muted);line-height:1.5;"><span x-text="$store.ui.lang==='en' ? 'Full attendance — approved leave will show here once anyone is booked off.' : 'Kehadiran penuh — cuti yang diluluskan akan dipaparkan di sini sebaik sahaja ada yang bercuti.'"></span></div>
            @endforelse
        </div>

        <div class="uj-card" style="padding:20px;">
            <h3 class="uj-card-title" style="margin-bottom:12px;"><span x-text="$store.ui.lang==='en' ? 'Holidays' : 'Cuti umum'">Holidays</span></h3>
            @forelse ($holidaysThisMonth as $h)
                <div style="display:flex;align-items:center;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--hairline-soft);">
                    <div style="min-width:0;">
                        <div style="font-size:13px;color:var(--ink);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ $h->name }}</div>
                        <div style="font-size:11.5px;color:var(--muted-soft);">{{ $h->state }}</div>
                    </div>
                    <span style="font-size:12px;color:var(--muted);font-family:var(--font-mono);white-space:nowrap;">{{ $h->date->format('j M') }}</span>
                </div>
            @empty
                <div style="font-size:13px;color:var(--ink);font-weight:500;margin-bottom:2px;"><span x-text="$store.ui.lang==='en' ? 'No holidays this month' : 'Tiada cuti umum bulan ini'"></span></div>
                <div style="font-size:12px;color:var(--muted);line-height:1.5;"><span x-text="$store.ui.lang==='en' ? 'No public holidays fall in this month. Use the arrows above to check other months.' : 'Tiada cuti umum jatuh pada bulan ini. Guna anak panah di atas untuk semak bulan lain.'"></span></div>
            @endforelse
        </div>

        <div class="uj-card" style="padding:20px;">
            <h3 class="uj-card-title" style="margin-bottom:12px;"><span x-text="$store.ui.lang==='en' ? 'Birthdays' : 'Hari lahir'">Birthdays</span></h3>
            @forelse ($birthdaysThisMonth as $b)
                <div style="display:flex;align-items:center;gap:11px;padding:8px 0;border-bottom:1px solid var(--hairline-soft);">
                    <div style="width:30px;height:30px;border-radius:50%;background:{{ $b->avatar_color ?? '#c026d3' }};color:#fff;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:600;flex-shrink:0;">{{ $b->initials }}</div>
                    <div style="flex:1;min-width:0;">
                        <div style="font-size:13px;color:var(--ink);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ $b->name }}</div>
                    </div>
                    <a href="{{ route('app.screen', ['screen' => 'messages', 'to' => $b->id, 'draft' => 'Happy birthday, '.\Illuminate\Support\Str::of($b->name)->squish()->explode(' ')->first().'! 🎉']) }}" style="font-size:12px;color:var(--muted);font-family:var(--font-mono);white-space:nowrap;text-decoration:none;display:inline-flex;align-items:center;gap:4px;">
                        {{ $b->date_of_birth->format('j M') }} <span style="font-size:13px;">🎂</span>
                    </a>
                </div>
            @empty
                <div style="font-size:13px;color:var(--ink);font-weight:500;margin-bottom:2px;"><span x-text="$store.ui.lang==='en' ? 'No birthdays this month' : 'Tiada hari lahir bulan ini'"></span></div>
                <div style="font-size:12px;color:var(--muted);line-height:1.5;"><span x-text="$store.ui.lang==='en' ? 'Colleagues celebrating this month appear here.' : 'Rakan sekerja yang menyambut bulan ini muncul di sini.'"></span></div>
            @endforelse
        </div>

        <div class="uj-card" style="padding:20px;">
            <h3 class="uj-card-title" style="margin-bottom:12px;"><span x-text="$store.ui.lang==='en' ? 'Events' : 'Acara'">Events</span></h3>
            @forelse ($eventsThisMonth as $e)
                <div style="display:flex;align-items:center;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--hairline-soft);">
                    <div style="min-width:0;">
                        <div style="font-size:13px;color:var(--ink);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ $e->title }}</div>
                        <div style="font-size:11.5px;color:var(--muted-soft);">{{ $e->location ?: ucfirst($e->type ?? 'event') }}</div>
                    </div>
                    <span style="font-size:12px;color:var(--muted);font-family:var(--font-mono);white-space:nowrap;">{{ $e->event_date->format('j M') }}</span>
                </div>
            @empty
                <div style="font-size:13px;color:var(--ink);font-weight:500;margin-bottom:2px;"><span x-text="$store.ui.lang==='en' ? 'No events this month' : 'Tiada acara bulan ini'"></span></div>
                <div style="font-size:12px;color:var(--muted);line-height:1.5;"><span x-text="$store.ui.lang==='en' ? 'Nothing scheduled. Company events created on the Events screen will appear here.' : 'Tiada apa-apa dijadualkan. Acara syarikat yang dicipta pada skrin Events akan muncul di sini.'"></span></div>
            @endforelse
        </div>
    </div>
</div>
@endsection
