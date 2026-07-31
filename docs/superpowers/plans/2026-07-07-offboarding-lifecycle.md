# Offboarding Lifecycle Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Wire Amanahku's resignation and offboarding flows into one case-centric lifecycle so every departure (resignation, termination, end-of-contract, retirement) opens a clearance case, gets auto-archived on the last day, and flags any unfinished clearance to HR.

**Architecture:** The `OffboardingCase` becomes the single departure record. A new `OffboardingService::openCase()` is the sole path that creates a case + seeds the standard checklist; it is called by the manual HR action, by resignation acknowledgement (auto-open), and by the archival self-heal. `ArchiveDepartedStaff` is rewritten to sweep cases whose `last_day` has passed — archiving via the existing `StaffArchiver` cascade, marking the case/resignation completed, and notifying HR when clearance is incomplete. Access cutoff on the last day is never blocked by an unchecked item.

**Tech Stack:** Laravel 11 (bootstrap/app.php scheduling), Blade + Alpine.js (bilingual EN/MS), PHPUnit feature tests with `RefreshDatabase`, MySQL.

**Spec:** `docs/superpowers/specs/2026-07-07-offboarding-lifecycle-design.md`

---

## File Structure

| File | Responsibility |
|------|----------------|
| `database/migrations/2026_07_07_000001_link_offboarding_cases_to_resignations.php` | Add `resignation_id` FK + `completed_at` to `offboarding_cases` |
| `app/Services/OffboardingService.php` | Single home for `openCase()` + `STANDARD_CHECKLIST` |
| `app/Models/OffboardingCase.php` | `resignation()` relation, `completed_at` cast |
| `app/Models/Resignation.php` | `offboardingCase()` relation |
| `app/Http/Controllers/OffboardingController.php` | `store()` delegates to service |
| `app/Http/Controllers/ResignationController.php` | `acknowledge()` auto-opens a case; `screenData()` eager-loads the case |
| `app/Console/Commands/ArchiveDepartedStaff.php` | Case-centric archival sweep + self-heal + HR flag |
| `resources/views/screens/offboarding.blade.php` | Archived banner, outstanding warning, from-resignation tag, read-only when completed |
| `resources/views/screens/resignation.blade.php` | Clearance indicator on the resignation card |
| `tests/Feature/OffboardingTest.php` | Auto-open + delegation tests |
| `tests/Feature/ArchiveDepartedStaffTest.php` | Case-centric archival tests |

---

### Task 1: Migration — link cases to resignations

**Files:**
- Create: `database/migrations/2026_07_07_000001_link_offboarding_cases_to_resignations.php`

- [ ] **Step 1: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links an offboarding case back to the resignation that spawned it (nullable — termination /
 * end-of-contract / retirement cases have no resignation) and records when archival closed the
 * case. Additive to 2026_06_24_000009_create_offboarding_tables.php; the status enum already
 * carries 'completed', now made live.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offboarding_cases', function (Blueprint $table) {
            $table->foreignId('resignation_id')->nullable()->after('employee_id')
                ->constrained('resignations')->nullOnDelete();
            $table->timestamp('completed_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('offboarding_cases', function (Blueprint $table) {
            $table->dropConstrainedForeignId('resignation_id');
            $table->dropColumn('completed_at');
        });
    }
};
```

- [ ] **Step 2: Run the migration**

Run: `php artisan migrate`
Expected: migration `2026_07_07_000001_link_offboarding_cases_to_resignations` runs, "DONE".

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_07_07_000001_link_offboarding_cases_to_resignations.php
git commit -m "feat(offboarding): link cases to resignations + completed_at column"
```

---

### Task 2: Model relations + casts

**Files:**
- Modify: `app/Models/OffboardingCase.php`
- Modify: `app/Models/Resignation.php`

- [ ] **Step 1: Add the `resignation` relation + `completed_at` cast to `OffboardingCase`**

Replace the `casts()` method and add the relation. Final state of the class body (imports already include `BelongsTo`, `HasMany`; `Resignation` is same-namespace so needs no import):

