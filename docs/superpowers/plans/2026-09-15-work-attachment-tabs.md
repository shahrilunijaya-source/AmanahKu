# Work + Attachment Tabs Implementation Plan (sub-project 4)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add the Worksy "Work" tab (work details, work location with allowed clock-in sites, assets with return/reference/remark) and the "Attachment" tab (employee documents with upload and delete) to the profile screen, and make the geofence honour the allowed-sites list.

**Architecture:** Three additive migrations (employee work columns, asset detail columns, `employee_work_site` pivot). One new `WorkRecordController::update` for the work-details modal, one new `AssetController::updateDetails` for per-asset return/reference/remark, and the Attachment tab reuses `documents.store` / `documents.destroy` (both `back()`, so `?tab=` survives). `ScheduleResolver::matchActualSite` filters client-site candidates by the pivot when it has rows. The old Assets tab is removed; its list moves into the Work tab.

**Tech Stack:** Laravel 13 / PHP 8.5, Blade + Alpine, PHPUnit on sqlite, Pint.

**Spec:** `docs/superpowers/specs/2026-09-15-employee-record-and-progression-design.md` § 4 and "Shared rules".

## Global Constraints

- Edit gate `hasTenantRole($request, ['management','hr'])` (director collapses into management). Work tab edit = HR/management only (spec: employee edits own Personal/Family/Experience/Attachment, not Work). Attachment: employee uploads/deletes own, HR everything (`DocumentController` already enforces this).
- `manager` role sees neither new tab on a report. Removing the Assets tab means a manager no longer sees a report's assets on the profile (the Assets admin screen still lists them). Documented deviation, accepted.
- Route-model binding is NOT tenant-safe: every controller re-checks `tenant_id`.
- Every write calls `AuditLog::record(action, target)`.
- Validation-failure reopen: `session()->flash('form', '<key>'); throw new ValidationException($validator);` — profile x-data reads `session('form')`.
- Bilingual label helper (must escape): `$L = fn ($en, $ms) => '<span x-text="'.e("\$store.ui.lang==='en' ? ".json_encode($en).' : '.json_encode($ms)).'">'.e($en).'</span>';`
- Section headings use `class="uj-section-head"` (tinted bar, added 2026-09-15).
- Upload limits stay those of `documents.store` (PDF/JPG/PNG/DOC/DOCX, 8 MB). Spec's 4 MB / images+PDF would mean two rules for one endpoint; documented deviation.
- Tests: `php artisan test --compact <file>`; Pint `vendor/bin/pint --dirty --format agent`; dev DB `lerd artisan migrate`; assets `lerd artisan view:clear && lerd artisan view:cache && bun run build`, commit `public/build` if changed.
- Work directly on `dev`, commit per task.

---

## File map

| File | Responsibility |
|---|---|
| `database/migrations/2026_09_29_100000_add_work_columns_to_employees.php` | `attendance_id`, `work_phone`, `benefit_start_at` |
| `database/migrations/2026_09_29_100100_add_detail_columns_to_assets.php` | `returned_at`, `reference_no`, `remark` |
| `database/migrations/2026_09_29_100200_create_employee_work_site_table.php` | pivot: allowed clock-in sites |
| `app/Models/Employee.php` | `WORK_FIELDS`, cast, `allowedWorkSites()` |
| `app/Models/Asset.php` | cast `returned_at` |
| `app/Attendance/ScheduleResolver.php` | pivot-aware `matchActualSite`, `configuredSites` gains site id |
| `app/Http/Controllers/WorkRecordController.php` | Work details + location update |
| `app/Http/Controllers/AssetController.php` | `updateDetails` |
| `routes/web.php` | 2 routes |
| `app/Http/Controllers/Concerns/BuildsPeopleData.php` | `workGate`, `canEditWork`, `attachmentGate`, `workSites`, `allowedSiteIds` |
| `resources/views/screens/profile.blade.php` | tabs + panels, remove Assets tab |
| `resources/views/partials/profile/work-tab.blade.php` | Work Details, Work Location, Assets + modal |
| `resources/views/partials/profile/attachment-tab.blade.php` | documents list + upload + delete |
| `tests/Feature/WorkSchemaTest.php`, `WorkRecordTest.php`, `GeofenceAllowedSitesTest.php`, `AssetDetailsTest.php`, `ProfileWorkAttachmentTabsTest.php` | tests |

---

### Task 1: Schema — work columns, asset detail columns, pivot

**Files:**
- Create: the three migrations above
- Modify: `app/Models/Employee.php`, `app/Models/Asset.php`
- Test: `tests/Feature/WorkSchemaTest.php`

