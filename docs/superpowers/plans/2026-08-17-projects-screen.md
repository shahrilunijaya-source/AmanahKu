# Projects Screen Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn the create-only "New Project" screen into a Workplace → Projects register that everyone can read and managers can edit, and make sub-pillars one shared tenant-wide list instead of a copy per project.

**Architecture:** A new `sub_pillars` table replaces `project_sub_pillars` (data migrated by name match, old table left in place as the rollback). Project and sub-pillar writes move out of `TimesheetAdminController` into a new `ProjectController` with a wider role gate, because projects now admit managers while categories stay management/HR. One new Blade screen renders both lists, reusing the existing row partials and the existing AJAX add script. The timesheet picker keeps its shape: the server attaches the shared sub-pillar list to every project in the payload, so `resources/js/timesheet-capture.js` is not touched.

**Tech Stack:** Laravel 13, PHP 8.5, Blade + Alpine 3, PHPUnit 12, Pint, Larastan. No new dependencies.

**Spec:** [docs/superpowers/specs/2026-08-17-projects-source-of-truth-design.md](../specs/2026-08-17-projects-source-of-truth-design.md)
**Mockup:** [docs/mockups/projects-screen.html](../../mockups/projects-screen.html)

## Global Constraints

- **Edit roles for projects and sub-pillars:** `['manager', 'management', 'hr']`. Categories keep `['management', 'hr']`. `director` passes automatically via `Controller::hasTenantRole` (`app/Http/Controllers/Controller.php:32-33`).
- **View:** any authenticated tenant user. No `roles` key on the nav item, no role gate in `AppController::screen` for `projects`.
- **Never hard-delete a record that is in use.** Deactivate (`is_active = false`) instead, exactly as `TimesheetAdminController::deleteProject` does today.
- **Every user-visible string is bilingual** (en + ms), following the `x-text="$store.ui.lang==='en' ? '…' : '…'"` pattern used across existing screens.
- **Design system:** `docs/DESIGN.md`. Warm canvas, 1px hairlines not shadows, one red (primary action / focus / active nav icon only), type ramp `11 / 12.5 / 14 / 16 / 22`, all changeable numbers in `var(--font-mono)` with tabular figures. Match the inline-style idiom of `resources/views/screens/timesheet-setup.blade.php` — do not invent Tailwind utility classes.
- **Migrations must be portable.** They run on MySQL locally and sqlite under `RefreshDatabase`. No `UPDATE ... JOIN`, no raw MySQL syntax. `dropForeign(['column'])` (array form) works on both; `dropForeign('name')` (string form) throws on sqlite.
- **No new dependencies**, no changes to `composer.json` or `package.json`.
- **Before every commit:** `vendor/bin/pint --dirty --format agent`.
- **Test command:** `lerd artisan test --compact --filter=<Name>` (the app runs on PHP 8.5 inside lerd; plain `php artisan test` works only if the host PHP matches).

---

## File Structure

**Created**
- `database/migrations/2026_08_17_000002_create_sub_pillars_and_migrate_data.php` — new table, backfill, remap, FK swap, self-check
- `app/Models/SubPillar.php` — tenant-scoped sub-pillar, no project
- `app/Http/Controllers/ProjectController.php` — screen data + all project and sub-pillar writes
- `resources/views/screens/projects.blade.php` — the register screen
- `resources/views/partials/ajax-row-add.blade.php` — the shared inline add script
- `tests/Feature/SubPillarTest.php` — shared-list behaviour
- `tests/Feature/ProjectScreenTest.php` — screen visibility + write gates + usage counts

**Modified**
- `app/Models/Project.php` — drop `subPillars()`, add `workItems()`
- `app/Models/TimesheetEntry.php`, `app/Models/TimesheetTemplate.php` — `subPillar()` points at `SubPillar`
- `app/Http/Controllers/TimesheetController.php` — validation table names, drop the project-ownership rule, attach the shared list in `projectOptions()`
- `app/Http/Controllers/TimesheetAdminController.php` — categories only
- `app/Http/Controllers/AppController.php` — screen wiring, alias, gate removal
- `app/Support/Amanahku.php` — nav entry, page meta
- `app/Http/Controllers/SetupController.php` — wizard step copy
- `routes/web.php` — new routes, old ones removed
- `resources/views/partials/ts-project-row.blade.php`, `ts-subpillar-row.blade.php` — `$canEdit`, new routes, no nesting
- `resources/views/screens/timesheet-setup.blade.php` — categories only, shared script include
- `database/seeders/ProjectSeeder.php`, `StagingTimesheetCategoryImportSeeder.php`
- `tests/Feature/TimesheetSetupAjaxTest.php` — categories only

**Deleted**
- `app/Models/ProjectSubPillar.php`
- `app/Http/Controllers/ProjectQuickCreateController.php`
- `resources/views/screens/project-quick-create.blade.php`
- `tests/Feature/ProjectQuickCreateTest.php`

---

## Task 1: The `sub_pillars` table and its data migration

**Files:**
- Create: `database/migrations/2026_08_17_000002_create_sub_pillars_and_migrate_data.php`
- Create: `app/Models/SubPillar.php`
- Create: `tests/Feature/SubPillarTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: table `sub_pillars` (`id`, `tenant_id`, `name`, `is_active`, `sort`, `created_at`, `updated_at`, unique on `tenant_id + name`); `App\Models\SubPillar` with `entries(): HasMany`. `timesheet_entries.sub_pillar_id` and `timesheet_templates.sub_pillar_id` point at `sub_pillars` after this task.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/SubPillarTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Project;
use App\Models\SubPillar;
use App\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A sub-pillar is a kind of work (Management / Meeting / Technical), shared by
 * every project in the tenant — not a part of one project. Unijaya's 24 project
 * records all carried the identical three before this change.
 */
class SubPillarTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
    }

    public function test_a_sub_pillar_belongs_to_a_tenant_and_not_to_a_project(): void
    {
        $sub = SubPillar::create(['tenant_id' => $this->tenant->id, 'name' => 'Technical']);

        $this->assertTrue($sub->is_active);
        $this->assertSame(0, $sub->sort);
        $this->assertFalse(array_key_exists('project_id', $sub->getAttributes()));
    }

    public function test_the_same_name_cannot_be_added_twice_in_one_tenant(): void
    {
        SubPillar::create(['tenant_id' => $this->tenant->id, 'name' => 'Meeting']);

        $this->expectException(QueryException::class);
        SubPillar::create(['tenant_id' => $this->tenant->id, 'name' => 'Meeting']);
    }

    public function test_two_projects_need_only_one_copy_of_a_sub_pillar(): void
    {
        Project::create(['tenant_id' => $this->tenant->id, 'name' => 'JKDM: MyStods']);
        Project::create(['tenant_id' => $this->tenant->id, 'name' => 'KKM: NSFIRM']);
        SubPillar::create(['tenant_id' => $this->tenant->id, 'name' => 'Management']);

        // The old shape stored one row per project. One row now serves both.
        $this->assertSame(2, Project::where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(1, SubPillar::where('tenant_id', $this->tenant->id)->count());
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `lerd artisan test --compact --filter=SubPillarTest`
Expected: FAIL — `Class "App\Models\SubPillar" not found`.

- [ ] **Step 3: Write the model**

Create `app/Models/SubPillar.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A kind of work staff can book time against — Management, Meeting, Technical.
 * Tenant-wide and shared by every project: replaced the per-project
 * ProjectSubPillar, which stored the same three names once per project.
 */
