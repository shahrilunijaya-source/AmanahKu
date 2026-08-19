# Attendance work-mode pill: declare a site visit instead of being flagged for one

**Date:** 2026-08-19
**Status:** design approved, not implemented

## Problem

A staff member who spends the day at a customer's premises clocks in from outside their
branch geofence. `ClockService` reads that as an anomaly: the punch is stopped for a typed
justification (app/Attendance/ClockService.php:59), then stamped `out_of_radius_in`
(app/Attendance/ClockService.php:93) and rendered in the report as a red **"Off-site in"**
badge (resources/views/screens/attendance-report.blade.php:10).

Nothing is wrong with the punch. The work was planned, the customer was expected, and the
employee did exactly what they were asked to do. The system has no way to hear that, so it
files planned work under the same heading as a punch from a car park. The employee types a
defence every time; the manager reads a violation that isn't one.

The site *type* already exists — `office`, `client`, `home` — but it is set by HR on the
employee profile (`work_arrangement`) and describes a standing arrangement, not today. There
is no per-day expression of intent anywhere in the system.

## Decision

Add a two-option **segmented pill** above the clock button. The employee declares the shape
of their day before punching; the punch itself is unchanged.

| | EN | BM |
|---|---|---|
| default | **Office / Home** | Pejabat / Rumah |
| | **Site visit** | Lawatan tapak |

| Question | Decision |
|----------|----------|
| How many options? | **Two.** Not office/home/site-visit as three. Office vs home is already resolved from the employee's arrangement and must not become a staff choice — see "Why home is not an option". |
| Default? | **Office / Home.** Zero extra taps for the ordinary day. |
| What does Site visit cost? | **A typed destination, required.** Reuses the existing justification field with a different label. |
| Does it remove the off-site flag? | **Yes, for that punch only.** Office/Home mode keeps every check exactly as today. |
| Does it change working hours? | **No.** Late is still late; min-hours unchanged. |
| Does it exempt the selfie or GPS? | **No.** Both still mandatory, both modes. |
| Who may pick it? | **Everyone.** It costs a destination and a GPS pin, so it is not free. |
| Approval flow? | **None.** Declared, not requested. |
| Clock-out behaviour? | **Sticks to the clock-in choice, still switchable.** |
| New table? | **No.** Two columns on `attendance_records`. |

## Why this is not "staff pick their site from a list"

`ScheduleResolver::matchActualSite` carries a deliberate refusal
(app/Attendance/ScheduleResolver.php:40):

> Staff never pick their site from a list — being near a company location is not the same as
> working there, and a free choice would let anyone living near any branch clock "on-site"
> forever.

That decision stands and is not reversed here. The pill declares **intent**, not **location**.
It never selects a geofence, never names which branch you are at, and never changes which site
your GPS is measured against. `matchActualSite` runs untouched in both modes.

The distinction that keeps the trust model intact: **the mode changes the framing, never the
evidence.** A declared site visit still records GPS, still requires a selfie, still writes a
typed line to the record, and is still visible to the manager on the same row it always was.
What changes is that the row says *"Site visit — Customer ABC, Shah Alam"* in blue instead of
*"Off-site in"* in red.

Removing the destination requirement would break this. A pill that costs nothing is a
one-tap exemption from the geofence, and it is precisely what the docblock above warns
about. The destination text is the price, and it is not optional.

The docblock gains a short note pointing at this spec, so the next reader sees the nuance
rather than a contradiction.

## Why home is not an option

The pill deliberately does **not** offer "Home" as a separate choice.

`ClockService::clockIn` registers and permanently locks an employee's home coordinates on
their first home-day punch when none are on file (`needsHomeCapture` → `home_locked_at`,
app/Attendance/ClockService.php:41-48). If an office-arrangement employee could declare
"Home", their first such punch — from a café, a relative's house, anywhere — would become
their locked home address for good.

Ad-hoc work-from-home is a real gap, but it is a different feature with a different cost
(who approves it, does it consume anything, what happens to the locked home). Out of scope
here, listed below.

## Design

### The pill

