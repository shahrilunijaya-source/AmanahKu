# Live Setup Guide for the First HR — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** While a company's setup is unfinished, HR and management-tier users see a floating guide dock on every app screen that names the next Launch Center step, deep-links to it, pulses the matching sidebar item, and points a coachmark bubble at the exact button on the target screen. It advances by itself as the data appears, hides once `CompanySetupProgress.completed_at` is set, and never shows for tenants that already have staff.

**Architecture:** One new class `App\Support\SetupGuide` turns `SetupController::compute()` rows into an ordered JSON list of steps (`key`, labels, guide copy, `screen`, `url`, `done`). The app layout computes it once per request, seeds an Alpine store `guide`, and includes a new `partials.setup-guide` dock. "Current step" is computed client-side (first step not done and not in the localStorage skip list) so Skip stays browser-only, as the spec requires. The sidebar and the existing `partials.coachmark` read `$store.guide` to highlight and to show bubbles. No new tables; one data migration stamps `completed_at` for live tenants.

**Tech Stack:** Laravel 13, PHP 8.5, PHPUnit 12 (sqlite in-memory), Alpine 3, Blade with inline styles + `uj-*` classes, bilingual via `$store.ui.lang`, assets built with `bun run build`.

**Spec:** `docs/superpowers/specs/2026-09-15-self-serve-company-signup-design.md` — "Change 3: live setup guide for the first HR" and the "Guide" block under "Edge cases and decisions". Mockup: `docs/superpowers/mockups/2026-09-15-self-serve-signup/index.html` lines 164–230 (screen 4).

**Global Constraints:**
- Do not touch signup or the work week. Another plan adds a `work_week` step to `stepDefs()`; Task 2 must add `guide`/`guide_ms` to every step present when it runs, including that one if it has landed.
- No new state table. Progress is Launch Center's (`CompanySetupProgress` + data detectors); collapse, skip and last-step memory live in localStorage.
- Never block the page: dock and bubble float; the app stays usable.
- Run `vendor/bin/pint --dirty --format agent` before every commit that touches PHP.
- Tests: `php artisan test --compact tests/Feature/SetupGuideTest.php` (host PHP 8.5, sqlite; separate from the dev DB). Dev DB migration only via `lerd artisan migrate`.
- Work directly on `dev` (no feature branch). Commit after every task.
- Blade partials are asserted through rendered markup (`assertSee` / `assertDontSee` on data attributes, `false` for raw HTML).

---

### Task 1: Migration stamps `completed_at` for tenants that already have staff

