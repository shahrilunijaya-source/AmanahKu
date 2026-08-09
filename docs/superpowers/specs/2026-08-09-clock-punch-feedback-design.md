# Clock-in/out feedback: replace the small toast with a card pulse

**Date:** 2026-08-09
**Status:** design approved, not implemented

## Problem

Staff feedback: the success confirmation after clock-in/out is too small to notice.

Today a successful punch reaches the screen through the generic toast pipeline: `AttendanceController::clock()` returns `back()->with('ok', $result['message'])`, and `app.blade.php` turns any `session('ok')` into `Alpine.store('toast').success(...)` (resources/views/layouts/app.blade.php:410-411) — the same small corner toast used by every other screen in the app for every kind of "ok" flash. Making it bigger is not a real fix: the toast is shared infrastructure, and resizing it for one screen either forks the component or changes it everywhere else too.

The screen already has a natural place for this feedback: the status card (`uj-at-figrow`, attendance.blade.php:640) and the mobile dock button (`uj-at-dock-go`, attendance.blade.php:843) both already re-render with the correct fact ("worked · in since 9:02") on the page reload that follows a punch — the form is a real multipart POST, not a fetch, so this is a full page reload today, not a client-side swap. The gap is that nothing draws attention to the card when it changes; the tiny toast is the only signal.

## Decision

Stop routing clock-punch success through the toast. Instead, give the status card and the dock button a one-shot highlight animation on the reload that follows a successful punch, driven by a flash key dedicated to this one action so it never collides with, or gets suppressed by, every other screen's `ok` flash.

| Question | Decision |
|---|---|
| Toast or card? | **Card + dock button pulse**, toast suppressed for this action specifically. Other screens' `ok` toast is untouched. |
| New flash key? | **`clock_ok`**, not `ok`. Keeps this screen's routing independent of the shared toast pipeline; no other controller writes `clock_ok`. |
| What triggers the pulse? | A one-time Alpine flag (`justPunched`) seeded server-side from `session('clock_ok')`, cleared by `setTimeout` after ~1.8s so it never re-fires on a later refresh. |
| Does the "already punched" (noop) case change? | **No.** That still returns `session('info')` and still goes through the toast — a repeat punch is explicitly not a success and should not get the celebratory treatment. |
| Justification/photo-required cases? | **Unchanged.** Those return `withErrors(...)`/`with('attendance_justify', ...)`, never touch `clock_ok`. |

## Implementation

**1. `app/Http/Controllers/AttendanceController.php:96`**
Change the success return from `back()->with('ok', $result['message'])` to `back()->with('clock_ok', $result['message'])`.

**2. `resources/views/layouts/app.blade.php`**
No change. `session('clock_ok')` is deliberately never read here — that's what keeps it out of the toast.

**3. `resources/views/screens/attendance.blade.php`**
- Add `justPunched: {{ session('clock_ok') ? 'true' : 'false' }}` to the form's `x-data`.
- On init, if `justPunched` is true, `setTimeout(() => this.justPunched = false, 1800)`.
- `uj-at-figrow` (line 640) gets `:class="{ 'uj-at-figrow--punched': justPunched }"`.
- `uj-at-dock-go` (line 843) gets `:class="{ 'uj-at-dock-go--punched': justPunched }"`.
- No new markup, no new text — the real "worked · in since 9:02" / "Clock out" strings are already server-rendered; the animation just highlights them.

**4. CSS**
One shared `@keyframes uj-punch-pulse` (brief scale + glow in `--success`, ~1.2s), applied via `.uj-at-figrow--punched` and `.uj-at-dock-go--punched`. The desktop card version pulses a glow behind the text; the dock button version pulses its own background/border since it's a filled pill, not bare text.

## Out of scope

- Does not touch the toast component itself, or any other screen using `session('ok')`.
- Does not change the form submission from a real POST+reload to a fetch-based swap. That's a bigger change (tracked nowhere yet) and orthogonal to this feedback fix.
- Does not change the "already punched" or justification/photo-required flows.