**Interfaces:**
- Produces: `Employee::WORK_FIELDS = ['attendance_id','work_phone','benefit_start_at','work_site_id']`, `Employee::allowedWorkSites(): BelongsToMany` (pivot `employee_work_site`), `Asset` casts `returned_at` date.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\WorkSite;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_work_columns_pivot_and_asset_details_round_trip(): void
    {
        $t = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        app(CurrentTenant::class)->set($t);
        $site = WorkSite::create(['tenant_id' => $t->id, 'name' => 'Client HQ']);
        $e = Employee::create(['tenant_id' => $t->id, 'name' => 'Adibah', 'status' => 'active', 'workload' => 'green', 'joined_at' => '2026-01-05',
            'attendance_id' => 'ATT-001', 'work_phone' => '03-1234', 'benefit_start_at' => '2026-04-05']);
        $e->allowedWorkSites()->sync([$site->id => ['tenant_id' => $t->id]]);
        $a = Asset::create(['tenant_id' => $t->id, 'employee_id' => $e->id, 'name' => 'Laptop', 'category' => 'laptop', 'status' => 'assigned',
            'assigned_at' => '2026-01-05', 'returned_at' => '2026-06-01', 'reference_no' => 'REF-9', 'remark' => 'Scratched lid']);

        $e = $e->fresh();
        $this->assertSame('ATT-001', $e->attendance_id);
        $this->assertSame('2026-04-05', $e->benefit_start_at->toDateString());
        $this->assertSame([$site->id], $e->allowedWorkSites->pluck('id')->all());
        $this->assertSame('2026-06-01', $a->fresh()->returned_at->toDateString());
        $this->assertSame('REF-9', $a->fresh()->reference_no);
    }
}
```

- [ ] **Step 2: Run → FAIL** (`no such column: attendance_id`).

- [ ] **Step 3: Migrations**

`database/migrations/2026_09_29_100000_add_work_columns_to_employees.php`
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('attendance_id', 40)->nullable()->after('staff_id');
            $table->string('work_phone', 40)->nullable()->after('attendance_id');
            $table->date('benefit_start_at')->nullable()->after('confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('employees', fn (Blueprint $table) => $table->dropColumn(['attendance_id', 'work_phone', 'benefit_start_at']));
    }
};
```

`database/migrations/2026_09_29_100100_add_detail_columns_to_assets.php`
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->date('returned_at')->nullable()->after('assigned_at');
            $table->string('reference_no', 80)->nullable()->after('returned_at');
            $table->text('remark')->nullable()->after('reference_no');
        });
    }

    public function down(): void
    {
        Schema::table('assets', fn (Blueprint $table) => $table->dropColumn(['returned_at', 'reference_no', 'remark']));
    }
};
```

`database/migrations/2026_09_29_100200_create_employee_work_site_table.php`
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_work_site', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_site_id')->constrained('work_sites')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['employee_id', 'work_site_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_work_site');
    }
};
```

- [ ] **Step 4: Models**

`app/Models/Employee.php` — next to `PERSONAL_FIELDS`:
```php
    /** Work tab columns written by WorkRecordController. */
    public const WORK_FIELDS = ['attendance_id', 'work_phone', 'benefit_start_at', 'work_site_id'];
```
Add `'benefit_start_at' => 'date'` to `casts()`. Add relation (import `BelongsToMany`):
```php
    /** Client sites this person may clock in at (geofence allow-list). Empty = any configured site. */
    public function allowedWorkSites(): BelongsToMany
    {
        return $this->belongsToMany(WorkSite::class, 'employee_work_site')->withPivot('tenant_id')->withTimestamps();
    }
```
If `$fillable` is used on Employee, add the three new columns; if `$guarded = []`, nothing.

`app/Models/Asset.php`: `return ['assigned_at' => 'date', 'returned_at' => 'date'];`

- [ ] **Step 5: Run → PASS.** Pint.
- [ ] **Step 6: Commit** `git add database app tests && git commit -m "feat(work): work columns, asset detail columns, allowed work-site pivot"`

---

### Task 2: Geofence honours the allowed-sites pivot

**Files:**
- Modify: `app/Attendance/ScheduleResolver.php` (`matchActualSite`, `configuredSites`)
- Test: `tests/Feature/GeofenceAllowedSitesTest.php`

**Interfaces:**
- `configuredSites(int $tenantId): array` tuples gain a 6th element: `id` (branch id or work-site id). Existing destructuring of five elements keeps working; `BuildsWorkData` passes the array through untouched.

- [ ] **Step 1: Failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Attendance\ScheduleResolver;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\WorkSite;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GeofenceAllowedSitesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        app(CurrentTenant::class)->set($this->tenant);
    }

    private function site(string $name, float $lat, float $lng): WorkSite
    {
        return WorkSite::create(['tenant_id' => $this->tenant->id, 'name' => $name, 'latitude' => $lat, 'longitude' => $lng, 'radius_m' => 200]);
    }

    public function test_without_pivot_rows_any_configured_site_matches(): void
    {
        $a = $this->site('Site A', 3.1000, 101.6000);
        $this->site('Site B', 3.2000, 101.7000);
        $e = Employee::create(['tenant_id' => $this->tenant->id, 'name' => 'X', 'status' => 'active', 'workload' => 'green', 'joined_at' => '2026-01-05', 'work_arrangement' => 'client', 'work_site_id' => $a->id]);
        $r = app(ScheduleResolver::class);
        $spec = $r->matchActualSite($e, $r->resolve($e, now()), 3.2000, 101.7000);
        $this->assertSame('Site B', $spec->label);
    }

    public function test_with_pivot_rows_only_allowed_client_sites_match(): void
    {
        $a = $this->site('Site A', 3.1000, 101.6000);
        $this->site('Site B', 3.2000, 101.7000);
        $e = Employee::create(['tenant_id' => $this->tenant->id, 'name' => 'X', 'status' => 'active', 'workload' => 'green', 'joined_at' => '2026-01-05', 'work_arrangement' => 'client', 'work_site_id' => $a->id]);
        $e->allowedWorkSites()->sync([$a->id => ['tenant_id' => $this->tenant->id]]);
        $r = app(ScheduleResolver::class);
        $spec = $r->matchActualSite($e, $r->resolve($e, now()), 3.2000, 101.7000);
        $this->assertSame('Site A', $spec->label); // fell back to the assigned site, Site B not allowed
    }
}
```

- [ ] **Step 2: Run → second test FAILS** (label `Site B`).

- [ ] **Step 3: Implement**

In `configuredSites`, append the id to each tuple and update the docblock:
```php
     * @return list<array{0:string,1:string,2:float,3:float,4:int,5:int}> type, label, lat, lng, radius, id
