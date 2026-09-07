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

### S04 / CR-32 / slot placeholders and the Friday card outside its window
- Question: what a slot shows before the CR that fills it lands; whether the Friday sign-off stays in the widget picker when it is not on the page; whether the pre-existing always-on `data-plain` on the bands wrapper was intended.
- Decided: each active slot renders one text line naming what will sit there (management: "Lateness today and overdue by Primary Owner"; awards: "<Month>'s awards"; friday: "Wrap up the week here"), so the gate and window are visible and testable now and the filling session only swaps the body. The Friday card is dropped from the available ids outside its window, so it is absent from the layout and the picker rather than shown as an empty card. The wrapper renders `data-plain` only when Keep it plain is on; before S04 it rendered the attribute always, which made every band look plain for every user and left the plain switch with nothing to switch.
- Alternatives: render nothing until the filling CR lands. Rejected, the acceptance items for CR-32 name the band and the card as visible on their days, and a slot nobody can see cannot be graded. Keep the Friday card in the picker year-round. Rejected, a hideable entry for a card that is not there reads as broken. Leave `data-plain` always on. Rejected, it contradicts the contract's "text-only version when plain is true" (there was no non-plain version).
- Reversal cost: cheap, the three placeholder strings live in `DashboardBands::managementSlot()`, `awardsSlot()` and `partials/dash/widgets/friday.blade.php`; the picker rule is one `if` in `BuildsDashboardWidgets::dashboardData()`; the wrapper attribute is one Blade `@if`.
- Source: spec silent.

### QA / CR-30 / shapes fixed by CR30Test
- Question: the spec names no storage for the reaction set, no API for reacting by custom reaction, no admin route for HR, no markup for a picker or a tally, and no shape for Request Help; its Acceptance is one paragraph, not numbered items.
- Decided: the paragraph is five items in the order written (picker shows the eight everywhere; Send Help notifies nobody; Request Help notifies and tags; HR adds a ninth; a retired reaction still shows on old items). The set is a per-tenant table read through `GET /app/reactions` as `{reactions: [{key, label, icon, retired}]}`, active only, in display order; a fresh tenant carries the eight defaults with keys `power`, `legend`, `chefs_kiss`, `noted_with_fear`, `send_help`, `respect`, `how_did_you_do_this`, `claim_bila`. The three react endpoints that exist today (TOT session, Knowledge Bank entry, birthday wish) take `reaction` = key; the old `emoji` field, an unknown key and a retired key are 422; the JSON state carries `reactions` (key => count) and `mine` (keys), one reaction per person per item with the same key as the undo and a different key as the replacement (the birthday endpoint gains those two fields beside its `html`). Picker buttons are `data-reaction-pick="<key>"`, tallies `data-reaction-count="<key>"`, shown with the reaction's label so a retired one stays readable. HR and management add with `POST /app/admin/reactions {key, label, icon}` (422 past ten active or on a duplicate key) and retire with `POST /app/admin/reactions/{key}/retire`; there is no rename route and no route may change a label. Request Help is `POST /app/board/{id}/request-help {employee_id, message ≤ 200}` by someone who may edit the card: tags the person as `helper` (CR-04), writes one `app_notifications` row naming the asker and carrying the message and a link to the card, and a `participants` audit row; asking again re-notifies without a second tag. Wall, Events, Awards, Wins and Office Requests do not exist yet; their sessions wire the same picker.
- Alternatives: keeping `emoji` as the field name with keys as values. Rejected, the spec replaces emoji, and a key in an `emoji` field would let old generic values back in unnoticed. A config array instead of a table. Rejected, HR edits the set at runtime. Reactions as a polymorphic single table. Not required by acceptance; the session may keep the three existing tables and store the key in their `emoji` column, or unify, as long as the shapes above hold.
- Reversal cost: cheap for route paths and attribute names (one place each); medium for the field name `reaction` (three endpoints and their JS).
- Source: spec silent.

