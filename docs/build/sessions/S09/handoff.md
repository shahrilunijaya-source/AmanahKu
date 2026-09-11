# Session S09 handoff: CR-06a (project master schema and versioning)

## Delivered
- Project master fields (project_code, client, status, contract terms, procurement, bond, LOA/agreement dates+refs, PM/PE, drive link) added to `projects`, verified by acceptance 1.
- `project_versions`: version 1 written on create, a new version on every master-field change with old/new deltas, `effective_date`-based point-in-time lookup (`Project::versionEffectiveOn()`), verified by acceptance 1 and 5.
- Legacy projects backfilled to version 1 (effective date = created_at date, `client` defaulted from the old `code` badge when blank), via `php artisan projects:backfill-versions`, called automatically from the `project_versions` migration, verified by acceptance 5.
- Field-level role permission: finance set (contract_value, bond_value, bond_submitted_at, loa_date/ref, agreement_date/ref) editable by hr/management; PM set (pm_id, pe_id, status, drive_link, procurement_method, contractor) editable by manager/management; base set (name, sort, categories, is_active, code) editable by any of manager/hr/management; a field outside the caller's set 403s naming the field; director folds into management throughout. Verified by acceptance 6.
- Contract-terms lock (contract_value, contract_start, contract_end, client cannot move in place, only via the CR-06b Variation path in S10) and the project_code immutability lock, both 422 naming the field/path. Verified by acceptance 6.
- Closed-project lock: every update 422s "closed" while `status = closed`; `POST /app/projects/{project}/reopen` (management tier only, reason required) reopens and writes a version. Verified by acceptance 7.
- Every master-field change and reopen writes one `AuditLog::change()` row per field plus the version row's own reason/created_by trail.
- `GET /api/v1/projects` (ability `projects:read`) now carries project_code, client, status, contract_value, contract_start/end, procurement_method, contractor, drive_link, pm/pe display names, and current version number, so Track can pull them. Verified by acceptance 1's Track-payload assertions.
- Projects screen: add-form and each row's edit form disable fields the viewer's role cannot touch, disable the locked/variation fields once a project exists, show a collapsible version history per row, and (for management tier on a closed project) an inline reopen form.

## Schema changes
- `projects`: `database/migrations/2026_09_14_100000_add_master_fields_to_projects.php` adds project_code (nullable, unique per tenant), client, status (default active), contract_value, bond_value, procurement_method, contractor, bond_submitted_at, loa_date, loa_ref, agreement_date, agreement_ref, contract_start, contract_end, drive_link, pm_id, pe_id (FK employees, nullOnDelete), closed_at, closed_by_id (FK employees, nullOnDelete). Backfills `client` from the old `code` badge where client is null.
- `project_versions` (new table): `database/migrations/2026_09_14_100100_create_project_versions.php` — id, tenant_id, project_id, version_no, effective_date, snapshot (json), changes (json, nullable), reason (nullable), created_by_id (FK users, nullOnDelete), created_at; unique(project_id, version_no). Calls `projects:backfill-versions` on `up()` to version every pre-existing project as v1.

## Contracts touched
- None. `docs/build/contracts/*` and `tests/Acceptance/*` are read-only input per the standing rules; not edited.

## Port calls stubbed
- None. CR-06a has no external port calls (Track integration is a read-only API pull by Track itself, not an outbound call from this app).

## Deferred
- CR-06b (Variations: the actual "raise a Variation to move contract_value/contract_start/contract_end/client" workflow) — S10. `ProjectMaster::VARIATION_FIELDS` already names the locked set and 422s pointing at it; S10 wires the actual variation record and approval.
- CR-06c (Track pull, the live integration itself) — acceptance items 2, 3, 4 in `tests/Acceptance/CR06aTest.php` are `markTestIncomplete` placeholders for this; the API payload shape they'll consume is already shipped in `ApiController::projects()`.

## OPEN, decided without Shazwan
- Schema/field-set/route/version shapes: see `/OPEN.md` entry "QA / CR-06a / shapes fixed by CR06aTest" (already present before this session; S09 built to it).
- `config/sanctum.php` guard-array change (`['web']` -> `[]`) to stop a leftover web session from shadowing a bearer-token API request: see `/OPEN.md` entry "S09 / CR-06a / sanctum guard collision on `/api/v1/projects`".

## Requested contract change (generator may not make it itself)
- None.

## Traps for the next session
- `Project::versions()` has a default `orderBy('version_no')` (ascending). Chaining `orderByDesc()` on top of that relation does NOT override it in Eloquent, the SQL ORDER BY takes the first clause first. Both `currentVersion()` and `versionEffectiveOn()` call `->reorder()` before applying their own ordering; if S10 adds another version-ordered query, it needs the same `reorder()` or it will silently return the wrong row.
- `ProjectMaster::update()` checks in this exact order: closed lock, project_code lock, then VARIATION_FIELDS (branches on finance-authorization: a non-finance role gets an ordinary 403, hr/management get a 422 pointing at the Variation path), then per-field role permission. S10's Variation workflow needs to reuse the finance-authorization check (`Permissions::effectiveRole($role) in ['hr','management']`) rather than reinvent it.
- `config/sanctum.php` `'guard' => []` (see OPEN entry above) — do not put `'web'` back without re-testing the full suite; the collision it fixes is a real cross-test risk, not a local quirk.
- This worktree's dev DB is empty, so the legacy-backfill path (`projects:backfill-versions` defaulting `client` from `code`) is only verified by `tests/Feature/ProjectMasterTest.php` and `tests/Acceptance/CR06aTest.php` acceptance 5, not against real production-shaped data. Worth an eyeball against a real dump before this ships.
- `Project::MASTER_FIELDS` is the single source of truth for what gets diffed into a version's `changes` and what `masterSnapshot()` captures; any new master field must be added there or it silently skips versioning and the audit log both.