class SubPillar extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort' => 'integer',
        ];
    }

    public function entries(): HasMany
    {
        return $this->hasMany(TimesheetEntry::class, 'sub_pillar_id');
    }
}
```

- [ ] **Step 4: Write the migration**

Create `database/migrations/2026_08_17_000002_create_sub_pillars_and_migrate_data.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sub-pillars stop belonging to a project. Every one of Unijaya's 24 project
     * records carried the identical three (Management / Meeting / Technical), so
     * the per-project shape was 24 copies of one company-wide list.
     *
     * Existing timesheet history is repointed by NAME, never by id: old ids run
     * 1..~72 and new ids run 1..~6, so they overlap and an id-based remap would
     * silently rewrite history. `project_sub_pillars` is left in place, unused —
     * it is the rollback, and a later migration drops it once staging and
     * production have both run clean.
     */
    public function up(): void
    {
        // 1. Release the foreign keys that pin these columns to the old table.
        //    Array form (not the string name) — the string form throws on sqlite.
        Schema::table('timesheet_entries', function (Blueprint $table) {
            $table->dropForeign(['sub_pillar_id']);
        });
        Schema::table('timesheet_templates', function (Blueprint $table) {
            $table->dropForeign(['sub_pillar_id']);
        });

        // 2. The new home.
        Schema::create('sub_pillars', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
        });

        // 3. Backfill: one row per distinct name per tenant. Active if ANY source
        //    row was active; earliest sort wins. Plain Eloquent/query-builder so
        //    the migration runs on sqlite too — 72 rows, speed is irrelevant.
        $newIdByKey = [];   // "tenantId|lowered name" => new id
        $newIdByOldId = []; // old project_sub_pillars.id => new sub_pillars.id
        $oldNameByOldId = [];

        foreach (DB::table('project_sub_pillars')->orderBy('id')->get() as $old) {
            $name = trim($old->name);
            $key = $old->tenant_id.'|'.mb_strtolower($name);
            $oldNameByOldId[$old->id] = $name;

            if (! isset($newIdByKey[$key])) {
                $newIdByKey[$key] = DB::table('sub_pillars')->insertGetId([
                    'tenant_id' => $old->tenant_id,
                    'name' => $name,
                    'is_active' => (bool) $old->is_active,
                    'sort' => $old->sort,
                    'created_at' => $old->created_at,
                    'updated_at' => $old->updated_at,
                ]);
            } elseif ($old->is_active) {
                DB::table('sub_pillars')->where('id', $newIdByKey[$key])->update(['is_active' => true]);
            }

            $newIdByOldId[$old->id] = $newIdByKey[$key];
        }

        // 4. Repoint history. Read every old value BEFORE writing any new one and
        //    update row by primary key, so a row already carrying new id 3 is never
        //    caught again when old id 3 comes round.
        foreach (['timesheet_entries', 'timesheet_templates'] as $table) {
            $rows = DB::table($table)->whereNotNull('sub_pillar_id')->get(['id', 'sub_pillar_id']);

            foreach ($rows as $row) {
                if (! isset($newIdByOldId[$row->sub_pillar_id])) {
                    throw new RuntimeException(
                        "{$table}.id={$row->id} points at sub_pillar_id={$row->sub_pillar_id}, which has no row in project_sub_pillars. Aborting before any data is lost."
                    );
                }

                DB::table($table)->where('id', $row->id)
                    ->update(['sub_pillar_id' => $newIdByOldId[$row->sub_pillar_id]]);
            }

            // 5. Self-check: every repointed row still names the same sub-pillar.
            foreach ($rows as $row) {
                $newName = DB::table($table)
                    ->join('sub_pillars', 'sub_pillars.id', '=', $table.'.sub_pillar_id')
                    ->where($table.'.id', $row->id)
                    ->value('sub_pillars.name');

                if ($newName !== $oldNameByOldId[$row->sub_pillar_id]) {
                    throw new RuntimeException(
                        "{$table}.id={$row->id} was '{$oldNameByOldId[$row->sub_pillar_id]}' and is now '".($newName ?? 'NULL')."'. Aborting."
                    );
                }
            }
        }

        // 6. Pin the columns to the new table.
        Schema::table('timesheet_entries', function (Blueprint $table) {
            $table->foreign('sub_pillar_id')->references('id')->on('sub_pillars')->nullOnDelete();
        });
        Schema::table('timesheet_templates', function (Blueprint $table) {
            $table->foreign('sub_pillar_id')->references('id')->on('sub_pillars')->nullOnDelete();
        });
    }

    /**
     * Reverse by name — both tables still exist, so every new row can find the
     * old row it came from within the same tenant.
     */
    public function down(): void
    {
        Schema::table('timesheet_entries', function (Blueprint $table) {
            $table->dropForeign(['sub_pillar_id']);
        });
        Schema::table('timesheet_templates', function (Blueprint $table) {
            $table->dropForeign(['sub_pillar_id']);
        });

        $oldIdByKey = [];
        foreach (DB::table('project_sub_pillars')->orderBy('id')->get() as $old) {
            $oldIdByKey[$old->tenant_id.'|'.mb_strtolower(trim($old->name))] ??= $old->id;
        }

        foreach (['timesheet_entries', 'timesheet_templates'] as $table) {
            $rows = DB::table($table)->whereNotNull('sub_pillar_id')->get(['id', 'sub_pillar_id']);

            foreach ($rows as $row) {
                $new = DB::table('sub_pillars')->where('id', $row->sub_pillar_id)->first();
                $oldId = $new ? ($oldIdByKey[$new->tenant_id.'|'.mb_strtolower(trim($new->name))] ?? null) : null;

                DB::table($table)->where('id', $row->id)->update(['sub_pillar_id' => $oldId]);
            }
        }

        Schema::dropIfExists('sub_pillars');

        Schema::table('timesheet_entries', function (Blueprint $table) {
            $table->foreign('sub_pillar_id')->references('id')->on('project_sub_pillars')->nullOnDelete();
        });
        Schema::table('timesheet_templates', function (Blueprint $table) {
            $table->foreign('sub_pillar_id')->references('id')->on('project_sub_pillars')->nullOnDelete();
        });
    }
};
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `lerd artisan test --compact --filter=SubPillarTest`
Expected: PASS, 3 tests.

- [ ] **Step 6: Run the migration against the dev database and check the data**

The backfill cannot be feature-tested — `RefreshDatabase` migrates an empty database, so there is nothing to convert. The dev database holds the real shape, so run it there:

```bash
lerd artisan migrate
```

Then confirm the result — expect 3 rows per tenant, and every timesheet entry still naming the same sub-pillar:

```bash
lerd artisan tinker --execute 'echo App\Models\SubPillar::count()." sub-pillars\n"; echo App\Models\TimesheetEntry::whereNotNull("sub_pillar_id")->count()." entries with a sub-pillar\n"; App\Models\SubPillar::get()->each(fn ($s) => print($s->tenant_id." ".$s->name."\n"));'
```

Expected: 6 rows (3 per tenant, both tenants), names `Management`, `Meeting`, `Technical`, and the entry count unchanged from before the migration (39).

- [ ] **Step 7: Run Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/2026_08_17_000002_create_sub_pillars_and_migrate_data.php app/Models/SubPillar.php tests/Feature/SubPillarTest.php
git commit -m "feat(projects): one shared sub-pillar list instead of a copy per project

Every project carried the same three (Management/Meeting/Technical), so the
per-project table stored 24 copies of one company-wide list. Adds sub_pillars,
repoints timesheet history by name (never by id, the ranges overlap), and
leaves project_sub_pillars in place as the way back."
```

---

## Task 2: Point the timesheet at the shared list

**Files:**
- Modify: `app/Models/Project.php`
- Modify: `app/Models/TimesheetEntry.php:55`, `app/Models/TimesheetTemplate.php:40-43`
- Modify: `app/Http/Controllers/TimesheetController.php:213`, `:338`, `:800-810`, `:847`, `:869-874`
- Modify: `database/seeders/ProjectSeeder.php`, `database/seeders/StagingTimesheetCategoryImportSeeder.php`
- Delete: `app/Models/ProjectSubPillar.php`
- Test: `tests/Feature/SubPillarTest.php`

**Interfaces:**
- Consumes: `App\Models\SubPillar` from Task 1.
- Produces: `Project::workItems(): HasMany` (used by Task 3's usage counts). `Project::subPillars()` no longer exists. `projectOptions()` still emits a `sub_pillars` key per project, so `resources/js/timesheet-capture.js` keeps working untouched.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/SubPillarTest.php` (add the imports `App\Models\Employee`, `App\Models\TimesheetCategory`, `App\Models\Timesheet`, `App\Models\User`, `Illuminate\Support\Carbon`, `Illuminate\Support\Facades\Hash` at the top):

```php
    public function test_any_sub_pillar_can_be_booked_against_any_project(): void
    {
        Carbon::setTestNow('2026-06-19 12:00:00');

        $user = User::create(['name' => 'Demo', 'email' => 'demo@example.com', 'password' => Hash::make('password')]);
        $user->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $employee = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'name' => 'Demo', 'status' => 'active', 'workload' => 'green',
        ]);

        $category = TimesheetCategory::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Development', 'requires_project' => true,
        ]);
        $project = Project::create(['tenant_id' => $this->tenant->id, 'name' => 'JKDM: MyStods']);
        // A sub-pillar created with no reference to that project at all.
        $sub = SubPillar::create(['tenant_id' => $this->tenant->id, 'name' => 'Technical']);

        $this->actingAs($user)->withSession(['current_tenant' => $this->tenant->id])
            ->post('/app/timesheets', [
                'week_start' => '2026-06-15',
                'entries' => [[
                    'entry_date' => '2026-06-15',
                    'category_id' => $category->id,
                    'project_id' => $project->id,
                    'sub_pillar_id' => $sub->id,
                    'percentage' => 100,
                ]],
            ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('timesheet_entries', [
            'project_id' => $project->id,
            'sub_pillar_id' => $sub->id,
        ]);

        $this->assertSame(1, Timesheet::where('employee_id', $employee->id)->count());

        Carbon::setTestNow();
    }
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `lerd artisan test --compact --filter=test_any_sub_pillar_can_be_booked_against_any_project`
Expected: FAIL — validation error on `entries.0.sub_pillar_id` ("That sub-pillar does not belong to the chosen project."), because `TimesheetController` still checks `project_sub_pillars` and matches on `project_id`.

- [ ] **Step 3: Repoint the models**

In `app/Models/TimesheetEntry.php`, change the `subPillar()` relation body to:

```php
        return $this->belongsTo(SubPillar::class, 'sub_pillar_id');
```

In `app/Models/TimesheetTemplate.php`, same change:

```php
    public function subPillar(): BelongsTo
    {
        return $this->belongsTo(SubPillar::class, 'sub_pillar_id');
    }
```

In `app/Models/Project.php`, **delete** the `subPillars()` method and its docblock, and add:

```php
    /** Board cards that name this project. Counted on the Projects register. */
    public function workItems(): HasMany
    {
        return $this->hasMany(WorkItem::class, 'project_id');
    }
