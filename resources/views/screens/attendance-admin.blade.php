@extends('layouts.app')

@php
    $fs = 'height:38px;padding:0 11px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;background:#fff;color:var(--ink);outline:none;';
    $lbl = 'display:block;font-size:11px;font-weight:600;color:var(--muted);margin-bottom:4px;text-transform:uppercase;letter-spacing:.3px;';
    // [EN, BM] pairs — every visible string on this screen follows the app-wide
    // $store.ui.lang toggle, mirroring the board / timesheet screens.
    $weekdays = [1 => ['Mon', 'Isn'], 2 => ['Tue', 'Sel'], 3 => ['Wed', 'Rab'], 4 => ['Thu', 'Kha'], 5 => ['Fri', 'Jum'], 6 => ['Sat', 'Sab'], 7 => ['Sun', 'Ahd']];
    $arrangements = ['office' => ['Office', 'Pejabat'], 'client' => ['Client site', 'Lokasi klien'], 'wfh' => ['Work from home', 'Kerja dari rumah'], 'hybrid' => ['Hybrid', 'Hibrid']];
    $hhmm = fn ($t) => $t ? substr((string) $t, 0, 5) : '';

    // Shared styles. Each branch / site / staff is its own panel split into labelled
    // segments (Location · Working hours) so dense fields read as groups, not one strip.
    $th = 'font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.4px;';
    $mono = 'font-family:var(--font-mono);';
    $cell = $fs.'width:100%;';
    $panel = 'border:1px solid var(--hairline);border-radius:12px;padding:16px 18px;margin-bottom:12px;background:#fff;';
    $panelHead = 'display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:14px;';
    $segWrap = 'display:flex;flex-wrap:wrap;gap:18px 28px;';
    $seg = 'display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-top:8px;';
    $segDiv = 'border-left:1px solid var(--hairline-soft);padding-left:28px;';
    $colArr = 'minmax(190px,1fr) 150px 226px 128px auto';

    // Tab switcher (segmented control) — mirrors Position & Manday Rates.
    $tabBtn = 'border:none;border-radius:8px;padding:9px 18px;font-size:13px;cursor:pointer;transition:color .15s,background .15s,box-shadow .15s;display:inline-flex;align-items:center;gap:8px;white-space:nowrap;';
    $tabBadge = 'font-size:11px;font-weight:600;background:var(--canvas);border:1px solid var(--hairline);border-radius:20px;padding:1px 8px;color:var(--muted);';
    $wfhStaff = $staff->whereIn('work_arrangement', ['wfh', 'hybrid']);
    $wfhPending = $wfhStaff->filter(fn ($e) => $e->home_latitude === null)->count();
@endphp

@section('screen')
@include('partials.guide', [
    'key' => 'attendance-admin',
    'en'  => [
        'title' => 'Attendance Setup',
        'body'  => 'Define where and when staff are expected to work. Each branch and client site has a map location, an allowed radius, and working hours. Then assign each employee an arrangement — office, client, home, or hybrid — and the attendance screen enforces it automatically.',
        'who'   => 'HR and management only',
        'steps' => [
            'Set each branch geofence: tap "Pick on map", click the exact office spot, then set the radius and hours.',
            'Add client sites for resident engineers, with the client\'s own hours.',
            'Assign every employee an arrangement. Client staff pick a site; hybrid staff pick their office days.',
            'Register each work-from-home staff\'s address on the map — or leave it to capture automatically on their first work-from-home clock-in.',
        ],
    ],
    'ms'  => [
        'title' => 'Tetapan Kehadiran',
        'body'  => 'Tetapkan di mana dan bila staf sepatutnya bekerja. Setiap cawangan dan lokasi klien ada lokasi peta, radius dibenarkan, dan waktu kerja. Kemudian berikan setiap pekerja satu susunan — pejabat, klien, rumah, atau hibrid — dan skrin kehadiran menguatkuasakannya automatik.',
        'who'   => 'HR dan pengurusan sahaja',
        'steps' => [
            'Tetapkan geofence cawangan: tekan "Pilih di peta", klik lokasi tepat pejabat, kemudian tetapkan radius dan waktu.',
            'Tambah lokasi klien untuk jurutera residen, dengan waktu kerja klien.',
            'Berikan setiap pekerja satu susunan. Staf klien pilih lokasi; staf hibrid pilih hari pejabat.',
            'Daftar alamat setiap staf kerja-dari-rumah di peta — atau biarkan ia direkod automatik pada clock in kerja-dari-rumah pertama mereka.',
        ],
    ],
])

