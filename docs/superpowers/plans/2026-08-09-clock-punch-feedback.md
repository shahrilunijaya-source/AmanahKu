# Clock-punch card pulse feedback Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the small corner toast as the confirmation for a successful clock-in/out with a one-shot pulse animation on the attendance screen's own status card and mobile dock button.

**Architecture:** `AttendanceController::clock()` flashes a new `clock_ok` session key instead of the shared `ok` key on a successful punch, so `app.blade.php`'s existing `session('ok')` → toast wiring never sees it. `attendance.blade.php` seeds an Alpine `justPunched` boolean from `session('clock_ok')` on page load, clears it after ~1.8s, and binds it to a CSS class on the two elements that already show the post-punch state.

**Tech Stack:** Laravel 13 (PHPUnit 12 feature tests), Blade, Alpine.js, vanilla CSS (no Tailwind in this file).

## Global Constraints

- PHP: explicit return types and param type hints on any touched method signature.
- Run `vendor/bin/pint --dirty --format agent` after any PHP edit, before considering a task done.
- Tests are PHPUnit classes (`declare(strict_types=1)`), run via `php artisan test --compact --filter=...`.
- Do not touch `session('info')` (declined/noop punch) or the `withErrors(...)` justification/photo paths — only the success (`status === 'ok'`) branch changes.
- `.uj-at-figrow` is shared with the leave screen via `.uj-lv-figrow` (resources/css/app.css:1407) — the new class must be scoped to `.uj-at-figrow--punched` only, never bleed into `.uj-lv-figrow`.
- Respect the existing `@media (prefers-reduced-motion: reduce)` block (resources/css/app.css:1570-1577) — any new animation needs a `animation: none` entry added there too.

---

### Task 1: Rename the success flash key from `ok` to `clock_ok`

**Files:**
- Modify: `app/Http/Controllers/AttendanceController.php:96`
- Modify: `tests/Feature/AttendanceClockEndpointTest.php:72,84,90,111,127`

**Interfaces:**
- Consumes: nothing new.
- Produces: `session('clock_ok')` — a string message, present only on a successful clock-in/out, read by Task 2 in `attendance.blade.php`. `session('ok')` is no longer set by this controller (no other controller currently sets it for this route).

- [ ] **Step 1: Update the four existing assertions in `AttendanceClockEndpointTest.php` to the new key**

Change every `assertSessionHas('ok')` on a successful punch to `assertSessionHas('clock_ok')`, and the one `assertSessionMissing('ok')` (declined-punch case, line 90) to `assertSessionMissing('clock_ok')` — it is the same assertion, just against the renamed key.

```php
    public function test_first_clock_in_is_a_success(): void
    {
        $this->punch(['action' => 'in'])->assertSessionHas('clock_ok');

        $this->assertNotNull($this->employee->attendanceRecords()->first()?->clock_in);
    }

    public function test_second_clock_in_is_declined_without_claiming_success(): void
    {
        $this->punch(['action' => 'in'])->assertSessionHas('clock_ok');
        $firstPunch = $this->employee->attendanceRecords()->first()->clock_in;

        $this->travel(30)->minutes();
        $response = $this->punch(['action' => 'in']);

        $response->assertSessionMissing('clock_ok');
        $response->assertSessionHas('info', 'Already clocked in today.');
        $this->assertSame($firstPunch, $this->employee->attendanceRecords()->first()->clock_in);
        $this->assertSame(1, $this->employee->attendanceRecords()->count());
    }
```

(`test_a_declined_punch_leaves_no_orphan_selfie_on_the_disk` line 111 and `test_an_accepted_punch_keeps_its_selfie` line 127 get the same `'ok'` → `'clock_ok'` swap on their `assertSessionHas` calls; their bodies are otherwise unchanged.)

- [ ] **Step 2: Run the test file to confirm it currently fails against the unchanged controller**

Run: `php artisan test --compact tests/Feature/AttendanceClockEndpointTest.php`
Expected: FAIL — `test_first_clock_in_is_a_success` and the others report the session is missing key `clock_ok` (controller still flashes `ok`).

- [ ] **Step 3: Change the controller's success return**

In `app/Http/Controllers/AttendanceController.php`, line 96:

