@extends('layouts.app')

@php
    $stColor = ['on_time' => 'var(--success)', 'late' => 'var(--amber)', 'pending' => 'var(--muted-soft)'];
    $stLabel = ['on_time' => 'On time', 'late' => 'Late', 'pending' => 'Pending'];
    $stLabelMs = ['on_time' => 'Tepat masa', 'late' => 'Lewat', 'pending' => 'Menunggu'];
    // Flag badge labels (EN / MS) for the weekly list.
    $flagLabel = [
        'late' => ['Late', 'Lewat'],
        'out_of_radius_in' => ['Off-site clock-in', 'Clock in luar lokasi'],
        'out_of_radius_out' => ['Off-site clock-out', 'Clock out luar lokasi'],
        'early_out' => ['Left early', 'Balik awal'],
        'short_hours' => ['Short hours', 'Jam kurang'],
    ];
    $siteTypeLabel = [
        'office' => ['Office', 'Pejabat'],
        'client' => ['Client site', 'Lokasi klien'],
        'home' => ['Work from home', 'Kerja dari rumah'],
    ];
@endphp

@section('screen')
@include('partials.see-all-btn', ['target' => 'attendance-report', 'label' => 'See all staff attendance', 'labelMs' => 'Lihat kehadiran semua staf'])
@include('partials.guide', [
    'key'   => 'attendance',
    'en'  => [
        'title' => 'Attendance',
        'body'  => 'Clock in when you start and clock out when you finish. Your GPS is checked against where you are meant to be that day — your office, your client site, or your home. If you are outside that location, you can still clock but must give a reason. Clocking out early or off-site is also flagged.',
        'who'   => 'Everyone clocks their own time',
        'steps' => [
            'The banner shows where you are expected today and your hours.',
            'Tap "Clock in" and allow location so the system can confirm you are on-site.',
            'If you are outside the location, a reason box appears — say why (e.g. client meeting).',
            'Clock out when you finish. Leaving before your end time or off-site needs a reason too.',
        ],
    ],
    'ms'  => [
        'title' => 'Kehadiran',
        'body'  => 'Clock in bila mula dan clock out bila habis. GPS anda disemak dengan tempat anda sepatutnya berada hari itu — pejabat, lokasi klien, atau rumah. Jika anda di luar lokasi itu, anda masih boleh clock tetapi perlu beri sebab. Clock out awal atau di luar lokasi juga ditanda.',
        'who'   => 'Semua orang rekod masa sendiri',
        'steps' => [
            'Sepanduk menunjukkan di mana anda sepatutnya hari ini dan waktu kerja anda.',
            'Tekan "Clock in" dan benarkan lokasi supaya sistem boleh sahkan anda di lokasi.',
            'Jika anda di luar lokasi, kotak sebab muncul — nyatakan kenapa (cth. mesyuarat klien).',
            'Clock out bila habis. Balik sebelum waktu tamat atau di luar lokasi perlu sebab juga.',
        ],
    ],
])

<style>
    /* Mobile-first attendance layout. Clock card owns the top of the screen,
       week list collapses on phones, action button sits under the thumb. */
    .att-wrap{display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap;}
    .att-clock{flex:1;min-width:300px;padding:28px;text-align:center;}
    .att-week{flex:1.4;min-width:340px;}
    .att-time{font-size:44px;font-weight:600;color:var(--ink);font-family:var(--font-mono);letter-spacing:-1px;line-height:1.05;}
    .att-action-btn{width:100%;height:56px;font-size:16px;font-weight:600;display:flex;align-items:center;justify-content:center;gap:9px;}
    .att-selfie-tile{display:flex;align-items:center;justify-content:center;width:100%;padding:15px;border:1.5px dashed var(--hairline);border-radius:12px;cursor:pointer;background:var(--canvas);transition:border-color .15s ease,background .15s ease;}
    .att-selfie-tile:hover{border-color:var(--muted);background:var(--surface,var(--canvas));}
    .att-spinner{width:16px;height:16px;border:2px solid currentColor;border-right-color:transparent;border-radius:50%;animation:att-spin .6s linear infinite;}
    @keyframes att-spin{to{transform:rotate(360deg);}}
    .att-week-head{display:flex;align-items:center;justify-content:space-between;gap:10px;width:100%;background:none;border:none;text-align:left;cursor:default;}
    .att-week-chevron{display:none;font-size:22px;line-height:1;color:var(--muted);transition:transform .2s ease;}
    @media (max-width:640px){
        .att-clock{min-width:100%;flex:1 1 100%;padding:22px 18px;}
        .att-week{min-width:100%;flex:1 1 100%;}
        .att-time{font-size:54px;}
        .att-action-btn{height:60px;font-size:17px;}
        .att-week-head{cursor:pointer;}
        .att-week-chevron{display:inline-block;}
        .att-week-chevron.att-open{transform:rotate(90deg);}
    }