```

Keep the `HasMany` import; the `ProjectSubPillar` import goes.

Delete the file `app/Models/ProjectSubPillar.php`.

- [ ] **Step 4: Repoint the controller**

In `app/Http/Controllers/TimesheetController.php`:

Line 213 and line 338 — swap the table name in both rules:

```php
            'entries.*.sub_pillar_id' => ['nullable', 'integer', Rule::exists('sub_pillars', 'id')->where('tenant_id', $tid)],
```

```php
            'sub_pillar_id' => ['nullable', 'integer', Rule::exists('sub_pillars', 'id')->where('tenant_id', $tid)],
```

Line 847 — swap the class:

```php
        $subPillars = SubPillar::whereIn('id', collect($raw)->pluck('sub_pillar_id')->filter()->unique())->get()->keyBy('id');
```

Lines 869-874 — **delete** the whole ownership block:

```php
            if ($subId) {
                $sub = $subPillars->get($subId);
                if (! $sub || (int) $sub->project_id !== (int) $projectId) {
                    throw ValidationException::withMessages(["entries.$i.sub_pillar_id" => 'That sub-pillar does not belong to the chosen project.']);
                }
            }
```

The tenant-scoped `exists` rule in `validate()` already proves the id is real and belongs to this tenant; there is no project to belong to any more. `$subPillars` is now only used by that deleted block, so delete its lookup at line 847 too and drop the `SubPillar` import if nothing else uses it.

Lines 800-810 — `projectOptions()` attaches the one shared list to every project, which is what keeps `timesheet-capture.js` unchanged:

```php
    /**
     * Active projects as plain arrays for the grid. `category_ids` drives the
     * picker's filter: a project with no categories at all is uncategorized and
     * stays selectable under every category, so existing projects don't disappear.
     *
     * Every project carries the same `sub_pillars` — one tenant-wide list since
     * sub-pillars stopped belonging to a project. The key stays per-project so the
     * capture JS (which reads `p.sub_pillars`) needs no change.
     */
    private function projectOptions(): Collection
    {
        $subPillars = SubPillar::where('is_active', true)->orderBy('sort')->orderBy('name')
            ->get()->map(fn (SubPillar $s) => ['id' => $s->id, 'name' => $s->name])->values();

        return Project::with('categories')
            ->where('is_active', true)->orderBy('sort')->orderBy('name')->get()
            ->map(fn (Project $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'category_ids' => $p->categories->pluck('id')->values(),
                'sub_pillars' => $subPillars,
            ])->values();
    }
```

Replace the `use App\Models\ProjectSubPillar;` import with `use App\Models\SubPillar;`.

- [ ] **Step 5: Fix the seeders**

In `database/seeders/ProjectSeeder.php`, replace the whole `run()` body's project loop so the demo data seeds one shared list. Change the `$projects` array to `[code, name]` pairs and add the sub-pillars once:

```php
        // [code, name]
        $projects = [
            ['KPT', 'KPT: RMS'],
            ['MITI', 'MITI: eABDC'],
            ['KDN', 'KDN: iLPF'],
            ['INT', 'Internal'],
        ];

        foreach ($projects as $i => [$code, $name]) {
            Project::create([
                'tenant_id' => $tid,
                'code' => $code,
                'name' => $name,
                'is_active' => true,
                'sort' => $i,
            ]);
        }

        // One shared list, used by every project — the shape Unijaya actually uses.
        foreach (['Management', 'Meeting', 'Technical'] as $j => $name) {
            SubPillar::firstOrCreate(
                ['tenant_id' => $tid, 'name' => $name],
                ['is_active' => true, 'sort' => $j],
            );
        }
```

Swap the import `use App\Models\SubPillar;` in place of nothing (the file imports `Project` and `Tenant` today).

In `database/seeders/StagingTimesheetCategoryImportSeeder.php`, the import is a historical one-off that copied staging's per-project sub-pillars. Change `$stagingProjects` from a name => sub-pillars map to a plain list of names, and delete the sub-pillar half of `importProjects()`:

```php
        // Project names — from staging tenant 1, 2026-08-05. Sub-pillars are no
        // longer per project (see the sub_pillars migration), so this import only
        // creates the projects; the shared list is seeded by ProjectSeeder.
        $stagingProjects = [
            'JKDM: MyStods', 'JKDM: MyDLV', 'KKM: NSFIRM', 'SPA: IRIS', 'MOTAC: TTMS',
            'KUSKOP: EPMS', 'KDN: iLPF', 'KKDW: Pendigitalan', 'DOA: MyLRMP',
            'JBG: iGuaman', 'DOSM: HIES/BA', 'JSM: eACC', 'InHouse Project X', 'Amanahku',
        ];
```

```php
    /**
     * @param  array<int, string>  $stagingProjects
     */
    private function importProjects(int $tenantId, array $stagingProjects): void
    {
        $nextProjectSort = (int) Project::where('tenant_id', $tenantId)->max('sort');

        foreach ($stagingProjects as $projectName) {
            $exists = Project::where('tenant_id', $tenantId)
                ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower(trim($projectName))])
                ->exists();

            if ($exists) {
                continue;
            }

            $nextProjectSort++;

            Project::create([
                'tenant_id' => $tenantId,
                'name' => $projectName,
                'sort' => $nextProjectSort,
                'is_active' => true,
            ]);
        }
    }
```

Delete the now-unused `use App\Models\ProjectSubPillar;` import — leaving it would fail Larastan, and pushes to staging are not analysed by CI.

- [ ] **Step 6: Run the tests to verify they pass**

Run: `lerd artisan test --compact --filter=SubPillarTest`
Expected: PASS, 4 tests.

Run: `lerd artisan test --compact tests/Feature/TimesheetTest.php`
Expected: PASS, unchanged. This is the proof that allocation still works.

- [ ] **Step 7: Confirm nothing still references the deleted model**

```bash
grep -rn "ProjectSubPillar\|subPillars()" --include="*.php" app/ database/ tests/
```

Expected: no output.

- [ ] **Step 8: Run Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/ database/seeders tests/Feature/SubPillarTest.php
git commit -m "feat(timesheet): book any sub-pillar against any project

Drops the 'that sub-pillar does not belong to the chosen project' rule, which
no longer means anything now the list is shared. The picker payload still
carries sub_pillars per project, so timesheet-capture.js is untouched."
```

---

## Task 3: Move the write endpoints into ProjectController

**Files:**
- Create: `app/Http/Controllers/ProjectController.php`
- Create: `tests/Feature/ProjectScreenTest.php`
- Modify: `routes/web.php:392-404`
- Modify: `app/Http/Controllers/TimesheetAdminController.php`
- Modify: `resources/views/partials/ts-project-row.blade.php`, `resources/views/partials/ts-subpillar-row.blade.php`
- Modify: `resources/views/screens/timesheet-setup.blade.php:57-82`
- Modify: `tests/Feature/TimesheetSetupAjaxTest.php`

**Interfaces:**
- Consumes: `SubPillar`, `Project::workItems()` from Tasks 1-2.
- Produces: route names `projects.store`, `projects.update`, `projects.delete`, `sub-pillars.store`, `sub-pillars.update`, `sub-pillars.delete`. `ProjectController::screenData(Request): array` returning keys `projects`, `subPillars`, `projectCategories`, `canEdit`. Partials `ts-project-row` and `ts-subpillar-row` accept `$canEdit` (bool, defaults true).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/ProjectScreenTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Project;
use App\Models\SubPillar;
use App\Models\Tenant;
use App\Models\TimesheetCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The Projects register: everyone reads it, manager / management / HR write to
 * it. Projects used to be created on one screen (manager-facing, ungated write)
 * and edited on another (management/HR only, buried in Timesheet Setup).
 */
class ProjectScreenTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
    }

    /** Idempotent: a test may act as the same role more than once in one case. */
    private function actorWithRole(string $role): User
    {
        if ($existing = User::where('email', $role.'@example.com')->first()) {
            return $existing;
        }

        $user = User::create([
            'name' => ucfirst($role),
            'email' => $role.'@example.com',
            'password' => Hash::make('password'),
        ]);
        $user->tenants()->attach($this->tenant->id, ['role' => $role]);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'name' => ucfirst($role), 'status' => 'active', 'workload' => 'green',
        ]);

        return $user;
    }

    private function actingAsRole(string $role): self
    {
        $this->actingAs($this->actorWithRole($role))->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    public function test_a_manager_can_create_a_project(): void
    {
        $this->actingAsRole('manager')
            ->post(route('projects.store'), ['name' => 'KPT: RMS', 'code' => 'KPT'])
            ->assertRedirect();

        $this->assertDatabaseHas('projects', [
            'tenant_id' => $this->tenant->id, 'name' => 'KPT: RMS', 'is_active' => true,
        ]);
    }

    public function test_an_employee_cannot_create_a_project(): void
    {
        $this->actingAsRole('employee')
            ->post(route('projects.store'), ['name' => 'KPT: RMS'])
            ->assertForbidden();

        $this->assertDatabaseMissing('projects', ['name' => 'KPT: RMS']);
    }

    public function test_an_employee_cannot_create_a_sub_pillar(): void
    {
        $this->actingAsRole('employee')
            ->post(route('sub-pillars.store'), ['name' => 'Technical'])
            ->assertForbidden();

        $this->assertDatabaseMissing('sub_pillars', ['name' => 'Technical']);
    }

    public function test_a_manager_can_add_update_and_deactivate_a_sub_pillar(): void
    {
        $this->actingAsRole('manager')
            ->post(route('sub-pillars.store'), ['name' => 'Technical'])
            ->assertRedirect();

        $sub = SubPillar::where('name', 'Technical')->firstOrFail();

        $this->actingAsRole('manager')
            ->post(route('sub-pillars.update', $sub), ['name' => 'Technical work', 'is_active' => 1])
            ->assertRedirect();

        $this->assertSame('Technical work', $sub->fresh()->name);

        $this->actingAsRole('manager')
            ->post(route('sub-pillars.delete', $sub))
            ->assertRedirect();

        $this->assertDatabaseMissing('sub_pillars', ['id' => $sub->id]);
    }

    public function test_a_project_in_use_is_deactivated_rather_than_deleted(): void
    {
        $project = Project::create(['tenant_id' => $this->tenant->id, 'name' => 'KPT: RMS']);
        $category = TimesheetCategory::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Development', 'requires_project' => true,
        ]);
        $booker = $this->actorWithRole('employee');
        $employee = Employee::where('user_id', $booker->id)->firstOrFail();
        $timesheet = \App\Models\Timesheet::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $employee->id,
            'week_start' => '2026-06-15', 'status' => 'draft', 'total_hours' => 8,
        ]);
        \App\Models\TimesheetEntry::create([
            'tenant_id' => $this->tenant->id, 'timesheet_id' => $timesheet->id,
            'entry_date' => '2026-06-15', 'category_id' => $category->id,
            'project_id' => $project->id, 'percentage' => 100,
        ]);

        $this->actingAsRole('hr')->post(route('projects.delete', $project))->assertRedirect();

        $this->assertDatabaseHas('projects', ['id' => $project->id, 'is_active' => false]);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `lerd artisan test --compact --filter=ProjectScreenTest`
Expected: FAIL — `Route [projects.store] not defined`.

- [ ] **Step 3: Write the controller**

Create `app/Http/Controllers/ProjectController.php`. The project and sub-pillar bodies are the ones from `TimesheetAdminController`, with the sub-pillar half no longer scoped to a project:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Project;
use App\Models\SubPillar;
use App\Models\TimesheetCategory;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * The Projects register: every project in the tenant plus the shared sub-pillar
 * list they all draw on. Readable by anyone signed in; written by manager,
 * management and HR (director folds into management).
 *
 * Split out of TimesheetAdminController because the edit roles diverged —
 * categories stay management/HR, projects admit managers. Records in use are
 * deactivated, never hard-deleted, so reports keep their labels.
 */
class ProjectController extends Controller
{
    private const EDITOR_ROLES = ['manager', 'management', 'hr'];

    /** Data for the Projects screen. */
    public function screenData(Request $request): array
    {
        return [
            'projects' => Project::with('categories')
                ->withCount(['entries', 'workItems'])
                ->orderBy('sort')->orderBy('name')->get(),
            'subPillars' => SubPillar::withCount('entries')
                ->orderBy('sort')->orderBy('name')->get(),
            'projectCategories' => $this->projectCategories(),
            'canEdit' => $this->hasTenantRole($request, self::EDITOR_ROLES),
        ];
    }

    // ---- Projects ---------------------------------------------------------

    public function storeProject(Request $request): JsonResponse|RedirectResponse
    {
        $this->authorizeEditor($request);
        $data = $this->validateProject($request);
        $categories = $data['categories'] ?? [];
        unset($data['categories']);

        $project = Project::create($data);
        $project->categories()->sync($categories);
        AuditLog::record('Added project', $project->name);

        if ($request->wantsJson()) {
            $project->load('categories')->loadCount(['entries', 'workItems']);

            return response()->json([
                'html' => view('partials.ts-project-row', [
                    'project' => $project,
                    'categories' => $this->projectCategories(),
                    'canEdit' => true,
                ])->render(),
                'count_sel' => '#ts-proj-count',
            ]);
        }

        return back()->with('ok', $project->name.' added.');
    }

    public function updateProject(Request $request, Project $project): RedirectResponse
    {
        $this->authorizeEditor($request);
        $this->assertTenant($project->tenant_id);

        $data = $this->validateProject($request, $project->id);
        $categories = $data['categories'] ?? [];
        unset($data['categories']);

        $project->update($data);
        $project->categories()->sync($categories);
        AuditLog::record('Updated project', $project->name);

        return back()->with('ok', $project->name.' updated.');
    }

    public function deleteProject(Request $request, Project $project): RedirectResponse
    {
        $this->authorizeEditor($request);
        $this->assertTenant($project->tenant_id);

        if ($project->entries()->exists()) {
            $project->update(['is_active' => false]);

            return back()->with('ok', $project->name.' is in use — deactivated instead of deleted.');
        }

        $name = $project->name;
        $project->delete();
        AuditLog::record('Removed project', $name);

        return back()->with('ok', $name.' removed.');
    }

    // ---- Sub-pillars ------------------------------------------------------

    public function storeSubPillar(Request $request): JsonResponse|RedirectResponse
    {
        $this->authorizeEditor($request);

        $sub = SubPillar::create($this->validateSubPillar($request));
        AuditLog::record('Added sub-pillar', $sub->name);

        if ($request->wantsJson()) {
            $sub->loadCount('entries');

            return response()->json([
                'html' => view('partials.ts-subpillar-row', ['sp' => $sub, 'canEdit' => true])->render(),
                'count_sel' => '#ts-sub-count',
            ]);
        }

        return back()->with('ok', $sub->name.' added.');
    }

    public function updateSubPillar(Request $request, SubPillar $subPillar): RedirectResponse
    {
        $this->authorizeEditor($request);
        $this->assertTenant($subPillar->tenant_id);

        $subPillar->update($this->validateSubPillar($request, $subPillar->id));
        AuditLog::record('Updated sub-pillar', $subPillar->name);

        return back()->with('ok', $subPillar->name.' updated.');
    }

    public function deleteSubPillar(Request $request, SubPillar $subPillar): RedirectResponse
    {
        $this->authorizeEditor($request);
        $this->assertTenant($subPillar->tenant_id);

        if ($subPillar->entries()->exists()) {
            $subPillar->update(['is_active' => false]);

            return back()->with('ok', $subPillar->name.' is in use — deactivated instead of deleted.');
        }

        $name = $subPillar->name;
        $subPillar->delete();
        AuditLog::record('Removed sub-pillar', $name);

        return back()->with('ok', $name.' removed.');
    }

    // ---- Validation -------------------------------------------------------

    /** @return array<string,mixed> */
    private function validateProject(Request $request, ?int $ignoreId = null): array
    {
        $tid = app(CurrentTenant::class)->id();

        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:160', Rule::unique('projects', 'name')->where('tenant_id', $tid)->ignore($ignoreId)],
            'sort' => ['nullable', 'integer', 'between:0,9999'],
            'is_active' => ['nullable', 'boolean'],
            'categories' => ['nullable', 'array'],
            'categories.*' => [Rule::exists('timesheet_categories', 'id')->where('tenant_id', $tid)],
        ]);

        $data['is_active'] = $request->boolean('is_active', true);

        return $data;
    }

    /** @return array<string,mixed> */
    private function validateSubPillar(Request $request, ?int $ignoreId = null): array
    {
        $tid = app(CurrentTenant::class)->id();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:160', Rule::unique('sub_pillars', 'name')->where('tenant_id', $tid)->ignore($ignoreId)],
            'sort' => ['nullable', 'integer', 'between:0,9999'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $data['is_active'] = $request->boolean('is_active', true);

        return $data;
    }

    /**
     * The project-linkable categories, for the project form's category chips. Not
     * filtered to active-only — a project already tied to a deactivated category
     * must keep showing that chip, or re-syncing the form would silently drop it.
     */
    private function projectCategories(): Collection
    {
        return TimesheetCategory::projectLinkable()->orderBy('sort')->orderBy('name')->get();
    }

    private function authorizeEditor(Request $request): void
    {
        $this->authorizeTenantRole($request, self::EDITOR_ROLES);
    }

    private function assertTenant(int $tenantId): void
    {
        abort_unless($tenantId === app(CurrentTenant::class)->id(), 403);
    }
}
```

- [ ] **Step 4: Add the routes**

In `routes/web.php`, add the import beside the other controller imports:

```php
use App\Http\Controllers\ProjectController;
```

Replace the six `timesheet.admin.projects.*` / `timesheet.admin.subpillars.*` route lines (currently `:396-401`) with:

```php
        // Projects register (Workplace → Projects) — manager / management / HR write,
        // everyone reads. Sub-pillars are tenant-wide, not nested under a project.
        Route::post('/app/projects', [ProjectController::class, 'storeProject'])->name('projects.store');
        Route::post('/app/projects/{project}', [ProjectController::class, 'updateProject'])->name('projects.update');
        Route::post('/app/projects/{project}/delete', [ProjectController::class, 'deleteProject'])->name('projects.delete');
        Route::post('/app/sub-pillars', [ProjectController::class, 'storeSubPillar'])->name('sub-pillars.store');
        Route::post('/app/sub-pillars/{subPillar}', [ProjectController::class, 'updateSubPillar'])->name('sub-pillars.update');
        Route::post('/app/sub-pillars/{subPillar}/delete', [ProjectController::class, 'deleteSubPillar'])->name('sub-pillars.delete');
