# S19 grade: CR-19 auto-done rules for system cards

**Verdict: PASS.** No defects found. Every acceptance item was driven in the browser on the
dev database (app at `worktree-change-request-tracker.amanahku.localhost`, dev clock where
the rule depends on the date), the scheduler was run flagged off and flagged on, and every
every-session check is green.

Graded on commits `30b4fc17` (feature) and `049c7e35` (session docs) on top of `a0689e8c`.

## Acceptance items

| # | Item | Result | Evidence |
|---|------|--------|----------|
| 1 | Event ends → "Pending Attendance" badge; organiser prompted once; Attended → Done + Auto; Did not attend → archived, never Done; invitation withdrawn → cancelled + archived; reopen clears the marker | PASS | Event "QA CR-19 HPE Auto-Done" (09:00–11:00 on 2026-09-09, attendees Kussairi + Shazwan) created as HR. At dev clock 12:00 Shazwan's To Do card carried `data-pending-attendance="1"` and the amber badge (`grade-cr19-1-pending.png`). `board:auto-done` flag off printed the dry-run line and wrote nothing; flag on sent one `app_notifications` row (`event-attendance-3`, to HR's user) and a second run sent zero. Event page RSVP select offers "Did not attend" (`grade-cr19-1-event-page.png`). Attended (Shazwan) → card 335 `done`, `auto_closed_at` set, system comment "Closed automatically – Attended" by Amanahku, audit rows status/done_at; card shows the Auto pill (`grade-cr19-1-done-auto.png`), drawer shows the bolt meta line and the system row (`grade-cr19-1-drawer.png`). Did not attend (Kussairi) → card 334 stays `todo`, `archived_at` + `auto_closed_at` set, audit archived_at. Drawer segment To Do → In Progress reopened 335: `auto_closed_at` null, no pill, audit status done→prog (`grade-cr19-1-reopened.png`). HR removed Shazwan from attendees → 335 `cancelled_at` + `archived_at`, comment "Closed automatically – Invitation withdrawn", audit rows for both. |
| 2 | Google Calendar deletion → "Cancelled (calendar)" | INCOMPLETE (human check) | No inbound Calendar port exists; the frozen test carries `markTestIncomplete`, already in RULES.md and OPEN.md. Not counted as FAIL. |
| 3 | Nominate card closes on submit; flag-off dry-run leaves everything; flag-on archives unsubmitted nominate cards after the window; select card closes Done on publish | PASS | `awards:tasks` at 2026-09-28 created 43 cards. Shazwan nominated Kussairi (dev clock 2026-09-28) → card 361 Done, pill title "Closed automatically – Nomination submitted" (`grade-cr19-3-nominate-auto.png`); every other nominate card stayed `todo`. At 2026-10-01 00:15 the flagged-off run listed 33 "would archive" lines and changed nothing; flagged on it archived 33, comment "Closed automatically – the nomination window closed", status still `todo`. September publish is blocked on dev by audit row 1144 (S18 grade), so October was used: `awards:tasks` 2026-10-26, `awards:freeze` 2026-10-31, `awards:publish` 2026-11-02 → both select cards (Haryati 414, Kussairi 415) Done with "Closed automatically – the awards were published" (`grade-cr19-3-select-published.png`). |
| 4 | Auto-closed cards never score, never overdue | PASS | `awards:freeze` for September wrote no `done_and_dusted` / `deadline_who` row for Shazwan although cards 335 and 361 were Done that month (both auto-closed), so they did not count. Director's Management Exceptions page at dev clock 2026-10-05 lists no "Nominate this month's awards" card (archived by the scheduler, due 2026-09-30) while manual late cards are listed (`grade-cr19-4-overdue.png`). The September "Select manual award winners" cards do appear there, correctly: they were never closed on dev because the blocked September publish never ran. |
| 5 | Manual cards never auto-closed by date | PASS | After every live scheduler run, zero manual cards (no `source`, no event) carry `auto_closed_at`; Shazwan's fixture cards overdue since July/August (229, 231, 232) remain open. |

## Every-session checks

| Check | Result |
|-------|--------|
| `php artisan test --compact tests/Acceptance/CR19Test.php` | 6 passed, 1 incomplete (item 2) |
| Due date PATCH (`/app/board/415`, Kussairi, `due_at` 2026-12-25) | 422 "Due dates are locked after the first save" |
| Audit row edit (tinker `update()` on row 1150) | rejected: "audit_logs rows are append-only", row unchanged |
| Dashboard as Shazwan, 2026-09-09 09:00, plain staff | widget order unchanged: summary, clock log / calendar, then the rest (`grade-cr19-dashboard.png`) |
| Keep it plain | S19 adds no transition or animation rules (grep of `app.css` for the new classes is empty); pill, badge and system comment are plain words, no cheeky copy (`grade-cr19-plain-card.png`, `grade-cr19-plain-drawer.png`) |
| Outbound calls in the diff (`Http::`, guzzle, googleapis, `Mail::`, curl) | none |
| OPEN entries | both new entries carry Alternatives and Reversal cost |
| Protected files (`CLAUDE.md`, `docs/build/RULES.md`, `docs/build/contracts/*`, `tests/Acceptance/*`) | untouched (`git diff a0689e8c..HEAD` on those paths is empty) |
| Dev DB migration | `auto_closed_at` present on `work_items`, migration recorded |
| Pre-existing failure claimed in the handoff | confirmed: `LeaveScreenTabsTest::test_cancelling_an_approved_replacement_refunds_the_quota` fails on HEAD ("Failed asserting that 1.0 matches expected 0.0") and the S19 diff touches no leave file, so it is not S19's |

## Mockup fidelity (`docs/build/sessions/S19/mockup/README.md`)

- Auto pill, bolt icon, drawer meta line and the Amanahku system comment row match the approved mockup.
- Badge text is "Pending Attendance" (title case) where the mockup said "Pending attendance". `CR19Test` asserts the title-case string, so the frozen test wins; noted, not a defect.
- Observation outside CR-19: the drawer Type field shows "Assignment" for an Event card (pre-existing, drawer type list has no Event option).

## Housekeeping

Dev DB cleaned: QA event, its cards and comments, the organiser notification, the QA nomination, the September and October award cards and snapshots are gone. Audit rows 1150–1282 stay (append-only). Row 1282 (`awards.published`, target 2026-10-01) now blocks an October publish on the dev copy, same as row 1144 blocks September. Dev clock reset to real, Keep it plain off.