</style>

<div class="att-wrap">
    <div class="uj-card att-clock">
        @php
            $ci = $today?->clock_in ? \Illuminate\Support\Str::of($today->clock_in)->limit(5, '') : null;
            $co = $today?->clock_out ? \Illuminate\Support\Str::of($today->clock_out)->limit(5, '') : null;
        @endphp

        {{-- Where the staff is expected to clock from today. --}}
        @if ($site)
            @php $sType = $siteTypeLabel[$site->type] ?? ['Workplace', 'Tempat kerja']; @endphp
            <div style="background:var(--canvas);border:1px solid var(--hairline-soft);border-radius:10px;padding:11px 13px;margin-bottom:18px;text-align:left;">
                <div style="display:flex;align-items:center;gap:7px;font-size:12.5px;">
                    <span style="font-weight:600;color:var(--ink);" x-text="$store.ui.lang==='en' ? @js($sType[0]) : @js($sType[1])">{{ $sType[0] }}</span>
                    <span style="color:var(--muted);">· {{ $site->label }}</span>
                </div>
                <div style="font-size:11.5px;color:var(--muted);margin-top:3px;font-family:var(--font-mono);">
                    @if ($site->workStart && $site->workEnd){{ $site->workStart }}–{{ $site->workEnd }}@endif
                    @if ($site->hasGeofence()) · <span x-text="$store.ui.lang==='en' ? 'within {{ $site->radiusM }}m' : 'dalam {{ $site->radiusM }}m'"></span>@endif
                    @if ($site->needsHomeCapture) · <span style="color:var(--info);" x-text="$store.ui.lang==='en' ? 'home registers on this clock-in' : 'rumah didaftar pada clock in ini'"></span>@endif
                </div>
            </div>
        @endif

        <div style="font-size:13px;color:var(--muted);margin-bottom:6px;">{{ now()->format('l, j F Y') }}</div>
        @if ($co)
            {{-- Shift done — freeze on the clock-out time. --}}
            <div class="att-time">{{ $co }}</div>
        @else
            {{-- Live wall-clock: ticks every second so the big number is the CURRENT time,
                 whether you are about to clock in or clock out. Your actual clock-in time
                 stays in the muted line below ("Clocked in at …"). --}}
            <div class="att-time" x-data="{ t: @js(now()->format('H:i')) }"
                 x-init="setInterval(() => { const d = new Date(); t = String(d.getHours()).padStart(2,'0') + ':' + String(d.getMinutes()).padStart(2,'0'); }, 1000)"
                 x-text="t">{{ now()->format('H:i') }}</div>
        @endif
        <div style="font-size:13px;color:var(--muted);margin:6px 0 20px;">
            @if ($co) <span x-text="$store.ui.lang==='en' ? 'Clocked out at {{ $co }} · done for today' : 'Clock out pada {{ $co }} · selesai untuk hari ini'"></span>
            @elseif ($ci) <span x-text="$store.ui.lang==='en' ? 'Clocked in at {{ $ci }}' : 'Clock in pada {{ $ci }}'"></span>
            @else <span x-text="$store.ui.lang==='en' ? 'Not clocked in yet' : 'Belum clock in'"></span> @endif
        </div>

        @if ($co)
            <button disabled class="uj-btn-ghost att-action-btn" style="opacity:.6;cursor:default;"><span x-text="$store.ui.lang==='en' ? 'Shift complete ✓' : 'Shift selesai ✓'">Shift complete ✓</span></button>
        @else
            <form method="post" action="{{ route('attendance.clock') }}" enctype="multipart/form-data"
                  x-data="{
                      submitting: false,
                      photoUrl: null,
                      camOpen: false,
                      stream: null,
                      camError: '',
                      camNotice: '',
                      action: '{{ $ci ? 'out' : 'in' }}',
                      justify: {{ (session('attendance_justify') || $errors->has('justification')) ? 'true' : 'false' }},
                      reason: @js(old('justification', '')),
                      siteLat: {{ $site && $site->hasGeofence() ? $site->latitude : 'null' }},
                      siteLng: {{ $site && $site->hasGeofence() ? $site->longitude : 'null' }},
                      radius: {{ $site?->radiusM ?? 0 }},
                      expectedEnd: '{{ $site?->workEnd ?? '' }}',
                      distM(lat, lng) {
                          const R = 6371000, toR = (x) => x * Math.PI / 180;
                          const dLa = toR(lat - this.siteLat), dLo = toR(lng - this.siteLng);
                          const a = Math.sin(dLa/2)**2 + Math.cos(toR(this.siteLat))*Math.cos(toR(lat))*Math.sin(dLo/2)**2;
                          return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
                      },
                      earlyNow() {
                          if (!this.expectedEnd) return false;
                          const p = this.expectedEnd.split(':');
                          const now = new Date();
                          return (now.getHours()*60 + now.getMinutes()) < (Number(p[0])*60 + Number(p[1]));
                      },
                      proceed(lat, lng) {
                          let need = false;
                          if (this.siteLat !== null && lat !== null && this.distM(lat, lng) > this.radius) need = true;
                          if (this.action === 'out' && this.earlyNow()) need = true;
                          if (need && !this.reason.trim()) { this.justify = true; this.submitting = false; this.$nextTick(() => this.$refs.reason?.focus()); return; }
                          if (lat !== null) { this.$refs.lat.value = lat; this.$refs.lng.value = lng; }
                          this.$el.submit();
                      },
                      submit() {
                          if (this.submitting) return;
                          this.submitting = true;
                          if (!navigator.geolocation) { this.proceed(null, null); return; }
                          navigator.geolocation.getCurrentPosition(
                              (pos) => this.proceed(pos.coords.latitude, pos.coords.longitude),
                              () => this.proceed(null, null),
                              { enableHighAccuracy: true, timeout: 8000, maximumAge: 60000 }
                          );
                      },
                      /* Open the in-page webcam. On any failure, say WHY on screen and fall
                         back to the file / native-camera picker instead of failing silently. */
                      async openCam() {
                          this.camError = '';
                          this.camNotice = '';
                          if (!window.isSecureContext) {
                              this.camNotice = 'In-page camera needs HTTPS or localhost — you are on ' + location.origin + '. Opened the file / phone-camera picker instead.';
                              this.$refs.photo.click();
                              return;
                          }
                          if (!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia)) {
                              this.camNotice = 'This browser will not expose the camera here. Opened the file picker instead.';
                              this.$refs.photo.click();
                              return;
                          }
                          try {
                              this.stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' }, audio: false });
                              this.camOpen = true;
                              this.$nextTick(() => { this.$refs.cam.srcObject = this.stream; });
                          } catch (e) {
                              const n = e.name || 'error';
                              let msg;
                              if (n === 'NotFoundError' || n === 'OverconstrainedError' || n === 'DevicesNotFoundError') {
                                  msg = 'No camera found on this device. If you have a webcam, plug it in or close any app using it (Zoom, Teams), then click again. Opened the file picker so you can still attach a photo.';
                              } else if (n === 'NotAllowedError' || n === 'SecurityError' || n === 'PermissionDeniedError') {
                                  msg = 'Camera permission blocked. Click the camera / lock icon in the address bar, allow camera for this site, then click again. Opened the file picker as a fallback.';
                              } else if (n === 'NotReadableError' || n === 'TrackStartError') {
                                  msg = 'Camera is busy — another app (Zoom, Teams, OBS) is using it. Close it, then click again. Opened the file picker as a fallback.';
                              } else {
                                  msg = 'Could not open camera (' + n + '). Opened the file picker so you can still attach a photo.';
                              }
                              this.camNotice = msg;
                              this.$refs.photo.click();
                          }
                      },
                      capture() {
                          const v = this.$refs.cam, c = this.$refs.canvas;
                          c.width = v.videoWidth; c.height = v.videoHeight;
                          c.getContext('2d').drawImage(v, 0, 0, c.width, c.height);
                          c.toBlob((blob) => {
                              if (!blob) { this.camError = 'Capture failed, try again.'; return; }
                              const file = new File([blob], 'selfie.jpg', { type: 'image/jpeg' });
                              const dt = new DataTransfer();
                              dt.items.add(file);
                              this.$refs.photo.files = dt.files;
                              if (this.photoUrl) URL.revokeObjectURL(this.photoUrl);
                              this.photoUrl = URL.createObjectURL(file);
                              this.closeCam();
                          }, 'image/jpeg', 0.9);
                      },
                      closeCam() {
                          if (this.stream) { this.stream.getTracks().forEach((t) => t.stop()); this.stream = null; }
                          this.camOpen = false;
                      }
                  }"
                  @submit.prevent="submit()">
                @csrf
                <input type="hidden" name="action" value="{{ $ci ? 'out' : 'in' }}" />
                <input type="hidden" name="latitude" x-ref="lat" />
                <input type="hidden" name="longitude" x-ref="lng" />

                {{-- Optional selfie — shown for clock-in (arrival proof) AND clock-out (departure proof). --}}
                <div style="margin-bottom:14px;">
                        <label for="attendance-photo" class="att-selfie-tile" @click="if (!window.matchMedia('(pointer:coarse)').matches) { $event.preventDefault(); openCam(); }">
                            <template x-if="!photoUrl">
                                <div style="display:flex;flex-direction:column;align-items:center;gap:5px;">
                                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="color:var(--muted);"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"></path><circle cx="12" cy="13" r="4"></circle></svg>
                                    <span style="font-size:13px;color:var(--ink);font-weight:600;" x-text="$store.ui.lang==='en' ? 'Add a selfie' : 'Tambah selfie'">Add a selfie</span>
                                    <span style="font-size:11px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'optional · proves you were here' : 'pilihan · bukti anda di sini'"></span>
                                </div>
                            </template>
                            <template x-if="photoUrl">
                                <div style="display:flex;align-items:center;gap:11px;">
                                    <img :src="photoUrl" alt="Clock-in selfie preview" style="width:48px;height:48px;object-fit:cover;border-radius:10px;border:1px solid var(--hairline-soft);" />
                                    <span style="font-size:12.5px;color:var(--ink);font-weight:500;" x-text="$store.ui.lang==='en' ? 'Selfie added · tap to retake' : 'Selfie ditambah · tekan untuk ambil semula'"></span>
                                </div>
                            </template>
                        </label>
                        <input type="file" id="attendance-photo" name="photo" accept="image/*" capture="user" x-ref="photo"
                               style="display:none;"
                               @change="photoUrl = $event.target.files[0] ? URL.createObjectURL($event.target.files[0]) : null; camNotice = ''" />
                        <div x-show="camNotice" x-cloak x-text="camNotice" style="color:var(--amber);font-size:11.5px;margin-top:7px;line-height:1.45;text-align:left;"></div>
                    </div>

                    {{-- In-page webcam (desktop, secure context). Phones use the native input above. --}}
                    <template x-teleport="body">
                    <div x-show="camOpen" x-cloak @keydown.escape.window="closeCam()" @click.self="closeCam()"
                         style="position:fixed;inset:0;z-index:60;background:rgba(0,0,0,.72);display:flex;align-items:center;justify-content:center;padding:20px;">
                        <div style="background:var(--surface,#fff);border-radius:16px;padding:18px;max-width:420px;width:100%;margin:auto;text-align:center;">
                            <div style="font-size:14px;font-weight:600;color:var(--ink);margin-bottom:12px;" x-text="$store.ui.lang==='en' ? 'Take a selfie' : 'Ambil selfie'">Take a selfie</div>
                            <video x-ref="cam" autoplay playsinline muted style="width:100%;border-radius:12px;background:#000;transform:scaleX(-1);"></video>
                            <div x-show="camError" x-cloak style="color:var(--red);font-size:12px;margin-top:8px;" x-text="camError"></div>
                            <div style="display:flex;gap:10px;margin-top:14px;">
                                <button type="button" class="uj-btn-ghost" style="flex:1;height:46px;font-weight:600;" @click="closeCam()" x-text="$store.ui.lang==='en' ? 'Cancel' : 'Batal'">Cancel</button>
                                <button type="button" class="uj-btn-primary" style="flex:1;height:46px;font-weight:600;" @click="capture()" x-text="$store.ui.lang==='en' ? 'Capture' : 'Tangkap'">Capture</button>
                            </div>
                        </div>
                    </div>
                    </template>
                    <canvas x-ref="canvas" style="display:none;"></canvas>

                {{-- Reason box — auto-revealed when GPS is outside the geofence or the clock-out is early. --}}
                <div x-show="justify" x-cloak style="margin-bottom:14px;text-align:left;">
                    <label style="display:block;font-size:12px;font-weight:500;color:var(--ink);margin-bottom:5px;"><span x-text="$store.ui.lang==='en' ? 'Reason (you are outside the expected location or leaving early)' : 'Sebab (anda di luar lokasi atau balik awal)'">Reason</span></label>
                    <textarea name="justification" x-ref="reason" x-model="reason" rows="2" maxlength="500"
                              :placeholder="$store.ui.lang==='en' ? 'e.g. Client meeting at HQ, approved by manager' : 'cth. Mesyuarat klien di HQ, diluluskan pengurus'"
                              style="width:100%;padding:10px 12px;border:1px solid var(--hairline);border-radius:8px;font-size:13px;color:var(--ink);outline:none;resize:vertical;"></textarea>
                    @error('justification')<div style="color:var(--red);font-size:11.5px;margin-top:4px;">{{ $message }}</div>@enderror
                </div>

                <button type="submit" class="uj-btn-primary att-action-btn" x-bind:disabled="submitting">
                    <span x-show="!submitting">{{ $ci ? 'Clock out' : 'Clock in' }}</span>
                    <span x-show="submitting" x-cloak style="display:inline-flex;align-items:center;gap:9px;">
                        <span class="att-spinner"></span>
                        <span x-text="$store.ui.lang==='en' ? '{{ $ci ? 'Clocking out…' : 'Clocking in…' }}' : '{{ $ci ? 'Sedang clock out…' : 'Sedang clock in…' }}'"></span>
                    </span>
                </button>
            </form>
        @endif

        @php $locLabel = $today?->location ?? ($site?->label ?? ($employee?->branch?->name ?? 'Workplace')); @endphp
        <div style="display:flex;align-items:center;justify-content:center;gap:7px;margin-top:14px;font-size:12px;color:var(--muted);">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
            <span>{{ $locLabel }} · <span x-text="$store.ui.lang==='en' ? 'location captured at check-in' : 'lokasi direkod semasa clock in'">location captured at check-in</span></span>
        </div>
    </div>

    {{-- Week history — collapses to a tap on phones, stays open on desktop. --}}
    <div class="uj-card att-week" x-data="{ open: window.innerWidth >= 641 }">
        <button type="button" class="uj-card-head att-week-head" @click="open = !open">
            <h3 class="uj-card-title"><span x-text="$store.ui.lang==='en' ? 'This week' : 'Minggu ini'">This week</span></h3>
            <span class="att-week-chevron" :class="{ 'att-open': open }" aria-hidden="true">›</span>
        </button>
        <div x-show="open" x-cloak>
            @forelse ($records as $r)
                @php
                    $rin = $r->clock_in ? \Illuminate\Support\Str::of($r->clock_in)->limit(5, '') : '—';
                    $rout = $r->clock_out ? \Illuminate\Support\Str::of($r->clock_out)->limit(5, '') : '—';
                    $worked = $r->worked_minutes ? intdiv($r->worked_minutes, 60).'h'.($r->worked_minutes % 60 ? ($r->worked_minutes % 60).'m' : '') : null;
                @endphp
                <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;padding:14px 20px;border-bottom:1px solid var(--hairline-soft);">
                    <div style="display:flex;align-items:center;gap:10px;min-width:0;">
                        @if ($r->photo_url)
                            <img src="{{ $r->photo_url }}" alt="Clock-in photo" title="Clock-in selfie" loading="lazy" style="width:30px;height:30px;object-fit:cover;border-radius:8px;border:1px solid var(--hairline-soft);flex-shrink:0;" />
                        @endif
                        @if ($r->clock_out_photo_url)
                            <img src="{{ $r->clock_out_photo_url }}" alt="Clock-out photo" title="Clock-out selfie" loading="lazy" style="width:30px;height:30px;object-fit:cover;border-radius:8px;border:1px solid var(--hairline-soft);flex-shrink:0;" />
                        @endif
                        <div style="min-width:0;">
                            <div style="font-size:13.5px;color:var(--ink);font-weight:500;">{{ $r->date->format('l, j M') }}</div>
                            <div style="font-size:11.5px;color:var(--muted);font-family:var(--font-mono);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                                {{ $rin }}–{{ $rout }}@if ($worked) · {{ $worked }}@endif · {{ $r->location ?? '—' }}
                            </div>
                        </div>
                    </div>
                    <div style="display:flex;align-items:center;gap:5px;flex-wrap:wrap;justify-content:flex-end;">
                        @foreach (($r->flags ?? []) as $f)
                            @php $fl = $flagLabel[$f] ?? [$f, $f]; @endphp
                            <span style="font-size:9.5px;font-weight:600;color:var(--red);background:var(--red-tint);padding:2px 6px;border-radius:9999px;white-space:nowrap;" x-text="$store.ui.lang==='en' ? @js($fl[0]) : @js($fl[1])">{{ $fl[0] }}</span>
                        @endforeach
                        <span style="display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:600;color:{{ $stColor[$r->status] }};"><span style="width:7px;height:7px;border-radius:50%;background:{{ $stColor[$r->status] }};"></span><span x-text="$store.ui.lang==='en' ? @js($stLabel[$r->status]) : @js($stLabelMs[$r->status])">{{ $stLabel[$r->status] }}</span></span>
                    </div>
                </div>
            @empty
                <div style="padding:28px 20px;text-align:center;">
                    <div style="font-size:13px;color:var(--ink);font-weight:500;margin-bottom:3px;"><span x-text="$store.ui.lang==='en' ? 'No attendance yet this week' : 'Belum ada kehadiran minggu ini'">No attendance yet this week</span></div>
                    <div style="font-size:12px;color:var(--muted);line-height:1.5;"><span x-text="$store.ui.lang==='en' ? 'Clock in on the left to start the week. Each day you clock in or out will be listed here.' : 'Clock in di sebelah kiri untuk mula minggu. Setiap hari anda clock in atau clock out akan disenaraikan di sini.'"></span></div>
                </div>
            @endforelse
        </div>
    </div>
</div>
@endsection