```

Update the comment on the line above the category routes so it reads "Timesheet categories — privileged (management / HR)".

- [ ] **Step 5: Trim TimesheetAdminController to categories**

Delete from `app/Http/Controllers/TimesheetAdminController.php`: `storeProject`, `updateProject`, `deleteProject`, `storeSubPillar`, `updateSubPillar`, `deleteSubPillar`, `validateProject`, `validateSubPillar`, `projectCategories`, and the now-unused imports (`Project`, `ProjectSubPillar`, `Collection`).

Change `screenData` to:

```php
    /** Data for the Timesheet Setup screen — categories only; projects live on their own screen. */
    public function screenData(Request $request): array
    {
        return [
            'categories' => TimesheetCategory::orderBy('sort')->orderBy('name')->get(),
        ];
    }
```

Update the class docblock:

```php
/**
 * HR setup for the timesheet categories staff pick from when allocating their
 * week. Privileged (management / HR) only. Projects and sub-pillars moved to
 * ProjectController — their edit roles now include managers.
 *
 * Records in use are never hard-deleted (that would null historical entries via
 * the nullOnDelete FKs and erase report history) — they are deactivated instead.
 */
```

- [ ] **Step 6: Rewrite the two row partials**

Replace `resources/views/partials/ts-project-row.blade.php` entirely:

```blade
{{-- One project row on the Projects register. Shared by the initial render and the
     AJAX append on add. Expects $project (with categories loaded and entries_count /
     work_items_count), $categories (full list, for the edit form) and $canEdit. --}}
@php
    $canEdit = $canEdit ?? true;
    $hay = mb_strtolower(trim($project->name.' '.$project->code));
@endphp
<div class="uj-card" style="padding:15px 18px;margin-bottom:10px;{{ $project->is_active ? '' : 'background:var(--canvas);' }}"
     x-data="{ edit: false }"
     x-show="(showOff || @js($project->is_active)) && @js($hay).includes(q.toLowerCase())">
    <div style="display:flex;gap:13px;align-items:center;">
        @if ($project->code)
            <span style="width:36px;height:36px;border-radius:9px;background:var(--canvas);border:1px solid var(--hairline);color:var(--muted);font-size:11px;font-weight:600;font-family:var(--font-mono);display:flex;align-items:center;justify-content:center;flex-shrink:0;">{{ $project->code }}</span>
        @endif
        <div style="flex:1;min-width:0;">
            <div style="font-size:14px;color:{{ $project->is_active ? 'var(--ink)' : 'var(--muted)' }};font-weight:500;">{{ $project->name }}</div>
            <div style="display:flex;flex-wrap:wrap;align-items:center;gap:7px;margin-top:5px;">
                @unless ($project->is_active)
                    <span class="uj-stamp"><span x-text="$store.ui.lang==='en' ? 'Inactive' : 'Tidak aktif'">Inactive</span></span>
                @endunless
                @foreach ($project->categories as $cat)
                    <span class="uj-pill">{{ $cat->name }}</span>
                @endforeach
                <span style="font-size:11.5px;font-weight:500;color:var(--muted-soft);font-family:var(--font-mono);font-variant-numeric:tabular-nums;">
                    @if ($project->entries_count || $project->work_items_count)
                        <span style="font-weight:600;color:var(--muted);">{{ $project->entries_count }}</span> <span x-text="$store.ui.lang==='en' ? 'timesheet lines' : 'baris lembaran masa'">timesheet lines</span>
                        · <span style="font-weight:600;color:var(--muted);">{{ $project->work_items_count }}</span> <span x-text="$store.ui.lang==='en' ? 'board cards' : 'kad papan'">board cards</span>
                    @else
                        <span x-text="$store.ui.lang==='en' ? 'not used yet' : 'belum digunakan'">not used yet</span>
                    @endif
                </span>
            </div>
        </div>
        @if ($canEdit)
            <button @click="edit = ! edit" type="button" class="uj-btn-ghost" style="height:32px;font-size:12px;padding:0 13px;"><span x-text="edit ? ($store.ui.lang==='en' ? 'Close' : 'Tutup') : ($store.ui.lang==='en' ? 'Edit' : 'Sunting')">Edit</span></button>
            <form method="post" action="{{ route('projects.delete', $project) }}" onsubmit="return confirm('Delete or deactivate this project?')">
                @csrf
                <button type="submit" class="uj-btn-ghost" style="height:32px;font-size:12px;padding:0 13px;color:var(--error);"><span x-text="$store.ui.lang==='en' ? 'Delete' : 'Padam'">Delete</span></button>
            </form>
        @endif
    </div>

    @if ($canEdit)
        <div x-show="edit" x-cloak style="margin-top:14px;padding-top:14px;border-top:1px solid var(--hairline-soft);">
            @include('partials.ts-project-form', ['project' => $project, 'action' => route('projects.update', $project), 'submitLabel' => 'Save changes', 'categories' => $categories])
        </div>
    @endif
</div>
```

Replace `resources/views/partials/ts-subpillar-row.blade.php` entirely:

```blade
{{-- One sub-pillar row. Tenant-wide: it applies to every project, not to one.
     Shared by the initial render and the AJAX append on add. Expects $sp
     (SubPillar, with entries_count) and $canEdit. --}}
@php $canEdit = $canEdit ?? true; @endphp
<div style="display:flex;align-items:center;gap:12px;padding:11px 0;border-bottom:1px solid var(--hairline-soft);" x-data="{ se: false }">
    <span style="flex:1;min-width:0;font-size:13.5px;font-weight:500;color:{{ $sp->is_active ? 'var(--ink)' : 'var(--muted)' }};">{{ $sp->name }}@unless ($sp->is_active) <span style="color:var(--muted);font-size:11px;">(<span x-text="$store.ui.lang==='en' ? 'inactive' : 'tidak aktif'">inactive</span>)</span>@endunless</span>
    <span style="font-size:11.5px;font-weight:500;color:var(--muted-soft);font-family:var(--font-mono);font-variant-numeric:tabular-nums;">
        <span style="font-weight:600;color:var(--muted);">{{ $sp->entries_count ?? 0 }}</span> <span x-text="$store.ui.lang==='en' ? 'timesheet lines' : 'baris lembaran masa'">timesheet lines</span>
    </span>
    @if ($canEdit)
        <button @click="se = ! se" type="button" class="uj-btn-ghost" style="height:28px;font-size:11.5px;padding:0 10px;"><span x-text="se ? ($store.ui.lang==='en' ? 'Close' : 'Tutup') : ($store.ui.lang==='en' ? 'Edit' : 'Sunting')">Edit</span></button>
        <form method="post" action="{{ route('sub-pillars.delete', $sp) }}" onsubmit="return confirm('Delete or deactivate this sub-pillar?')">
            @csrf
            <button type="submit" class="uj-btn-ghost" style="height:28px;font-size:11.5px;padding:0 10px;color:var(--error);"><span x-text="$store.ui.lang==='en' ? 'Delete' : 'Padam'">Delete</span></button>
        </form>
        <div x-show="se" x-cloak style="flex-basis:100%;padding:8px 0 4px;">
            @include('partials.ts-subpillar-form', ['sub' => $sp, 'action' => route('sub-pillars.update', $sp), 'submitLabel' => 'Save', 'compact' => true])
        </div>
    @endif
</div>
```

`resources/views/partials/ts-subpillar-form.blade.php` needs no change — it already takes `$action` from the caller. Update only its opening comment to say "Shared add/edit form for a sub-pillar" (drop "project").

- [ ] **Step 7: Remove the projects block from Timesheet Setup**

In `resources/views/screens/timesheet-setup.blade.php`, delete lines 57-82 — the `{{-- PROJECTS --}}` heading, its add card, and the `#ts-projects` list. Leave the categories section and the inline `<script>` alone for now (Task 5 extracts it).

- [ ] **Step 8: Move the project cases out of the AJAX test**

In `tests/Feature/TimesheetSetupAjaxTest.php`:

- delete `test_project_ajax_add_returns_rendered_row`, `test_project_categories_are_synced_on_store_and_update` and `test_validation_error_returns_422_json_not_a_redirect`
- shrink `test_category_and_subpillar_ajax_add_return_rows` to the category half and rename it:

```php
    public function test_category_ajax_add_returns_a_rendered_row(): void
    {
        $cat = $this->actingHr()->postJson(route('timesheet.admin.categories.store'), [
            'name' => 'Development', 'requires_project' => 1,
        ]);

        $cat->assertOk();
        $this->assertStringContainsString('Development', $cat->json('html'));
        $this->assertSame('#ts-cat-count', $cat->json('count_sel'));
    }
```

- drop the now-unused `App\Models\Project` import, and update the class docblock to say categories only.

Then add the moved cases to `tests/Feature/ProjectScreenTest.php`:

