# Team status → the chase card

Replaces the `This week — team status` card at the top of `/app/timesheet-reports` with
the roster block from the **Chase** variant of `public/_timesheet-report-revamp.html`
(`?v=1`), scaled down to sit under the Lens screen rather than lead it.

## Why

The card was left untouched by the Lens plan and is now the messiest thing on the screen.

- **It is a wall of grey.** Twelve identical chips reading `PENDING`, wrapping over three
  rows. Nothing ranks them, so the reader cannot tell who to start with. The code comment
  above it predicted exactly this and tried to avoid it by hiding the `done` chips; that
  only helps when most people are done, and at the start of a week nobody is.
- **The only real number is the smallest text on the card.** `0 / 12 done` is the fact HR
  opens the card for, set in 12.5px grey beside a 13.5px heading.
- **It is a dead end.** A chip cannot be clicked, cannot be nudged, and does not link to the
  week it is complaining about. HR reads a name here and then goes hunting elsewhere.
- **It never says when the week locks.** `TimesheetCompliance::deadline()` is Friday 19:00
  and drives the `late` status, so the card judges people against a clock it will not show
  them.
- **`late` and `pending` are drawn almost identically** — an 8px dot changes colour and the
  uppercase word changes. Those are two different problems: one is overdue, the other is
  simply not finished yet.
- **It is visually foreign to the screen it now sits on.** A `uj-card` with inline styles
  above a `uj-tr-` shelf, chips styled with a `var(--surface-2, #f3f4f6)` fallback that
  exists nowhere else in the token set.
- **It ignores data scope.** `reportData()` scopes every other figure through
  `DataScope::visibleEmployeeIds()`; `tsRoster` is built from the whole tenant
  (`TimesheetController.php:490` against `:398`). A branch- or department-scoped viewer sees
  every employee's name and compliance status. This plan fixes it, because it is a
  correctness bug inside the code being rewritten.

## The direction

Three facts and one action, in that order: how many sheets are in, when the week locks, and
who is missing — each of those a row you can act on rather than a chip you can only read.

Sorted worst first: overdue before unfinished, then least-filled before most-filled, so the
top of the list is always where to start. Everyone in means the list disappears and the card
becomes one green line.

## Shared decisions

Decided. Do not re-open them.

- **Reuse the `uj-tr-` system.** Card is `uj-tr-card`; rows, clock line and buttons take new
  `uj-tr-owe`, `uj-tr-clock` classes ported from the Chase variant. No inline `<style>`, no
  `var(--surface-2)` — that token does not exist in `app.css`.
- **The card keeps its position** at the top of the screen, above the shelf, and stays
  collapsible with its current `x-data="{ open: true }"` pattern.
- **Progress is days, not percent-of-percent.** A person's progress this week is
  `filled / expected` **weekdays**, where a weekday is filled when its entries total 100%
  (±0.01) and expected excludes days locked by approved leave or a public holiday. Showing
  `2/5 days` is honest in a way a single percentage is not, because the underlying capture
  screen works day by day.
- **Only people who owe get a row.** A `done` person is a number in the lead line, not a row.
  That is the existing intent and it stays; what changes is that the rows are now useful.
- **Cap the list at 8 rows** with a `+N more` disclosure that reveals the rest. Thirty staff
  must not reproduce the wall this plan exists to remove.
- **A nudge is the same bell the cron sends.** `AppNotification::send($userId, …)` with the
  title and body already used by `App\Console\Commands\TimesheetReminder`, deduped with a
  key of `timesheet-nudge:{employeeId}:{weekStart}` so one person cannot be bell-spammed
  twice for the same week. Do not add mail, do not add a new notification type.
- **Nudging is authorised by the click, and gated by the screen.** The route requires the
  same management/HR role as the screen (`AppController::screen`, landed in `eaa47ee`) and
  refuses an employee outside the caller's data scope.
- **`late` and `pending` are visually distinct**, not two shades of grey: overdue rows carry
  a red-tinted stamp naming how overdue, unfinished rows carry an amber progress bar.
