# Session S09 contract: CR-06a project master schema and versioning

Spec: `docs/specs/CR-06.md` (parts A, E1, E2, E3, E6, E7). Tests: `tests/Acceptance/CR06aTest.php`. Shapes follow OPEN "QA / CR-06a / shapes fixed by CR06aTest". Parts E4 and E5 (variations, approvals) are S10; Track (B, C, D, 6c) is deferred.

## Files

- `database/migrations/2026_09_14_100000_add_master_fields_to_projects.php`: new columns on `projects`, backfill `client` from the old `code` badge.
- `database/migrations/2026_09_14_100100_create_project_versions.php`: table `project_versions`; calls `projects:backfill-versions`.
- `app/Models/Project.php`: casts, `versions()`, `pm()`, `pe()`, `currentVersion()`, `versionEffectiveOn()`, master-field list.
- `app/Models/ProjectVersion.php` (new).
- `app/Projects/ProjectMaster.php` (new): field sets per role, `create()`, `update()` (permission check, in-place lock for contract value/dates/client, closed lock, version + audit), `close()`, `reopen()`.
- `app/Console/Commands/BackfillProjectVersions.php` (new): `projects:backfill-versions`.
- `config/projects.php` (new): `code_pattern`.
- `app/Http/Controllers/ProjectController.php`: validation for the new fields, JSON answer on update, `reopenProject`.
- `app/Http/Controllers/Api/V1/ApiController.php`: master fields and `version` on `GET /api/v1/projects`.
- `routes/web.php`: `POST /app/projects/{project}/reopen`.
- `resources/views/partials/ts-project-form.blade.php`, `ts-project-row.blade.php`, `screens/projects.blade.php`: the new fields grouped (Details, Contract, People), disabled outside the caller's field set, version history list per project, Reopen with reason for directors.
- `tests/Feature/ProjectMasterTest.php` (new); `tests/Feature/ProjectScreenTest.php` updated where it posts a create without `client` or `project_code`.

## Schema

`projects` + `project_code string(40) nullable unique(tenant_id, project_code)`, `client string(160) nullable`, `status string(10) default active`, `contract_value decimal(14,2) nullable`, `procurement_method string(80) nullable`, `contractor string(160) nullable`, `bond_value decimal(14,2) nullable`, `bond_submitted_at date nullable`, `loa_date date nullable`, `loa_ref string(80) nullable`, `agreement_date date nullable`, `agreement_ref string(80) nullable`, `contract_start date nullable`, `contract_end date nullable`, `drive_link string(500) nullable`, `pm_id`, `pe_id` FK employees nullOnDelete, `closed_at timestamp nullable`, `closed_by_id` FK employees nullOnDelete.

`project_versions`: `id, tenant_id FK, project_id FK cascade, version_no unsignedSmallInteger, effective_date date, snapshot json, changes json nullable, reason text nullable, created_by_id FK users nullOnDelete, created_at`; unique `(project_id, version_no)`.

Run on the dev DB with `lerd artisan migrate`.

## Verification per acceptance item

1. Full-detail create and Track list: CR06aTest 1; browser as Kussairi adding a project with every field, then `GET /api/v1/projects` with a token.
2. Track pre-fill: human check after the run (CR-06c deferred).
3. Variation and period reports: S10.
4. Track minimal save: human check after the run.
5. Legacy rows as version 1: CR06aTest 5; dev DB after migrate, every existing project has version 1 dated its creation.
6. Field permissions: CR06aTest 6; browser as Kussairi (PM set), Hidayah (finance set), Shazwan (read-only), Shahril (both).
7. Closed lock and reopen: CR06aTest 7; browser: Kussairi closes, Hidayah blocked, Shahril reopens with reason.
8. The four always checks.
