@extends('layouts.app')

@php
    $stColor = ['on_time' => 'var(--success)', 'late' => 'var(--amber)', 'pending' => 'var(--muted)'];
    $stTone = ['on_time' => 'success', 'late' => 'amber', 'pending' => 'muted'];
    $stLabel = ['on_time' => 'On time', 'late' => 'Late', 'pending' => 'Pending'];
    $stLabelMs = ['on_time' => 'Tepat masa', 'late' => 'Lewat', 'pending' => 'Menunggu'];
    $flagLabel = [
        'late' => ['Late', 'Lewat'],
        'out_of_radius_in' => ['Off-site clock-in', 'Clock in luar lokasi'],
        'out_of_radius_out' => ['Off-site clock-out', 'Clock out luar lokasi'],
        'early_out' => ['Left early', 'Balik awal'],
        'short_hours' => ['Short hours', 'Jam kurang'],
        'no_location' => ['No location', 'Tiada lokasi'],
    ];
    $siteTypeLabel = [
        'office' => ['Office', 'Pejabat'],
        'client' => ['Client site', 'Lokasi klien'],
        'home' => ['Work from home', 'Kerja dari rumah'],
    ];

    $ci = $today?->clock_in ? \Illuminate\Support\Str::of($today->clock_in)->limit(5, '')->toString() : null;
    $co = $today?->clock_out ? \Illuminate\Support\Str::of($today->clock_out)->limit(5, '')->toString() : null;

    $workedMinsToday = $today?->worked_minutes;
    if ($workedMinsToday === null && $ci && $co) {
        $t1 = \Carbon\Carbon::parse($today->clock_in);
        $t2 = \Carbon\Carbon::parse($today->clock_out);
        $workedMinsToday = (int) $t1->diffInMinutes($t2);
    }
    $todayWorkedStr = $workedMinsToday !== null
        ? intdiv($workedMinsToday, 60).'h '.sprintf('%02dm', $workedMinsToday % 60)
        : '0h 00m';

    $wwH = intdiv($weekWorkedMinutes, 60);
    $wwM = sprintf('%02d', $weekWorkedMinutes % 60);
    $weekWorkedStr = "{$wwH}h {$wwM}m";

    $deltaSign = $weekBaselineDeltaMinutes >= 0 ? '+' : '−';
    $absDelta = abs($weekBaselineDeltaMinutes);
    $deltaH = intdiv($absDelta, 60);
    $deltaM = $absDelta % 60;
    $deltaStr = $deltaH > 0
        ? "{$deltaSign}{$deltaH}h " . sprintf('%02dm', $deltaM)
        : "{$deltaSign}{$deltaM}m";
@endphp

@section('screen')
@include('partials.guide', [
    'key'   => 'attendance',
    'en'  => [
        'title' => 'Attendance',
        'body'  => 'Clock in when you start and clock out when you finish. Your GPS is checked against where you are meant to be that day — your office, your client site, or your home. If you are outside that location, you can still clock but must give a reason. Clocking out early or off-site is also flagged.',
        'who'   => 'Everyone clocks their own time',
        'steps' => [
            'The banner shows where you are expected today and your hours.',
            'Tap "Clock in" and allow location. If your device genuinely cannot find your location, the screen offers to clock without it — that punch needs a reason and a selfie, and is flagged for your manager.',
            'Add a remark if there is something your manager should know about the day. It is optional.',
            'If you are outside the location, that same box turns into a required reason — say why (e.g. client meeting) — and a selfie is required as well.',
            'Clock out when you finish. Leaving before your end time or off-site needs a reason too.',
        ],
    ],
    'ms'  => [
        'title' => 'Kehadiran',
        'body'  => 'Clock in bila mula dan clock out bila habis. GPS anda disemak dengan tempat anda sepatutnya berada hari itu — pejabat, lokasi klien, atau rumah. Jika anda di luar lokasi itu, anda masih boleh clock tetapi perlu beri sebab. Clock out awal atau di luar lokasi juga ditanda.',
        'who'   => 'Semua orang rekod masa sendiri',
        'steps' => [
            'Sepanduk menunjukkan di mana anda sepatutnya hari ini dan waktu kerja anda.',
            'Tekan "Clock in" dan benarkan lokasi. Jika peranti anda benar-benar tidak dapat mencari lokasi, skrin menawarkan clock tanpa lokasi — rekod itu perlu sebab dan selfie, dan ditanda untuk pengurus anda.',
            'Tambah catatan jika ada perkara yang pengurus anda perlu tahu tentang hari itu. Ia pilihan.',
            'Jika anda di luar lokasi, kotak yang sama menjadi sebab wajib — nyatakan kenapa (cth. mesyuarat klien) — dan selfie juga wajib.',
            'Clock out bila habis. Balik sebelum waktu tamat atau di luar lokasi perlu sebab juga.',
        ],
    ],
])