- **Bilingual.** Every label carries EN and BM through `$store.ui.lang`, per the screen's
  existing pattern. Strings are given per task.
- **The deadline is stated in full** — `Locks Friday 31 Jul, 19:00` before it passes,
  `Locked Friday 19:00 · 3 days ago` after. Never a bare relative time.

---

## Task 1 — Compliance and controller: progress, scope, and the nudge route

**Files:** `app/Timesheet/TimesheetCompliance.php`,
`app/Http/Controllers/TimesheetController.php`, `routes/web.php`,
`tests/Unit/TimesheetComplianceTest.php`, `tests/Feature/TimesheetChaseCardTest.php` (new).

### `roster()` gains progress and takes a scope

`TimesheetCompliance::roster(Tenant $tenant, CarbonInterface $weekStart, ?array $visibleIds = null)`.
When `$visibleIds` is not null, restrict the employee query with `whereIn('id', $visibleIds)`.
Null keeps today's behaviour, so the other two callers
(`BuildsDashboardData.php:121` and `:657`) are unaffected and need no edit.

Each row grows from `['employee', 'status']` to:

```php
[
  'employee' => Employee,
  'status' => 'done'|'pending'|'late',
  'filledDays' => int,      // weekdays whose entries total 100% (±0.01)
  'expectedDays' => int,    // weekdays not fully locked by leave or a holiday
  'lastTouched' => CarbonImmutable|null,  // the sheet's updated_at, null with no sheet
]
```

`expectedDays` comes from the `$lockedByEmployee` map `roster()` already builds through
`forWeekMany()` — a weekday whose locked percentage is 100 is not expected. Do not add a
query; the sheets and their entries are already eager-loaded.

`filledDays` counts weekdays at 100% using the same tolerance as
`weekdaysComplete()`. Factor the shared per-day arithmetic into one private helper rather
than writing the ±0.01 comparison twice.

### `reportData()` passes the scope

Call `roster($tenant, $weekStart, $visibleIds)` with the `$visibleIds` the method already
computes at the top. That is the scope-leak fix.

Also add to the returned array `'tsDeadline'` — the `deadline($weekStart)` value — and
`'tsWeekStart'`, the current week's Monday as an ISO string, so the view can render the
clock and post the nudge without recomputing either.

### The nudge route

`POST /app/timesheet-reports/nudge/{employee}`, name `timesheet.reports.nudge`, in the same
authenticated tenant group as the other `/app` POST routes in `routes/web.php`. A new
`TimesheetController::nudge(Request, Employee)` method that:

1. `abort_unless` the acting tenant role is `management` or `hr` — same gate as the screen.
   Use `authorizeTenantRole`, which already collapses `director` to `management`.
2. `abort_unless` the employee's `tenant_id` matches the current tenant. Route-model binding
   resolves across tenants in this app, so this check is the tenant boundary, not decoration.
3. `abort_unless` the employee id is inside `DataScope::visibleEmployeeIds()` for the caller,
   when that returns a list.
4. `abort_if` the employee has no `user_id` — there is nobody to notify.
5. Sends `AppNotification::send($employee->user_id, $title, $body, route('app.screen', 'timesheets'), "timesheet-nudge:{$employee->id}:{$weekStart}")`,
   reusing `TimesheetReminder`'s exact title and body strings. Lift them to public constants
   on that command and reference them, rather than copying the text into a second place.
6. Redirects back with a flash the view can show.

**Tests.** `TimesheetComplianceTest` (unit): a person with three of five weekdays at 100%
reports `filledDays: 3, expectedDays: 5`; a person with an approved whole-day leave on the
Monday reports `expectedDays: 4`; a person with no sheet reports `filledDays: 0` and
`lastTouched: null`; passing `$visibleIds` restricts the roster and passing null does not.

`TimesheetChaseCardTest` (feature): HR can nudge and an `app_notifications` row appears for
that user; nudging twice for the same week creates only one row (the dedupe key); a
`manager` gets 403; an employee in another tenant gets 403; an employee with no `user_id`
gets 422; a scoped viewer's roster excludes an employee outside their scope.

---

