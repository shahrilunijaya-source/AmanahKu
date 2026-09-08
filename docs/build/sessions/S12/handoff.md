# Session S12 handoff: CR-10

## Delivered
- `tot.actions.store` gains `owners[]` (first id = Pemilik → `owner_employee_id`, the rest →
  `tot_action_helper`) and `create_card` (default off, so the CR-09 two-step flow through
  `tot.actions.card` stays valid); with `create_card` on and an owner present the row and its
  T.A.A. card are made in one request, answering 201 `{id, work_item: {id, due_at, due_text}}`,
  verified by acceptance item 1 (Rubmin owner, Nabil tagged helper, card due on the September
  TOT Saturday, `labels` carries `tot`, a `TOT <Month Year>` link back to the session, plain
  staff 403).
- Sasaran (`target_date`) is freely editable through the new `tot.actions.update` while no
  card exists; once `work_item_id` is set, a different `target_date` or a different first
  `owners[]` id both 422 and change nothing, while action text and helpers keep updating (text
  → the card title, helpers → `work_item_participant`) — verified by acceptance item 2.
- `TotAction::statusLabel()` reads the linked card's status live (no card / `todo` → Open,
  `prog`/`review` → In Progress, `done` → Done) and is printed on every row, so moving the
  card through the board changes what the session page shows with no extra write — verified
  by acceptance item 3.
- `TotSession::previousSession()` (already existed from S11) feeds a "Tindakan bulan lepas"
  block, rendered once per session, shown only when the previous month has at least one
  action, each row showing action text/owner/status in position order — verified by
  acceptance item 4, and by `TotActionsTest` for the December→January year rollover case.
- `tot.actions.delete` removes the row and, when a card exists, sets `archived_at` and
  `cancelled_at` on it (never deletes it), so the board's existing `whereNull('archived_at')`
  query drops it — verified by scope item 5.
