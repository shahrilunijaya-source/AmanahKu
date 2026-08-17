# Timesheet-driven staff dedication (AmanahKu → Track)

Spans two repos: **AmanahKu** (`/home/shzwn/Projects/Unijaya/AmanahKu`) and **Track** (`/home/shzwn/Projects/Unijaya/Track`).

**Progress: all 7 steps of §9 are built and green, plus the verify harness (§6.6). Nothing is committed in either repo.**

End to end across the real HTTP boundary: `amanahku:sync-effort` was run against the live local AmanahKu (Track's dev settings point at `http://host.containers.internal:9100/api/v1`) and completed correctly, so both new endpoints, the client, the sync service and the ledger have all been exercised for real, not only against fakes.

## 1. Problem

Track's weekly cadence has a **Money Out → Staff dedication this week** box. The PM types rows of `{position, HC, Alloc%, Days}` by hand from memory. `WeekNoteController::syncLedger()` multiplies them by a Track daily rate and writes `Weekly · Staff` expense rows into the project ledger. Those rows feed Money Out and `MonthlyReportService`.

So Track bills a guess. Meanwhile every staffer has already recorded, day by day, what percentage of each day went to which project, in AmanahKu timesheets.

## 2. Goal

One source of truth for staff dedication. **AmanahKu owns the effort. Track owns the rate and the money.** The PM stops typing.

## 3. Decisions

Each decision is numbered so it can be changed by itself. D1 to D9 were chosen by Shazwan during the spec interview. D10 to D16 were decided by Claude and are open to veto.

| # | Decision | Rejected alternative |
|---|---|---|
| D1 | Pulled effort **replaces** the manual rows. AmanahKu becomes source of truth; Money Out staff cost becomes timesheet-driven. | Show beside, write nothing. Seed and let the PM edit. |
| D2 | Rows stay **per position**, not per employee. AmanahKu aggregates people into their position band before sending. | Per-employee rows with names. |
| D3 | **Track owns the rate.** AmanahKu sends person-days only, no money. Track resolves the position through an explicit mapping and applies `DailyRateService`. | AmanahKu sends RM. Match positions by name. Auto-create missing positions. |
| D4 | **Cron pull on a rolling window, plus a manual Sync button.** No push from AmanahKu. | AmanahKu pushes on submit. Manual only. Cron only. |
| D5 | Manual "+ Add roles" entry is **disabled on linked projects only**. Unlinked projects keep manual entry exactly as today. | Remove manual entry everywhere. Keep it and let sync overwrite it. |
| D6 | An unmapped role shows as a row with its AmanahKu title and person-days, **amount RM 0, flagged "rate not mapped"**, and writes no ledger entry. | Block the whole week. Drop the row. Use a fallback rate. |
| D7 | First sync reaches **only the rolling window**. Weeks older than the window keep their manual rows and ledger entries forever. | Rewrite all history. Backfill on request. |
| D8 | **Client visibility is unchanged.** Accepted risk, see section 10. | Hide Money Out from client-role users. |
| D9 | Row display keeps the existing columns: `HC · Alloc% · Days`. Alloc% is the average across the people in that role. | A single person-days column. Alloc pinned at 100%. |
| D10 | A week counts once its owner is finished with it: `status` in `('submitted','approved')`. Drafts are invisible until submitted; rejected weeks never count. | Include drafts, flagged. Count `submitted` only. |
| D11 | The AmanahKu endpoints are gated to `PRIVILEGED = ['management','hr']`, matching `/employees` and `/payslips`. | Ungated like `/projects`. |
| D12 | **A failed fetch is not zero.** On API failure the last good data and its ledger rows stay in place. A *successful* fetch that returns no effort **is** zero, and clears that week's **synced rows only**. Manual rows are never removed by a sync that carries no effort. | Treat any empty result as zero. Let a zero result clear the week outright. |
| D13 | **`person_days` is the stored, authoritative number. Money is `daily_rate × person_days`.** HC / Alloc% / Days are display-only derivations and are never multiplied back to get money. | Reuse the existing `rate × HC × alloc% × days` path. |
| D14 | Rolling window is **the current week plus 3 earlier weeks**, matching AmanahKu's `BACKFILL_WEEKS = 3`. Cron runs nightly. | Weekly cron. Fixed 1-week window. |
| D15 | The manual Sync button works on **any** week the PM is viewing, not only the rolling window. It is the only path for a recalled and resubmitted old week to reach Track. | Restrict Sync to the rolling window. |
| D16 | Categories and sub-pillars are ignored. Effort is aggregated at project level. | Break the row down by category or sub-pillar. |

## 4. Facts this spec is built on

Checked in the code, not assumed.

- **AmanahKu timesheets are never approved.** The status enum carries `approved` and `rejected`, but nothing sets them. The lifecycle is `draft → submitted`, and [TimesheetController.php:318](app/Http/Controllers/TimesheetController.php:318) lets a staffer recall a submitted week back to draft. There is no approval event to trigger on. This is why D4 is a pull, not a push.
- **Submission is per person, not per project.** Track can never know when "the project's week is complete". This is why the window re-pulls instead of freezing.
- **Effort is a percentage of a day, not hours.** `timesheet_entries.percentage` (0.00 to 100.00). Every populated day must total 100%. The `hours` column is legacy and nullable. Person-days = `sum(percentage) / 100`.
- **Leave and holiday rows carry no `project_id`** (`source` = `'leave'` or `'holiday'`), and non-project categories have `requires_project = false`. Both fall out of a per-project sum with no special handling.
- **Both apps start the week on Monday.** AmanahKu `timesheets.week_start`, Track `project_week_notes.week_start`.
- **The two rate cards are unrelated.** AmanahKu `positions(department_id, staff_level_id, title, max_salary)` costed via `config/manday.php`. Track `positions(department_id, level_id, name)` + `salary_bands.max_salary` costed via `CostSetting`. Different titles, different salaries, no shared id. This is why D3 needs an explicit mapping.
- **24 Track projects are still unlinked.** The AmanahKu link is mandatory only for new projects. Linked and unlinked projects coexist indefinitely, which is why D5 keeps manual entry alive.
- **`DailyRateService::forPosition()` returns `0.0`** when a position has no salary band. It does not throw. An unmapped or unbanded position books RM 0 silently unless D6 flags it.

## 5. AmanahKu side

Two new read-only endpoints on the existing `auth:sanctum` + `api.tenant` v1 group. Both gated to `management|hr` (D11).

### 5.1 `GET /api/v1/positions`

Feeds the mapping screen in Track. Returns the tenant's position bands, no salary.

```json
{ "data": [ { "id": 2, "title": "Senior Engineer", "code": "SE",
              "department": "Operation", "level": "Senior", "status": "active" } ],
  "error": null }
```

`department` and `level` are names resolved through `departments` and `staff_levels`, both nullable. Inactive bands are included: a mapping must still cover effort booked under a band that has since been retired.

**Built.** `ApiController::positions()`, `routes/api.php`, 3 tests in `ApiTokenTest`.

### 5.2 `GET /api/v1/timesheet-effort?week_start=YYYY-MM-DD`

One call returns every project's effort for that week, so a nightly run over 24 projects and 4 weeks costs 4 calls, not 96.

```json
{
  "data": {
    "week_start": "2026-08-03",
    "projects": [
      {
        "project_id": 12,
        "positions": [
          { "position_id": 2, "position_title": "Senior Engineer",
            "headcount": 2, "person_days": 7.0, "days_present": 5, "alloc_pct": 70.0 },
          { "position_id": null, "position_title": null,
            "headcount": 1, "person_days": 1.5, "days_present": 3, "alloc_pct": 50.0 }
        ]
      }
    ]
  },
  "error": null
}
```

Aggregation rules:

- Source rows: `timesheet_entries` joined to `timesheets` where `status` is `submitted` or `approved` and `week_start` matches, with `project_id` not null. Nothing in the app sets `approved` today, but `TimesheetSeeder` does and `WeekReconciler` treats an approved week as a decided figure that must not be mutated — it is the *more* final of the two, so filtering on `submitted` alone silently withheld real effort.
- `person_days` = `sum(percentage) / 100`, grouped by project and by the employee's `position_id`.
- `headcount` = distinct employees in that group.
- `days_present` = distinct `entry_date` values in that group.
- `alloc_pct` = `person_days / (headcount × days_present) × 100`, rounded to 2 dp. It cannot exceed 100 because one employee-day never exceeds 1.0.
- An employee with no position band groups under `position_id: null`. Track treats that the same as an unmapped role (D6).
- **No employee name, no employee id, and no money ever leave AmanahKu.** Aggregation happens server side.

**Built.** `ApiController::timesheetEffort()` + `effortByPosition()`, `routes/api.php`, 9 tests in `tests/Feature/Api/TimesheetEffortApiTest.php`: arithmetic, part-day fractions, 2 dp rounding, draft weeks excluded, project-less entries excluded, the unbanded bucket, privilege gate, missing `week_start`, tenant isolation. Each filter was mutation-checked, so the tests are known to fail when the production code is broken.

One note for the consumer: JSON has a single number type, so `7.0` arrives as `7`. Track must cast, which it already does.

## 6. Track side

### 6.1 Schema

```
amanahku_position_map                                        -- the D3 mapping
  amanahku_position_id   unique
  amanahku_title         cached, for display when AmanahKu is unreachable
  position_id            FK -> positions
  mapped_by              FK -> users, nullable
project_week_notes.amanahku_synced_at  nullable timestamp     -- step 5
project_week_notes.amanahku_sync_error nullable string        -- step 5
```

A join table, not a column on `positions`. AmanahKu's table is the whole org grid (department x level: HR Executive, Operations Manager, Intern). Track's is delivery roles only. Two AmanahKu roles will need to point at one Track position, for example "Jr Developer" and "Intern" both mapping to Track's junior band. A column on `positions` allows one value per row and cannot express that. The unique side stays `amanahku_position_id`, so resolution at sync time is still a single unambiguous lookup.

`staff_allocation` JSON rows gain, for synced rows only:

```json
{ "position_id": 8, "hc": 2, "alloc": 70.0, "days": 5,
  "person_days": 7.0, "source": "amanahku",
  "amanahku_position_title": "Senior Engineer", "mapped": true }
```

Rows without `"source": "amanahku"` are manual and behave as today.

### 6.2 Mapping screen

A separate screen at `/admin/hr/amanahku-positions`, not a column on the existing Positions page: that one is a department × level org-chart matrix and a per-row dropdown has nowhere to live in a matrix cell. It lists the AmanahKu roles from `GET /api/v1/positions` and, for each, lets an admin choose which Track position it costs against. Many AmanahKu roles may point at one Track position. Unmapped is allowed and produces D6 behaviour.

Each row shows one of **three** rate states, because two of them bill RM 0 and an admin must know which they are looking at:

| State | Meaning | Rate cell |
|---|---|---|
| `ok` | mapped, and the Track position has a salary band | the daily rate |
| `no-band` | mapped, but the Track position has no salary band | "no salary band" |
| `unmapped` | no mapping at all | "not mapped" |

Without the `no-band` state, `DailyRateService` returning `0.0` renders as `0.00`, which reads as a real rate of zero rather than a missing one.

When AmanahKu is unreachable the screen still renders, showing a warning plus the rows it can rebuild from `amanahku_title`. It never 500s and never shows an empty list that could be mistaken for "nothing is mapped".

**Built.** `AmanahkuPositionMap`, `AmanahkuClient::positions()`, `Admin\Hr\AmanahkuPositionController`, `admin/hr/amanahku-positions/{index,_table}.blade.php`, 10 tests in `tests/Feature/AmanahkuPositionMapTest.php`. `updateOrCreate` and the offline fallback were mutation-checked.

### 6.3 Sync service

New `AmanahkuEffortSync`. For one project and one week:

1. If the project has no `amanahku_project_id`, do nothing.
2. Call `AmanahkuClient::timesheetEffort($weekStart)` (cached per week per run, so one HTTP call serves all projects).
3. On failure, write `amanahku_sync_error`, leave rows and ledger untouched, return. (D12) The catch is `Throwable`, **not** `RuntimeException`: a bad status comes back as a response and surfaces as a `RuntimeException`, but a host that is simply down never gets that far — Guzzle raises `ConnectionException`, which extends `Exception`. Catching only `RuntimeException` meant host-down, DNS and timeout failures skipped this step entirely, so the week was never marked stale and the panel served old figures with no warning.
4. On success **with effort**, rebuild the `source: 'amanahku'` rows for that week from the response, resolving each `position_id` through `amanahku_position_map`. On success **with no effort**, remove that week's `source: 'amanahku'` rows and leave every manual row alone. (D12)
5. Resync the ledger: `amount = DailyRateService::forPosition($pos) × person_days` (D13). Unmapped or unbanded rows write no ledger entry.
6. Set `amanahku_synced_at`, clear `amanahku_sync_error`. A failure leaves the stamp where it was, so the screen can say how stale a figure is instead of implying it was just refreshed.

`syncWeek()` returns one of `synced`, `cleared`, `skipped`, `failed`, which is what makes the failure path assertable rather than inferred from side effects.

**Built.** `AmanahkuClient::timesheetEffort()`, `App\Services\AmanahkuEffortSync`, two migrations, 11 tests across `AmanahkuEffortSyncTest` and `WeekNoteLedgerTest`. Four mutations were each caught by the right test: treating a failed fetch as zero, wiping manual rows on an empty week, dropping the per-week request cache, and costing by re-multiplying instead of from `person_days`.

Three things this step forced:

- **`ledger_entries.created_by` was NOT NULL**, so the `$actorId = null` that step 4 introduced for unattended runs would have crashed the cron on its first row. Now nullable; an automated sync genuinely has no human author, and the only reader already used `optional($e->createdBy)->name ?? ''`. The MySQL branch drops and re-adds the foreign key around the change, verified on the dev database (column nullable, `ledger_entries_created_by_foreign` intact).
- **`WeekNoteLedger` now costs from `person_days` when a row carries it** (D13). Re-multiplying the readable decomposition drifts: one person over 3 days at 1.0 person-days decomposes to `alloc_pct` 33.33, and `810 × 1 × 0.3333 × 3` is RM 809.92 rather than RM 810.00. Rows without `person_days`, meaning the PM's own, keep the old arithmetic exactly.
- **`Http::fake()` merges stubs rather than replacing them.** A test that faked success, synced, then faked a 502 never saw the 502 — the request succeeded, the data was still in place, and every other assertion passed. The most important test in the file was green and vacuous. It now seeds prior state directly so only one stub is ever registered, and asserts the returned outcome string so the failure path is proven to have run.

`WeekNoteController::syncLedger()` was private and assumed a logged-in user (`auth()->id()`). It is now `App\Services\WeekNoteLedger`, taking an optional `$actorId` so an unattended cron run can use the same code path as the PM's save. Synced rows keep the existing `weeknote:{week_start}:staff:{idx}` reference namespace.

**Where the manual-row guard lives.** The risk is real: a PM presses Sync on a week older than the linkage (D15), AmanahKu correctly returns zero effort, and a naive resync deletes months-old manual rows that D7 promised would stay. The guard does **not** belong in the ledger service. `WeekNoteLedger` writes exactly the rows it is handed and deletes stale rows only inside the namespaces it was given; teaching it about `source: 'amanahku'` would couple a generic costing service to one caller's ownership rules.

Instead **the sync service composes the full row set** before calling it: AmanahKu rows for weeks with effort, and the surviving manual rows when there is none. A zero-effort sync therefore hands over the manual rows unchanged, the ledger regenerates exactly those, and nothing is lost. Ownership logic sits in step 5, where the ownership is known.

**Built.** `App\Services\WeekNoteLedger`, `WeekNoteController` reduced to validation plus normalisation, 9 tests in `tests/Feature/WeekNoteTest.php` (5 new). The four behaviours the sync depends on were mutation-checked: the week lookup, namespace-scoped deletion, the zero-amount skip, and the cost-settings hydration.

Two pre-existing defects surfaced while characterising the code before moving it, both fixed:

- **A second save to the same project-week 500'd.** `updateOrCreate` matched `week_start` against the raw request string, but Laravel's `date` cast reads back as `'2026-05-25 00:00:00'` on sqlite and `'2026-05-25'` on MySQL. The miss made it insert and trip the `(project_id, week_start)` unique index. Invisible in production, but it meant no test could cover the repeat-save path, which is exactly what a nightly sync does. Fixed with `ProjectWeekNote::scopeForWeek()`, a sargable range mirroring AmanahKu's `Timesheet::scopeForWeek()`.
- **The first rate computed on a fresh database was RM 0.** `CostSetting::current()` used `firstOrCreate([])`; `inflate_factor` and `working_days_per_month` exist only as database defaults, so the newly created model carried `null` for both and `DailyRateService` computed `(max_salary × 0) / 0`. Only bites a brand-new database, but it sits directly in this feature's path. Fixed by refreshing the created row.

### 6.4 Triggers

- **Nightly cron**: `amanahku:sync-effort`, scheduled `0 2 * * *` Asia/Kuala_Lumpur, `withoutOverlapping()`. Syncs the current week plus 3 earlier weeks for every linked project. (D14) `--weeks=N` narrows the window by hand.
- **Manual Sync button** on the cadence week panel, gated by `manageWeekly` (PM + PE + admin/director), works on any week. (D15)

The command **always exits 0**. A transient AmanahKu outage is recorded per week and healed by the next run; exiting non-zero would page somebody nightly over something that fixes itself. `syncWindow()` also catches `Throwable` per project-week and logs it, so one project with an unexpected payload cannot cost the other twenty-three their week.

**Built.** `App\Console\Commands\SyncAmanahkuEffort`, the `routes/console.php` registration, the per-project guard in `syncWindow()`, 7 tests in `tests/Feature/SyncAmanahkuEffortCommandTest.php`. Four mutations were each caught by the right test: removing the per-project guard, collapsing the window to one week, dropping the schedule registration, and exiting non-zero on failures.

Requires the server cron already running `php artisan schedule:run` every minute, same as the existing monthly and weekly jobs.

### 6.5 UI

In `_wbs_cadence.blade.php`, when the project is linked:

- Hide "+ Add roles" and the per-row remove button.
- Label the box "Staff dedication this week · from AmanahKu timesheets".
- Show `Last synced {time}` and the Sync button.
- Rows with `mapped: false` show the AmanahKu title, HC, Alloc%, Days, **RM 0**, and a "rate not mapped" badge.
- On a sync error, show the stale data plus a warning badge naming the failure. Never blank the box.

When the project is not linked, nothing changes.

**The gate is server-side, not just visual.** `WeekNoteController::update()` rejects `staff_allocation` with a 422 on a linked project. Hiding the controls is not gating them: a stale tab or a crafted request would otherwise overwrite synced rows and rewrite the ledger behind them. Everything else on the week note (daily plans, actuals, expenses) still saves normally.

Manual entry is withheld in **Blade**, not behind an Alpine `x-if`. `<template x-if>` is still server-rendered markup, so a client-side gate would ship the controls to linked projects and no test could tell the difference. The staff modal markup is gated the same way.

**Built.** `WbsController` (sync state + `$amanahkuLinked`), `ProjectAmanahkuController::sync()`, `POST /projects/{project}/amanahku-sync`, the cadence Blade and its Alpine component, 10 tests in `tests/Feature/CadenceAmanahkuStaffTest.php`. Three mutations were each caught by the right test: removing the server-side gate, not snapping a mid-week date to its Monday, and un-hiding manual entry on linked projects.

**One check here is manual, and stays manual.** PHPUnit cannot execute the Alpine component, so the parity between what the panel shows and what the ledger books is not covered by the suite. To re-run it after touching `staffRowAmount()`: render the cadence for a linked project, extract the inline `<script>` that defines `wbsCadence`, load it with `new Function(src + '; return wbsCadence;')`, and assert `staffRowAmount({position_id, hc:1, alloc:33.33, days:3, person_days:1.0})` returns `rate × 1.0` and not `rate × 0.3333 × 3`. If that function is edited and this is skipped, nothing anywhere will fail.

Verified beyond PHPUnit, because a JavaScript error in the Alpine block passes every server-side assertion silently: both rendered variants were extracted and syntax-checked, then `staffRowAmount()` was **executed** against the real rendered code and shown to return `rate × person_days` (RM 810.00, not the RM 809.92 the decomposition gives), to keep the old arithmetic for manual rows, and to cost an unmapped row at zero.

### 6.6 Verify harness (Track)

Runtime observation of a single UI unit in a fixed state, so a screen can be checked without a human driving a browser. Laravel-native, following the framework-agnostic pattern rather than its React templates, since Track is Blade and Alpine.

- **Surface**: `data-verify-*` attributes on the rendered Blade. A verifier reads those, never Blade internals, so a restyle cannot break it.
- **Units**: `App\Verify\Units` names a partial, its verifiers, its invariants, and its fixtures. First unit is the mapping table.
- **Fixtures**: named render states returning plain arrays. Nothing touches the database, so the same fixture renders identically in CI, in a browser and for an agent. A fixture marked `probe` is an adversarial edge case; `VerifyMatrixTest` refuses a unit that has none.
- **Verifiers**: `DomContractVerifier` (the unit publishes its contract) and `InvariantVerifier` (the unit's own predicates). A new kind of check is a new class plus one array entry.
- **Verdicts**: `PASS | FAIL | BLOCKED | SKIP` from `Verdict::worstOf()`. `BLOCKED` means could not observe, and stays distinct from `FAIL`, which means observed and wrong.
- **Agent handle**: `GET /verify/manifest`, `GET /verify/run`, `GET /verify/{unit}/{fixture}` for an isolated render, plus `GET /verify` as a human dashboard. All read the same `Runner` the CI test uses, so the dashboard cannot show a green CI would refuse.
- **Gated twice**: routes are registered only under `local`/`testing`, and `VerifyController` re-checks, so relocating the route definitions cannot quietly expose it. Unauthenticated on purpose, so an agent needs no session.

**Built.** `app/Verify/*`, `VerifyController`, `verify/dashboard.blade.php`, 10 tests across `VerifyMatrixTest` and `VerifyRoutesTest`. Three deliberate breakages of the real partial (wrong count attribute, a non-billable row showing a number, a missing contract root) were each caught by the right invariant.

## 7. Consequences worth stating

- **Monthly reports change too.** `MonthlyReportService` reads the same ledger rows. Once a project is linked, its monthly staff cost becomes timesheet-driven without any separate change.
- **Money moves without anyone touching Track.** A late submitter or a recall in AmanahKu changes a past week's ledger on the next nightly run, within the rolling window.
- **Track's API token was already privileged.** D11 gates the new endpoints to `management|hr`, so a plain-employee token would 403 on every sync. Checked against the running local AmanahKu with Track's stored token: `GET /api/v1/positions` and `GET /api/v1/timesheet-effort` both returned 200. Nothing to re-mint. If the token is ever rotated to a non-privileged user, that is the failure to look for.

## 8. Out of scope

- Categories and sub-pillars (D16).
- Per-employee names or per-employee rows (D2).
- The income side of Money Out, and free-form expense lines.
- Unlinked projects (D5).
- Changing who can see Money Out (D8).
- History older than the rolling window (D7).

## 9. Build order

1. AmanahKu: `GET /api/v1/positions` + tests.
2. AmanahKu: `GET /api/v1/timesheet-effort` + aggregation tests.
3. Track: `amanahku_position_map` migration + mapping screen.
4. Track: extract the ledger sync out of `WeekNoteController` into a service, add the manual-row deletion guard, no other behaviour change, tests still green.
5. Track: `AmanahkuEffortSync` + `AmanahkuClient::timesheetEffort()` + tests, including the failure-is-not-zero case and the zero-effort-does-not-delete-manual-rows case.
6. Track: cadence UI (read-only rows, badges, Sync button).
7. Track: nightly scheduled command.

Steps 1 and 2 ship through AmanahKu's release path (dev → staging → PR staging into main → push gitlab main). Steps 3 to 7 ship through Track's own path.

## 10. Accepted risks

**Client-role users can read Money Out.** `WbsController::index` authorizes with `view`, and `ProjectPolicy::view` passes for any active assignment including `project_role = 'client'`. Only editing is gated. So a client assigned to a project can open `/projects/{id}/wbs` and read staff dedication rows and RM amounts.

This hole exists today. Today it exposes a PM's rough guess. After this change it exposes precise, payroll-derived labour cost per role, refreshed weekly. Shazwan reviewed this and chose to leave visibility unchanged (D8). Recorded here so it is a decision, not a surprise.

## 11. Open questions

Archived projects were an open question; they are now answered by construction. `syncWindow()` queries `Project::whereNotNull('amanahku_project_id')`, and `Project` carries a `not_archived` global scope, so an archived project is never synced and its existing rows are left frozen. That is pinned by `test_it_never_touches_an_archived_project`, because adding a `->withArchived()` to that query would otherwise start rewriting the ledgers of projects nobody is watching, with nothing failing.


- Does the mapping screen need a warning when an AmanahKu position has no Track counterpart, or is the D6 badge on the week enough?