```php
    public function test_project_ajax_add_returns_a_rendered_row(): void
    {
        $res = $this->actingAsRole('hr')->postJson(route('projects.store'), [
            'name' => 'KPT: RMS', 'code' => 'KPT', 'sort' => 0,
        ]);

        $res->assertOk()->assertJsonStructure(['html', 'count_sel']);
        $this->assertStringContainsString('KPT: RMS', $res->json('html'));
        $this->assertSame('#ts-proj-count', $res->json('count_sel'));
    }

    public function test_sub_pillar_ajax_add_returns_a_rendered_row_and_bumps_its_own_count(): void
    {
        $res = $this->actingAsRole('hr')->postJson(route('sub-pillars.store'), ['name' => 'Technical']);

        $res->assertOk();
        $this->assertStringContainsString('Technical', $res->json('html'));
        $this->assertSame('#ts-sub-count', $res->json('count_sel'));
    }

    public function test_project_categories_are_synced_on_store_and_update(): void
    {
        $dev = TimesheetCategory::create(['tenant_id' => $this->tenant->id, 'name' => 'Development', 'requires_project' => true]);
        $maint = TimesheetCategory::create(['tenant_id' => $this->tenant->id, 'name' => 'Maintenance', 'requires_project' => true]);

        $this->actingAsRole('hr')->postJson(route('projects.store'), [
            'name' => 'KPT: RMS', 'categories' => [$dev->id],
        ])->assertOk();

        $project = Project::where('name', 'KPT: RMS')->firstOrFail();
        $this->assertSame([$dev->id], $project->categories->pluck('id')->all());

        $this->actingAsRole('hr')->post(route('projects.update', $project), [
            'name' => 'KPT: RMS', 'categories' => [$maint->id],
        ])->assertRedirect();

        $this->assertSame([$maint->id], $project->fresh()->categories->pluck('id')->all());
    }

    public function test_a_validation_error_returns_422_json_not_a_redirect(): void
    {
        $this->actingAsRole('hr')->postJson(route('projects.store'), ['name' => ''])
            ->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_a_project_name_must_be_unique_within_the_tenant(): void
    {
        Project::create(['tenant_id' => $this->tenant->id, 'name' => 'KPT: RMS', 'is_active' => true]);

        $this->actingAsRole('management')
            ->post(route('projects.store'), ['name' => 'KPT: RMS'])
            ->assertSessionHasErrors('name');
    }
```

- [ ] **Step 9: Run the tests to verify they pass**

Run: `lerd artisan test --compact --filter=ProjectScreenTest`
Expected: PASS.

Run: `lerd artisan test --compact tests/Feature/TimesheetSetupAjaxTest.php`
Expected: PASS.

- [ ] **Step 10: Run Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers routes/web.php resources/views tests/Feature
git commit -m "refactor(projects): move project + sub-pillar writes to ProjectController

Managers may now edit projects, categories stay management/HR, so the two can
no longer share one role constant. Routes move to /app/projects and
/app/sub-pillars; Timesheet Setup keeps categories only."
```

---

## Task 4: The Projects screen

**Files:**
- Create: `resources/views/screens/projects.blade.php`
- Create: `resources/views/partials/ajax-row-add.blade.php`
- Modify: `app/Support/Amanahku.php:98`, `:377`
- Modify: `app/Http/Controllers/AppController.php:191-195`, `:231`, `:411`
- Delete: `app/Http/Controllers/ProjectQuickCreateController.php`, `resources/views/screens/project-quick-create.blade.php`, `tests/Feature/ProjectQuickCreateTest.php`
- Modify: `routes/web.php` (drop `project-quick-create.store`)
- Test: `tests/Feature/ProjectScreenTest.php`

**Interfaces:**
- Consumes: `ProjectController::screenData()` and the partials from Task 3.
- Produces: screen id `projects` at `/app/projects` (GET, via the existing `app.screen` route), with `project-quick-create` aliasing to it.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/ProjectScreenTest.php`:

```php
    public function test_an_employee_sees_the_register_with_no_write_controls(): void
    {
        Project::create(['tenant_id' => $this->tenant->id, 'name' => 'JKDM: MyStods']);
        SubPillar::create(['tenant_id' => $this->tenant->id, 'name' => 'Technical']);

        $response = $this->actingAsRole('employee')->get('/app/projects');

        $response->assertOk()
            ->assertSee('JKDM: MyStods')
            ->assertSee('Technical')
            ->assertDontSee(route('projects.store'))
            ->assertDontSee(route('sub-pillars.store'));
    }

    public function test_a_manager_sees_the_add_forms(): void
    {
        $response = $this->actingAsRole('manager')->get('/app/projects');

        $response->assertOk()
            ->assertSee(route('projects.store'))
            ->assertSee(route('sub-pillars.store'));
    }

    public function test_everyone_sees_the_projects_nav_link(): void
    {
        $this->actingAsRole('employee')
            ->get(route('app.screen', 'dash'))
            ->assertOk()
            ->assertSee(route('app.screen', ['screen' => 'projects']));
    }

    public function test_the_old_new_project_link_still_lands_on_the_register(): void
    {
        Project::create(['tenant_id' => $this->tenant->id, 'name' => 'JKDM: MyStods']);

        $this->actingAsRole('manager')
            ->get('/app/project-quick-create')
            ->assertOk()
            ->assertSee('JKDM: MyStods');
    }

    public function test_the_usage_counts_are_rendered(): void
    {
        $project = Project::create(['tenant_id' => $this->tenant->id, 'name' => 'JKDM: MyStods']);
        $category = TimesheetCategory::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Development', 'requires_project' => true,
        ]);
        $user = $this->actorWithRole('employee');
        $employee = Employee::where('user_id', $user->id)->firstOrFail();
        $timesheet = \App\Models\Timesheet::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $employee->id,
            'week_start' => '2026-06-15', 'status' => 'draft', 'total_hours' => 8,
        ]);
        \App\Models\TimesheetEntry::create([
            'tenant_id' => $this->tenant->id, 'timesheet_id' => $timesheet->id,
            'entry_date' => '2026-06-15', 'category_id' => $category->id,
            'project_id' => $project->id, 'percentage' => 100,
        ]);

        $this->actingAsRole('hr')->get('/app/projects')
            ->assertOk()
            ->assertSee('timesheet lines');
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `lerd artisan test --compact --filter=ProjectScreenTest`
Expected: FAIL — `/app/projects` renders `screens.empty` (no such view), so `assertSee('JKDM: MyStods')` fails.

- [ ] **Step 3: Write the screen**

Create `resources/views/screens/projects.blade.php`:

```blade
@extends('layouts.app')

@section('screen')
@include('partials.guide', [
    'key' => 'projects',
    'en'  => [
        'title' => 'Projects',
        'body'  => 'Every project Unijaya is working on, and the sub-pillars they all share. Timesheets, T.A.A. cards and Track read this same list, so a project only ever needs adding once — here.',
        'who'   => 'Everyone can read · Manager, Management & HR can edit',
        'steps' => [
            'Add a project with its name, then tag the categories it falls under.',
            'Sub-pillars are the kind of work — Management, Meeting, Technical. One list, shared by every project.',
            'Anything already used on a timesheet is deactivated instead of deleted, so reports keep their history.',
        ],
    ],
    'ms'  => [
        'title' => 'Projek',
        'body'  => 'Setiap projek yang sedang dijalankan Unijaya, dan sub-tiang yang dikongsi semuanya. Lembaran masa, kad T.A.A. dan Track membaca senarai yang sama, jadi projek hanya perlu ditambah sekali — di sini.',
        'who'   => 'Semua boleh baca · Pengurus, Pengurusan & HR boleh sunting',
        'steps' => [
            'Tambah projek dengan namanya, kemudian tandakan kategori yang berkaitan.',
            'Sub-tiang ialah jenis kerja — Pengurusan, Mesyuarat, Teknikal. Satu senarai, dikongsi setiap projek.',
            'Apa-apa yang telah digunakan pada lembaran masa akan dinyahaktifkan, bukan dipadam, supaya laporan kekal sejarahnya.',
        ],
    ],
])

{{-- ============================ PROJECTS ============================ --}}
@php
    $projectIndex = $projects->map(fn ($p) => [
        'hay' => mb_strtolower(trim($p->name.' '.$p->code)),
        'active' => (bool) $p->is_active,
    ])->values();