```php
        return back()->with('clock_ok', $result['message']);
```

(replacing `return back()->with('ok', $result['message']);` — the `noop`, `needs_justification`, and `needs_photo` branches above it, lines 75-94, are untouched.)

- [ ] **Step 4: Run the test file again to confirm it passes**

Run: `php artisan test --compact tests/Feature/AttendanceClockEndpointTest.php`
Expected: PASS, all tests green.

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/AttendanceController.php tests/Feature/AttendanceClockEndpointTest.php
git commit -m "fix(attendance): flash clock-punch success under its own key

Split it off from the shared 'ok' key so a successful punch stops
routing through the generic corner toast — the attendance screen
picks up 'clock_ok' itself in the next change."
```

---

### Task 2: Alpine `justPunched` flag + class bindings on the status card and dock button

**Files:**
- Modify: `resources/views/screens/attendance.blade.php` (x-data block, `uj-at-figrow` at line 640, `uj-at-dock-go` at line 843)
- Test: `tests/Feature/AttendanceScreenTest.php`

**Interfaces:**
- Consumes: `session('clock_ok')` from Task 1.
- Produces: an Alpine data property `justPunched` (boolean) on the clock form's root `x-data`, and the class `uj-at-figrow--punched` / `uj-at-dock-go--punched`, both consumed by the CSS in Task 3.

- [ ] **Step 1: Read the current `x-data` opening and `init()` (if any) to find where to add the field**

Run: `grep -n "x-data=\"{" resources/views/screens/attendance.blade.php` and open the surrounding lines (the block starts around line 75) to see the existing property list and whether an `init()` method already exists on this component.

- [ ] **Step 2: Write the failing feature test**

Add to `tests/Feature/AttendanceScreenTest.php`, reusing the class's existing `setUp()` (tenant/user/employee already built there) and its established way of reaching the screen — every existing test in this file calls `->get('/app/attendance')` directly, not a named route:

```php
    public function test_status_card_carries_the_punched_flag_right_after_a_successful_punch(): void
    {
        $response = $this->actingAs($this->user)
            ->withSession(['current_tenant' => $this->tenant->id, 'clock_ok' => 'Clocked in at 09:02.'])
            ->get('/app/attendance');

        $response->assertSee('justPunched: true', false);
    }

    public function test_status_card_does_not_carry_the_punched_flag_on_a_plain_visit(): void
    {
        $response = $this->actingAs($this->user)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/attendance');

        $response->assertSee('justPunched: false', false);
    }
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `php artisan test --compact --filter=test_status_card_carries_the_punched_flag_right_after_a_successful_punch tests/Feature/AttendanceScreenTest.php`
Run: `php artisan test --compact --filter=test_status_card_does_not_carry_the_punched_flag_on_a_plain_visit tests/Feature/AttendanceScreenTest.php`
Expected: both FAIL — `justPunched` does not exist in the rendered HTML yet.

- [ ] **Step 4: Add `justPunched` to the `x-data` block**

In `resources/views/screens/attendance.blade.php`, inside the `x-data="{ ... }"` object that opens the clock `<form>` (around line 75-120), add a new property. Keep it next to the other server-seeded scalars (e.g. `clockInTime`):

```php
              justPunched: {{ session('clock_ok') ? 'true' : 'false' }},
```

- [ ] **Step 5: Clear the flag after 1.8s on mount**

Find (or add) the component's `init()` method inside the same `x-data` object. If one already exists, append to it; if not, add:

```php
              init() {
                  if (this.justPunched) {
                      setTimeout(() => { this.justPunched = false; }, 1800);
                  }
              },
```

(If an `init()` already exists elsewhere in this object for other setup, add the `if (this.justPunched) { ... }` block inside that existing method instead of creating a second `init()` — Alpine only calls one.)

- [ ] **Step 6: Bind the class on the status card**

At line 640, change:

```html
                <div class="uj-at-figrow">
```

to:

```html
                <div class="uj-at-figrow" :class="{ 'uj-at-figrow--punched': justPunched }">
```

- [ ] **Step 7: Bind the class on the dock button**

At line 843, change:

