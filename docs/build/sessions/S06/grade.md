# QA grade: S06 CR-18 (recurring tasks)

**Result: PASS.** Zero FAIL. Graded on the dev DB (commit d85b62a2) through the worktree
vhost with Playwright, plus `lerd artisan work:recurring --on=<date>` for the engine, and
`mysql` to read what landed. Screenshots `grade-cr18-*.png` in this folder.

## Acceptance items

| # | Item | Result |
|---|------|--------|
| 1 | Recurring task "Organise company social activity", every 2 months, owner Finance Manager (Project Manager on this data), tagged HR + Admin + Finance, due end of month | **PASS** |
| 2 | Subtasks pre-filled; completing "Create Event" links an Event with all staff as attendees | **PASS** |
| 3 | A Cancelled or unattended past Event does not close the task; only Approved + Held + attendance + evidence does | **PASS** |
| 3b | Owner leaves, next occurrence goes to the current holder; HR reassigns any open one | **PASS** |
| 3c | Skip with reason, nothing created; later occurrence still created; Pause stops creation until Resume | **PASS** |
| 3d | Public holiday on the period start defers to the next working day; one occurrence per period even when the job runs twice | **PASS** |
| 4 | Next occurrence created automatically on the day | **PASS** |
| 5 | HR sets up a second schedule (quarterly fire drill) on the same screen | **PASS** |

Item 1 detail. Card 317 on Kussairi's board (`grade-cr18-card317.png`): title from the
schedule, label Recurring, due 30 Nov 2026, Helpers Hidayah, Ain, Alya, Aminah, five
subtasks in template order with the same due date. Made by
`work:recurring --on=2026-11-01` (a Sunday, created that day as CR18Test item 1 expects).

Item 2 detail. Event "Bowling night" (id 4, 20 Nov 2026) with an RSVP for 34 of 35 active
staff: the drawer's Linked event row refused with "Add everyone as an attendee first: 1 of
35 staff are not on this event yet." (`grade-cr18-link-refused.png`). With the last RSVP
added, Link stored `company_event_id = 4`, ticked subtask 320 "Create Event in The
Playground with all staff as attendees" and left the other four open
(`grade-cr18-linked.png`); audit rows `work_item.company_event_id` on 317 and
`work_item.status` todo to done on 320, actor Kussairi.

Item 3 detail. All five subtasks ticked, then `POST /app/board/317/move {status: done}` as
Kussairi under seven event states:

| Event state | Response |
|-------------|----------|
| draft, future, no RSVP attended, no evidence | 422 "Missing: approved, held, date passed, attendance (0 of 1), evidence." |
| cancelled + approved + past + attended + evidence | 422 "Missing: held." |
| held + approved + past + evidence, nobody attended | 422 "Missing: attendance (0 of 1)." |
| held + approved + past + attended, no evidence | 422 "Missing: evidence." |
| held + approved + attended + evidence, dated 20 Nov 2026 (future) | 422 "Missing: date passed." |
| held + past + attended + evidence, not approved | 422 "Missing: approved." |
| all five satisfied | 200, card 317 Done, audit `work_item.status` + `work_item.done_at` |

Item 3b detail. Schedule 2 "Quarterly fire drill" owned by position "Grade Safety Officer"
(id 63). Holder Grade Officer One (employee 35) archived, Grade Officer Two (employee 36)
given the position: `resolveOwner()` returned 36 and `--on=2027-10-01` made card 344 for
employee 36, due 2027-10-31. Before the archive the July run had correctly gone to 35
(card 335). HR `POST /app/board/323/reassign {employee_id: 1, reason}` 200: owner 35 to 1,
both open subtasks 324/325 moved to 1, due date still 2026-10-31, audit row
`work_item.employee_id` 35 to 1 with the reason, actor Hidayah, source ui. Reassign as
staff 403, as manager 403 (spec says HR; `management` also allowed by design), HR without a
reason 422, HR to the same owner 422.

Item 3c detail. Period 2027-01-01 of schedule 1 skipped "Year-end close" in S06; runs on
1 to 5 Jan 2027 created nothing for it. Skip without reason 422 "The reason field is
required."; skip of a period that already has a card 422 "That period already has a card.
Cancel the card instead."; skip and pause as staff 403, as manager 403. HR paused
schedule 1, `--on=2027-03-01` created 0. HR resumed (`grade-cr18-resumed.png`),
`--on=2027-07-01` created only the July card 329 (no March or May back-fill, per OPEN
"S06 / CR-18"). `--on=2027-10-01` then made September's card 338.

Item 3d detail. Public holiday "New Year 2027" added on 2027-01-01. Engine on 1, 2, 3 Jan
2027 created 0; 4 Jan 2027 (Monday) created 1 (drill card 326, due 2027-01-31); a second
run on 4 Jan created 0; 5 Jan created 0. Occurrence table holds exactly one row per
(schedule, period).

Item 4 detail. Every `--on` run above created the period's card on the day itself without
any manual step; the 06:00 daily scheduler entry is pinned by CR18Test item 4.

Item 5 detail. As Hidayah on `/app/recurring`: the form with an empty owner showed
"Name an owner: a person or a position." inline (`grade-cr18-form-error.png`); a
title-only API POST returned 422; the drill schedule saved with position owner, low
priority, two subtasks and listed with its holder (`grade-cr18-second-schedule.png`).
Staff and manager get 403 on GET `/app/recurring` and on every admin POST.

## Every-session checks

| Check | Result |
|-------|--------|
| `php artisan test --compact tests/Acceptance/CR18Test.php` | PASS, 9 tests, 138 assertions |
| Due date change via API (`PATCH /app/board/329 {due_at}` as owner) | PASS, 422 "Due dates are locked after the first save", value unchanged |
| Audit-log row edit and delete via the model | PASS, both throw "audit_logs rows are append-only" |
| Dashboard as Shazwan on quiet 2026-09-09 | PASS, left summary, clock, tasks, leave, style; right calendar, notices, flowers, claims, work; no band, no `data-kind`, same as baseline and S05 (`grade-cr18-dashboard.png`) |
| Keep it plain | PASS, greeting "Good morning, Shazwan.", cards unchanged, only the pre-existing tile entrance animation, no cheeky text on the recurring screen or the drawer row (`grade-cr18-dashboard-plain.png`) |
| Diff grep for outbound calls (Http, guzzle, googleapis, brevo, smtp, Mail::, track, curl) | PASS, no hits in d85b62a2 |
| OPEN entries name alternatives and reversal cost | PASS, "QA / CR-18" and "S06 / CR-18" both do |
| Protected files untouched | PASS, `git show --stat d85b62a2` touches none of CLAUDE.md, RULES.md, contracts, tests/Acceptance |

## Notes for the next session

- Dev DB left with: schedules 1 and 2; occurrences 1/2026-11-01 (317, Done), 1/2027-01-01
  skipped, 1/2027-07-01 (329), 1/2027-09-01 (338), 2/2026-10-01 (323, owner 1),
  2/2027-01-01 (326), 2/2027-07-01 (335), 2/2027-10-01 (344); event 4 "Bowling night"
  held, approved, dated 2026-09-01, all RSVPs attended, evidence note set; public holiday
  2027-01-01; position 63 with both holders (employees 35, 36) archived. Audit rows stay.
  Dev clock reset, plain pref off.
- `App\Mcp\Tools\MoveCardTool` still bypasses the linked-event Done rule (handoff says so);
  not in this CR's acceptance list, so not a FAIL here. Worth one line in whichever session
  next touches the MCP tools.