Rendered in the clock shelf above the punch button, bilingual like everything else on the
screen. Bound to Alpine state, mirrored onto a hidden `work_mode` form input alongside the
existing hidden `action` input (resources/views/screens/attendance.blade.php:757).

Picking **Site visit** opens the existing reason box immediately (not on submit), relabelled:

| Mode | Label EN | Label BM |
|------|----------|----------|
| Office / Home | Reason | Sebab |
| Site visit | Where are you going? | Ke mana anda pergi? |

The box is already there, already 500 chars, already flows to the same column. Nothing new
is built for it; only the label and the trigger change.

### Clock-out

The pill's initial state on load comes from the open record's `work_mode`, so a day declared
as a site visit stays declared. It remains tappable: someone who started at the office and
ended at a customer flips it before clocking out and types where they were. Someone who came
back flips it the other way.

The two punches are recorded independently, which the table already does for location,
selfie and justification.

### Rules that change

Exactly two gates, both in `ClockService`, both only when the mode says site visit:

1. **The radius condition stops demanding a justification.** On clock-in that is the whole
   gate (app/Attendance/ClockService.php:59). On clock-out the gate is a three-way
   `outRadius === false || early || short` (app/Attendance/ClockService.php:159) and **only
   the radius term is dropped** — leaving at 3pm is still early, and a short day is still
   short, site visit or not.
2. **A destination gate replaces it on clock-in.** A site visit with no typed destination is
   refused with *"Say where you are going to clock in."*
3. `out_of_radius_in` / `out_of_radius_out` are not written
   (app/Attendance/ClockService.php:93, :178). A `site_visit_in` / `site_visit_out` flag is
   written instead.

**Clock-out does not ask for the destination a second time.** If the day was already declared
a site visit at clock-in, the destination is on the record and asking again collects nothing
new. The destination gate fires on clock-out **only** when the mode was switched at clock-out
— that is, clock-in was Office / Home and the employee is now declaring a visit that was not
declared in the morning. That case genuinely has no text on the record yet.

Everything else in both methods is untouched: the late gate, the early gate, the short-hours
gate, the no-location gate, the mandatory selfie, `in_radius`/`out_radius` (still recorded
truthfully — the fence result is a fact, and suppressing it would blind the report), and the
midnight-crossing clock-out lookup.

Note the ordering consequence: someone on a site visit who is also **late** still hits the
late gate, and the one line they type covers both. This mirrors how the existing off-site and
late gates already share a single reason box.

### Rules that do not change

- Selfie mandatory on every punch, both modes.
- GPS still required. A site visit with no location fix still costs a reason and still gets
  the permanent `no_location` flag.
- Working hours stay those of the employee's own arrangement. Visiting a customer on 08:30
  hours does not make a 09:00 office worker late — the existing `matchActualSite` rule.
- Office / Home mode behaves exactly as today, byte for byte.

### Declared site visit inside a fence

Allowed, and produces no contradiction. If the GPS lands inside a configured branch or client
fence, `within()` returns true, so no radius condition arises in either mode. The record
carries the site visit mode and its destination text; the manager reads both. Not worth
blocking — someone starting a customer trip from the office lobby is ordinary.

### The report

`$flagLabel` (resources/views/screens/attendance-report.blade.php:8) gains two entries:

```
'site_visit_in'  => ['Site visit', 'Lawatan tapak'],
'site_visit_out' => ['Site visit out', 'Lawatan tapak (keluar)'],
```

styled in the informational colour, not the red used for violations.

**The 📍 map must follow the new flag.** The map control is gated on `out_of_radius_in` /
`out_of_radius_out` (resources/views/screens/attendance-report.blade.php:149, :158). A
declared site visit is exactly the punch a manager most wants to plot, so both gates widen
to `out_of_radius_* OR site_visit_*`. The null-coordinate guards already on those lines stay
— a `site_visit` punch with no fix plots nothing, same as today.

### Every surface that renders the accusation

The off-site verdict is written in five places, not one. All five must learn the new mode or
the pill removes a red badge in the report while the same accusation survives everywhere
else. Enumerated so none is missed:

