# QA grade: S17 / CR-14a (monthly award freeze, publish and computation)

**Verdict: PASS after fixes F1 to F6.** The S17 build passed `CR14aTest` as delivered, but
driving the real August data on the dev database (148 snapshot rows, 34 staff) exposed six
computation and delivery defects the fixture-sized acceptance test could not see. All six are
fixed in this grade, each pinned by a feature test in `tests/Feature/AwardsTest.php`
(`f1_` to `f6_`), and the August re-run now produces sane winners.

Graded on `http://worktree-change-request-tracker.amanahku.localhost`. CR-14a has no screen
(the Awards page, band and nominations are CR-14b, S18), so the freeze and publish were
driven with `Carbon::setTestNow(...)` + `Artisan::call(...)` in one tinker process against
the dev database, first on the S17 tree and again after the fixes.

## Fixes made during the grade

| # | Defect (S17 tree) | Fix | Test |
|---|-------------------|-----|------|
| F1 | `billable` snapshot rows with `0.00` hours: five people "won" billable with no approved client hours | `Awards::billable()` skips sums `<= 0` | `f1_zero_approved_client_hours_is_not_a_billable_value` |
| F2 | `clockwork_royalty` streak reset on every weekend, so August was an 18-way tie at 5 | streak walks working days only (`DayRules::isWorkingDay`, incl. the TOT Saturday); weekends, holidays and approved leave are neutral; a missing record, null clock-in or `late` status resets | `f2_the_on_time_streak_runs_across_weekends_holidays_and_approved_leave` |
| F3 | `always_here` and `timesheet_done` ignored approved leave (a person on leave could never qualify) and counted only `standard` attendance type; no incomplete-shift rule | required days = working days minus approved leave; all attendance types count; a record with clock-in and no clock-out disqualifies `always_here` | `f3_approved_leave_and_home_days_are_neutral_for_full_attendance_and_timesheets` |
| F4 | `resolveWinners` grouped by `(float) value`; PHP truncates float array keys to int, so -4.53 and -4.90 became one "tie" (deprecation "Implicit conversion from float to int") | group by the two-decimal string, order groups numerically | `f4_close_decimal_values_are_not_a_tie` |
| F5 | publish notified all 34 employees with users, including 5 resigned/archived | recipients = `Employee::active()->where('status','active')->whereNotNull('user_id')` (29) | `f5_only_active_staff_are_told_the_awards_are_out` |
| F6 | `mic_drop_mentor` credited only the last presenter of a team TOT session | every presenter of the session gets the reactions | `f6_a_team_session_credits_every_presenter` |

## Acceptance items

| # | Item | Result | Evidence |
|---|------|--------|----------|
| 1 | Awards freeze on the last day, publish on the first working day, monthly | PASS | `CR14aTest::test_acceptance_1_*` green (schedules `59 23 * * *` / `0 8 * * *`, gating, values, idempotent freeze and publish, Sunday 11-01 skipped, 11-02 publishes). Dev DB: freeze at 2026-08-31 23:59 wrote 148 snapshot rows across 10 keys; a second freeze wrote 0. Publish at 2026-09-01 08:00 on the S17 tree wrote 13 result rows and 34 notifications (`August 2026 awards are out!`, url `/app/awards`); after the fixes the same snapshot resolves to 13 winners with no 0.00 billable and no 18-way streak tie (see notes), notifications go to 29 active staff. |
| 2 | Awards band / carousel on the dashboard | S18 | `markTestIncomplete` in `CR14aTest`; CR-14b. |
| 3 | `beating_the_traffic` median vs previous month, rule 10 no repeat winner | PASS | `test_acceptance_3_*` green (August winner Emysha excluded, Adri wins). Dev: Rubmin -57.58 (58 minutes earlier than expected). |
| 4 | Awards screen under The Playground | S18 | CR-14b. |
| 5 | Nominations and manual awards | S18 | CR-14b. |
| 6 | Owner vs helper credit (`done_and_dusted` vs `not_my_task`) | PASS | `test_acceptance_6_*` green. Dev: DzulHazly 18 done, Hidayah helped on 8. |
| 7 | A card counts once, in the month it was first done | PASS | `test_acceptance_7_*` green (done/reopen/done = 1; an August-first-done card is 0 for September). |
| 8 | Events, recurring, management-meeting and auto-done cards never count | PASS | `test_acceptance_8_*` green (exactly 1 result from the one real card). |
| 9 | `zero_overdue` eligibility floor | PASS | `test_acceptance_9_*` green. Dev August: nobody qualified (every active card owner had at least one overdue), no row written. |
| 10 | Rule 9 max two awards a person, cascade to the runner-up | PASS | `test_acceptance_10_*` green (Ahmad wins two, third goes to Nurin). Dev: Hakime and Khusalam tie on `never_late` and `clockwork_royalty` (20 of 20 days) and stop at two each. |

