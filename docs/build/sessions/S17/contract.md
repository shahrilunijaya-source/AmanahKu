# Session S17 contract: CR-14a (award computation and frozen snapshot)

Shapes below follow `docs/build/OPEN.md`'s frozen "QA / CR-14a / shapes fixed by CR14aTest"
entry verbatim. This session builds the compute/freeze/publish half only. The carousel, Awards
screen, nominations, manual awards, profile badge and Director override are S18's (CR14bTest);
acceptance items 2, 4, 5 are `markTestIncomplete` in the frozen test and stay that way.

## Files touched

- `database/migrations/2026_09_22_100000_create_award_tables.php` — new
- `app/Support/Awards.php` — new (one computation method per award key, tenant-scoped, reused
  by S18)
- `app/Console/Commands/AwardsFreeze.php` — new (`awards:freeze`)
- `app/Console/Commands/AwardsPublish.php` — new (`awards:publish`)
- `bootstrap/app.php` — register the two commands in `withSchedule`
- `tests/Feature/AwardsTest.php` — new, covers the 8 awards CR14aTest doesn't drive, ties,
  a holiday landing on the 1st, cross-tenant isolation

No models needed for the two new tables — both are read/written with `DB::table()`, matching
how `CR14aTest` itself asserts them (raw query builder, no Eloquent row needed on either side).

## Schema changes

One migration, `2026_09_22_100000_create_award_tables.php`:
- `award_snapshots`: `id, tenant_id (FK cascade), month (date), award_key (string 40),
  employee_id (FK employees cascade), value (decimal 10,2), label (string 255), frozen_at
  (datetime), timestamps`, unique (`tenant_id`, `month`, `award_key`, `employee_id`). Carries
  its own human label so `awards:publish` never recomputes one from a bare value.
- `award_results`: `id, tenant_id (FK cascade), month (date), award_key (string 40),
  employee_id (FK employees cascade), value (decimal 10,2), label (string 255), source
  (string 10, default 'auto'), reason (string 255 nullable), published_at (datetime),
  timestamps`, unique (`tenant_id`, `month`, `award_key`, `employee_id`).

Applied to the dev DB via `lerd artisan migrate --no-interaction`, verified read-only with
`mysql -h127.0.0.1 -uroot amanahku -e 'describe award_snapshots; describe award_results'`.

## Command design

- `awards:freeze`, `dailyAt('23:59')` (cron `59 23 * * *`). Tenant loop (same shape as
  `CreateManagementMeetingTasks`). Per tenant: no-op unless today is the last calendar day of
  the month; no-op if `award_snapshots` already has rows for this tenant+month (idempotent).
  Computes all 15 auto award keys via `Awards::compute()` and inserts one row per
  (employee, award_key) the person is eligible for, `frozen_at = now()`.
- `awards:publish`, `dailyAt('08:00')` (cron `0 8 * * *`). Tenant loop. No-op unless today is
  the first working day of the month (`App\Timesheet\DayRules::isWorkingDay`, walking day 1
  forward). No-op if `award_results` already has rows for this tenant+previous month
  (idempotent). Reads the previous month's `award_snapshots`, resolves winners in award-key
  list order applying rule 9 (2-award cap per person) and rule 10 (no repeat winner, checked
  against last month's `award_results`), inserts one `award_results` row per winner (ties: one
  row each), writes one `AuditLog::record('awards.published', ...)` row (action contains
  "award"), sends one `AppNotification::sendMany()` app-only notification (title contains
  "award", `mail: false`) to every active employee with a `user_id`, deduped per tenant+month
  so a second same-morning run adds nothing.

## Award formulas (docblock in `Awards.php` carries the same text)

