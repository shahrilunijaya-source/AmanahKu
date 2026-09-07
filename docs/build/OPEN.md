# OPEN – decisions taken without Shazwan

Every entry is a decision the run made because it could not stop to ask. This file is the first thing Shazwan reads when the run ends, before any code.

Append only. Never remove an entry. If a later session changes an earlier decision, add a new entry referencing the old one.

## Format

```
### <session id> / <CR-ID> / <short title>
- Question: what was undecided
- Decided: what was implemented
- Alternatives: what else was viable and why it was not chosen
- Reversal cost: cheap / medium / expensive, and what would have to change
- Source: CR text says "to confirm" / contract conflict / spec silent
```

## Known open questions carried in from the tracker

These are already known before the run starts. A session that hits one of them still writes its own entry above, recording what it actually implemented.

| CR | Question | Safe default for the run |
|---|---|---|
| CR-02 | Read-only, or timesheet approval rights too | Read-only |
| CR-06 | Which fields mandatory vs optional; project-code format agreed with Finance | All new fields optional except Client and Status; project code is a configurable regex with a documented placeholder default |
| CR-09 | Does TOT attendance also feed the Attendance module for Saturday work | No, TOT attendance stays in the Learning module |
| CR-13 | Weekend or holiday birthday: show again on the next working day | Show on the day only, do not repeat |
| CR-16 | BM label for The Playground | Keep 'The Playground' in both languages |
| CR-18 | Minimum attendance percentage satisfying the social-activity Done rule | At least one Attended, configurable, default 1 |
| CR-19 | Track WBS-linked card at 100 percent, auto-Done or not | Not auto-Done. Deferred with the rest of the Track binding |
| CR-14 | Does any award carry a tangible reward | No tangible reward, recognition only |
| CR-05 | Definition of Done checklist templates per project | Not implemented, single global checklist only |

## Entries

<!-- sessions append below this line -->

### S00 / CR-32 / Appendix B column for My work summary and My working style
- Question: Appendix B draws "My work summary" and "My working style" in the left column; the registry (`DashboardWidgets`) has both in the right column, and users can drag them anyway.
- Decided: contract follows the code, right column. Nothing moved.
- Alternatives: move both to left to match the drawing. Rejected because CR-32 forbids reordering existing cards and the drawing was made from memory.
- Reversal cost: cheap, change `column` on two registry entries.
- Source: spec vs code conflict.

### S00 / CR-04 / meaning of existing "shared with" participants
- Question: `work_item_participant` rows exist today with no Helper/FYI distinction. What are they after S03?
- Decided: contract says existing rows become `role = 'helper'`.
- Alternatives: `fyi` (no credit). Rejected because today's participants can edit the card and are shown as working on it; stripping credit silently would under-report. Owners can downgrade a helper to FYI afterwards.
- Reversal cost: cheap, one data migration.
- Source: spec silent.

### S00 / Global Clause / fixture data and the "no attendance writes before S01" hard stop
- Question: S00 must produce three months of attendance, timesheet and card fixtures, but the hard stop says nothing writes to attendance or timesheet tables before S01.
- Decided: the fixture seeder is written and proven on the test database only (`BuildFixturesSeederTest`). It is not run against the dev database in S00. S01 or later runs `php artisan db:seed --class=BuildFixturesSeeder` when a session needs the data; the baseline screenshot is taken on the untouched dev data.
- Alternatives: run it in S00. Rejected because the dev database is a copy of production staff and the hard stop is explicit.
- Reversal cost: cheap, run the seeder.
- Source: rules conflict.

### S00 / CR-01 / Google Calendar client already exists
- Question: tracker treats CR-01 as unbuilt; a one-way Calendar sync (`GoogleCalendarClient`, `SyncWorkItemCalendarEventJob`) already exists.
- Decided: keep it, wrap it as the real `CalendarPort` adapter in S07, leave it unbound during the run. Feature code after S07 calls the port only.
- Alternatives: delete the client and rebuild behind the port later. Rejected, throws away working code.
- Reversal cost: cheap.
- Source: spec vs code.

