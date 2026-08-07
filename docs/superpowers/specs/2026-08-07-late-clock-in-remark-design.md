# Late clock-in needs a remark

**Date:** 2026-08-07
**Status:** design approved, not implemented

## Problem

Lateness is the only attendance anomaly the system records silently.

Every other irregular punch already costs the employee a typed reason before it is
accepted: clocking in outside the geofence, clocking in with no GPS fix at all, clocking
out early, clocking out short of the minimum hours, clocking out off-site. Each of those
returns `needs_justification` from `ClockService`, the screen re-opens the reason field,
and the punch only saves once a reason is present.

A late clock-in does none of this. `ClockService::clockIn()` computes `$late`, writes
`status = 'late'` and appends a `late` flag, then saves. No reason is asked for. HR sees
that somebody was late but never learns why, and the employee is never given the moment to
explain.

This design closes that one gap. It does not touch the other gaps found while mapping the
subsystem (see "Out of scope" below).

## Decisions taken

| Question | Decision |
|---|---|
| Mandatory or optional remark? | **Mandatory.** The punch is refused until a reason is typed, exactly like the off-site and early-out gates. An optional box would come back empty on most rows and give HR nothing to review. |
| Any role exempt? | **No.** Director and management are gated the same as everybody else. `ClockService` currently contains no role logic at all and gains none here. |
| Grace period | **15 minutes**, held in `tenants.late_grace_minutes`. |
| Client-side mirror? | **Yes**, re-confirmed after the original justification for it was found to be false. See "Why the client mirror is worth having". |
| Where the grace control lives | **Lifted out of the Work from home tab** into its own always-visible row on the Attendance Setup screen. See section 5. |

## Grace period, the precondition

`ClockService::clockIn()` reads `$employee->tenant->late_grace_minutes ?? 0`. That column
is `NULL` on every tenant in every environment today, so the effective grace is **zero**:
a punch at 09:00:15 against an 09:00 start is already late.

Shipping a mandatory remark on top of zero grace would block every employee every morning
and turn the field into a column of the word "traffic" within a week. The grace must carry
a real value before the gate goes live.

**Approach: a data migration setting existing `NULL` values to 15.**

```php
DB::table('tenants')->whereNull('late_grace_minutes')->update(['late_grace_minutes' => 15]);
```

The reason for a migration rather than asking HR to fill the field in Attendance Setup:
production is devops-owned and carries no shell or database access for this project, so a
manual configuration step there cannot be verified, only assumed. A migration runs on every
environment as part of the normal release and leaves the column authoritative.

The column stays editable in the UI (Attendance Setup, "Late grace (min)", range 0 to 120,
`resources/views/screens/attendance-admin.blade.php:191`). The migration sets a floor for
tenants that never chose a value; it does not take the control away.

The code fallback `?? 0` in `ClockService.php:76` stays as it is. Changing it to `?? 15`
would silently redefine what counts as late for any future tenant row, which is a wider
behavioural change than this work asks for.

## The change

### 1. Server gate, `app/Attendance/ClockService.php`

One new block in `clockIn()`, inserted after `$late` is computed (currently line 76) and
before the `$flags` array is built:

```php
// Lateness is the one anomaly that used to be recorded in silence. It now costs the same
// typed reason as an off-site or unlocatable punch: the flag alone told HR that somebody
// was late but never why, and gave the employee no moment to say so.
if ($late && ! $this->filled($justification)) {
    return ['status' => 'needs_justification', 'message' => 'You are clocking in after '.$site->workStart.'. Add a reason.'];
}
```

Properties that follow from placing it there:

- **One definition of late.** The gate reuses the same `$late` boolean that sets
  `status = 'late'` and the `late` flag, so grace is honoured identically and the two can
  never disagree.
- **No hours configured means no gate.** `isLate()` returns `false` when `$site->workStart`
  is null, so staff on a site with no configured start time are never asked for a reason.
- **Location gates still win.** The no-GPS and off-site checks run earlier and return
  first, so a punch that is both late and off-site shows the fence message. The single
  `justification` field covers both conditions; the employee types one reason, not two.