```
```php
            ->map(fn (Branch $b) => ['office', $b->name, (float) $b->latitude, (float) $b->longitude, (int) $b->radius_m, (int) $b->id]);
...
            ->map(fn (WorkSite $s) => ['client', $s->name, (float) $s->latitude, (float) $s->longitude, (int) $s->radius_m, (int) $s->id]);
```

In `matchActualSite`, before the loop:
```php
        // Allow-list from the profile Work tab: when HR listed sites for this person, only
        // those client sites count as "on-site". Branches are never restricted. Empty list = any.
        $allowed = $employee->allowedWorkSites()->pluck('work_sites.id')->all();
```
and inside the loop, first line:
```php
            [$type, $label, $sLat, $sLng, $radius, $id] = $siteRow;
            if ($type === 'client' && $allowed !== [] && ! in_array($id, $allowed, true)) {
                continue;
            }
```
(change the `foreach` to `foreach ($this->configuredSites($employee->tenant_id) as $siteRow)` and keep `$best = [$type, $label, $sLat, $sLng, $radius]`).

- [ ] **Step 4: Run** this test + `tests/Feature/AttendanceClockEndpointTest.php` → PASS. Pint.
- [ ] **Step 5: Commit** `git commit -am "feat(attendance): geofence honours the per-employee allowed work-site list"`

---

### Task 3: WorkRecordController + AssetController::updateDetails + routes

**Files:**
- Create: `app/Http/Controllers/WorkRecordController.php`
- Modify: `app/Http/Controllers/AssetController.php`, `routes/web.php` (after the experience routes)
- Test: `tests/Feature/WorkRecordTest.php`, `tests/Feature/AssetDetailsTest.php`

**Interfaces:**
- `employees.work.update` POST `/app/employees/{employee}/work` — fields `attendance_id`, `work_phone`, `benefit_start_at`, `work_site_id`, `allowed_work_sites[]`. Flash `'work'` on failure. Redirect `?emp=..&tab=workinfo`.
- `assets.details` POST `/app/employees/assets/{asset}/details` (not under /app/assets: that prefix 404s when the Asset Register module is off) — `returned_at`, `reference_no`, `remark`, hidden `_asset`. Flash `'asset'`. `back()`.

- [ ] **Step 1: Failing tests**

`tests/Feature/WorkRecordTest.php` (copy the `setUp`/`login`/`emp` helpers verbatim from `tests/Feature/FamilyMemberTest.php`):
```php
    public function test_hr_updates_work_fields_and_allowed_sites(): void
    {
        $this->login('hr');
        $e = $this->emp('Adibah');
        $s1 = WorkSite::create(['tenant_id' => $this->tenant->id, 'name' => 'A']);
        $s2 = WorkSite::create(['tenant_id' => $this->tenant->id, 'name' => 'B']);
        $this->post("/app/employees/{$e->id}/work", ['attendance_id' => 'ATT-7', 'work_phone' => '03-999', 'benefit_start_at' => '2026-05-01', 'work_site_id' => $s1->id, 'allowed_work_sites' => [$s1->id, $s2->id]])
            ->assertRedirect("/app/profile?emp={$e->id}&tab=workinfo");
        $e->refresh();
        $this->assertSame('ATT-7', $e->attendance_id);
        $this->assertSame($s1->id, $e->work_site_id);
        $this->assertEqualsCanonicalizing([$s1->id, $s2->id], $e->allowedWorkSites->pluck('id')->all());
        $this->assertDatabaseHas('audit_logs', ['action' => 'Updated work details']);
    }

    public function test_employee_cannot_update_own_work_fields(): void
    {
        $me = $this->login('employee');
        $this->post("/app/employees/{$me->id}/work", ['attendance_id' => 'X'])->assertForbidden();
    }

    public function test_site_from_another_tenant_is_rejected(): void
    {
        $this->login('hr');
        $e = $this->emp('Adibah');
        $other = Tenant::create(['slug' => 'other', 'name' => 'Other', 'initials' => 'OT']);
        $foreign = WorkSite::create(['tenant_id' => $other->id, 'name' => 'Foreign']);
        $this->from('/app/profile')->post("/app/employees/{$e->id}/work", ['work_site_id' => $foreign->id])->assertSessionHasErrors('work_site_id')->assertSessionHas('form', 'work');
    }

    public function test_cross_tenant_employee_is_404(): void
    {
        $this->login('hr');
        $other = Tenant::create(['slug' => 'other', 'name' => 'Other', 'initials' => 'OT']);
        $e = Employee::create(['tenant_id' => $other->id, 'name' => 'Z', 'status' => 'active', 'workload' => 'green', 'joined_at' => '2026-01-05']);
        $this->post("/app/employees/{$e->id}/work", ['attendance_id' => 'X'])->assertNotFound();
    }
