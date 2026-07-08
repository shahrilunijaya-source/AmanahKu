@extends('layouts.app')

@php
    $typeLabel = [
        'townhall' => 'Town hall',
        'training' => 'Training',
        'holiday' => 'Holiday',
        'social' => 'Social',
        'meeting' => 'Meeting',
    ];
    $typeLabelMs = [
        'townhall' => 'Town hall',
        'training' => 'Latihan',
        'holiday' => 'Cuti',
        'social' => 'Sosial',
        'meeting' => 'Mesyuarat',
    ];
    $typeColor = [
        'townhall' => 'var(--info)',
        'training' => 'var(--amber)',
        'holiday' => 'var(--success)',
        'social' => 'var(--accent, var(--info))',
        'meeting' => 'var(--muted)',
    ];
    $rsvpLabel = ['going' => 'Going', 'maybe' => 'Maybe', 'declined' => 'Can’t go'];
    $rsvpLabelMs = ['going' => 'Hadir', 'maybe' => 'Mungkin', 'declined' => 'Tak dapat'];
@endphp

@section('screen')
@include('partials.guide', [
    'key'   => 'events',
    'en'  => [
        'title' => 'Company events',
        'body'  => 'See upcoming company events — town halls, training, holidays and socials — and tell the organiser if you\'re coming. You can RSVP once per event, and change it any time before the day.',
        'who'   => 'Everyone RSVPs · HR publishes events',
        'steps' => [
            'Read the event details — date, time and location are listed under each title.',
            'Click "Going", "Maybe" or "Can\'t go". Your choice is highlighted.',
            'Need to change your mind? Just click a different option — the latest one counts.',
            'Organisers see the running headcount of who is going.',
        ],
    ],
    'ms'  => [
        'title' => 'Acara syarikat',
        'body'  => 'Lihat acara syarikat akan datang — town hall, training, cuti dan acara sosial — dan beritahu penganjur jika anda hadir. Anda boleh RSVP sekali bagi setiap acara, dan tukar bila-bila masa sebelum harinya.',
        'who'   => 'Semua orang RSVP · HR terbitkan acara',
        'steps' => [
            'Baca butiran acara — tarikh, masa dan lokasi disenaraikan di bawah setiap tajuk.',
            'Klik "Going", "Maybe" atau "Can\'t go". Pilihan anda akan diserlahkan.',
            'Tukar fikiran? Cuma klik pilihan lain — yang terkini dikira.',
            'Penganjur nampak jumlah semasa siapa yang akan hadir.',
        ],
    ],
])
@if (session('ok'))
    <div style="background:#e7f4ee;border:1px solid var(--success);color:var(--success);font-size:12.5px;border-radius:8px;padding:10px 14px;margin-bottom:16px;">{{ session('ok') }}</div>
@endif

