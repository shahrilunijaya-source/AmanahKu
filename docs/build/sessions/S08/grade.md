# QA grade: S08 CR-03 daily timesheet submission

**Result: PASS.** Zero FAIL after one fix. Graded on the dev DB with Playwright on the
worktree vhost, logged in as Shazwan (staff, reports to Kussairi) and Kussairi (manager),
using the dev clock. Lines were typed into the grid through the screen's own percentage
inputs where the item is about a line; draft rows for a day were seeded through the same
save endpoint the screen calls. Every submit, return, approve, unlock and reason box was
clicked on screen. Audit rows read with `mysql`.

## Finding fixed during the grade

**F1 (fixed, commit e42d18cb).** The first build (04bce380) named the new per-day status
map `days` inside the capture component, the same property that already held how many
days the week strip shows. The strip rendered empty and Submit week stayed disabled on
every week. Renamed to `dayStatuses`; items 1, 2, 5, 6 and 7 above were driven before
the fix through Submit day, which did not depend on the strip, and item 3 was driven
after it. Re-checked the week strip and Submit week gate on both weeks after the fix.

A wording nit found under Keep it plain ("Tuesday and Wednesday has no lines") was
corrected to "have" in aeb5c7eb.

## Acceptance items (docs/specs/CR-03.md, as CR03Test numbers them)

| # | Item | Result |
|---|------|--------|
| 1 | Friday alone submits: Monday 40% draft + Friday 100%, Submit day on Friday | **PASS** `grade-cr03-1-friday-submitted.png`. `timesheet_days` row for 11 Sep only, status submitted, late 0; Monday line kept; audit `day.2026-09-11.status` null to submitted |
| 2 | Submitted day locked; manager returns with a comment; staff edits and resubmits; all logged | **PASS** Friday input disabled and API refusal "Fri, 11 Sep is submitted and locked — ask your manager to return it for correction."; staff return 403; Kussairi Return with reason from the report (`grade-cr03-2-manager-returned.png`); Shazwan sees Returned plus the comment (`grade-cr03-2-staff-returned.png`), button reads Resubmit day, 60/40 resubmitted; audit rows 1050 (user 6, returned, reason), 1052 and 1054 (`day.2026-09-11.entries` 100 to 60 to 60+40, user 27), 1055 (returned to submitted) |
| 3 | Submit week checks each day, skips leave and holiday | **PASS** week of 14 Sep with approved leave Tue 15 and Malaysia Day Wed 16: screen blocks with "Thursday not filled yet", server answers "Thu, 17 Sep totals 60% — that day must add up to 100% before submitting."; after Thursday 100 the week submits, Mon/Thu/Fri rows submitted, no row for Tue or Wed, week badge Submitted (`grade-cr03-3-week-*.png`) |
| 4 | Late submission flagged after 10:00 next working day | **PASS** Monday 14 Sep submitted Tue 15 Sep 10:01 shows Late on the capture header and "Late submission" on Kussairi's report row (`grade-cr03-4-late-*.png`); Friday 11 Sep submitted Friday 12:00 is not late |
| 5 | Zero-hour day needs a reason | **PASS** Thursday 10 Sep empty: Submit day opens the reason box, Confirm disabled until text, submitted with `zero_reason` "Offsite at client, no allocation", shown on both screens (`grade-cr03-5-zero-reason.png`) |
| 6 | Edit window 3 working days, manager unlock | **PASS** on Friday 11 Sep Monday 7 Sep reads "Locked. Ask your manager to unlock it.", input disabled, API "Mon, 7 Sep is more than 3 working days back — ask your manager to unlock it.", self unlock 403; Kussairi Unlock with reason (`grade-cr03-6-manager-unlocked.png`); Shazwan edits 40 to 100 and submits (`grade-cr03-6-monday-resubmitted.png`); `unlocked_by_id` 5 |
| 7 | Sidebar Timesheet % follows approvals | **PASS** 0% before, 40% after Kussairi approves Monday and Friday of a five-day week (`grade-cr03-7-*.png`); 33.3% on Wednesday 9 Sep with one approved day of three |

## Every-session checks

| Check | Result |
|-------|--------|
| `php artisan test --compact tests/Acceptance/CR03Test.php` | PASS, 8 tests, 102 assertions |
| Due date change via API (`PATCH /app/board/310 {due_at}` as Shazwan) | PASS, 422 "Due dates are locked after the first save" |
| Audit-log row edit and delete via the model | PASS, both throw "audit_logs rows are append-only" |
| Dashboard as Shazwan on quiet 2026-09-09 | PASS, cards summary, clock, tasks, leave, style; calendar, notices, flowers, claims, work; same as baseline (`grade-cr03-dashboard.png`) |
| Keep it plain | PASS, greeting "Good morning, Shazwan.", no animation on the timesheet card, new text plain in both screens (`grade-cr03-plain*.png`) |
| Diff grep for outbound calls (Http::, guzzle, googleapis, brevo, smtp, Mail::, Notification::, curl, Google\) on e5aff1b4..aeb5c7eb | PASS, no hits |
| OPEN entries name alternatives and reversal cost | PASS, "S08 / CR-03 / edit window, first save, approved days" |
| Protected files untouched | PASS, `git diff --stat` over the S08 commits touches none of CLAUDE.md, RULES.md, contracts, tests/Acceptance |
| Full suite | PASS, 2733 tests, 2728 passed, 5 skipped, 9 incomplete |

## Notes for the next session

- Dev DB now holds Shazwan's weeks of 7 Sep (timesheet 111: Mon and Fri approved, Thu
  submitted with a zero reason, Tue and Wed untouched) and 14 Sep (Mon, Thu, Fri
  submitted, week Submitted), plus leave request 27 (approved unpaid leave 15 Sep) as a
  fixture. Audit rows 1044 onward are the grade. Dev clock reset, plain pref off.
- The manager strip lists Monday to Friday only; the first-Saturday TOT half day shows
  once a row exists for it. Fine for CR-03, worth a glance when CR-09 touches Saturdays.