### S05 / CR-30 / icons, storage, Request Help scope, and a contradiction in CR30Test
- Question: the spec wants "custom Unijaya reactions" but names no icon format; nothing says where a custom reaction is stored on an item; Request Help does not say who may ask, who may be asked, or whether it needs a due date; `CR30Test` item 1 asserts, after the same person presses the same reaction twice, both that the tally is 1 and that the person's own list is empty, which no implementation can satisfy at once.
- Decided: an icon is a short text glyph (emoji or one or two letters, 16 chars), not an SVG or an upload, entered by HR in the Company Settings card. The reaction key is stored in the existing `emoji` column of `tot_reactions`, `knowledge_reactions` and `birthday_wish_reactions` (widened to 40), so pre-CR-30 emoji rows stay readable in the tally as themselves. Request Help is open to whoever may edit the card (`authorizeManage`, the same gate as adding a person), may name anyone on the roster who is not archived and not the card's owner (the roster's own rule, not the employee status), and needs no due date. The double-press case follows the spec ("react once per person per item", the second press is the undo): the tally goes back to 0 and the person's own list empties. `/qa grade` owns the correction of the frozen assertion; the session did not touch `tests/Acceptance/*`.
- Alternatives: custom SVG or uploaded icons. Rejected, an upload path is a new surface with no acceptance item behind it, and a glyph is what the birthday composer already uses. A new polymorphic `reaction_uses` table. Rejected, the three tables already carry per-person uniqueness and the acceptance shapes only fix the API. Restricting Request Help to managers. Rejected, the spec calls it "the explicit action for a person who needs help", so the owner asks too. Treating the second press as a no-op (count stays 1). Rejected, that contradicts "once per person per item" as an undo and the toggle every existing react endpoint already had.
- Reversal cost: cheap for the icon rule (one validation rule in `ReactionController::store` and the settings form); medium for the storage column (a data move out of `emoji` into a new table); cheap for the Request Help gate (one call in `WorkItemController::requestHelp`).
- Source: spec silent / QA test contradicts itself.

### QA / CR-30 / CR30Test line 103 corrected after S05
- Question: `CR30Test` item 1 asserted that a second press of the same reaction by the same person leaves the tally at 1 while also leaving that person's own list empty; the two cannot both hold.
- Decided: the second press is the undo, as the spec's "once per person per item" and every existing react endpoint already read it: tally back to 0, own list empty. One assertion changed, nothing else in the file. QA edited it, not the session.
- Alternatives: keep the count at 1 and treat the second press as a no-op. Rejected, then the person could never take a reaction back, and the file's own next line already expects the undo.
- Reversal cost: cheap, one line in `tests/Acceptance/CR30Test.php`.
- Source: QA test contradicted itself.