**1. The punch sheet — the loudest one.** The screen pre-computes `offSite` locally before
submitting (resources/views/screens/attendance.blade.php:286) and gates the sheet on it via
`isReq()` (:207). The sheet then reads *"Off-site clock in"* (`sheetTitle`, :518) over
*"You appear to be outside the expected location… Your manager sees it flagged"* (`sheetBody`,
:539 EN / :545 BM), and prompts *"Why are you clocking in from off-site?"* (:555 / :561).

Under site visit every one of those is wrong, and the body line is now factually false at the
most prominent moment in the flow. So: the local off-site pre-gate becomes a **destination**
pre-gate (is the text filled), and heading, body and prompt get site-visit copy in both
languages — *"Site visit"* / *"Lawatan tapak"*, *"Tell your manager where you are going. Your
selfie and location are still recorded."* The client mirrors `ClockService`, as it already
does for every other gate.

**2. The employee's own attendance screen.** Its own label map at
resources/views/screens/attendance.blade.php:10-11 is separate from the report's. Without the
two new entries the employee sees a raw `site_visit_in` string on their own record.

**3. The day partial's row tone.** `$redFlags` (resources/views/partials/attendance-day.blade.php:8)
paints a row red on `out_of_radius_*`. A declared visit no longer carries that flag, so the row
already stops going red — correct, and no change needed. `site_visit_*` is deliberately **not**
added to that list.

**4. The day partial's geofence sentence — the subtle one.** Line :59 reads
`$r->in_radius === false` **directly, not the flag**. Since `in_radius` stays truthful (a site
visit outside the fence really is outside it, and suppressing that would blind the report), this
sentence would still print *"Clock landed outside the expected geofence"* for a punch the app
just told the employee was fine. Under `work_mode = 'site_visit'` it reads instead:
*"Site visit — {destination}."* / *"Lawatan tapak — {destination}."*

**5. The report.** Labels and map gates, below.

### The location badge

The screen already computes a live fence badge on load — *"At Unijaya HQ · 42m"*,
*"Off-site · 1.2 km away"*, *"checking location…"* (resources/views/screens/attendance.blade.php:134-172,
fenceText at :220). Three defects surface once the pill exists:

| Defect | Fix |
|--------|-----|
| Reads once on page load. A page opened in the car still says off-site after walking in, and there is no way to re-read short of reloading. | Badge becomes tappable. Tap re-runs `bestFix` and repaints. Reuses the existing acquisition path; no new geolocation code. |
| On a non-refusal failure (timeout, unavailable) `fenceStatus` is set to `'none'` and the badge disappears with no message (resources/views/screens/attendance.blade.php:456). | A fourth state: *"Location not found — tap to check"*. The `'none'` state stays for the genuinely fence-less case, where there is nothing to report. |
| Under Site visit, *"Off-site · 1.2 km away"* in red is the accusation the pill exists to remove. | Site visit mode renders the distance without the verdict: *"1.2 km from office"*, informational colour. The distance is still useful; the judgement is not. |

A tappable badge, not a new button. The screen has one primary action and adding a second
control beside it is what produced the "I clocked in twice" confusion documented at
resources/views/screens/attendance.blade.php:352-355.

## Data

One migration, two nullable columns on `attendance_records`:

```php
$table->string('work_mode')->nullable();             // 'office_home' | 'site_visit'
$table->string('clock_out_work_mode')->nullable();
```

Nullable and paired in/out, matching the shape the table already uses throughout
(`latitude`/`clock_out_latitude`, `photo_path`/`clock_out_photo_path`,
`clock_in_justification`/`clock_out_justification`). Null means a record written before this
change — read as Office / Home, never back-filled.

A dedicated column rather than an entry in the `flags` JSON: "show me every site visit this
month" is a query HR will ask, and a JSON search in MySQL for it is the wrong tool. The flag
entries exist for the badge; the column exists for the question.

The destination text goes in the existing `clock_in_justification` /
`clock_out_justification` columns. Same length, same place in the report, same privacy
handling. `work_mode` is what tells a reader whether that text is a defence or an itinerary.

