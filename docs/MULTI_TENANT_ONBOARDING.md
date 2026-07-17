# Multi-Tenant Company Onboarding & Access

_Added 2026-06-27. Builds on the existing multi-tenant foundation (Tenant model, `BelongsToTenant` global scope, `ResolveTenant` / `EnsureSuperAdmin` / `EnsureModuleEnabled` middleware, `Features` registry + `FeatureManager`)._

## Flow

```
Superadmin → Create Company (+ category, profile) → category seeds feature entitlement
          → Create first Company Admin (one-time password, emailed)
Company Admin → branded /login/{slug} → first sign-in password rotation
          → Setup Wizard → configure branches/departments/positions/levels/types/roles
          → Add Staff → Staff activate via branded portal → see only permitted modules/data
```

## Company Categories (Stage 1 / 2 / 3)

- `company_categories` table (seeded in migration) holds the three stages + level (1/2/3).
- Each module in [Features.php](../app/Support/Features.php) `MODULES` carries a 3rd element: its **stage**. The package for a stage = every module with `stage ≤ level` (cumulative). Single source of truth — no parallel mapping table.
- `tenants.company_category_id` records the assigned category.
- `FeatureManager::applyCategoryPackage($tenant, $level)` writes explicit `tenant_features` rows so the **resolved entitlement is the source of truth**, not the category. Super-admin sets/changes category; re-applying is explicit + audited.

## Company Profile + Lifecycle

`tenants` gained: `registration_number`, `company_code` (unique), `industry`, `address`, `contact_number`, `email`, `website`, `logo_path`, `secondary_color`, `welcome_message`, `status` (active|suspended), `subscription_start/end`. All additive + nullable.

- **Super-admin owns** category, plan, slug, status, subscription dates — set only via the super-admin console; backend-enforced.
- **Company admin owns** branding (logo, colours, welcome), industry, contact, address — via Company Settings ([AdminController::updateSettings](../app/Http/Controllers/AdminController.php)). Restricted fields are not in the form and are rejected server-side.
- `EnsureCompanyIsActive` middleware (alias `company.active`, on the tenant route group) blocks all `/app/*` for a suspended or subscription-expired company with a clear notice ([errors/company-suspended.blade.php](../resources/views/errors/company-suspended.blade.php)).

## Branded Login

- `GET /login/{tenant:slug}` → [AppController::brandedLogin](../app/Http/Controllers/AppController.php) renders the shared login view with the company's logo, name, colours and welcome message; stores `intended_tenant` in session. Unknown slug → 404.
- Post-auth, `AppController::tenantSelect` consumes `intended_tenant`: a genuine member is redirected straight into that company; a non-member silently sees their own workspace picker. No cross-tenant exposure.
- Generic `/login` + post-login picker retained. Invite emails link to `/login/{slug}`.

## Setup Wizard

- `company_setup_progress` table (one row per tenant; manual step keys + `completed_at`).
- [SetupController](../app/Http/Controllers/SetupController.php) — 10 ordered steps. Data-backed steps (branch/department/position/level/type/staff/profile/roles) auto-detect; the rest are marked done by the admin. Reuses existing CRUD screens — no duplicated business logic.
- Screen: `/app/setup` (privileged). Progress card on the admin dashboard.

## Org Lookups + Richer Fields

- `staff_levels` + `employment_types` (tenant-scoped) with admin CRUD in Company Settings; selectable on the add/edit staff form. `employees.staff_level_id` / `employment_type_id` added (legacy `level` string kept).
- Additive nullable columns on `branches` (code, type, address, contact, email, status, effective_date) and `positions` (code, staff_level_id, reports_to_position_id, default_role, is_managerial, description, status) with `unique(tenant_id, code)`.

## Routes added

Super-admin (`superadmin.*`): `companies.edit`, `companies.update`, `companies.category`, `companies.status`.
Tenant app: `setup.step`, `setup.finish`, `admin.staff-levels.*`, `admin.employment-types.*`.

## Tests

[tests/Feature/MultiTenantOnboardingTest.php](../tests/Feature/MultiTenantOnboardingTest.php) — category→entitlement, stage re-apply, disabled-module 404, suspend/reactivate, suspended-blocks-routes, branded login render + 404 + member auto-enter + non-member ignored, company-admin field boundary, staff-level tenant isolation, setup-finish gate. `SuperAdminCompanyTest` updated for the required category field.

## ACL: Permissions + User Overrides + Data Scope (layered on the role enum)

Implements the spec §9 access formula: **feature entitlement + role permission + user override + data scope**.

- **Role permissions** — [Permissions.php](../app/Support/Permissions.php): role → permission map (spec §9 keys), `forRole()` / `roleHas()` / `all()` / `overridable()`.
- **User overrides** — `user_permissions` table + [UserPermission](../app/Models/UserPermission.php) (grant/deny per member). `User::resolvedPermissionsIn()` = role perms ± overrides; `User::canInTenant($tenant, $perm)`. A redundant override (restating the role) is not stored.
- **Enforcement** — `EmployeeController` gates staff create/update/import on `staff.create|update|import` via `canInTenant` (so overrides work end-to-end; defaults match the previous role gate). Other domains still use role checks (documented).
- **Data scope** — `tenant_user.data_scope` (own/team/department/branch/company, default `company` → no behaviour change). [DataScope.php](../app/Services/DataScope.php) `applyToEmployees()` narrows the directory; `ResolveTenant` exposes `tenantScope`.
- **UI** — Roles & Permissions screen: per-member **Data scope** selector (`admin.scope.update`), per-member **permission override** editor (inherit/grant/deny, `admin.permissions.update`), and a role→permission reference.

## Account Activation Link

- Signed, expiring activation link in the invite email ([MemberInvited](../app/Notifications/MemberInvited.php)) alongside the one-time password.
- [ActivationController](../app/Http/Controllers/ActivationController.php) (`activation.show` / `activation.update`, guest + `signed`): the member sets their own password → `password_change_required` cleared, `email_verified_at` stamped, logged in. The link is single-use in effect (rejected with 410 once the account is active).

## Bulk Staff Import (CSV)

- [EmployeeController::import / importTemplate](../app/Http/Controllers/EmployeeController.php) — upload a CSV to create many directory records at once; department/branch/staff-level/employment-type matched by name within the tenant, invalid rows skipped + reported, capped at 1000 rows. Privileged-only (`staff.import`). Downloadable template. "Import CSV" UI on the directory. Creates directory records only — login accounts are still provisioned via the per-member invite. (Native CSV — no new dependency; true `.xlsx` parsing remains a possible follow-up.)

## Branch / Position Extended Fields (UI)

- Branch (location) add/edit forms in Company Settings now capture `code`, `type` (HQ/Branch/Office/Outlet/Project Site/Operational/Other), `address`, `contact_number`, `email`, `status`, `effective_date` (`code` unique per tenant).
- Position add form captures `code`, `default_role`, `is_managerial` (plus `description`/`status` accepted by the controller). The dense rate-card inline editor keeps its band fields; the new metadata is on the add form.

## Deferred (documented follow-up)

- Extending permission *enforcement* (via `permission:` middleware / `canInTenant`) beyond the staff domain to every controller — currently the staff domain is enforced and other domains keep their role checks.
- True `.xlsx` parsing for bulk import (CSV import is implemented; `.xlsx` needs a spreadsheet dependency).