- Governance unchanged from CR-09: add/edit/delete all go through
  `TotController::canManageSession()` (`TotSession::isManagedBy` — management, hr, manager,
  the session's own chair); "Create T.A.A. task" (`tot.actions.card`) keeps CR-09's
  owner-may-click-their-own-row right via `TotAction::canCreateCardBy()`.
- Every write (add/update/delete/card-create) keeps writing an `AuditLog::record(...)` row,
  same pattern CR-09 used.

## Schema changes
- `tot_action_helper` (new): `action_id` FK `tot_actions` cascade, `employee_id` FK
  `employees` cascade, timestamps, unique `(action_id, employee_id)` — migration
  `2026_09_17_100000_create_tot_action_helper_table.php`. Applied to the dev database with
  `lerd artisan migrate --no-interaction`. Verified: `DESCRIBE tot_action_helper` shows the
  five columns and the FKs; `SHOW INDEX` shows the unique `(action_id, employee_id)` pair;
  `SELECT COUNT(*) FROM tot_action_helper` = 0 (fresh table, nothing backfilled — there was
  nothing to backfill, CR-09's `tot_actions` never had a helper concept).

## Contracts touched
- None. `docs/build/contracts/*` was read, not edited.

## Port calls stubbed
- None. Card creation only writes a `work_items` row through the same `WorkItem::create()`
  path every other card creation in the app already uses; no outbound call anywhere in this
  session's code.

## Deferred
- Nothing from CR-10's scope. The OPEN "QA / CR-10 / shapes fixed by CR10Test" entry's own
  listed alternatives (a `status` column mirrored by an observer, a `projects` row per TOT
  month, soft-deleting the row, letting Pemilik changes reassign the card) were rejected
  there, not here — this session followed that decision, did not redecide it.

## OPEN, decided without Shazwan
- No new entries. This session implemented exactly the shapes OPEN "QA / CR-10 / shapes fixed
  by CR10Test" already fixed; nothing here was left for the run to decide on its own.

## Requested contract change (generator may not make it itself)
- None.

## Traps for the next session
- `TotController::authorizeSlotEdit()`'s allow-list (`chair_employee_id`/`nota_url`/
  `next_agenda`/`year`/`month`/`_token`) only gates `tot.update` (the session-level route).
  The new `tot.actions.update` and `tot.actions.delete` routes go through
  `canManageSession()` directly instead, matching how `storeAction`/`createActionCard`
  already did in S11 — do not route a future Tindakan field change through
  `authorizeSlotEdit()`'s allow-list, it was never meant to cover this endpoint.
- `TotController::makeActionCard()` is now the one place both `storeAction` (with
  `create_card`) and `createActionCard` build the card. If a later session adds another field
  the card should carry (e.g. a second label, a different link shape), add it there once —
  do not special-case the two call sites separately, they are meant to stay identical.
- `TotAction::helpers()` (a `tot_action_helper` pivot) and the card's own
  `work_item_participant` rows are two different tables that must be kept in sync by hand:
  `updateAction()` re-syncs the card's participants from the row's helpers whenever `owners[]`
  is present in the request AND the card already exists. A future write path that touches
  `tot_action_helper` directly (a bulk import, say) without going through `updateAction()`
  will silently desync the two — the row's helper list is the source of truth, the card's
  participants are a projection of it, never the other way round.
- The "Tindakan bulan lepas" block calls `$session->previousSession()` per session render
  (12 times per year screen load, same N+1 S11 already accepted for `carriedAgenda()`) and
  then lazy-loads that other session's `actions`/`owner`/`workItem` — not eager-loaded from
  `screenData()`, because the previous session is a different row per session and isn't a
  declared Eloquent relation. Fine at this scale; worth an eager batch if the year screen
  ever gets slow.
- The add-tindakan form's Pemilik `<select name="owners[]">` and the Helpers
  `<select multiple name="owners[]">` rely on browser form-field submission order (owner
  field appears first in the DOM, so it lands first in the posted `owners[]` array) to keep
  "first = Pemilik" true. `TotController::dropBlankOwnerRows()` strips the blank `"—"` owner
  option before validation so an ownerless tindakan can still be saved through the form. If a
  future session reworks this into a JS-driven multi-select, keep that ordering guarantee
  explicit rather than relying on DOM order again.

## Test counts
- `tests/Acceptance/CR10Test.php`: 6/6 passed, 121 assertions.
- `tests/Acceptance/CR09Test.php`: 4/4 passed (plus the shared `test_always_checks`),
  unaffected — 17/17 passed together with `tests/Feature/TotSessionSlotsTest.php`, 260
  assertions.
- `tests/Feature/TotActionsTest.php` (new, this session): 11/11 passed, 62 assertions —
  cross-session 404 on update/delete, plain-staff and chair governance, `owners[]` splitting
  into owner + `tot_action_helper`, `create_card` omitted saves the row only, helpers
  re-syncing to the card's participants both ways (added and cleared), the `tot` label and
  the slot-qualified link, `statusLabel()` across todo/prog/review/done, deleting a
  card-less tindakan, and the December→January "Tindakan bulan lepas" rollover.
- Related TOT/reaction cluster (`CR09Test`, `CR10Test`, `TotSessionSlotsTest`,
  `TotActionsTest`, `TotTest`, `TotLiveActionsTest`, `TotAssignPermissionTest`,
  `TotHistorySeederTest`, `TotReminderTest`, `TotSaturdayTimesheetTest`, `CR30Test`):
  214/214 passed, 1011 assertions.
- Full suite (`php artisan test --compact`): 2799 tests, 2794 passed, 0 failed, 5 skipped, 12
  incomplete (pre-existing, unrelated to CR-10).

## Commit
- Formatted with `vendor/bin/pint --dirty --format agent` (no changes needed).
- Assets rebuilt (`lerd artisan view:clear && lerd artisan view:cache && bun run build`);
  `public/build` came out byte-identical (the Blade change only used classes already in the
  compiled CSS, no new JS), so there is nothing new to commit there.
- Committed on this worktree's branch as
  `feat(S12): CR-10 editable TOT tindakan that make T.A.A. cards`. Not merged, not pushed,
  `dev` untouched.