```php
    protected function casts(): array
    {
        return [
            'last_day' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** The resignation that spawned this case, if any (termination/EOC/retirement have none). */
    public function resignation(): BelongsTo
    {
        return $this->belongsTo(Resignation::class);
    }

    public function clearanceItems(): HasMany
    {
        return $this->hasMany(ClearanceItem::class)->orderBy('sort');
    }
```

- [ ] **Step 2: Add the inverse `offboardingCase` relation to `Resignation`**

In `app/Models/Resignation.php`, add a `HasOne` (the `HasOne` import already exists for `exitInterview`). Add after the `exitInterview()` method:

```php
    /** The exit-clearance case opened for this resignation, if any. */
    public function offboardingCase(): HasOne
    {
        return $this->hasOne(OffboardingCase::class);
    }
```

- [ ] **Step 3: Sanity-check the app still boots**

Run: `php artisan route:list --path=offboarding`
Expected: the two offboarding routes list without error (models load cleanly).

- [ ] **Step 4: Commit**

```bash
git add app/Models/OffboardingCase.php app/Models/Resignation.php
git commit -m "feat(offboarding): case<->resignation relations + completed_at cast"
```

---

### Task 3: OffboardingService (TDD)

**Files:**
- Create: `app/Services/OffboardingService.php`
- Test: `tests/Feature/OffboardingTest.php` (add cases)

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/OffboardingTest.php`. First add the import at the top with the other `use` lines:

```php
use App\Models\Resignation;
use App\Services\OffboardingService;
```

Then add these methods to the class:

```php
    public function test_open_case_creates_a_case_and_seeds_the_standard_checklist(): void
    {
        app(\App\Tenancy\CurrentTenant::class)->set($this->tenant);

        $case = app(OffboardingService::class)->openCase(
            $this->employee, now()->addDays(14)->toDateString(), 'termination',
        );

        $this->assertSame('in_progress', $case->status);
        $this->assertNull($case->resignation_id);
        $this->assertSame(count(OffboardingService::STANDARD_CHECKLIST), $case->clearanceItems()->count());
    }

    public function test_open_case_links_an_existing_unlinked_case_instead_of_duplicating(): void
    {
        app(\App\Tenancy\CurrentTenant::class)->set($this->tenant);

        $existing = OffboardingCase::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'last_day' => now()->addDays(20)->toDateString(), 'reason' => 'termination', 'status' => 'in_progress',
        ]);
        $resignation = Resignation::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'submitted_at' => now(), 'last_working_date' => now()->addDays(30)->toDateString(),
            'notice_days' => 30, 'reason' => 'Growth', 'status' => 'acknowledged',
        ]);

        $case = app(OffboardingService::class)->openCase(
            $this->employee, $resignation->last_working_date, 'resignation', null, $resignation,
        );

        $this->assertSame($existing->id, $case->id);
        $this->assertSame($resignation->id, $case->fresh()->resignation_id);
        $this->assertSame(1, OffboardingCase::where('employee_id', $this->employee->id)->count());
    }

    public function test_open_case_is_idempotent_for_the_same_resignation(): void
    {
        app(\App\Tenancy\CurrentTenant::class)->set($this->tenant);

        $resignation = Resignation::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'submitted_at' => now(), 'last_working_date' => now()->addDays(30)->toDateString(),
            'notice_days' => 30, 'reason' => 'Growth', 'status' => 'acknowledged',
        ]);
        $svc = app(OffboardingService::class);

        $first = $svc->openCase($this->employee, $resignation->last_working_date, 'resignation', null, $resignation);
        $second = $svc->openCase($this->employee, $resignation->last_working_date, 'resignation', null, $resignation);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, OffboardingCase::where('resignation_id', $resignation->id)->count());
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=OffboardingTest`
Expected: FAIL — `Class "App\Services\OffboardingService" not found`.

- [ ] **Step 3: Write the service**

Create `app/Services/OffboardingService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\OffboardingCase;
use App\Models\Resignation;
use Illuminate\Support\Carbon;