Driven directly by `CR14aTest`: `deadline_who`, `done_and_dusted`, `chief_firefighter`,
`not_my_task`, `zero_overdue`, `billable`, `beating_the_traffic`. Completion credit reads the
first `audit_logs` row with `subject_type = WorkItem::class`, `field = 'status'`,
`new_value = '"done"'`, falling back to `done_at` only for a card with no such row (created
already done). Excluded everywhere: `type = event`, non-null `source`, the `recurring`/
`system` labels, a `recurring_task_occurrences` row. Subtasks are invisible too — `WorkItem`'s
default `ParentOnly` scope already hides them from every plain query here; logged as an OPEN
entry rather than special-cased, since no acceptance test exercises a subtask completion.

Not driven by `CR14aTest` (all read "no data → no row", verified by the new feature test):
- `never_late`: eligible with ≥1 `standard` attendance record this month and zero `late`
  records; value = count of on-time records.
- `always_here`: eligible only with a record on every working day of the month
  (`DayRules::isWorkingDay`); value = that count.
- `clockwork_royalty`: value = the longest run of consecutive on-time attendance dates in the
  month; eligible when that run is ≥1.
- `timesheet_done`: eligible only with a compliant (`late = false`, submitted) `TimesheetDay`
  row for every working day of the month; value = that count.
- `mic_drop_mentor`: value = count of `TotReaction` rows this month on sessions the person
  presented (solo or team); no vote table exists, this is the closest sourceable reading.
- `question_department`: value = count of distinct `TotSession` ids the person left a
  `TotComment` on this month.
- `walking_wikipedia`: value = count of `KnowledgeEntry` rows the person authored this month.
- `chief_hype_officer`: value = count of distinct colleagues the person reacted to this month
  across `BirthdayWishReaction`, `TotReaction` and `KnowledgeReaction` (recipient = the wish's/
  session's/entry's owner), excluding reacting to themselves.

## Rule 9 / rule 10 resolution

Walk `Awards::KEYS` in order. Per award, sort its snapshot rows into value-order (ascending for
`beating_the_traffic`, descending otherwise), grouped by tied value. Walk groups best-first:
within a group, keep only people who (a) did not win this exact award last month and (b) hold
fewer than 2 wins so far this publish run. First non-empty filtered group wins (ties: everyone
in it); increment each winner's running count; move to the next award. An exhausted walk (every
group filtered to empty) leaves the award with no result row.

## Acceptance items — how each is verified

1. **September publishes 1 Oct from the frozen snapshot.** `assertScheduled` for both cron
   lines; freeze/publish no-ops before their trigger day; `deadline_who`/`done_and_dusted`/
   `billable` snapshot values; snapshot immune to a post-freeze archive or late approval;
   audit + notification rows; second-run idempotency; late approval counts into October
   instead (billable's decided-after-freeze rule); 1 Nov Sunday defers to 2 Nov. Verified by
   `test_acceptance_1_*`.
2. Carousel — S18, `markTestIncomplete`.
3. **Last month's winner can't repeat.** Rule 10 walk with the seeded August `award_results`
   row; Emysha stays under the 2-award cap because rule 10 (not rule 9) is what blocks her.
   Verified by `test_acceptance_3_*`.
4. Awards screen / nominate-select — S18, `markTestIncomplete`.
5. Profile badge — S18, `markTestIncomplete`.
6. **Owner credit vs helper credit.** `not_my_task` reads `work_item_participant.role =
   'helper'`; completion awards read `employee_id` only. Verified by `test_acceptance_6_*`.
7. **Reopen/redo counts once.** First-done-transition read from `audit_logs`, not `done_at`,
   so a card done in August and redone in September contributes nothing to September.
   Verified by `test_acceptance_7_*`.
8. **Event/system/recurring cards never count.** Exclusion filter applied identically to every
   award's card query, including `zero_overdue`'s assigned-card floor. Verified by
   `test_acceptance_8_*`.
9. **Zero overdue needs ≥5 assigned cards.** Eligibility floor checked before the value is
   written to `award_snapshots`. Verified by `test_acceptance_9_*`.
10. **Two-award cap passes the third to the runner-up.** List-order walk with the running
    per-person win count. Verified by `test_acceptance_10_*`.