### QA / global-clause / acceptance items reinterpreted or deferred in GlobalClauseTest
- Question: item 1 says "change a due date", but the clause itself locks work due dates and Events do not exist until S13. Items 2 and 3 assert on the award freeze and the Awards page, which are S17 and S18.
- Decided: item 1 is tested on the first set of a due date (null to date, the write that stays legal after S02) plus a priority change with real old and new values, plus web-UI move and archive. Items 2 and 3 are `markTestIncomplete` naming what `CR14Test` must assert; `/qa write CR-14` owes both.
- Alternatives: test item 1 as a due date change on an existing date. Rejected, it would turn red at S02 and the file is frozen. Wait until S13 for an Event reschedule. Rejected, S01 needs a gate now.
- Reversal cost: cheap, `/qa write CR-11` adds the Event reschedule audit test.
- Source: spec vs contract.

### QA / date-calendar-rules / shapes fixed by DateCalendarRulesTest and items deferred
- Question: the spec names no API for cancel-and-recreate, no Event type or CalendarPort exists at S02 (both S13/S07), TOT Tindakan is S12, and `work_items.type` has no `event` value yet.
- Decided: item 4 fixes these shapes for S02: a locked `due_at` is refused with 422 on `due_at` (same date re-sent is not a change; null is refused); `POST /app/board/{id}/cancel` with a required `reason` stamps `cancelled_at`, sets `archived_at` so the card leaves the board like an archive, and writes an audit row for field `cancelled_at` with that reason; `POST /app/board` requires `due_at` for a top-level work card (the model still allows null on create, so fixtures and the frozen `AlwaysChecks::card()` keep working). Item 7 asserts rows with `type = 'event'` and cancelled cards are excluded from overdue (`WorkforceInsights::overdueItems`, board `wc-when--over`) and that an Event date can still change and is audited; S02 therefore adds the `event` value to the `type` enum in the schema only, no UI. Items 1, 2, 3, 5, 6 are `markTestIncomplete` naming what `CR11Test` must assert on `port_outbox`; item 8 names what `CR10Test` must assert.
- Alternatives: a `cancelled` status. Rejected, it would need a board column and touches move rules; `cancelled_at` plus archive changes nothing visible on the board. Wait for S13 to add the `event` enum value. Rejected, rule 7 is named a non-negotiable gate from S02 and cannot be tested without a row of that type.
- Reversal cost: cheap for the enum (one migration); medium for cancel if a status is wanted later (data backfill from `cancelled_at`).
- Source: spec silent / run note vs code.

### S02 / date-calendar-rules / cancel granularity and reopening
- Question: can a subtask be cancelled on its own, and can a cancelled card be reopened?
- Decided: `cancel` refuses a subtask (422, "A subtask is cancelled with its parent"); cancelling a parent cascades to its subtasks. A cancelled card cannot be restored, from the API (`restore` 422) or the archived list (no Reopen button). A subtask whose date moved is deleted and re-added.
- Alternatives: per-subtask cancel with its own reason. Rejected for now, the drawer only shows the cancel action on parents and the archive flow it mirrors is parent-only too. Allowing reopen. Rejected, it would give a moved due date a way back onto the board.
- Reversal cost: cheap, drop the `isChild()` abort in `WorkItemController::cancel` and the `isCancelled()` abort in `restore`, show the buttons.
- Source: spec silent.

### QA / CR-04 / shapes fixed by CR04Test
- Question: the spec names no API for tagging with a role or for appointing a reviewer, says nothing about who may appoint one, and the roles contract leaves the counter markup and the filter chips to the session.
- Decided: tagging is `PATCH /app/board/{id}` with `tagged` = list of `{employee_id, role}` (`helper` or `fyi`), replacing the whole tagged set like `participant_ids` does today; `participant_ids` keeps working and means `helper`; subtask `helper_ids` (CR-05) writes the same pivot with role `helper`; existing pivot rows read back as `helper` (column default). The reviewer is `reviewer_id` on the same PATCH, nullable, refused with 422 when it equals the Assigned person; only the manager tier (manager, hr, management, PM and above) may set it, the card owner gets 403. The reviewer's one extra power is moving In Review to Done: she gets 403 on a title edit, and owner, helper and PM get 403 on that move while a reviewer is set; a card with no reviewer moves as today. Every card face carries `data-role="assigned|helper|fyi|reviewer"` for the viewer plus the visible text "Tagged – Helper", "Tagged – FYI" or "Reviewer"; the team board person row gains `data-helping` and `data-reviewing` next to `data-open` and `data-overdue` with the text "helping on N" / "reviewing N" (FYI adds to neither); column badges `data-count` count Assigned only; the board carries chips with `data-role-filter="assigned|tagged|reviewing|all"`. Item 4's actual hiding is client-side and is a human check in `/qa grade`.
- Alternatives: a separate `reviewers` pivot. Rejected, the contract fixes `work_items.reviewer_id`. Letting the owner appoint her own reviewer. Rejected, the spec's example has the PM appoint Yati and a self-chosen reviewer is a way around the review gate. Letting the PM bypass the reviewer on Done. Rejected, the contract says the reviewer is the sole authority for review to done.
- Reversal cost: cheap for the appointment gate (one `abort_unless` in `WorkItemController::update`); medium for the PATCH shape (the drawer JS and MCP `update_card` would follow).
- Source: spec silent / contract silent on markup.

