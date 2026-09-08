# Session S15 handoff: CR-17 (Management view on the dashboard: lateness and overdue tasks)

## Delivered

- Dashboard `management` band (director, management, hr) now shows two live panels inside
  the existing slot: lateness (`data-panel="lateness"`, one `data-late-row` per active
  standard-site employee today, `Late Hh MMm` / `On time` / `Not clocked in` / `Unverified`)
  and overdue work (`data-panel="overdue"`, grouped by `data-overdue-owner`, one `data-card`
  row per overdue card, `N days overdue`, nudge action). Verified by acceptance items 1, 5,
  6, 7, 9, and CR32Test item 2 (exactly one `data-band=` on a quiet day) stays green.
- `GET /app/management/exceptions`: the same two panels, scoped to a branch/company-data-scope
  manager's `DataScope::teamIds()` reporting line (full depth, not just direct reports); 403
  for team-scope managers and staff. Verified by acceptance item 2 and the new 3-level-deep
  governance test.
- `POST /app/management/overdue/{card}/nudge`: one `app_notifications` row to the card's
  owner, 422 on a same-tenant-day repeat or a done card, an `AuditLog` row on the card whose
  action contains "nudge". Verified by acceptance items 3, 4.
- `POST /app/management/overdue/{card}/reassign {employee_id, reason}`: allowed for the
  owner's direct manager, the card's project `pm_id`, or management tier; HR 403; due date
  never moves (existing immutability guard); writes an `overdue_ledger` row for the old
  owner/month; notifies both owners; audits `employee_id` via the existing `AuditsChanges`
  trait. Verified by acceptance items 3, 8, plus the new cross-tenant and same-owner
  governance tests.
- `POST /app/attendance/incidents` (HR only): creates an `attendance_incidents` window;
  clock-ins whose date+time falls inside it (inclusive both ends) render `Unverified`.
  Verified by acceptance item 6 plus the new inclusive-boundary governance test.
- `management:digest` artisan command, scheduled daily 08:00: one `MailPort::send` per
  tenant to every `Permissions::FINAL_APPROVAL_ROLES` user (correctly includes `director`),
  one `app_notifications` "digest" row per recipient, idempotent per tenant per day, never
  mixes tenants. Verified by the deferred digest acceptance test and the new cross-tenant
  digest governance test.

## Schema changes

- `overdue_ledger`: `id, tenant_id, work_item_id, employee_id, month (date), days_overdue
  (unsigned int), timestamps`. Migration
  `database/migrations/2026_09_20_100000_create_management_exceptions_tables.php`.
- `attendance_incidents`: `id, tenant_id, starts_at (datetime), ends_at (datetime), note
  (text), created_by_id (nullable FK employees), timestamps`. Same migration file.
- Migrated on the dev MySQL DB via `lerd artisan migrate --no-interaction` this session.
  Verified read-only with `mysql -h127.0.0.1 -uroot amanahku -e 'describe overdue_ledger;
  describe attendance_incidents'` — both tables present with the expected columns.

## Contracts touched

- None. `docs/build/contracts/dashboard-slots.md` and `roles.md` were read, not edited; the
  band still fills only the existing `management` slot, no widget added/moved/renamed.

## Port calls stubbed

- `MailPort.send`, one row per tenant per digest run written to `port_outbox` (`kind =
  management_digest`), via the bound `StubMailPort`. Real adapter still owed, unchanged from
  every other digest in this app.

## Deferred

- Nothing from CR-17's acceptance list deferred. The digest's scheduling wiring is live
  (`bootstrap/app.php`, `dailyAt('08:00')`) but, like every other scheduled command in this
  app, only actually fires in production/staging cron, not in this dev environment.

## OPEN, decided without Shazwan

- No sidebar nav entry for `/app/management/exceptions`; reassign UI uses `window.prompt()`
  rather than a full employee picker; no separate "totals by project" overdue breakdown; the
  `?scope=staff|company` toggle lives only on the dedicated exceptions page, not the
  dashboard band. See `/OPEN.md` entry "S15 / CR-17 / dedicated exceptions page not on nav,
  no separate project breakdown, prompt()-based reassign, scope toggle page-only".
- `tests/TestCase.php` warmed with `Artisan::call('schedule:list')` in `setUp()`, fixing a
  pre-existing, suite-wide, test-order-dependent bug in how `app(Schedule::class)->events()`
  resolves under `RefreshDatabase` (unrelated to CR-17's own code, but the only way the
  frozen CR17Test digest-scheduling assertion can pass reliably). See `/OPEN.md` entry "S15 /
  CR-17 / tests/TestCase.php warmed with Artisan::call('schedule:list') to fix a pre-existing
  test-order bug in Schedule assertions".

## Requested contract change (generator may not make it itself)

- None. CR17Test's own resolution of the `dashboard-slots.md` vs `roles.md` conflict (band
  stays gated to FINAL_APPROVAL_ROLES, senior managers read a separate scoped page instead)
  was already recorded in the frozen "QA / CR-17 / shapes fixed by CR17Test" OPEN.md entry
  from a prior session; nothing new to request.

## Traps for the next session

- **Schedule/Artisan test-order bug**: any future session that adds a new scheduled command
  and writes a test asserting `app(Schedule::class)->events()` contains it, without that test
  itself calling `Artisan::call(...)` first, will see zero events if any other test ran
  earlier in the same PHPUnit process. This is now papered over suite-wide by
  `tests/TestCase.php::setUp()`'s `Artisan::call('schedule:list')` warm-up — do not remove
  that line without re-checking `tests/Feature/TimesheetReminderTest.php` and CR17Test's
  deferred digest test still pass standalone AND inside a full-suite run. Root cause is
  `Illuminate\Foundation\Configuration\ApplicationBuilder::withSchedule()`'s lazy
  `Artisan::starting()` hook combined with `RefreshDatabase` only bootstrapping Artisan once
  per process.
- **`WorkItem` route-model binding is not tenant-scoped.** Both `nudge()` and `reassign()` on
  `ManagementExceptionsController` check `$card->tenant_id === app(CurrentTenant::class)->id()`
  as their first line. Any new action added to this controller on a `{card}`-bound route must
  do the same or it will leak across tenants (`SubstituteBindings` runs before
  `ResolveTenant`).
- **`DataScope::teamIds()` vs `applyToEmployees()`/`visibleEmployeeIds()`**: these are not the
  same thing. `teamIds()` walks the full `reports_to_id` subtree at every depth (what CR-17's
  "reporting line" needed); the other two match the `data_scope` string literally against a
  column like `employees.branch_id`. Using the wrong one silently changes who a scoped page
  shows.
- `overdue_ledger` rows are written only on reassign, not on a schedule — if a card stays
  overdue with the same owner across a month boundary with no reassign, nothing gets logged
  for that month. This matches the frozen OPEN.md entry's wording ("so CR-14 rule 8 has a
  record to read" on reassign) but is worth knowing if CR-14 or a future session expects a
  month-end sweep instead.
- `OverdueLedger.month` has no cast — it is written as a plain date string
  (`toDateString()`). Adding a `'date'` cast back re-introduces a bug where MySQL/sqlite
  serialize it with a spurious time component, breaking exact `assertDatabaseHas` checks.
- One flaky failure was seen in the first full-suite run of this session
  (`ShippedScopeTest::test_in_scope_screens_render_on_a_default_tenant`, a transient
  `ViteException: Unable to locate font CSS file from manifest`) that did not reproduce when
  the file was run alone or in a second full-suite run. Looked like a one-off race against a
  concurrent asset rebuild in this session, not a CR-17 regression; flagged here in case it
  recurs.