- **Overnight shifts already handled.** `isLate()` anchors the shift start onto the correct
  calendar day, so a 00:30 punch on a 22:00 shift is judged against the right boundary.

No migration for the text itself: the existing `clock_in_justification` column already
stores it, and `ClockService` already writes it on the last line of `$attributes`.

### 2. View payload, `app/Http/Controllers/Concerns/BuildsWorkData.php`

The screen needs the shift start and the grace to judge lateness in the browser. `site`
(a `SiteSpec`) already carries `workStart`, but grace lives on the tenant and is not in the
payload today. Add one key beside the existing `site` and `geofencedSites` entries (around
line 86):

```php
'lateGraceMinutes' => $employee?->tenant->late_grace_minutes ?? 0,
```

The `?? 0` mirrors `ClockService.php:76` exactly, so the browser and the server agree even
on a tenant the migration has not touched.

### 3. Client mirror, `resources/views/screens/attendance.blade.php`

The screen mirrors every server rule so a punch is not bounced through a round-trip. Four
edits, matching the existing `earlyNow()` treatment for clock-out:

1. Alpine state gains `expectedStart` (from `$site?->workStart`) and `graceMin` (from
   `$lateGraceMinutes`), beside the existing `expectedEnd` at around line 113.
2. A `lateNow()` method beside `earlyNow()` (around line 252), mirroring `isLate()`:
   current wall-clock minutes greater than start plus grace. Returns `false` when
   `expectedStart` is empty, matching the server.
3. `proceed()` gains one line beside the existing early-out line (around line 270):
   `if (this.action === 'in' && this.lateNow()) need = true;`
   This routes a late punch into the existing reason drawer. No camera is opened, because a
   late punch inside the fence is already located by the fence.
4. The red "Reason required" label (around line 700) gains a late branch, in both English
   and Malay, alongside the off-site and early-out branches it already carries.

`lateNow()` deliberately does **not** feed the `isReq` getter. `isReq` turns the label red,
and the existing comment at line 186 records why `earlyNow()` was kept out of it: a
condition that is true for a long stretch of the day paints a red accusation across the
screen from morning to night. Lateness has the same shape, so it is raised the same way,
inside `proceed()`, at the moment the employee actually punches.

### Why the client mirror is worth having

It does **not** make the recorded time more accurate. An earlier draft of this spec claimed
it did; that claim was wrong and is recorded here so nobody revives it. In both designs the
employee types the reason before the punch that succeeds, so `Carbon::now()` stamps the
post-typing moment either way. Passing the first-attempt timestamp through instead would be
client-forgeable, so it is not on the table.

What the mirror actually buys:

- **Consistency.** Off-site, no-GPS and early-out are all mirrored already. A late gate
  without a mirror would be the only reason-gate on the screen that behaves differently.
- **No page reload.** The server path returns through `back()->withInput()`, which discards
  the Alpine state: the cached GPS fix in `lastFix` and any captured selfie preview are
  gone, and the employee sees the screen flash and rebuild. The mirror opens the drawer in
  place and keeps that state.
- **Fewer taps.** One punch, type, punch. The server path costs an extra failed submit.

The server gate stays regardless. It is the backstop for a submit that bypasses the client,
including a device whose clock is wrong (`lateNow()` trusts the browser clock, exactly as
`earlyNow()` already does), and `AttendanceController` already handles `needs_justification`
by flashing `attendance_justify` and re-opening the field.

### 4. Lift the grace control out of the Work from home tab

`resources/views/screens/attendance-admin.blade.php`

The field that governs lateness for the entire company currently sits inside the **Work from
home** tab, in the same form as the WFH hours (line 190). A caption underneath explains that
it applies to every arrangement, which is a sign the placement already misleads people.

Once the late gate ships, this stops being a dial nobody touches: it decides whether an
office worker is stopped for a reason at 09:01. HR retuning it after complaints will look
under Attendance, not under Work from home.

Move `late_grace_minutes` into its own small form, placed **above the tab bar** (before line
67) so it is visible on all three tabs, labelled for what it governs. The WFH form keeps the
four `wfh_*` fields and loses the grace input and its caption. No new route: the field keeps
posting to `attendance.admin.wfh-policy`.