@php
    // Branch geofences moved to Company Settings (a branch is defined in one place). A
    // stale localStorage 'branches' from before the move would select a tab that no longer
    // exists, so fall back to 'sites'.
@endphp
<div x-data="{ tab: (['sites','wfh','arr'].includes(localStorage.getItem('attTab')) ? localStorage.getItem('attTab') : 'sites') }" x-init="$watch('tab', v => localStorage.setItem('attTab', v))">

{{-- 1. Lateness — above the tabs on purpose. This one setting governs every arrangement,
     and it used to sit inside the Work from home card, where nobody looking for it would
     think to open. It decides whether an office worker is stopped for a reason at 09:01. --}}
<div class="uj-card" style="padding:18px 20px;margin:0 0 16px;">
    <div class="uj-card-head" style="padding:0 0 10px;"><h3 class="uj-card-title" x-text="$store.ui.lang==='en' ? 'Lateness' : 'Kelewatan'">Lateness</h3></div>
    <form method="post" action="{{ route('attendance.admin.wfh-policy') }}" style="display:flex;gap:14px;flex-wrap:wrap;align-items:flex-end;">
        @csrf
        <div><label style="{{ $lbl }}"><span x-text="$store.ui.lang==='en' ? 'Late grace (min)' : 'Tempoh lewat (min)'">Late grace (min)</span></label><input name="late_grace_minutes" type="number" min="0" max="120" value="{{ $wfhPolicy?->late_grace_minutes }}" placeholder="15" style="{{ $fs }}width:110px;{{ $mono }}" /></div>
        <button type="submit" class="uj-btn-primary" style="height:38px;padding:0 18px;font-size:13px;"><span x-text="$store.ui.lang==='en' ? 'Save' : 'Simpan'">Save</span></button>
    </form>
    <p style="font-size:12px;color:var(--muted);margin:10px 0 0;" x-text="$store.ui.lang==='en' ? 'Applies to every arrangement — office, client, work-from-home and hybrid alike. Staff who clock in after this window must give a reason before the punch is accepted.' : 'Terpakai pada setiap susunan — pejabat, klien, kerja-dari-rumah dan hibrid. Staf yang clock in selepas tempoh ini mesti beri sebab sebelum rekod diterima.'">Applies to every arrangement — office, client, work-from-home and hybrid alike. Staff who clock in after this window must give a reason before the punch is accepted.</p>
</div>

{{-- ── Tab switcher (segmented control) ─────────────────────────────── --}}
<div style="display:flex;gap:4px;margin-bottom:18px;background:var(--canvas);border:1px solid var(--hairline);border-radius:11px;padding:4px;width:fit-content;max-width:100%;overflow-x:auto;">
    <button type="button" @click="tab='sites'" style="{{ $tabBtn }}"
        :style="tab==='sites' ? { background:'#fff', color:'var(--ink)', fontWeight:'600', boxShadow:'0 1px 2px rgba(0,0,0,.07)' } : { background:'transparent', color:'var(--muted)', fontWeight:'500' }">
        <span x-text="$store.ui.lang==='en' ? 'Client sites' : 'Lokasi klien'">Client sites</span> <span style="{{ $tabBadge }}">{{ $sites->count() }}</span>
    </button>
    <button type="button" @click="tab='wfh'" style="{{ $tabBtn }}"
        :style="tab==='wfh' ? { background:'#fff', color:'var(--ink)', fontWeight:'600', boxShadow:'0 1px 2px rgba(0,0,0,.07)' } : { background:'transparent', color:'var(--muted)', fontWeight:'500' }">
        <span x-text="$store.ui.lang==='en' ? 'Work from home' : 'Kerja dari rumah'">Work from home</span>
        @if ($wfhPending > 0)
            <span style="font-size:11px;font-weight:600;background:color-mix(in oklch, var(--amber) 16%, #fff);border:1px solid color-mix(in oklch, var(--amber) 35%, var(--hairline));border-radius:20px;padding:1px 8px;color:var(--ink);">{{ $wfhPending }}</span>
        @elseif ($wfhStaff->count() > 0)
            <span style="font-size:11px;font-weight:600;color:var(--success);">✓</span>
        @endif
    </button>
    <button type="button" @click="tab='arr'" style="{{ $tabBtn }}"
        :style="tab==='arr' ? { background:'#fff', color:'var(--ink)', fontWeight:'600', boxShadow:'0 1px 2px rgba(0,0,0,.07)' } : { background:'transparent', color:'var(--muted)', fontWeight:'500' }">
        <span x-text="$store.ui.lang==='en' ? 'Staff arrangements' : 'Susunan staf'">Staff arrangements</span> <span style="{{ $tabBadge }}">{{ $staff->count() }}</span>
    </button>
