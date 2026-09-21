# CR-35 Day-Level Timesheet Effort Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `GET /api/v1/timesheet-effort` counts a timesheet entry once that entry's own day is submitted or approved, instead of waiting for the whole week to reach `submitted`.

**Architecture:** The endpoint currently filters entries by a subquery on `timesheets.status`. Replace that with: load the week's entries regardless of week status, eager-load `timesheet.days`, and keep an entry when its own `timesheet_days` row for that `entry_date` is `submitted`/`approved`. Timesheets that have **no** day rows at all (every historical week — 133 of 133 in the production dump) keep being judged by their week status. The filter is per-timesheet, not per-date: once a timesheet has any day rows, a missing row means "not submitted", so a dateless day can never inherit a `submitted` week status.

**Tech Stack:** Laravel 13, PHP 8.5, PHPUnit 12, Eloquent, Laravel Pint.

**Spec:** `docs/specs/CR-35.md`

## Global Constraints

- Payload shape unchanged: one aggregate row per position band per week — `position_id, position_title, headcount, person_days, days_present, alloc_pct`. No employee name, employee id or salary crosses the wire.
- No change to Track (`/home/shzwn/Projects/Unijaya/Track`). Its cron is already nightly over a four-week window and `WeekNoteLedger` costs from `person_days`.
- Non-goal: per-day effort in the response. The endpoint stays one aggregate per week.
- `days_present` stays band-wide (distinct dates across the band, not per person). Making it per-person would be a bigger change for no ledger benefit, since Track costs from `person_days`. The consequence — a low mid-week `alloc_pct` for a ragged band — is pinned by a test and stated in the docs instead.
- Tenant isolation, `effort:read` scope and the privileged-role check are untouched and must stay green.
- Run `vendor/bin/pint --dirty --format agent` before each commit.
- Tests run on the host: `php artisan test --compact tests/Feature/Api/TimesheetEffortApiTest.php`. Never point a test run at the dev MySQL.

---

### Task 1: Gate effort on the entry's own day, with a legacy fallback

**Files:**
- Modify: `app/Http/Controllers/Api/V1/ApiController.php` (the `COUNTED_TIMESHEET_STATUSES` constant around line 39, and `timesheetEffort()` around lines 278-336)
- Test: `tests/Feature/Api/TimesheetEffortApiTest.php`

**Interfaces:**
- Consumes: `Timesheet::scopeForWeek()`, `Timesheet::days()` (HasMany to `TimesheetDay`), `TimesheetDay::STATUS_SUBMITTED` / `STATUS_APPROVED`, `TimesheetEntry::$entry_date` (Carbon, `date` cast).
- Produces: `ApiController::COUNTED_DAY_STATUSES` (`['submitted', 'approved']`) and `private function entryIsCounted(TimesheetEntry $entry): bool`. `COUNTED_TIMESHEET_STATUSES` stays, now used only for the legacy branch.

- [ ] **Step 1: Add the day-row helper to the test's helpers section**

At the bottom of `tests/Feature/Api/TimesheetEffortApiTest.php`, next to `submitWeek()`, add:

```php
    /**
     * Give a timesheet the CR-03 per-day rows a modern week has. Offsets are Monday
     * first, matching submitWeek()'s percentages array.
     *
     * @param  array<int, string>  $statuses  Day status per offset, Monday first.
     */
    private function days(Timesheet $timesheet, array $statuses): void
    {
        app(CurrentTenant::class)->set($this->tenant);

        foreach ($statuses as $offset => $status) {
            TimesheetDay::create([
                'tenant_id' => $this->tenant->id,
                'timesheet_id' => $timesheet->id,
                'entry_date' => date('Y-m-d', strtotime(self::WEEK.' +'.$offset.' day')),
                'status' => $status,
                'submitted_at' => in_array($status, ['submitted', 'approved'], true) ? now() : null,
            ]);
        }

        app(CurrentTenant::class)->set(null);
    }
```

Add the import at the top of the file, after `use App\Models\TimesheetEntry;`:

```php
use App\Models\TimesheetDay;
```