**This move introduces a data-loss regression unless the validation changes with it.**

`updateWfhPolicy` currently ends in `$tenant->update($data)` with all five fields declared
`nullable`. Laravel's validator backfills an absent-but-nullable key as `null`, so a form
that posts only `late_grace_minutes` would write `NULL` over `wfh_work_start`,
`wfh_work_end`, `wfh_min_hours` and `wfh_radius_m`. Saving the grace would silently erase
the company's WFH hours.

This exact trap is already documented in this codebase, with the same fix, at
`app/Http/Controllers/WorkItemController.php:150-158`. Apply it here: change the four
`wfh_*` rules from `nullable` to `sometimes, nullable`, so a partial post only touches the
columns it actually carries.

Clearing a field from the UI still works after the change. The WFH form's inputs are always
present in its POST body, so blanking one sends an empty string, which
`ConvertEmptyStringsToNull` turns into a real `null`. `sometimes` only skips keys that are
genuinely absent, which is precisely the standalone grace post.

### 5. Tests, `tests/Feature/ClockServiceTest.php`

Extending the existing file, which already proves the current silent behaviour at
`test_clock_in_after_work_start_is_marked_late` (lines 148 to 158). That test passes
`$justification = null` and asserts `status: 'ok'`, so **it must be updated**, not left
alone: it encodes precisely the behaviour being removed.

New and changed cases:

| Case | Expected |
|---|---|
| Late, no reason | `needs_justification` |
| Late, reason given | `ok`, `status = late`, `late` flag, reason stored in `clock_in_justification` |
| Late but inside grace | `ok`, `status = on_time`, no reason demanded |
| Site with no `work_start` | `ok`, never gated |
| Late **and** off-site, no reason | `needs_justification` carrying the fence message, not the late one |
| On time, no reason | `ok`, unchanged, guards against gating everybody |

Migration coverage: assert that a tenant whose `late_grace_minutes` is `NULL` ends up at 15.
Under `RefreshDatabase` the migration has already run by the time the test starts, so seed a
tenant with an explicit `NULL` and run the update statement against it rather than trying to
replay the migration.

One controller test is also required by section 4: posting **only** `late_grace_minutes` to
`attendance.admin.wfh-policy` must leave `wfh_work_start`, `wfh_work_end`, `wfh_min_hours`
and `wfh_radius_m` at their existing values. Without it the regression described in that
section is invisible.

## Out of scope

Mapping the subsystem surfaced other gaps. None are addressed here; each is its own piece
of work.

- **Leave, public holidays and weekends are invisible to `ClockService`.** `ReminderTargets`
  already skips all three (`isNonWorkingDay()`, `employeeIdsOnLeave()`) so the nudge stays
  quiet, but the clock itself does not ask. An employee on approved leave who punches on a
  public holiday is marked late and, once this ships, will be asked to justify a day they
  were never due to work. **This is the gap most likely to be felt first.**
- No "absent" concept: a day with no punch produces no row, so a report cannot separate
  absent from on-leave from not-yet-arrived.
- A forgotten clock-out older than one day is never found again and stays open forever
  (`clockOut()` bounds its lookup to yesterday-or-today).
- `status = 'pending'` exists in the enum but no code path writes it.
- Hybrid staff with an empty `hybrid_office_days` are treated as home every day.
- A branch or home with no coordinates disables the geofence silently.

## Success criteria

1. A late punch with no reason is refused, and the screen opens the reason field with the
   server's message.
2. A late punch carrying a reason saves with `status = late`, the `late` flag, and the text
   in `clock_in_justification`.
3. A punch inside the 15-minute grace is untouched: on time, no reason asked.
4. A director is gated identically to an employee.
5. A late punch made through the screen opens the reason drawer in place, with no page
   reload and no failed submit, and the punch carries the reason on its first successful
   POST.
6. The grace control is visible on the Attendance Setup screen without opening the Work from
   home tab, and saving it leaves the WFH hours untouched.
7. `php artisan test --compact tests/Feature/ClockServiceTest.php` passes, along with the
   `AttendanceAdmin` controller test covering criterion 6.
