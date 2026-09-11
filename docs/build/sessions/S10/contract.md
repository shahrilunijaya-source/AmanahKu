# Session S10 contract: CR-06b (contract variations, Director approval)

## Files touched

- `database/migrations/2026_09_15_100000_create_project_variations.php` (new)
- `database/migrations/2026_09_15_100100_change_project_variations_delta_to_string.php` (new,
  fix-up — the first migration had already run against the dev database with
  `delta decimal(14,2)` before the delta-column deviation below was decided; see OPEN)
- `app/Models/ProjectVariation.php` (new)
- `app/Models/Project.php` (add `variations()` hasMany, `pendingVariationsCount()`)
- `app/Projects/ProjectMaster.php` (extract `financeAuthorized(string $role): bool` so
  the variation raise gate reuses the exact check `update()` already uses, per the S09
  handoff trap)
- `app/Projects/ProjectVariations.php` (new service: raise/approve/reject)
- `app/Http/Controllers/ProjectController.php` (4 new actions: storeVariation,
  approveVariation, rejectVariation, variationAttachment; `screenData()` gains
  `canRaiseVariation` / `canDecideVariation`)
- `routes/web.php` (4 new routes next to the existing projects block)
- `app/Http/Controllers/Api/V1/ApiController.php` (`projects()` gains `as_of` query
  param and `awaiting_approval` per row)
- `resources/views/partials/ts-project-row.blade.php` (Variations section: list, raise
  form, approve/reject, "Awaiting approval" stamp)
- `tests/Feature/ProjectVariationTest.php` (new, validation edges + as_of API path)
- `app/Http/Middleware/ApiTenant.php` (shared middleware, not new to this CR — one-line
  fix plus a `try`/`finally` wrap; see OPEN "S10 / Sanctum default-guard leak")

## Schema

`project_variations`: id, tenant_id, project_id (FK cascade), vo_no string(40),
variation_date date, reason text, changes json, delta string(20) nullable,
attachment_path string(500) nullable, status string(10) default pending,
raised_by_id (FK users, nullOnDelete), decided_by_id (FK users, nullable,
nullOnDelete), decided_at timestamp nullable, decision_note text nullable,
version_id (FK project_versions, nullable, nullOnDelete), timestamps.
Unique (project_id, vo_no).

Exact shape taken from `/OPEN.md` "QA / CR-06b / shapes fixed by CR06bTest" — that
entry is this session's build spec; not repeated field-by-field here, with one
deviation: `delta` is built there as `decimal(14,2)`, built here as `string(20)`.
See OPEN "S10 / delta column type deviates from the QA-fixed shape" for why.
The model's `decimal:2` cast keeps every JSON/read consumer seeing the same shape
either way; only the raw column type differs.

## Acceptance verification (docs/specs/CR-06.md)

| # | Item | Verified by |
|---|---|---|
| 1 | Full-detail create appears in Track's list | S09 (CR06aTest) |
| 2 | Track pre-fill | human check, deferred (CR-06c) |
| 3 | VO dated 1 Oct awaiting approval, Oct report new value, Aug report old value | `tests/Acceptance/CR06bTest.php::test_acceptance_3_...`; also E4/E5 tests in the same file for the governance rules the item leans on |
| 4 | Track minimal save | human check, deferred (CR-06c) |
| 5 | Legacy rows as version 1 | S09 (CR06aTest) |
| 6 | Field permissions | S09 (CR06aTest) |
| 7 | Closed project lock | S09 (CR06aTest) |

Only item 3 is this session's to satisfy; items 1, 5, 6, 7 already pass from S09 and
must stay green (CR06aTest re-run at the end). Items 2 and 4 stay `markTestIncomplete`.
