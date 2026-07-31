# Shared Resources — Design

**Date:** 2026-06-26
**Branch:** feat/full-i18n
**Status:** Approved design → ready for implementation plan

## Problem

The company has accounts and tools shared by all staff — the Unijaya Gmail account,
Canva, Blue Dot, the WhatsApp number, the inhouse system. Today there is no single
place to find them, so staff ask around for logins and links. We want one dedicated
screen that lists every shared resource with its link and credentials.

## Decisions (locked)

| Decision | Choice |
|----------|--------|
| What each resource shows | **Full credentials visible** to all staff (username + password shown plainly) |
| Who can add/edit/delete | **Managers + Management + HR** (the standard privileged set). All staff view read-only. |
| Storage | **DB-backed CRUD** — HR maintains via the UI, no code change to update the list |
| Sidebar placement | **Workplace** section |
| Seed | **Yes** — pre-seed the 5 named resources as placeholders |

## Security posture

- **Edit restricted** to privileged roles (`manager`, `management`, `hr`); plain
  employees cannot mutate. Enforced server-side in every write action, not just hidden in UI.
- **Password encrypted at rest** via Laravel's `encrypted` cast. The DB column holds
  ciphertext; the UI decrypts and shows plaintext. Protects DB dumps/backups without
  changing the agreed UX.
- **All writes audit-logged** via `AuditLog::record(...)` — who changed which resource.
- **Accepted residual risk:** any signed-in staff member can read every shared password
  in plaintext on this screen. This is inherent to the "full credentials visible" choice
  and is acceptable because these are deliberately-shared company accounts.

## Architecture

The app renders every screen through `AppController::screen()`, keyed by a screen id.
Adding a screen = nav entry + page meta + breadcrumb translations + a `screenData` case
+ a Blade view. This feature follows that path exactly; no new infrastructure.

### Screen wiring

- Screen id: `shared-resources`. **Core** screen (no feature-module gate) so it is
  always available.
- `App\Support\Amanahku::nav()` — add a leaf item in the **Workplace** section:
  - `id => 'shared-resources'`, label EN `Shared Resources` / BM `Sumber Bersama`, key icon.
- `Amanahku::page()` — add title/subtitle (EN + BM) and breadcrumb `['Shared Resources']`.
- `Amanahku::crumbMap()` — add `'Shared Resources' => 'Sumber Bersama'`.
- `AppController::screenData()` — add
  `'shared-resources' => app(SharedResourceController::class)->screenData($request),`.
  No role gate on view (all staff). Privilege is enforced only in the write actions.

### Data model

New migration `create_shared_resources` and model `App\Models\SharedResource`
(`use BelongsToTenant; protected $guarded = [];`).

Table `shared_resources`:

| column | type | notes |
|--------|------|-------|
| id | id | |
| tenant_id | foreignId → tenants, cascadeOnDelete | auto-filled by `BelongsToTenant` |
| name | string | e.g. "Company Gmail" |
| category | enum(`email`,`design`,`comms`,`system`,`storage`,`other`) default `other` | drives icon + colour swatch |
| url | string nullable | login link |
| username | string nullable | account login / email |
| password | string nullable | **`encrypted` cast** — ciphertext at rest, shown plaintext in UI |
| notes | text nullable | access instructions ("2FA on Yati's phone", etc.) |
| sort_order | integer default 0 | manual ordering within a category |
| timestamps | | |

Model casts: `['password' => 'encrypted']`.

### Controller `App\Http\Controllers\SharedResourceController`

`declare(strict_types=1);`. Mirrors `HelpdeskController` conventions.

- `const PRIVILEGED_ROLES = ['manager', 'management', 'hr'];`
- `const CATEGORIES = ['email', 'design', 'comms', 'system', 'storage', 'other'];`
- `screenData(Request $request): array`
  - All `SharedResource` ordered by `sort_order`, then `name`.
  - `grouped` = resources keyed by category (only non-empty categories shown).
  - `canManage` = current role ∈ PRIVILEGED_ROLES.
  - `categories` = CATEGORIES (for the add/edit form select).
