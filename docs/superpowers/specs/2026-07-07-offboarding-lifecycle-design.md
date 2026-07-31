# Offboarding Lifecycle — Design

**Date:** 2026-07-07
**Status:** Approved
**Author:** Shahril (with Claude)

## Problem

Amanahku has two departure-related surfaces that are fully built and tested but **do not talk to each other**:

- **Resignation** (`Resignation` + `ExitInterview`) — employee submits, HR acknowledges, exit interview recorded. Feeds auto-archival.
- **Offboarding** (`OffboardingCase` + `ClearanceItem`) — HR-managed exit-clearance checklist grouped by department.

Four gaps result:

| Gap | Effect today |
|-----|--------------|
| Resignation acknowledged → no offboarding case | HR re-enters employee / last-day / reason by hand |
| Clearance 100% → nothing | `offboarding_cases.status = 'completed'` is dead code |
| Archival ignores clearance | Staff auto-archived on last day with laptop uncollected / access not revoked, no flag |
| Termination / end-of-contract / retirement | **No auto-archival path at all** — only acknowledged resignations are archived |

## Decision

Adopt a **case-centric model**: the offboarding case is *the* departure record. A resignation is one trigger that opens a case; archival closes it. All departure reasons flow through the case, so all four gaps close — including the security-relevant hole where non-resignation leavers never have access revoked.

Approved policy decisions:

1. **Wiring depth:** Case-centric (rewrite `ArchiveDepartedStaff` to drive off cases).
2. **Auto-open:** Automatic on acknowledge (prefilled), no duplicate if a case already exists.
3. **Clearance gate:** Archive on last day regardless of clearance (access cutoff must never be blocked by a forgotten checkbox); flag outstanding items to HR.

## Architecture

```
Employee submits resignation ──► HR acknowledges ──► [OffboardingService::openCase]
                                                          │  (linked, prefilled, checklist seeded)
                                                          ▼
Termination/EOC/Retirement ──► HR opens case manually ──► OffboardingCase (in_progress)
                                                          │
                                          HR ticks clearance items (derived %)
                                                          │
                              last_day < today  ──► [ArchiveDepartedStaff, daily 00:30]
                                                          │
                            StaffArchiver::archive (sever login/ties, release assets)
                            case → completed + completed_at
                            linked resignation → completed
                            clearance < 100% → flag HR (notification + audit)
```

### Units and responsibilities

- **`OffboardingService`** (new) — owns case creation + checklist seeding. `openCase(Employee, Carbon $lastDay, string $reason, ?string $notes, ?Resignation): OffboardingCase`. Idempotent per resignation. Sole home of `STANDARD_CHECKLIST`. Depended on by `OffboardingController::store` and `ResignationController::acknowledge`.
- **`ArchiveDepartedStaff`** (rewrite) — case-driven sweep. Depends on `OffboardingService` (self-heal), `StaffArchiver`, `CurrentTenant`.
- **`StaffArchiver`** (unchanged) — the detach cascade. Already idempotent, already writes audit.
- **`OffboardingCase`** (extended) — gains `resignation()` relation, `completed_at` cast, and read helpers.

## Data model

New additive migration `2026_07_07_000001_link_offboarding_cases_to_resignations.php`:

```php
Schema::table('offboarding_cases', function (Blueprint $table) {
    $table->foreignId('resignation_id')->nullable()->after('employee_id')
          ->constrained('resignations')->nullOnDelete();
    $table->timestamp('completed_at')->nullable()->after('status');
});
```

- Do **not** edit the committed create-migration `2026_06_24_000009_create_offboarding_tables.php`.
- `status` enum already contains `completed` — reused, no enum change.
- `OffboardingCase` uses `$guarded = []` (mass-assign open) — no fillable edit needed; add `completed_at => datetime` cast and `resignation_id` handling.

## Behaviors

### B1 — `OffboardingService::openCase`

