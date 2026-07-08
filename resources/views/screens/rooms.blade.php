@extends('layouts.app')

@php
    $fs = 'height:38px;padding:0 11px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;background:#fff;color:var(--ink);outline:none;';
    $fmtTime = fn ($t) => $t ? \Illuminate\Support\Carbon::parse($t)->format('g:ia') : '—';
    $selected = \Illuminate\Support\Carbon::parse($selectedDate);
    $isToday = $selectedDate === now()->toDateString();
@endphp

@section('screen')
@include('partials.guide', [
    'key'   => 'rooms',
    'en'  => [
        'title' => 'Meeting rooms',
        'body'  => 'Book a meeting room for a time slot. The system blocks overlaps automatically, so two bookings can never clash on the same room. Pick a day at the top to see who has booked what.',
        'who'   => 'Everyone books · HR adds rooms',
        'steps' => [
            'Choose the day you want at the top, then click "+ Book a room".',
            'Pick the room, set start and end times, and give the meeting a title.',
            'Confirm — if the slot clashes with an existing booking it is rejected, so try another time or room.',
            'Your bookings appear under "My upcoming bookings", where you can cancel one.',
        ],
    ],
    'ms'  => [
        'title' => 'Bilik mesyuarat',
        'body'  => 'Booking bilik mesyuarat untuk satu slot masa. Sistem menghalang pertindihan secara automatik, jadi dua booking tidak akan bertembung pada bilik yang sama. Pilih hari di bahagian atas untuk lihat siapa booking apa.',
        'who'   => 'Semua orang booking · HR tambah bilik',
        'steps' => [
            'Pilih hari yang anda mahu di bahagian atas, kemudian klik "+ Book a room".',
            'Pilih bilik, tetapkan masa mula dan tamat, dan beri tajuk mesyuarat.',
            'Sahkan — jika slot bertembung dengan booking sedia ada ia akan ditolak, jadi cuba masa atau bilik lain.',
            'Booking anda muncul di bawah "My upcoming bookings", di mana anda boleh batalkannya.',
        ],
    ],
])
<div x-data="{ book: {{ $errors->any() && ! $errors->has('room') ? 'true' : 'false' }}, addRoom: {{ $errors->has('room') ? 'true' : 'false' }} }">

    {{-- Day selector + booking trigger --}}
    <div class="uj-card" style="padding:16px 20px;margin-bottom:16px;display:flex;gap:14px;flex-wrap:wrap;align-items:center;justify-content:space-between;">
        <form method="get" action="{{ route('app.screen', 'rooms') }}" style="display:flex;gap:10px;align-items:center;">
            <label style="font-size:12px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'Showing schedule for' : 'Jadual untuk'">Showing schedule for</label>
            <input type="date" name="date" value="{{ $selectedDate }}" onchange="this.form.submit()" style="{{ $fs }}" />
            <span style="font-size:13px;color:var(--ink);font-weight:500;">{{ $selected->format('l, j M Y') }}@if ($isToday) · <span x-text="$store.ui.lang==='en' ? 'Today' : 'Hari ini'">Today</span>@endif</span>
        </form>
        <div style="display:flex;gap:8px;">
            @if ($canBook)
                <button @click="book = ! book" class="uj-btn-primary" style="height:36px;padding:0 14px;font-size:12.5px;"><span x-text="book ? ($store.ui.lang==='en' ? 'Cancel' : 'Batal') : ($store.ui.lang==='en' ? '+ Book a room' : '+ Booking bilik')"></span></button>
            @endif
            @if ($privileged)
                <button @click="addRoom = ! addRoom" class="uj-btn-ghost" style="height:36px;padding:0 14px;font-size:12.5px;"><span x-text="addRoom ? ($store.ui.lang==='en' ? 'Cancel' : 'Batal') : ($store.ui.lang==='en' ? '+ Add room' : '+ Tambah bilik')"></span></button>
            @endif
        </div>
    </div>

    {{-- Booking form (any employee) --}}
    @if ($canBook)
        <div x-show="book" x-cloak class="uj-card" style="padding:20px;margin-bottom:16px;">
            <h3 class="uj-card-title" style="margin-bottom:14px;" x-text="$store.ui.lang==='en' ? 'Book a meeting room' : 'Booking bilik mesyuarat'">Book a meeting room</h3>
            @if ($errors->any() && ! $errors->has('room'))
                <div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12px;border-radius:8px;padding:9px 12px;margin-bottom:14px;">{{ $errors->first() }}</div>
            @endif
            @if ($rooms->isEmpty())
                <div style="font-size:13px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'No bookable rooms yet.' : 'Belum ada bilik untuk ditempah.'">No bookable rooms yet.</div>
            @else
                <form method="post" action="{{ route('rooms.book') }}">
                    @csrf
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;align-items:start;">
                        <div>
                            <label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Room' : 'Bilik'">Room</span> *</label>
                            <select name="meeting_room_id" required style="{{ $fs }}width:100%;">
                                <option value="" x-text="$store.ui.lang==='en' ? 'Select…' : 'Pilih…'">Select…</option>
                                @foreach ($rooms as $r)
                                    <option value="{{ $r->id }}" @selected((string) old('meeting_room_id') === (string) $r->id)>{{ $r->name }}{{ $r->capacity ? ' (cap '.$r->capacity.')' : '' }}</option>
                                @endforeach
                            </select>
                            @include('partials.hint', ['en' => 'The number in brackets is the room\'s seating capacity — pick one big enough for your group.', 'ms' => 'Nombor dalam kurungan ialah kapasiti tempat duduk bilik — pilih yang cukup besar untuk kumpulan anda.'])
                        </div>
                        <div><label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Date' : 'Tarikh'">Date</span> *</label><input type="date" name="date" value="{{ old('date', $selectedDate) }}" required style="{{ $fs }}width:100%;" /></div>
                        <div><label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Start' : 'Mula'">Start</span> *</label><input type="time" name="start_time" value="{{ old('start_time', '09:00') }}" required style="{{ $fs }}width:100%;margin-bottom:6px;" />@include('partials.hint', ['en' => 'If this time clashes with another booking for the same room, the system rejects it.', 'ms' => 'Jika masa ini bertembung dengan booking lain untuk bilik yang sama, sistem akan menolaknya.'])</div>
                        <div><label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'End' : 'Tamat'">End</span> *</label><input type="time" name="end_time" value="{{ old('end_time', '10:00') }}" required style="{{ $fs }}width:100%;margin-bottom:6px;" />@include('partials.hint', ['en' => 'Must be after the start time. Keep it tight so others can use the room.', 'ms' => 'Mesti selepas masa mula. Pastikan ringkas supaya orang lain boleh guna bilik itu.'])</div>
                        <div><label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Meeting title' : 'Tajuk mesyuarat'">Meeting title</span> *</label><input name="title" value="{{ old('title') }}" required maxlength="160" placeholder="e.g. Sprint planning" :placeholder="$store.ui.lang==='en' ? 'e.g. Sprint planning' : 'cth. Perancangan sprint'" style="{{ $fs }}width:100%;" /></div>
                    </div>
                    <button type="submit" class="uj-btn-primary" style="height:42px;padding:0 20px;font-size:13.5px;margin-top:16px;" x-text="$store.ui.lang==='en' ? 'Confirm booking' : 'Sahkan booking'">Confirm booking</button>
                </form>
            @endif
        </div>
    @endif

    {{-- Add-room form (privileged) --}}
    @if ($privileged)
        <div x-show="addRoom" x-cloak class="uj-card" style="padding:20px;margin-bottom:16px;">
            <h3 class="uj-card-title" style="margin-bottom:14px;" x-text="$store.ui.lang==='en' ? 'Add a meeting room' : 'Tambah bilik mesyuarat'">Add a meeting room</h3>
            @if ($errors->has('room'))
                <div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12px;border-radius:8px;padding:9px 12px;margin-bottom:14px;">{{ $errors->first('room') }}</div>
            @endif
            <form method="post" action="{{ route('rooms.store') }}">
                @csrf
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;align-items:start;">
                    <div><label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Room name' : 'Nama bilik'">Room name</span> *</label><input name="name" value="{{ old('name') }}" required maxlength="120" placeholder="e.g. Boardroom A" :placeholder="$store.ui.lang==='en' ? 'e.g. Boardroom A' : 'cth. Bilik Lembaga A'" style="{{ $fs }}width:100%;" /></div>
                    <div><label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;" x-text="$store.ui.lang==='en' ? 'Location' : 'Lokasi'">Location</label><input name="location" value="{{ old('location') }}" maxlength="160" placeholder="e.g. Level 12" :placeholder="$store.ui.lang==='en' ? 'e.g. Level 12' : 'cth. Aras 12'" style="{{ $fs }}width:100%;" /></div>
                    <div><label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;" x-text="$store.ui.lang==='en' ? 'Capacity' : 'Kapasiti'">Capacity</label><input type="number" name="capacity" value="{{ old('capacity') }}" min="1" max="1000" placeholder="e.g. 8" :placeholder="$store.ui.lang==='en' ? 'e.g. 8' : 'cth. 8'" style="{{ $fs }}width:100%;margin-bottom:6px;" />@include('partials.hint', ['en' => 'How many people the room seats. Shown to staff when they book, so they pick the right size.', 'ms' => 'Berapa ramai orang bilik ini boleh muat. Ditunjuk kepada staf semasa booking, supaya mereka pilih saiz yang betul.'])</div>
                </div>
                <button type="submit" class="uj-btn-primary" style="height:42px;padding:0 20px;font-size:13.5px;margin-top:16px;" x-text="$store.ui.lang==='en' ? 'Add room' : 'Tambah bilik'">Add room</button>
            </form>
        </div>
    @endif

    {{-- Per-room schedule for the selected day --}}
    <div class="uj-card">
        <div class="uj-card-head">
            <h3 class="uj-card-title"><span x-text="$store.ui.lang==='en' ? 'Room schedule' : 'Jadual bilik'">Room schedule</span> · {{ $selected->format('D, j M') }}</h3>
            <span style="font-size:12px;color:var(--muted);">{{ $rooms->count() }} <span x-text="$store.ui.lang==='en' ? @js($rooms->count() === 1 ? 'active room' : 'active rooms') : 'bilik aktif'">{{ $rooms->count() === 1 ? 'active room' : 'active rooms' }}</span></span>
        </div>
        @forelse ($rooms as $r)
            @php $slots = $dayBookings->get($r->id, collect()); @endphp
            <div style="padding:14px 20px;border-bottom:1px solid var(--hairline-soft);">
                <div style="display:flex;align-items:center;gap:10px;margin-bottom:9px;">
                    <span style="font-size:14px;font-weight:600;color:var(--ink);">{{ $r->name }}</span>
                    @if ($r->location)
                        <span style="font-size:12px;color:var(--muted);">· {{ $r->location }}</span>
                    @endif
                    @if ($r->capacity)
                        <span style="font-size:11px;color:var(--muted);background:var(--canvas);border-radius:20px;padding:2px 9px;"><span x-text="$store.ui.lang==='en' ? 'cap' : 'kap'">cap</span> {{ $r->capacity }}</span>
                    @endif
                </div>
                @if ($slots->isEmpty())
                    <div style="font-size:12.5px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'No bookings — free all day.' : 'Tiada booking — kosong sepanjang hari.'">No bookings — free all day.</div>
                @else
                    <div style="display:flex;flex-direction:column;gap:6px;">
                        @foreach ($slots as $b)
                            <div style="display:flex;align-items:center;gap:12px;border:1px solid var(--hairline);border-left:3px solid var(--success);border-radius:7px;padding:7px 11px;font-size:12.5px;">
                                <span style="font-weight:600;color:var(--ink);min-width:120px;">{{ $fmtTime($b->start_time) }} – {{ $fmtTime($b->end_time) }}</span>
                                <span style="color:var(--body);flex:1;">{{ $b->title }}</span>
                                <span style="color:var(--muted);">{{ $b->employee?->name ?? '—' }}</span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @empty
            <div style="padding:28px 20px;text-align:center;">
                <div style="font-size:13px;color:var(--ink);font-weight:500;margin-bottom:3px;" x-text="$store.ui.lang==='en' ? 'No meeting rooms set up yet' : 'Belum ada bilik mesyuarat disediakan'">No meeting rooms set up yet</div>
                <div style="font-size:12px;color:var(--muted);line-height:1.5;">@if ($privileged)<span x-text="$store.ui.lang==='en' ? 'Click &quot;+ Add room&quot; above to register your first room, then staff can start booking it.' : 'Klik &quot;+ Add room&quot; di atas untuk daftar bilik pertama anda, kemudian staf boleh mula booking.'"></span>@else<span x-text="$store.ui.lang==='en' ? 'No rooms have been added yet. Ask HR or an admin to set them up.' : 'Belum ada bilik ditambah. Minta HR atau admin untuk menyediakannya.'"></span>@endif</div>
            </div>
        @endforelse
    </div>

    {{-- My upcoming bookings --}}
    @if ($canBook)
        <div class="uj-card" style="margin-top:16px;">
            <div class="uj-card-head"><h3 class="uj-card-title" x-text="$store.ui.lang==='en' ? 'My upcoming bookings' : 'Booking akan datang saya'">My upcoming bookings</h3></div>
            <div style="display:grid;grid-template-columns:1.4fr 1.2fr 1fr 1.6fr auto;gap:8px;padding:12px 20px;font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;border-bottom:1px solid var(--hairline-soft);">
                <span x-text="$store.ui.lang==='en' ? 'Room' : 'Bilik'">Room</span>
                <span x-text="$store.ui.lang==='en' ? 'Date' : 'Tarikh'">Date</span>
                <span x-text="$store.ui.lang==='en' ? 'Time' : 'Masa'">Time</span>
                <span x-text="$store.ui.lang==='en' ? 'Title' : 'Tajuk'">Title</span>
                <span></span>
            </div>
            @forelse ($myBookings as $b)
                <div style="display:grid;grid-template-columns:1.4fr 1.2fr 1fr 1.6fr auto;gap:8px;padding:13px 20px;border-bottom:1px solid var(--hairline-soft);align-items:center;">
                    <span style="font-size:13.5px;color:var(--ink);font-weight:500;">{{ $b->room?->name ?? '—' }}</span>
                    <span style="font-size:13px;color:var(--body);">{{ $b->date->format('D, j M Y') }}</span>
                    <span style="font-size:13px;color:var(--body);">{{ $fmtTime($b->start_time) }} – {{ $fmtTime($b->end_time) }}</span>
                    <span style="font-size:13px;color:var(--body);">{{ $b->title }}</span>
                    <form method="post" action="{{ route('rooms.cancel', $b) }}" onsubmit="return confirm(window.Alpine && Alpine.store('ui').lang==='ms' ? 'Batalkan booking ini?' : 'Cancel this booking?');" style="text-align:right;">
                        @csrf
                        <button type="submit" class="uj-btn-ghost" style="height:28px;padding:0 11px;font-size:11.5px;" x-text="$store.ui.lang==='en' ? 'Cancel' : 'Batal'">Cancel</button>
                    </form>
                </div>
            @empty
                <div style="padding:28px 20px;text-align:center;">
                    <div style="font-size:13px;color:var(--ink);font-weight:500;margin-bottom:3px;" x-text="$store.ui.lang==='en' ? 'No upcoming bookings' : 'Tiada booking akan datang'">No upcoming bookings</div>
                    <div style="font-size:12px;color:var(--muted);line-height:1.5;"><span x-text="$store.ui.lang==='en' ? 'Click &quot;+ Book a room&quot; above to reserve a room. Your future bookings will be listed here.' : 'Klik &quot;+ Book a room&quot; di atas untuk tempah bilik. Booking akan datang anda akan disenaraikan di sini.'"></span></div>
                </div>
            @endforelse
        </div>
    @endif
</div>
@endsection
