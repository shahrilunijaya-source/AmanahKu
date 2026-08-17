# Projects — one register, one screen

2026-08-17

## Problem

**Two screens own "project", and neither is a register.**

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

**Sub-pillars are stored per project but used company-wide.** The schema says a
sub-pillar belongs to one project (`project_sub_pillars.project_id`, unique per
project). The data says otherwise — every project record that has sub-pillars carries
the identical three:

| Projects with sub-pillars | Distinct sets in use |
|---|---|
| 24 | 1 — `Management`, `Meeting`, `Technical` |

Only the insertion order differs. `Management` appears on 24 projects, `Meeting` on 24,
`Technical` on 24, and nothing else exists. These are not parts of a project, they are
**kinds of work**: a second axis that happens to be identical everywhere, stored 24
times. Adding a project means retyping the same three names, and renaming one would
mean editing it 24 times.

Two supporting facts, both from the live data:

- **77% of timesheet lines carry no sub-pillar** (169 entries, 130 blank). It is
  optional and usually skipped, so the fill effort is pure overhead.
- **Every project's `code` is empty** (28 of 28). A code chip on every card would
  render an em dash and nothing else.

The projects table itself is already standardised: it feeds timesheet allocation
(`timesheet_entries.project_id`), T.A.A. board cards (`work_items.project_id`) and
Track via `GET /api/v1/projects`.

## Goal

One **Projects** screen that is the source of truth for project work at Unijaya: every
project, plus the one shared list of sub-pillars they all draw on. Sub-pillars become
tenant-wide records instead of per-project copies. Timesheet Setup keeps categories
only.

## Non-goals

- **No new project fields.** No owner, client, status, dates, or description. Track
  already holds project detail (budget, phases, cadence); duplicating it here creates
  two records to keep current. Project stays `code`, `name`, `is_active`, `sort`, plus
  its category tags.
- **No change to how staff allocate time.** The timesheet picker keeps its three-step
  drill (category → project → sub-pillar) and `resources/js/timesheet-capture.js` ships
  unchanged. See *Timesheet picker* below.
- **No change to the delete rule.** A project or sub-pillar with history is
  deactivated, never hard-deleted, so reports keep their labels.
- **No per-project permissions.** Access is by role, not by project membership.
- **No partial file renames.** `ts-project-*` / `ts-subpillar-*` partials keep their
  names — churn with no behaviour change, and their include sites are few.
- **`project_sub_pillars` is not dropped in this release.** It is left in place,
  unused, and dropped in a later one. See *Data change*.

## The screen

New screen id `projects`, view `resources/views/screens/projects.blade.php`, nav
section **Workplace** (with Room Booking, Vehicle Booking, Assets, Shared Resources —
the shared company reference lists).

Four parts, top to bottom:

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
   That script is inline at `screens/timesheet-setup.blade.php:88-137` and two screens
   now need it, so it is **extracted to `partials.ajax-row-add`** and included by both.
   Not copied: a second copy is how the last one rotted. While extracting, its
   `if (! res.d.count_sel)` branch — which bumped a project card's own `[data-sub-count]`
   — is removed: sub-pillars no longer live inside a project card, so that path is dead.
3. **The project list** — one card per project via `partials.ts-project-row`: name,
   category tags, inactive marker, and a **usage line** — how many timesheet lines and
   how many board cards point at that project (`"12 timesheet lines · 3 board cards"`,
   or `"not used yet"` when both are zero). That usage line is the register part; it
   distinguishes real work from leftovers. The **code chip renders only when the project
   has a code**, otherwise the name starts at the card's left edge. Every project's code
   is empty today, so an unconditional chip would be 28 em dashes. No sub-pillar
   expander on the card — sub-pillars are no longer a property of a project.
4. **Sub-pillars** — a second, deliberately small section below the projects, listing
   the tenant's shared sub-pillars (today: Management, Meeting, Technical) with their
   own usage counts and, for editors, an inline add box plus edit/deactivate per row.
   Its explainer line states the relationship plainly: these apply to every project, and
   staff pick one when they book time.

Time is allocated as a **percentage** of the week, not hours
(`timesheet_entries.percentage`), so every usage line counts entries and cards. It does
not claim hours.

## Who sees what