## Every-session checks

| Check | Result |
|-------|--------|
| `php artisan test --compact tests/Acceptance/CR14aTest.php tests/Feature/AwardsTest.php` | 24 passed (3 incomplete: items 2, 4, 5). Full suite after the fixes: 2882 passed, 0 failed, 5 skipped, 16 incomplete. |
| Task due date via API | As Kussairi `PATCH /app/board/196 {due_at: 2026-10-30}` → 422 "Due dates are locked after the first save…". |
| Edit an audit-log row | `AuditLog::orderByDesc('id')->first()->update(['action' => 'tampered'])` → RuntimeException "audit_logs rows are append-only". |
| Dashboard as plain staff on a quiet day | Shazwan, clock 2026-09-09 09:00: no band, no panel, ten widgets in baseline order (summary, clock, tasks, leave, style; calendar, notices, flowers, claims, work) (`grade-cr14a-dashboard-staff.png`). Clock reset to real. |
| Keep it plain | Toggled on for Shahril: greeting drops to "Good evening, Shahrilnizam.", `.uj-db[data-plain]` rendered, notifications page plain (`grade-cr14a-plain-director.png`). Toggled back off. CR-14a adds no screen; its notification body is "See who won this month." / "No awards were eligible for a winner this month.", no cheek in either. |
| Outbound calls in the diff | None (`Http::`, guzzle, googleapis, `Mail::`, curl absent from `app/`, `routes/`, `bootstrap/`, `resources/`, `database/` in `34546059..HEAD` plus the grade fixes). |
| OPEN entries | The QA shapes entry, S17's two entries and this grade's entry name alternatives and a reversal cost. |
| Protected files | `CLAUDE.md`, `docs/build/RULES.md`, `docs/build/contracts/*`, `tests/Acceptance/*` untouched by S17 and by this grade (`git diff 34546059..HEAD --stat` on those paths is empty). |

## Design notes (accepted, not defects)
- `deadline_who` counts a card done on or before its due date; the spec says "before the due
  date". Same-day completion is the everyday case and the acceptance test fixture was
  written that way, so on-or-before stands. Reversal: change one `lte` in `Awards::deadlineWho`.
- The publish notification links to `/app/awards`, which exists only after S18. Until then the
  link 404s; the notification itself is right and S18 fills the route.
- If the scheduler misses the last day (freeze) or the first working day (publish), that month
  is never frozen or published; there is no catch-up. Follow-up for the ops runbook, not a FAIL.
- `question_department` is structurally capped at one per month (one `tot_sessions` row per
  tenant/month), as S17's OPEN entry records.

## Notes for the next session
- Dev DB end state: `award_snapshots` and `award_results` empty, the August publish
  notifications deleted; audit row 1096 `awards.published` target `2026-08-01` stays
  (append-only), so `awards:publish` will not re-publish August on dev without a fresh month.
  Dev clock real; Keep it plain off.
- S18 (CR-14b) builds `/app/awards`, the band and nominations on top of `award_results`;
  `award_results.source` (`auto` / manual keys) and `reason` are already in place for the
  Director override.