<style>
    @keyframes att-spin { to { transform: rotate(360deg); } }
</style>

<div class="uj-at-wrap">
    <form method="post" action="{{ route('attendance.clock') }}" enctype="multipart/form-data" class="uj-at-shelf"
          x-data="{
              submitting: false,
              photoUrl: null,
              // Off-site punch was blocked for a missing selfie (client gate + server backstop).
              photoReq: {{ $errors->has('photo') ? 'true' : 'false' }},
              camOpen: false,
              stream: null,
              camError: '',
              camNotice: '',
              action: '{{ $ci ? 'out' : 'in' }}',
              serverJustify: {{ (session('attendance_justify') || $errors->has('justification')) ? 'true' : 'false' }},
              reason: @js(old('justification', '')),
              siteLat: {{ $site && $site->hasGeofence() ? $site->latitude : 'null' }},
              siteLng: {{ $site && $site->hasGeofence() ? $site->longitude : 'null' }},
              radius: {{ $site?->radiusM ?? 0 }},
              expectedEnd: '{{ $site?->workEnd ?? '' }}',
              workStart: '{{ $site?->workStart ?? '' }}',
              clockInTime: '{{ $ci ?? '' }}',
              geoError: '',
              geoDetail: '',
              // [type, label, lat, lng, radius] for every geofenced branch and client site.
              sites: @js($geofencedSites ?? []),
              assignedLabel: @js($site?->label ?? ''),
              matchedLabel: '',
              fenceStatus: 'wait',
              fenceDistM: null,
              noteOpen: {{ (session('attendance_justify') || $errors->has('justification') || old('justification', '')) ? 'true' : 'false' }},
              wallTime: @js(now()->format('H:i')),
              elapsedWorked: '',
              leadStr: '',
              leadWordEn: '',
              leadWordMs: '',
              init() {
                  this.tick();
                  setInterval(() => this.tick(), 1000);
                  if (!window.isSecureContext) {
                      // Warn on load: on an insecure origin no punch can ever succeed.
                      this.geoFail('insecure');
                  } else if (navigator.geolocation) {
                      navigator.geolocation.getCurrentPosition(
                          (pos) => {
                              const m = this.matchSite(pos.coords.latitude, pos.coords.longitude);
                              if (m) {
                                  this.matchedLabel = m.label === this.assignedLabel ? '' : m.label;
                                  this.fenceDistM = Math.round(m.d);
                                  this.fenceStatus = 'in';
                              } else if (this.siteLat !== null) {
                                  this.matchedLabel = '';
                                  this.fenceDistM = Math.round(this.distTo(pos.coords.latitude, pos.coords.longitude, this.siteLat, this.siteLng));
                                  this.fenceStatus = 'out';
                              } else {
                                  this.fenceStatus = 'none';
                              }
                          },
                          (err) => {
                              // Warn on load rather than letting the staff discover it on tap.
                              if (err.code === 1) { this.geoFail('denied', err); }
                              this.fenceStatus = 'none';
                          },
                          { enableHighAccuracy: true, timeout: 8000, maximumAge: 60000 }
                      );
                  } else {
                      this.fenceStatus = 'none';
                  }
                  this.$watch('isReq', (val) => {
                      if (!val && !this.reason.trim()) {
                          this.noteOpen = false;
                      }
                  });
              },
              tick() {
                  const d = new Date();
                  const nowMins = d.getHours() * 60 + d.getMinutes();
                  this.wallTime = String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');

                  if (this.clockInTime) {
                      const p = this.clockInTime.split(':');
                      const inMins = Number(p[0]) * 60 + Number(p[1]);
                      const worked = Math.max(0, nowMins - inMins);
                      const h = Math.floor(worked / 60);
                      const m = worked % 60;
                      this.elapsedWorked = h + 'h ' + String(m).padStart(2, '0') + 'm';
                  }

                  if (this.workStart) {
                      const p = this.workStart.split(':');
                      const startMins = Number(p[0]) * 60 + Number(p[1]);
                      const diff = startMins - nowMins;
                      const absDiff = Math.abs(diff);
                      const h = Math.floor(absDiff / 60);
                      const m = absDiff % 60;
                      this.leadStr = h > 0 ? (h + 'h ' + String(m).padStart(2, '0') + 'm') : (m + 'm');
                      if (diff >= 0) {
                          this.leadWordEn = 'early';
                          this.leadWordMs = 'awal';
                      } else {
                          this.leadWordEn = 'late';
                          this.leadWordMs = 'lewat';
                      }
                  }
              },
              get isReq() {
                  return (this.siteLat !== null && this.fenceStatus === 'out')
                      || (this.action === 'out' && this.earlyNow())
                      || this.serverJustify;
              },
              toggleNote() {
                  if (this.isReq) {
                      this.$refs.reason?.focus();
                      return;
                  }
                  this.noteOpen = !this.noteOpen;
                  if (this.noteOpen) {
                      this.$nextTick(() => this.$refs.reason?.focus());
                  }
              },
              fenceText(lang) {
                  if (this.fenceStatus === 'wait') {
                      return lang === 'en' ? 'checking location…' : 'menyemak lokasi…';
                  }
                  if (this.fenceStatus === 'in') {
                      const dStr = this.fenceDistM < 1000
                          ? this.fenceDistM + 'm'
                          : (this.fenceDistM / 1000).toFixed(1) + ' km';
                      if (this.matchedLabel) {
                          return lang === 'en'
                              ? ('At ' + this.matchedLabel + ' · ' + dStr)
                              : ('Di ' + this.matchedLabel + ' · ' + dStr);
                      }
                      return lang === 'en' ? ('You are inside · ' + dStr) : ('Anda di dalam · ' + dStr);
                  }
                  if (this.fenceStatus === 'out') {
                      const dStr = this.fenceDistM < 1000
                          ? this.fenceDistM + 'm away'
                          : (this.fenceDistM / 1000).toFixed(1) + ' km away';
                      const dStrMs = this.fenceDistM < 1000
                          ? this.fenceDistM + 'm jauh'
                          : (this.fenceDistM / 1000).toFixed(1) + ' km jauh';
                      return lang === 'en' ? ('Off-site · ' + dStr) : ('Di luar lokasi · ' + dStrMs);
                  }
                  return '';
              },
              distTo(lat, lng, tLat, tLng) {
                  const R = 6371000, toR = (x) => x * Math.PI / 180;
                  const dLa = toR(lat - tLat), dLo = toR(lng - tLng);
                  const a = Math.sin(dLa/2)**2 + Math.cos(toR(tLat))*Math.cos(toR(lat))*Math.sin(dLo/2)**2;
                  return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
              },
              // Mirror of ScheduleResolver::matchActualSite — nearest configured site the
              // fix lands inside, or null when it lands inside none of them.
              matchSite(lat, lng) {
                  let best = null;
                  for (const [, label, sLat, sLng, radius] of this.sites) {
                      const d = this.distTo(lat, lng, sLat, sLng);
                      if (d <= radius && (best === null || d < best.d)) { best = { label, d }; }
                  }
                  // A registered home is not in the tenant site list — check it separately.
                  if (best === null && this.siteLat !== null) {
                      const d = this.distTo(lat, lng, this.siteLat, this.siteLng);
                      if (d <= this.radius) { best = { label: this.assignedLabel, d }; }
                  }
                  return best;
              },
              earlyNow() {
                  if (!this.expectedEnd) return false;
                  const p = this.expectedEnd.split(':');
                  const now = new Date();
                  return (now.getHours()*60 + now.getMinutes()) < (Number(p[0])*60 + Number(p[1]));
              },
              proceed(lat, lng) {
                  const noFix = lat === null || lng === null;
                  const offSite = ! noFix && this.siteLat !== null && !this.matchSite(lat, lng);
                  let need = offSite || noFix;
                  if (this.action === 'out' && this.earlyNow()) need = true;
                  if (need && !this.reason.trim()) {
                      this.serverJustify = true;
                      this.noteOpen = true;
                      this.submitting = false;
                      this.$nextTick(() => this.$refs.reason?.focus());
                      return;
                  }
                  // Off-site and unlocatable punches must carry a selfie — mirrors ClockService.
                  if ((offSite || noFix) && !this.photoUrl) {
                      this.submitting = false;
                      this.photoReq = true;
                      this.triggerSelfie();
                      return;
                  }
                  // Empty inputs, not '0' — ConvertEmptyStringsToNull hands the controller a
                  // real null, which is what marks the punch as having no location at all.
                  this.$refs.lat.value = noFix ? '' : lat;
                  this.$refs.lng.value = noFix ? '' : lng;
                  this.$el.submit();
              },
              /**
               * Clock with no coordinates. Offered ONLY after the browser has actually failed
               * to produce a fix, never up front, so it cannot be used as a one-tap way around
               * the geofence. Costs a reason, a selfie and a permanent no_location flag.
               */
              submitWithoutLocation() {
                  if (this.submitting) return;
                  this.submitting = true;
                  this.proceed(null, null);
              },
              submit() {
                  if (this.submitting) return;
                  this.submitting = true;
                  this.geoError = '';
                  if (!navigator.geolocation) { this.geoFail('unsupported'); return; }
                  // Browsers refuse geolocation outside a secure context and report it as
                  // PERMISSION_DENIED — identical to a real denial, but no address-bar
                  // toggle can fix it. Name the actual problem instead of the wrong cure.
                  if (!window.isSecureContext) { this.geoFail('insecure'); return; }
                  this.locate(true);
              },
              /**
               * One position request. A desktop has no GPS chip, so enableHighAccuracy makes
               * the browser wait on a network lookup that often fails outright (Firefox on a
               * wired machine has no WiFi scan to geolocate from). Any failure other than a
               * refusal is therefore retried once at low accuracy before giving up.
               */
              locate(highAccuracy) {
                  navigator.geolocation.getCurrentPosition(
                      (pos) => this.proceed(pos.coords.latitude, pos.coords.longitude),
                      (err) => {
                          if (err.code === 1) { this.geoFail('denied', err); return; }
                          if (highAccuracy) { this.locate(false); return; }
                          this.geoFail(err.code === 3 ? 'timeout' : 'unavailable', err);
                      },
                      { enableHighAccuracy: highAccuracy, timeout: highAccuracy ? 8000 : 20000, maximumAge: 60000 }
                  );
              },
              /**
               * Chat apps (WhatsApp, Telegram, Facebook, Instagram) open links in an embedded
               * webview that usually cannot read location and denies with the same code 1 as a
               * real refusal. Detected by user agent: Android marks its webview `; wv)`, and an
               * iOS in-app view claims Mobile/AppleWebKit while omitting the Safari token.
               */
              inAppBrowser() {
                  const ua = navigator.userAgent;
                  if (/; wv\)|FBAN|FBAV|Instagram|Line\/|Twitter|MicroMessenger/i.test(ua)) { return true; }

                  return /iPhone|iPad/i.test(ua) && /AppleWebKit/i.test(ua) && !/Safari|CriOS|FxiOS|EdgiOS/i.test(ua);
              },
              geoFail(kind, err = null) {
                  this.submitting = false;
                  this.fenceStatus = 'none';
                  if (kind === 'denied' && this.inAppBrowser()) { kind = 'denied_webview'; }
                  const en = {
                      denied: 'Location is blocked for this site, so you cannot clock in or out. On a computer, tap the lock icon in the address bar and allow location. On a phone, also check that location is on for Chrome or Safari in your phone settings.',
                      denied_webview: 'You opened Amanahku inside another app (WhatsApp, Telegram, Facebook), and that window is not allowed to read your location — so clocking cannot work here. Tap the ⋮ or ↗ menu and choose “Open in browser”, then clock from Chrome or Safari.',
                      unavailable: 'Your browser allowed location but could not work out where you are. On a desktop there is no GPS, so the browser looks your position up over the network and that lookup failed. Check that location services are on for the whole computer (Windows Settings, Privacy, Location), then try again. Clocking from your phone always works.',
                      timeout: 'Your location took too long to arrive, so the punch was not sent. Try again — if it keeps timing out, clock from your phone instead.',
                      unsupported: 'This browser cannot share location, so clocking is not possible here. Use the app on your phone browser instead.',
                      insecure: 'This address (' + location.origin + ') is not secure, so the browser will not share your location and clocking is blocked. Open the app on its https:// address instead.',
                  };
                  const ms = {
                      denied: 'Lokasi disekat untuk laman ini, jadi anda tidak boleh clock in atau clock out. Pada komputer, tekan ikon kunci di bar alamat dan benarkan lokasi. Pada telefon, semak juga lokasi dihidupkan untuk Chrome atau Safari dalam tetapan telefon.',
                      denied_webview: 'Anda membuka Amanahku di dalam aplikasi lain (WhatsApp, Telegram, Facebook), dan tetingkap itu tidak dibenarkan membaca lokasi anda — jadi clock tidak boleh dibuat di sini. Tekan menu ⋮ atau ↗ dan pilih “Buka dalam pelayar”, kemudian clock dari Chrome atau Safari.',
                      unavailable: 'Pelayar anda membenarkan lokasi tetapi tidak dapat mengetahui di mana anda berada. Pada komputer tiada GPS, jadi pelayar mencari kedudukan melalui rangkaian dan carian itu gagal. Pastikan servis lokasi dihidupkan untuk seluruh komputer (Windows Settings, Privacy, Location), kemudian cuba lagi. Clock dari telefon sentiasa berfungsi.',
                      timeout: 'Lokasi anda terlalu lama sampai, jadi rekod tidak dihantar. Cuba lagi — jika masih gagal, clock dari telefon anda.',
                      unsupported: 'Pelayar ini tidak boleh berkongsi lokasi, jadi clock tidak boleh dibuat di sini. Guna pelayar telefon anda.',
                      insecure: 'Alamat ini (' + location.origin + ') tidak selamat, jadi pelayar tidak akan berkongsi lokasi anda dan clock disekat. Buka aplikasi pada alamat https:// sebaliknya.',
                  };
                  this.geoError = this.$store.ui.lang === 'en' ? en[kind] : ms[kind];
                  // Support cannot reproduce a staff member's browser, so carry the browser's
                  // own verdict in the message. Without it every failure looks the same and
                  // the guesswork starts.
                  this.geoDetail = err
                      ? 'Browser reported: ' + kind + ' (code ' + err.code + (err.message ? ' — ' + err.message : '') + ')'
                      : 'Browser reported: ' + kind;
              },
              triggerSelfie() {
                  if (!window.matchMedia('(pointer:coarse)').matches) {
                      this.openCam();
                  } else {
                      this.$refs.photo.click();
                  }
              },
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
                      this.photoReq = false;
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
        <input type="hidden" name="action" value="{{ $ci && !$co ? 'out' : 'in' }}" />
        <input type="hidden" name="latitude" x-ref="lat" />
        <input type="hidden" name="longitude" x-ref="lng" />
        <input type="file" id="attendance-photo" name="photo" accept="image/*" capture="user" x-ref="photo"
               style="display:none;"
               @change="photoUrl = $event.target.files[0] ? URL.createObjectURL($event.target.files[0]) : null; camNotice = ''; if (photoUrl) photoReq = false;" />

        <div class="uj-at-shelf-top">
            <div style="min-width:0;">
                <div class="uj-at-kicker">Attendance · {{ now()->format('l j F') }}</div>

                <div class="uj-at-figrow">
                    @if ($co)
                        <div class="uj-at-fig">{{ $todayWorkedStr }}</div>
                        <div class="uj-at-figsub">
                            <span x-show="$store.ui.lang==='en'">worked · <b>{{ $ci }} – {{ $co }}</b> · done for today</span>
                            <span x-show="$store.ui.lang!=='en'" x-cloak>bekerja · <b>{{ $ci }} – {{ $co }}</b> · selesai untuk hari ini</span>
                        </div>
                    @elseif ($ci)
                        <div class="uj-at-fig" x-text="elapsedWorked">{{ $todayWorkedStr }}</div>
                        <div class="uj-at-figsub">
                            <span x-show="$store.ui.lang==='en'">worked · in since <b>{{ $ci }}</b>@if ($site?->workEnd), ends <b>{{ \Illuminate\Support\Str::of($site->workEnd)->limit(5, '') }}</b>@endif</span>
                            <span x-show="$store.ui.lang!=='en'" x-cloak>bekerja · clock in sejak <b>{{ $ci }}</b>@if ($site?->workEnd), tamat <b>{{ \Illuminate\Support\Str::of($site->workEnd)->limit(5, '') }}</b>@endif</span>
                        </div>
                    @else
                        <div class="uj-at-fig" x-text="wallTime">{{ now()->format('H:i') }}</div>
                        <div class="uj-at-figsub">
                            @if ($site?->workStart)
                                <span x-show="$store.ui.lang==='en'">shift starts <b>{{ \Illuminate\Support\Str::of($site->workStart)->limit(5, '') }}</b> · you are <b x-text="leadStr"></b> <span x-text="leadWordEn"></span></span>
                                <span x-show="$store.ui.lang!=='en'" x-cloak>shift bermula <b>{{ \Illuminate\Support\Str::of($site->workStart)->limit(5, '') }}</b> · anda <span x-text="leadWordMs"></span> <b x-text="leadStr"></b></span>
                            @else
                                <span x-show="$store.ui.lang==='en'">Not clocked in yet</span>
                                <span x-show="$store.ui.lang!=='en'" x-cloak>Belum clock in</span>
                            @endif
                        </div>
                    @endif
                </div>

                @if ($site)
                    @php $sType = $siteTypeLabel[$site->type] ?? ['Workplace', 'Tempat kerja']; @endphp
                    <div class="uj-at-where">
                        <span>
                            <span x-text="$store.ui.lang==='en' ? @js($sType[0]) : @js($sType[1])">{{ $sType[0] }}</span>
                            · {{ $site->label }}
                            @if ($site->workStart && $site->workEnd) · {{ \Illuminate\Support\Str::of($site->workStart)->limit(5, '') }}–{{ \Illuminate\Support\Str::of($site->workEnd)->limit(5, '') }}@endif
                            @if ($site->hasGeofence()) · <span x-text="$store.ui.lang==='en' ? 'within {{ $site->radiusM }}m' : 'dalam {{ $site->radiusM }}m'">within {{ $site->radiusM }}m</span>@endif
                            @if ($site->needsHomeCapture) · <span style="color:var(--info);" x-text="$store.ui.lang==='en' ? 'home registers on this clock-in' : 'rumah didaftar pada clock in ini'">home registers on this clock-in</span>@endif
                        </span>

                        {{-- Shown even when the assigned site has no geofence: the fix may
                             still land inside another configured branch or client site. --}}
                        <span class="uj-at-fence" x-show="fenceStatus !== 'none' && fenceStatus !== 'wait'" :data-f="fenceStatus" x-cloak>
                            <i></i>
                            <span x-text="fenceText($store.ui.lang)"></span>
                        </span>
                    </div>
                @endif
            </div>

            <div class="uj-at-acts">
                <button type="button" class="uj-at-ghost" data-selfie :data-on="photoUrl ? true : false" @click="triggerSelfie()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                    <span x-text="photoUrl ? ($store.ui.lang==='en' ? 'Selfie attached' : 'Selfie dilampirkan') : ($store.ui.lang==='en' ? 'Selfie' : 'Selfie')">Selfie</span>
                </button>
                <button type="button" class="uj-at-ghost" data-notebtn :data-on="(noteOpen || isReq) ? true : false" @click="toggleNote()">
                    <span x-text="$store.ui.lang==='en' ? 'Add a remark' : 'Tambah catatan'">Add a remark</span>
                </button>
                <button type="submit" class="uj-at-go" @if ($co) disabled @else :disabled="submitting" @endif>
                    @if ($co)
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 12l5 5L20 6"/></svg>
                        <span x-text="$store.ui.lang==='en' ? 'Shift complete' : 'Shift selesai'">Shift complete</span>
                    @else
                        <template x-if="!submitting">
                            <span x-text="$store.ui.lang==='en' ? @js($ci ? 'Clock out' : 'Clock in') : @js($ci ? 'Clock out' : 'Clock in')">{{ $ci ? 'Clock out' : 'Clock in' }}</span>
                        </template>
                        <template x-if="submitting">
                            <span style="display:inline-flex;align-items:center;gap:8px;">
                                <span class="att-spinner" style="width:16px;height:16px;border:2px solid currentColor;border-right-color:transparent;border-radius:50%;animation:att-spin .6s linear infinite;"></span>
                                <span x-text="$store.ui.lang==='en' ? @js($ci ? 'Clocking out…' : 'Clocking in…') : @js($ci ? 'Sedang clock out…' : 'Sedang clock in…')"></span>
                            </span>
                        </template>
                    @endif
                </button>
            </div>
        </div>

        <div class="uj-at-note" :data-open="(noteOpen || isReq) ? true : false" :data-req="isReq ? true : false">
            <div>
                <label for="attendance-remarks" data-note-lbl>
                    <span x-show="!isReq" x-text="$store.ui.lang==='en' ? 'Remarks — optional, your manager sees this with the punch' : 'Catatan — pilihan, pengurus anda melihat ini bersama rekod'">Remarks — optional, your manager sees this with the punch</span>
                    <span x-show="isReq" x-cloak style="color:var(--red-active);"
                          x-text="$store.ui.lang==='en' ? 'Reason required — you are outside the expected location or leaving early' : 'Sebab diperlukan — anda di luar lokasi atau balik awal'">Reason required — you are outside the expected location or leaving early</span>
                </label>
                <textarea name="justification" id="attendance-remarks" x-ref="reason" x-model="reason" rows="2" maxlength="500"
                          :placeholder="isReq
                              ? ($store.ui.lang==='en' ? 'e.g. Client meeting at HQ, approved by manager' : 'cth. Mesyuarat klien di HQ, diluluskan pengurus')
                              : ($store.ui.lang==='en' ? 'Anything your manager should know about today' : 'Apa-apa yang pengurus anda perlu tahu tentang hari ini')"></textarea>
                @error('justification')<div style="color:var(--red);font-size:11.5px;margin-top:4px;">{{ $message }}</div>@enderror
            </div>
        </div>

        <div class="uj-at-chips">
            <div class="uj-at-chip">
                <b>{{ $weekWorkedStr }}</b>
                <span x-text="$store.ui.lang==='en' ? 'Worked this week' : 'Jam minggu ini'">Worked this week</span>
            </div>
            <div class="uj-at-chip" data-tone="{{ $weekBaselineDeltaMinutes >= 0 ? 'ok' : 'amber' }}">
                <b>{{ $deltaStr }}</b>
                <span x-text="$store.ui.lang==='en' ? 'Over baseline' : 'Lebih baseline'">Over baseline</span>
            </div>
            <div class="uj-at-chip"@if ($lateThisMonth > 0) data-tone="amber"@endif>
                <b>{{ $lateThisMonth }}</b>
                <span x-text="$store.ui.lang==='en' ? 'Late this month' : 'Lewat bulan ini'">Late this month</span>
            </div>
            <div class="uj-at-chip">
                <b>{{ $offSiteThisMonth }}</b>
                <span x-text="$store.ui.lang==='en' ? 'Off-site this month' : 'Luar lokasi bulan ini'">Off-site this month</span>
            </div>
        </div>

        <div x-show="geoError" x-cloak style="color:var(--red);font-size:11.5px;margin-top:7px;line-height:1.45;text-align:left;">
            <div x-text="geoError"></div>
            <div x-text="geoDetail" style="opacity:.65;margin-top:3px;font-family:ui-monospace,monospace;font-size:10.5px;"></div>
            @if (! $co)
                {{-- Last resort, and deliberately not a shortcut: it appears only once the
                     browser has failed, and the punch still costs a reason and a selfie. --}}
                <button type="button" class="uj-at-ghost" style="margin-top:9px;"
                        @click="submitWithoutLocation()" :disabled="submitting"
                        x-text="$store.ui.lang==='en'
                            ? @js($ci ? 'Clock out without location — needs a reason and a selfie' : 'Clock in without location — needs a reason and a selfie')
                            : @js($ci ? 'Clock out tanpa lokasi — perlu sebab dan selfie' : 'Clock in tanpa lokasi — perlu sebab dan selfie')"></button>
            @endif
        </div>
        @error('latitude')<div style="color:var(--red);font-size:11.5px;margin-top:7px;line-height:1.45;text-align:left;">{{ $message }}</div>@enderror

        <div x-show="photoReq" x-cloak style="color:var(--red);font-size:11.5px;margin-top:7px;line-height:1.45;text-align:left;"
             x-text="$store.ui.lang==='en'
                 ? 'You are outside the expected location, so a selfie is required. Take one, then clock again.'
                 : 'Anda di luar lokasi, jadi selfie diperlukan. Ambil satu, kemudian clock semula.'"></div>
        @error('photo')<div style="color:var(--red);font-size:11.5px;margin-top:7px;line-height:1.45;text-align:left;">{{ $message }}</div>@enderror

        <div x-show="camNotice" x-cloak x-text="camNotice" style="color:var(--amber);font-size:11.5px;margin-top:7px;line-height:1.45;text-align:left;"></div>

        {{-- In-page webcam modal --}}
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

        {{-- Mobile action dock --}}
        <div class="uj-at-dock">
            <button type="button" class="uj-at-dock-sq" :data-on="photoUrl ? true : false" @click="triggerSelfie()">
                <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
            </button>
            <button type="submit" class="uj-at-dock-go" @if ($co) disabled @else :disabled="submitting" @endif>
                @if ($co)
                    <span x-text="$store.ui.lang==='en' ? 'Shift complete ✓' : 'Shift selesai ✓'">Shift complete ✓</span>
                @else
                    <template x-if="!submitting">
                        <span x-text="$store.ui.lang==='en' ? @js($ci ? 'Clock out' : 'Clock in') : @js($ci ? 'Clock out' : 'Clock in')">{{ $ci ? 'Clock out' : 'Clock in' }}</span>
                    </template>
                    <template x-if="submitting">
                        <span style="display:inline-flex;align-items:center;gap:8px;">
                            <span class="att-spinner" style="width:16px;height:16px;border:2px solid currentColor;border-right-color:transparent;border-radius:50%;animation:att-spin .6s linear infinite;"></span>
                            <span x-text="$store.ui.lang==='en' ? @js($ci ? 'Clocking out…' : 'Clocking in…') : @js($ci ? 'Sedang clock out…' : 'Sedang clock in…')"></span>
                        </span>
                    </template>
                @endif
            </button>
        </div>
    </form>

    {{-- The week --}}
    <section class="uj-at-list">
        <div class="uj-at-listhd">
            <h2>
                <span x-text="$store.ui.lang==='en' ? 'This week' : 'Minggu ini'">This week</span>
                <span class="sum">· {{ $weekWorkedStr }} <span x-text="$store.ui.lang==='en' ? 'over {{ count($weekRecords) }} {{ \Illuminate\Support\Str::plural('day', count($weekRecords)) }}' : 'dalam {{ count($weekRecords) }} hari'">over {{ count($weekRecords) }} {{ \Illuminate\Support\Str::plural('day', count($weekRecords)) }}</span></span>
            </h2>
            @if ($qaCanSeeAll ?? false)
                <a href="{{ route('app.screen', 'attendance-report') }}" class="uj-at-seeall"
                   x-text="$store.ui.lang==='en' ? 'All staff attendance →' : 'Kehadiran semua staf →'">All staff attendance →</a>
            @endif
        </div>

        @forelse ($weekRecords as $r)
            @include('partials.attendance-day', ['r' => $r])
        @empty
            <div class="uj-at-empty">
                <h3 x-text="$store.ui.lang==='en' ? 'No attendance recorded yet this week' : 'Belum ada kehadiran minggu ini'">No attendance recorded yet this week</h3>
                <p x-text="$store.ui.lang==='en' ? 'Clock in above to start the week. Each day you clock in or out will be listed here with your punch time, where it landed against the geofence, and any remark attached.' : 'Clock in di atas untuk memulakan minggu. Setiap hari anda clock in atau clock out akan disenaraikan di sini bersama masa clock, lokasi geofence, dan sebarang catatan.'">Clock in above to start the week. Each day you clock in or out will be listed here with your punch time, where it landed against the geofence, and any remark attached.</p>
            </div>
        @endforelse

        @if ($earlierRecords->isNotEmpty())
            <div x-data="{ open: false }" style="margin-top:18px;text-align:center;">
                <button type="button" class="uj-at-seeall" @click="open = !open" :aria-expanded="open ? 'true' : 'false'">
                    <span x-show="!open" x-text="$store.ui.lang==='en' ? 'Earlier days →' : 'Hari terdahulu →'">Earlier days →</span>
                    <span x-show="open" x-cloak x-text="$store.ui.lang==='en' ? 'Hide earlier days' : 'Sembunyikan hari terdahulu'">Hide earlier days</span>
                </button>

                <div x-show="open" x-cloak style="margin-top:12px;text-align:left;">
                    @foreach ($earlierRecords as $r)
                        @include('partials.attendance-day', ['r' => $r])
                    @endforeach
                </div>
            </div>
        @endif
    </section>
</div>
@endsection