/**
 * Opens exit-clearance cases and seeds their standard checklist. The single path shared by the
 * HR "open case" action (OffboardingController::store), resignation acknowledgement
 * (ResignationController::acknowledge), and the archival self-heal (ArchiveDepartedStaff) — so
 * every departure, resignation or not, flows through one case. Caller owns authorization; the
 * BelongsToTenant trait fills tenant_id from the active CurrentTenant on create.
 */
class OffboardingService
{
    /**
     * The standard clearance checklist seeded with each new case: [department, title].
     *
     * @var list<array{0:string,1:string}>
     */
    public const STANDARD_CHECKLIST = [
        ['IT', 'Revoke system & email access'],
        ['IT', 'Collect laptop & devices'],
        ['HR', 'Conduct exit interview'],
        ['HR', 'Process final documentation'],
        ['Finance', 'Settle final salary & claims'],
        ['Finance', 'Recover company advances'],
        ['Manager', 'Knowledge handover sign-off'],
        ['Admin', 'Collect access card & keys'],
    ];

    /**
     * Open (or reuse) the employee's in-progress exit-clearance case. Idempotent:
     *  - a case already linked to $resignation is returned untouched;
     *  - an existing UNLINKED in-progress case for the employee is linked to $resignation and
     *    re-dated rather than duplicated;
     *  - otherwise a fresh case is created and the standard checklist seeded.
     */
    public function openCase(
        Employee $employee,
        Carbon|string $lastDay,
        string $reason,
        ?string $notes = null,
        ?Resignation $resignation = null,
    ): OffboardingCase {
        if ($resignation) {
            $linked = OffboardingCase::where('resignation_id', $resignation->id)->first();
            if ($linked) {
                return $linked;
            }
        }

        $existing = OffboardingCase::where('employee_id', $employee->id)
            ->where('status', 'in_progress')
            ->whereNull('resignation_id')
            ->first();

        if ($existing) {
            if ($resignation) {
                $existing->update([
                    'resignation_id' => $resignation->id,
                    'last_day' => $lastDay,
                    'reason' => $reason,
                ]);
            }

            return $existing;
        }

        $case = OffboardingCase::create([
            'employee_id' => $employee->id,
            'resignation_id' => $resignation?->id,
            'last_day' => $lastDay,
            'reason' => $reason,
            'status' => 'in_progress',
            'notes' => $notes,
        ]);

        foreach (self::STANDARD_CHECKLIST as $i => [$department, $title]) {
            $case->clearanceItems()->create([
                'department' => $department,
                'title' => $title,
                'done' => false,
                'sort' => $i,
            ]);
        }

        AuditLog::record('Opened offboarding', $employee->name);

        return $case;
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --filter=OffboardingTest`
Expected: PASS (all offboarding tests, old + 3 new).

- [ ] **Step 5: Commit**

```bash
git add app/Services/OffboardingService.php tests/Feature/OffboardingTest.php
git commit -m "feat(offboarding): OffboardingService.openCase single seeding path"
```

---

### Task 4: OffboardingController::store delegates to the service

**Files:**
- Modify: `app/Http/Controllers/OffboardingController.php`

- [ ] **Step 1: Confirm the existing store tests pass (baseline)**

Run: `php artisan test --filter=OffboardingTest`
Expected: PASS (this task must not change behavior).

- [ ] **Step 2: Replace the `STANDARD_CHECKLIST` constant + `store()` body**

Remove the `STANDARD_CHECKLIST` const (lines 23-38) — it now lives on the service. Replace the `store()` method so it delegates. Add `use App\Services\OffboardingService;` to the imports. New `store()`:

```php
    /** Privileged-only: open an exit-clearance case and seed its standard checklist. */
    public function store(Request $request, OffboardingService $offboarding): RedirectResponse
    {
        $this->authorizePrivileged($request);
        $tenantId = app(CurrentTenant::class)->id();

        $data = $request->validate([
            'employee_id' => ['required', 'integer', Rule::exists('employees', 'id')->where('tenant_id', $tenantId)],
            'last_day' => ['required', 'date'],
            'reason' => ['required', Rule::in(self::REASONS)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $employee = Employee::findOrFail($data['employee_id']);
        $offboarding->openCase($employee, $data['last_day'], $data['reason'], $data['notes'] ?? null);

        return back()->with('ok', 'Offboarding case opened.');
    }
```

Note: `OffboardingCase` may now be unused in this controller's imports — leave the import only if still referenced by `toggleItem` (it is, via `OffboardingCase::find`). Keep it.

- [ ] **Step 3: Run the tests to verify they still pass**

Run: `php artisan test --filter=OffboardingTest`
Expected: PASS — `test_privileged_user_opens_an_offboarding_case` still creates the case + checklist through the service.

- [ ] **Step 4: Commit**

```bash
git add app/Http/Controllers/OffboardingController.php
git commit -m "refactor(offboarding): store() delegates to OffboardingService"
```

---

### Task 5: Resignation acknowledge auto-opens a case (TDD)

**Files:**
- Modify: `app/Http/Controllers/ResignationController.php`
- Test: `tests/Feature/OffboardingTest.php` (add cases)

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/OffboardingTest.php`:

```php
    public function test_acknowledging_a_resignation_auto_opens_a_linked_prefilled_case(): void
    {
        $resignation = Resignation::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'submitted_at' => now(), 'last_working_date' => now()->addDays(30)->toDateString(),
            'notice_days' => 30, 'reason' => 'Growth', 'status' => 'submitted',
        ]);

        $this->actingAs($this->hrActor())->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/resignation/{$resignation->id}/acknowledge")->assertRedirect();

        $case = OffboardingCase::where('resignation_id', $resignation->id)->first();
        $this->assertNotNull($case);
        $this->assertSame($this->employee->id, $case->employee_id);
        $this->assertSame('resignation', $case->reason);
        $this->assertSame(now()->addDays(30)->format('Y-m-d'), $case->last_day->format('Y-m-d'));
        $this->assertGreaterThan(0, $case->clearanceItems()->count());
    }

    public function test_acknowledge_links_an_existing_unlinked_case_rather_than_duplicating(): void
    {
        $existing = OffboardingCase::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'last_day' => now()->addDays(20)->toDateString(), 'reason' => 'termination', 'status' => 'in_progress',
        ]);
        $resignation = Resignation::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'submitted_at' => now(), 'last_working_date' => now()->addDays(30)->toDateString(),
            'notice_days' => 30, 'reason' => 'Growth', 'status' => 'submitted',
        ]);

        $this->actingAs($this->hrActor())->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/resignation/{$resignation->id}/acknowledge")->assertRedirect();

        $this->assertSame(1, OffboardingCase::where('employee_id', $this->employee->id)->count());
        $this->assertSame($resignation->id, $existing->fresh()->resignation_id);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=OffboardingTest`
Expected: FAIL — no case is created on acknowledge (`assertNotNull($case)` fails).

- [ ] **Step 3: Wire acknowledge to the service**

In `app/Http/Controllers/ResignationController.php`, add `use App\Services\OffboardingService;` to the imports. Change the `acknowledge()` signature to inject the service and call `openCase()` after the notification:

```php
    /** Privileged-only: acknowledge a pending resignation (submitted -> acknowledged) + open its clearance case. */
    public function acknowledge(Request $request, Resignation $resignation, OffboardingService $offboarding): RedirectResponse
    {
        $this->authorizePrivileged($request, $resignation);
        abort_unless($resignation->status === 'submitted', 422);

        $resignation->update([
            'status' => 'acknowledged',
            'acknowledged_at' => now(),
            'acknowledged_by_id' => $request->attributes->get('employee')?->id,
        ]);

        AuditLog::record('Acknowledged resignation', $resignation->employee?->name);
        AppNotification::send(
            $resignation->employee?->user_id,
            'Resignation acknowledged',
            'Your resignation has been acknowledged by HR.',
            route('app.screen', 'resignation'),
        );

        if ($resignation->employee) {
            $offboarding->openCase(
                $resignation->employee,
                $resignation->last_working_date,
                'resignation',
                'Auto-opened from resignation.',
                $resignation,
            );
        }

        return back()->with('ok', 'Resignation acknowledged.');
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --filter=OffboardingTest`
Expected: PASS.

- [ ] **Step 5: Confirm resignation tests are unaffected**

Run: `php artisan test --filter=ResignationTest`
Expected: PASS (all 7 existing).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/ResignationController.php tests/Feature/OffboardingTest.php
git commit -m "feat(offboarding): acknowledging a resignation auto-opens a clearance case"
```

---

### Task 6: Case-centric archival + self-heal + HR flag (TDD)

**Files:**
- Modify: `app/Console/Commands/ArchiveDepartedStaff.php`
- Test: `tests/Feature/ArchiveDepartedStaffTest.php`

- [ ] **Step 1: Add the new failing tests**

Add these imports to `tests/Feature/ArchiveDepartedStaffTest.php` (with the existing `use` lines):

```php
use App\Models\OffboardingCase;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
```

Add these test methods to the class (keep the existing 4 — they still pass via self-heal):

```php
    private function caseFor(Employee $e, string $lastDay, string $reason, string $status = 'in_progress'): OffboardingCase
    {
        return OffboardingCase::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $e->id,
            'last_day' => $lastDay, 'reason' => $reason, 'status' => $status,
        ]);
    }

    public function test_it_archives_a_termination_case_with_no_resignation(): void
    {
        $leaver = $this->emp('Terminated');
        $case = $this->caseFor($leaver, now()->subDay()->toDateString(), 'termination');
        $case->clearanceItems()->create(['department' => 'IT', 'title' => 'Revoke access', 'done' => true, 'sort' => 0]);

        $this->artisan('staff:archive-departed')->assertExitCode(0);

        $this->assertNotNull($leaver->fresh()->archived_at);
        $case->refresh();
        $this->assertSame('completed', $case->status);
        $this->assertNotNull($case->completed_at);
    }

    public function test_it_marks_the_linked_resignation_completed(): void
    {
        $leaver = $this->emp('Resigned');
        $r = $this->resignation($leaver, now()->subDay()->toDateString());
        $case = $this->caseFor($leaver, now()->subDay()->toDateString(), 'resignation');
        $case->update(['resignation_id' => $r->id]);

        $this->artisan('staff:archive-departed')->assertExitCode(0);

        $this->assertSame('completed', $r->fresh()->status);
        $this->assertSame('completed', $case->fresh()->status);
    }

    public function test_it_flags_outstanding_clearance_on_archival(): void
    {
        $hr = User::create(['name' => 'HR', 'email' => 'hr@acme.test', 'password' => Hash::make('password')]);
        $hr->tenants()->attach($this->tenant->id, ['role' => 'hr']);

        $leaver = $this->emp('Half Cleared');
        $case = $this->caseFor($leaver, now()->subDay()->toDateString(), 'termination');
        $case->clearanceItems()->create(['department' => 'IT', 'title' => 'Collect laptop', 'done' => false, 'sort' => 0]);
        $case->clearanceItems()->create(['department' => 'HR', 'title' => 'Final docs', 'done' => true, 'sort' => 1]);

        $this->artisan('staff:archive-departed')->assertExitCode(0);

        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $hr->id,
            'title' => 'Offboarding: clearance outstanding',
        ]);
    }

