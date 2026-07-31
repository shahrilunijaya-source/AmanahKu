# Task Assignment Adhoc (tac) — Design

**Date:** 2026-06-26
**Status:** Approved (design); pending implementation plan
**Feature:** Directors, managers, and superiors assign adhoc tasks ("tac") to staff.

---

## 1. Problem

The work board today is **self-service only**. Each employee owns the cards on
their own board: `WorkItemController::authorizeItem()` hard-asserts
`item->employee_id === viewer->id` on every action. There is no path for a
superior to put a task on a subordinate's board.

`WorkItem` already supports `type = adhoc`, but the *defining gap* is assignment
— a privileged user creating a tracked task **on another employee's board**.

## 2. Locked Decisions

| # | Decision | Choice |
|---|----------|--------|
| 1 | Who can assign | **Role-gated**: roles `manager`, `management`, `hr` may assign to **any** staff in the tenant. No reporting-chain check. |
| 2 | Assignee control over a tac | **Move (status) + comment only.** Cannot delete or edit assigner-set fields (title/type/priority/due/description). |
| 3 | Assigner UI | Assign from an **employee profile** ("Assign task" button → modal) + a **per-profile tracking panel** ("Tasks assigned to {name}"). |
| 4 | Extras (all in v1) | (a) notify staff on assign, (b) real `due_at` date picker, (c) notify assigner on completion, (d) "Assigned by {Manager}" badge on the card. |
| 5 | UI naming | "Assigned task" (BM: "Tugas diberi"). Type defaults to `adhoc` in the assign modal. |

## 3. Approach

**Extend `WorkItem`** rather than build a separate model.

A tac is a `WorkItem` with `assigned_by_id` set and `employee_id` = the
assignee. It lands on the staff member's existing board automatically and
reuses the whole move / comment / notification pipeline. The trade-off — a
permission split in `WorkItemController` so an assignee can move+comment but
not edit/delete an assigned card — is contained and worth it.

Rejected: a separate `TaskAssignment` model + "Assigned to me" inbox. It
duplicates the kanban/status/comment machinery, gives staff two task surfaces,
and contradicts decision #3 ("lands on their work board").

## 4. Data Model

New migration on `work_items`:

| Column | Type | Notes |
|--------|------|-------|
| `assigned_by_id` | nullable FK → `employees.id` | **null = self-created card (unchanged); set = a tac.** The single marker. `nullOnDelete`. |
| `assigned_at` | nullable datetime | When the tac was created. |

`due_at` already exists (cast `date`) — reuse for the real due date.

New relation on `WorkItem`:

```php
public function assignedBy(): BelongsTo
{
    return $this->belongsTo(Employee::class, 'assigned_by_id');
}
```

A card **is a tac** iff `assigned_by_id !== null`.

## 5. Authorization

Privileged-assigner helper (roles): `manager`, `management`, `hr`.

Per-action rules (replacing the blanket owner assert):

| Action | Self-created card | Assigned tac |
|--------|-------------------|--------------|
| move (status) | owner | owner **or** assigner |
| show / comment | owner | owner **or** assigner |
| update fields | owner | **assigner only** (assignee locked out) |
| destroy | owner | **assigner only** (= cancel the tac) |
| assign (create on another's board) | — | privileged role only; target = any staff in tenant |

All checks remain tenant-scoped (existing `tenant_id` assert stays).

## 6. Flows

### 6.1 Assign

- Route: `POST /app/board/assign/{employee}` → `WorkItemController::assign()`.
- Guard: `abort_unless(canAssign($role), 403)`; resolve target `Employee`
  (tenant-scoped), `abort_unless` same tenant.
- Validate: `title` (req, max 160), `type` (in assignment,task,adhoc; default
  adhoc), `priority` (high,medium,low), `due_at` (nullable date),
  `description` (nullable, max 5000).
- Create on target's board: `employee_id = target->id`,
  `assigned_by_id = me->id`, `assigned_at = now()`, `status = 'todo'`,
  `sort_order` = bottom of target's todo column.
- Notify: `AppNotification::send(target->user_id, "{me->name} assigned you a task", title, route to board)`.

### 6.2 Completion loop

In `move()`: after status update, if new status === `done` **and**
`assigned_by_id` set → `AppNotification::send(assigner->user_id,
"{assignee->name} completed: {title}", null, board url)`. Guard against
re-notifying when already done (only fire on transition into `done`).

## 7. UI

### 7.1 Profile screen (`screens/profile.blade.php`, `?emp=`)

Visible to privileged viewers only:
- **"Assign task"** button → modal (teleported to body, centered — per the
  project's modal rule): title, type, priority, **due date picker**, description.
  Submits to the assign route.
- **"Tasks assigned to {name}"** panel below: live list of that employee's tacs
  — status pill, assigner name, due date, overdue flag. v1 scope is per-profile
  (one person at a time); a global cross-staff rollup is deferred.

### 7.2 Board card (`screens/board.blade.php` + `work-board.js`)

- Assigned cards render an **"Assigned by {Manager}"** badge (distinct from
  self-created).
- On an assigned card the assignee sees **no Delete** and **read-only** core
  fields; only column (status) and comments are editable. The detail modal
  must branch on `card.assigned_by` to lock the inputs.

## 8. Data Wiring

- `AppController::profileData()` — add the assignments list:
  `WorkItem::where('employee_id', $e->id)->whereNotNull('assigned_by_id')->with('assignedBy')->orderBy('due_at')->get()`,
  plus the privileged flag and tenant staff list for the modal's target (target
  is the profile's employee, so no picker needed — the button is on their page).
- `AppController::boardColumns()` — eager-load `assignedBy` so the badge renders
  without N+1.
- `cardPayload()` — include `assigned_by` (name/initials/color or null) and
  `due_at` so the front-end can branch.

## 9. Endpoints (new)

| Method | Path | Name | Handler |
|--------|------|------|---------|
| POST | `/app/board/assign/{employee}` | `work.assign` | `WorkItemController::assign` |

Existing `work.move`, `work.show`, `work.update`, `work.destroy`,
`work.comment` are reused with the new permission split.

## 10. Out of Scope (v1)

- Global "everything I assigned across all staff" rollup view.
- Accept / decline acknowledgement gate.
- Recurring or templated tasks.
- Reassignment to a different staff member after creation.

## 11. Testing

- **Unit/feature (PHPUnit/Pest):**
  - privileged role can assign; `employee` role gets 403.
  - assignee can `move` + `comment` an assigned tac.
  - assignee gets 403 on `update`/`destroy` of an assigned tac.
  - assigner can `update`/`destroy` their own tac.
  - assign creates the card on the **target's** board with `assigned_by_id` set.
  - assign fires a notification to the target user.
  - moving a tac to `done` notifies the assigner exactly once.
  - tenant isolation: cannot assign to an employee in another tenant.
- **Manual/visual:** badge shows on assigned cards; locked fields on assignee's
  modal; tracking panel lists tacs with correct status/overdue.