</div>

{{-- Branch geofences & hours now live on Company Settings → Branches, so a branch is
     defined in one place. This screen covers only the non-branch policies below. --}}

{{-- ══ TAB · CLIENT SITES ══ --}}
<div x-show="tab==='sites'" x-cloak>
{{-- 2. Client sites --}}
<div class="uj-card" style="margin-bottom:16px;padding:22px;">
    <div class="uj-card-head" style="padding:0 0 14px;"><h3 class="uj-card-title" x-text="$store.ui.lang==='en' ? 'Client sites (resident engineers)' : 'Lokasi klien (jurutera residen)'">Client sites (resident engineers)</h3></div>
    @foreach ($sites as $s)
        <form method="post" action="{{ route('attendance.admin.sites.update', $s) }}" style="{{ $panel }}">
            @csrf
            <div style="{{ $panelHead }}">
                <div style="display:flex;align-items:center;gap:8px;">
                    <span style="font-size:14px;font-weight:700;color:var(--ink);">{{ $s->name }}</span>
                    @if ($s->client)<span style="font-size:11px;font-weight:600;color:var(--muted);background:var(--hairline-soft);border-radius:20px;padding:2px 9px;">{{ $s->client }}</span>@endif
                </div>
                <div style="display:flex;gap:8px;">
                    <button type="submit" class="uj-btn-primary" style="height:36px;padding:0 18px;font-size:13px;"><span x-text="$store.ui.lang==='en' ? 'Save' : 'Simpan'">Save</span></button>
                    <button type="submit" form="delete-site-{{ $s->id }}" class="uj-btn-ghost" style="height:36px;padding:0 12px;font-size:12px;color:var(--red);"><span x-text="$store.ui.lang==='en' ? 'Remove' : 'Buang'">Remove</span></button>
                </div>
            </div>
            <div style="{{ $segWrap }}">
                <div>
                    <span style="{{ $th }}" x-text="$store.ui.lang==='en' ? 'Details' : 'Butiran'">Details</span>
                    <div style="{{ $seg }}">
                        <div><label style="{{ $lbl }}"><span x-text="$store.ui.lang==='en' ? 'Site name' : 'Nama lokasi'">Site name</span></label><input name="name" value="{{ $s->name }}" required style="{{ $fs }}width:200px;" /></div>
                        <div><label style="{{ $lbl }}"><span x-text="$store.ui.lang==='en' ? 'Client' : 'Klien'">Client</span></label><input name="client" value="{{ $s->client }}" style="{{ $fs }}width:150px;" /></div>
                    </div>
                </div>
                <div style="{{ $segDiv }}">
                    <span style="{{ $th }}" x-text="$store.ui.lang==='en' ? 'Location' : 'Lokasi'">Location</span>
                    <div style="{{ $seg }}">
                        <div><label style="{{ $lbl }}"><span x-text="$store.ui.lang==='en' ? 'Latitude' : 'Latitud'">Latitude</span></label><input id="lat-site-{{ $s->id }}" name="latitude" value="{{ $s->latitude }}" style="{{ $fs }}width:120px;{{ $mono }}" /></div>
                        <div><label style="{{ $lbl }}"><span x-text="$store.ui.lang==='en' ? 'Longitude' : 'Longitud'">Longitude</span></label><input id="lng-site-{{ $s->id }}" name="longitude" value="{{ $s->longitude }}" style="{{ $fs }}width:120px;{{ $mono }}" /></div>
                        <button type="button" x-data @click="window.dispatchEvent(new CustomEvent('open-map-picker', { detail: { latId: 'lat-site-{{ $s->id }}', lngId: 'lng-site-{{ $s->id }}', title: @js($s->name) } }))" class="uj-btn-ghost" style="height:38px;padding:0 12px;font-size:12px;white-space:nowrap;">📍 <span x-text="$store.ui.lang==='en' ? 'Map' : 'Peta'">Map</span></button>
                        <div><label style="{{ $lbl }}">Radius (m)</label><input name="radius_m" type="number" min="20" max="5000" value="{{ $s->radius_m ?? 200 }}" required style="{{ $fs }}width:90px;{{ $mono }}" /></div>
                    </div>
                </div>
                <div style="{{ $segDiv }}">
                    <span style="{{ $th }}" x-text="$store.ui.lang==='en' ? 'Working hours' : 'Waktu kerja'">Working hours</span>
                    <div style="{{ $seg }}">
                        <div><label style="{{ $lbl }}"><span x-text="$store.ui.lang==='en' ? 'Start' : 'Mula'">Start</span></label><input name="work_start" type="time" value="{{ $hhmm($s->work_start) }}" style="{{ $fs }}width:120px;" /></div>
                        <div><label style="{{ $lbl }}"><span x-text="$store.ui.lang==='en' ? 'End' : 'Tamat'">End</span></label><input name="work_end" type="time" value="{{ $hhmm($s->work_end) }}" style="{{ $fs }}width:120px;" /></div>
                        <div><label style="{{ $lbl }}"><span x-text="$store.ui.lang==='en' ? 'Min hrs' : 'Jam min'">Min hrs</span></label><input name="min_hours" type="number" step="0.5" min="0" max="24" value="{{ $s->min_hours }}" style="{{ $fs }}width:84px;{{ $mono }}" /></div>
                    </div>
                </div>
            </div>
        </form>
        <form id="delete-site-{{ $s->id }}" method="post" action="{{ route('attendance.admin.sites.delete', $s) }}" onsubmit="return confirm('Remove {{ $s->name }}?')">@csrf</form>
    @endforeach

    {{-- Add new site --}}
    <form method="post" action="{{ route('attendance.admin.sites.store') }}" style="{{ $panel }}border-style:dashed;">
        @csrf
        <div style="{{ $panelHead }}">
            <span style="font-size:13px;font-weight:700;color:var(--ink);" x-text="$store.ui.lang==='en' ? 'Add a client site' : 'Tambah lokasi klien'">Add a client site</span>
            <button type="submit" class="uj-btn-primary" style="height:36px;padding:0 18px;font-size:13px;"><span x-text="$store.ui.lang==='en' ? '+ Add site' : '+ Tambah lokasi'">+ Add site</span></button>
        </div>
        <div style="{{ $segWrap }}">
            <div>
                <span style="{{ $th }}" x-text="$store.ui.lang==='en' ? 'Details' : 'Butiran'">Details</span>
                <div style="{{ $seg }}">
                    <div><label style="{{ $lbl }}"><span x-text="$store.ui.lang==='en' ? 'Site name' : 'Nama lokasi'">Site name</span></label><input name="name" required placeholder="e.g. Petron Tg Lumpur" style="{{ $fs }}width:200px;" /></div>
                    <div><label style="{{ $lbl }}"><span x-text="$store.ui.lang==='en' ? 'Client' : 'Klien'">Client</span></label><input name="client" placeholder="Petron" style="{{ $fs }}width:150px;" /></div>
                </div>
            </div>
            <div style="{{ $segDiv }}">
                <span style="{{ $th }}" x-text="$store.ui.lang==='en' ? 'Location' : 'Lokasi'">Location</span>
                <div style="{{ $seg }}">
                    <div><label style="{{ $lbl }}"><span x-text="$store.ui.lang==='en' ? 'Latitude' : 'Latitud'">Latitude</span></label><input id="lat-newsite" name="latitude" placeholder="Lat" style="{{ $fs }}width:120px;{{ $mono }}" /></div>
                    <div><label style="{{ $lbl }}"><span x-text="$store.ui.lang==='en' ? 'Longitude' : 'Longitud'">Longitude</span></label><input id="lng-newsite" name="longitude" placeholder="Lng" style="{{ $fs }}width:120px;{{ $mono }}" /></div>
                    <button type="button" x-data @click="window.dispatchEvent(new CustomEvent('open-map-picker', { detail: { latId: 'lat-newsite', lngId: 'lng-newsite', title: 'New client site' } }))" class="uj-btn-ghost" style="height:38px;padding:0 12px;font-size:12px;white-space:nowrap;">📍 <span x-text="$store.ui.lang==='en' ? 'Map' : 'Peta'">Map</span></button>
                    <div><label style="{{ $lbl }}">Radius (m)</label><input name="radius_m" type="number" min="20" max="5000" value="200" required style="{{ $fs }}width:90px;{{ $mono }}" /></div>
                </div>
            </div>
            <div style="{{ $segDiv }}">
                <span style="{{ $th }}" x-text="$store.ui.lang==='en' ? 'Working hours' : 'Waktu kerja'">Working hours</span>
                <div style="{{ $seg }}">
                    <div><label style="{{ $lbl }}"><span x-text="$store.ui.lang==='en' ? 'Start' : 'Mula'">Start</span></label><input name="work_start" type="time" style="{{ $fs }}width:120px;" /></div>
                    <div><label style="{{ $lbl }}"><span x-text="$store.ui.lang==='en' ? 'End' : 'Tamat'">End</span></label><input name="work_end" type="time" style="{{ $fs }}width:120px;" /></div>
                    <div><label style="{{ $lbl }}"><span x-text="$store.ui.lang==='en' ? 'Min hrs' : 'Jam min'">Min hrs</span></label><input name="min_hours" type="number" step="0.5" min="0" max="24" style="{{ $fs }}width:84px;{{ $mono }}" /></div>
                </div>
            </div>
        </div>
    </form>
