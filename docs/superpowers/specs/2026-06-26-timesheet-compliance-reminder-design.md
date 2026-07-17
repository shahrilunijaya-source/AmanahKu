# Timesheet Compliance & Friday Reminder — Design

**Date:** 2026-06-26
**Branch:** feat/full-i18n
**Status:** Approved (design), pending spec review

## Problem

Staff forget to fill their weekly timesheet. We want:

1. A reminder every **Friday 5:00 PM** telling staff to update their timesheet.
2. **All active staff** are expected to keep their timesheet up to date.
3. **Everybody** can see who is and isn't done — full transparency, social pressure by design.

## Locked decisions

| Decision | Choice |
|----------|--------|
| Reminder delivery | In-app **bell push** at Fri 5pm (via cron) **+** passive **overdue banner** fallback computed on page load |
| Board visibility | **All staff** see the compliance board (no cost/RM data shown) |
| "Done" definition | Every weekday **Mon–Fri totals 100%** (±0.01). Submit-click NOT required. |
| Scheduler | Real cron runs `php artisan schedule:run`; Fri-5pm command will fire |
| Migrations | **None** — all status derives from existing `timesheets` + `timesheet_entries` |
| Timezone | App default `Asia/Kuala_Lumpur` (config/app.php) |

## Grounding (existing code this builds on)