(Keep the `use` block alphabetical: `TimesheetDay` sorts before `TimesheetEntry`.)

- [ ] **Step 2: Write the failing tests**

Add these five tests to `tests/Feature/Api/TimesheetEffortApiTest.php`, after `test_a_rejected_week_is_excluded()`:

```php
    public function test_a_legacy_week_with_no_day_rows_still_counts_by_its_week_status(): void
    {
        // Every historical timesheet predates CR-03 and has no timesheet_days rows at
        // all. Judging those by day status would zero every figure already in Track's
        // ledger on the next nightly run.
        $this->submitWeek($this->employee('Ali', $this->senior), [100, 100, 100, 100, 100]);

        $row = $this->effortRow();

        $this->assertSame(5.0, (float) $row['person_days']);
        $this->assertSame(5, $row['days_present']);
    }

    public function test_a_part_submitted_week_counts_only_its_submitted_days(): void
    {
        // Mon-Wed submitted, Thu-Fri still being worked on, so the week itself is draft.
        $timesheet = $this->submitWeek($this->employee('Ali', $this->senior), [100, 100, 100, 100, 100], 'draft');
        $this->days($timesheet, ['submitted', 'submitted', 'submitted', 'draft', 'draft']);

        $row = $this->effortRow();

        $this->assertSame(3.0, (float) $row['person_days']);
        $this->assertSame(3, $row['days_present']);
        $this->assertSame(1, $row['headcount']);
        // 3.0 / (1 head x 3 days) = 100%. One person submitting a run of whole days
        // keeps alloc_pct honest; see the ragged-band test below for the case that
        // does not.
        $this->assertSame(100.0, (float) $row['alloc_pct']);
    }

    public function test_a_fully_submitted_week_with_day_rows_reads_the_same_as_before(): void
    {
        $timesheet = $this->submitWeek($this->employee('Ali', $this->senior), [100, 100, 100, 100, 100]);
        $this->days($timesheet, ['submitted', 'submitted', 'submitted', 'approved', 'approved']);

        $row = $this->effortRow();

        $this->assertSame(5.0, (float) $row['person_days']);
        $this->assertSame(5, $row['days_present']);
    }

    public function test_a_returned_day_stops_counting(): void
    {
        // A manager returning Tuesday for correction makes the project's money out go
        // DOWN on the next pull. That is correct, not a regression.
        $timesheet = $this->submitWeek($this->employee('Ali', $this->senior), [100, 100, 100], 'draft');
        $this->days($timesheet, ['submitted', 'returned', 'submitted']);

        $row = $this->effortRow();

        $this->assertSame(2.0, (float) $row['person_days']);
        $this->assertSame(2, $row['days_present']);
    }

    public function test_a_day_with_no_row_never_inherits_a_submitted_week_status(): void
    {
        // Once a timesheet has any day rows, a missing row means not submitted. The
        // fallback is per timesheet, not per date, or a submitted week would leak its
        // unsubmitted days.
        $timesheet = $this->submitWeek($this->employee('Ali', $this->senior), [100, 100, 100], 'submitted');
        $this->days($timesheet, ['submitted']);

        $row = $this->effortRow();

        $this->assertSame(1.0, (float) $row['person_days']);
        $this->assertSame(1, $row['days_present']);
    }

    public function test_a_band_submitting_different_days_reads_a_low_alloc_pct(): void
    {
        // days_present counts distinct dates across the WHOLE band, not per person, so
        // two people covering disjoint halves of the week look half-dedicated. This was
        // unreachable while a week counted all-or-nothing. It is pinned here rather than
        // discovered later: person_days is the figure Track costs from, alloc_pct is a
        // reading aid and mid-week it under-reads.
        $ali = $this->submitWeek($this->employee('Ali', $this->senior), [100, 100, 100], 'draft');
        $this->days($ali, ['submitted', 'submitted', 'submitted']);

        $siti = $this->submitWeek($this->employee('Siti', $this->senior), [0, 0, 0, 100, 100], 'draft');
        $this->days($siti, ['draft', 'draft', 'draft', 'submitted', 'submitted']);

        $row = $this->effortRow();

        $this->assertSame(5.0, (float) $row['person_days']);
        $this->assertSame(2, $row['headcount']);
        $this->assertSame(5, $row['days_present']);
        // 5.0 / (2 heads x 5 days) = 50%, for a band that is in fact full-time.
        $this->assertSame(50.0, (float) $row['alloc_pct']);
    }

    public function test_a_rejected_week_is_excluded_even_when_its_days_say_submitted(): void
    {
        // Nothing in the app writes 'rejected' to a week today and
        // Timesheet::refreshStatusFromDays() overwrites the week status the moment day
        // rows exist, so this combination should be unreachable. It is guarded anyway:
        // a dead week must never leak effort into a ledger because a day row survived.
        $timesheet = $this->submitWeek($this->employee('Ali', $this->senior), [100, 100], 'rejected');
        $this->days($timesheet, ['submitted', 'submitted']);

        $response = $this->getJson('/api/v1/timesheet-effort?week_start='.self::WEEK, $this->bearer($this->hr));

        $response->assertOk();
        $this->assertSame([], $response->json('data.projects'));
    }
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Api/TimesheetEffortApiTest.php`