**View: every authenticated user in the tenant.** No `roles` key on the nav item, no
role gate in `AppController::screen`. An employee sees both lists and has no form and
no buttons at all — no add panels, no Edit, no Delete. Read-only means nothing to fill
in.

**Edit (create / update / deactivate, projects and sub-pillars):
`['manager', 'management', 'hr']`** (`director` is admitted: `Controller::hasTenantRole`
at `app/Http/Controllers/Controller.php:32-33` tests the raw role *and* its
`Permissions::effectiveRole`, and director folds into management). The blade receives a
`canEdit` flag from the controller and renders the write affordances only for those
roles; every write endpoint enforces the same set server-side.

`partials.ts-project-row` and `partials.ts-subpillar-row` take a new `$canEdit` bool
wrapping their Edit / Delete buttons and inline edit form. It defaults to `true` so the
AJAX append path needs no change — only an editor can trigger an add in the first
place.

Two security fixes ride along:

- `ProjectQuickCreateController::store` has **no role check** today. Its route
  (`routes/web.php:404`) sits in the plain `auth` + `tenant` group, so any logged-in
  employee could POST a new project. The screen was gated; the write was not. The
  replacement endpoint is gated.
- Project and sub-pillar writes are currently management/HR only
  (`TimesheetAdminController::PRIVILEGED_ROLES`), which would 403 the managers this
  screen now shows buttons to. The new endpoints admit managers.

## Data change

**New table `sub_pillars`** — tenant-scoped, project-free:

```
id, tenant_id (FK, cascade), name (160), is_active (default true),
sort (default 0), timestamps, unique(tenant_id, name)
```

`App\Models\SubPillar` replaces `App\Models\ProjectSubPillar`: same shape minus
`project()`, plus `entries(): HasMany(TimesheetEntry, 'sub_pillar_id')`.

**Migration order** — one migration, and the order is load-bearing:

1. Drop the `sub_pillar_id` foreign keys on `timesheet_entries` and
   `timesheet_templates` (both currently point at `project_sub_pillars`).
2. Create `sub_pillars`.
3. Backfill: for each tenant, one row per distinct `lower(trim(name))` found in
   `project_sub_pillars`. Active if **any** source row was active. `sort` from the
   lowest source `sort`, then name.