- `store(Request $request): RedirectResponse`
  - `abort_unless` role ∈ PRIVILEGED_ROLES.
  - Validate: `name` required string max:120; `category` Rule::in(CATEGORIES);
    `url` nullable url max:255; `username` nullable string max:160;
    `password` nullable string max:255; `notes` nullable string max:2000;
    `sort_order` nullable integer.
  - `SharedResource::create(...)` (tenant_id auto). `AuditLog::record('Added shared resource', $name)`.
- `update(Request $request, SharedResource $resource): RedirectResponse`
  - `abort_unless` privileged; `abort_unless` `$resource->tenant_id === CurrentTenant::id()`.
  - Same validation; `$resource->update(...)`. `AuditLog::record('Updated shared resource', $name)`.
- `destroy(Request $request, SharedResource $resource): RedirectResponse`
  - `abort_unless` privileged; tenant-ownership check; `$resource->delete()`.
    `AuditLog::record('Deleted shared resource', $name)`.

### Routes (`routes/web.php`)

Inside the `['tenant', 'module.enabled']` group, before the `/app/{screen?}` catch-all,
mirroring the existing positions/branches POST-delete convention:

```php
Route::post('/app/shared-resources', [SharedResourceController::class, 'store'])->name('shared-resources.store');
Route::post('/app/shared-resources/{resource}', [SharedResourceController::class, 'update'])->name('shared-resources.update');
Route::post('/app/shared-resources/{resource}/delete', [SharedResourceController::class, 'destroy'])->name('shared-resources.destroy');
```

### View `resources/views/screens/shared-resources.blade.php`

- Reuse `partials/guide-banner` and `partials/field-hint` (self-teaching layer).
- Resources rendered as cards grouped by category. Each card:
  - category icon + colour swatch, resource name, category badge;
  - **Open ↗** link to `url` (new tab) when present;
  - `username` row with a copy button;
  - `password` row in mono font, shown plainly, with a copy button;
  - `notes` block when present.
- Privileged users (`canManage`): an **Add resource** button and per-card **Edit** /
  **Delete** controls. Add/Edit open a modal **teleported to `body`**
  (`<template x-teleport="body">`) so it is centred, per the project's modal rule.
- Empty state (no resources): friendly prompt; privileged users see the Add button,
  others see "Ask HR to add the shared accounts here."
- Fully bilingual — every label uses the `$store.ui.lang === 'en' ? ... : ...` pattern
  with BM strings, matching the rest of the i18n branch.

### Seed

A seeder (and/or migration data step) pre-fills 5 placeholder rows for the default
tenant so the screen is populated on first view. Names + categories filled;
url/username/password left blank for HR to complete:

| name | category |
|------|----------|
| Unijaya Gmail | email |
| Canva | design |
| Blue Dot | other |
| Company WhatsApp | comms |
| Inhouse System | system |

(HR can recategorise Blue Dot once its purpose is confirmed.)

## Testing

Feature tests (PHPUnit/Pest, mirroring existing controller tests):

- Privileged role (hr/management/manager) can store, update, delete a resource.
- Plain employee POST to any write route → 403.
- Tenant isolation: a resource from tenant B is not visible/editable in tenant A;
  cross-tenant update/delete → 403.
- The `shared-resources` screen renders for a plain employee (read-only, no Add button).
- Password round-trips through the `encrypted` cast (stored ciphertext, read plaintext).

## Out of scope (YAGNI)

- Per-resource visibility/ACL (everyone sees everything by design).
- Password reveal/hide toggle (full credentials visible is the chosen UX).
- Password rotation reminders, expiry, or strength checks.
- A feature-module toggle in Company Settings (can be added later if a tenant wants
  to switch the screen off).
