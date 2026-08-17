# Projects — one register, one screen

2026-08-17

## Problem

Two screens own "project" today, and neither is a register.

- **New Project** (`resources/views/screens/project-quick-create.blade.php`,
  `ProjectQuickCreateController`) — nav section *My Team*, admitted to manager /
  management / HR by `AppController::screen` (`app/Http/Controllers/AppController.php:193`).
  Creates code + name + category tags and nothing else. No list. A manager creates a
  project and then cannot see, edit, or add sub-pillars to it.
- **Timesheet Setup** (`resources/views/screens/timesheet-setup.blade.php`,
  `TimesheetAdminController`) — management / HR only. Holds the real project list:
  edit, sub-pillars, deactivate. The company's project register is therefore buried
  inside a timesheet configuration screen and hidden from the managers who create
  projects.

The data layer is already standardised: one `projects` table feeds timesheet
allocation (`timesheet_entries.project_id`), T.A.A. board cards
(`work_items.project_id`), and Track via `GET /api/v1/projects`. Only the management
surface is split.

## Goal

One **Projects** screen that is the source of truth for every project in Unijaya:
see all of them, create, edit, sub-pillars, category tags. Timesheet Setup keeps
categories only.

## Non-goals

- **No new project fields.** No owner, client, status, dates, or description. Track
  already holds project detail (budget, phases, cadence); duplicating it here creates
  two records to keep current. Project stays `code`, `name`, `is_active`, `sort`,
  plus its category tags and sub-pillars.
- **No change to how projects are consumed.** Timesheets, board cards and Track's API
  read the same table, unchanged.
- **No change to the delete rule.** A project or sub-pillar with history is
  deactivated, never hard-deleted (`TimesheetAdminController::deleteProject`), so
  reports keep their labels. Behaviour moves verbatim.
- **No per-project permissions.** Access is by role, not by project membership.
- **No partial file renames.** `ts-project-*` / `ts-subpillar-*` partials keep their
  names. Cosmetic churn only, and their include sites are few.

## The screen

New screen id `projects`, view `resources/views/screens/projects.blade.php`, nav
section **Workplace** (with Room Booking, Vehicle Booking, Assets, Shared Resources —
the shared company reference lists).

Three parts, top to bottom:

1. **Header** — `partials.guide` with key `projects` (bilingual explainer, same as
   every setup screen), a live project count badge, a search box filtering by name or
   code, and a "show inactive" toggle. Inactive projects are hidden by default, so the
   list reads as current work. Search and toggle are client-side Alpine over the
   rendered cards — the tenant has dozens of projects, not thousands, so there is no
   server round-trip and no pagination.
2. **Add project** — collapsible panel, editors only, reusing
   `partials.ts-project-form` and the existing AJAX add script (`data-ajax` form →
   server-rendered row appended → form reset → focus back in the name field). Same
   pattern Timesheet Setup uses today, so bulk entry stays one screen, no reloads.
3. **The list** — one card per project via `partials.ts-project-row`, showing code
   chip, name, category tags, sub-pillar count, inactive marker, and a new **usage
   line**: how many timesheet lines and how many board cards point at that project
   (`"12 timesheet lines · 3 board cards"`, or `"not used yet"` when both are zero).
   That usage line is the register part — it distinguishes real work from leftovers.
   Expanding a card lists its sub-pillars.

Time is allocated as a **percentage** of the week, not hours
(`timesheet_entries.percentage`), so the usage line counts entries and cards. It does
not claim hours.

## Who sees what

**View: every authenticated user in the tenant.** No `roles` key on the nav item, no
role gate in `AppController::screen`. An employee sees the full list including
sub-pillars, and has no form and no buttons at all — no add panel, no Edit, no
Delete, no Add sub-pillar. Read-only means nothing to fill in.

**Edit (create / update / deactivate / sub-pillars): `['manager', 'management', 'hr']`**
(`director` folds into `management` via `Permissions::effectiveRole`). The blade
receives a `canEdit` flag from the controller and renders the write affordances only
for those roles; every write endpoint enforces the same set server-side.

`partials.ts-project-row` and `partials.ts-subpillar-row` take a new `$canEdit` bool
wrapping their Edit / Delete buttons, the inline edit form, and (on the project card)
the Add sub-pillar form. It defaults to `true` so the AJAX append path needs no
change — only an editor can trigger an add in the first place. With `canEdit` false a
project card is name, code, tags, usage line, and an expandable read-only sub-pillar
list.

Two security fixes ride along:

- `ProjectQuickCreateController::store` has **no role check** today. Its route
  (`routes/web.php:404`) sits in the plain `auth` + `tenant` group, so any logged-in
  employee could POST a new project. The screen was gated; the write was not. The
  replacement endpoint is gated.
- Project and sub-pillar writes are currently management/HR only
  (`TimesheetAdminController::PRIVILEGED_ROLES`), which would 403 the managers this
  screen now shows buttons to. The new endpoints admit managers.

## Code moves

