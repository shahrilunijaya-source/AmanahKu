# Contract: roles

Frozen by S00 from the real schema. Sessions read this, never edit it.

## App-level roles (membership role, one per user per tenant)

Enum on the tenant membership row (`database/migrations/2026_07_07_000005_add_director_to_role_enum.php`), constants in `app/Support/Permissions.php`:

| Role | Meaning in Unijaya | Notes |
|---|---|---|
| `employee` | developer / staff | default |
| `manager` | project manager, immediate superior | verifies requests, `OVERSIGHT_ROLES` |
| `hr` | HR + finance | final approval, `FINAL_APPROVAL_ROLES`, `OVERSIGHT_ROLES` |
| `management` | management tier | `MANAGEMENT_TIER`; no real user holds it in the prod data |
| `director` | director | `MANAGEMENT_TIER`; `Permissions::effectiveRole('director') === 'management'` for every gate |

"PM and above" in a CR = `manager`, `hr`, `management`, `director`. "Director / HR / Sr Mgr" in CR-17 = `Permissions::FINAL_APPROVAL_ROLES` (`management`, `director`, `hr`); there is no separate senior-manager role, a senior manager is a `manager` whose DataScope is `branch` or wider. Data reach is a separate axis: `Permissions::SCOPES` = own, team, department, branch, company, resolved through `DataScope`.

Never add a role to the enum. Never gate on the raw string `director`; go through `effectiveRole()` or the constants.

## Card-level roles (per work item)

Source of truth after S03. Until S03 lands, the columns marked *new* do not exist.

| Card role | Storage | Cardinality | Credit |
|---|---|---|---|
| Assigned (Primary Owner) | `work_items.employee_id` | exactly one | completion credit, overdue responsibility |
| Creator | `work_items.assigned_by_id` (nullable; null = self-created, owner is creator) | one | none unless also Assigned |
| Tagged, Helper | `work_item_participant` pivot, `role = 'helper'` (*new column on the pivot*) | many | helper credit |
| Tagged, FYI | `work_item_participant` pivot, `role = 'fyi'` (*new*) | many | none |
| Reviewer | `work_items.reviewer_id` (*new*, nullable, FK employees) | at most one, never equal to `employee_id` | none; sole authority for In Review to Done when set |

Rules:
- Existing pivot rows migrate to `role = 'helper'` (the safe, credit-bearing default of today's "shared with" meaning). Log to OPEN.
- Subtasks are `work_items` rows with `parent_id`; they use the same columns, same roles. No separate subtask role model.
- Board visibility for a person = `employee_id = me OR participant(me) OR reviewer_id = me`, through the existing `BoardRules`/`BuildsWorkData` paths, not a new query layer.
- Counters (open / overdue / blocked / in review) count Assigned only. Helper and Reviewer items are reported separately.
- `BoardRules::canManage()` stays the write gate. Reviewer gains exactly one extra power: the In Review to Done move.