```

`tests/Feature/AssetDetailsTest.php` (same helpers):
```php
    public function test_hr_sets_return_reference_and_remark(): void
    {
        $this->login('hr');
        $e = $this->emp('Adibah');
        $a = Asset::create(['tenant_id' => $this->tenant->id, 'employee_id' => $e->id, 'name' => 'Laptop', 'category' => 'laptop', 'status' => 'assigned']);
        $this->from("/app/profile?emp={$e->id}&tab=workinfo")->post("/app/assets/{$a->id}/details", ['returned_at' => '2026-06-01', 'reference_no' => 'REF-1', 'remark' => 'ok'])
            ->assertRedirect("/app/profile?emp={$e->id}&tab=workinfo");
        $a->refresh();
        $this->assertSame('2026-06-01', $a->returned_at->toDateString());
        $this->assertSame('REF-1', $a->reference_no);
        $this->assertDatabaseHas('audit_logs', ['action' => 'Updated asset details']);
    }

    public function test_employee_is_forbidden(): void
    {
        $me = $this->login('employee');
        $a = Asset::create(['tenant_id' => $this->tenant->id, 'employee_id' => $me->id, 'name' => 'Laptop', 'category' => 'laptop', 'status' => 'assigned']);
        $this->post("/app/assets/{$a->id}/details", ['remark' => 'x'])->assertForbidden();
    }
```

- [ ] **Step 2: Run → FAIL** (404 route).

- [ ] **Step 3: Routes** (`routes/web.php`, after the experience routes):
```php
        Route::post('/app/employees/{employee}/work', [WorkRecordController::class, 'update'])->name('employees.work.update');
        Route::post('/app/assets/{asset}/details', [AssetController::class, 'updateDetails'])->name('assets.details');
```
(import `App\Http\Controllers\WorkRecordController`).

- [ ] **Step 4: Controller**

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Profile Work tab: work details and work location. HR/management only. */
class WorkRecordController extends Controller
{
    public function update(Request $request, Employee $employee): RedirectResponse
    {
        $tenantId = app(CurrentTenant::class)->id();
        abort_unless($employee->tenant_id === $tenantId, 404);
        abort_unless($this->hasTenantRole($request, ['management', 'hr']), 403);

        $siteExists = Rule::exists('work_sites', 'id')->where('tenant_id', $tenantId);
        $validator = validator($request->all(), [
            'attendance_id' => ['nullable', 'string', 'max:40'],
            'work_phone' => ['nullable', 'string', 'max:40'],
            'benefit_start_at' => ['nullable', 'date'],
            'work_site_id' => ['nullable', 'integer', $siteExists],
            'allowed_work_sites' => ['nullable', 'array'],
            'allowed_work_sites.*' => ['integer', $siteExists],
        ]);
        if ($validator->fails()) {
            session()->flash('form', 'work');
            throw new ValidationException($validator);
        }
        $data = $validator->validated();

        $employee->fill(array_intersect_key($data, array_flip(Employee::WORK_FIELDS)))->save();
        if ($request->has('allowed_work_sites')) {
            $employee->allowedWorkSites()->sync(array_fill_keys(array_map('intval', $data['allowed_work_sites'] ?? []), ['tenant_id' => $tenantId]));
        }

        AuditLog::record('Updated work details', $employee->name);

        return redirect()->route('profile', ['emp' => $employee->id, 'tab' => 'workinfo'])->with('ok', 'Work details saved for '.$employee->name.'.');
    }
}
```
Check the profile route name with `php artisan route:list --path=app/profile` — the personal controller uses the same redirect, copy its exact call.

`AssetController::updateDetails`:
```php
    /** Return date, reference number and remark for an assigned asset (profile Work tab). */
    public function updateDetails(Request $request, Asset $asset): RedirectResponse
    {
        $this->authorizePrivileged($request);
        abort_unless($asset->tenant_id === app(CurrentTenant::class)->id(), 403);

        $validator = validator($request->all(), [
            'returned_at' => ['nullable', 'date'],
            'reference_no' => ['nullable', 'string', 'max:80'],
            'remark' => ['nullable', 'string', 'max:500'],
        ]);
        if ($validator->fails()) {
            session()->flash('form', 'asset');
            throw new ValidationException($validator);
        }
        $asset->update($validator->validated());
        AuditLog::record('Updated asset details', $asset->name);

        return back()->with('ok', $asset->name.' updated.');
    }
```
(import `Illuminate\Validation\ValidationException`).

- [ ] **Step 5: Run both tests → PASS.** Pint.
- [ ] **Step 6: Commit** `git add app routes tests && git commit -m "feat(work): work record controller and asset detail fields"`

---

### Task 4: Profile data, Work tab, Attachment tab, Assets tab removal

**Files:**
- Modify: `app/Http/Controllers/Concerns/BuildsPeopleData.php`, `resources/views/screens/profile.blade.php`
- Create: `resources/views/partials/profile/work-tab.blade.php`, `resources/views/partials/profile/attachment-tab.blade.php`
- Modify: `tests/Feature/ProfileExperienceTabTest.php::test_training_moved_out_of_assets_tab_into_experience` (assets panel no longer exists → assert `x-show="tab === 'assets'"` absent, keep the experience assertion)
- Test: `tests/Feature/ProfileWorkAttachmentTabsTest.php`

- [ ] **Step 1: Failing test** (same helpers as FamilyMemberTest)