### QA / CR-18 / shapes fixed by CR18Test
- Question: the spec names no storage for a recurring schedule or its occurrences, no command or scheduler entry for the engine, no admin routes or screen for HR, no rule for how "Finance Manager" resolves to a person, no shape for linking the social event to the card, no event states (approved, held, evidence, attended are CR-11 words), no shape for the departed-owner reassignment, and no number for "enough people attended".
- Decided: a schedule is a `recurring_tasks` row (`title`, `frequency` weekly | monthly | every_n_months | yearly, `interval`, `start_on`, `owner_employee_id` or `owner_position_title`, `tagged_employee_ids` json, `project_id`, `priority`, `lead_days`, `subtasks` json, `min_attended` default 1, `paused_at`); one occurrence per period is a `recurring_task_occurrences` row (`recurring_task_id`, `period` = first day, `work_item_id` null when skipped, `skipped_reason`). `php artisan work:recurring` runs daily from the scheduler, creates each due occurrence on the first working day on or after the period start (weekend or a `public_holidays` row is not a working day), never twice for one period, as a task card with label `recurring`, the schedule's priority and project, tagged people as `helper`, due on the last day of the period, subtasks in template order each with the parent's due date. A role owner is resolved at each creation to the active non-archived employee whose Position title matches, so an archived owner never receives a new card. HR and management use `GET /app/recurring`, `POST /app/admin/recurring`, and `POST /app/admin/recurring/{id}/skip {period, reason}` | `/pause` | `/resume`, each audited; employees and managers get 403. The event link is `POST /app/board/{card}/link-event {company_event_id}` (422 unless every active employee has an rsvp), stored as `work_items.company_event_id`, and it ticks the 'Create Event' subtask. The done rule for a schedule card with a linked event: 422 unless the event is approved, held, dated before today, has at least `min_attended` rsvps marked `attended`, and carries evidence. Events gain the smallest CR-11 form now: `company_events.status` (draft | approved | held | cancelled), `approved_at`, `approved_by_employee_id`, `evidence_note`; `event_rsvps.response` accepts `attended`. Reassignment is `POST /app/board/{card}/reassign {employee_id, reason}` for HR, audited as `employee_id`, due date unchanged. The overdue panel (CR-17) is not tested here.
- Alternatives: a cron expression per schedule. Rejected, the spec's own examples are "every two months" and "every three months", and HR should not write cron. Owner by employee only. Rejected, the spec says the role, not the person, and item 3b needs the replacement to follow the role. Wait for CR-11 to define event states. Rejected, item 3 cannot be graded without approved, held, attended and evidence, so the smallest columns land now and CR-11 builds on them. Skip the attendance floor. Rejected, "not just created" is the spec's own words; 1 is the lowest number that still means someone came. Reassign through the existing `PATCH /app/board/{id}`. Rejected, that route refuses an owner change on parent cards by design and carries no reason.
- Reversal cost: cheap for route paths and column names (one place each); medium for the frequency model (the engine's period arithmetic) and for the event columns once CR-11 extends them.
- Source: spec silent / CR-11 not yet built.

### S06 / CR-18 / period maths, weekend starts, the owner fallback, and what "Held" needs today
- Question: the spec gives "every N months" and a due date of "end of that month" but no rule for weekly or yearly due dates, nor for a period that starts on a weekend (1 Nov 2026 is a Sunday and CR18Test item 1 expects the card that day, while item 3d expects a holiday to push creation to the next working day); it names no owner when the position has no holder; it lists "evidence uploaded" but CR-11 has no upload yet; it does not say who may reassign; it says nothing about a schedule that was paused for several periods.
- Decided: a period's card falls due at the end of the month the period starts in (weekly: six days after the period start). The card is made on the period start minus the lead days; only a public holiday on that day moves creation to the next working day, a weekend does not (the card waits on the board until Monday). Owner resolution at each creation: the named person while active, else the active holder of the named Position title (lowest employee id when two hold it), else the schedule's creator; with nobody active the period is left uncreated with a console warning and is retried by the next run. Evidence is `company_events.evidence_note` (free text) until CR-11 adds photos; an event is Held only when its organiser sets `status = held`, never by the date. Reassign is HR and management (the CR-17 "Director / HR" tier) with a reason; it also hands over the departed owner's open subtasks on that card, drops the new owner from the helper list, and notifies them. After a pause, resume creates only the latest period whose creation day has passed; periods that fell wholly inside the pause are not back-filled and are not written as skipped. The engine takes `--on=YYYY-MM-DD` on a local install only, so the dev clock can drive it for a browser check. The Events screen's RSVP route still validates going / maybe / declined; `attended` is written by the model (CR-11 will add the UI).
- Alternatives: due at the end of the whole N-month span. Rejected, the spec's own sentence is "due end of that month". Defer weekend starts too. Rejected, it contradicts acceptance item 1 as QA wrote it. Fail the run when a position has no holder. Rejected, one empty role must not stop every other schedule. Back-fill on resume. Rejected, the spec says "nothing created until Resume", and a stack of stale cards is not what HR asked for. Reassign through `PATCH /app/board/{id}`. Rejected, that route refuses an owner change on parent cards and takes no reason.
- Reversal cost: cheap for due-date and deferral rules (`RecurringTask::dueFor`, `CreateRecurringWorkItems::creationDay`); cheap for the fallback chain (`RecurringTask::resolveOwner`); medium for back-filling (the engine would need per-period creation instead of latest-period).
- Source: spec silent / QA test fixes the weekend case.