```html
            <button type="submit" class="uj-at-dock-go" @if ($co) disabled @else :disabled="submitting" @endif>
```

to:

```html
            <button type="submit" class="uj-at-dock-go" :class="{ 'uj-at-dock-go--punched': justPunched }" @if ($co) disabled @else :disabled="submitting" @endif>
```

- [ ] **Step 8: Run the tests again to confirm they pass**

Run: `php artisan test --compact --filter=test_status_card_carries_the_punched_flag_right_after_a_successful_punch tests/Feature/AttendanceScreenTest.php`
Run: `php artisan test --compact --filter=test_status_card_does_not_carry_the_punched_flag_on_a_plain_visit tests/Feature/AttendanceScreenTest.php`
Expected: both PASS.

- [ ] **Step 9: Run the full attendance screen test file to check for regressions**

Run: `php artisan test --compact tests/Feature/AttendanceScreenTest.php`
Expected: PASS, all tests green.

- [ ] **Step 10: Commit**

```bash
git add resources/views/screens/attendance.blade.php tests/Feature/AttendanceScreenTest.php
git commit -m "feat(attendance): seed a one-shot punched flag for the status card

justPunched comes from the clock_ok flash added in the previous
commit, clears itself after 1.8s, and is now bound onto the status
card and mobile dock button classes — CSS pulse comes next."
```

---

### Task 3: CSS pulse animation

**Files:**
- Modify: `resources/css/app.css`

**Interfaces:**
- Consumes: `.uj-at-figrow--punched` and `.uj-at-dock-go--punched` classes from Task 2.
- Produces: nothing consumed elsewhere — this is the last task.

This task is pure CSS (no PHP/JS logic), so there is no PHPUnit test for it — verify visually per Step 4 below instead, matching the "programmatically tested" bar this codebase otherwise holds to (there is no meaningful assertion to write against a keyframe animation's visual timing).

- [ ] **Step 1: Add the shared keyframe next to the other `uj-at-*` keyframe (`uj-at-ping`, resources/css/app.css:1423)**

```css
@keyframes uj-punch-pulse {
  0% { box-shadow: 0 0 0 0 color-mix(in srgb, var(--success) 45%, transparent); }
  70% { box-shadow: 0 0 0 14px color-mix(in srgb, var(--success) 0%, transparent); }
  100% { box-shadow: 0 0 0 0 color-mix(in srgb, var(--success) 0%, transparent); }
}
```

- [ ] **Step 2: Apply it to the desktop status card, directly under the `.uj-at-figrow` rule (resources/css/app.css:1407)**

```css
.uj-at-figrow--punched { border-radius: 10px; animation: uj-punch-pulse 1.2s cubic-bezier(.23, 1, .32, 1); }
```

- [ ] **Step 3: Apply it to the mobile dock button, inside the existing `@media (max-width: 640px)` block, directly under `.uj-at-dock-go` (resources/css/app.css:1587-1591)**

```css
  .uj-at-dock-go--punched { animation: uj-punch-pulse 1.2s cubic-bezier(.23, 1, .32, 1); }
```

- [ ] **Step 4: Add the reduced-motion override**

In the existing `@media (prefers-reduced-motion: reduce)` block (resources/css/app.css:1570-1577), add a line alongside the other animation resets:

```css
  .uj-at-figrow--punched, .uj-at-dock-go--punched { animation: none; }
```

- [ ] **Step 5: Verify in the browser**

Start the app (`lerd status` to confirm services are up), log in via the dev quick-login helper (`http://localhost:9100/dev/login?email=employee@amanahku.test&tenant=unijaya`), open the attendance screen, and clock in. Confirm:
- No toast appears for the punch itself (the "already punched" case on a second attempt should still show one, via `info`).
- The status card briefly pulses a green glow around its border.
- On mobile width (resize to ≤640px), the dock button shows the same pulse.
- The pulse does not repeat on a plain page refresh afterward.

- [ ] **Step 6: Commit**

```bash
git add resources/css/app.css
git commit -m "style(attendance): pulse the status card and dock button on a punch

Closes out the clock_ok flash added earlier — a successful punch now
gets an unmissable highlight on the screen's own status, instead of
the small shared toast."
```