```php
    public function test_hr_sees_work_and_attachment_tabs_with_edit_and_upload(): void
    {
        $this->login('hr');
        $e = $this->emp('Adibah', ['attendance_id' => 'ATT-5']);
        Asset::create(['tenant_id' => $this->tenant->id, 'employee_id' => $e->id, 'name' => 'Dell XPS', 'category' => 'laptop', 'status' => 'assigned', 'reference_no' => 'REF-3']);
        EmployeeDocument::create(['tenant_id' => $this->tenant->id, 'employee_id' => $e->id, 'title' => 'Offer letter', 'category' => 'Contract', 'file_path' => 'x/y.pdf', 'original_name' => 'y.pdf', 'mime' => 'application/pdf', 'size' => 10, 'uploaded_by_employee_id' => $e->id]);
        $this->get("/app/profile?emp={$e->id}")->assertOk()
            ->assertSee('data-tab="workinfo"', false)->assertSee('data-tab="attachment"', false)->assertDontSee('data-tab="assets"', false)
            ->assertSee('ATT-5')->assertSee('Dell XPS')->assertSee('REF-3')->assertSee("/app/employees/{$e->id}/work", false)
            ->assertSee('Offer letter')->assertSee('action="'.route('documents.store').'"', false);
    }

    public function test_employee_own_view_reads_work_and_can_upload_attachment(): void
    {
        $me = $this->login('employee', ['attendance_id' => 'ATT-9']);
        $this->get('/app/profile')->assertOk()
            ->assertSee('data-tab="workinfo"', false)->assertSee('ATT-9')->assertDontSee("/app/employees/{$me->id}/work", false)
            ->assertSee('data-tab="attachment"', false)->assertSee('action="'.route('documents.store').'"', false);
    }

    public function test_manager_sees_neither_tab_on_a_report(): void
    {
        $m = $this->login('manager');
        $e = $this->emp('Adibah', ['reports_to_id' => $m->id]);
        $this->get("/app/profile?emp={$e->id}")->assertOk()
            ->assertDontSee('data-tab="workinfo"', false)->assertDontSee('data-tab="attachment"', false)->assertDontSee('data-tab="assets"', false);
    }
```
Imports: `App\Models\Asset`, `App\Models\EmployeeDocument`.

- [ ] **Step 2: Run → FAIL.**

- [ ] **Step 3: BuildsPeopleData::profileData**

`$with` += `'workSite', 'allowedWorkSites'`. After `$canEditExperience`:
```php
        $workGate = $experienceGate;          // HR/management, or own record
        $canEditWork = $canEdit;              // HR/management only
        $attachmentGate = $experienceGate;
```
Return keys:
```php
            'workGate' => $workGate,
            'canEditWork' => $canEditWork,
            'attachmentGate' => $attachmentGate,
            'workSites' => $workGate && $canEditWork ? WorkSite::orderBy('name')->get(['id', 'name']) : collect(),
```
(import `App\Models\WorkSite`). `documents` is already returned under `$experienceGate`.

- [ ] **Step 4: profile.blade.php**

x-data: add `'work'`, `'asset'` to the `edit` exclusion list; add `editWork: {{ session('form') === 'work' ? 'true' : 'false' }}`.

Tabs: after the experience entry
```php
        if ($workGate ?? false) { $tabs[] = ['workinfo', 'Work', 'Kerja']; }
```
Remove the `['assets', 'Assets', 'Aset']` entry. After the `leave` entry (find `$tabs[] = ['leave'`):
```php
        if ($attachmentGate ?? false) { $tabs[] = ['attachment', 'Attachment', 'Lampiran']; }
```
Panels: after the experience panel
```blade
            @if ($workGate ?? false)
                <div x-show="tab === 'workinfo'" x-cloak class="uj-tab-stack" style="padding:20px;">@include('partials.profile.work-tab')</div>
            @endif
```
after the leave panel:
```blade
            @if ($attachmentGate ?? false)
                <div x-show="tab === 'attachment'" x-cloak class="uj-tab-stack" style="padding:20px;">@include('partials.profile.attachment-tab')</div>
            @endif
```
Delete the whole `<div x-show="tab === 'assets'" …>…</div>` block (comment "Assets · training moved…" included). `$aIcon`/`$aSc` at line ~218 stay (used by the Work tab).

- [ ] **Step 5: `partials/profile/work-tab.blade.php`**