@endphp
<div x-data="{ q: '', showOff: false, items: @js($projectIndex) }">
    <div style="display:flex;align-items:center;gap:9px;margin:0 0 11px;">
        <h2 style="font-size:14px;font-weight:600;color:var(--ink);margin:0;"><span x-text="$store.ui.lang==='en' ? 'Projects' : 'Projek'">Projects</span></h2>
        {{-- Plain text, never Alpine-bound: the AJAX add script increments this node. --}}
        <span id="ts-proj-count" style="font-size:11px;font-weight:600;color:var(--muted);background:var(--canvas);border:1px solid var(--hairline);padding:2px 9px;border-radius:9999px;font-family:var(--font-mono);font-variant-numeric:tabular-nums;">{{ $projects->count() }}</span>
    </div>

    <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:12px;">
        <input x-model="q" type="search" :placeholder="$store.ui.lang==='en' ? 'Search name or code' : 'Cari nama atau kod'"
               style="flex:1;min-width:200px;max-width:320px;height:40px;padding:0 14px;background:var(--card);border:1px solid var(--hairline);border-radius:9px;font-size:14px;outline:none;" />
        <label style="display:inline-flex;align-items:center;gap:8px;font-size:12.5px;color:var(--muted);cursor:pointer;">
            <input type="checkbox" x-model="showOff" />
            <span x-text="$store.ui.lang==='en' ? 'Show inactive' : 'Tunjuk tidak aktif'">Show inactive</span>
        </label>
    </div>

    @if ($canEdit)
        <div class="uj-card" style="padding:0;margin-bottom:14px;" x-data="{ open: false }">
            <button @click="open = ! open" type="button" style="width:100%;display:flex;align-items:center;justify-content:space-between;gap:10px;padding:14px 20px;background:none;cursor:pointer;border:0;">
                <span style="display:flex;align-items:center;gap:9px;font-size:13.5px;font-weight:600;color:var(--ink);">
                    <span style="width:24px;height:24px;border-radius:7px;background:var(--red-tint);color:var(--red);display:flex;align-items:center;justify-content:center;font-size:16px;line-height:1;">+</span>
                    <span x-text="$store.ui.lang==='en' ? 'Add project' : 'Tambah projek'">Add project</span>
                </span>
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--muted)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" :style="open ? 'transform:rotate(180deg);transition:.15s' : 'transition:.15s'"><path d="M6 9l6 6 6-6"/></svg>
            </button>
            <div x-show="open" x-cloak style="padding:18px 22px;border-top:1px solid var(--hairline);">
                @include('partials.ts-project-form', ['project' => null, 'action' => route('projects.store'), 'submitLabel' => 'Add project', 'ajaxTarget' => '#ts-projects', 'categories' => $projectCategories])
            </div>
        </div>
    @endif

    <div id="ts-projects">
        @forelse ($projects as $project)
            @include('partials.ts-project-row', ['project' => $project, 'categories' => $projectCategories, 'canEdit' => $canEdit])
        @empty
            <div data-empty class="uj-card" style="padding:24px;text-align:center;font-size:13px;color:var(--muted);"><span x-text="$store.ui.lang==='en' ? 'No projects yet.' : 'Tiada projek lagi.'">No projects yet.</span></div>
        @endforelse
    </div>

    {{-- Shown only when a search or the inactive filter hides everything. --}}
    <div x-show="items.length && ! items.some(i => (showOff || i.active) && i.hay.includes(q.toLowerCase()))" x-cloak
         class="uj-card" style="padding:24px;text-align:center;font-size:13px;color:var(--muted);">
        <span x-text="$store.ui.lang==='en' ? 'No project matches that search.' : 'Tiada projek sepadan dengan carian itu.'">No project matches that search.</span>
    </div>
</div>

{{-- ============================ SUB-PILLARS ============================ --}}
<div style="display:flex;align-items:center;gap:9px;margin:34px 0 4px;">
    <h2 style="font-size:14px;font-weight:600;color:var(--ink);margin:0;"><span x-text="$store.ui.lang==='en' ? 'Sub-pillars' : 'Sub-tiang'">Sub-pillars</span></h2>
    <span id="ts-sub-count" style="font-size:11px;font-weight:600;color:var(--muted);background:var(--canvas);border:1px solid var(--hairline);padding:2px 9px;border-radius:9999px;font-family:var(--font-mono);font-variant-numeric:tabular-nums;">{{ $subPillars->count() }}</span>
</div>
<p style="font-size:12.5px;color:var(--muted);margin:0 0 12px;max-width:60ch;">
    <span x-text="$store.ui.lang==='en'
        ? 'The kind of work, not a part of a project. One list, shared by every project — staff pick one when they book time, or leave it blank and book the whole project.'
        : 'Jenis kerja, bukan sebahagian daripada projek. Satu senarai, dikongsi setiap projek — staf pilih satu semasa merekod masa, atau biarkan kosong untuk keseluruhan projek.'">The kind of work, not a part of a project.</span>
</p>

<div class="uj-card" style="padding:6px 18px 14px;">
    <div id="ts-subpillars">
        @forelse ($subPillars as $sp)
            @include('partials.ts-subpillar-row', ['sp' => $sp, 'canEdit' => $canEdit])
        @empty
            <div data-empty style="padding:18px 0;text-align:center;font-size:13px;color:var(--muted);"><span x-text="$store.ui.lang==='en' ? 'No sub-pillars yet.' : 'Tiada sub-tiang lagi.'">No sub-pillars yet.</span></div>
        @endforelse
    </div>
    @if ($canEdit)
        <div style="margin-top:12px;padding-top:13px;border-top:1px solid var(--hairline);">
            @include('partials.ts-subpillar-form', ['sub' => null, 'action' => route('sub-pillars.store'), 'submitLabel' => '+ Add', 'compact' => false, 'ajaxTarget' => '#ts-subpillars'])
        </div>
    @endif
</div>

@include('partials.ajax-row-add')
@endsection
```

That last include needs the shared script, so create `resources/views/partials/ajax-row-add.blade.php` in this task too. It is the block currently inline at `screens/timesheet-setup.blade.php:88-137`, minus the `if (! res.d.count_sel)` branch that bumped a project card's nested `[data-sub-count]` — sub-pillars no longer live inside a project card, so that path is dead:

```blade
{{-- Inline add without a page reload: intercept forms marked data-ajax, POST them,
     append the server-rendered row to the target list, then reset the form so the
     next entry is one keystroke away. Kills the "add → full refresh → re-scroll →
     re-open" loop that made bulk entry painful.

     Shared by the Projects register and Timesheet Setup — one copy, because two
     copies is how the last one rotted. --}}
<script>
    (function () {
        function bump(sel, by) {
            var el = sel && document.querySelector(sel);
            if (el) { el.textContent = (parseInt(el.textContent, 10) || 0) + by; }
        }
        document.addEventListener('submit', function (e) {
            var form = e.target;
            if (! form || ! form.matches || ! form.matches('form[data-ajax]')) { return; }
            e.preventDefault();
            var target = document.querySelector(form.dataset.target);
            var btn = form.querySelector('[type=submit]');
            if (btn) { btn.disabled = true; }

            fetch(form.action, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                body: new FormData(form),
            }).then(function (r) {
                return r.json().then(function (d) { return { ok: r.ok, d: d }; }, function () { return { ok: r.ok, d: {} }; });
            }).then(function (res) {
                if (btn) { btn.disabled = false; }
                if (! res.ok) {
                    alert(res.d && res.d.message ? res.d.message : 'Could not save — check the fields.');
                    return;
                }
                if (target && res.d.html) {
                    var empty = target.querySelector('[data-empty]');
                    if (empty) { empty.remove(); }
                    target.insertAdjacentHTML('beforeend', res.d.html);
                    var added = target.lastElementChild;
                    if (window.Alpine && added) { window.Alpine.initTree(added); }
                }
                bump(res.d.count_sel, 1);
                form.reset();
                var first = form.querySelector('input[name=name]');
                if (first) { first.focus(); }
            }).catch(function () {
                if (btn) { btn.disabled = false; }
                alert('Network error — not saved.');
            });
        });
    })();
</script>
```

Timesheet Setup keeps its own inline copy for now — Task 5 deletes it and points that screen here. Two copies exist for exactly one task.

- [ ] **Step 4: Wire the screen up**

In `app/Http/Controllers/AppController.php`:

Replace the `project-quick-create` gate at `:191-195` with nothing — delete those five lines. The register is readable by everyone, so it needs no role gate.

At `:231`, extend the alias line:

```php
        // claim-approvals was merged into the unified claims screen, and
        // project-quick-create into the Projects register; both slugs still resolve
        // (deep links, bookmarks) and land on the screen that replaced them.
        $viewScreen = match ($screen) {
            'claim-approvals' => 'claims',
            'project-quick-create' => 'projects',
            default => $screen,
        };
```

At `:411`, replace the `project-quick-create` data line with:

```php
            'projects', 'project-quick-create' => app(ProjectController::class)->screenData($request),
```

Swap the `ProjectQuickCreateController` import for `ProjectController`.

In `app/Support/Amanahku.php`, replace the nav entry at `:98` — and move it out of the *My Team* block into the *Workplace* block, next to Shared Resources:

```php
            // The project register — readable by everyone (no `roles` key), written by
            // manager/management/HR (gated in ProjectController). Lives in Workplace
            // because it is a shared company reference list, not personal work.
            $s('Workplace', 'Tempat Kerja', ['id' => 'projects', 'label' => 'Projects', 'label_ms' => 'Projek', 'icon' => 'M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z']),
```

In `Amanahku::page()`, replace the `project-quick-create` entry at `:377` with two entries — the new one, and the old key kept pointing at the same copy so stale bookmarks keep their header:

```php
            'projects' => ['title' => 'Projects', 'title_ms' => 'Projek', 'sub' => 'Every project in Unijaya, and the sub-pillars they all share.', 'sub_ms' => 'Setiap projek di Unijaya, dan sub-tiang yang dikongsi semuanya.', 'crumb' => ['Workplace', 'Projects']],
            // Retired slug, same destination — see AppController::screen.
            'project-quick-create' => ['title' => 'Projects', 'title_ms' => 'Projek', 'sub' => 'Every project in Unijaya, and the sub-pillars they all share.', 'sub_ms' => 'Setiap projek di Unijaya, dan sub-tiang yang dikongsi semuanya.', 'crumb' => ['Workplace', 'Projects']],
