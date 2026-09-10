# Session S12 contract: CR-10

Shapes are frozen by OPEN "QA / CR-10 / shapes fixed by CR10Test" — this contract follows it,
does not redecide it.

## Files touched

- `database/migrations/2026_09_17_100000_create_tot_action_helper_table.php` (new)
- `app/Models/TotAction.php` — `helpers()` relation, `statusLabel()`
- `app/Models/WorkItem.php` — `LABELS['tot']`
- `app/Http/Controllers/TotController.php` — `storeAction` gains `owners[]`/`create_card`;
  new `updateAction`, `deleteAction`; shared `makeActionCard()` extracted from
  `createActionCard()`
- `routes/web.php` — `tot.actions.update`, `tot.actions.delete`
- `resources/views/partials/tot-actions-table.blade.php` — helper names + status label per
  row, "Tindakan bulan lepas" block, owners/create_card on the add form, edit/delete forms
- `tests/Feature/TotActionsTest.php` (new)

## Schema

`tot_action_helper`: `action_id` FK `tot_actions` cascade, `employee_id` FK `employees`
cascade, timestamps, unique `(action_id, employee_id)`. Migration
`2026_09_17_100000_create_tot_action_helper_table.php`.

## Acceptance item verification (CR10Test)

1. `owners[]` on `tot.actions.store` with `create_card=1`: first id → `owner_employee_id`,
   rest → `tot_action_helper`; `makeActionCard()` builds the card with `labels` containing
   `tot`, a `links` row `TOT <Month Year>` → `/app/tot?year=&month=`, helpers synced to
   `work_item_participant` role `helper`. Plain staff 403 before any row is touched. Row and
   status render on `GET /app/tot?year=` via the updated partial. Audited via the existing
   `AuditLog::record('Added TOT tindakan', ...)` call (target contains the action text).
2. Sasaran (`target_date`) editable through `tot.actions.update` while no card exists;
   `tot.actions.card` (unchanged route) creates the card from the stored value; once
   `work_item_id` is set, `updateAction` 422s on a different `target_date` or a different
   first `owners[]` id, changes nothing; action-text and helper changes still go through,
   text change updates `WorkItem::title`, helpers re-sync to participants. Board due-date
   lock (existing `BoardRules::assertDueDateLocked`) already covers the API-side re-check.
3. `TotAction::statusLabel()` reads `workItem->status` live (`todo`/no card → Open,
   `prog`/`review` → In Progress, `done` → Done); the partial prints it next to each row, so
   moving the linked card through `/app/board/{id}/move` changes what the session page shows
   with no extra write.
4. `TotSession::previousSession()` (already exists from S11) feeds a "Tindakan bulan lepas"
   block rendered once per session in the drawer, shown only when the previous month has at
   least one action; each row shows action text, owner name, status label, in position order.
5. `deleteAction()` deletes the `tot_actions` row and, when `work_item_id` is set, sets
   `archived_at` and `cancelled_at` on the card (never deletes it) — same pair `archive()`/
   `cancel()` already write on `WorkItem`, so the board's existing `whereNull('archived_at')`
   query drops it without a new exclusion rule. Audited.

Roles: every new/changed route reuses `TotController::canManageSession()`
(`TotSession::isManagedBy`, unchanged from S11: management/hr/manager/chair). No new role
gate invented.

## Not building

- No `status` column on `tot_actions` (read live from the card, per OPEN).
- No `projects` row per TOT month (the link is the "project tag").
- `tot.actions.card` (two-step create) keeps existing behaviour, now sharing
  `makeActionCard()` so it also gets the label/link/helpers CR-10 adds.