```blade
{{-- Work: work details, work location (primary site + allowed clock-in sites), assets.
     Expects $p, $canEditWork, $workSites, $fs, $aIcon, $aSc. --}}
@php
    $L = fn ($en, $ms) => '<span x-text="'.e("\$store.ui.lang==='en' ? ".json_encode($en).' : '.json_encode($ms)).'">'.e($en).'</span>';
    $lbl = 'display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;';
    $canEdit = $canEditWork ?? false;
    $v = fn ($x) => filled($x) ? $x : '—';
    $d = fn ($x) => $x?->format('d/m/Y') ?? '—';
    $arr = ['office' => 'Office', 'client' => 'Client site', 'wfh' => 'Work from home', 'hybrid' => 'Hybrid'];
    $site = $p->workSite;
    $schedule = ($arr[$p->work_arrangement] ?? '—').($site && $site->work_start ? ' · '.substr($site->work_start, 0, 5).' – '.substr((string) $site->work_end, 0, 5) : '');
    $allowedIds = $p->allowedWorkSites->pluck('id')->all();
    $form = session('form');
    $sections = [
        ['Work Details', 'Butiran Kerja', [
            ['Employee ID', 'ID Pekerja', $v($p->staff_id)], ['Attendance ID', 'ID Kehadiran', $v($p->attendance_id)],
            ['Work Email', 'E-mel Kerja', $v($p->email)], ['Work Phone', 'Telefon Kerja', $v($p->work_phone)],
            ['Schedule', 'Jadual', $schedule], ['Benefit Start Date', 'Tarikh Mula Faedah', $d($p->benefit_start_at ?? $p->confirmed_at)],
        ]],
        ['Work Location', 'Lokasi Kerja', [
            ['Primary Work Location', 'Lokasi Kerja Utama', $site?->name ?? '—'],
            ['Allowed clock-in sites', 'Lokasi daftar masuk dibenarkan', $p->allowedWorkSites->isEmpty() ? 'Any configured site' : $p->allowedWorkSites->pluck('name')->join(', ')],
        ]],
    ];
@endphp

@if ($canEdit)
    <div style="display:flex;justify-content:flex-end;"><button type="button" @click="editWork = true" class="uj-btn-ghost" style="height:32px;padding:0 14px;font-size:12.5px;">{!! $L('Edit', 'Sunting') !!}</button></div>
@endif

@foreach ($sections as [$en, $ms, $rows])
    <div>
        <div class="uj-section-head" style="margin-bottom:12px;">{!! $L($en, $ms) !!}</div>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px 32px;">
            @foreach ($rows as [$ren, $rms, $val])
                <div><div style="font-size:11px;color:var(--muted);margin-bottom:2px;">{!! $L($ren, $rms) !!}</div><div style="font-size:13px;color:var(--ink);">{{ $val }}</div></div>
            @endforeach
        </div>
    </div>
@endforeach

<div>
    <div class="uj-section-head" style="margin-bottom:10px;">{!! $L('Assets', 'Aset') !!}</div>
    @forelse ($p->assets as $a)
        <div class="uj-card" x-data="{ open: {{ ($form === 'asset' && old('_asset') == $a->id) ? 'true' : 'false' }} }" style="padding:12px 14px;margin-bottom:8px;">
            <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                <span style="font-size:18px;">{{ $aIcon[$a->category] ?? '📦' }}</span>
                <div style="flex:1;min-width:0;">
                    <div style="font-size:13.5px;color:var(--ink);font-weight:500;">{{ $a->name }}</div>
                    <div style="font-size:11.5px;color:var(--muted);">{{ ucfirst($a->category) }}@if ($a->serial) · {{ $a->serial }}@endif · {!! $L('Issued', 'Dikeluarkan') !!} {{ $d($a->assigned_at) }}@if ($a->returned_at) · {!! $L('Returned', 'Dipulangkan') !!} {{ $d($a->returned_at) }}@endif @if ($a->reference_no) · Ref {{ $a->reference_no }}@endif</div>
                    @if ($a->remark)<div style="font-size:12px;color:var(--body);margin-top:2px;">{{ $a->remark }}</div>@endif
                </div>
                <span style="display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:600;color:{{ $aSc[$a->status] ?? 'var(--muted)' }};white-space:nowrap;"><span style="width:8px;height:8px;border-radius:50%;background:{{ $aSc[$a->status] ?? 'var(--muted)' }};"></span>{{ ucfirst($a->status) }}</span>
                @if ($canEdit)<button type="button" @click="open = !open" class="uj-btn-ghost" style="height:28px;padding:0 10px;font-size:12px;">{!! $L('Edit', 'Sunting') !!}</button>@endif
            </div>
            @if ($canEdit)
                <form x-show="open" x-cloak method="post" action="{{ route('assets.details', $a) }}" style="margin-top:12px;display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px 14px;align-items:end;">
                    @csrf
                    <input type="hidden" name="_asset" value="{{ $a->id }}" />
                    @if ($form === 'asset' && old('_asset') == $a->id && $errors->any())<div style="grid-column:1/-1;background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12px;border-radius:8px;padding:8px 11px;">{{ $errors->first() }}</div>@endif
                    <div><label style="{{ $lbl }}">{!! $L('Returned on', 'Tarikh pulang') !!}</label><input type="date" name="returned_at" value="{{ old('_asset') == $a->id ? old('returned_at') : $a->returned_at?->toDateString() }}" style="{{ $fs }}" /></div>
                    <div><label style="{{ $lbl }}">{!! $L('Reference No', 'No. Rujukan') !!}</label><input name="reference_no" maxlength="80" value="{{ old('_asset') == $a->id ? old('reference_no') : $a->reference_no }}" style="{{ $fs }}" /></div>
                    <div style="grid-column:1/-1;"><label style="{{ $lbl }}">{!! $L('Remark', 'Catatan') !!}</label><input name="remark" maxlength="500" value="{{ old('_asset') == $a->id ? old('remark') : $a->remark }}" style="{{ $fs }}" /></div>
                    <button type="submit" class="uj-btn-primary" style="height:36px;padding:0 16px;font-size:13px;justify-self:start;">{!! $L('Save', 'Simpan') !!}</button>
                </form>
            @endif
        </div>
    @empty
        <p style="font-size:12.5px;color:var(--muted);margin:0;">{!! $L('No assets assigned to this person.', 'Tiada aset ditugaskan kepada orang ini.') !!}</p>
    @endforelse
</div>

@if ($canEdit)
    <template x-teleport="body">
    <div x-show="editWork" x-cloak @click.self="editWork = false" @keydown.escape.window="editWork = false"
         style="position:fixed;inset:0;z-index:120;display:flex;padding:40px 16px;background:rgba(18,18,30,.42);overflow-y:auto;">
        <form method="post" action="{{ route('employees.work.update', $p) }}" class="uj-card" style="width:100%;max-width:640px;margin:auto;padding:20px;display:flex;flex-direction:column;gap:14px;max-height:calc(100vh - 80px);overflow-y:auto;">
            @csrf
            <div style="font-size:13px;font-weight:600;color:var(--ink);">{!! $L('Edit work details', 'Sunting butiran kerja') !!} · {{ $p->name }}</div>
            @if ($errors->any() && $form === 'work')<div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12px;border-radius:8px;padding:8px 11px;">{{ $errors->first() }}</div>@endif
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px 16px;">
                <div><label style="{{ $lbl }}">{!! $L('Attendance ID', 'ID Kehadiran') !!}</label><input name="attendance_id" maxlength="40" value="{{ old('attendance_id', $p->attendance_id) }}" style="{{ $fs }}" /></div>
                <div><label style="{{ $lbl }}">{!! $L('Work Phone', 'Telefon Kerja') !!}</label><input name="work_phone" maxlength="40" value="{{ old('work_phone', $p->work_phone) }}" style="{{ $fs }}" /></div>
                <div><label style="{{ $lbl }}">{!! $L('Benefit Start Date', 'Tarikh Mula Faedah') !!}</label><input type="date" name="benefit_start_at" value="{{ old('benefit_start_at', ($p->benefit_start_at ?? $p->confirmed_at)?->toDateString()) }}" style="{{ $fs }}" /></div>
                <div><label style="{{ $lbl }}">{!! $L('Primary Work Location', 'Lokasi Kerja Utama') !!}</label>
                    <select name="work_site_id" style="{{ $fs }}"><option value="">—</option>@foreach ($workSites as $s)<option value="{{ $s->id }}" @selected(old('work_site_id', $p->work_site_id) == $s->id)>{{ $s->name }}</option>@endforeach</select></div>
            </div>
            <div>
                <label style="{{ $lbl }}">{!! $L('Allowed clock-in sites (none ticked = any configured site)', 'Lokasi daftar masuk dibenarkan (tiada = mana-mana lokasi)') !!}</label>
                <input type="hidden" name="allowed_work_sites" value="" />
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:6px 14px;font-size:12.5px;color:var(--body);">
                    @foreach ($workSites as $s)
                        <label style="display:flex;align-items:center;gap:8px;"><input type="checkbox" name="allowed_work_sites[]" value="{{ $s->id }}" @checked(in_array($s->id, old('allowed_work_sites', $allowedIds)))> {{ $s->name }}</label>
                    @endforeach
                </div>
            </div>
            <div style="display:flex;gap:8px;justify-content:flex-end;">
                <button type="button" @click="editWork = false" class="uj-btn-ghost" style="height:40px;padding:0 16px;font-size:13px;">{!! $L('Cancel', 'Batal') !!}</button>
                <button type="submit" class="uj-btn-primary" style="height:40px;padding:0 18px;font-size:13px;">{!! $L('Save changes', 'Simpan perubahan') !!}</button>
            </div>
        </form>
    </div>
    </template>
@endif
```
Note on the hidden `allowed_work_sites` input: with no boxes ticked the browser still sends the key (empty string), so the controller's `$request->has()` sees it and clears the pivot. Laravel's `nullable|array` rule accepts `""` as null → `sync([])`. If the validator rejects the empty string, change the hidden input to `name="allowed_work_sites_touched" value="1"` and check that key instead.

