@extends('layouts.app')

@php
    $fs = 'height:38px;padding:0 11px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;background:#fff;color:var(--ink);outline:none;';
    $fmtWin = fn ($b) => \Illuminate\Support\Carbon::parse($b->starts_at)->format('D, j M · g:ia').' – '.\Illuminate\Support\Carbon::parse($b->ends_at)->format('g:ia');
    $typeLabels = ['car' => 'Car', 'van' => 'Van', 'truck' => 'Truck', 'motorcycle' => 'Motorcycle'];
    $typeLabelsMs = ['car' => 'Kereta', 'van' => 'Van', 'truck' => 'Lori', 'motorcycle' => 'Motosikal'];
@endphp

@section('screen')
@include('partials.guide', [
    'key'   => 'vehicles',
    'en'  => [
        'title' => 'Vehicle booking',
        'body'  => 'Book a company vehicle for a time window. The system blocks overlaps automatically, so two people can never double-book the same vehicle. Add a purpose and destination so others know where the vehicle is.',
        'who'   => 'Everyone books · HR adds vehicles',
        'steps' => [
            'Click "+ Book a vehicle" and pick the vehicle you need.',
            'Set the start and end date/time, then add a purpose and destination.',
            'Confirm — if the window clashes with an existing booking it is rejected, so try another time or vehicle.',
            'Your bookings appear under "My upcoming bookings", where you can cancel one.',
        ],
    ],
    'ms'  => [
        'title' => 'Booking kenderaan',
        'body'  => 'Booking kenderaan syarikat untuk satu tempoh masa. Sistem menghalang pertindihan secara automatik, jadi dua orang tidak boleh booking kenderaan yang sama serentak. Tambah tujuan dan destinasi supaya orang lain tahu di mana kenderaan itu.',
        'who'   => 'Semua orang booking · HR tambah kenderaan',
        'steps' => [
            'Klik "+ Book a vehicle" dan pilih kenderaan yang anda perlukan.',
            'Tetapkan tarikh/masa mula dan tamat, kemudian tambah tujuan dan destinasi.',
            'Sahkan — jika tempoh bertembung dengan booking sedia ada ia akan ditolak, jadi cuba masa atau kenderaan lain.',
            'Booking anda muncul di bawah "My upcoming bookings", di mana anda boleh batalkannya.',
        ],
    ],
])
<div x-data="{ book: {{ $errors->any() && ! $errors->has('vehicle') ? 'true' : 'false' }}, addVehicle: {{ $errors->has('vehicle') ? 'true' : 'false' }} }">

    {{-- Action bar --}}
    <div class="uj-card" style="padding:16px 20px;margin-bottom:16px;display:flex;gap:14px;flex-wrap:wrap;align-items:center;justify-content:space-between;">
        <span style="font-size:13px;color:var(--muted);">{{ $vehicles->count() }} <span x-text="$store.ui.lang==='en' ? @js(($vehicles->count() === 1 ? 'active vehicle' : 'active vehicles').' in the fleet') : 'kenderaan aktif dalam armada'">{{ $vehicles->count() === 1 ? 'active vehicle' : 'active vehicles' }} in the fleet</span></span>
        <div style="display:flex;gap:8px;">
            @if ($canBook)
                <button @click="book = ! book" class="uj-btn-primary" style="height:36px;padding:0 14px;font-size:12.5px;"><span x-text="book ? ($store.ui.lang==='en' ? 'Cancel' : 'Batal') : ($store.ui.lang==='en' ? '+ Book a vehicle' : '+ Booking kenderaan')"></span></button>
            @endif
            @if ($privileged)
                <button @click="addVehicle = ! addVehicle" class="uj-btn-ghost" style="height:36px;padding:0 14px;font-size:12.5px;"><span x-text="addVehicle ? ($store.ui.lang==='en' ? 'Cancel' : 'Batal') : ($store.ui.lang==='en' ? '+ Add vehicle' : '+ Tambah kenderaan')"></span></button>
            @endif
        </div>
    </div>

    {{-- Booking form (any employee) --}}
    @if ($canBook)
        <div x-show="book" x-cloak class="uj-card" style="padding:20px;margin-bottom:16px;">
            <h3 class="uj-card-title" style="margin-bottom:14px;" x-text="$store.ui.lang==='en' ? 'Book a vehicle' : 'Booking kenderaan'">Book a vehicle</h3>
            @if ($errors->any() && ! $errors->has('vehicle'))
                <div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12px;border-radius:8px;padding:9px 12px;margin-bottom:14px;">{{ $errors->first() }}</div>
            @endif
            @if ($vehicles->isEmpty())
                <div style="font-size:13px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'No bookable vehicles yet.' : 'Belum ada kenderaan untuk ditempah.'">No bookable vehicles yet.</div>
            @else
                <form method="post" action="{{ route('vehicles.book') }}">
                    @csrf
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;align-items:start;">
                        <div>
                            <label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Vehicle' : 'Kenderaan'">Vehicle</span> *</label>
                            <select name="vehicle_id" required style="{{ $fs }}width:100%;">
                                <option value="" x-text="$store.ui.lang==='en' ? 'Select…' : 'Pilih…'">Select…</option>
                                @foreach ($vehicles as $v)
                                    <option value="{{ $v->id }}" @selected((string) old('vehicle_id') === (string) $v->id)>{{ $v->name }} · {{ $v->registration_no }}</option>
                                @endforeach
                            </select>
                            @include('partials.hint', ['en' => 'The plate number after the name helps you confirm you booked the right vehicle.', 'ms' => 'Nombor plat selepas nama membantu anda sahkan kenderaan yang betul telah ditempah.'])
                        </div>
                        <div><label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Start' : 'Mula'">Start</span> *</label><input type="datetime-local" name="starts_at" value="{{ old('starts_at') }}" required style="{{ $fs }}width:100%;margin-bottom:6px;" />@include('partials.hint', ['en' => 'When you will take the vehicle. Overlaps with another booking for the same vehicle are rejected.', 'ms' => 'Bila anda akan ambil kenderaan. Pertindihan dengan booking lain untuk kenderaan yang sama akan ditolak.'])</div>
                        <div><label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'End' : 'Tamat'">End</span> *</label><input type="datetime-local" name="ends_at" value="{{ old('ends_at') }}" required style="{{ $fs }}width:100%;margin-bottom:6px;" />@include('partials.hint', ['en' => 'When you will return the vehicle. Must be after the start time.', 'ms' => 'Bila anda akan pulangkan kenderaan. Mesti selepas masa mula.'])</div>
                        <div><label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Purpose' : 'Tujuan'">Purpose</span> *</label><input name="purpose" value="{{ old('purpose') }}" required maxlength="160" placeholder="e.g. Site inspection" :placeholder="$store.ui.lang==='en' ? 'e.g. Site inspection' : 'cth. Lawatan tapak'" style="{{ $fs }}width:100%;" /></div>
                        <div><label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;" x-text="$store.ui.lang==='en' ? 'Destination' : 'Destinasi'">Destination</label><input name="destination" value="{{ old('destination') }}" maxlength="160" placeholder="e.g. Klang depot" :placeholder="$store.ui.lang==='en' ? 'e.g. Klang depot' : 'cth. Depoh Klang'" style="{{ $fs }}width:100%;" /></div>
                        <div><label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;" x-text="$store.ui.lang==='en' ? 'Odometer out' : 'Odometer keluar'">Odometer out</label><input type="number" name="odometer_out" value="{{ old('odometer_out') }}" min="0" placeholder="e.g. 84210" :placeholder="$store.ui.lang==='en' ? 'e.g. 84210' : 'cth. 84210'" style="{{ $fs }}width:100%;margin-bottom:6px;" />@include('partials.hint', ['en' => 'Optional. Record the reading shown on the dashboard when you take the vehicle.', 'ms' => 'Pilihan. Catat bacaan pada papan pemuka semasa anda ambil kenderaan.'])</div>
                    </div>
                    <button type="submit" class="uj-btn-primary" style="height:42px;padding:0 20px;font-size:13.5px;margin-top:16px;" x-text="$store.ui.lang==='en' ? 'Confirm booking' : 'Sahkan booking'">Confirm booking</button>
                </form>
            @endif
        </div>
    @endif

    {{-- Add-vehicle form (privileged) --}}
    @if ($privileged)
        <div x-show="addVehicle" x-cloak class="uj-card" style="padding:20px;margin-bottom:16px;">
            <h3 class="uj-card-title" style="margin-bottom:14px;" x-text="$store.ui.lang==='en' ? 'Add a vehicle' : 'Tambah kenderaan'">Add a vehicle</h3>
            @if ($errors->has('vehicle'))
                <div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12px;border-radius:8px;padding:9px 12px;margin-bottom:14px;">{{ $errors->first('vehicle') }}</div>
            @endif
            <form method="post" action="{{ route('vehicles.store') }}">
                @csrf
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;align-items:start;">
                    <div><label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Name' : 'Nama'">Name</span> *</label><input name="name" value="{{ old('name') }}" required maxlength="120" placeholder="e.g. Toyota Hilux" :placeholder="$store.ui.lang==='en' ? 'e.g. Toyota Hilux' : 'cth. Toyota Hilux'" style="{{ $fs }}width:100%;" /></div>
                    <div><label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Registration no' : 'No pendaftaran'">Registration no</span> *</label><input name="registration_no" value="{{ old('registration_no') }}" required maxlength="40" placeholder="e.g. WXY 1234" :placeholder="$store.ui.lang==='en' ? 'e.g. WXY 1234' : 'cth. WXY 1234'" style="{{ $fs }}width:100%;" /></div>
                    <div>
                        <label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Type' : 'Jenis'">Type</span> *</label>
                        <select name="type" required style="{{ $fs }}width:100%;">
                            @foreach ($typeLabels as $value => $label)
                                <option value="{{ $value }}" @selected(old('type') === $value) x-text="$store.ui.lang==='en' ? @js($label) : @js($typeLabelsMs[$value] ?? $label)">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div><label style="display:block;font-size:12px;color:var(--muted);margin-bottom:5px;" x-text="$store.ui.lang==='en' ? 'Seats' : 'Tempat duduk'">Seats</label><input type="number" name="seats" value="{{ old('seats') }}" min="1" max="120" placeholder="e.g. 5" :placeholder="$store.ui.lang==='en' ? 'e.g. 5' : 'cth. 5'" style="{{ $fs }}width:100%;margin-bottom:6px;" />@include('partials.hint', ['en' => 'How many people the vehicle seats. Shown to staff when they book.', 'ms' => 'Berapa ramai orang kenderaan ini boleh muat. Ditunjuk kepada staf semasa booking.'])</div>
                </div>
                <button type="submit" class="uj-btn-primary" style="height:42px;padding:0 20px;font-size:13.5px;margin-top:16px;" x-text="$store.ui.lang==='en' ? 'Add vehicle' : 'Tambah kenderaan'">Add vehicle</button>
            </form>
        </div>
    @endif

    {{-- Fleet + upcoming bookings per vehicle --}}
    <div class="uj-card">
        <div class="uj-card-head">
            <h3 class="uj-card-title" x-text="$store.ui.lang==='en' ? 'Fleet' : 'Armada'">Fleet</h3>
            <span style="font-size:12px;color:var(--muted);">{{ $vehicles->count() }} <span x-text="$store.ui.lang==='en' ? @js($vehicles->count() === 1 ? 'active vehicle' : 'active vehicles') : 'kenderaan aktif'">{{ $vehicles->count() === 1 ? 'active vehicle' : 'active vehicles' }}</span></span>
        </div>
        @forelse ($vehicles as $v)
            @php $slots = $vehicleBookings->get($v->id, collect()); @endphp
            <div style="padding:14px 20px;border-bottom:1px solid var(--hairline-soft);">
                <div style="display:flex;align-items:center;gap:10px;margin-bottom:9px;flex-wrap:wrap;">
                    <span style="font-size:14px;font-weight:600;color:var(--ink);">{{ $v->name }}</span>
                    <span style="font-size:11px;color:var(--muted);background:var(--canvas);border-radius:20px;padding:2px 9px;">{{ $v->registration_no }}</span>
                    <span style="font-size:11px;color:var(--muted);background:var(--canvas);border-radius:20px;padding:2px 9px;" x-text="$store.ui.lang==='en' ? @js($typeLabels[$v->type] ?? $v->type) : @js($typeLabelsMs[$v->type] ?? $typeLabels[$v->type] ?? $v->type)">{{ $typeLabels[$v->type] ?? $v->type }}</span>
                    @if ($v->seats)
                        <span style="font-size:11px;color:var(--muted);background:var(--canvas);border-radius:20px;padding:2px 9px;">{{ $v->seats }} <span x-text="$store.ui.lang==='en' ? 'seats' : 'tempat duduk'">seats</span></span>
                    @endif
                    <span style="font-size:11px;color:var(--success);background:#e7f4ee;border-radius:20px;padding:2px 9px;" x-text="$store.ui.lang==='en' ? 'Active' : 'Aktif'">Active</span>
                </div>
                @if ($slots->isEmpty())
                    <div style="font-size:12.5px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'No upcoming bookings — available.' : 'Tiada booking akan datang — tersedia.'">No upcoming bookings — available.</div>
                @else
                    <div style="display:flex;flex-direction:column;gap:6px;">
                        @foreach ($slots as $b)
                            <div style="display:flex;align-items:center;gap:12px;border:1px solid var(--hairline);border-left:3px solid var(--success);border-radius:7px;padding:7px 11px;font-size:12.5px;">
                                <span style="font-weight:600;color:var(--ink);min-width:200px;">{{ $fmtWin($b) }}</span>
                                <span style="color:var(--body);flex:1;">{{ $b->purpose }}{{ $b->destination ? ' → '.$b->destination : '' }}</span>
                                <span style="color:var(--muted);">{{ $b->employee?->name ?? '—' }}</span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @empty
            <div style="padding:28px 20px;text-align:center;">
                <div style="font-size:13px;color:var(--ink);font-weight:500;margin-bottom:3px;" x-text="$store.ui.lang==='en' ? 'No vehicles set up yet' : 'Belum ada kenderaan disediakan'">No vehicles set up yet</div>
                <div style="font-size:12px;color:var(--muted);line-height:1.5;">@if ($privileged)<span x-text="$store.ui.lang==='en' ? 'Click &quot;+ Add vehicle&quot; above to register your first vehicle, then staff can start booking it.' : 'Klik &quot;+ Add vehicle&quot; di atas untuk daftar kenderaan pertama anda, kemudian staf boleh mula booking.'"></span>@else<span x-text="$store.ui.lang==='en' ? 'No vehicles have been added yet. Ask HR or an admin to set them up.' : 'Belum ada kenderaan ditambah. Minta HR atau admin untuk menyediakannya.'"></span>@endif</div>
            </div>
        @endforelse
    </div>

    {{-- My upcoming bookings --}}
    @if ($canBook)
        <div class="uj-card" style="margin-top:16px;">
            <div class="uj-card-head"><h3 class="uj-card-title" x-text="$store.ui.lang==='en' ? 'My upcoming bookings' : 'Booking akan datang saya'">My upcoming bookings</h3></div>
            <div style="display:grid;grid-template-columns:1.4fr 1.8fr 1.6fr 1fr auto;gap:8px;padding:12px 20px;font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;border-bottom:1px solid var(--hairline-soft);">
                <span x-text="$store.ui.lang==='en' ? 'Vehicle' : 'Kenderaan'">Vehicle</span>
                <span x-text="$store.ui.lang==='en' ? 'Window' : 'Tempoh'">Window</span>
                <span x-text="$store.ui.lang==='en' ? 'Purpose' : 'Tujuan'">Purpose</span>
                <span x-text="$store.ui.lang==='en' ? 'Status' : 'Status'">Status</span>
                <span></span>
            </div>
            @forelse ($myBookings as $b)
                <div style="display:grid;grid-template-columns:1.4fr 1.8fr 1.6fr 1fr auto;gap:8px;padding:13px 20px;border-bottom:1px solid var(--hairline-soft);align-items:center;">
                    <span style="font-size:13.5px;color:var(--ink);font-weight:500;">{{ $b->vehicle?->name ?? '—' }}</span>
                    <span style="font-size:13px;color:var(--body);">{{ $fmtWin($b) }}</span>
                    <span style="font-size:13px;color:var(--body);">{{ $b->purpose }}{{ $b->destination ? ' → '.$b->destination : '' }}</span>
                    <span><span style="font-size:11px;font-weight:600;color:var(--success);background:#e7f4ee;border-radius:20px;padding:2px 10px;" x-text="$store.ui.lang==='en' ? 'Confirmed' : 'Disahkan'">Confirmed</span></span>
                    <form method="post" action="{{ route('vehicles.cancel', $b) }}" onsubmit="return confirm(window.Alpine && Alpine.store('ui').lang==='ms' ? 'Batalkan booking ini?' : 'Cancel this booking?');" style="text-align:right;">
                        @csrf
                        <button type="submit" class="uj-btn-ghost" style="height:28px;padding:0 11px;font-size:11.5px;" x-text="$store.ui.lang==='en' ? 'Cancel' : 'Batal'">Cancel</button>
                    </form>
                </div>
            @empty
                <div style="padding:28px 20px;text-align:center;">
                    <div style="font-size:13px;color:var(--ink);font-weight:500;margin-bottom:3px;" x-text="$store.ui.lang==='en' ? 'No upcoming bookings' : 'Tiada booking akan datang'">No upcoming bookings</div>
                    <div style="font-size:12px;color:var(--muted);line-height:1.5;"><span x-text="$store.ui.lang==='en' ? 'Click &quot;+ Book a vehicle&quot; above to reserve a vehicle. Your future bookings will be listed here.' : 'Klik &quot;+ Book a vehicle&quot; di atas untuk tempah kenderaan. Booking akan datang anda akan disenaraikan di sini.'"></span></div>
                </div>
            @endforelse
        </div>
    @endif

    {{-- All upcoming bookings across the fleet --}}
    @if ($allUpcoming->isNotEmpty())
        <div class="uj-card" style="margin-top:16px;">
            <div class="uj-card-head"><h3 class="uj-card-title" x-text="$store.ui.lang==='en' ? 'All upcoming bookings' : 'Semua booking akan datang'">All upcoming bookings</h3></div>
            <div style="display:grid;grid-template-columns:1.4fr 1.8fr 1.6fr 1.2fr;gap:8px;padding:12px 20px;font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;border-bottom:1px solid var(--hairline-soft);">
                <span x-text="$store.ui.lang==='en' ? 'Vehicle' : 'Kenderaan'">Vehicle</span>
                <span x-text="$store.ui.lang==='en' ? 'Window' : 'Tempoh'">Window</span>
                <span x-text="$store.ui.lang==='en' ? 'Purpose' : 'Tujuan'">Purpose</span>
                <span x-text="$store.ui.lang==='en' ? 'Booked by' : 'Ditempah oleh'">Booked by</span>
            </div>
            @foreach ($allUpcoming as $b)
                <div style="display:grid;grid-template-columns:1.4fr 1.8fr 1.6fr 1.2fr;gap:8px;padding:13px 20px;border-bottom:1px solid var(--hairline-soft);align-items:center;">
                    <span style="font-size:13.5px;color:var(--ink);font-weight:500;">{{ $b->vehicle?->name ?? '—' }}</span>
                    <span style="font-size:13px;color:var(--body);">{{ $fmtWin($b) }}</span>
                    <span style="font-size:13px;color:var(--body);">{{ $b->purpose }}{{ $b->destination ? ' → '.$b->destination : '' }}</span>
                    <span style="font-size:13px;color:var(--muted);">{{ $b->employee?->name ?? '—' }}</span>
                </div>
            @endforeach
        </div>
    @endif
</div>
@endsection