`type` (`standard` | `wfh` | `client` | `overtime`) is **not** touched. A resident engineer
permanently assigned to a client site and an office worker visiting one for an afternoon are
different things, and collapsing both into `type='client'` would lose that. `work_mode` is
the new axis; `attendanceType()` keeps deriving `type` from the resolved site as it does now.

## Validation

`AttendanceController::validateClock` (app/Http/Controllers/AttendanceController.php:136)
gains:

```php
'work_mode' => ['nullable', 'in:office_home,site_visit'],
```

Nullable so an older cached page, or a client that omits the field, still punches — treated
as Office / Home, the behaviour that exists today.

The destination requirement is enforced in `ClockService`, not in validation, because it is a
business gate that returns `needs_justification` and re-opens the box, the same path the
existing off-site and late gates use. Enforcing it as a validation rule would produce a bare
error bag instead and lose the sheet.

`AttendanceAttempt::record` is unchanged — the mode does not alter what an attempt row means.

## Testing

| Case | Expectation |
|------|-------------|
| Office/Home mode, inside fence, on time | Unchanged from today: clean record, no flags |
| Office/Home mode, outside fence, no reason | Unchanged: `needs_justification`, then `out_of_radius_in` |
| Site visit, outside fence, destination typed | Clocks in; `work_mode='site_visit'`; `site_visit_in` flag; **no** `out_of_radius_in` |
| Site visit, outside fence, destination blank | `needs_justification` with the "say where you are going" message |
| Site visit, outside fence, destination whitespace only | Refused — `filled()` already trims |
| Site visit, no selfie | `needs_photo`, unchanged |
| Site visit, no GPS fix | Still `no_location` flag, still needs a reason |
| Site visit, **late** | `late` flag still written, one reason covers both gates |
| Site visit, GPS inside a fence | Clocks in, `in_radius=true`, mode and text recorded, no contradiction |
| Clock in Office/Home, clock out Site visit | Two independent modes on one record; only the out punch carries `site_visit_out` |
| Clock in Site visit, clock out with pill untouched | Clock-out inherits site visit; destination **not** asked again |
| Clock in Office/Home, switch to Site visit at clock-out, no text | Refused — this punch has no destination on the record yet |
| Site visit, clocking out before shift end | `early_out` still fires and still needs a reason |
| Record with null `work_mode` (pre-migration) | Reads as Office / Home everywhere; report renders as it does today |
| `work_mode` absent from the request entirely | Punch succeeds as Office / Home |
| `work_mode` set to junk | Validation rejects |
| Report: site visit punch, HR viewer | Blue "Site visit" badge, destination text, 📍 map available |
| Report: site visit punch with no coordinates | Badge and text, **no** map control, nothing broken |
| Badge tapped after moving | Re-reads location and repaints the distance |
| Badge under Site visit mode | Distance shown, no "Off-site" verdict, informational colour |
| Punch sheet under Site visit | Never says "off-site" or "flagged", EN or BM; asks for the destination |
| Employee's own attendance screen, site visit record | Reads "Site visit", not a raw `site_visit_in` string |
| Day partial, site visit outside the fence | Row is not red; sentence reads "Site visit — …", not "outside the expected geofence" |

## Out of scope

- **Ad-hoc work from home.** The real second half of the original idea, deliberately deferred
  — see "Why home is not an option". Needs its own decision on the home-lock.
- **Approval or pre-declaration.** No manager sign-off, no declaring tomorrow's site visit in
  advance. Declared at the punch, full stop.
- **Which customer, from a list.** The destination is free text. A customer register exists
  (`work_sites`) but is for geofenced resident-engineer sites, not the ad-hoc visit this
  serves. Revisit only if the free text proves unreadable in reports.
- **Reporting on site-visit patterns.** "Who is on site visit every Friday" is a reports
  feature, not this one. The column makes it answerable later.
- **Mileage, travel claims, or anything else hanging off a declared visit.** Claims are a
  separate module and stay that way.
