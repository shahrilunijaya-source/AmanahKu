# Session S08 contract: CR-03 daily timesheet submission

Spec: `docs/specs/CR-03.md`. Tests: `tests/Acceptance/CR03Test.php`. Shapes follow OPEN "QA / CR-03 / shapes fixed by CR03Test".

## Files

- `database/migrations/2026_09_13_100000_create_timesheet_days.php`: table `timesheet_days`.
- `app/Models/TimesheetDay.php` (new), `app/Models/Timesheet.php` (`days()` relation, derived week status helper).
- `app/Timesheet/DayRules.php` (new): working-day arithmetic for the 3-working-day edit window and the 10:00 next-working-day deadline; frozen-day comparison.
- `app/Timesheet/WeekWriter.php`: `save()` gains `submitDay` / `dayReason`; frozen days (submitted, approved, or beyond the edit window without unlock) must keep their stored lines; per-day submit; submit week = every unsubmitted working day up to today, all-or-nothing; line-change audit per day; week status derived from the days.
- `app/Http/Controllers/TimesheetController.php`: `store()` validates `submit_day`, `day_reason`; new `returnDay`, `approveDay`, `unlockDay`, `approveWeek`; `recall()` puts submitted (not approved) days back to draft; `personWeeks()` and `buildWeekBlocks()` carry day statuses for the manager view; `screenData()` carries them for the capture screen.
- `routes/web.php`: `POST /app/timesheets/{employee}/days/{date}/return | approve | unlock`, `POST /app/timesheets/{employee}/approve-week`.
- `app/Http/Controllers/AppController.php`: sidebar Timesheet % = approved days ÷ working days Monday to today.
- `config/manday.php`: `day_submit_deadline` (default `10:00`), `edit_window_working_days` (default 3).
- `resources/views/partials/timesheet-report/person-weeks.blade.php`, `resources/views/screens/timesheets.blade.php`, `resources/js/timesheet-capture.js`: per-day badges, Submit day, Returned comment + Resubmit, zero-hour reason, manager Return / Approve / Unlock / Approve week.
- `tests/Feature/TimesheetTest.php` and siblings: the tests that pinned "submit needs the week to be over" and "empty days pass" move to the CR-03 rule.
- `tests/Feature/TimesheetDayTest.php` (new): deadline and edit-window arithmetic around holidays and the TOT Saturday, recall of a partly approved week, approve-week.

## Schema

`timesheet_days`: `id, tenant_id FK, timesheet_id FK cascade, entry_date date, status string(10) default draft, submitted_at nullable, late bool default false, resubmitted bool default false, zero_reason text nullable, return_reason text nullable, unlocked_at nullable, unlocked_by_id FK employees nullable, timestamps, unique(timesheet_id, entry_date)`. Run on the dev DB with `lerd artisan migrate`.

## Verification per acceptance item

1. Friday-only submit: CR03Test 1; browser as Shazwan on a Friday via the dev clock, Submit day on Friday with Monday half-filled.
2. Lock, return, resubmit, log: CR03Test 2; browser as Shazwan (day read-only), Kussairi returns it from the report with a reason, Shazwan edits and resubmits, audit rows checked in the DB.
3. Submit week per-day validation, leave and holiday skipped: CR03Test 3; browser with Christmas 2026 and an approved leave day in the same week.
4. Late submission: CR03Test 4; dev clock Tuesday 10:01, Monday shows "Late submission" on the report.
5. Zero-hour reason: CR03Test 5; browser prompt.
6. Edit window and unlock: CR03Test 6; browser: old day refused, Kussairi unlocks, Shazwan edits.
7. Timesheet %: CR03Test 7; sidebar figure as Shazwan after Kussairi approves two of three days.
8. The four always checks.
