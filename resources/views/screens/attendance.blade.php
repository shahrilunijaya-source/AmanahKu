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
@endphp

@section('screen')
@include('partials.guide', [
    'key'   => 'attendance',
    'en'  => [
        'title' => 'Attendance',
        'body'  => 'Pick your working mode, then clock in when you start and clock out when you finish. A selfie is required every time and the camera opens on its own. Your GPS is checked against where you are meant to be that day, which is your office, your client site, or your home. On a site visit you say where you are going instead, and there is no off-site flag. Clocking in late or out early still needs a reason.',
        'who'   => 'Everyone clocks their own time',
        'steps' => [
            'The banner shows where you are expected today and your hours.',
            'Pick your working mode first. Leave it on "Office / Home" for an ordinary day, or tap "Site visit" if you are going to a customer.',
            'Tap "Clock in" and allow location. The camera opens to take your selfie, which is required for every clock in and clock out, no exceptions.',
            'On a site visit the same window asks where you are going. Say the place, for example "Customer ABC, Shah Alam".',
            'If you are late, off-site, or your device cannot find your location, that window asks for a reason instead. Your manager sees it with the punch.',
            'Clock out when you finish, with another selfie. Leaving before your end time needs a reason too.',
        ],
    ],
    'ms'  => [
        'title' => 'Kehadiran',
        'body'  => 'Pilih mod kerja anda, kemudian clock in bila mula dan clock out bila habis. Selfie diperlukan setiap kali dan kamera terbuka sendiri. GPS anda disemak dengan tempat anda sepatutnya berada hari itu, iaitu pejabat, lokasi klien, atau rumah. Untuk lawatan tapak anda nyatakan ke mana anda pergi, dan tiada tanda luar lokasi. Clock in lewat atau clock out awal tetap perlu sebab.',
        'who'   => 'Semua orang rekod masa sendiri',
        'steps' => [
            'Sepanduk menunjukkan di mana anda sepatutnya hari ini dan waktu kerja anda.',
            'Pilih mod kerja anda dahulu. Biarkan pada "Pejabat / Rumah" untuk hari biasa, atau tekan "Lawatan tapak" jika anda ke tempat pelanggan.',
            'Tekan "Clock in" dan benarkan lokasi. Kamera terbuka untuk ambil selfie anda, yang diperlukan untuk setiap clock in dan clock out, tiada pengecualian.',
            'Untuk lawatan tapak, tetingkap yang sama bertanya ke mana anda pergi. Nyatakan tempatnya, contohnya "Customer ABC, Shah Alam".',
            'Jika anda lewat, di luar lokasi, atau peranti anda tidak dapat mencari lokasi, tetingkap itu meminta sebab. Pengurus anda melihatnya bersama rekod.',
            'Clock out bila habis, dengan satu lagi selfie. Balik sebelum waktu tamat perlu sebab juga.',
        ],
    ],
])

<style>
    @keyframes att-spin { to { transform: rotate(360deg); } }
</style>