- Look for an existing `in_progress` case for the employee:
  - If one is already linked to *this* resignation → return it (idempotent).
  - If one exists that is **unlinked** (`resignation_id` null) → link it (set `resignation_id`, update `last_day`/`reason` to the resignation's) and return it, rather than create a duplicate.
  - Else create a new case (`status = in_progress`, prefilled) and seed `STANDARD_CHECKLIST` (8 items with `department`, `title`, `sort`).
- Audit: `AuditLog::record('Opened offboarding', employee->name)` (parity with existing store).

### B2 — `OffboardingController::store`

- Unchanged validation/authorization (privileged gate, `employee_id`/`last_day`/`reason`/`notes` rules).
- Body delegates to `OffboardingService::openCase(...)` with `resignation = null` (manual departures: termination/EOC/retirement, or a resignation not tracked in the resignation flow).
- Existing tests must stay green.

### B3 — `ResignationController::acknowledge`

- After setting `acknowledged` / `acknowledged_at` / `acknowledged_by_id` and sending the existing notification, call:
  `OffboardingService::openCase(employee, resignation->last_working_date, 'resignation', 'Auto-opened from resignation', resignation)`.
- Runs inside the same request; wrap acknowledge + openCase so a case is opened exactly once. Idempotent via B1.

### B4 — `ArchiveDepartedStaff` (rewrite)

Per tenant (keep the existing per-tenant loop + try/catch isolation, AK-REL-04):

1. **Primary:** `OffboardingCase` where `status = 'in_progress'` and `whereDate('last_day', '<', today)`, with `clearanceItems` + `employee` + `resignation`.
   - `StaffArchiver::archive(employee)` (idempotent).
   - Case → `completed`, `completed_at = now()`.
   - Linked resignation with `status = 'acknowledged'` → `completed`.
   - If any clearance item `done = false` → **flag**: `AppNotification::send` to management + HR users, message "Archived {name} with {n} clearance item(s) outstanding"; `AuditLog::record('Archived with outstanding clearance', "{name} · {n} open")`.
2. **Self-heal:** `Resignation` where `status = 'acknowledged'` and `whereDate('last_working_date', '<', today)` with **no** linked case (legacy rows acknowledged before auto-open existed). For each: `OffboardingService::openCase(...)` then run the same archive+complete+flag path. Guarantees no leaver is missed.
3. Report count.

`strictly < today` preserved — never archive on a day the person is still meant to work.

### B5 — Clearance is derived, not a status flip

- `toggleItem` unchanged. 100% cleared before last day shows a UI badge ("Cleared · awaiting last day") but does **not** set `completed`. Case becomes `completed` only at archival, keeping `completed` == "departed".

### B6 — UI

- **Offboarding screen** (`offboarding.blade.php`):
  - Linked case → small "From resignation" tag in the header.
  - `status = completed` → "Departed · archived {completed_at}" banner; clearance items rendered read-only (no toggle form).
  - Archived with outstanding items (`completed` AND undone items exist) → red warning "N item(s) were outstanding at archival".
- **Resignation screen** (`resignation.blade.php`):
  - Acknowledged/completed card gains a clearance indicator line "Clearance {cleared}/{total}" from the linked case.

## Testing

New/updated behaviors (PHPUnit feature tests, existing style + factories):

**`ArchiveDepartedStaffTest`** (rewrite the 4 existing + add):
- archives a resignation-linked case whose last day passed, runs the cascade, marks case + resignation completed
- archives a **termination** case (no resignation) whose last day passed — covers the previously-orphaned reasons
- does not archive before the last day
- ignores withdrawn resignations (no case, not acknowledged)
- idempotent for an already-archived leaver
- flags outstanding clearance (notification + audit) when items remain
- self-heals a legacy acknowledged resignation with no case

**`OffboardingTest`** (add, keep the 4 existing):
- acknowledging a resignation auto-opens a linked, prefilled case with the standard checklist
- auto-open is idempotent — re-acknowledge / existing case does not duplicate
- `store` still opens a manual case (delegation preserves behavior)

**`ResignationTest`** — keep the 7 existing green (acknowledge now also opens a case; assertion additive).

## Out of scope (YAGNI)

- Manual "mark case completed" button — archival is the sole completion trigger.
- Blocking archival on incomplete clearance — explicitly rejected (security).
- Cascading resignation completion from clearance-100% — rejected (would mark someone departed early).
- Reworking the exit-interview flow, or a deep-link from the resignation card into a specific offboarding case.

## Files touched

| File | Change |
|------|--------|
| `database/migrations/2026_07_07_000001_link_offboarding_cases_to_resignations.php` | new — `resignation_id`, `completed_at` |
| `app/Services/OffboardingService.php` | new — `openCase` + `STANDARD_CHECKLIST` |
| `app/Models/OffboardingCase.php` | `resignation()` relation, `completed_at` cast, helpers (`isCompleted`, outstanding count) |
| `app/Http/Controllers/OffboardingController.php` | `store` delegates to service; `STANDARD_CHECKLIST` moves to service |
| `app/Http/Controllers/ResignationController.php` | `acknowledge` auto-opens case |
| `app/Console/Commands/ArchiveDepartedStaff.php` | case-centric rewrite + self-heal + flag |
| `resources/views/screens/offboarding.blade.php` | completed/archived banner, outstanding warning, from-resignation tag |
| `resources/views/screens/resignation.blade.php` | clearance indicator on acknowledged card |
| `tests/Feature/ArchiveDepartedStaffTest.php` | rewrite + additions |
| `tests/Feature/OffboardingTest.php` | additions |