Expected: `test_a_legacy_week_with_no_day_rows_still_counts_by_its_week_status`, `test_a_fully_submitted_week_with_day_rows_reads_the_same_as_before` and `test_a_rejected_week_is_excluded_even_when_its_days_say_submitted` PASS (today's behaviour already satisfies them). The other four FAIL: `test_a_part_submitted_week_counts_only_its_submitted_days`, `test_a_returned_day_stops_counting` and `test_a_band_submitting_different_days_reads_a_low_alloc_pct` fail on `effortRow()`'s own assertion, "No effort returned for the project under test.", because a draft week still returns `projects: []`; `test_a_day_with_no_row_never_inherits_a_submitted_week_status` fails with `3.0` where `1.0` is expected.

- [ ] **Step 4: Add the day-status constant**

In `app/Http/Controllers/Api/V1/ApiController.php`, directly below the existing `COUNTED_TIMESHEET_STATUSES` constant (around line 40), add:

```php
    /** Day states whose effort is final enough to bill against. See timesheetEffort(). */
    private const COUNTED_DAY_STATUSES = [TimesheetDay::STATUS_SUBMITTED, TimesheetDay::STATUS_APPROVED];
```

Leave `COUNTED_TIMESHEET_STATUSES` exactly as it is, and update its docblock line to read:

```php
    /** Week states that count for a legacy timesheet with no per-day rows. See timesheetEffort(). */
```

Add the model import to the file's `use` block, keeping it alphabetical among the other `App\Models\` imports:

```php
use App\Models\TimesheetDay;
```

- [ ] **Step 5: Replace the week-status filter with the day-level one**

In `timesheetEffort()`, delete the `$countedWeeks` builder and its comment, and replace the `$entries` query with:

```php
        // Every entry in the week, no status filter here: the decision is per day and
        // per timesheet, made in entryIsCounted(). `timesheet.days` is eager loaded so
        // that decision costs no query per entry.
        $entries = TimesheetEntry::query()
            ->whereNotNull('project_id')
            ->whereIn('timesheet_id', Timesheet::query()->forWeek($weekStart)->select('id'))
            ->with(['timesheet.employee', 'timesheet.days'])
            ->get()
            ->filter(fn (TimesheetEntry $entry) => $this->entryIsCounted($entry));
```

Then add this method directly below `timesheetEffort()`:

```php
    /**
     * Does one entry's effort count?
     *
     * Per day (CR-03): the entry counts once the `timesheet_days` row for its own
     * `entry_date` is submitted or approved. Timesheets are submitted a day at a time,
     * so waiting for the week-level status held Monday's effort back until Friday.
     *
     * The fallback is per timesheet, never per date: a timesheet with no day rows at
     * all predates CR-03 and is judged by its week status, exactly as before. Once a
     * timesheet has any day rows, a missing row means not submitted — a per-date
     * fallback would let a dateless day inherit a submitted week status, which is the
     * leak this guard exists to close.
     */
    private function entryIsCounted(TimesheetEntry $entry): bool
    {
        // A week-level veto ahead of the day branch. A rejected week is dead (see
        // WeekReconciler, which refuses to touch one) and must not leak effort because a
        // day row outlived it. Nothing writes 'rejected' today and refreshStatusFromDays()
        // would overwrite it, so this is a guard, not a live path.
        if ($entry->timesheet->status === 'rejected') {
            return false;
        }

        $days = $entry->timesheet->days;

        if ($days->isEmpty()) {
            return in_array($entry->timesheet->status, self::COUNTED_TIMESHEET_STATUSES, true);
        }

        $day = $days->first(
            fn (TimesheetDay $day) => $day->entry_date->toDateString() === $entry->entry_date->toDateString()
        );

        return $day !== null && in_array($day->status, self::COUNTED_DAY_STATUSES, true);
    }
```

Update the endpoint docblock: replace the paragraph beginning "A week counts once its owner has finished with it" with:

```
     * An entry counts once its own day is finished with: the `timesheet_days` row for
     * that date is 'submitted', or 'approved' for the decided figures WeekReconciler
     * refuses to mutate. A draft day is still being edited and a returned day was sent
     * back for correction, so both contribute nothing and a day sent back makes a
     * project's figure go down on the next pull.
     *
     * A timesheet with no day rows at all is a pre-CR-03 week and is judged by its
     * week-level status instead. Every historical week in the data is one of these. A
     * 'rejected' week counts for nothing either way.
     *
     * Mid-week, `alloc_pct` can read low: `days_present` is the distinct dates across
     * the whole band, so two people submitting different halves of the week look
     * half-dedicated. `person_days` is the figure to cost from.
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Api/TimesheetEffortApiTest.php`

Expected: PASS, all of them, including the pre-existing `test_a_draft_week_contributes_nothing`, `test_a_rejected_week_is_excluded` and `test_it_is_tenant_isolated` (those create no day rows, so they land in the legacy branch unchanged).

- [ ] **Step 7: Run the wider API suite and static analysis**

Run: `php artisan test --compact tests/Feature/Api`
Expected: PASS.

Run: `vendor/bin/phpstan analyse app/Http/Controllers/Api/V1/ApiController.php --memory-limit=1G`
Expected: no errors. If larastan cannot infer the `days` collection's item type, add `/** @var \Illuminate\Database\Eloquent\Collection<int, TimesheetDay> $days */` above the `$days` assignment rather than loosening a type.

- [ ] **Step 8: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/Api/V1/ApiController.php tests/Feature/Api/TimesheetEffortApiTest.php
git commit -m "fix(api): count timesheet effort per submitted day, not per submitted week

Timesheets are submitted a day at a time (CR-03), but timesheet-effort
filtered on the week-level status, which only flips once every working day
is in. Track's nightly pull therefore saw nothing for a project until the
whole week closed, so money out lagged the work by up to five days.

An entry now counts when its own timesheet_days row is submitted or
approved. A timesheet with no day rows at all is pre-CR-03 and keeps being
judged by its week status - all 133 submitted weeks in the data are those,
and day-level gating without that fallback would zero every historical
figure in Track's ledger."
```

---

### Task 2: Make every published description state the day rule

**Files:**
- Modify: `docs/API.md:233-234` (the sentence "A week is counted once it is `submitted` or `approved`; draft and rejected weeks contribute nothing.")
- Modify: `app/Support/ApiReference.php:64-72` (the `/timesheet-effort` row)
- Modify: `public/openapi.json:318` (the `/timesheet-effort` `description`)
- Test: `tests/Feature/Api/ApiDocsTest.php` (existing, not edited — it must stay green)

**Interfaces:**
- Consumes: the behaviour shipped in Task 1.
- Produces: nothing other code reads. `ApiDocsTest` asserts only that each scope string and each route path appears, so the wording is free as long as the `/timesheet-effort` path and the `effort:read` scope stay present.

- [ ] **Step 1: Update `docs/API.md`**

Replace this sentence in the `GET /timesheet-effort` section:

```
A week is counted once it is `submitted` or `approved`; draft and rejected
weeks contribute nothing.
```

with:

```
Effort is counted **a day at a time**: a day's entries appear once that day
is `submitted` or `approved`, so a part-submitted week returns the days that
are in and nothing else. A day a manager sends back for correction stops
counting, which makes a project's figure go **down** on the next pull — that
is the rule working, not a fault. Weeks recorded before per-day submission
existed have no day rows at all and are still counted whole, once the week
itself is `submitted` or `approved`; draft and rejected ones of those
contribute nothing.

Cost from `person_days`. Mid-week `alloc_pct` can read lower than the band
really is: `days_present` counts the distinct dates the whole band submitted,
not each person's own days, so two people covering different halves of the
week report as half-dedicated. `person_days` stays exact throughout.
```

- [ ] **Step 2: Update the `ApiReference` row**

In `app/Support/ApiReference.php`, in the `/timesheet-effort` row, replace the `note` value:

```php
            'note' => 'Counted a day at a time: a day appears once it is submitted or approved, so a part-submitted week returns only the days that are in. Cost from person_days — mid-week alloc_pct reads low when a band submits different days. Aggregated server-side: no employee name, id or salary ever crosses the wire.',
```

Leave `path`, `scope`, `app_key`, `title`, `blurb`, `fields` and `query` exactly as they are.

- [ ] **Step 3: Update `public/openapi.json`**

Replace the `description` on the `/timesheet-effort` path (the string that currently begins "Requires the effort:read scope.") with:

```
Requires the effort:read scope. Aggregated server-side so no employee name, id, or salary ever leaves AmanahKu. Effort is counted a day at a time: a day's entries appear once that day is submitted or approved, so a part-submitted week returns only the days that are in, and a day sent back for correction stops counting. Weeks recorded before per-day submission have no day rows and are still counted whole, by the week's own submitted or approved status. Cost from person_days: mid-week alloc_pct reads low when the people in a band submit different days, because days_present counts dates across the band rather than per person. Only grant this scope to an app that costs work against the figures it returns.
```

Keep the rest of the file byte-identical — it is hand-maintained, nothing generates it.

- [ ] **Step 4: Verify the docs tests still pass**

Run: `php artisan test --compact tests/Feature/Api/ApiDocsTest.php`
Expected: PASS. A failure here means the edit broke the JSON or dropped the `/timesheet-effort` path or the `effort:read` scope string.

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add docs/API.md app/Support/ApiReference.php public/openapi.json
git commit -m "docs(api): timesheet-effort counts submitted days, not submitted weeks

The reference page, docs/API.md and openapi.json all still described the
week-level rule that CR-35 replaced."
```

---

### Task 3: Confirm the change against the real dev data

**Files:**
- No files changed. This task is a read-only check against the dev MySQL, whose copy of the production dump is what exposed the bug.

**Interfaces:**
- Consumes: the behaviour shipped in Task 1.
- Produces: nothing. It is the evidence that the CR did what it claims on real data rather than on fixtures.

- [ ] **Step 1: Confirm the legacy split still holds**

Run:

```bash
php artisan tinker --execute '
$legacy = DB::table("timesheets")->whereIn("status",["submitted","approved"])
    ->whereNotExists(fn($q) => $q->select(DB::raw(1))->from("timesheet_days")->whereColumn("timesheet_days.timesheet_id","timesheets.id"))->count();
$counted = DB::table("timesheets")->whereIn("status",["submitted","approved"])->count();
echo "submitted/approved weeks: $counted, of which legacy (no day rows): $legacy\n";'
```

Expected: both numbers equal (133 / 133 when this plan was written). If they diverge, some submitted week now has day rows, which is fine and is exactly the case Task 1's fifth test covers — note the numbers and carry on.

- [ ] **Step 2: Read the current week through the endpoint**

The dev data had exactly one submitted day in week `2026-09-14`, on a draft week, with one project entry against it. Before this change the endpoint returned `projects: []` for it.

Run:

The dev database holds three tenants, and the 2026-09-14 timesheet belongs to
tenant 1 — take the tenant from the timesheet rather than assuming, or the call
comes back as a tenant mismatch and you debug the wrong thing. `mintApiToken()`
defaults to the `*` ability, which satisfies `effort:read`.

```bash
php artisan tinker --execute '
$tenantId = DB::table("timesheets")->where("week_start","2026-09-14")->value("tenant_id");
$tenant = App\Models\Tenant::find($tenantId);
$token = App\Models\User::where("email","hidayahsuffya.unijaya@gmail.com")->sole()
    ->mintApiToken($tenant, "cr35-check")->plainTextToken;
echo "tenant {$tenant->slug}: {$token}\n";'
```

Then, with that token:

```bash
curl -s -H "Authorization: Bearer <token>" \
  "http://localhost:9100/api/v1/timesheet-effort?week_start=2026-09-14" | head -c 800; echo
```

Expected: `data.projects` is no longer empty — it carries the project that submitted day was booked to, with `days_present: 1`.

- [ ] **Step 3: Confirm a week nobody has touched still reads empty**

```bash
curl -s -H "Authorization: Bearer <token>" \
  "http://localhost:9100/api/v1/timesheet-effort?week_start=2026-09-07" | head -c 400; echo
```

Expected: `"projects": []`. No timesheets exist for that week, so nothing is invented.

- [ ] **Step 4: Confirm a historical week is unchanged**

Pick any week that has a submitted legacy timesheet:

```bash
php artisan tinker --execute '
echo DB::table("timesheets")->whereIn("status",["submitted","approved"])->orderByDesc("week_start")->value("week_start"), "\n";'
```

Call the endpoint for that week and check it returns the same figures it did before the change. If you did not capture a "before" reading, compare against `project_week_notes.staff_allocation` in the Track database for the same week: those rows were written by the old rule.

- [ ] **Step 5: Record the readings in the session handoff**

Write the three readings (current week, empty week, historical week) into `docs/build/sessions/<id>/handoff.md` under a "CR-35 verification" heading. No commit of code; the handoff commit is the session's own.

---

## Notes for whoever picks this up

- CR-35 is not one of the 34 CRs split from the original tracker, so it has no session slot in `docs/build/BUILD-PLAN.md` and no tracker row. That registration is Shazwan's call, not this plan's.
- Track needs no change and must not be touched by this work. Its `amanahku:sync-effort` command already runs nightly over a four-week window, so the first run after this ships will pull the corrected figures for the current week and the three behind it on its own.

---

## Verification readings (2026-09-18)

Taken against the dev MySQL through the running app at `http://localhost:9100`,
after Task 1 shipped. No session id exists for this work, so the readings live
here instead of a session handoff. This section is deliberately uncommitted.

**Legacy split:** `submitted/approved weeks: 133, of which legacy (no day rows): 133`
— unchanged from when the plan was written, so the fallback branch still covers
every historical week.

**Current week `2026-09-14`** (tenant `unijaya-resources-sdn-bhd`, week status
still `draft`, one submitted day). Before the change this returned `projects: []`:

```json
{"data":{"week_start":"2026-09-14","projects":[{"project_id":8,"positions":[
{"position_id":60,"position_title":"Intern DevOps","headcount":1,
"person_days":1,"days_present":1,"alloc_pct":100}]}]},"error":null}
```

**Empty week `2026-09-07`** (no timesheets at all):

```json
{"data":{"week_start":"2026-09-07","projects":[]},"error":null}
```

**Historical week `2026-08-24`** (latest week with a submitted legacy timesheet).
No "before" reading was captured, so the endpoint was compared against the old
rule re-expressed as SQL (entries joined to `submitted`/`approved` weeks,
grouped per project). Every project matches once the endpoint's per-position
rows are summed — for example project 2 reads 0.38 + 0.21 = 0.59 person-days
against the old rule's 0.59, and project 8 reads 0.25 + 0.62 = 0.87 against
0.87. All 14 projects agree. Legacy weeks are untouched.