    public function test_it_self_heals_a_legacy_acknowledged_resignation_with_no_case(): void
    {
        $leaver = $this->emp('Legacy Leaver');
        $r = $this->resignation($leaver, now()->subDay()->toDateString()); // acknowledged, no case

        $this->artisan('staff:archive-departed')->assertExitCode(0);

        $this->assertNotNull($leaver->fresh()->archived_at);
        $this->assertSame('completed', $r->fresh()->status);
        $this->assertNotNull($r->fresh()->offboardingCase); // a case was opened for it
    }
```

- [ ] **Step 2: Run the tests to verify the new ones fail**

Run: `php artisan test --filter=ArchiveDepartedStaffTest`
Expected: the 4 new tests FAIL (e.g. `completed_at` is null / no case created / no notification), existing 4 still pass.

- [ ] **Step 3: Rewrite the command**

Replace the body of `app/Console/Commands/ArchiveDepartedStaff.php` with the case-centric sweep. Full file:

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\OffboardingCase;
use App\Models\Resignation;
use App\Models\Tenant;
use App\Services\OffboardingService;
use App\Services\StaffArchiver;
use App\Tenancy\CurrentTenant;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Auto-archive staff whose offboarding last working day has passed.
 *
 * The offboarding case is the departure record. Any in-progress case with a last_day strictly
 * before today means the person has left — so we archive them (full detach cascade via
 * StaffArchiver: reports move up-chain, pivots drop, open tasks pass to their manager, pending
 * requests close, login can no longer act), close the case, and close a linked resignation.
 * This covers EVERY reason (resignation, termination, end-of-contract, retirement), not just
 * resignations. Strictly before-today so a person is never detached on a day they still work.
 *
 * Self-heal: a legacy acknowledged resignation past its last day with no case (acknowledged
 * before auto-open existed) gets a case opened first, so no leaver is ever missed.
 *
 * Clearance is NEVER a gate — access cutoff must not wait on a forgotten checkbox. Instead a
 * case archived with unchecked items raises an HR notification + audit flag.
 *
 * Idempotent: an already-archived person is skipped by StaffArchiver, but their case/resignation
 * are still closed, so a re-run never double-processes.
 */
class ArchiveDepartedStaff extends Command
{
    protected $signature = 'staff:archive-departed';

    protected $description = 'Archive + detach staff whose offboarding last working day has passed.';

    public function handle(CurrentTenant $context, StaffArchiver $archiver, OffboardingService $offboarding): int
    {
        $today = Carbon::today()->toDateString();
        $archivedCount = 0;

        foreach (Tenant::query()->orderBy('id')->get() as $tenant) {
            $context->set($tenant);

            try {
                // Self-heal: legacy acknowledged resignations past their last day with no case.
                $caseless = Resignation::query()
                    ->where('status', 'acknowledged')
                    ->whereDate('last_working_date', '<', $today)
                    ->whereDoesntHave('offboardingCase')
                    ->with('employee')
                    ->get();

                foreach ($caseless as $resignation) {
                    if ($resignation->employee) {
                        $offboarding->openCase(
                            $resignation->employee,
                            $resignation->last_working_date,
                            'resignation',
                            'Auto-opened from resignation.',
                            $resignation,
                        );
                    }
                }

                // Primary sweep: every in-progress case whose last day has passed — all reasons.
                $due = OffboardingCase::query()
                    ->where('status', 'in_progress')
                    ->whereDate('last_day', '<', $today)
                    ->with(['employee', 'clearanceItems', 'resignation'])
                    ->get();

                foreach ($due as $case) {
                    $employee = $case->employee;
                    if (! $employee) {
                        continue;
                    }

                    if ($archiver->archive($employee)) {
                        $archivedCount++;
                    }

                    $case->update(['status' => 'completed', 'completed_at' => now()]);

                    if ($case->resignation && $case->resignation->status === 'acknowledged') {
                        $case->resignation->update(['status' => 'completed']);
                    }

                    $outstanding = $case->clearanceItems->where('done', false)->count();
                    if ($outstanding > 0) {
                        $this->flagOutstanding($tenant, $employee, $outstanding);
                    }
                }
            } catch (\Throwable $e) {
                // Per-tenant isolation (AK-REL-04): one tenant's failure must not abort the rest.
                report($e);
                $this->error("Tenant {$tenant->id}: {$e->getMessage()}");
            }
        }

        $context->set(null);

        $this->info("Archived {$archivedCount} departed staff.");

        return self::SUCCESS;
    }

    /** Notify management/HR + audit that a person was archived with clearance still open. */
    private function flagOutstanding(Tenant $tenant, Employee $employee, int $outstanding): void
    {
        $userIds = $tenant->users()->wherePivotIn('role', ['management', 'hr'])->pluck('users.id');

        AppNotification::sendMany(
            $userIds,
            'Offboarding: clearance outstanding',
            "{$employee->name} was archived with {$outstanding} clearance item(s) still open.",
            route('app.screen', 'offboarding'),
        );

        AuditLog::record('Archived with outstanding clearance', "{$employee->name} · {$outstanding} open");
    }
}
```

- [ ] **Step 4: Run the full archival suite**

Run: `php artisan test --filter=ArchiveDepartedStaffTest`
Expected: PASS — all 8 (4 existing + 4 new).

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/ArchiveDepartedStaff.php tests/Feature/ArchiveDepartedStaffTest.php
git commit -m "feat(offboarding): case-centric archival with self-heal + HR clearance flag"
```

---

### Task 7: UI surfacing

**Files:**
- Modify: `resources/views/screens/offboarding.blade.php`
- Modify: `resources/views/screens/resignation.blade.php`
- Modify: `app/Http/Controllers/ResignationController.php` (eager-load the case)

- [ ] **Step 1: Eager-load the case on the resignation screen**

In `ResignationController::screenData`, change the `$allResignations` query to load the case + its items:

```php
        $allResignations = $privileged
            ? Resignation::with(['employee', 'exitInterview', 'offboardingCase.clearanceItems'])->latest('id')->get()
            : new Collection;
```

- [ ] **Step 2: Add the clearance indicator to each resignation card**

In `resources/views/screens/resignation.blade.php`, inside the name column, insert immediately AFTER the muted position/last-day line (the `<div style="font-size:12px;color:var(--muted);">…d notice</div>` at line ~96), still inside `<div style="flex:1;min-width:0;">`:

```blade
                    @if ($r->offboardingCase)
                        @php $oc = $r->offboardingCase; $ocCleared = $oc->clearanceItems->where('done', true)->count(); $ocTotal = $oc->clearanceItems->count(); @endphp
                        <div style="font-size:11.5px;color:var(--muted);margin-top:2px;"><span x-text="$store.ui.lang==='en' ? 'Clearance' : 'Pelepasan'">Clearance</span> <span style="font-family:var(--font-mono);color:var(--ink);">{{ $ocCleared }}/{{ $ocTotal }}</span></div>
                    @endif
```

- [ ] **Step 3: Add completed/outstanding state to the offboarding case header**

In `resources/views/screens/offboarding.blade.php`, extend the `@php` block (lines 59-68) — add these three lines before `@endphp`:

```php
        $isCompleted = $case->status === 'completed';
        $outstanding = $totalItems - $cleared;
        $fromResignation = (bool) $case->resignation_id;
```

Add the "From resignation" tag inside the position/last-day line — append it just before the closing `</p>` of the `<p style="font-size:12.5px;color:var(--muted);…">` at line 76:

```blade
@if ($fromResignation) · <span style="font-size:10.5px;font-weight:600;color:var(--muted);background:var(--paper);border:1px solid var(--hairline);border-radius:999px;padding:1px 7px;"><span x-text="$store.ui.lang==='en' ? 'From resignation' : 'Dari perletakan'">From resignation</span></span>@endif
```

Add the archived banner immediately AFTER the header `uj-card` div closes (after line 87, before the `<div style="display:flex;gap:16px;…">` department row):

```blade
    @if ($isCompleted)
        <div class="uj-card" style="padding:14px 18px;margin-bottom:16px;border-left:3px solid {{ $outstanding > 0 ? 'var(--red)' : 'var(--muted)' }};">
            <div style="font-size:13px;font-weight:600;color:var(--ink);">
                <span x-text="$store.ui.lang==='en' ? 'Departed · archived' : 'Telah keluar · diarkib'">Departed · archived</span>@if ($case->completed_at) {{ $case->completed_at->format('j M Y') }}@endif
            </div>
            @if ($outstanding > 0)
                <div style="font-size:12px;color:var(--red);margin-top:4px;"><span x-text="$store.ui.lang==='en' ? @json($outstanding.' item(s) were outstanding at archival') : @json($outstanding.' item belum selesai semasa diarkib')">{{ $outstanding }} item(s) were outstanding at archival</span></div>
            @endif
        </div>
    @endif
```

- [ ] **Step 4: Make clearance items read-only once the case is completed**

In `resources/views/screens/offboarding.blade.php`, change the toggle gate at line 96 from `@if ($privileged)` to `@if ($privileged && ! $isCompleted)`. Completed cases then fall through to the existing read-only checkbox branch (`@else` at line 102), so the items show final state without a toggle form.

- [ ] **Step 5: Verify the screens render**

Run: `php artisan test --filter=AllScreensRenderTest`
Expected: PASS (offboarding + resignation screens still render for their roles).

If `AllScreensRenderTest` does not cover these, manually verify: `pm2 restart amanahku-8888`, then load `/app/offboarding` and `/app/resignation` as an HR user — no Blade errors, clearance line shows on acknowledged resignations, and a completed case shows the "Departed · archived" banner.

- [ ] **Step 6: Commit**

```bash
git add resources/views/screens/offboarding.blade.php resources/views/screens/resignation.blade.php app/Http/Controllers/ResignationController.php
git commit -m "feat(offboarding): UI for archived state, outstanding flag, clearance link"
```

---

### Task 8: Full suite + finalize

**Files:** none (verification)

- [ ] **Step 1: Run the whole feature test suite**

Run: `php artisan test`
Expected: PASS — all green, including `OffboardingTest`, `ResignationTest`, `ArchiveDepartedStaffTest`, `AllScreensRenderTest`.

- [ ] **Step 2: If any regression, fix it before proceeding.** Re-run `php artisan test` until green.

- [ ] **Step 3: Update project memory**

Update `C:\Users\User\.claude\projects\c--Users-User-Desktop-Claude-ClaudeCode-Aril-ProjectAI-SpecialProject-AmanahKu\memory\amanahku-project.md` — mark offboarding as built (no longer a stub) and note the case-centric lifecycle. Add a new memory file `offboarding-lifecycle.md` capturing: case = departure record; acknowledge auto-opens a linked case; `ArchiveDepartedStaff` sweeps cases (all reasons) + self-heals legacy resignations; clearance never gates archival but flags HR. Add its one-line pointer to `MEMORY.md`.

- [ ] **Step 4: Final commit (if memory lives in-repo, skip — memory is outside the repo, so nothing to commit here).**

Confirm `git status` is clean for the repo.

---

## Self-Review Notes

- **Spec coverage:** §Data model → Task 1; §OffboardingService → Task 3; §B2 store → Task 4; §B3 acknowledge → Task 5; §B4 archival + self-heal + flag → Task 6; §B5 derived clearance (toggleItem unchanged) → not touched (correct); §B6 UI → Task 7; §Testing → Tasks 3/5/6/7; §Files touched → all mapped.
- **Type consistency:** `openCase(Employee, Carbon|string, string, ?string, ?Resignation)` used identically in Tasks 3/4/5/6. `offboardingCase()` relation (Task 2) used by `whereDoesntHave('offboardingCase')` and `$r->offboardingCase` (Tasks 6/7). `resignation()` relation (Task 2) used by `$case->resignation` (Task 6) and `$case->resignation_id` (Task 7).
- **Clearance-100%-does-not-complete:** intentionally there is NO task that flips status on toggle — `completed` is set only by archival (Task 6), matching spec §B5.