### S03 / CR-04 / default role view, existing shared cards, and where a PM sets the reviewer
- Question: which chip the personal board opens on; what today's "shared with" rows become; where a PM can appoint a reviewer when the team board drawer is read-only; whether a subtask helper sees the subtask on their own board.
- Decided: the board opens on Assigned (column badges count Assigned only, so the badges match what is shown); every existing `work_item_participant` row reads back as `helper` through the column default, no data rewrite; the team board drawer, otherwise view and comment only, gains exactly one control, the Reviewer select, shown when the server says `can_set_reviewer` (manager tier covering the owner), because the team board is the only place a PM meets a staff member's card; the reviewer roster there is the people already on the team board. A helper on a subtask (CR-05 `helper_ids`) is stored with role `helper` but still does not get the subtask on their own board, as before S03.
- Alternatives: open on All. Rejected, the badges would then disagree with the contract's "count Assigned only" or with what is visible. Migrate old rows to FYI. Rejected, the contract names helper as the safe credit-bearing default. A per-employee board route for managers. Rejected, new surface outside the CR. Listing subtask helper rows on the helper's board. Rejected, it needs the ParentOnly scope bypass widened and nothing in CR-04 or CR-05 acceptance asks for it.
- Reversal cost: cheap for the default chip (`roleFilter: 'assigned'` in `work-board.js` plus `assigned` in `BuildsWorkData::boardColumns`); cheap for the reviewer roster (pass `assignableEmployees` to the Alpine component); medium for subtask helper visibility (a third query in `boardColumns`).
- Source: spec silent.

### QA / CR-32 / shapes fixed by CR32Test
- Question: the contract fixes the band order (moments, management, awards) and the widget slots (Friday sign-off after Pending tasks, Plot Twist inside the notice board, Events after Attendance) but no markup for a band, no gate or window for the management band beyond "management, director, hr", no working-day rule for the awards window, and no clock rule for the Friday card's ends.
- Decided: a band is `<section class="uj-db-band" data-band="management|awards">` inside the existing `.uj-db` wrapper, above `.uj-dw-grid`; moments keep `data-kind`. Only active slots render, in contract order. Management renders for `Permissions::FINAL_APPROVAL_ROLES` (management, director, hr) on every day; employee and manager never see it; its content is CR-17 (S15), S04 owns the slot, gate and text-only form. Awards renders for everyone from the first working day of the month (not a weekend, no `PublicHoliday` row) through the 7th inclusive; content is CR-14 (S17/S18), S04 owns the window. The Friday sign-off is a registry widget with id `friday` (`data-widget="friday"`), left column right after `tasks`, shown from Friday 15:00 up to but not including Monday 09:00, tenant clock; content is CR-29 (S25), S04 owns the slot and window. Keep it plain keeps every band as text inside `.uj-db[data-plain]` with no `uj-db-art`. Widget order is read from `data-widget` ids inside each `data-col` column; on a fresh tenant the right column is calendar, notices, claims, work, style because Flowers hides itself when there are no flowers. Item 5 (Appendix B layout match) is a human check in `/qa grade`.
- Alternatives: rendering the bands as widgets in the grid. Rejected, the contract puts them above the grid. Gating the management band on `MANAGEMENT_TIER` only. Rejected, the contract names hr too. A calendar-day window for awards. Rejected, Appendix B says "first working day".
- Reversal cost: cheap for the gate and windows (one predicate each in `BuildsDashboardWidgets::dashboardBands`); cheap for the band markup (one Blade partial).
- Source: spec silent / contract silent on markup.