- [ ] **Step 6: `partials/profile/attachment-tab.blade.php`**

```blade
{{-- Attachment: employee documents grouped by category, upload + delete via the Documents endpoints
     (both back() → this tab). Expects $p, $documents, $fs. --}}
@php
    $L = fn ($en, $ms) => '<span x-text="'.e("\$store.ui.lang==='en' ? ".json_encode($en).' : '.json_encode($ms)).'">'.e($en).'</span>';
    $lbl = 'display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;';
    $cats = ['Contract' => 'Kontrak', 'Certificate' => 'Sijil', 'ID' => 'Pengenalan', 'Other' => 'Lain-lain'];
    $groups = $documents->sortByDesc('created_at')->groupBy('category');
    $mine = old('_form') === 'attachment';
@endphp

<div x-data="{ add: {{ $mine && $errors->any() ? 'true' : 'false' }} }">
    <div style="display:flex;justify-content:flex-end;"><button type="button" @click="add = !add" class="uj-btn-ghost" style="height:32px;padding:0 14px;font-size:12.5px;">+ {!! $L('Upload', 'Muat naik') !!}</button></div>
    <form x-show="add" x-cloak method="post" action="{{ route('documents.store') }}" enctype="multipart/form-data" class="uj-card" style="margin-top:8px;padding:14px;display:flex;flex-direction:column;gap:10px;"
          x-data="{ over: false, name: '' }">
        @csrf
        <input type="hidden" name="_form" value="attachment" />
        <input type="hidden" name="employee_id" value="{{ $p->id }}" />
        @if ($mine && $errors->any())<div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12px;border-radius:8px;padding:8px 11px;">{{ $errors->first() }}</div>@endif
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px 14px;">
            <div><label style="{{ $lbl }}">{!! $L('Title', 'Tajuk') !!}</label><input name="title" required maxlength="160" value="{{ $mine ? old('title') : '' }}" style="{{ $fs }}" /></div>
            <div><label style="{{ $lbl }}">{!! $L('Category', 'Kategori') !!}</label><select name="category" style="{{ $fs }}">@foreach ($cats as $en => $ms)<option value="{{ $en }}" @selected(($mine ? old('category') : 'Other') === $en)>{{ $en }}</option>@endforeach</select></div>
        </div>
        <label @dragover.prevent="over = true" @dragleave="over = false" @drop.prevent="over = false; $refs.file.files = $event.dataTransfer.files; name = $refs.file.files[0]?.name ?? ''"
               :style="over ? 'border-color:var(--red);background:var(--red-tint);' : ''"
               style="display:flex;flex-direction:column;align-items:center;gap:6px;padding:22px;border:1.5px dashed var(--hairline);border-radius:10px;cursor:pointer;font-size:12.5px;color:var(--muted);">
            <input type="file" name="file" x-ref="file" required accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" @change="name = $event.target.files[0]?.name ?? ''" style="display:none;" />
            <span x-show="!name">{!! $L('Drop a file here or click to choose · PDF, JPG, PNG, DOC, DOCX up to 8 MB', 'Seret fail ke sini atau klik untuk pilih · PDF, JPG, PNG, DOC, DOCX sehingga 8 MB') !!}</span>
            <span x-show="name" x-text="name" style="color:var(--ink);font-weight:500;"></span>
        </label>
        <button type="submit" class="uj-btn-primary" style="height:36px;padding:0 16px;font-size:13px;align-self:flex-start;">{!! $L('Upload', 'Muat naik') !!}</button>
    </form>
</div>

@forelse ($groups as $cat => $docs)
    <div>
        <div class="uj-section-head" style="margin-bottom:10px;">{!! $L($cat, $cats[$cat] ?? $cat) !!}</div>
        @foreach ($docs as $doc)
            <div class="uj-row" style="display:flex;align-items:center;gap:12px;padding:10px 0;border-top:1px solid var(--hairline-soft);">
                <span style="font-size:18px;">{{ str_starts_with((string) $doc->mime, 'image/') ? '🖼️' : '📄' }}</span>
                <div style="flex:1;min-width:0;">
                    <a href="{{ route('documents.download', $doc) }}" style="font-size:13px;color:var(--ink);font-weight:500;">{{ $doc->title }}</a>
                    <div style="font-size:11.5px;color:var(--muted);">{{ $doc->original_name }} · {{ number_format($doc->size / 1024) }} KB · {{ $doc->created_at?->format('d/m/Y') }}</div>
                </div>
                <form method="post" action="{{ route('documents.destroy', $doc) }}" onsubmit="return confirm('Delete this document?')">@csrf<button type="submit" class="uj-btn-ghost" style="height:28px;padding:0 10px;font-size:12px;color:var(--red);">{!! $L('Delete', 'Padam') !!}</button></form>
            </div>
        @endforeach
    </div>
@empty
    <p style="font-size:12.5px;color:var(--muted);margin:0;">{!! $L('No documents yet.', 'Tiada dokumen lagi.') !!}</p>
@endforelse
```
`documents.destroy` already authorises owner-or-privileged, so the Delete button can show for everyone the gate lets in. Check that `documents.store` accepts the extra `_form` field (it uses `$request->validate` with a rule list, unknown keys are ignored) and that a plain employee's `employee_id` is forced to self (it is).