## Task 2 — The card

**Files:** `resources/css/app.css`,
`resources/views/screens/timesheet-reports.blade.php`,
`tests/Feature/TimesheetChaseCardTest.php`.

Port `uj-tr-owe` and `uj-tr-clock` from the Chase variant of the mock into the existing
`uj-tr-` block. `uj-tr-owe` is a three-column grid — who, progress, actions — collapsing to
one column under the existing `max-width: 640px` media query. No new tokens.

Replace the card's whole body. Keep the wrapper `x-data="{ open: true }"` collapse and the
heading row.

**Lead line**, replacing `0 / 12 done` as the card's smallest text: `3 of 12 sheets in`,
with the count in mono at the shelf-chip weight, and the deadline immediately after —
`Locks Friday 31 Jul, 19:00` while it is future, `Locked Friday 19:00 · 3 days ago` once
past, taken from `tsDeadline`.

**One row per person who owes**, worst first — `late` before `pending`, then fewest
`filledDays` first, then by name:

- Who: avatar (`uj-tr-av`, the employee's initials and colour), name, position title.
- Progress: `2 of 5 days at 100%` in mono above a `uj-tr-bar` filled
  `filledDays / expectedDays`. Amber for `pending`, red for `late`. A person with zero filled
  days gets the bar's empty track and the words `Nothing yet`, not a 0% bar that reads as a
  rendering fault.
- An overdue row also carries a `uj-stamp` with `data-tone="red"` reading `Overdue`.
- `lastTouched` beneath the progress when present: `Saved Tue 28 Jul, 18:22`. When absent:
  `No draft yet`.
- Actions: a `Remind` button posting to `timesheet.reports.nudge`, and an `Open week` link to
  `route('app.screen', ['screen' => 'timesheets', 'week' => $tsWeekStart])`. After a
  successful nudge the button returns disabled reading `Reminded`, driven by the flash and
  the dedupe state, not by client-side optimism.

**Cap at 8 rows**, with `+N more` revealing the rest through Alpine, no reload.

**Everyone in**: no rows, no bars. One line — `Every sheet is in for this week.` — beside a
`uj-stamp` with `data-tone="success"`.

Copy, EN then BM, verbatim:

| EN | BM |
|---|---|
| This week — team status | Minggu ini — status pasukan |
| sheets in | lembaran masuk |
| Locks | Tutup |
| Locked | Ditutup |
| ago | lalu |
| of 5 days at 100% | daripada 5 hari pada 100% |
| Nothing yet | Belum ada apa-apa |
| Overdue | Lewat |
| Saved | Disimpan |
| No draft yet | Belum ada draf |
| Remind | Ingatkan |
| Reminded | Sudah diingatkan |
| Open week | Buka minggu |
| more | lagi |
| Every sheet is in for this week. | Semua lembaran sudah masuk untuk minggu ini. |

Two copy rules:

- **Overdue is not the same as unfinished.** Never render them in one grey style, and never
  call an unfinished sheet late before the deadline has passed.
- **A nudge is a reminder, not an accusation.** Reuse the cron's wording; do not write
  sharper copy for the manual path than the automatic one uses.

**Tests** (`TimesheetChaseCardTest`): the card renders a row for a person who owes and no
row for a person who is done; the lead line shows the in-count; the deadline string renders;
a person with no entries renders `Nothing yet` and not `0 of`; with everyone done the
all-in line renders and no `uj-tr-owe` row does; `PENDING` no longer appears anywhere on the
screen.

---

## Verification

`php artisan test --compact` over the **entire** suite. `TimesheetReminderTest` asserts on
this card's heading and on a pending person's name, and `WorkforceInsightsTest` plus the
dashboard tests call `roster()` through other paths — the optional third argument is what
keeps them passing, so if any of them fails, the signature is wrong rather than the test.

`vendor/bin/pint --dirty --format agent`.

Assets and browser verification are the orchestrator's: `lerd artisan view:cache` then
`bun run build`, then drive the card at 1280px and 375px as `hr@amanahku.test`, including an
actual nudge and its `Reminded` state.