**Files:**
- Create: `database/migrations/2026_09_30_000000_stamp_setup_complete_for_live_tenants.php`
- Create: `tests/Feature/SetupGuideTest.php` (first test in the new file)
- Reference: `database/migrations/2026_06_27_000003_create_company_setup_progress_table.php` lines 17–23 (columns: `id`, `tenant_id` unique FK, `steps` json nullable, `completed_at` timestamp nullable, timestamps); `app/Models/Employee.php` line 167–170 (`scopeActive` = `whereNull('archived_at')`); `app/Http/Controllers/SetupController.php` line 132 (`'staff' => Employee::active()->count() > 1` — the same "has real staff" threshold, so a freshly provisioned company whose only employee is the HR does NOT get stamped, matching the spec's "companies created by superadmin from the Companies page do get it").

**Interfaces:**
- Consumes: tables `employees(tenant_id, archived_at)`, `company_setup_progress(tenant_id, steps, completed_at, created_at, updated_at)`.
- Produces: for every `tenant_id` with more than one non-archived employee: an existing progress row with `completed_at IS NULL` gets `completed_at = now()`; a tenant with no row gets one inserted with `steps = '[]'`, `completed_at = now()`. `down()` is a no-op (stamping is not reversible without guessing).

**Steps:**

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/SetupGuideTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Http\Controllers\SetupController;
use App\Models\Branch;
use App\Models\CompanyCategory;
use App\Models\CompanySetupProgress;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Services\FeatureManager;
use App\Support\SetupGuide;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The live setup guide: the dock, the sidebar ring and the coachmark pointer that walk
 * the first HR through Launch Center until setup is finished.
 */
class SetupGuideTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0:Tenant,1:User} a fresh tenant at the given stage + its HR admin (the only employee). */
    private function company(int $level = 1): array
    {
        $category = CompanyCategory::where('level', $level)->first();
        $tenant = Tenant::create(['slug' => 'acme'.$level, 'name' => 'Acme '.$level, 'initials' => 'A'.$level, 'company_category_id' => $category->id]);
        app(FeatureManager::class)->applyCategoryPackage($tenant, $level);

        $hr = User::create(['name' => 'HR', 'email' => 'hr'.$level.'@example.com', 'password' => Hash::make('<redacted>')]);
        $hr->tenants()->attach($tenant->id, ['role' => 'hr']);
        Employee::create(['tenant_id' => $tenant->id, 'user_id' => $hr->id, 'name' => 'HR', 'status' => 'active', 'workload' => 'green', 'initials' => 'HR', 'avatar_color' => '#000', 'joined_at' => now()->toDateString()]);

        return [$tenant, $hr];
    }

    /** @return User a plain staff member of the tenant. */
    private function staff(Tenant $tenant): User
    {
        $u = User::create(['name' => 'Staff', 'email' => 'staff'.Employee::count().'@example.com', 'password' => Hash::make('<redacted>')]);
        $u->tenants()->attach($tenant->id, ['role' => 'employee']);
        Employee::create(['tenant_id' => $tenant->id, 'user_id' => $u->id, 'name' => 'Staff', 'status' => 'active', 'workload' => 'green', 'initials' => 'ST', 'avatar_color' => '#000', 'joined_at' => now()->toDateString()]);

        return $u;
    }

    private function superAdmin(): User
    {
        $u = User::create(['name' => 'Platform', 'email' => 'super@example.com', 'password' => Hash::make('<redacted>')]);
        $u->forceFill(['is_super_admin' => true])->save();

        return $u;
    }

    /** Re-run the stamping migration against whatever rows the test has set up. */
    private function runStampMigration(): void
    {
        $migration = require base_path('database/migrations/2026_09_30_000000_stamp_setup_complete_for_live_tenants.php');
        $migration->up();
    }

    // ── Migration ──────────────────────────────────────────────────────────────

    public function test_migration_stamps_tenants_with_staff_and_leaves_fresh_ones_alone(): void
    {
        [$live] = $this->company(1);
        $this->staff($live); // two active employees → "has staff"
        [$fresh] = $this->company(2); // only the HR

        // A live tenant that never had a progress row, and one that has an unfinished row.
        app(CurrentTenant::class)->set($fresh);
        CompanySetupProgress::forCurrentTenant();

        $this->runStampMigration();

        $this->assertNotNull(DB::table('company_setup_progress')->where('tenant_id', $live->id)->value('completed_at'));
        $this->assertNull(DB::table('company_setup_progress')->where('tenant_id', $fresh->id)->value('completed_at'));
    }

    public function test_migration_stamps_an_existing_unfinished_row_without_duplicating_it(): void
    {
        [$live] = $this->company(1);
        $this->staff($live);
        app(CurrentTenant::class)->set($live);
        CompanySetupProgress::forCurrentTenant()->update(['steps' => ['modules']]);

        $this->runStampMigration();

        $this->assertSame(1, DB::table('company_setup_progress')->where('tenant_id', $live->id)->count());
        $row = DB::table('company_setup_progress')->where('tenant_id', $live->id)->first();
        $this->assertNotNull($row->completed_at);
        $this->assertSame(['modules'], json_decode($row->steps, true));
    }

    public function test_migration_skips_archived_employees_when_counting_staff(): void
    {
        [$tenant] = $this->company(1);
        $this->staff($tenant);
        Employee::where('name', 'Staff')->update(['archived_at' => now()]);

        $this->runStampMigration();

        $this->assertNull(DB::table('company_setup_progress')->where('tenant_id', $tenant->id)->value('completed_at'));
    }
}
```

- [ ] **Step 2: Run the test to see it fail**

Run: `php artisan test --compact tests/Feature/SetupGuideTest.php --filter=test_migration`
Expected: 3 failures, "Failed to open stream: No such file" for the migration path.

- [ ] **Step 3: Write the migration**

Create `database/migrations/2026_09_30_000000_stamp_setup_complete_for_live_tenants.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The live setup guide (dock, sidebar ring, coachmark pointer) shows on every screen
 * while company_setup_progress.completed_at is null. Every company that already runs
 * on Amanahku — Unijaya and any live tenant — would otherwise get walked through a
 * setup it finished long ago. "Already has staff" is the same threshold the Launch
 * Center's staff step uses (more than one non-archived employee: the first HR alone
 * does not count), so a company freshly provisioned by a superadmin still gets the
 * guide. Not reversible: down() cannot know which rows it stamped.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $liveTenantIds = DB::table('employees')
            ->whereNull('archived_at')
            ->select('tenant_id')
            ->groupBy('tenant_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('tenant_id');

        if ($liveTenantIds->isEmpty()) {
            return;
        }

        DB::table('company_setup_progress')
            ->whereIn('tenant_id', $liveTenantIds)
            ->whereNull('completed_at')
            ->update(['completed_at' => $now, 'updated_at' => $now]);

        $existing = DB::table('company_setup_progress')->whereIn('tenant_id', $liveTenantIds)->pluck('tenant_id');
        $rows = $liveTenantIds->diff($existing)->map(fn ($tenantId) => [
            'tenant_id' => $tenantId,
            'steps' => '[]',
            'completed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->values()->all();

        if ($rows !== []) {
            DB::table('company_setup_progress')->insert($rows);
        }
    }

    public function down(): void
    {
        // Intentionally empty: which rows were stamped here is not recoverable.
    }
};
```

- [ ] **Step 4: Run the test to see it pass**

Run: `php artisan test --compact tests/Feature/SetupGuideTest.php --filter=test_migration`
Expected: 3 passed.

- [ ] **Step 5: Migrate the dev DB and commit**

```
lerd artisan migrate
vendor/bin/pint --dirty --format agent
git add database/migrations/2026_09_30_000000_stamp_setup_complete_for_live_tenants.php tests/Feature/SetupGuideTest.php
git commit -m "feat(setup-guide): stamp setup complete for tenants that already have staff

The live setup guide only shows while completed_at is null; without this every
live company (Unijaya included) would be walked through a setup it finished."
```

---

### Task 2: Guide copy on every Launch Center step

**Files:**
- Modify: `app/Http/Controllers/SetupController.php` lines 63 (docblock shape), 73–114 (`stepDefs()` entries)
- Test: `tests/Feature/SetupGuideTest.php`

**Interfaces:**
- Consumes: `SetupController::stepDefs()` (line 65) — returns `array<string, array{label,label_ms,desc,screen,query,auto,domain,critical}>`.
- Produces: the same array with two more string keys on **every** entry: `guide` (en) and `guide_ms`. One imperative sentence naming the screen and the button. This includes any step another plan adds later (for example `work_week`); if such a step exists in `stepDefs()` when this task runs, give it copy too — the test asserts every entry carries both keys, so a missing one fails loudly.

**Steps:**

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/SetupGuideTest.php` (inside the class, after the migration tests):

```php
    // ── Step copy ──────────────────────────────────────────────────────────────

    public function test_every_step_carries_bilingual_guide_copy(): void
    {
        [$tenant] = $this->company(2); // stage 2 → payroll on → payroll_setup present
        app(CurrentTenant::class)->set($tenant);

        foreach (app(SetupController::class)->stepDefs() as $key => $def) {
            $this->assertNotSame('', trim($def['guide'] ?? ''), "step {$key} has no guide copy");
            $this->assertNotSame('', trim($def['guide_ms'] ?? ''), "step {$key} has no guide_ms copy");
        }
    }
```

- [ ] **Step 2: Run the test to see it fail**

Run: `php artisan test --compact tests/Feature/SetupGuideTest.php --filter=test_every_step_carries_bilingual_guide_copy`
Expected: fails on `step modules has no guide copy`.

- [ ] **Step 3: Add the copy**

In `app/Http/Controllers/SetupController.php`, update the docblock at line 63:

```php
     * @return array<string, array{label:string,label_ms:string,desc:string,guide:string,guide_ms:string,screen:string,query:array<string,string>,auto:bool,domain:string,critical:bool}>
```

Then add `'guide' => …, 'guide_ms' => …` to each entry. Replace lines 73–114 with:

```php
            'modules' => ['label' => 'Enable modules', 'label_ms' => 'Aktifkan modul', 'desc' => 'Turn on the features this company uses — overtime, payroll (EPF/SOCSO), claims, timesheets and more.', 'guide' => 'Go to Company Settings, scroll to the Features card, tick the modules you use and click Save features.', 'guide_ms' => 'Pergi ke Tetapan Syarikat, skrol ke kad Ciri, tandakan modul yang anda guna dan klik Simpan ciri.', 'screen' => 'settings', 'query' => [], 'auto' => false, 'domain' => 'basics', 'critical' => false],
            'profile' => ['label' => 'Complete company profile', 'label_ms' => 'Lengkapkan profil syarikat', 'desc' => 'Logo, branding, contact details and welcome message.', 'guide' => 'On Company Settings, fill the Workspace profile card (address, contact or logo is enough) and click Save.', 'guide_ms' => 'Di Tetapan Syarikat, isi kad Profil workspace (alamat, nombor hubungan atau logo sudah memadai) dan klik Simpan.', 'screen' => 'settings', 'query' => [], 'auto' => true, 'domain' => 'basics', 'critical' => false],
            'branches' => ['label' => 'Add branches & locations', 'label_ms' => 'Tambah cawangan & lokasi', 'desc' => 'At least one branch, with its map geofence and working hours.', 'guide' => 'Go to Company Settings and click + Add on the Branches card. Give it a name and address; the map pin can wait.', 'guide_ms' => 'Pergi ke Tetapan Syarikat dan klik + Tambah pada kad Cawangan. Beri nama dan alamat; pin peta boleh ditunggu.', 'screen' => 'settings', 'query' => [], 'auto' => true, 'domain' => 'basics', 'critical' => true],
            'departments' => ['label' => 'Add departments', 'label_ms' => 'Tambah jabatan', 'desc' => 'The departments staff are organised under.', 'guide' => 'On Company Settings, click + Add on the Departments card, type a name and click Add.', 'guide_ms' => 'Di Tetapan Syarikat, klik + Tambah pada kad Jabatan, taip nama dan klik Tambah.', 'screen' => 'settings', 'query' => [], 'auto' => true, 'domain' => 'basics', 'critical' => true],
            'staff_levels' => ['label' => 'Configure staff levels', 'label_ms' => 'Konfigur tahap staf', 'desc' => 'Grade/level bands (e.g. L1–L6).', 'guide' => 'On Company Settings, add at least one level on the Staff levels card and click Save.', 'guide_ms' => 'Di Tetapan Syarikat, tambah sekurang-kurangnya satu tahap pada kad Tahap staf dan klik Simpan.', 'screen' => 'settings', 'query' => [], 'auto' => true, 'domain' => 'basics', 'critical' => false],
            'employment_types' => ['label' => 'Configure employment types', 'label_ms' => 'Konfigur jenis pekerjaan', 'desc' => 'Full-time, contract, part-time and so on.', 'guide' => 'On Company Settings, add Full-time (and any others you use) on the Employment types card and click Save.', 'guide_ms' => 'Di Tetapan Syarikat, tambah Sepenuh masa (dan jenis lain yang anda guna) pada kad Jenis pekerjaan dan klik Simpan.', 'screen' => 'settings', 'query' => [], 'auto' => true, 'domain' => 'basics', 'critical' => false],
            // Positions LAST in basics: a position ties a department + staff level + rate,
            // so those must exist first.
            'positions' => ['label' => 'Add positions', 'label_ms' => 'Tambah jawatan', 'desc' => 'Job positions and their rate bands.', 'guide' => 'Go to Positions, open the Manage bands tab, click + New band and fill in the title, department and salary band.', 'guide_ms' => 'Pergi ke Jawatan, buka tab Urus band, klik + Band baru dan isi jawatan, jabatan dan band gaji.', 'screen' => 'position', 'query' => [], 'auto' => true, 'domain' => 'basics', 'critical' => true],

            // People & access — staff FIRST: a role is assigned to a member (a login), so
            // people must exist before there is anything to assign a role to.
            'staff' => ['label' => 'Add & import staff', 'label_ms' => 'Tambah & import staf', 'desc' => 'Add employees one by one, bulk-import from CSV, and provision their logins.', 'guide' => 'Go to Add & Import Staff. Fill the Add employee form and click Add employee, or upload a CSV under Bulk import.', 'guide_ms' => 'Pergi ke Tambah & Import Staf. Isi borang Tambah pekerja dan klik Tambah pekerja, atau muat naik CSV di bawah Import pukal.', 'screen' => 'staff-load', 'query' => [], 'auto' => true, 'domain' => 'people', 'critical' => true],
            'roles' => ['label' => 'Assign roles & access', 'label_ms' => 'Tetapkan peranan & akses', 'desc' => 'Give each member a role and data scope. The five roles are built in — you assign them, not create them.', 'guide' => 'Go to Roles & Permissions, click + Add member and give at least one person the Manager or Management role.', 'guide_ms' => 'Pergi ke Peranan & Kebenaran, klik + Tambah ahli dan beri sekurang-kurangnya seorang peranan Pengurus atau Pengurusan.', 'screen' => 'roles', 'query' => [], 'auto' => true, 'domain' => 'people', 'critical' => false],
            'acl' => ['label' => 'Review permissions', 'label_ms' => 'Semak kebenaran', 'desc' => 'Confirm what each role can see and do.', 'guide' => 'On Roles & Permissions, read what each role can do, then tick this step done in Launch Center.', 'guide_ms' => 'Di Peranan & Kebenaran, baca apa yang setiap peranan boleh buat, kemudian tandakan langkah ini selesai di Pusat Pelancaran.', 'screen' => 'roles', 'query' => [], 'auto' => false, 'domain' => 'people', 'critical' => false],

            // Attendance policy (previously orphaned)
            'attendance_policy' => ['label' => 'Set attendance policy', 'label_ms' => 'Tetapkan dasar kehadiran', 'desc' => 'Client sites, work-from-home policy and per-staff work arrangements. (Branch geofences live under Branches.)', 'guide' => 'Set the late grace on Attendance Setup, then on Company Settings click Edit on a branch, click Map to drop its pin and Save.', 'guide_ms' => 'Tetapkan tempoh lewat di Persediaan Kehadiran, kemudian di Tetapan Syarikat klik Sunting pada cawangan, klik Peta untuk letak pin dan Simpan.', 'screen' => 'attendance-admin', 'query' => [], 'auto' => true, 'domain' => 'attendance', 'critical' => true],

            // Time & work (previously orphaned)
            'timesheet_categories' => ['label' => 'Set up timesheet categories', 'label_ms' => 'Sediakan kategori timesheet', 'desc' => 'The categories staff allocate time against. Projects and sub-pillars live under Workplace → Projects.', 'guide' => 'Go to Timesheet Setup, click Add category, name it and click Save.', 'guide_ms' => 'Pergi ke Persediaan Timesheet, klik Tambah kategori, beri nama dan klik Simpan.', 'screen' => 'timesheet-setup', 'query' => [], 'auto' => true, 'domain' => 'time', 'critical' => false],

            // Leave & requests
            'leave_types' => ['label' => 'Configure leave types', 'label_ms' => 'Konfigur jenis cuti', 'desc' => 'Annual, medical, unpaid and other leave types with entitlements.', 'guide' => 'Go to Leave Setup and click Load standard Malaysian set, then adjust the days to match your policy.', 'guide_ms' => 'Pergi ke Persediaan Cuti dan klik Muat set standard Malaysia, kemudian laraskan hari mengikut dasar anda.', 'screen' => 'leave-setup', 'query' => [], 'auto' => true, 'domain' => 'requests', 'critical' => true],
            // Same screen as the step above, so it needs ?tab= to open on the right one —
            // without it the holiday step drops you on the leave types tab.
            'holidays' => ['label' => 'Add public holidays', 'label_ms' => 'Tambah cuti umum', 'desc' => 'The holiday calendar leave and attendance work against.', 'guide' => 'On Leave Setup, open the Public holidays tab and click Load the Malaysian set, then check the lunar dates.', 'guide_ms' => 'Di Persediaan Cuti, buka tab Cuti umum dan klik Muat set Malaysia, kemudian semak tarikh kalendar lunar.', 'screen' => 'leave-setup', 'query' => ['tab' => 'holidays'], 'auto' => true, 'domain' => 'requests', 'critical' => false],
        ];

        // Payroll is only relevant when the module is enabled for the tenant.
        if ($this->payrollEnabled()) {
            $defs['payroll_setup'] = ['label' => 'Configure payroll', 'label_ms' => 'Konfigur gaji', 'desc' => 'Salary structures for active employees. EPF/SOCSO/EIS/PCB follow fixed published schedules.', 'guide' => 'Go to Payroll, open an employee under Salary structures and save their basic salary and statutory numbers.', 'guide_ms' => 'Pergi ke Gaji, buka pekerja di bawah Struktur gaji dan simpan gaji pokok serta nombor berkanun mereka.', 'screen' => 'payroll', 'query' => [], 'auto' => true, 'domain' => 'payroll', 'critical' => false];
        }

        // Dashboard touches — optional. Both banks are seeded for every company, so these
        // tick themselves and never hold setup back; they are here so HR can find the cards.
        $defs['greetings'] = ['label' => 'Dashboard greetings', 'label_ms' => 'Ucapan papan pemuka', 'desc' => 'The rotating greeting line at the top of everyone\'s dashboard, including the holiday-eve lines.', 'guide' => 'On Company Settings, scroll to the Dashboard greetings card to read or edit the lines. Already seeded, nothing to do.', 'guide_ms' => 'Di Tetapan Syarikat, skrol ke kad Ucapan papan pemuka untuk baca atau sunting baris. Sudah disemai, tiada yang perlu dibuat.', 'screen' => 'settings', 'query' => [], 'auto' => true, 'domain' => 'culture', 'critical' => false];
        $defs['eggs'] = ['label' => 'Dashboard easter eggs', 'label_ms' => 'Telur Paskah papan pemuka', 'desc' => 'The small one-off messages under the greeting (Friday after 5, late night, holiday eve).', 'guide' => 'On Company Settings, scroll to the Easter eggs card to read or edit them. Already seeded, nothing to do.', 'guide_ms' => 'Di Tetapan Syarikat, skrol ke kad Telur Paskah untuk baca atau sunting. Sudah disemai, tiada yang perlu dibuat.', 'screen' => 'settings', 'query' => [], 'auto' => true, 'domain' => 'culture', 'critical' => false];
        $defs['reactions'] = ['label' => 'Reactions', 'label_ms' => 'Reaksi', 'desc' => 'The emoji set people react with on posts and wins. Up to ten active.', 'guide' => 'On Company Settings, scroll to the Reactions card and pick up to ten emoji. The default set already works.', 'guide_ms' => 'Di Tetapan Syarikat, skrol ke kad Reaksi dan pilih sehingga sepuluh emoji. Set lalai sudah berfungsi.', 'screen' => 'settings', 'query' => [], 'auto' => true, 'domain' => 'culture', 'critical' => false];

        // Review & launch — always last.
        $defs['review'] = ['label' => 'Review & complete setup', 'label_ms' => 'Semak & selesai persediaan', 'desc' => 'Confirm everything is in place, then launch.', 'guide' => 'Go to Company Setup, check every step shows done, then click Complete setup at the bottom.', 'guide_ms' => 'Pergi ke Persediaan Syarikat, pastikan setiap langkah selesai, kemudian klik Selesai persediaan di bahagian bawah.', 'screen' => 'setup', 'query' => [], 'auto' => false, 'domain' => 'finish', 'critical' => false];
```

If a `work_week` step (or any other step) has been added to `stepDefs()` by another plan by the time this runs, add `'guide' => 'On Company Settings, tick the days your company works on the Work week card and click Save.', 'guide_ms' => 'Di Tetapan Syarikat, tandakan hari syarikat anda bekerja pada kad Minggu kerja dan klik Simpan.'` to it (adapt to its real screen/button). The test in Step 1 iterates every entry, so nothing can be missed.

- [ ] **Step 4: Run the test and the existing Launch Center tests**

Run: `php artisan test --compact tests/Feature/SetupGuideTest.php tests/Feature/OnboardingWizardTest.php tests/Feature/MultiTenantOnboardingTest.php`
Expected: all pass.

- [ ] **Step 5: Commit**

```
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/SetupController.php tests/Feature/SetupGuideTest.php
git commit -m "feat(setup-guide): where-to-click copy on every Launch Center step

One imperative sentence per step, en and ms, naming the screen and the button.
The live guide dock reads it; Launch Center itself is unchanged."
```

---

### Task 3: `App\Support\SetupGuide` — the ordered step list for the guide

**Files:**
- Create: `app/Support/SetupGuide.php`
- Test: `tests/Feature/SetupGuideTest.php`
- Reference: `app/Http/Controllers/SetupController.php` lines 183–233 (`compute()` returns `rows` in order with `key`, `done`, `screen`, `query`, `label`, `label_ms`, `auto`, `guide`, `guide_ms`), line 229 (`'complete' => $progress->completed_at !== null`); `app/Support/Permissions.php` line 51 (`effectiveRole('director') === 'management'`); `app/Models/User.php` line 126–130 (`roleIn()` returns `'management'` for a superadmin with no membership, so the observer seat passes the role gate on its own); `app/Http/Middleware/ResolveTenant.php` line 32 (`$request->attributes->set('tenantRole', …)`).

**Interfaces:**
- Consumes: `Request $request` (`attributes['tenantRole']`), `app(CurrentTenant::class)->get()`, `SetupController::compute()`.
- Produces:

```php
/**
 * @return array{
 *   steps: list<array{key:string,label:string,label_ms:string,guide:string,guide_ms:string,screen:string,url:string,done:bool,auto:bool}>,
 *   done: int,
 *   total: int
 * }|null
 */
public static function forRequest(Request $request): ?array
```

`null` when: no current tenant; the tenant's progress row has `completed_at` set; or `Permissions::effectiveRole(tenantRole)` is not `hr` or `management`. Otherwise the full ordered list (Launch Center order, every step, done flags included). "Current step" is NOT computed here: the browser picks the first step that is not done and not in its localStorage skip list, so Skip stays client-only. `url` is `route('app.screen', ['screen' => $screen] + $query)`, the same deep link Launch Center's non-embedded row would use.

**Steps:**

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/SetupGuideTest.php`:

```php
    // ── SetupGuide::forRequest ────────────────────────────────────────────────

    /** A request as ResolveTenant would leave it for a member with the given role. */
    private function requestAs(string $role): Request
    {
        $request = Request::create('/app/dash');
        $request->attributes->set('tenantRole', $role);

        return $request;
    }

    public function test_guide_lists_every_step_in_launch_center_order_with_done_flags(): void
    {
        [$tenant] = $this->company(1);
        app(CurrentTenant::class)->set($tenant);
        Branch::create(['tenant_id' => $tenant->id, 'name' => 'HQ']);

        $guide = SetupGuide::forRequest($this->requestAs('hr'));

        $this->assertNotNull($guide);
        $keys = array_column($guide['steps'], 'key');
        $this->assertSame(array_keys(app(SetupController::class)->stepDefs()), $keys);
        $this->assertSame('modules', $keys[0]);
        $this->assertSame('review', end($keys));

        $byKey = array_column($guide['steps'], null, 'key');
        $this->assertTrue($byKey['branches']['done']);
        $this->assertFalse($byKey['departments']['done']);
        $this->assertSame(route('app.screen', ['screen' => 'settings']), $byKey['branches']['url']);
        $this->assertSame(route('app.screen', ['screen' => 'leave-setup', 'tab' => 'holidays']), $byKey['holidays']['url']);
        $this->assertSame('Go to Company Settings and click + Add on the Branches card. Give it a name and address; the map pin can wait.', $byKey['branches']['guide']);
        $this->assertSame(count($keys), $guide['total']);
        $this->assertSame(count(array_filter($guide['steps'], fn ($s) => $s['done'])), $guide['done']);
    }

    public function test_guide_is_null_for_plain_staff_and_managers(): void
    {
        [$tenant] = $this->company(1);
        app(CurrentTenant::class)->set($tenant);

        $this->assertNull(SetupGuide::forRequest($this->requestAs('employee')));
        $this->assertNull(SetupGuide::forRequest($this->requestAs('manager')));
    }

    public function test_guide_shows_for_hr_management_and_directors(): void
    {
        [$tenant] = $this->company(1);
        app(CurrentTenant::class)->set($tenant);

        $this->assertNotNull(SetupGuide::forRequest($this->requestAs('hr')));
        $this->assertNotNull(SetupGuide::forRequest($this->requestAs('management')));
        $this->assertNotNull(SetupGuide::forRequest($this->requestAs('director')));
    }

    public function test_guide_is_null_once_setup_is_finished(): void
    {
        [$tenant] = $this->company(1);
        app(CurrentTenant::class)->set($tenant);
        CompanySetupProgress::forCurrentTenant()->update(['completed_at' => now()]);

        $this->assertNull(SetupGuide::forRequest($this->requestAs('hr')));
    }

    public function test_guide_is_null_without_a_tenant(): void
    {
        $this->assertNull(SetupGuide::forRequest($this->requestAs('hr')));
    }

    public function test_guide_drops_the_payroll_step_when_the_module_is_off(): void
    {
        [$tenant] = $this->company(2); // payroll on at stage 2
        app(CurrentTenant::class)->set($tenant);
        $this->assertContains('payroll_setup', array_column(SetupGuide::forRequest($this->requestAs('hr'))['steps'], 'key'));

        app(FeatureManager::class)->setTenant($tenant, 'module.payroll', false);

        $this->assertNotContains('payroll_setup', array_column(SetupGuide::forRequest($this->requestAs('hr'))['steps'], 'key'));
    }
```

- [ ] **Step 2: Run the tests to see them fail**

Run: `php artisan test --compact tests/Feature/SetupGuideTest.php --filter=test_guide_`
Expected: 6 errors, `Class "App\Support\SetupGuide" not found`.

- [ ] **Step 3: Create the class**

Create `app/Support/SetupGuide.php`:

```php
<?php

declare(strict_types=1);

namespace App\Support;

use App\Http\Controllers\SetupController;
use App\Models\CompanySetupProgress;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\Request;

/**
 * The live setup guide's data: Launch Center's ordered step list, surfaced to the
 * browser so the dock, the sidebar ring and the coachmark pointers can agree on
 * which step is current. No second source of truth — every flag comes from
 * SetupController::compute(). Which step is *current* is decided client-side
 * (first step not done and not skipped in this browser), so "Skip for now" never
 * touches the server.
 */
class SetupGuide
{
    /** Roles that run setup. A director collapses to management via Permissions::effectiveRole. */
    private const ROLES = ['hr', 'management'];

    /**
     * Null when the guide must not show: no tenant, setup already finished, or the
     * viewer is not HR / management tier. A superadmin browsing a tenant reads as
     * management (User::roleIn), so they see it too — intended.
     *
     * @return array{
     *   steps: list<array{key:string,label:string,label_ms:string,guide:string,guide_ms:string,screen:string,url:string,done:bool,auto:bool}>,
     *   done: int,
     *   total: int
     * }|null
     */
    public static function forRequest(Request $request): ?array
    {
        if (app(CurrentTenant::class)->get() === null) {
            return null;
        }

        $role = $request->attributes->get('tenantRole');
        if (! is_string($role) || ! in_array(Permissions::effectiveRole($role), self::ROLES, true)) {
            return null;
        }

        // Cheap check first: the full compute() runs a dozen detectors, and most
        // requests on a live company should never pay for them.
        if (CompanySetupProgress::forCurrentTenant()->completed_at !== null) {
            return null;
        }

        $computed = app(SetupController::class)->compute();

        $steps = array_map(fn (array $row) => [
            'key' => $row['key'],
            'label' => $row['label'],
            'label_ms' => $row['label_ms'],
            'guide' => $row['guide'],
            'guide_ms' => $row['guide_ms'],
            'screen' => $row['screen'],
            'url' => route('app.screen', ['screen' => $row['screen']] + $row['query']),
            'done' => $row['done'],
            'auto' => $row['auto'],
        ], $computed['rows']);

        return [
            'steps' => array_values($steps),
            'done' => $computed['done'],
            'total' => $computed['total'],
        ];
    }
}
```

- [ ] **Step 4: Run the tests to see them pass**

Run: `php artisan test --compact tests/Feature/SetupGuideTest.php`
Expected: all pass (migration + copy + 6 guide tests).

- [ ] **Step 5: Commit**

```
vendor/bin/pint --dirty --format agent
git add app/Support/SetupGuide.php tests/Feature/SetupGuideTest.php
git commit -m "feat(setup-guide): SetupGuide::forRequest builds the ordered step list

Launch Center's rows with done flags and deep links, null for staff, finished
tenants and no-tenant requests. Current step is chosen in the browser so Skip
stays client-only."
```

---

### Task 4: Coachmark `$when` option (page-view dismissal, no localStorage)

**Files:**
- Modify: `resources/views/partials/coachmark.blade.php` lines 19–48 (usage docs + `x-data` head), 111–117 (`dismiss()`), 122 (`@coach-dismissed.window` re-check)
- Test: `tests/Feature/SetupGuideTest.php`

**Interfaces:**
- Consumes: existing include params `key`, `en`, `ms`, `after`, `anchor`, `side`.
- Produces: new optional `when` — a **JS expression string** evaluated inside the bubble's Alpine scope (e.g. `"$store.guide.current === 'branches'"`). When given: `show` is a getter `! closed && (<when>)`; `dismiss()` sets `closed = true` only (no `localStorage` write), so the bubble returns on the next page view if the step is still current. When absent: behaviour is byte-for-byte the current one. The `$after` queue keeps working in both modes.

**Steps:**

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/SetupGuideTest.php`:

```php
    // ── Coachmark $when ───────────────────────────────────────────────────────

    public function test_coachmark_with_when_renders_the_expression_and_never_writes_localstorage(): void
    {
        $html = view('partials.coachmark', [
            'key' => 'guide-branches',
            'when' => "\$store.guide.current === 'branches'",
            'en' => ['title' => 'Add your first branch', 'body' => 'Click + Add.'],
        ])->render();

        $this->assertStringContainsString("get show() { return ! this.closed && (\$store.guide.current === 'branches'); }", $html);
        $this->assertStringContainsString('closed: false', $html);
        $this->assertStringNotContainsString("localStorage.setItem('amanahku-coach-guide-branches'", $html);
        $this->assertStringNotContainsString("localStorage.getItem('amanahku-coach-guide-branches')", $html);
        $this->assertStringContainsString('uj-coach-bubble', $html);
    }

    public function test_coachmark_without_when_still_remembers_dismissal_in_localstorage(): void
    {
        $html = view('partials.coachmark', [
            'key' => 'plain',
            'en' => ['title' => 'T', 'body' => 'B'],
        ])->render();

        $this->assertStringContainsString("show: localStorage.getItem('amanahku-coach-plain') !== '1'", $html);
        $this->assertStringContainsString("localStorage.setItem('amanahku-coach-plain', '1')", $html);
        $this->assertStringNotContainsString('get show()', $html);
    }
```

- [ ] **Step 2: Run the tests to see them fail**

Run: `php artisan test --compact tests/Feature/SetupGuideTest.php --filter=test_coachmark`
Expected: the `when` test fails (no `get show()`), the plain test passes.

- [ ] **Step 3: Edit the partial**

In `resources/views/partials/coachmark.blade.php`, replace lines 40–48:

```blade
    Optional $when: a JS expression evaluated in the bubble's Alpine scope, e.g.
    "$store.guide.current === 'branches'". With it the bubble shows because the
    expression is true — not because localStorage says so — and closing it hides it
    for this page view only. Used by the live setup guide: the pointer must come back
    on the next visit while the step is still current.

    Required: $key, $en. $ms, $after, $anchor, $side and $when optional; $ms falls back to English.
--}}
@php
    $ms = $ms ?? $en;
    $after = $after ?? null;
    $anchor = $anchor ?? null;
    $when = $when ?? null;
@endphp
{{-- $when is unescaped on purpose: it is developer-authored JS from the include
     site, never user input, and {{ }} would turn its quotes into &#039;. --}}
<div x-data="{
        @if ($when)
        closed: false,
        get show() { return ! this.closed && ({!! $when !!}); },
        @else
        show: localStorage.getItem('amanahku-coach-{{ $key }}') !== '1'@if ($after) && localStorage.getItem('amanahku-coach-{{ $after }}') === '1'@endif,
        @endif
```

Replace lines 111–117 (`dismiss()`):

```blade
        dismiss() {
            @if ($when)
            this.closed = true;
            @else
            this.show = false;
            localStorage.setItem('amanahku-coach-{{ $key }}', '1');
            @endif
            // Wakes any coachmark queued behind this one ($after). Without it the next
            // bubble waits for a page load the staff member has no reason to perform.
            window.dispatchEvent(new CustomEvent('coach-dismissed'));
        }
```

Replace line 122 (the `@after` re-check) so it only applies in localStorage mode:

```blade
     @if ($after && ! $when) @coach-dismissed.window="show = localStorage.getItem('amanahku-coach-{{ $key }}') !== '1' && localStorage.getItem('amanahku-coach-{{ $after }}') === '1'" @endif
```

Everything else in the file (anchor tail logic, markup, transitions) stays as is.

- [ ] **Step 4: Run the coachmark tests and the existing attendance coachmark tests**

Run: `php artisan test --compact tests/Feature/SetupGuideTest.php --filter=test_coachmark`
Run: `php artisan test --compact tests/Feature/AttendanceScreenTest.php --filter=coachmark`
Expected: all pass (existing behaviour unchanged for plain coachmarks).

- [ ] **Step 5: Commit**

```
git add resources/views/partials/coachmark.blade.php tests/Feature/SetupGuideTest.php
git commit -m "feat(coachmark): \$when option shows a bubble by expression, dismiss per page view

Needed by the live setup guide: the pointer returns on the next visit while the
step is still current, so it cannot be a one-time localStorage dismissal."
```

---

### Task 5: Alpine `guide` store, the dock partial, layout include and CSS

**Files:**
- Create: `resources/views/partials/setup-guide.blade.php`
- Modify: `resources/views/layouts/app.blade.php` line 31–32 (`@php $embed = …` block: compute `$setupGuide`), lines 300–308 (`alpine:init`: register the `guide` store next to `ui`), line 263 (include the dock after `@include('partials.welcome')`)
- Modify: `resources/css/app.css` — append after line 3801 (end of `.uj-coach-in-end`)
- Test: `tests/Feature/SetupGuideTest.php`
- Reference: `resources/css/app.css` lines 186–187 (toast host clears the phone dock with `bottom: calc(20px + var(--uj-dock-h))`), line 735–737 (`--uj-dock-h` is 0 above 900px, `61px + safe-area` below); mobile dock z-index 50 (line 762–766), toast host z-index 120.

**Interfaces:**
- Consumes: `\App\Support\SetupGuide::forRequest(request())` (Task 3); `$store.ui.lang`.
- Produces:
  - Blade var `$setupGuide` (array|null) in the layout.
  - Alpine store `guide` (always registered, empty `steps` when hidden):
    ```js
    Alpine.store('guide', {
        steps: [...],                    // ordered, from SetupGuide
        skipped: [...],                  // localStorage 'amanahku-guide-skip' (JSON list of keys)
        get step()      // first step with !done && !skipped, or null
        get current()   // step?.key ?? null   ← coachmarks and sidebar read this
        get index()     // 1-based position of step, or total when null
        get total()
        get doneCount()
        on(screens)     // true when step && screens.includes(step.screen)  ← sidebar ring
        skip()          // pushes current key to skipped + localStorage
    })
    ```
  - Layout emits `<script type="application/json" id="uj-guide-steps">` (the ordered step JSON, embed mode included) whenever `$setupGuide` is non-null; the store reads it. Dock markup root: `<div class="uj-guide" data-guide-dock :data-guide-step="$store.guide.current">`, only outside embed. Collapsed state in localStorage `amanahku-guide-collapsed` (`'1'`), last-current key in `amanahku-guide-last`.
  - CSS classes: `.uj-guide`, `.uj-guide-pill`, `.uj-guide-bar`, `.uj-guide-fill`, `.uj-guide-flash`, `.uj-sb-guide` (pulse ring, used by Task 6), `@keyframes uj-guide-pulse`.

**Steps:**

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/SetupGuideTest.php`:

```php
    // ── Dock rendering ────────────────────────────────────────────────────────

    public function test_dock_renders_for_hr_on_a_fresh_tenant_with_the_ordered_step_json(): void
    {
        [$tenant, $hr] = $this->company(1);

        $html = $this->actingAs($hr)->withSession(['current_tenant' => $tenant->id])
            ->get('/app/setup')->assertOk()->getContent();

        $this->assertStringContainsString('data-guide-dock', $html);
        $this->assertStringContainsString("Alpine.store('guide'", $html);
        $this->assertMatchesRegularExpression('/id="uj-guide-steps"[^>]*>\s*\[\{"key":"modules"/', $html);
        $this->assertStringContainsString('"key":"review"', $html);
        $this->assertStringContainsString('amanahku-guide-skip', $html);
        $this->assertStringContainsString('amanahku-guide-collapsed', $html);
        $this->assertStringContainsString('amanahku-guide-last', $html);
        // The deep link is the one Launch Center uses.
        $this->assertStringContainsString(json_encode(route('app.screen', ['screen' => 'leave-setup', 'tab' => 'holidays'])), $html);
    }

    /** Satisfy every launch-critical detector so the launch lock lets staff in (mirrors OnboardingWizardTest::launch). */
    private function launch(Tenant $tenant): void
    {
        $dept = \App\Models\Department::create(['tenant_id' => $tenant->id, 'name' => 'IT']);
        $branch = Branch::create(['tenant_id' => $tenant->id, 'name' => 'HQ']);
        $branch->forceFill(['latitude' => 3.1, 'longitude' => 101.6])->save();
        \App\Models\Position::create(['tenant_id' => $tenant->id, 'department_id' => $dept->id, 'title' => 'Developer', 'max_salary' => 5000]);
        \App\Models\LeaveType::create(['tenant_id' => $tenant->id, 'name' => 'Annual', 'entitlement' => 14]);
    }

    public function test_dock_is_not_rendered_for_plain_staff(): void
    {
        [$tenant] = $this->company(1);
        $this->launch($tenant);
        $staff = $this->staff($tenant);
        // Past the profile gate too, so a real app screen renders (not the welcome wizard).
        Employee::where('user_id', $staff->id)->update(['nric' => '900101015555', 'date_of_birth' => '1990-01-01', 'phone' => '0123456789', 'address' => '1 Jalan Test', 'emergency_contact_name' => 'Kin', 'emergency_contact_phone' => '0198887777']);

        $html = $this->actingAs($staff)->withSession(['current_tenant' => $tenant->id])
            ->get('/app/dash')->assertOk()->getContent();

        // Setup is still unfinished (HR would see the dock); staff get neither the dock
        // nor the step data, only the empty store.
        $this->assertStringNotContainsString('data-guide-dock', $html);
        $this->assertStringNotContainsString('id="uj-guide-steps"', $html);
        $this->assertStringContainsString("Alpine.store('guide'", $html);
    }

    public function test_dock_disappears_after_finish(): void
    {
        [$tenant, $hr] = $this->company(1);
        app(CurrentTenant::class)->set($tenant);
        CompanySetupProgress::forCurrentTenant()->update(['completed_at' => now()]);

        $this->actingAs($hr)->withSession(['current_tenant' => $tenant->id])
            ->get('/app/setup')->assertOk()->assertDontSee('data-guide-dock', false);
    }

    public function test_dock_is_hidden_for_a_tenant_the_migration_stamped(): void
    {
        [$tenant, $hr] = $this->company(1);
        $this->staff($tenant);
        $this->runStampMigration();

        $this->actingAs($hr)->withSession(['current_tenant' => $tenant->id])
            ->get('/app/setup')->assertOk()->assertDontSee('data-guide-dock', false);
    }

    public function test_superadmin_browsing_a_new_company_sees_the_dock(): void
    {
        [$tenant] = $this->company(1);
        $super = $this->superAdmin();

        $this->actingAs($super)->withSession(['current_tenant' => $tenant->id])
            ->get('/app/setup')->assertOk()->assertSee('data-guide-dock', false);
    }

    public function test_dock_step_list_drops_payroll_when_the_module_is_off(): void
    {
        [$tenant, $hr] = $this->company(2);
        $this->actingAs($hr)->withSession(['current_tenant' => $tenant->id])
            ->get('/app/setup')->assertOk()->assertSee('"key":"payroll_setup"', false);

        app(FeatureManager::class)->setTenant($tenant, 'module.payroll', false);

        $this->actingAs($hr)->withSession(['current_tenant' => $tenant->id])
            ->get('/app/setup')->assertOk()->assertDontSee('"key":"payroll_setup"', false);
    }

    public function test_embedded_screens_get_the_store_but_not_the_dock(): void
    {
        [$tenant, $hr] = $this->company(1);

        $html = $this->actingAs($hr)->withSession(['current_tenant' => $tenant->id])
            ->get('/app/settings?embed=1&section=branches')->assertOk()->getContent();

        $this->assertStringNotContainsString('data-guide-dock', $html);
        $this->assertStringContainsString('"key":"branches"', $html);
    }
```

- [ ] **Step 2: Run the tests to see them fail**

Run: `php artisan test --compact tests/Feature/SetupGuideTest.php --filter=dock`
Expected: all dock tests fail on the missing `data-guide-dock` / store.

- [ ] **Step 3: Compute the guide once in the layout**

In `resources/views/layouts/app.blade.php`, the `@php` block that begins at line 31 (`$embed = $embed ?? false;`) — add directly after that line:

```php
    // The live setup guide (docs/superpowers/specs/2026-09-15-self-serve-company-signup-design.md,
    // Change 3). Null for staff, for finished tenants and outside a tenant, so most
    // requests never pay for the Launch Center detectors. Computed in embed mode too:
    // the Launch Center iframes screens with ?embed=1 and the coachmark pointers inside
    // them still read $store.guide.
    $setupGuide = \App\Support\SetupGuide::forRequest(request());
```

- [ ] **Step 4: Register the store next to `ui`**

In the same file, inside the `document.addEventListener('alpine:init', () => {` block (line 300), directly after the `Alpine.store('ui', { … });` registration that ends at line 308, add:

```js
        // Live setup guide. Always registered so `$store.guide.current` is safe to read
        // from any coachmark or sidebar row; steps is empty whenever the guide is hidden.
        // Current step is chosen here, not on the server: "Skip for now" is remembered
        // per browser (amanahku-guide-skip) and must never mark anything done.
        Alpine.store('guide', {
            steps: (() => { try { return JSON.parse(document.getElementById('uj-guide-steps')?.textContent || '[]'); } catch (e) { return []; } })(),
            skipped: (() => { try { return JSON.parse(localStorage.getItem('amanahku-guide-skip') || '[]'); } catch (e) { return []; } })(),
            get step() { return this.steps.find(s => ! s.done && ! this.skipped.includes(s.key)) ?? null; },
            get current() { return this.step?.key ?? null; },
            get index() { return this.step ? this.steps.indexOf(this.step) + 1 : this.steps.length; },
            get total() { return this.steps.length; },
            get doneCount() { return this.steps.filter(s => s.done).length; },
            on(screens) { return this.step !== null && screens.includes(this.step.screen); },
            skip() {
                if (! this.step) { return; }
                this.skipped = [...this.skipped, this.step.key];
                try { localStorage.setItem('amanahku-guide-skip', JSON.stringify(this.skipped)); } catch (e) {}
            },
        });
```

The store reads its steps from a JSON `<script>` emitted by the layout (next step) rather than an inline `@js()`, so the raw JSON is greppable in tests and identical in embed mode.

- [ ] **Step 5: Emit the step JSON and include the dock partial**

In the same file, directly BEFORE the `<script>` tag that opens the block containing `document.addEventListener('alpine:init', …)` (around line 296), add — outside any `@unless ($embed)` so the Launch Center iframes get it too:

```blade
{{-- Live setup guide step list (SetupGuide::forRequest). Read by the Alpine `guide`
     store below; absent entirely when the guide is hidden. --}}
@if ($setupGuide)
<script type="application/json" id="uj-guide-steps">@json($setupGuide['steps'])</script>
@endif
```

Then, line 263 reads `@include('partials.welcome')` inside the `@unless ($embed)` block. Directly after it add:

```blade
        @if ($setupGuide)
            @include('partials.setup-guide')
        @endif
```

- [ ] **Step 6: Create the dock partial**

Create `resources/views/partials/setup-guide.blade.php`:

```blade
{{--
    Live setup guide dock — bottom-right of every app screen while the company's
    setup is unfinished (SetupGuide::forRequest returned data; the layout only
    includes this partial then). Reads $store.guide, registered in layouts/app.

    - Progress and the step list are Launch Center's. Nothing here writes state.
    - "Skip for now" only advances the dock (localStorage amanahku-guide-skip). It
      never ticks a step, the launch lock still holds staff out.
    - Collapse to a pill: localStorage amanahku-guide-collapsed.
    - "Done, next: …": the key that was current on the previous page load is kept
      in amanahku-guide-last; if that key is done on this load, flash it for a few
      seconds before showing the next step.
    - Sits above the phone dock (--uj-dock-h) and below toasts. See .uj-guide in app.css.
--}}
<div class="uj-guide"
     data-guide-dock
     x-data="{
        collapsed: localStorage.getItem('amanahku-guide-collapsed') === '1',
        flash: null,
        get g() { return $store.guide; },
        get s() { return this.g.step; },
        get en() { return $store.ui.lang === 'en'; },
        t(step, field) { return step ? (this.en ? step[field] : (step[field + '_ms'] || step[field])) : ''; },
        get doneNames() { return this.g.steps.filter(s => s.done).slice(-2).map(s => this.t(s, 'label')).join(', '); },
        get nextStep() { const i = this.g.steps.indexOf(this.s); return i >= 0 ? (this.g.steps.slice(i + 1).find(s => ! s.done && ! this.g.skipped.includes(s.key)) ?? null) : null; },
        toggle() {
            this.collapsed = ! this.collapsed;
            try { localStorage.setItem('amanahku-guide-collapsed', this.collapsed ? '1' : '0'); } catch (e) {}
        },
        init() {
            // Step-done feedback: the step that was current last time is done now.
            let last = null;
            try { last = localStorage.getItem('amanahku-guide-last'); } catch (e) {}
            const done = last ? this.g.steps.find(s => s.key === last && s.done) : null;
            if (done) {
                this.flash = done;
                setTimeout(() => { this.flash = null; }, 4000);
            }
            this.$watch('g.current', (k) => { try { k ? localStorage.setItem('amanahku-guide-last', k) : localStorage.removeItem('amanahku-guide-last'); } catch (e) {} });
            try { this.g.current ? localStorage.setItem('amanahku-guide-last', this.g.current) : localStorage.removeItem('amanahku-guide-last'); } catch (e) {}
        },
     }"
     :data-guide-step="g.current"
     x-cloak>

    {{-- Collapsed pill --}}
    <button type="button" class="uj-guide-pill" x-show="collapsed" @click="toggle()"
            :aria-label="en ? 'Open setup guide' : 'Buka panduan persediaan'">
        <span class="uj-guide-pill-dot" aria-hidden="true"></span>
        <span x-text="(en ? 'Setup' : 'Persediaan') + ' · ' + g.doneCount + '/' + g.total">Setup</span>
    </button>

    {{-- Open card --}}
    <div class="uj-guide-card" x-show="! collapsed" role="complementary"
         :aria-label="en ? 'Setup guide' : 'Panduan persediaan'">
        <div class="uj-guide-head">
            <span class="uj-guide-eyebrow"
                  x-text="s ? ((en ? 'Setting up · step ' : 'Persediaan · langkah ') + g.index + (en ? ' of ' : ' daripada ') + g.total) : (en ? 'Setting up' : 'Persediaan')">Setting up</span>
            <button type="button" class="uj-guide-x" @click="toggle()" :title="en ? 'Collapse' : 'Kecilkan'" :aria-label="en ? 'Collapse setup guide' : 'Kecilkan panduan persediaan'">&ndash;</button>
        </div>
        <div class="uj-guide-bar" aria-hidden="true"><div class="uj-guide-fill" :style="'width:' + Math.round(g.doneCount / Math.max(g.total, 1) * 100) + '%'"></div></div>

        {{-- Done, next: … --}}
        <div class="uj-guide-flash" x-show="flash" x-transition.opacity>
            <span x-text="(en ? 'Done: ' : 'Selesai: ') + t(flash, 'label') + (s ? (en ? '. Next: ' : '. Seterusnya: ') + t(s, 'label') : '')"></span>
        </div>

        <template x-if="s">
            <div>
                <div class="uj-guide-title" x-text="t(s, 'label')"></div>
                <p class="uj-guide-body" x-text="t(s, 'guide')"></p>
                <div class="uj-guide-actions">
                    <a :href="s.url" class="uj-btn-primary uj-guide-go" data-guide-go x-text="en ? 'Take me there' : 'Bawa saya ke sana'">Take me there</a>
                    <button type="button" class="uj-btn-ghost uj-guide-skip" data-guide-skip @click="g.skip()" x-text="en ? 'Skip for now' : 'Langkau dulu'">Skip for now</button>
                </div>
                <div class="uj-guide-foot" x-show="doneNames || nextStep">
                    <span x-show="doneNames" x-text="(en ? 'Done so far: ' : 'Selesai setakat ini: ') + doneNames + '.'"></span>
                    <span x-show="nextStep" x-text="' ' + (en ? 'Next: ' : 'Seterusnya: ') + t(nextStep, 'label') + '.'"></span>
                </div>
            </div>
        </template>

        {{-- Every remaining step was skipped in this browser: point at Launch Center,
             where skipped steps are still listed as outstanding. --}}
        <template x-if="! s">
            <div>
                <div class="uj-guide-title" x-text="en ? 'Nothing left to point at' : 'Tiada lagi yang perlu ditunjuk'"></div>
                <p class="uj-guide-body" x-text="en ? 'The steps you skipped are still open in Company Setup. Finish them there, then click Complete setup.' : 'Langkah yang anda langkau masih terbuka di Persediaan Syarikat. Selesaikan di sana, kemudian klik Selesai persediaan.'"></p>
                <div class="uj-guide-actions">
                    <a href="{{ route('app.screen', ['screen' => 'setup']) }}" class="uj-btn-primary uj-guide-go" data-guide-go x-text="en ? 'Open Company Setup' : 'Buka Persediaan Syarikat'">Open Company Setup</a>
                </div>
            </div>
        </template>
    </div>
</div>
```

- [ ] **Step 7: Add the CSS**

Append to `resources/css/app.css` after line 3801 (`.uj-coach-in-end { … }`):

```css
/* ── Live setup guide ────────────────────────────────────────────────────────
   The dock bottom-right of every app screen while setup is unfinished
   (partials/setup-guide). Clears the phone dock with --uj-dock-h like the toast
   host does; z-index sits above the mobile dock (50) and under toasts (120). */
.uj-guide {
    position: fixed;
    right: 16px;
    bottom: calc(16px + var(--uj-dock-h));
    z-index: 60;
    width: 320px;
    max-width: calc(100vw - 32px);
}
.uj-guide-card {
    background: var(--card, #fff);
    border: 1px solid var(--hairline);
    border-radius: 14px;
    box-shadow: 0 12px 36px rgba(0, 0, 0, .14);
    padding: 16px 18px;
}
.uj-guide-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; }
.uj-guide-eyebrow { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .6px; color: var(--red); }
.uj-guide-x { background: none; border: 0; color: var(--muted); cursor: pointer; font-size: 16px; line-height: 1; padding: 0 2px; }
.uj-guide-x:hover { color: var(--ink); }
.uj-guide-bar { height: 4px; background: var(--hairline-soft); border-radius: 9999px; margin-bottom: 12px; overflow: hidden; }
.uj-guide-fill { height: 100%; background: var(--red); transition: width .3s ease; }
.uj-guide-flash { font-size: 12.5px; font-weight: 600; color: var(--ink); background: var(--red-tint); border-radius: 8px; padding: 7px 10px; margin-bottom: 10px; }
.uj-guide-title { font-size: 14.5px; font-weight: 600; color: var(--ink); margin-bottom: 4px; }
.uj-guide-body { font-size: 13px; color: var(--muted); line-height: 1.5; margin: 0 0 14px; }
.uj-guide-actions { display: flex; gap: 8px; }
.uj-guide-go { height: 34px; flex: 1; display: inline-flex; align-items: center; justify-content: center; font-size: 12.5px; text-decoration: none; }
.uj-guide-skip { height: 34px; padding: 0 12px; font-size: 12.5px; }
.uj-guide-foot { font-size: 11.5px; color: var(--muted); margin-top: 10px; line-height: 1.45; }
/* Collapsed: a pill in the same corner reading "Setup · 3/12". */
.uj-guide-pill {
    display: inline-flex; align-items: center; gap: 7px;
    margin-left: auto; float: right;
    height: 34px; padding: 0 14px;
    background: var(--ink); color: #fff;
    border: 0; border-radius: 9999px;
    font-size: 12.5px; font-weight: 600;
    box-shadow: 0 8px 24px rgba(0, 0, 0, .18);
    cursor: pointer;
}
.uj-guide-pill-dot { width: 7px; height: 7px; border-radius: 9999px; background: var(--red); animation: uj-guide-pulse-dot 1.6s ease-in-out infinite; }
/* Sidebar ring on the nav row for the current step's screen (partials/sidebar, Task 6). */
.uj-sb-guide { box-shadow: 0 0 0 2px var(--red); animation: uj-guide-pulse 1.6s ease-in-out infinite; }
@keyframes uj-guide-pulse {
    0%, 100% { box-shadow: 0 0 0 2px var(--red); }
    50% { box-shadow: 0 0 0 5px rgba(214, 35, 43, .35); }
}
@keyframes uj-guide-pulse-dot {
    0%, 100% { opacity: 1; }
    50% { opacity: .45; }
}
@media (prefers-reduced-motion: reduce) {
    .uj-sb-guide, .uj-guide-pill-dot { animation: none; }
}
@media (max-width: 900px) {
    .uj-guide { right: 12px; bottom: calc(12px + var(--uj-dock-h)); }
    .uj-guide-card { padding: 14px 16px; }
}
```

- [ ] **Step 8: Run the whole test file**

Run: `php artisan test --compact tests/Feature/SetupGuideTest.php`
Expected: all pass. The staff test relies on `launch()` + the profile essentials to reach `/app/dash` with a 200; if it still redirects, compare against `OnboardingWizardTest::test_staff_with_essentials_reach_the_app` and match its setup exactly.

- [ ] **Step 9: Commit**

```
git add resources/views/partials/setup-guide.blade.php resources/views/layouts/app.blade.php resources/css/app.css tests/Feature/SetupGuideTest.php
git commit -m "feat(setup-guide): guide dock, Alpine guide store and layout wiring

Floating card bottom-right on every app screen while setup is unfinished: step
n of N, where-to-click copy, Take me there, Skip for now (browser-only),
collapse to a pill, and a 'Done, next' flash when the last step just ticked."
```

---

### Task 6: Sidebar pulse ring on the current step's screen

**Files:**
- Modify: `resources/views/partials/sidebar.blade.php` — sections layout: `@php` block lines 152–159, solo `<a>` line 166, section `<button>` line 177, fly leaf `<a>` lines 233–234; tree layout: `@php` block lines 266–272, solo `<a>` lines 276–277, section `<button>` lines 284–289, tree leaf `<a>` lines 348–349
- Test: `tests/Feature/SetupGuideTest.php`
- Reference: `resources/css/app.css` line 583 (`.uj-nav-row[data-on]`), `.uj-sb-guide` from Task 5; `app/Http/Controllers/Concerns/BuildsNav.php` line 30 (`$item['active'] = $item['id'] === $screen` — `id` IS the screen slug); the sidebar lives inside `#uj-shell`'s `x-data` (layout line 83), so `$store` resolves on every nav row.

**Interfaces:**
- Consumes: `$store.guide.on(screens: string[])` (Task 5).
- Produces: `:class="$store.guide.on(@js($secScreens)) ? 'uj-sb-guide' : ''"` on every section row (all screen ids in the section, children included; `@js` renders `JSON.parse('[...]')`, which is fine inside the attribute) and `:class="$store.guide.on(['<id>']) ? 'uj-sb-guide' : ''"` on every leaf link. The ring lands on the section a closed panel hides the step behind, and on the exact row once the panel opens. Grandchild links (`uj-fly-lnk` under a group, `uj-tree-deep`) are left alone: no Launch Center step lives under a group.

**Steps:**

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/SetupGuideTest.php`:

```php
    // ── Sidebar ring ──────────────────────────────────────────────────────────

    public function test_sidebar_rows_bind_the_guide_ring_to_their_screens(): void
    {
        [$tenant, $hr] = $this->company(1);

        $html = $this->actingAs($hr)->withSession(['current_tenant' => $tenant->id])
            ->get('/app/setup')->assertOk()->getContent();

        // Section rows bind the whole section's screen list (@js emits JSON.parse(...)),
        // so the ring finds the Administration row while its panel is closed.
        $this->assertMatchesRegularExpression('/uj-nav-row[^>]*:class="\$store\.guide\.on\(JSON\.parse\(/', $html);
        // Leaf rows bind their own id as a literal.
        $this->assertStringContainsString("\$store.guide.on(['settings'])", $html);
        $this->assertStringContainsString("\$store.guide.on(['setup'])", $html);
    }
```

- [ ] **Step 2: Run the test to see it fail**

Run: `php artisan test --compact tests/Feature/SetupGuideTest.php --filter=test_sidebar_rows_bind`
Expected: fails, no `$store.guide.on(` in the page.

- [ ] **Step 3: Edit the sidebar**

In `resources/views/partials/sidebar.blade.php`:

(a) Sections layout `@php` block (lines 152–159), after the `$solo = …;` line add:

```php
                    // Every screen id inside this section, children included, so the
                    // live setup guide's ring can land on the section while its panel is shut.
                    $secScreens = $items->flatMap(fn ($i) => array_merge([$i['id']], array_column($i['children'] ?? [], 'id')))->values()->all();
```

(b) Solo link (line 166) — change the opening tag to:

```blade
                    <a href="{{ route('app.screen', ['screen' => $only['id']]) }}" class="uj-nav-row"
                       :class="$store.guide.on(@js($secScreens)) ? 'uj-sb-guide' : ''"
                       @if ($secOn) data-on @endif
```

(c) Section button (line 177) — change to:

```blade
                        <button type="button" class="uj-nav-row" @click="toggle($event)"
                                :class="$store.guide.on(@js($secScreens)) ? 'uj-sb-guide' : ''"
                                :aria-expanded="fly ? 'true' : 'false'" @if ($secOn) data-on @endif>
```

(d) Fly leaf link (lines 233–234, the `@else` branch `<a … class="uj-fly-lnk"`) — change to:

```blade
                                            <a href="{{ route('app.screen', ['screen' => $item['id']]) }}" class="uj-fly-lnk"
                                               :class="$store.guide.on(['{{ $item['id'] }}']) ? 'uj-sb-guide' : ''"
                                               @if ($item['active']) data-on @endif>
```

(e) Tree layout `@php` block (lines 266–272), after `$solo = …;` add the same `$secScreens` line as (a).

(f) Tree solo link (lines 276–277):

```blade
                    <a href="{{ route('app.screen', ['screen' => $only['id']]) }}" class="uj-nav-row"
                       :class="$store.guide.on(@js($secScreens)) ? 'uj-sb-guide' : ''"
                       @mouseenter="leave()" @if ($secOn) data-on @endif>
```

(g) Tree section button (lines 284–289):

```blade
                        <button type="button" class="uj-nav-row"
                                :class="$store.guide.on(@js($secScreens)) ? 'uj-sb-guide' : ''"
                                @mouseenter="enter(@js($section))"
                                @click="toggle(@js($section))"
                                @keydown.escape="close()"
                                :aria-expanded="open === @js($section) ? 'true' : 'false'"
                                @if ($secOn) data-on @endif>
```

(h) Tree leaf link (lines 348–349):

```blade
                                    <a href="{{ route('app.screen', ['screen' => $item['id']]) }}" class="uj-tree-lnk"
                                       :class="$store.guide.on(['{{ $item['id'] }}']) ? 'uj-sb-guide' : ''"
                                       @mouseenter="leaveKid()" @if ($itemOn) data-on @endif>
```

- [ ] **Step 4: Run the test and the sidebar/nav tests**

Run: `php artisan test --compact tests/Feature/SetupGuideTest.php --filter=test_sidebar_rows_bind`
Run: `php artisan test --compact --filter=Sidebar`
Run: `php artisan test --compact --filter=Nav`
Expected: all pass.

- [ ] **Step 5: Commit**

```
git add resources/views/partials/sidebar.blade.php tests/Feature/SetupGuideTest.php
git commit -m "feat(setup-guide): pulse ring on the sidebar row for the current step

Section rows carry every screen in the section so the ring shows while the
panel is shut; leaf rows carry their own id. Bound to \$store.guide.on()."
```

---

### Task 7: On-screen coachmark pointers for the critical steps and modules

**Files:**
- Modify: `resources/views/screens/settings.blade.php` — after line 99 (`</div>` closing the Branches card header row), after line 197 (`</div>` closing the Departments header row), after line 500 (Save features button)
- Modify: `resources/views/screens/position.blade.php` — after line 162 (`</div>` closing `.uj-card-head` of "Add a position band")
- Modify: `resources/views/screens/staff-load.blade.php` — after line 39 (`</form>` of Add employee)
- Modify: `resources/views/screens/attendance-admin.blade.php` — after line 72 (`</form>` of the Lateness card)
- Modify: `resources/views/screens/leave-setup.blade.php` — after line 84 (`</form>` of Load standard Malaysian set)
- Modify: `resources/views/screens/timesheet-setup.blade.php` — after line 42 (`</button>` of the Add category toggle)
- Test: `tests/Feature/SetupGuideTest.php`
- Reference: coachmark placement rule (`resources/views/partials/coachmark.blade.php` lines 9–13): the host sits directly after the element it points at; `$anchor` (lines 30–34) slides the tail under a control found by `this.$root.parentElement.querySelector(selector)`.

**Interfaces:**
- Consumes: `partials.coachmark` with `when` (Task 4), `$store.guide.current` (Task 5).
- Produces: one include per critical step + modules, each `'key' => 'guide-<step>'`, `'when' => "$store.guide.current === '<step>'"`. Keys are namespaced `guide-` so they never collide with existing one-time coachmarks. Steps without a pointer (profile, staff_levels, employment_types, roles, acl, holidays, payroll_setup, greetings, eggs, reactions, review) rely on the dock copy — the spec lists the critical buttons "and so on"; add more later by copying any include below.

**Steps:**

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/SetupGuideTest.php`:

```php
    // ── Coachmark pointers on the target screens ──────────────────────────────

    public function test_settings_carries_pointers_for_modules_branches_and_departments(): void
    {
        [$tenant, $hr] = $this->company(1);

        $html = $this->actingAs($hr)->withSession(['current_tenant' => $tenant->id])
            ->get('/app/settings')->assertOk()->getContent();

        $this->assertStringContainsString("\$store.guide.current === 'modules'", $html);
        $this->assertStringContainsString("\$store.guide.current === 'branches'", $html);
        $this->assertStringContainsString("\$store.guide.current === 'departments'", $html);
    }

    public function test_each_critical_screen_carries_its_pointer(): void
    {
        [$tenant, $hr] = $this->company(1);
        $as = fn () => $this->actingAs($hr)->withSession(['current_tenant' => $tenant->id]);

        $as()->get('/app/position')->assertOk()->assertSee("\$store.guide.current === 'positions'", false);
        $as()->get('/app/staff-load')->assertOk()->assertSee("\$store.guide.current === 'staff'", false);
        $as()->get('/app/attendance-admin')->assertOk()->assertSee("\$store.guide.current === 'attendance_policy'", false);
        $as()->get('/app/leave-setup')->assertOk()->assertSee("\$store.guide.current === 'leave_types'", false);
        $as()->get('/app/timesheet-setup')->assertOk()->assertSee("\$store.guide.current === 'timesheet_categories'", false);
    }

    public function test_pointers_are_absent_once_setup_is_finished_and_the_store_is_empty(): void
    {
        [$tenant, $hr] = $this->company(1);
        app(CurrentTenant::class)->set($tenant);
        CompanySetupProgress::forCurrentTenant()->update(['completed_at' => now()]);

        // The include still renders (it is a static Blade include) but the store has no
        // steps, so the expression is false and the bubble never shows; what must be
        // absent is any step data that could make it true.
        $html = $this->actingAs($hr)->withSession(['current_tenant' => $tenant->id])
            ->get('/app/settings')->assertOk()->getContent();

        $this->assertStringNotContainsString('id="uj-guide-steps"', $html);
        $this->assertStringNotContainsString('"key":"branches"', $html);
    }
```

- [ ] **Step 2: Run the tests to see them fail**

Run: `php artisan test --compact tests/Feature/SetupGuideTest.php --filter=pointer`
Expected: the first two fail on the missing expressions; the third passes already.

- [ ] **Step 3: Add the includes**

`resources/views/screens/settings.blade.php`, after line 99 (the `</div>` that closes the Branches header flex row, just before `@if ($canManageFeatures)` at line 101):

```blade
            @include('partials.coachmark', [
                'key' => 'guide-branches',
                'when' => "\$store.guide.current === 'branches'",
                'anchor' => 'button.uj-btn-ghost',
                'en' => ['title' => 'Add your first branch', 'body' => 'Click + Add, give the branch a name and address, then click Add branch. The map pin can wait; it comes up in the attendance step.'],
                'ms' => ['title' => 'Tambah cawangan pertama anda', 'body' => 'Klik + Tambah, beri nama dan alamat cawangan, kemudian klik Tambah cawangan. Pin peta boleh ditunggu; ia muncul dalam langkah kehadiran.'],
            ])
```

Same file, after line 197 (the `</div>` closing the Departments header row):

```blade
            @include('partials.coachmark', [
                'key' => 'guide-departments',
                'when' => "\$store.guide.current === 'departments'",
                'anchor' => 'button.uj-btn-ghost',
                'en' => ['title' => 'Add a department', 'body' => 'Click + Add, type the department name and click Add. One is enough to start; staff are grouped under these.'],
                'ms' => ['title' => 'Tambah jabatan', 'body' => 'Klik + Tambah, taip nama jabatan dan klik Tambah. Satu sudah cukup untuk mula; staf dikumpulkan di bawah ini.'],
            ])
```

Same file, after line 500 (the Save features `<button>`; inside the `<form>`):

```blade
        @include('partials.coachmark', [
            'key' => 'guide-modules',
            'when' => "\$store.guide.current === 'modules'",
            'en' => ['title' => 'Switch on what you use', 'body' => 'Tick the modules your company runs on, then click Save features. Turning payroll on here adds the payroll step to the guide.'],
            'ms' => ['title' => 'Hidupkan yang anda guna', 'body' => 'Tandakan modul yang syarikat anda guna, kemudian klik Simpan ciri. Menghidupkan gaji di sini menambah langkah gaji ke panduan.'],
        ])
```

`resources/views/screens/position.blade.php`, after line 162 (the `</div>` closing `.uj-card-head` in the "Add a position band" card):

```blade
            @include('partials.coachmark', [
                'key' => 'guide-positions',
                'when' => "\$store.guide.current === 'positions'",
                'anchor' => 'button.uj-btn-ghost',
                'en' => ['title' => 'Add your first position band', 'body' => 'Click + New band, fill in the job title, department and salary band, then click Add position.'],
                'ms' => ['title' => 'Tambah band jawatan pertama', 'body' => 'Klik + Band baru, isi jawatan, jabatan dan band gaji, kemudian klik Tambah jawatan.'],
            ])
```

`resources/views/screens/staff-load.blade.php`, after line 39 (the `</form>` closing the Add employee form, inside the card):

```blade
    @include('partials.coachmark', [
        'key' => 'guide-staff',
        'when' => "\$store.guide.current === 'staff'",
        'anchor' => 'button[type=submit]',
        'en' => ['title' => 'Add your people', 'body' => 'Fill the form above and click Add employee, or upload a CSV under Bulk import staff below. This step ticks once someone besides you is on the books.'],
        'ms' => ['title' => 'Tambah kakitangan anda', 'body' => 'Isi borang di atas dan klik Tambah pekerja, atau muat naik CSV di bawah Import staf pukal. Langkah ini selesai apabila ada orang selain anda dalam rekod.'],
    ])
```

`resources/views/screens/attendance-admin.blade.php`, after line 72 (the `</form>` closing the Lateness card form):

```blade
    @include('partials.coachmark', [
        'key' => 'guide-attendance-policy',
        'when' => "\$store.guide.current === 'attendance_policy'",
        'anchor' => 'button[type=submit]',
        'en' => ['title' => 'Set the policy, then pin a branch', 'body' => 'Save the late grace here. The step ticks once a branch has a map pin: on Company Settings, click Edit on the branch, click Map, drop the pin and Save.'],
        'ms' => ['title' => 'Tetapkan dasar, kemudian pin cawangan', 'body' => 'Simpan tempoh lewat di sini. Langkah ini selesai apabila cawangan ada pin peta: di Tetapan Syarikat, klik Sunting pada cawangan, klik Peta, letak pin dan Simpan.'],
    ])
```

`resources/views/screens/leave-setup.blade.php`, after line 84 (the `</form>` closing the Load standard Malaysian set form, inside the empty-state card):

```blade
            @include('partials.coachmark', [
                'key' => 'guide-leave-types',
                'when' => "\$store.guide.current === 'leave_types'",
                'anchor' => 'button[type=submit]',
                'en' => ['title' => 'Load the standard set', 'body' => 'Click Load standard Malaysian set. You can change the days on each type after; one type is enough for staff to be let in.'],
                'ms' => ['title' => 'Muat set standard', 'body' => 'Klik Muat set standard Malaysia. Anda boleh ubah bilangan hari setiap jenis selepas itu; satu jenis sudah cukup untuk staf dibenarkan masuk.'],
            ])
```

`resources/views/screens/timesheet-setup.blade.php`, after line 42 (the `</button>` closing the Add category toggle, inside the card):

```blade
    @include('partials.coachmark', [
        'key' => 'guide-timesheet-categories',
        'when' => "\$store.guide.current === 'timesheet_categories'",
        'en' => ['title' => 'Add a category', 'body' => 'Click Add category, give it a name and click Save. Staff log their week against these.'],
        'ms' => ['title' => 'Tambah kategori', 'body' => 'Klik Tambah kategori, beri nama dan klik Simpan. Staf merekod minggu mereka mengikut kategori ini.'],
    ])
```

- [ ] **Step 4: Run the whole file plus the screens' own tests**

Run: `php artisan test --compact tests/Feature/SetupGuideTest.php`
Run: `php artisan test --compact tests/Feature/LeaveSetupTest.php tests/Feature/AttendanceScreenTest.php tests/Feature/FeatureToggleUiTest.php`
Expected: all pass.

- [ ] **Step 5: Commit**

```
git add resources/views/screens/settings.blade.php resources/views/screens/position.blade.php resources/views/screens/staff-load.blade.php resources/views/screens/attendance-admin.blade.php resources/views/screens/leave-setup.blade.php resources/views/screens/timesheet-setup.blade.php tests/Feature/SetupGuideTest.php
git commit -m "feat(setup-guide): coachmark pointers at the main action of each critical step

Branches, departments, modules, positions, staff, attendance policy, leave
types and timesheet categories. Shown by \$store.guide.current, closed per
page view, back on the next visit while the step is still current."
```

---

### Task 8: Build assets, full suite, browser check

**Files:**
- Modify: `public/build/**` (built output, committed)
- Reference: `CLAUDE.md` "Assets: only if Blade/CSS/JS changed, `lerd artisan view:clear && lerd artisan view:cache && bun run build`, commit `public/build`"; memory note "Build assets from a clean tree" (build in the main checkout at the committed state).

**Interfaces:**
- Consumes: the committed Blade + CSS from Tasks 4–7.
- Produces: fresh `public/build/manifest.json` and assets carrying `.uj-guide*` and `.uj-sb-guide`.

**Steps:**

- [ ] **Step 1: Confirm the tree is clean at the last commit**

Run: `git status -sb`
Expected: `## dev` with no modified files.

- [ ] **Step 2: Build**

```
lerd artisan view:clear && lerd artisan view:cache && bun run build
```
Expected: Vite completes; `grep -l "uj-sb-guide" public/build/assets/*.css` prints one file.

- [ ] **Step 3: Full suite**

Run: `php artisan test --compact`
Expected: green. Also `vendor/bin/pint --dirty --format agent` reports nothing to fix.

- [ ] **Step 4: Browser check (integrated browser MCP, http://localhost:9100)**

Log in as the Super Admin quick-login (`superadmin@amanahku.com`), switch to a tenant with no `completed_at` (create one from the Companies page if none exists — the migration stamped every tenant with staff, including Unijaya), then:
- Dashboard shows the dock bottom-right reading "Setting up · step 1 of N"; the Administration row pulses.
- "Skip for now" moves to step 2 and survives a reload; clearing `localStorage['amanahku-guide-skip']` brings step 1 back.
- "Take me there" on the branches step lands on Company Settings with the bubble under the Branches "+ Add" button; "Got it" closes it; reload brings it back.
- Add a branch: dock shows "Done: Add branches & locations. Next: Add departments." for a few seconds, then the departments step.
- Collapse to the pill; reload keeps it collapsed; click reopens.
- Resize under 900px: the dock sits above the phone tab bar.
- Log in as `shazwanshah.unijaya@gmail.com` (Employee): no dock anywhere.

- [ ] **Step 5: Commit the build**

```
git add public/build
git commit -m "build: assets for the live setup guide dock, ring and pointers"
```

---

## Self-review

- Spec coverage: dock (Task 5), sidebar ring (Task 6), on-screen pointer with `$when` (Tasks 4, 7), step-done feedback (Task 5 `flash`), bilingual copy on every step (Task 2), hidden for staff / after finish / stamped tenants, superadmin sees it, payroll step drops with the module, Skip client-only and never ticks a step, collapse per browser, phone-safe above `--uj-dock-h`, migration stamps tenants with staff (Task 1). "Take me there" uses `route('app.screen', screen + query)`, the same target Launch Center's row links to minus `embed=1`/`section=` which only exist for the iframe.
- Names used consistently: `SetupGuide::forRequest`, `$setupGuide`, store `guide` with `step`/`current`/`index`/`total`/`doneCount`/`on()`/`skip()`, localStorage keys `amanahku-guide-skip`, `amanahku-guide-collapsed`, `amanahku-guide-last`, classes `uj-guide*`, `uj-sb-guide`, coachmark keys `guide-<step>`, test file `tests/Feature/SetupGuideTest.php`, migration `2026_09_30_000000_stamp_setup_complete_for_live_tenants.php`.
- Deliberate corners: the `attendance_policy` pointer sits on Attendance Setup (the step's own screen) and tells the reader the pin lives on Company Settings → Branches → Edit → Map, because the detector is `Branch.latitude` while the Launch Center link points at attendance-admin; changing the link would break "same link Launch Center uses". Non-critical steps have no bubble; the dock copy carries them.