4. Remap `timesheet_entries.sub_pillar_id` and `timesheet_templates.sub_pillar_id`
   **strictly by name match** (old row → its tenant + lowercased trimmed name → the new
   row's id). Old ids run 1..~72 and new ids run 1..~6, so they overlap numerically —
   matching on id in any form would corrupt history silently.
5. Add the new foreign keys pointing at `sub_pillars`, `nullOnDelete` as before.
6. Assert, and throw on failure: every non-null `sub_pillar_id` in both tables resolves
   to a `sub_pillars` row whose name equals the pre-migration name. A loud failed
   deploy beats a quiet wrong remap.

Written in **portable Eloquent, not `UPDATE ... JOIN`** — migrations run on sqlite
under `RefreshDatabase`, so MySQL-only syntax would pass on the dev DB and break the
test suite. At 72 source rows, performance is irrelevant.

`project_sub_pillars` is **left in place and untouched**, unused by any code path after
this release. It is the rollback: the remap is reversible by name while both tables
exist. A later release drops it, once staging and prod have both run clean.

**Consumers to update** (every `sub_pillar` reference outside the timesheet picker):

| Place | Change |
|---|---|
| `TimesheetController:213`, `:338` | `Rule::exists('project_sub_pillars', …)` → `'sub_pillars'` |
| `TimesheetController:870-872` | the "does not belong to the chosen project" check is **deleted**, not rewritten — the tenant-scoped `exists` rule already covers validity |
| `TimesheetController:847` | `ProjectSubPillar::whereIn(...)` → `SubPillar::whereIn(...)` |
| `TimesheetEntry::subPillar()`, `TimesheetTemplate::subPillar()` | point at `SubPillar::class` |
| `Project::subPillars()` | **removed** — orphaned by this change, and it is our orphan |
| `StagingTimesheetCategoryImportSeeder` | imports sub-pillars per project; reduced to categories + projects. It references `ProjectSubPillar`, so leaving it would fail phpstan |
| `ProjectSeeder` | its `[code, name, [sub-pillars]]` demo data seeds the shared list instead |

### Timesheet picker

`TimesheetController::projectOptions()` (`:800-810`) embeds `sub_pillars` inside each
project, and `resources/js/timesheet-capture.js` reads `p.sub_pillars` (`:382`, `:393`).
**The server attaches the one shared list to every project in that payload**, so the JS
is not touched at all and the staff-facing drill stays category → project → sub-pillar.

One deliberate behaviour change: the projects that have no sub-pillars today (Amanahku,
InHouse Project X) are picker-terminal — choosing them books time immediately. With a
shared list they gain the sub-pillar step like everything else. `"The whole project"`
stays the first option on that step, so the common path (77% of entries skip the
sub-pillar) is still one tap.

## Code moves

Projects and categories now have **different edit roles**, so they can no longer share
one controller constant. That forces the split, and the split is the "one
standardisation" the screen is named for.

**New `app/Http/Controllers/ProjectController.php`**, holding projects and the shared
sub-pillars:

| Method | Origin |
|---|---|
| `storeProject` / `updateProject` / `deleteProject` | moved from `TimesheetAdminController`, bodies unchanged |
| `storeSubPillar` / `updateSubPillar` / `deleteSubPillar` | moved, minus the `Project $project` scoping |
| `validateProject` / `projectCategories` | moved, unchanged |
| `validateSubPillar` | unique rule moves from `where('project_id', …)` to `where('tenant_id', …)` |
| `screenData` | new: projects with counts, sub-pillars with counts, `projectCategories`, `canEdit` |
| `EDITOR_ROLES = ['manager', 'management', 'hr']` | replaces `PRIVILEGED_ROLES` here |

`screenData` loads:

```php
'projects' => Project::with('categories')
    ->withCount(['entries', 'workItems'])
    ->orderBy('sort')->orderBy('name')->get(),
'subPillars' => SubPillar::withCount('entries')
    ->orderBy('sort')->orderBy('name')->get(),
```

`Project::workItems(): HasMany` is added (`WorkItem::class, 'project_id'`) — the
inverse already exists at `app/Models/WorkItem.php:57`.

`storeSubPillar`'s AJAX response changes from `'count_sel' => null` to
`'count_sel' => '#ts-sub-count'`: the sub-pillar list is now top-level with its own
count badge, not a nested list inside a project card. `storeProject` keeps
`'#ts-proj-count'` unchanged.

**Routes** (`routes/web.php`), replacing the six `timesheet.admin.projects.*` /
`timesheet.admin.subpillars.*` routes and `project-quick-create.store`:

```
POST /app/projects                          projects.store
POST /app/projects/{project}                projects.update
POST /app/projects/{project}/delete         projects.delete
POST /app/sub-pillars                       sub-pillars.store
POST /app/sub-pillars/{subPillar}           sub-pillars.update
POST /app/sub-pillars/{subPillar}/delete    sub-pillars.delete
```

`route()` call sites to update: `partials/ts-project-row.blade.php`,
`partials/ts-subpillar-row.blade.php`, `partials/ts-subpillar-form.blade.php` (loses its
`$project` argument), and the AJAX render calls in the moved controller methods.
`screens/timesheet-setup.blade.php` loses its project block outright, so its calls go
with it.

**Deleted:** `ProjectQuickCreateController`, `screens/project-quick-create.blade.php`,
its route, the `project-quick-create` role gate at `AppController.php:193`, and
`app/Models/ProjectSubPillar.php`.

**`TimesheetAdminController`** keeps categories only: `screenData` drops the `projects`
and `projectCategories` keys; the six project/sub-pillar methods and their validators
leave with the move; the class docblock and `PRIVILEGED_ROLES` (management / HR) stay
as-is for categories.

## Wiring and copy

- `app/Support/Amanahku.php:98` — the *My Team* "New Project" nav entry becomes a
  *Workplace* "Projects" entry (`id => 'projects'`, label `Projects` / `Projek`, no
  `roles` key). Its comment about server-gating is rewritten.
- `app/Support/Amanahku.php:377` — a `projects` entry in `Amanahku::page()`: title
  "Projects" / "Projek", subtitle "Every project in Unijaya, and the sub-pillars they
  all share.", crumb `['Workplace', 'Projects']`. The `project-quick-create` key
  **stays in the array** — as shipped, it is a duplicate of the `projects` entry
  (identical title, subtitle and crumb), not its own old "New Project" copy. Either
  way the effect described below holds: `page()` falls back to the `soon` placeholder
  for unknown slugs (`:390`), so dropping the old key would give old bookmarks a blank
  header rather than a 404 — the same reason `claim-approvals` is retained at `:353`,
  though that key keeps its own distinct copy rather than being duplicated.
- `AppController.php:231` — `project-quick-create` aliases to the `projects` view, and
  `:411` aliases to the same `screenData`, following the `claim-approvals` precedent at
  `:229-231` / `:368`, so old deep links and bookmarks still land somewhere real.
- `app/Support/Amanahku.php:376` — Timesheet Setup subtitle drops "projects and
  sub-pillars".
- `screens/timesheet-setup.blade.php:4-26` — guide copy (en + ms) drops the project and
  sub-pillar steps and gains a pointer line: projects and sub-pillars live on the
  Projects screen. The `data-ajax` script include stays; category add still uses it.
- `SetupController.php:90` — wizard step description drops "projects and sub-pillars".
  Its auto-completion check counts categories only (`SetupController.php:124`), so the
  wizard keeps working untouched.

No feature-module gate applies: neither `timesheet-setup` nor `project-quick-create`
appears in `app/Support/Features.php`, so `projects` is core and always visible.

## Tests

New `tests/Feature/ProjectScreenTest.php`:

- employee GETs `/app/projects` → 200, and the response contains no add form and no
  edit/delete controls for either list
- employee POSTs `projects.store` and `sub-pillars.store` → 403 (the hole being closed)
- manager creates a project, updates it, deactivates a used project (row stays,
  `is_active` false)
- manager creates a sub-pillar, updates it, deactivates a used one
- a sub-pillar is selectable on **any** project — the old "does not belong to the chosen
  project" rejection is gone
- HR and management still pass every write
- usage counts render: a project with N timesheet entries and M board cards shows both

`tests/Feature/TimesheetSetupAjaxTest.php` — its project cases
(`test_project_ajax_add_returns_rendered_row`,
`test_project_categories_are_synced_on_store_and_update`,
`test_validation_error_returns_422_json_not_a_redirect`) move to the new file with the
new route names. `test_category_and_subpillar_ajax_add_return_rows` covers both a
category and a sub-pillar in one case, so it **splits**: the category half stays, the
sub-pillar half moves. The AJAX contract (`html` + `count_sel` JSON, 422 on validation
error) is unchanged apart from `storeSubPillar`'s new `count_sel`.

`tests/Feature/ProjectQuickCreateTest.php` is **deleted, absorbed into
ProjectScreenTest**. Its create, category-visibility and validation cases carry over
against the new route. Two of its cases invert by design and must be rewritten, not
dropped:

- `test_employee_cannot_reach_the_screen` (403) becomes: employee reaches it and sees
  both lists, with no write controls
- the nav-visibility pair (link shown to manager, hidden from employee) becomes: the
  Workplace link is shown to everyone, employee included

`tests/Feature/TimesheetTest.php`, `TimesheetReportLensTest.php` and the report tests
must pass with only their sub-pillar factory setup changed — proof that allocation and
reporting still work when a sub-pillar is not owned by a project.

**The backfill is not feature-tested.** `RefreshDatabase` runs migrations against an
empty database, so there is nothing for an assertion to catch. Its checks are the
in-migration assertion (step 6), a run against the dev database, and the staging
rehearsal — and staging is a true rehearsal, because the Management/Meeting/Technical
data originally came from there.

## Build and deploy

Blade-only UI change reusing existing classes and inline styles. If any new Tailwind
utility class appears, run `lerd artisan view:cache && bun run build` and commit
`public/build` alongside, per CLAUDE.md.

This release **carries a data migration**, so:

- run `vendor/bin/phpstan` locally before pushing to staging — CI only runs on PRs to
  main, so a push to staging is otherwise unanalysed
- take a `mysqldump` before the staging deploy, per `docs/RULES.md`
- the PR from `staging` to `main` must say in its description that this release migrates
  data, since devops runs the production release from GitLab and needs to know before
  pushing