Projects and categories now have **different edit roles**, so they can no longer share
one controller constant. That forces the split, and the split is the "one
standardisation" the screen is named for.

**New `app/Http/Controllers/ProjectController.php`** — moved verbatim from
`TimesheetAdminController`, only the role constant differs:

| Moved | Notes |
|---|---|
| `storeProject` / `updateProject` / `deleteProject` | unchanged bodies |
| `storeSubPillar` / `updateSubPillar` / `deleteSubPillar` | unchanged bodies |
| `validateProject` / `validateSubPillar` / `projectCategories` | unchanged |
| `screenData` | new: projects with counts + `projectCategories` + `canEdit` |
| `EDITOR_ROLES = ['manager', 'management', 'hr']` | replaces `PRIVILEGED_ROLES` here |

`screenData` loads:

```php
Project::with(['subPillars' => fn ($q) => $q->orderBy('sort')->orderBy('name'), 'categories'])
    ->withCount(['entries', 'workItems'])
    ->orderBy('sort')->orderBy('name')->get()
```

`Project::workItems(): HasMany` is added (`WorkItem::class, 'project_id'`) — the
inverse already exists at `app/Models/WorkItem.php:57`.

**Routes** (`routes/web.php`), replacing the six `timesheet.admin.projects.*` /
`timesheet.admin.subpillars.*` routes and `project-quick-create.store`:

```
POST /app/projects                              projects.store
POST /app/projects/{project}                    projects.update
POST /app/projects/{project}/delete             projects.delete
POST /app/projects/{project}/subpillars         projects.subpillars.store
POST /app/subpillars/{subPillar}                projects.subpillars.update
POST /app/subpillars/{subPillar}/delete         projects.subpillars.delete
```

`route()` call sites to update: `partials/ts-project-row.blade.php` (delete, update,
sub-pillar add), `partials/ts-subpillar-row.blade.php`, `screens/timesheet-setup.blade.php`
(the project block is deleted outright, so its calls go with it), and the AJAX render
calls in the moved controller methods.

**Deleted:** `ProjectQuickCreateController`, `screens/project-quick-create.blade.php`,
its route, and the `project-quick-create` role gate at `AppController.php:193`.

**`TimesheetAdminController`** keeps categories only: `screenData` drops the
`projects` and `projectCategories` keys; the six project/sub-pillar methods and their
two validators leave with the move; the class docblock and
`PRIVILEGED_ROLES` (management / HR) stay as-is for categories.

## Wiring and copy

- `app/Support/Amanahku.php:98` — the *My Team* "New Project" nav entry becomes a
  *Workplace* "Projects" entry (`id => 'projects'`, label `Projects` / `Projek`, no
  `roles` key). Its comment about server-gating is rewritten.
- `app/Support/Amanahku.php:377` — `project-quick-create` screens entry becomes
  `projects`: title "Projects" / "Projek", subtitle "Every project in Unijaya, and the
  sub-pillars under each one.", crumb `['Workplace', 'Projects']`.
- `AppController.php:231` — `project-quick-create` aliases to the `projects` view, and
  `:411` aliases to the same `screenData`, following the `claim-approvals` precedent
  at `:229-231` / `:368`, so old deep links and bookmarks still land somewhere real.
- `app/Support/Amanahku.php:376` — Timesheet Setup subtitle drops "projects and
  sub-pillars".
- `screens/timesheet-setup.blade.php:4-26` — guide copy (en + ms) drops the project
  and sub-pillar steps and gains a pointer line: projects live on the Projects screen.
  The `data-ajax` script at the bottom stays; category add still uses it.
- `SetupController.php:90` — wizard step description drops "projects and sub-pillars".
  Its auto-completion check counts categories only (`SetupController.php:124`), so the
  wizard keeps working untouched.

No feature-module gate applies: neither `timesheet-setup` nor `project-quick-create`
appears in `app/Support/Features.php`, so `projects` is core and always visible.

## Tests

New `tests/Feature/ProjectScreenTest.php`:

- employee GETs `/app/projects` → 200, and the response contains no add form and no
  edit/delete/sub-pillar controls
- employee POSTs `projects.store` → 403 (the hole being closed)
- manager POSTs `projects.store` → succeeds; manager updates a project, adds a
  sub-pillar, deactivates a used project (stays, `is_active` false)
- HR and management still pass every write
- usage counts render: a project with N timesheet entries and M board cards shows both

`tests/Feature/TimesheetSetupAjaxTest.php` — the four project/sub-pillar cases move to
the new file with the new route names; the category case stays. The AJAX contract
(`html` + `count_sel` JSON, 422 on validation error) is unchanged and must stay green.

`tests/Feature/TimesheetTest.php` and the report tests must pass untouched — proof that
moving the management surface did not disturb allocation.

## Build

Blade-only change reusing existing classes and inline styles. If any new Tailwind
utility class appears, run `lerd artisan view:cache && bun run build` and commit
`public/build` alongside, per CLAUDE.md.