- **Week key:** `timesheets.week_start` = Monday (`Carbon::now()->startOfWeek()`). One timesheet per `(employee_id, week_start)`.
- **Per-day total rule:** `TimesheetController::assertDayTotals()` ([TimesheetController.php:564](../../../app/Http/Controllers/TimesheetController.php#L564)) — each `entry_date` sums `percentage` to 100% (±0.01). We replicate this rule in the new service (do not couple to the private controller method).
- **Sidebar hook:** `AppController::quickActions()` ([AppController.php:143](../../../app/Http/Controllers/AppController.php#L143)) runs on **every** screen and already loads the current-week timesheet for the sidebar `qaTsPct` tile. The overdue flag is added here.
- **Bell notifications:** `AppNotification::sendMany(array $userIds, string $title, ?string $body, ?string $url)`. Employee → `user_id`.
- **Scheduler:** `bootstrap/app.php` `->withSchedule()` ([bootstrap/app.php:16](../../../bootstrap/app.php#L16)) — already registers `leave:accrue`, `leave:carry-forward`, `digest:weekly`. Add the reminder here.
- **i18n:** branch `feat/full-i18n`; screens carry EN + BM (`name` / `name_ms`) strings. All new user-facing text is bilingual.

## Architecture

### 1. `TimesheetCompliance` service — single source of truth

`app/Timesheet/TimesheetCompliance.php` (`declare(strict_types=1)`, PSR-12, typed).

Pure read-only logic. Consumed by the command, the banner hook, and the board. No state, no writes.

```php
final class TimesheetCompliance
{
    // Monday 00:00 of $ref's week.
    public function weekStart(CarbonInterface $ref): CarbonImmutable;

    // That week's Friday 17:00 (app timezone) — the deadline.
    public function deadline(CarbonInterface $weekStart): CarbonImmutable;

    // True when every weekday Mon–Fri of $weekStart sums to 100% (±0.01).
    // A week with no timesheet, or any weekday < 100%, is incomplete.
    public function isComplete(Employee $employee, CarbonInterface $weekStart): bool;

    // Convenience for the banner: not complete AND now() >= deadline.
    public function isLate(Employee $employee, CarbonInterface $weekStart): bool;

    // Every active, eligible employee in $tenant → status for $weekStart.
    // Returns rows: ['employee' => Employee, 'status' => 'done'|'pending'|'late'].
    // Sorted late → pending → done, then name.
    public function roster(Tenant $tenant, CarbonInterface $weekStart): Collection;

    // Eligible employees who are NOT complete (drives the bell push).
    public function pending(Tenant $tenant, CarbonInterface $weekStart): Collection;
}
```

**Status rules (per employee, per week):**
- `done` — week complete (all Mon–Fri = 100%).
- `pending` — not complete AND `now() < deadline(weekStart)`.
- `late` — not complete AND `now() >= deadline(weekStart)`.

**Eligibility (who is counted):**
- Employee `status = active`.
- AND (`joined_at` is null OR `joined_at <= weekStart`) — a person who joined mid-week or later is not marked red for a week they weren't around for.

**Completeness computation:** load the `(employee, week_start)` timesheet with entries; group `percentage` by `entry_date`; the week is complete only if **all five weekdays** Mon–Fri are present and each sums to 100% (±0.01). Weekend entries are ignored (do not block, do not require). No timesheet row at all = incomplete.

**Performance:** `roster()`/`pending()` must avoid N+1 — eager-load each employee's current-week timesheet entries in one query keyed by `employee_id` (e.g. `Timesheet::with('entries')->where('week_start', …)->whereIn('employee_id', …)`), then evaluate in memory.

### 2. Friday 5pm bell push — scheduled command

`app/Console/Commands/TimesheetReminder.php`, signature `timesheet:remind`.

- For each tenant: `$compliance->pending($tenant, $compliance->weekStart(now()))`.
- Collect those employees' `user_id`s; `AppNotification::sendMany($userIds, $title, $body, $url)`.
  - Title (EN): `Timesheet reminder` / (BM): `Peringatan timesheet`.
  - Body: `Your timesheet for this week isn't fully filled in. Please complete it.` / BM equivalent.
  - URL: timesheets screen route.
- Skip tenants with zero pending (no empty notifications).
- **Idempotent / safe on double-fire:** re-running sends at most one fresh unread reminder per user per run; acceptable if cron retries. (No dedupe table — a duplicate bell is harmless and self-clears when read.)
- Register in `bootstrap/app.php` `->withSchedule()`:
  ```php
  $schedule->command('timesheet:remind')->fridays()->at('17:00');
  ```
  (Runs in app timezone `Asia/Kuala_Lumpur`.)

### 3. Passive overdue banner — no-cron fallback

- In `AppController::quickActions()` (already global, already loads current-week timesheet), add:
  `'qaTsOverdue' => $tsEnabled && $employee ? $compliance->isLate($employee, $weekStart) : false`
  where "late" = past Fri 5pm deadline AND current week not complete. (Implement `isLate()` as a thin wrapper over `isComplete()` + `deadline()`, or compute inline from the already-loaded data to avoid a second query.)
- In `resources/views/layouts/app.blade.php`, render a **red, dismissible** banner when `$qaTsOverdue` is true: short bilingual message + link to the timesheets screen.
- The banner shows **only for the signed-in user when they personally are `late`**. It auto-clears the instant they reach 100% for the week, or when the week rolls over to Monday (new `week_start`, fresh `pending` state).
- Dismiss is per-session/page (client-side); it reappears next page load while still late (intentional nag).

### 4. Compliance board — "This week — team status"

Folded into the existing timesheets screen ([resources/views/screens/timesheets.blade.php](../../../resources/views/screens/timesheets.blade.php)) as a **collapsible section at the top** — no new route, no new nav item.

- Data: `TimesheetController::screenData()` adds `$tsRoster` = `$compliance->roster($tenant, $weekStart)` for the selected week (reuses the screen's existing week picker).
- Render: a counts header (e.g. `12 / 15 done`) + a list/grid of every eligible employee with a status pill: **Done** (green) / **Pending** (grey) / **Late** (red). Name only — **no RM/cost/days**, safe for all-staff visibility.
- Visible to **all** staff who can open the timesheets screen (everyone with an employee record). No role gate.
- Bilingual EN/BM labels and section title.

### 5. Data / migrations

**None.** No schema change. Status is derived at read time from `timesheets` + `timesheet_entries`.

## Testing

**Unit — `TimesheetCompliance` (no HTTP):**
- complete week (all 5 days 100%) → `done`.
- one weekday at 80% → incomplete; before deadline → `pending`; after deadline → `late`.
- no timesheet row → incomplete.
- weekend entries present but a weekday missing → still incomplete (weekend ignored).
- new joiner (`joined_at` after `weekStart`) → excluded from roster/pending.
- inactive employee → excluded.
- `deadline()` returns Friday 17:00 in app timezone.

**Feature — command `timesheet:remind`:**
- sends bell only to pending employees; complete employees get nothing.
- zero pending → no notifications, no error.

**Feature — board + banner:**
- board renders for a plain staff user (no role gate) and shows no cost columns.
- `qaTsOverdue` true only after Fri 5pm when current user's week is incomplete.

Run: `vendor/bin/phpunit` (or Pest if configured). Keep unit tests DB-light via factories.

## Out of scope (YAGNI)

- Leave/holiday-aware exemptions (a day on approved leave still expected to total 100%, e.g. via a Leave category) — future.
- Email reminder (bell + banner chosen; email can be added later mirroring `digest:weekly`).
- Per-user reminder mute/preferences.
- Historical compliance trends / reporting.

## File manifest

| File | Action |
|------|--------|
| `app/Timesheet/TimesheetCompliance.php` | new — service |
| `app/Console/Commands/TimesheetReminder.php` | new — `timesheet:remind` |
| `bootstrap/app.php` | edit — add Friday 17:00 schedule line |
| `app/Http/Controllers/AppController.php` | edit — `quickActions()` adds `qaTsOverdue` |
| `app/Http/Controllers/TimesheetController.php` | edit — `screenData()` adds `$tsRoster` |
| `resources/views/layouts/app.blade.php` | edit — overdue banner |
| `resources/views/screens/timesheets.blade.php` | edit — team-status board section |
| `lang/*` (or inline `@lang`) | edit — EN/BM strings |
| `tests/Unit/TimesheetComplianceTest.php` | new |
| `tests/Feature/TimesheetReminderTest.php` | new |