- [ ] **Step 7: Fix `ProfileExperienceTabTest::test_training_moved_out_of_assets_tab_into_experience`**: replace the `$assets = substr(...)` + assertion with `$this->assertStringNotContainsString("x-show=\"tab === 'assets'\"", $html);`.

- [ ] **Step 8: Run** `php artisan test --compact tests/Feature/ProfileWorkAttachmentTabsTest.php tests/Feature/ProfileExperienceTabTest.php tests/Feature/ProfilePersonalTabsTest.php tests/Feature/ProfileEmploymentTabsTest.php tests/Feature/AllScreensRenderTest.php tests/Feature/DocumentsTest.php` (if the last exists) → PASS. Pint.
- [ ] **Step 9: Commit** `git add app resources tests && git commit -m "feat(profile): Work and Attachment tabs, Assets tab folded into Work"`

---

### Task 5: Dev DB, assets, browser walk, full suite

- [ ] `lerd artisan migrate`
- [ ] `lerd artisan view:clear && lerd artisan view:cache && bun run build`; commit `public/build` if changed.
- [ ] Browser walk (headless Chromium, element screenshots of the tab card per memory `profile-screenshots-capture-tab-card`) into `~/mockups/work-attachment-walk/`: HR on emp 2 — Work tab, edit modal, save (attendance id + one allowed site), asset row edit; Attachment tab, upload a small PDF, list, delete. Employee own — Work read-only, Attachment upload visible. Manager on emp 6 — tabs `overview,work,leave` only. Revert walk data via tinker (null attendance_id/work_phone/benefit_start_at, detach allowed sites, restore asset fields, delete uploaded doc + file).
- [ ] `php artisan test --compact` full suite → green.
- [ ] Report in plain talk.

---

## Self-review

- Spec coverage: Work Details ✓ (Employee ID, Attendance ID, Work Email, Work Phone, Schedule, Benefit Start Date defaulting to confirmation date); Work Location ✓ (primary + include list pivot, geofence consults pivot Task 2); Costing skipped per spec; Assets moved ✓ with returned_at/reference_no/remark; Attachment ✓ (list, drag-drop upload, category, delete, own vs HR via existing endpoint). Tests: work fields persist ✓, geofence honours pivot ✓, asset fields round-trip ✓, attachment scoping relies on existing DocumentController tests + tab render tests.
- Deviations: upload limit stays 8 MB / DOC allowed; Assets tab removed so managers lose the profile asset list on reports; new tab id is `workinfo` because `work` is already "Work & Tasks".
- Names consistent: `WORK_FIELDS`, `allowedWorkSites()`, `employees.work.update`, `assets.details`, `workGate`, `canEditWork`, `attachmentGate`, `workSites`, flash keys `work` / `asset`, hidden `_asset` / `_form`.