</div>

</div>{{-- /tab sites --}}

{{-- ══ TAB 3 · WORK FROM HOME ══ --}}
<div x-show="tab==='wfh'" x-cloak>
{{-- 3. Work from home --}}
<div class="uj-card" style="margin-bottom:16px;padding:22px;">
    <div class="uj-card-head" style="padding:0 0 14px;"><h3 class="uj-card-title" x-text="$store.ui.lang==='en' ? 'Work from home' : 'Kerja dari rumah'">Work from home</h3></div>

    {{-- One company-wide WFH standard: every WFH / hybrid home day follows these hours, not the staff's branch. --}}
    <div style="padding:0 0 18px;margin-bottom:6px;border-bottom:1px solid var(--hairline);">
        <span style="{{ $th }}" x-text="$store.ui.lang==='en' ? 'Company WFH hours' : 'Waktu WFH syarikat'">Company WFH hours</span>
        <p style="font-size:12px;color:var(--muted);margin:3px 0 12px;" x-text="$store.ui.lang==='en' ? 'Applies to every WFH & hybrid home day. Leave blank to use each staff\'s branch hours.' : 'Terpakai pada setiap hari rumah WFH & hibrid. Biar kosong untuk guna waktu cawangan setiap staf.'">Applies to every WFH &amp; hybrid home day. Leave blank to use each staff's branch hours.</p>
        <form method="post" action="{{ route('attendance.admin.wfh-policy') }}" style="display:flex;gap:14px;flex-wrap:wrap;align-items:flex-end;">
            @csrf
            <div><label style="{{ $lbl }}"><span x-text="$store.ui.lang==='en' ? 'Start' : 'Mula'">Start</span></label><input name="wfh_work_start" type="time" value="{{ $hhmm($wfhPolicy?->wfh_work_start) }}" style="{{ $fs }}width:120px;" /></div>
            <div><label style="{{ $lbl }}"><span x-text="$store.ui.lang==='en' ? 'End' : 'Tamat'">End</span></label><input name="wfh_work_end" type="time" value="{{ $hhmm($wfhPolicy?->wfh_work_end) }}" style="{{ $fs }}width:120px;" /></div>
            <div><label style="{{ $lbl }}"><span x-text="$store.ui.lang==='en' ? 'Min hrs' : 'Jam min'">Min hrs</span></label><input name="wfh_min_hours" type="number" step="0.5" min="0" max="24" value="{{ $wfhPolicy?->wfh_min_hours }}" style="{{ $fs }}width:90px;{{ $mono }}" /></div>
            <div><label style="{{ $lbl }}">Radius (m)</label><input name="wfh_radius_m" type="number" min="20" max="5000" value="{{ $wfhPolicy?->wfh_radius_m }}" placeholder="200" style="{{ $fs }}width:100px;{{ $mono }}" /></div>
            <button type="submit" class="uj-btn-primary" style="height:38px;padding:0 18px;font-size:13px;"><span x-text="$store.ui.lang==='en' ? 'Save' : 'Simpan'">Save</span></button>
        </form>
    </div>

    <span style="{{ $th }}" x-text="$store.ui.lang==='en' ? 'Registered home addresses' : 'Alamat rumah berdaftar'">Registered home addresses</span>
    @if ($wfhStaff->isEmpty())
        <p style="font-size:13px;color:var(--muted);margin-top:8px;" x-text="$store.ui.lang==='en' ? 'No staff on work-from-home or hybrid arrangements yet. Assign an arrangement below first.' : 'Belum ada staf dengan susunan kerja-dari-rumah atau hibrid. Berikan susunan di bawah dahulu.'">No staff on work-from-home or hybrid arrangements yet. Assign an arrangement below first.</p>
    @else
    <div style="margin-top:10px;">
        @foreach ($wfhStaff as $e)
            <form method="post" action="{{ route('attendance.admin.home', $e) }}" style="{{ $panel }}">
                @csrf
                <div style="{{ $panelHead }}">
                    <div style="display:flex;align-items:center;gap:8px;">
                        <span style="font-size:14px;font-weight:700;color:var(--ink);">{{ $e->name }}</span>
                        @php $arrPair = $arrangements[$e->work_arrangement] ?? $arrangements['wfh']; @endphp
                        <span style="font-size:11px;font-weight:600;color:var(--muted);background:var(--hairline-soft);border-radius:20px;padding:2px 9px;" x-text="$store.ui.lang==='en' ? @js($arrPair[0]) : @js($arrPair[1])">{{ $arrPair[0] }}</span>
                        @if ($e->home_latitude !== null)
                            <span style="font-size:11px;font-weight:600;color:var(--success);" x-text="$store.ui.lang==='en' ? '● Registered' : '● Berdaftar'">● Registered</span>
                        @else
                            <span style="font-size:11px;font-weight:600;color:var(--muted);" x-text="$store.ui.lang==='en' ? '○ Not set' : '○ Belum ditetapkan'">○ Not set</span>
                        @endif
                    </div>
                    <button type="submit" class="uj-btn-primary" style="height:36px;padding:0 18px;font-size:13px;"><span x-text="$store.ui.lang==='en' ? 'Save' : 'Simpan'">Save</span></button>
                </div>
                <div>
                    <span style="{{ $th }}" x-text="$store.ui.lang==='en' ? 'Home location' : 'Lokasi rumah'">Home location</span>
                    <div style="{{ $seg }}">
                        <div><label style="{{ $lbl }}"><span x-text="$store.ui.lang==='en' ? 'Latitude' : 'Latitud'">Latitude</span></label><input id="lat-home-{{ $e->id }}" name="home_latitude" value="{{ $e->home_latitude }}" required style="{{ $fs }}width:130px;{{ $mono }}" /></div>
                        <div><label style="{{ $lbl }}"><span x-text="$store.ui.lang==='en' ? 'Longitude' : 'Longitud'">Longitude</span></label><input id="lng-home-{{ $e->id }}" name="home_longitude" value="{{ $e->home_longitude }}" required style="{{ $fs }}width:130px;{{ $mono }}" /></div>
                        <button type="button" x-data @click="window.dispatchEvent(new CustomEvent('open-map-picker', { detail: { latId: 'lat-home-{{ $e->id }}', lngId: 'lng-home-{{ $e->id }}', title: @js($e->name.' — home'), submit: true } }))" class="uj-btn-ghost" style="height:38px;padding:0 12px;font-size:12px;white-space:nowrap;">📍 <span x-text="$store.ui.lang==='en' ? 'Pick on map & save' : 'Pilih di peta & simpan'">Pick on map &amp; save</span></button>
                    </div>
                </div>
            </form>
        @endforeach
    </div>
    @endif
</div>

</div>{{-- /tab wfh --}}

{{-- ══ TAB 4 · STAFF ARRANGEMENTS ══ --}}
<div x-show="tab==='arr'" x-cloak>
{{-- 4. Staff arrangements --}}
<div class="uj-card" style="padding:22px;">
    <div class="uj-card-head" style="padding:0 0 14px;"><h3 class="uj-card-title" x-text="$store.ui.lang==='en' ? 'Staff work arrangements' : 'Susunan kerja staf'">Staff work arrangements</h3></div>
    <div style="overflow-x:auto;">
        <div style="min-width:840px;">
            <div style="display:grid;grid-template-columns:{{ $colArr }};gap:12px;align-items:end;padding:0 0 10px;border-bottom:1px solid var(--hairline);">
                <span style="{{ $th }}" x-text="$store.ui.lang==='en' ? 'Staff' : 'Staf'">Staff</span><span style="{{ $th }}" x-text="$store.ui.lang==='en' ? 'Arrangement' : 'Susunan'">Arrangement</span><span style="{{ $th }}" x-text="$store.ui.lang==='en' ? 'Site / office days' : 'Lokasi / hari pejabat'">Site / office days</span><span style="{{ $th }}" x-text="$store.ui.lang==='en' ? 'Home' : 'Rumah'">Home</span><span></span>
            </div>
            <div style="max-height:560px;overflow-y:auto;">
                @foreach ($staff as $e)
                    <form method="post" action="{{ route('attendance.admin.staff', $e) }}"
                          x-data="{ arr: '{{ $e->work_arrangement ?: 'office' }}' }"
                          style="display:grid;grid-template-columns:{{ $colArr }};gap:12px;align-items:center;padding:12px 0;border-bottom:1px solid var(--hairline-soft);">
                        @csrf
                        <div>
                            <div style="font-size:13.5px;font-weight:600;color:var(--ink);">{{ $e->name }}</div>
                            <div style="font-size:11.5px;color:var(--muted);">{{ $e->position }}@if ($e->branch) · {{ $e->branch->name }}@endif</div>
                        </div>

                        <select name="work_arrangement" x-model="arr" aria-label="Arrangement" style="{{ $cell }}">
                            @foreach ($arrangements as $v => $l)<option value="{{ $v }}" @selected(($e->work_arrangement ?: 'office') === $v) x-text="$store.ui.lang==='en' ? @js($l[0]) : @js($l[1])">{{ $l[0] }}</option>@endforeach
                        </select>

                        {{-- Conditional: client site (client) or office weekdays (hybrid); empty otherwise --}}
                        <div>
                            <select name="work_site_id" x-show="arr === 'client'" x-cloak aria-label="Client site" style="{{ $cell }}">
                                <option value="" x-text="$store.ui.lang==='en' ? '— none —' : '— tiada —'">— none —</option>
                                @foreach ($sites as $s)<option value="{{ $s->id }}" @selected($e->work_site_id === $s->id)>{{ $s->name }}</option>@endforeach
                            </select>
                            <div x-show="arr === 'hybrid'" x-cloak style="display:flex;gap:3px;">
                                @foreach ($weekdays as $wn => $wl)
                                    <label style="font-size:10.5px;display:flex;flex-direction:column;align-items:center;gap:2px;cursor:pointer;color:var(--body);">
                                        <span x-text="$store.ui.lang==='en' ? @js($wl[0]) : @js($wl[1])">{{ $wl[0] }}</span>
                                        <input type="checkbox" name="hybrid_office_days[]" value="{{ $wn }}" @checked(in_array($wn, $e->hybrid_office_days ?? [], true)) style="width:16px;height:16px;" />
                                    </label>
                                @endforeach
                            </div>
                        </div>

                        {{-- Home status --}}
                        <div>
                            @if ($e->home_latitude !== null)
                                <label style="font-size:11.5px;color:var(--body);display:flex;align-items:center;gap:5px;"><input type="checkbox" name="reset_home" value="1" /> <span style="color:var(--success);" x-text="$store.ui.lang==='en' ? 'Registered' : 'Berdaftar'">Registered</span> · <span x-text="$store.ui.lang==='en' ? 'reset' : 'set semula'">reset</span></label>
                            @else
                                <span style="font-size:11.5px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'Not set' : 'Belum ditetapkan'">Not set</span>
                            @endif
                        </div>

                        <button type="submit" class="uj-btn-primary" style="height:38px;padding:0 16px;font-size:13px;"><span x-text="$store.ui.lang==='en' ? 'Save' : 'Simpan'">Save</span></button>
                    </form>
                @endforeach
            </div>
        </div>
    </div>
</div>

</div>{{-- /tab arr --}}
</div>{{-- /tabs --}}

@include('partials.map-picker')
@endsection
