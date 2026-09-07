# Session S03 handoff: CR-04 (roles on a card)

## Delivered
- One role model per `contracts/roles.md`: Assigned = `work_items.employee_id`, Creator = `assigned_by_id`, Tagged Helper / FYI = `work_item_participant.role`, Reviewer = `work_items.reviewer_id`. `WorkItem::roleFor($employeeId)` answers "what is this card to this person" and every render goes through it. Verified by acceptance items 1 to 4.
- Tagging with a role: `PATCH /app/board/{id}` with `tagged: [{employee_id, role}]` (helper | fyi) replaces the tagged set; `participant_ids` still works and means helper; subtask `helper_ids` writes helper. The audit row for field `participants` now carries `id:role` pairs. Verified by items 1 and 2 and the CR-05 migration test.
- Reviewer: `reviewer_id` on the same PATCH, manager tier only (`BoardRules::ASSIGNER_ROLES` through `effectiveRole`), 422 when it is the Assigned person, audited. A reviewer may open the card (`authorizeAccess`) and has exactly one extra power: `BoardRules::assertReviewerMovesToDone()` refuses everyone else the In Review to Done move on the web route and on MCP `move_card`. Verified by item 3.
- Personal board: cards I review join cards I own or am tagged on; each face carries `data-role` and a label ("Tagged – Helper", "Tagged – FYI", "Reviewer"); role chips Assigned / Tagged / Reviewing / All (`data-role-filter`), default Assigned; column badges count Assigned only. Verified by items 1 to 4; the chip hiding is Alpine and is a browser check.
- Team board: each person row carries `data-helping` / `data-reviewing` and shows "helping on N · reviewing N" under the name; the four counters count Assigned only; the person window lists the cards they help on, watch or review with the label, and its summary line adds the two figures. A person with no cards of their own but a tag or review still gets a row. Verified by items 1 to 3 and in the browser (`s03-nabil-window.png`).
- Drawer: "+ Add someone" offers Helper or FYI per name, a tagged chip shows the role and flips on click, a Reviewer row shows the name, and the select appears for a PM (`can_set_reviewer`) on both the personal board and the team board drawer (`s03-drawer-tag.png`, `s03-reviewer-set.png`). Lock hints name the viewer's role (reviewer, FYI).
- Assets rebuilt, `public/build` updated. Full suite green (2673 passed, 5 skipped, 8 incomplete).

## Schema changes
- `work_item_participant`: `role` string(10) not null default `helper`; `work_items`: `reviewer_id` nullable FK employees, null on delete. Migration `2026_09_09_100000_add_card_roles.php`, run on the dev database.

## Contracts touched
- none.

## Port calls stubbed
- none.

## Deferred
- MCP `update_card` still takes `participant_ids` / `participants` (spoken names) only, all stored as helper; no `tagged` or `reviewer_id` there. Whoever next touches the MCP write tools (S05 CR-30 or the CR-19 work) adds both.
- Subtask helpers do not see the subtask on their own board (unchanged from CR-05). See OPEN.
- Reviewer notification on the auto-move to In Review (CR-05 scope 4 says "Reviewer/Primary Owner notified"): `notifyParentAutoReview` still notifies the owner and assigner only. One `AppNotification::send` to `reviewer_id` when set; left for the session that owns CR-05 follow-up, not in CR-04 acceptance.

## OPEN, decided without Shazwan
- Board opens on the Assigned chip; old shared rows are helpers; the team board drawer gains the reviewer select; subtask helper visibility unchanged. See OPEN entry "S03 / CR-04 / default role view, existing shared cards, and where a PM sets the reviewer".
- The API shapes themselves were fixed by QA, see "QA / CR-04 / shapes fixed by CR04Test".

## Requested contract change (generator may not make it itself)
- none.

## Traps for the next session
- `WorkItem::participants()` now has `withPivot('role')`. Any `sync()` / `attach()` without a role gets `helper` from the column default; pass `[$id => ['role' => 'fyi']]` to mean FYI. `syncParticipants()` in `WorkItemController` takes a map `id => role`, not a list of ids.
- `partials.work-card` takes `viewerId`; without it every card renders as `data-role="assigned"` with no label. `cardHtml()` reads the viewer off `request()->attributes['employee']`. The team board passes the lane owner's id, and the same card can now appear twice in the team board DOM (once per lane), so a selector on `[data-id]` alone is ambiguous there; add `[data-owner-id]`.
- `boardColumns()` returns an `assigned` count per column beside `cards`; the Blade badges read it. The JS `refreshCounts()` still writes the visible count into the badges after any filter change, which equals the Assigned count only while the Assigned chip is active (the default).
- The reviewer gate lives in `BoardRules::assertReviewerMovesToDone()` and is called from `WorkItemController::move()` and `MoveCardTool` (preview and confirm). Any new mover must call it. The drawer's status buttons are disabled for a locked (non-owner) viewer, so a reviewer moves the card by drag on their own board.
- `cardPayload()` gained `employee_id`, `reviewer_id`, `reviewer`, and `role` on each participant; `show()` adds `can_set_reviewer` and `viewer_role`.
- The drawer's Reviewer select follows `can_set_reviewer`, not `drawer.locked`. It is the only writable control in the team board drawer; `team-board.js::setReviewer` is the only PATCH that file makes.
- Team board `teamBoardData()` runs one extra query for tagged and reviewed cards across the visible people and sets a transient `tagged_rows` attribute on each Employee model. Do not serialise those Employee models.
- `Employee` model has no `reviewing()` relation; `WorkItem::reviewer()` is the only side defined.