<div style="display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap;">
    {{-- Upcoming events + RSVP --}}
    <div style="flex:2;min-width:340px;display:flex;flex-direction:column;gap:16px;">
        <div class="uj-card" style="padding:0;">
            <div class="uj-card-head"><h3 class="uj-card-title" x-text="$store.ui.lang==='en' ? 'Upcoming events' : 'Acara akan datang'">Upcoming events</h3><span style="font-size:12.5px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'RSVP once per event' : 'RSVP sekali setiap acara'">RSVP once per event</span></div>
            @forelse ($upcomingEvents as $row)
                @php $e = $row['event']; $counts = $row['counts']; $mine = $row['myRsvp']; @endphp
                <div style="padding:18px 20px;border-bottom:1px solid var(--hairline-soft);">
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:5px;flex-wrap:wrap;">
                        <span class="uj-pill" style="background:var(--hairline-soft);color:{{ $typeColor[$e->type] ?? 'var(--muted)' }};" x-text="$store.ui.lang==='en' ? @js($typeLabel[$e->type] ?? $e->type) : @js($typeLabelMs[$e->type] ?? $typeLabel[$e->type] ?? $e->type)">{{ $typeLabel[$e->type] ?? $e->type }}</span>
                        <span style="font-size:13.5px;font-weight:600;color:var(--ink);">{{ $e->title }}</span>
                    </div>
                    <div style="font-size:12.5px;color:var(--muted);margin-bottom:8px;">
                        {{ $e->event_date->format('D, j M Y') }}
                        @if ($e->start_time) · {{ $e->start_time }}@endif
                        @if ($e->location) · {{ $e->location }}@endif
                    </div>
                    @if ($e->description)
                        <p style="font-size:13px;color:var(--muted);margin:0 0 12px;">{{ $e->description }}</p>
                    @endif

                    <div style="display:flex;gap:14px;font-size:12px;color:var(--muted);margin-bottom:12px;">
                        <span><strong style="color:var(--success);font-family:var(--font-mono);">{{ $counts['going'] }}</strong> <span x-text="$store.ui.lang==='en' ? 'going' : 'hadir'">going</span></span>
                        <span><strong style="color:var(--amber);font-family:var(--font-mono);">{{ $counts['maybe'] }}</strong> <span x-text="$store.ui.lang==='en' ? 'maybe' : 'mungkin'">maybe</span></span>
                        <span><strong style="color:var(--ink);font-family:var(--font-mono);">{{ $counts['declined'] }}</strong> <span x-text="$store.ui.lang==='en' ? 'declined' : 'tolak'">declined</span></span>
                    </div>

                    @if (! $canRespond)
                        <div style="font-size:12.5px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'No employee profile in this workspace — RSVP is disabled.' : 'Tiada profil pekerja dalam ruang kerja ini — RSVP dilumpuhkan.'">No employee profile in this workspace — RSVP is disabled.</div>
                    @else
                        <form method="post" action="{{ route('events.rsvp', $e) }}" style="display:flex;gap:8px;flex-wrap:wrap;">
                            @csrf
                            @foreach (['going', 'maybe', 'declined'] as $opt)
                                @php $isCurrent = $mine === $opt; @endphp
                                <button type="submit" name="response" value="{{ $opt }}"
                                    style="height:34px;padding:0 14px;border-radius:8px;font-size:12.5px;font-weight:600;cursor:pointer;border:1px solid {{ $isCurrent ? 'var(--ink)' : 'var(--hairline)' }};background:{{ $isCurrent ? 'var(--ink)' : '#fff' }};color:{{ $isCurrent ? '#fff' : 'var(--ink)' }};"
                                    x-text="$store.ui.lang==='en' ? @js($rsvpLabel[$opt]) : @js($rsvpLabelMs[$opt])">
                                    {{ $rsvpLabel[$opt] }}
                                </button>
                            @endforeach
                        </form>
                    @endif
                </div>
            @empty
                <div style="padding:28px 20px;text-align:center;">
                    <div style="font-size:13px;color:var(--ink);font-weight:500;margin-bottom:3px;" x-text="$store.ui.lang==='en' ? 'No upcoming events' : 'Tiada acara akan datang'">No upcoming events</div>
                    <div style="font-size:12px;color:var(--muted);line-height:1.5;">@if ($privileged)<span x-text="$store.ui.lang==='en' ? 'Use the &quot;New event&quot; form on the right to publish your first event — staff will then be able to RSVP.' : 'Guna borang &quot;New event&quot; di sebelah kanan untuk terbitkan acara pertama anda — staf kemudian boleh RSVP.'"></span>@else<span x-text="$store.ui.lang==='en' ? 'Nothing is scheduled right now. New events will appear here for you to RSVP.' : 'Tiada apa dijadualkan sekarang. Acara baru akan muncul di sini untuk anda RSVP.'"></span>@endif</div>
                </div>
            @endforelse
        </div>

        {{-- Privileged: past events --}}
        @if ($privileged && $pastEvents->isNotEmpty())
            <div class="uj-card" style="padding:0;">
                <div class="uj-card-head"><h3 class="uj-card-title" x-text="$store.ui.lang==='en' ? 'Past events' : 'Acara lepas'">Past events</h3><span style="font-size:12.5px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'Recent history' : 'Sejarah terkini'">Recent history</span></div>
                @foreach ($pastEvents as $row)
                    @php $e = $row['event']; $counts = $row['counts']; @endphp
                    <div style="padding:14px 20px;border-bottom:1px solid var(--hairline-soft);display:flex;align-items:center;justify-content:space-between;gap:10px;">
                        <div style="min-width:0;">
                            <div style="font-size:13px;font-weight:600;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ $e->title }}</div>
                            <div style="font-size:12px;color:var(--muted);"><span x-text="$store.ui.lang==='en' ? @js($typeLabel[$e->type] ?? $e->type) : @js($typeLabelMs[$e->type] ?? $typeLabel[$e->type] ?? $e->type)">{{ $typeLabel[$e->type] ?? $e->type }}</span> · {{ $e->event_date->format('j M Y') }}</div>
                        </div>
                        <span style="font-size:12px;color:var(--muted);white-space:nowrap;"><strong style="color:var(--ink);font-family:var(--font-mono);">{{ $counts['going'] }}</strong> <span x-text="$store.ui.lang==='en' ? 'attended' : 'hadir'">attended</span></span>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    {{-- Privileged: create event form --}}
    <div style="flex:1;min-width:300px;display:flex;flex-direction:column;gap:16px;">
        @if ($privileged)
            <div class="uj-card" style="padding:20px;">
                <h3 class="uj-card-title" style="margin-bottom:14px;" x-text="$store.ui.lang==='en' ? 'New event' : 'Acara baharu'">New event</h3>
                <form method="post" action="{{ route('events.store') }}">
                    @csrf
                    @if ($errors->any())<div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12px;border-radius:8px;padding:9px 12px;margin-bottom:14px;">{{ $errors->first() }}</div>@endif

                    <label style="display:block;font-size:12.5px;font-weight:500;color:var(--ink);margin-bottom:5px;" x-text="$store.ui.lang==='en' ? 'Title' : 'Tajuk'">Title</label>
                    <input name="title" value="{{ old('title') }}" required maxlength="160" placeholder="e.g. Q3 town hall" :placeholder="$store.ui.lang==='en' ? 'e.g. Q3 town hall' : 'cth. Town hall Q3'" style="width:100%;height:40px;padding:0 11px;border:1px solid var(--hairline);border-radius:8px;font-size:13.5px;margin-bottom:13px;outline:none;" />

                    <label style="display:block;font-size:12.5px;font-weight:500;color:var(--ink);margin-bottom:5px;" x-text="$store.ui.lang==='en' ? 'Type' : 'Jenis'">Type</label>
                    <select name="type" required style="width:100%;height:40px;padding:0 11px;border:1px solid var(--hairline);border-radius:8px;font-size:13.5px;background:#fff;color:var(--ink);margin-bottom:6px;">
                        @foreach ($eventTypes as $t)
                            <option value="{{ $t }}" @selected(old('type') === $t) x-text="$store.ui.lang==='en' ? @js($typeLabel[$t] ?? $t) : @js($typeLabelMs[$t] ?? $typeLabel[$t] ?? $t)">{{ $typeLabel[$t] ?? $t }}</option>
                        @endforeach
                    </select>
                    @include('partials.hint', ['en' => 'Sets the colour tag staff see. Use Holiday for public holidays, Town hall for all-staff briefings.', 'ms' => 'Menetapkan tag warna yang staf nampak. Guna Holiday untuk cuti umum, Town hall untuk taklimat semua staf.'])

                    <label style="display:block;font-size:12.5px;font-weight:500;color:var(--ink);margin-bottom:5px;" x-text="$store.ui.lang==='en' ? 'Date' : 'Tarikh'">Date</label>
                    <input type="date" name="event_date" value="{{ old('event_date') }}" required style="width:100%;height:40px;padding:0 11px;border:1px solid var(--hairline);border-radius:8px;font-size:13.5px;margin-bottom:13px;outline:none;" />

                    <label style="display:block;font-size:12.5px;font-weight:500;color:var(--ink);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Time' : 'Masa'">Time</span> <span style="color:var(--muted-soft);font-weight:400;" x-text="$store.ui.lang==='en' ? '(optional)' : '(pilihan)'">(optional)</span></label>
                    <input name="start_time" value="{{ old('start_time') }}" maxlength="40" placeholder="e.g. 3:00 PM" :placeholder="$store.ui.lang==='en' ? 'e.g. 3:00 PM' : 'cth. 3:00 PM'" style="width:100%;height:40px;padding:0 11px;border:1px solid var(--hairline);border-radius:8px;font-size:13.5px;margin-bottom:13px;outline:none;" />

                    <label style="display:block;font-size:12.5px;font-weight:500;color:var(--ink);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Location' : 'Lokasi'">Location</span> <span style="color:var(--muted-soft);font-weight:400;" x-text="$store.ui.lang==='en' ? '(optional)' : '(pilihan)'">(optional)</span></label>
                    <input name="location" value="{{ old('location') }}" maxlength="160" placeholder="e.g. PJ HQ, Level 12" :placeholder="$store.ui.lang==='en' ? 'e.g. PJ HQ, Level 12' : 'cth. PJ HQ, Aras 12'" style="width:100%;height:40px;padding:0 11px;border:1px solid var(--hairline);border-radius:8px;font-size:13.5px;margin-bottom:13px;outline:none;" />

                    <label style="display:block;font-size:12.5px;font-weight:500;color:var(--ink);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Description' : 'Penerangan'">Description</span> <span style="color:var(--muted-soft);font-weight:400;" x-text="$store.ui.lang==='en' ? '(optional)' : '(pilihan)'">(optional)</span></label>
                    <textarea name="description" maxlength="2000" rows="3" placeholder="What attendees should know…" :placeholder="$store.ui.lang==='en' ? 'What attendees should know…' : 'Apa yang hadirin perlu tahu…'" style="width:100%;padding:9px 11px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;resize:vertical;outline:none;margin-bottom:16px;font-family:inherit;">{{ old('description') }}</textarea>

                    <button type="submit" class="uj-btn-primary" style="width:100%;height:42px;font-size:13.5px;" x-text="$store.ui.lang==='en' ? 'Publish event' : 'Terbitkan acara'">Publish event</button>
                </form>
            </div>
        @endif
    </div>
</div>
@endsection
