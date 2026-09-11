# QA grade: S16 / CR-34 (Friday management-meeting task and deferred 3 PM reminder)

**Verdict: PASS**, no fixes needed. The S16 build passes `CR34Test`, and every acceptance
item was driven end to end on the dev site: the daily task command, the boards, the Done
move, the reminder command, the Director's overdue panel at 5 PM, the holiday shift to
Thursday, the pause, and the settings screen with its 403 for a plain manager.

Graded on `http://worktree-change-request-tracker.amanahku.localhost` with the quick-login
accounts. Cast: Shahril is the Director, Hidayah is HR, Kussairi plays Ahmad (manager, PM on
project 36 "KPT: RMS (QA CR-06b)"), Shazwan plays Nurin (PE on that project, reports to
Kussairi). The two commands run daily from the scheduler, so they were driven with
`Carbon::setTestNow(...)` + `Artisan::call(...)` in one tinker process (the browser dev clock
is session-scoped and artisan does not see it). The dev site had no
`management_meeting_settings` row before the grade; the defaults applied (Friday, 17:00,
task 08:00, reminder 15:00, roles manager/hr/management/director).

## Fixes made during the grade

None.

## Acceptance items

| # | Item | Result | Evidence |
|---|------|--------|----------|
| 1 | Friday 8:00 AM, Ahmad, Nurin and Yati each have their own 'Update Track for management meeting' card, due 5 PM, on their own boards | PASS | `management:meeting-tasks` at Thursday 2026-09-10 08:00 created 0 cards; at Friday 2026-09-11 08:00 it created 12 (ids 255 to 266: the live PM/PE on project 36 plus every manager, hr, management and director), each `todo`, due 2026-09-11, project 37 "URSB : Management meeting" (auto-created), labels `["system"]`, `source=management_meeting`, `source_ref=2026-09-11`; a second run the same morning created 0. Kussairi's board shows only his card 259 in To do (`grade-cr34-1-kussairi-board.png`), Shazwan's only his card 264 (`grade-cr34-1-shazwan-board.png`); neither sees the other's. Schedule `0 8 * * *` pinned by the test. |
| 2 | Friday 3:00 PM, all managers receive the same generic email with the 'Open Track' button (DEFERRED, MailPort intent) | PASS | `management:meeting-reminder` at 2026-09-11 15:00: `port_outbox` row 6 (port `mail`, kind `management_meeting_reminder`, subject "Management meeting today 5 PM, update your Track", body_en "Hello managers, let's prep for the management meeting. Please update your Track entry before 5 PM. Open Track." with the BM twin, `to` = the 12 recipients' emails, one row for the tenant), 12 `app_notifications` "Management meeting today: update Track" (1585 to 1596), audit row 1069 "Sent management meeting reminder". Second run the same day: 0 tenants, nothing added. Shazwan's notifications page lists the row. No mail left the machine. Schedule `0 15 * * *`. |
| 3 | Ahmad updates Track and drags his card to Done; Nurin's card remains open on her board only | PASS | Kussairi `POST /app/board/259/move {status: done}` → 200, card 259 sits in Done on his board; Shazwan's card 264 stays in To do on his board and is absent from Kussairi's. Shazwan moving Kussairi's card → 403. (Kussairi can also move Shazwan's card: he is Shazwan's line manager, which the board's existing `canManage` rule allows for every card; the acceptance test's 403 case is a person with no line or project relation. Card 264 was put back to `todo` for item 4.) |
| 4 | After 5 PM, Nurin's card shows overdue on the Director's CR-17 panel; it never counts against any award | PASS | Shahril's dashboard at dev clock 2026-09-11 12:00: no `data-card="264"` on the band. At 17:30: card 264 "Update Track for management meeting, 0 days overdue" under `data-overdue-owner="26"` with Nudge and Reassign; Kussairi's done card 259 absent (`grade-cr34-4-director-1730.png`). The award marker is `work_items.source = 'management_meeting'` + the `system` label, which `CR34Test` pins on every meeting card and asserts null on a manual card; CR-14a must exclude on it (noted in its spec via the test comment). |
| 5 | Friday public holiday, task and email move to Thursday | PASS | With a `public_holidays` row for Friday 2026-09-18: tasks at Thursday 09-17 08:00 created 12 cards due 2026-09-17, at Friday 09-18 08:00 created 0; reminder at 09-17 15:00 wrote `port_outbox` row 7, at 09-18 15:00 wrote nothing. Settings: Kussairi gets no nav link, `GET /app/management-meeting` 403 and `POST /app/admin/management-meeting` 403; Shahril sees the screen with the defaults (`grade-cr34-settings-director.png`); Hidayah set "Paused until" 2026-09-30 through the form ("saved" toast, value persists, audit row 1083 on `ManagementMeetingSettings` 1, `grade-cr34-5-hr-pause.png`); tasks at 09-25 08:00 then created 0, at 10-02 08:00 created 12. |
| 6 | Track AI answers 'What were last week's blockers?' from the completed updates (DEFERRED) | human check | `markTestIncomplete` in `CR34Test`; nothing in this repo can answer it until the Track port exists. Not graded. |

## Every-session checks

| Check | Result |
|-------|--------|
| `php artisan test --compact tests/Acceptance/CR34Test.php` | 7 passed (1 incomplete: item 6). Full suite on the S16 tree: 2863 passed, 0 failed (run by the session and re-checked before its commit). |
| Task due date via API | As Kussairi `PATCH /app/board/259 {due_at: 2026-09-25}` → 422 "Due dates are locked after the first save…". |
| Edit an audit-log row | `AuditLog::orderByDesc('id')->first()->update(['action' => 'tampered'])` → RuntimeException "audit_logs rows are append-only". |
| Dashboard as plain staff on a quiet day | Shazwan, clock 2026-09-09 09:00: no band, no panel, the same ten widgets in the same order as `docs/build/baseline/dashboard.png` (summary, clock, tasks, leave, style; calendar, notices, flowers, claims, work) (`grade-cr34-dashboard-staff.png`). The profile-completeness strip and the Office Requests nav item were there before S16. Clock reset to real. |
| Keep it plain | Toggled on for Shahril: greeting drops to "Good afternoon, Shahrilnizam."; the Management Meeting screen has no cheeky text and no animation of its own, only the layout's shared 0.15s page fade and the Knowledge bell ring every screen has (`grade-cr34-plain-director.png`). Toggled back off. |
| Outbound calls in the diff | None (`Http::`, guzzle, googleapis, `Mail::`, curl absent from `app/`, `routes/`, `bootstrap/`, `resources/`, `database/`). The reminder goes through `MailPort` into `port_outbox`. |
| OPEN entries | The QA shapes entry and the two S16 entries (settings/screen decisions; the `TestCase` schedule de-dup) name alternatives and a reversal cost. |
| Protected files | `CLAUDE.md`, `docs/build/RULES.md`, `docs/build/contracts/*`, `tests/Acceptance/*` untouched by S16 (`git diff f9db0103..HEAD --stat` on those paths is empty) and by this grade. |
| `tests/TestCase.php` change | Reviewed: the de-dup keeps the first event per command+expression after the `schedule:list` warm-up and only rewrites the private `$events` list; harmless when nothing doubled. Accepted, with the S16 OPEN entry as the record. |

## Notes for the next session
- Dev DB end state: the 36 meeting cards (255 to 290, three Fridays) and the test holiday row were deleted; `management_meeting_settings` row 1 stays with `paused_until` cleared (defaults otherwise); project 37 "URSB : Management meeting" stays (the command would recreate it); `port_outbox` rows 6 and 7, `app_notifications` 1585 to 1608 and the audit rows stay (append-only). Dev clock real; Keep it plain off.
- CR-14a (S17) must read `work_items.source = 'management_meeting'` (or the `system` label) to keep these cards out of every award; the acceptance test for CR-34 pins the marker, CR14aTest should pin the exclusion.
- The 5 PM overdue rule for meeting cards lives in `ManagementExceptions::overdue()` (same-day listing from `meeting_time`); every other card is overdue from the next day as before.