<div class="uj-at-wrap">
    <form id="attendance-clock-form" method="post" action="{{ route('attendance.clock') }}" enctype="multipart/form-data" class="uj-at-shelf"
          x-ref="form"
          x-data="{
              submitting: false,
              photoUrl: null,
              // Off-site punch was blocked for a missing selfie (client gate + server backstop).
              // The flash, not the error bag: a rejected photo (too large, wrong format) uses
              // the same `photo` error key and is not a demand for one.
              photoReq: {{ session('attendance_photo') ? 'true' : 'false' }},
              camOpen: false,
              // The sheet is gating a punch (selfie, and maybe a reason too) rather than
              // just attaching a photo someone asked for.
              sheetNeed: false,
              // Whether THIS gated punch also needs a typed reason — off-site, no-location,
              // late-in or early-out. A selfie is required every time; the reason is not.
              sheetReasonNeed: false,
              // Which of the four reasons above, driving the sheet's title/copy/label — null
              // when sheetReasonNeed is false (a plain punch, gated on the selfie alone).
              sheetReasonKind: null,
              // Coordinates the sheet's punch is for. Both null = a no-location punch.
              sheetFix: null,
              stream: null,
              camError: '',
              camNotice: '',
              {{-- Same expression as the hidden action field below: one rule, one value. --}}
              action: '{{ $ci && !$co ? 'out' : 'in' }}',
              // Set once the staff member has actually tried to punch. The clock-without-
              // location fallback keys off this, so a permission the browser refused at page
              // load cannot hand anyone a from-anywhere punch they never even attempted.
              attempted: false,
              // Last good fix, kept briefly so a punch interrupted for a reason or a selfie
              // resumes on the coordinates it already had instead of waking the GPS again.
              lastFix: null,
              // Consecutive network-provider failures (timeout OR unavailable — see geoFail)
              // on a real punch attempt. A second in a row means the lookup itself is stuck
              // (VPN, firewall, ad blocker), not a fluke — 'try again' is dead advice at that
              // point, so the message changes.
              timeoutStreak: 0,
              serverJustify: {{ (session('attendance_justify') || $errors->has('justification')) ? 'true' : 'false' }},
              // Declared before the punch, posted with it. Seeded from the open record so a
              // day declared a site visit this morning is still declared at clock-out, and
              // still switchable for someone who ended up somewhere else.
              workMode: @js(old('work_mode', $today?->work_mode === 'site_visit' && ! $co ? 'site_visit' : 'office_home')),
              reason: @js(old('justification', '')),
              siteLat: {{ $site && $site->hasGeofence() ? $site->latitude : 'null' }},
              siteLng: {{ $site && $site->hasGeofence() ? $site->longitude : 'null' }},
              radius: {{ $site?->radiusM ?? 0 }},
              expectedStart: '{{ $site?->workStart ?? '' }}',
              graceMin: {{ $lateGraceMinutes ?? 0 }},
              expectedEnd: '{{ $site?->workEnd ?? '' }}',
              clockInTime: '{{ $ci ?? '' }}',
              {{-- The open punch of an overnight shift is dated yesterday, so its clock-in
                   time is *later* in the day than the current wall clock. Without this the
                   elapsed ticker read 0h 00m for the whole night. --}}
              clockInWasYesterday: {{ $today && ! $today->clock_out && ! $today->date->isToday() ? 'true' : 'false' }},
              // One-shot: true only on the reload right after a successful punch, driving the
              // pulse on the status card / dock button. Cleared in init() so it never re-fires
              // on a later plain refresh.
              justPunched: {{ session('clock_ok') ? 'true' : 'false' }},
              geoError: '',
              geoShort: '',
              geoDetail: '',
              // [type, label, lat, lng, radius] for every geofenced branch and client site.
              sites: @js($geofencedSites ?? []),
              assignedLabel: @js($site?->label ?? ''),
              matchedLabel: '',
              fenceStatus: 'wait',
              fenceDistM: null,
              wallTime: @js(now()->format('H:i')),
              elapsedWorked: '',
              init() {
                  if (this.justPunched) {
                      setTimeout(() => { this.justPunched = false; }, 1800);
                  }
                  // The server refused the last punch for a missing reason (needs_justification).
                  // The drawer that used to reopen for this is gone, the sheet is now the only
                  // place a reason lives, so it has to reopen itself or the refusal is invisible.
                  if (this.serverJustify) {
                      this.openPunchSheet(null, null, true, this.workMode === 'site_visit' ? 'site_visit' : 'off_site');
                  }
                  this.tick();
                  setInterval(() => this.tick(), 1000);
                  if (!window.isSecureContext) {
                      // Warn on load: on an insecure origin no punch can ever succeed.
                      this.geoFail('insecure');
                  } else if (navigator.geolocation) {
                      this.bestFix(
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
                          (kind, err) => {
                              // Warn on load rather than letting the staff discover it on tap.
                              if (kind === 'denied') { this.geoFail('denied', err); }
                              this.fenceStatus = 'none';
                          },
                          true,
                          12000
                      );
                  } else {
                      this.fenceStatus = 'none';
                  }
              },
              tick() {
                  const d = new Date();
                  const nowMins = d.getHours() * 60 + d.getMinutes();
                  this.wallTime = String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');

                  if (this.clockInTime) {
                      const p = this.clockInTime.split(':');
                      const inMins = Number(p[0]) * 60 + Number(p[1]);
                      const worked = Math.max(0, nowMins - inMins + (this.clockInWasYesterday ? 1440 : 0));
                      const h = Math.floor(worked / 60);
                      const m = worked % 60;
                      this.elapsedWorked = h + 'h ' + String(m).padStart(2, '0') + 'm';
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
              // Mirror of ClockService::isLate for the single-day case (an overnight shift,
              // work_end before work_start, is not covered — see ReminderTargets ponytail
              // note), close enough to save the employee a failed submit. The server gate is
              // still the authority: it compares to the second, and a device with a wrong
              // clock is caught there.
              lateNow() {
                  if (!this.expectedStart) return false;
                  const p = this.expectedStart.split(':');
                  const now = new Date();
                  return (now.getHours()*60 + now.getMinutes()) >= (Number(p[0])*60 + Number(p[1]) + this.graceMin);
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
                  // Remember the punch before either gate below sends it back for a reason or
                  // a selfie: the staff member has not moved in the seconds it takes to type,
                  // so the next attempt should not pay for the fix a second time. lat/lng stay
                  // null for a deliberate no-location punch, which is why resuming reuses them
                  // but submit() will not (see both guards).
                  this.lastFix = { lat: noFix ? null : lat, lng: noFix ? null : lng, at: Date.now() };
                  // A selfie is mandatory for every punch — mirrors ClockService. A typed
                  // reason is still only for off-site, unlocatable, late-in or early-out — in
                  // that priority, matching the order ClockService itself checks them in.
                  // A declared site visit owes a destination, and outranks 'off_site' because
                  // the fence is no longer what is being asked about. It does NOT outrank
                  // 'no_location', which mirrors ClockService's own gate order: an unlocatable
                  // punch is reported as unlocatable whatever the employee declared.
                  let reasonKind = noFix
                      ? 'no_location'
                      : (this.workMode === 'site_visit' ? 'site_visit' : (offSite ? 'off_site' : null));
                  if (! reasonKind && this.action === 'out' && this.earlyNow()) { reasonKind = 'early'; }
                  if (! reasonKind && this.action === 'in' && this.lateNow()) { reasonKind = 'late'; }
                  const needReason = reasonKind !== null;
                  // Reason and selfie gate together, once, when both are missing — so a punch
                  // that needs both costs one sheet, not a reason drawer then a camera sheet.
                  if (!this.photoUrl || (needReason && !this.reason.trim())) {
                      this.submitting = false;
                      this.openPunchSheet(noFix ? null : lat, noFix ? null : lng, needReason, reasonKind);
                      return;
                  }
                  // Empty inputs, not '0' — ConvertEmptyStringsToNull hands the controller a
                  // real null, which is what marks the punch as having no location at all.
                  this.$refs.lat.value = noFix ? '' : lat;
                  this.$refs.lng.value = noFix ? '' : lng;
                  // Named ref, not $el: proceed() also runs from the selfie input's change
                  // handler and from the camera modal, where $el is that element rather than
                  // the form, and $el.submit() is then not a function at all.
                  this.$refs.form.submit();
              },
              /**
               * Clock with no coordinates. Offered ONLY after the browser has actually failed
               * to produce a fix, never up front, so it cannot be used as a one-tap way around
               * the geofence. Costs a reason, a selfie and a permanent no_location flag.
               */
              submitWithoutLocation() {
                  if (this.submitting) return;
                  this.openPunchSheet(null, null, true, 'no_location');
              },
              submit() {
                  if (this.submitting) return;
                  this.submitting = true;
                  this.attempted = true;
                  this.geoError = '';
                  if (!navigator.geolocation) { this.geoFail('unsupported'); return; }
                  // Browsers refuse geolocation outside a secure context and report it as
                  // PERMISSION_DENIED — identical to a real denial, but no address-bar
                  // toggle can fix it. Name the actual problem instead of the wrong cure.
                  if (!window.isSecureContext) { this.geoFail('insecure'); return; }
                  // An off-site punch is sent back twice — once for the reason, once for the
                  // selfie — and used to re-run the whole GPS acquisition on each retry, so
                  // one punch cost three fixes and up to half a minute of waiting. Reuse the
                  // fix while it is still fresh; a stale one falls through to a real locate().
                  // Only a real fix is reusable. A no-location punch is never resumed from
                  // here, or the ordinary Clock in button would quietly become the fallback.
                  if (this.freshFix() && this.lastFix.lat !== null) {
                      this.proceed(this.lastFix.lat, this.lastFix.lng);
                      return;
                  }
                  this.locate(true);
              },
              freshFix() {
                  return this.lastFix !== null && Date.now() - this.lastFix.at < 60000;
              },
              /**
               * Location has actually failed on a real attempt, so the only punch still open
               * is the one that carries no coordinates. The single punch button changes to it
               * rather than a second button appearing beside it: two buttons both reading
               * 'Clock in', one of them dead, is what made staff tap twice and believe they
               * had clocked in twice.
               */
              get noLoc() {
                  return this.attempted && this.geoError !== '';
              },
              /** Word on the punch button. Follows noLoc so the label always names what a tap does. */
              goLabel(lang) {
                  const out = this.action === 'out';
                  if (this.noLoc) {
                      return lang === 'en'
                          ? (out ? 'Clock out without location' : 'Clock in without location')
                          : (out ? 'Clock out tanpa lokasi' : 'Clock in tanpa lokasi');
                  }
                  return out ? 'Clock out' : 'Clock in';
              },
              /**
               * A selfie attached to a punch that was already sent back for one finishes that
               * punch. Without this the staff member supplies exactly what was asked for and
               * still has to find the button again — the third tap of a three-tap off-site
               * clock-in. Only ever fires after a real attempt left a fresh fix behind.
               */
              resumeAfterSelfie() {
                  if (!this.attempted || !this.freshFix()) { return; }
                  if (this.submitting) { return; }
                  this.submitting = true;
                  // Resume the punch that asked for the selfie, on its own coordinates —
                  // including the no-location punch, whose selfie this may well be.
                  this.proceed(this.lastFix.lat, this.lastFix.lng);
              },
              /**
               * The best fix the browser can produce inside the deadline, not the first one.
               * A cold GPS answers with a coarse network fix — accuracy often over a kilometre —
               * and only refines to real satellite precision seconds later. Taking that first
               * fix reads a staff member standing inside a 200m fence as off-site, which then
               * costs them a reason, a selfie and an out_of_radius flag on the record. Watching
               * until the fix is precise enough to judge the fence removes the guesswork; a
               * cached fix is refused outright (maximumAge 0) because a stale coarse one is
               * exactly the wrong answer.
               */
              bestFix(onFix, onFail, highAccuracy, deadlineMs) {
                  let best = null, lastErr = null, settled = false, watchId = null, timer = null;
                  const finish = (failKind, err) => {
                      if (settled) { return; }
                      settled = true;
                      clearTimeout(timer);
                      if (watchId !== null) { navigator.geolocation.clearWatch(watchId); }
                      if (!failKind && best) { onFix(best); return; }
                      onFail(failKind || (lastErr && lastErr.code !== 3 ? 'unavailable' : 'timeout'), err || lastErr);
                  };
                  // Good enough to call the fence either way. Floored at 50m because a 20m fence
                  // would otherwise wait out the whole deadline for precision no phone delivers.
                  const goodEnough = Math.max(this.radius || 100, 50);
                  timer = setTimeout(() => finish(null, null), deadlineMs);
                  watchId = navigator.geolocation.watchPosition(
                      (pos) => {
                          if (!best || pos.coords.accuracy < best.coords.accuracy) { best = pos; }
                          if (best.coords.accuracy <= goodEnough) { finish(null, null); }
                      },
                      (err) => {
                          lastErr = err;
                          // A refusal can never improve. Anything else may still be followed
                          // by a fix, so let the deadline decide.
                          if (err.code === 1) { finish('denied', err); }
                      },
                      { enableHighAccuracy: highAccuracy, timeout: deadlineMs, maximumAge: 0 }
                  );
                  // A synchronous error callback settles before watchId is assigned above.
                  if (settled) { navigator.geolocation.clearWatch(watchId); }
              },
              /**
               * A desktop has no GPS chip, so enableHighAccuracy makes the browser wait on a
               * network lookup that often fails outright (Firefox on a wired machine has no
               * WiFi scan to geolocate from). Any failure other than a refusal is therefore
               * retried once at low accuracy before giving up.
               */
              locate(highAccuracy) {
                  this.bestFix(
                      (pos) => { this.timeoutStreak = 0; this.proceed(pos.coords.latitude, pos.coords.longitude); },
                      (kind, err) => {
                          if (kind === 'denied') { this.geoFail('denied', err); return; }
                          if (highAccuracy) { this.locate(false); return; }
                          this.geoFail(kind, err);
                      },
                      highAccuracy,
                      highAccuracy ? 12000 : 20000
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
                  // Both are the same broken network provider underneath — 'timeout' is a
                  // silent hang, 'unavailable' is the same lookup failing fast instead. Firefox
                  // switches between the two from one retry to the next, so counting only
                  // 'timeout' let an 'unavailable' in between quietly reset the streak to 0 and
                  // the escalated message never showed up.
                  const isNetworkFail = kind === 'timeout' || kind === 'unavailable';
                  this.timeoutStreak = isNetworkFail ? this.timeoutStreak + 1 : 0;
                  // 'Try again' is only honest advice the first time — a second network-lookup
                  // failure in a row means it is stuck, and it will not unstick on its own (see
                  // bestFix's deadline comment above).
                  const displayKind = (isNetworkFail && this.timeoutStreak >= 2) ? 'timeout_repeat' : kind;
                  const en = {
                      denied: 'Location is blocked for this site, so you cannot clock in or out. On a computer, tap the lock icon in the address bar and allow location. On a phone, also check that location is on for Chrome or Safari in your phone settings.',
                      denied_webview: 'You opened Amanahku inside another app (WhatsApp, Telegram, Facebook), and that window is not allowed to read your location — so clocking cannot work here. Tap the ⋮ or ↗ menu and choose “Open in browser”, then clock from Chrome or Safari.',
                      unavailable: 'Your browser allowed location but could not work out where you are. On a desktop there is no GPS, so the browser looks your position up over the network and that lookup failed. Check that location services are on for the whole computer (Windows Settings, Privacy, Location), then try again. Clocking from your phone always works.',
                      timeout: 'Your location took too long to arrive, so the punch was not sent. Try again — if it keeps timing out, clock from your phone instead.',
                      timeout_repeat: 'Your location has failed to arrive more than once in a row, so trying again will not help. Firefox looks up your location over the network, and something on this connection is blocking that (a VPN, a firewall, or an ad blocker are the usual causes). Turn off any VPN and retry, try Chrome or Edge instead, or clock from your phone.',
                      unsupported: 'This browser cannot share location, so clocking is not possible here. Use the app on your phone browser instead.',
                      insecure: 'This address (' + location.origin + ') is not secure, so the browser will not share your location and clocking is blocked. Open the app on its https:// address instead.',
                  };
                  const ms = {
                      denied: 'Lokasi disekat untuk laman ini, jadi anda tidak boleh clock in atau clock out. Pada komputer, tekan ikon kunci di bar alamat dan benarkan lokasi. Pada telefon, semak juga lokasi dihidupkan untuk Chrome atau Safari dalam tetapan telefon.',
                      denied_webview: 'Anda membuka Amanahku di dalam aplikasi lain (WhatsApp, Telegram, Facebook), dan tetingkap itu tidak dibenarkan membaca lokasi anda — jadi clock tidak boleh dibuat di sini. Tekan menu ⋮ atau ↗ dan pilih “Buka dalam pelayar”, kemudian clock dari Chrome atau Safari.',
                      unavailable: 'Pelayar anda membenarkan lokasi tetapi tidak dapat mengetahui di mana anda berada. Pada komputer tiada GPS, jadi pelayar mencari kedudukan melalui rangkaian dan carian itu gagal. Pastikan servis lokasi dihidupkan untuk seluruh komputer (Windows Settings, Privacy, Location), kemudian cuba lagi. Clock dari telefon sentiasa berfungsi.',
                      timeout: 'Lokasi anda terlalu lama sampai, jadi rekod tidak dihantar. Cuba lagi — jika masih gagal, clock dari telefon anda.',
                      timeout_repeat: 'Lokasi anda gagal sampai lebih daripada sekali berturut-turut, jadi cuba lagi tidak akan membantu. Firefox mencari lokasi anda melalui rangkaian, dan sesuatu pada sambungan ini menyekat carian itu (VPN, firewall, atau ad blocker biasanya penyebabnya). Tutup mana-mana VPN dan cuba lagi, guna Chrome atau Edge sebaliknya, atau clock dari telefon anda.',
                      unsupported: 'Pelayar ini tidak boleh berkongsi lokasi, jadi clock tidak boleh dibuat di sini. Guna pelayar telefon anda.',
                      insecure: 'Alamat ini (' + location.origin + ') tidak selamat, jadi pelayar tidak akan berkongsi lokasi anda dan clock disekat. Buka aplikasi pada alamat https:// sebaliknya.',
                  };
                  // One line names the problem; the cure above stays folded until asked for.
                  const shortEn = {
                      denied: 'Location is blocked — tap for how to allow it',
                      denied_webview: 'This app window cannot read location — tap for how to fix',
                      unavailable: 'Your location could not be worked out — tap for how to fix',
                      timeout: 'Location took too long — tap for what to do',
                      timeout_repeat: 'Location keeps failing — tap for what to try next',
                      unsupported: 'This browser cannot share location — tap for what to do',
                      insecure: 'This address is not secure, so location is blocked — tap for why',
                  };
                  const shortMs = {
                      denied: 'Lokasi disekat — tekan untuk cara benarkan',
                      denied_webview: 'Tetingkap aplikasi ini tidak boleh baca lokasi — tekan untuk cara betulkan',
                      unavailable: 'Lokasi anda tidak dapat dikesan — tekan untuk cara betulkan',
                      timeout: 'Lokasi terlalu lama — tekan untuk apa perlu buat',
                      timeout_repeat: 'Lokasi terus gagal — tekan untuk apa nak cuba',
                      unsupported: 'Pelayar ini tidak boleh kongsi lokasi — tekan untuk apa perlu buat',
                      insecure: 'Alamat ini tidak selamat, jadi lokasi disekat — tekan untuk sebab',
                  };
                  this.geoError = this.$store.ui.lang === 'en' ? en[displayKind] : ms[displayKind];
                  this.geoShort = this.$store.ui.lang === 'en' ? shortEn[displayKind] : shortMs[displayKind];
                  // Support cannot reproduce a staff member's browser, so carry the browser's
                  // own verdict in the message. Without it every failure looks the same and
                  // the guesswork starts.
                  this.geoDetail = err
                      ? 'Browser reported: ' + kind + ' (code ' + err.code + (err.message ? ' — ' + err.message : '') + ')'
                      : 'Browser reported: ' + kind;
              },
              /** Sheet heading: which of the four reasons this gated punch is for, or a plain selfie ask. */
              sheetTitle(lang) {
                  const out = this.action === 'out';
                  if (!this.sheetNeed) { return lang === 'en' ? 'Take a selfie' : 'Ambil selfie'; }
                  const en = {
                      off_site: out ? 'Off-site clock out' : 'Off-site clock in',
                      no_location: out ? 'Clock out without location' : 'Clock in without location',
                      late: 'Late clock in',
                      early: 'Early clock out',
                      site_visit: 'Site visit',
                  };
                  const ms = {
                      off_site: out ? 'Clock out luar lokasi' : 'Clock in luar lokasi',
                      no_location: out ? 'Clock out tanpa lokasi' : 'Clock in tanpa lokasi',
                      late: 'Clock in lewat',
                      early: 'Clock out awal',
                      site_visit: 'Lawatan tapak',
                  };
                  const fallback = lang === 'en'
                      ? (out ? 'Clock out selfie' : 'Clock in selfie')
                      : (out ? 'Selfie clock out' : 'Selfie clock in');
                  return (lang === 'en' ? en : ms)[this.sheetReasonKind] ?? fallback;
              },
              /** Why a reason is needed too — shown only when sheetReasonNeed is true. */
              sheetWhy(lang) {
                  const en = {
                      off_site: 'You appear to be outside the expected location, so this punch needs a reason and a selfie. Your manager sees it flagged.',
                      no_location: 'This punch carries no location, so it needs a reason and a selfie. Your manager sees it flagged.',
                      late: 'You are clocking in after your shift started, so this punch needs a reason. Your manager sees it flagged.',
                      early: 'You are clocking out before your shift ends, so this punch needs a reason. Your manager sees it flagged.',
                      site_visit: 'Tell your manager where you are going. Your selfie and location are still recorded.',
                  };
                  const ms = {
                      off_site: 'Anda kelihatan di luar lokasi yang dijangka, jadi rekod ini perlu sebab dan selfie. Pengurus anda nampak ia ditanda.',
                      no_location: 'Rekod ini tiada lokasi, jadi ia perlu sebab dan selfie. Pengurus anda nampak ia ditanda.',
                      late: 'Anda clock in selepas shift bermula, jadi rekod ini perlu sebab. Pengurus anda nampak ia ditanda.',
                      early: 'Anda clock out sebelum shift tamat, jadi rekod ini perlu sebab. Pengurus anda nampak ia ditanda.',
                      site_visit: 'Beritahu pengurus anda ke mana anda pergi. Selfie dan lokasi anda tetap direkodkan.',
                  };
                  return (lang === 'en' ? en : ms)[this.sheetReasonKind] ?? '';
              },
              sheetReasonLabel(lang) {
                  const en = {
                      off_site: 'Why are you outside the expected location?',
                      no_location: 'Why are you clocking without location?',
                      late: 'Why are you clocking in late?',
                      early: 'Why are you clocking out early?',
                      site_visit: 'Where are you going?',
                  };
                  const ms = {
                      off_site: 'Kenapa anda di luar lokasi yang dijangka?',
                      no_location: 'Kenapa anda clock tanpa lokasi?',
                      late: 'Kenapa anda clock in lewat?',
                      early: 'Kenapa anda clock out awal?',
                      site_visit: 'Ke mana anda pergi?',
                  };
                  return (lang === 'en' ? en : ms)[this.sheetReasonKind] ?? '';
              },
              sheetReasonPlaceholder(lang) {
                  const en = {
                      off_site: 'e.g. Client meeting at HQ, approved by manager',
                      no_location: 'e.g. Office wifi has no location on this desktop',
                      late: 'e.g. Traffic jam on the way in',
                      early: 'e.g. Site visit ended early',
                      site_visit: 'e.g. Customer ABC, Shah Alam',
                  };
                  const ms = {
                      off_site: 'cth. Mesyuarat klien di HQ, diluluskan pengurus',
                      no_location: 'cth. Wifi pejabat tiada lokasi pada komputer ini',
                      late: 'cth. Kesesakan jalan raya',
                      early: 'cth. Lawatan tapak tamat awal',
                      site_visit: 'cth. Customer ABC, Shah Alam',
                  };
                  return (lang === 'en' ? en : ms)[this.sheetReasonKind] ?? '';
              },
              /** Voluntary selfie: the plain capture sheet, no reason box, no punch waiting on it. */
              triggerSelfie() {
                  this.sheetNeed = false;
                  this.sheetReasonNeed = false;
                  this.sheetReasonKind = null;
                  this.sheetFix = null;
                  this.openCam();
              },
              /**
               * The whole gated punch in one surface: live preview, and — when this punch
               * also needs one — the reason, and the button that sends it. Nothing here hands
               * off to the phone camera app — the file input's capture attribute launches the
               * full-screen system camera, which leaves the page, loses the reason box, and
               * costs another two taps to come back from. The stream renders in this sheet
               * instead.
               */
              openPunchSheet(lat, lng, reasonNeed = true, reasonKind = null) {
                  this.sheetFix = { lat, lng };
                  this.sheetNeed = true;
                  this.sheetReasonNeed = reasonNeed;
                  this.sheetReasonKind = reasonKind;
                  this.photoReq = false;
                  this.submitting = false;
                  this.openCam();
              },
              openCam() {
                  this.camError = '';
                  this.camNotice = '';
                  this.camOpen = true;
                  this.$nextTick(() => {
                      this.startCam();
                      if (this.sheetReasonNeed && !this.reason.trim()) { this.$refs.sheetReason?.focus(); }
                  });
              },
              /**
               * Attach the live stream. Every failure leaves the sheet open with the reason box
               * still usable and offers the file picker as a button — it is never clicked for
               * the staff member, because a picker that opens itself is the interruption this
               * sheet exists to remove.
               */
              async startCam() {
                  if (!window.isSecureContext) {
                      this.camError = 'In-page camera needs HTTPS or localhost — you are on ' + location.origin + '.';
                      return;
                  }
                  if (!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia)) {
                      this.camError = 'This browser will not expose the camera here.';
                      return;
                  }
                  try {
                      this.stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' }, audio: false });
                      const v = this.$refs.cam;
                      v.srcObject = this.stream;
                      // autoplay alone is not reliable on a element that was display:none a tick
                      // ago; without this the preview sits at readyState 0 and capture() draws a
                      // zero-sized frame that fails validation on the server with no explanation.
                      await v.play().catch(() => {});
                  } catch (e) {
                      const n = e.name || 'error';
                      if (n === 'NotFoundError' || n === 'OverconstrainedError' || n === 'DevicesNotFoundError') {
                          this.camError = 'No camera found on this device. If you have a webcam, plug it in or close any app using it (Zoom, Teams), then try again.';
                      } else if (n === 'NotAllowedError' || n === 'SecurityError' || n === 'PermissionDeniedError') {
                          this.camError = 'Camera permission blocked. Tap the camera or lock icon in the address bar, allow camera for this site, then try again.';
                      } else if (n === 'NotReadableError' || n === 'TrackStartError') {
                          this.camError = 'Camera is busy — another app (Zoom, Teams, OBS) is using it. Close it, then try again.';
                      } else {
                          this.camError = 'Could not open camera (' + n + ').';
                      }
                  }
              },
              /** True once the sheet has everything the server will demand of this punch. */
              get sheetReady() {
                  return (this.photoUrl !== null || this.stream !== null)
                      && (! this.sheetReasonNeed || this.reason.trim().length > 0);
              },
              /** The sheet's one button: capture if needed, then send. */
              confirmPunch() {
                  if (this.submitting) { return; }
                  if (this.sheetReasonNeed && !this.reason.trim()) { this.$refs.sheetReason?.focus(); return; }
                  if (this.photoUrl) { this.sendSheet(); return; }
                  if (!this.stream) { this.camError = 'A selfie is required for this punch. Allow the camera, or attach a photo below.'; return; }
                  this.capture(() => this.sendSheet());
              },
              sendSheet() {
                  this.submitting = true;
                  const fix = this.sheetFix ?? { lat: null, lng: null };
                  this.closeCam();
                  this.proceed(fix.lat, fix.lng);
              },
              capture(after = null) {
                  const v = this.$refs.cam, c = this.$refs.canvas;
                  // A stream that has not delivered a frame yet reports 0×0, and drawing it
                  // produces an empty file the server rejects for reasons no one can see here.
                  if (!v.videoWidth || !v.videoHeight) {
                      this.camError = this.$store.ui.lang === 'en'
                          ? 'The camera has not started yet. Give it a second, then try again.'
                          : 'Kamera belum bermula. Tunggu sekejap, kemudian cuba lagi.';
                      this.submitting = false;
                      return;
                  }
                  this.drawScaled(v, v.videoWidth, v.videoHeight);
                  c.toBlob((blob) => {
                      if (!blob) { this.camError = 'Capture failed, try again.'; return; }
                      this.setPhoto(new File([blob], 'selfie.jpg', { type: 'image/jpeg' }));
                      this.photoReq = false;
                      if (after) { after(); return; }
                      this.closeCam();
                      this.resumeAfterSelfie();
                  }, 'image/jpeg', 0.85);
              },
              /**
               * Draw a camera frame or a picked photo onto the shared canvas, never wider or
               * taller than 1600px. Both paths cap here because both end up as one upload, and
               * the smallest limit in the way is set on the host, not in this repo: production
               * runs stock PHP, whose upload_max_filesize is 2MB. Over that, PHP truncates the
               * file and the punch is refused for a reason the staff member cannot act on.
               * 1600px stays legible as proof of who was standing there and lands in the
               * hundreds of kilobytes; an uncapped 4K front camera does not.
               */
              drawScaled(source, width, height) {
                  const c = this.$refs.canvas;
                  const scale = Math.min(1, 1600 / Math.max(width, height));
                  c.width = Math.round(width * scale);
                  c.height = Math.round(height * scale);
                  c.getContext('2d').drawImage(source, 0, 0, c.width, c.height);
              },
              /** Put a file on the hidden form input and mirror it into the preview. */
              setPhoto(file) {
                  const dt = new DataTransfer();
                  if (file) { dt.items.add(file); }
                  this.$refs.photo.files = dt.files;
                  if (this.photoUrl) { URL.revokeObjectURL(this.photoUrl); }
                  this.photoUrl = file ? URL.createObjectURL(file) : null;
              },
              /**
               * The fallback input hands back whatever the phone has stored: a 3-8MB camera
               * JPEG, or an iPhone HEIC the server does not accept at all. This input used to
               * send that original file untouched, which is why a punch failed here that the
               * in-page camera would have passed. Re-encode it through the same canvas so
               * both paths hand the server the same kind of picture. Safari decodes HEIC into
               * a canvas, so the format is normalised on the way through.
               */
              attachFile(file) {
                  this.camNotice = '';
                  if (!file) { this.setPhoto(null); return; }
                  const img = new Image();
                  const src = URL.createObjectURL(file);
                  img.onload = () => {
                      URL.revokeObjectURL(src);
                      this.drawScaled(img, img.width, img.height);
                      this.$refs.canvas.toBlob((blob) => {
                          this.usePhoto(blob ? new File([blob], 'selfie.jpg', { type: 'image/jpeg' }) : file);
                      }, 'image/jpeg', 0.85);
                  };
                  // A format this browser cannot decode (HEIC anywhere but Safari) never loads.
                  // Send the original and let the server's own rule produce the message.
                  img.onerror = () => { URL.revokeObjectURL(src); this.usePhoto(file); };
                  img.src = src;
              },
              /** Accept a chosen file and carry on with whatever punch was waiting on it. */
              usePhoto(file) {
                  this.setPhoto(file);
                  this.photoReq = false;
                  if (! this.sheetNeed) { this.resumeAfterSelfie(); }
              },
              closeCam() {
                  if (this.stream) { this.stream.getTracks().forEach((t) => t.stop()); this.stream = null; }
                  this.camOpen = false;
                  this.sheetNeed = false;
                  this.sheetReasonNeed = false;
                  this.sheetReasonKind = null;
              }
          }"
          @submit.prevent="noLoc ? submitWithoutLocation() : submit()">
        @csrf
        <input type="hidden" name="action" value="{{ $ci && !$co ? 'out' : 'in' }}" />{{-- mirrored by `action` in x-data --}}
        <input type="hidden" name="work_mode" :value="workMode" />
        <input type="hidden" name="latitude" x-ref="lat" />
        <input type="hidden" name="longitude" x-ref="lng" />
        {{-- No `capture="user"`: that attribute makes the phone open its full-screen camera app,
             which leaves the page and drops whatever is half-typed in the sheet. This input is
             only the fallback for a browser that will not give us a stream, and it should offer
             the gallery as readily as the camera. --}}
        <input type="file" id="attendance-photo" name="photo" accept="image/*" x-ref="photo"
               style="display:none;"
               @change="attachFile($event.target.files[0])" />

        {{-- Declared before the punch: the ordinary day needs no tap, a customer visit needs
             one. Not a site picker — the GPS is still measured against the same place either
             way, and the declaration costs a typed destination in the sheet. --}}
        <div class="uj-at-mode" role="group" :aria-label="$store.ui.lang==='en' ? 'Working mode' : 'Mod kerja'">
            <button type="button" :data-on="workMode === 'office_home'" @click="workMode = 'office_home'"
                    :aria-pressed="workMode === 'office_home' ? 'true' : 'false'"
                    x-text="$store.ui.lang==='en' ? 'Office / Home' : 'Pejabat / Rumah'">Office / Home</button>
            <button type="button" :data-on="workMode === 'site_visit'" @click="workMode = 'site_visit'"
                    :aria-pressed="workMode === 'site_visit' ? 'true' : 'false'"
                    x-text="$store.ui.lang==='en' ? 'Site visit' : 'Lawatan tapak'">Site visit</button>
        </div>

        <div class="uj-at-shelf-top">
            <div style="min-width:0;">
                <div class="uj-at-figrow" :class="{ 'uj-at-figrow--punched': justPunched }">
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
                                <span x-show="$store.ui.lang==='en'">shift starts <b>{{ \Illuminate\Support\Str::of($site->workStart)->limit(5, '') }}</b></span>
                                <span x-show="$store.ui.lang!=='en'" x-cloak>shift bermula <b>{{ \Illuminate\Support\Str::of($site->workStart)->limit(5, '') }}</b></span>
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
                <button type="submit" class="uj-at-go" @if ($co) disabled @else :disabled="submitting" @endif>
                    @if ($co)
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 12l5 5L20 6"/></svg>
                        <span x-text="$store.ui.lang==='en' ? 'Shift complete' : 'Shift selesai'">Shift complete</span>
                    @else
                        <template x-if="!submitting">
                            <span x-text="goLabel($store.ui.lang)">{{ $ci ? 'Clock out' : 'Clock in' }}</span>
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

        <div x-show="geoError" x-cloak role="alert" style="color:var(--red-active);font-size:11.5px;margin-top:7px;line-height:1.45;text-align:left;">
            {{-- Summary first, cure on demand. The full instructions run to three sentences and
                 stood permanently open in red under the punch, which is what buried the button. --}}
            <details class="uj-at-geo">
                <summary x-text="geoShort"></summary>
                <div x-text="geoError" style="margin-top:6px;"></div>
                <div x-text="geoDetail" style="color:var(--body);margin-top:3px;font-family:ui-monospace,monospace;font-size:10.5px;"></div>
            </details>
            @if (! $co)
                {{-- The punch without location is NOT a second button here. Once a real attempt
                     has failed the main punch button becomes it (see goLabel + noLoc), because
                     two buttons both reading 'Clock in' — one of them dead — is what had staff
                     tapping twice and believing they had punched twice. What is left here is
                     the way back: allow location, then retry the ordinary punch. --}}
                <div x-show="noLoc" x-cloak style="margin-top:9px;">
                    <div x-text="$store.ui.lang==='en'
                            ? 'The button above now clocks you in without location. It needs a reason and a selfie, and your manager sees the punch flagged.'
                            : 'Butang di atas kini clock tanpa lokasi. Ia perlu sebab dan selfie, dan pengurus anda nampak rekod itu ditanda.'"
                         style="opacity:.8;margin-bottom:7px;"></div>
                    <button type="button" class="uj-at-ghost" :disabled="submitting"
                            @click="geoError = ''; geoDetail = ''; submit()"
                            x-text="$store.ui.lang==='en' ? 'I allowed location — try again' : 'Saya benarkan lokasi — cuba lagi'"></button>
                </div>
            @endif
        </div>
        @error('latitude')<div style="color:var(--red-active);font-size:11.5px;margin-top:7px;line-height:1.45;text-align:left;">{{ $message }}</div>@enderror

        {{-- One line, never two. A demand for a selfie gets the bilingual instruction; a photo
             the server refused gets the server's own words, which say what is wrong with it. --}}
        @if (session('attendance_photo'))
            <div x-show="photoReq" x-cloak style="color:var(--red-active);font-size:11.5px;margin-top:7px;line-height:1.45;text-align:left;"
                 x-text="$store.ui.lang==='en'
                     ? 'A selfie is required for every clock in and out. Take one, then clock again.'
                     : 'Selfie diperlukan untuk setiap clock in dan clock out. Ambil satu, kemudian clock semula.'"></div>
        @else
            @error('photo')<div style="color:var(--red-active);font-size:11.5px;margin-top:7px;line-height:1.45;text-align:left;">{{ $message }}</div>@enderror
        @endif

        <div x-show="camNotice" x-cloak x-text="camNotice" style="color:var(--amber);font-size:11.5px;margin-top:7px;line-height:1.45;text-align:left;"></div>

        {{-- The punch sheet: in-page camera, and — when a punch is waiting on it — the reason
             in the same surface, so an off-grid clock-in is one tap and one confirm. --}}
        <template x-teleport="body">
            {{-- Layout lives in the class, not an inline style: x-show writes `display:none`
                 onto the element and restores it with `display:''`, which falls back to the
                 stylesheet. An inline `display:flex` is gone for good after the first hide,
                 and the sheet reopens as a block stuck to the top-left. --}}
            <div x-show="camOpen" x-cloak class="uj-at-sheet-wrap"
                 @keydown.escape.window="closeCam()" @click.self="closeCam()"
                 role="dialog" aria-modal="true" aria-labelledby="uj-at-sheet-title">
                <div class="uj-at-sheet">
                    <h2 id="uj-at-sheet-title" x-text="sheetTitle($store.ui.lang)">Take a selfie</h2>

                    <p x-show="sheetReasonNeed" x-cloak class="uj-at-sheet-why" x-text="sheetWhy($store.ui.lang)"></p>

                    <div class="uj-at-sheet-cam">
                        <video x-ref="cam" autoplay playsinline muted x-show="!photoUrl"></video>
                        <img x-show="photoUrl" x-cloak :src="photoUrl" alt="" />
                    </div>

                    <div x-show="camError" x-cloak class="uj-at-sheet-err">
                        <span x-text="camError"></span>
                        <button type="button" class="uj-at-ghost" style="margin-top:8px;height:40px;"
                                @click="$refs.photo.click()"
                                x-text="$store.ui.lang==='en' ? 'Attach a photo instead' : 'Lampirkan gambar sebaliknya'"></button>
                    </div>

                    <div x-show="sheetReasonNeed" x-cloak class="uj-at-sheet-reason">
                        <label for="attendance-sheet-reason" x-text="sheetReasonLabel($store.ui.lang)">Why are you clocking without location?</label>
                        {{-- x-teleport moves this whole dialog to <body>, outside the <form> in the
                             DOM tree. Without the explicit `form` attribute below, native form
                             submission silently drops this field and every reason-required punch
                             (off-site, no-location, late, early, site visit) bounces forever. --}}
                        <textarea id="attendance-sheet-reason" name="justification" form="attendance-clock-form" x-ref="sheetReason" x-model="reason" rows="2" maxlength="500"
                                  aria-required="true" :placeholder="sheetReasonPlaceholder($store.ui.lang)"></textarea>
                        @error('justification')<div style="color:var(--red);font-size:11.5px;margin-top:4px;">{{ $message }}</div>@enderror
                    </div>

                    <div class="uj-at-sheet-acts">
                        <button type="button" class="uj-btn-ghost" @click="closeCam()"
                                x-text="$store.ui.lang==='en' ? 'Cancel' : 'Batal'">Cancel</button>
                        <button type="button" class="uj-btn-primary" x-show="!sheetNeed" @click="capture()"
                                x-text="$store.ui.lang==='en' ? 'Capture' : 'Tangkap'">Capture</button>
                        <button type="button" class="uj-btn-primary" x-show="sheetNeed" x-cloak
                                :disabled="submitting || !sheetReady" @click="confirmPunch()"
                                x-text="submitting
                                    ? ($store.ui.lang==='en' ? 'Sending…' : 'Menghantar…')
                                    : ($store.ui.lang==='en' ? @js($ci && !$co ? 'Clock out' : 'Clock in') : @js($ci && !$co ? 'Clock out' : 'Clock in'))"></button>
                    </div>
                </div>
            </div>
        </template>
        <canvas x-ref="canvas" style="display:none;"></canvas>

        {{-- Mobile action dock --}}
        <div class="uj-at-dock">
            <button type="submit" class="uj-at-dock-go" :class="{ 'uj-at-dock-go--punched': justPunched }" @if ($co) disabled @else :disabled="submitting" @endif>
                @if ($co)
                    <span x-text="$store.ui.lang==='en' ? 'Shift complete ✓' : 'Shift selesai ✓'">Shift complete ✓</span>
                @else
                    <template x-if="!submitting">
                        <span x-text="goLabel($store.ui.lang)">{{ $ci ? 'Clock out' : 'Clock in' }}</span>
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