```

- [ ] **Step 5: Delete the old screen**

```bash
git rm app/Http/Controllers/ProjectQuickCreateController.php resources/views/screens/project-quick-create.blade.php tests/Feature/ProjectQuickCreateTest.php
```

In `routes/web.php`, delete the `project-quick-create.store` route and its two-line comment.

Its test cases are already covered in `ProjectScreenTest`: create (`test_a_manager_can_create_a_project`), categories (`test_project_categories_are_synced_on_store_and_update`), name required (`test_a_validation_error_returns_422_json_not_a_redirect`), unique name (`test_a_project_name_must_be_unique_within_the_tenant`), and the two inverted cases (`test_an_employee_sees_the_register_with_no_write_controls`, `test_everyone_sees_the_projects_nav_link`).

- [ ] **Step 6: Run the tests to verify they pass**

Run: `lerd artisan test --compact --filter=ProjectScreenTest`
Expected: PASS, all cases.

- [ ] **Step 7: Run Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A app resources routes tests
git commit -m "feat(projects): a Projects register everyone can read

Workplace → Projects lists every project with how much it is actually used,
plus the shared sub-pillar list. Employees get the lists and no buttons.
Retires the create-only New Project screen; its old link still lands here."
```

---

## Task 5: Share the add script, fix the copy

**Files:**
- Modify: `resources/views/screens/timesheet-setup.blade.php`
- Modify: `app/Support/Amanahku.php:376`
- Modify: `app/Http/Controllers/SetupController.php:90`

**Interfaces:**
- Consumes: `partials.ajax-row-add` from Task 4.
- Produces: one copy of the add script, included by both `screens/projects.blade.php` and `screens/timesheet-setup.blade.php`.

- [ ] **Step 1: Point Timesheet Setup at the shared script**

In `resources/views/screens/timesheet-setup.blade.php`, delete the whole inline `<script>` block at the bottom of the file (and the four-line comment above it) and replace both with:

```blade
@include('partials.ajax-row-add')
```

That deletes the second copy. `partials/ajax-row-add.blade.php` (written in Task 4) is now the only one.

- [ ] **Step 2: Fix the Timesheet Setup copy**

In the same file, rewrite the guide arrays so they describe categories only and point at the new home:

```blade
    'en'  => [
        'title' => 'Timesheet setup',
        'body'  => 'Manage the categories staff pick from when they allocate their week. Mark a category "requires a project" (like Development or Maintenance) and staff must choose a project for it; others (Sales, On Leave, Public Holiday…) stand alone.',
        'who'   => 'HR & Management',
        'steps' => [
            'Add or edit the categories everyone sees in the dropdown — tick "requires a project" for delivery work.',
            'Projects and sub-pillars live on their own screen now: Workplace → Projects.',
            'Anything already used on a timesheet is deactivated instead of deleted, so reports keep their history.',
        ],
    ],
    'ms'  => [
        'title' => 'Tetapan lembaran masa',
        'body'  => 'Urus kategori yang dipilih staf semasa memperuntukkan minggu mereka. Tandakan kategori "memerlukan projek" (seperti Pembangunan atau Penyelenggaraan) dan staf mesti memilih projek untuknya; yang lain (Jualan, Bercuti, Cuti Umum…) berdiri sendiri.',
        'who'   => 'HR & Pengurusan',
        'steps' => [
            'Tambah atau sunting kategori yang dilihat semua orang — tandakan "memerlukan projek" untuk kerja penghantaran.',
            'Projek dan sub-tiang kini berada di skrin sendiri: Tempat Kerja → Projek.',
            'Apa-apa yang telah digunakan pada lembaran masa akan dinyahaktifkan, bukan dipadam, supaya laporan kekal sejarahnya.',
        ],
    ],
```

In `app/Support/Amanahku.php:376`, change the Timesheet Setup page meta:

```php
            'timesheet-setup' => ['title' => 'Timesheet Setup', 'title_ms' => 'Tetapan Lembaran Masa', 'sub' => 'The categories staff pick when allocating their week.', 'sub_ms' => 'Kategori yang dipilih staf semasa memperuntukkan minggu mereka.', 'crumb' => ['Administration', 'Timesheet Setup']],
```

In `app/Http/Controllers/SetupController.php:90`, change the wizard step description:

```php
            'timesheet_categories' => ['label' => 'Set up timesheet categories', 'label_ms' => 'Sediakan kategori timesheet', 'desc' => 'The categories staff allocate time against. Projects and sub-pillars live under Workplace → Projects.', 'screen' => 'timesheet-setup', 'query' => [], 'auto' => true, 'domain' => 'time', 'critical' => false],
```

The step's completion check (`SetupController.php:124`) counts categories only, so it keeps working untouched.

- [ ] **Step 3: Verify both screens still add rows without a reload**

Run: `lerd artisan test --compact tests/Feature/TimesheetSetupAjaxTest.php tests/Feature/ProjectScreenTest.php`
Expected: PASS.

Then check by hand at `http://localhost:9100` (log in with `hr@amanahku.test`, password `password`):
1. Workplace → Projects → Add project → save. The row appears with no page reload and the count next to "Projects" goes up by one.
2. Add a sub-pillar. Same, and the count next to "Sub-pillars" goes up.
3. Administration → Company Setup → Timesheet categories → add a category. Still appends in place.

- [ ] **Step 4: Run Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources app
git commit -m "refactor(screens): one copy of the inline add script, and honest copy

Timesheet Setup no longer claims to manage projects and sub-pillars; its guide
text and the wizard step point at Workplace → Projects instead."
```

---

## Task 6: Full verification and release prep

**Files:** none created; this task proves the whole change.

- [ ] **Step 1: Run the full test suite**

Run: `lerd artisan test --compact`
Expected: PASS. Pay attention to `TimesheetTest`, `TimesheetReportLensTest` and `TimesheetSetupAjaxTest` — those three are the proof that moving the management surface and the sub-pillar table did not disturb allocation or reporting.

- [ ] **Step 2: Run static analysis**

Run: `vendor/bin/phpstan analyse --memory-limit=1G`
Expected: no new errors. CI only runs on pull requests to `main`, so a push to `staging` is otherwise unanalysed. Common hits here: a leftover `ProjectSubPillar` import, or `$project->subPillars` in a file this plan did not name.

- [ ] **Step 3: Check nothing references the retired names**

```bash
grep -rn "ProjectSubPillar\|project_sub_pillars\|timesheet.admin.projects\|timesheet.admin.subpillars\|project-quick-create.store" --include="*.php" --include="*.blade.php" --include="*.js" app/ resources/ routes/ tests/ database/
```

Expected: matches only inside `database/migrations/` (the original create migration and the new data migration, both of which must keep the old name).

- [ ] **Step 4: Build the assets**

```bash
lerd artisan view:cache
bun run build
```

`view:cache` first, then the build — that order is what makes the Tailwind scan see every Blade file, and it is one of the four rules in `docs/RULES.md` that lose data or break the build when skipped.

- [ ] **Step 5: Walk the screen in a browser**

At `http://localhost:9100`:

1. Log in as `hr@amanahku.test` (password `password`). Workplace → Projects. Both lists render, every project shows a usage line, only the projects that have a code show a code chip.
2. Search for part of a project name — the list narrows; clear it — everything returns. Tick "Show inactive" — deactivated projects appear.
3. Log in as `employee@amanahku.test`. Same screen, both lists visible, no Add panels and no Edit/Delete buttons anywhere.
4. As the employee, open Timesheet and allocate a week: pick a category that requires a project, then a project, then a sub-pillar. The three-step picker still works and the sub-pillar list is the same on every project.
5. Visit `/app/project-quick-create` directly. It renders the register with the right title, not a blank header.

- [ ] **Step 6: Commit the built assets**

```bash
git add public/build
git commit -m "chore(build): rebuild assets for the Projects register"
```

- [ ] **Step 7: Release notes for the deploy**

This release migrates data, so the person deploying needs three things written down. Add them to the pull request description when `staging` is PR'd into `main`:

- **Takes a `mysqldump` before deploying** — `docs/RULES.md` requires it for any deploy that migrates, and this one repoints every timesheet line's sub-pillar.
- **The migration is self-checking.** If any row's sub-pillar name changes during the remap, it throws and the deploy fails loudly rather than corrupting history quietly.
- **`project_sub_pillars` is deliberately left behind**, unused, as the rollback. A later release drops it once staging and production have both run clean.

---

## Notes on deliberate simplifications

- **The project count badge is not live.** It shows the total, and searching does not change it, because the AJAX add script increments that node directly — an Alpine-bound number would fight it. The "no project matches that search" line covers the empty case.
- **A project added by AJAX while a search is active is not in the Alpine `items` index**, so the empty-state line can briefly disagree with what is on screen. Harmless: the row itself renders, and any reload fixes the index. Adding a project mid-search is not a real workflow.
- **Search and the inactive toggle are client-side** over the rendered rows. Fine at Unijaya's scale (14 projects per tenant). If the list ever passes a few hundred, this needs to move server-side with pagination.
