# Employment Tab, Timeline Tab & Progression Screen — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** HR can record hire / confirmation / update / resignation / rehire for a staff member, see the full employment record on the profile, and read an auto-generated timeline of every change.

**Architecture:** One append-only `employee_progressions` table plus new employment columns on `employees`. One service (`EmploymentRecordService`) owns every transition and writes the timeline row; the profile's Employment edit modal and the new Progression screen both call it. Timeline tab renders the rows. Old `career_timeline` table goes away.

**Tech Stack:** Laravel 13, PHP 8.5, Blade + Alpine, PHPUnit (sqlite), Pint. Dev DB migrated only via `lerd artisan migrate`.

**Spec:** `docs/superpowers/specs/2026-09-15-employee-record-and-progression-design.md`, section 1 and "Shared rules".

## Global Constraints

- Edit roles: `hr`, `management`, `director` (use `$this->hasTenantRole($request, ['management', 'hr'])`; director collapses to management inside `hasTenantRole`). Salary read/write: `$this->hasTenantRole($request, ['director', 'hr'])` only.
- `manager` and `employee` roles get 403 on the Progression screen. On the profile, `manager` sees neither new tab; an employee sees their own two tabs with salary hidden.
- Every controller re-checks `$employee->tenant_id === app(CurrentTenant::class)->id()`; route-model binding is not tenant-safe here.
- Every write calls `AuditLog::record(string $action, ?string $target)`. Progression rows are never updated or deleted.
- Labels bilingual: `<span x-text="$store.ui.lang==='en' ? 'English' : 'Bahasa'">English</span>`.
- Migrations additive. String columns for enums (sqlite parity). Latest existing migration is dated `2026_09_25`; new ones use `2026_09_26_*`.
- Tests: `php artisan test --compact tests/Feature/<File>.php`. Format: `vendor/bin/pint --dirty --format agent`.
- Blade/CSS changed at the end: `lerd artisan view:clear && lerd artisan view:cache && bun run build`, commit `public/build`.
- No full-page reload for in-tab saves: redirect back with `?tab=employment` and the tab row reads `tab` from the query string.
- Existing `Resignation` model (staff-submitted resignation + offboarding case) is a different flow and stays untouched. Progression "resign" is the HR-side record of the leave date.

## Test scaffolding used by every task

Every new test file starts like this (copied from `tests/Feature/AuditChangeTest.php`):

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class XxxTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        app(CurrentTenant::class)->set($this->tenant);
    }

    /** Signed-in user with a tenant role and a linked employee row. */
    private function login(string $role, array $attrs = []): Employee
    {
        $user = User::create(['name' => ucfirst($role), 'email' => $role.'@example.com', 'password' => Hash::make('password')]);
        $user->tenants()->attach($this->tenant->id, ['role' => $role]);
        $employee = Employee::create(array_merge([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'name' => ucfirst($role), 'status' => 'active', 'workload' => 'green',
        ], $attrs));
        $this->actingAs($user)->withSession(['current_tenant' => $this->tenant->id]);

        return $employee;
    }

    private function emp(string $name, array $attrs = []): Employee
    {
        return Employee::create(array_merge([
            'tenant_id' => $this->tenant->id, 'name' => $name, 'status' => 'probation', 'workload' => 'green',
            'joined_at' => '2026-01-05',
        ], $attrs));
    }
}
```

---

### Task 1: Schema — `employee_progressions` table and new `employees` columns

**Files:**
- Create: `database/migrations/2026_09_26_100000_create_employee_progressions_table.php`
- Create: `database/migrations/2026_09_26_100100_add_employment_record_columns_to_employees.php`
- Create: `app/Models/EmployeeProgression.php`
- Modify: `app/Models/Employee.php` (fillable + casts + relation)
- Test: `tests/Feature/EmployeeProgressionSchemaTest.php`

**Interfaces:**
- Produces: `App\Models\EmployeeProgression` (`$guarded = []`, casts `effective_on` date, `snapshot` array, `changed_fields` array; `employee()` BelongsTo; `recordedBy()` BelongsTo Employee). `Employee::progressions()` HasMany ordered `effective_on desc, id desc`. `Employee::EMPLOYMENT_FIELDS` constant listed below.

- [ ] **Step 1: Write the failing test**

```php
public function test_progression_row_round_trips_and_employee_has_new_columns(): void
{
    $e = $this->emp('Adibah', ['probation_months' => 6, 'pay_mode' => 'monthly', 'payment_term' => 'monthly', 'payment_method' => 'bank', 'division' => 'Senior', 'section' => 'PMO']);
    $row = EmployeeProgression::create([
        'tenant_id' => $this->tenant->id, 'employee_id' => $e->id, 'type' => 'hired',
        'effective_on' => '2026-01-05', 'snapshot' => ['position' => 'PE'], 'changed_fields' => [],
    ]);

    $this->assertSame(6, $e->fresh()->probation_months);
    $this->assertSame('PMO', $e->fresh()->section);
    $this->assertSame(['position' => 'PE'], $row->fresh()->snapshot);
    $this->assertSame('2026-01-05', $row->fresh()->effective_on->toDateString());
    $this->assertCount(1, $e->progressions);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact tests/Feature/EmployeeProgressionSchemaTest.php`
Expected: FAIL, class `EmployeeProgression` not found.

- [ ] **Step 3: Migrations**

`2026_09_26_100000_create_employee_progressions_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_progressions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('type', 16); // hired | confirmed | updated | resigned | rehired
            $table->date('effective_on');
            $table->json('snapshot');
            $table->json('changed_fields');
            $table->text('remark')->nullable();
            $table->foreignId('recorded_by_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();
            $table->index(['employee_id', 'effective_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_progressions');
    }
};
```

`2026_09_26_100100_add_employment_record_columns_to_employees.php`:

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
            $table->date('confirmed_at')->nullable()->after('joined_at');
            $table->date('resigned_at')->nullable()->after('confirmed_at');
            $table->date('last_working_day')->nullable()->after('resigned_at');
            $table->unsignedTinyInteger('probation_months')->nullable()->after('last_working_day');
            $table->unsignedTinyInteger('probation_days')->nullable()->after('probation_months');
            $table->unsignedTinyInteger('resign_notice_months')->nullable()->after('probation_days');
            $table->unsignedTinyInteger('resign_notice_days')->nullable()->after('resign_notice_months');
            $table->unsignedTinyInteger('short_notice_months')->nullable()->after('resign_notice_days');
            $table->unsignedTinyInteger('short_notice_days')->nullable()->after('short_notice_months');
            $table->string('pay_mode', 8)->default('monthly')->after('salary');       // monthly | daily | hourly
            $table->string('payment_term', 8)->default('monthly')->after('pay_mode'); // daily | weekly | biweekly | monthly
            $table->string('payment_method', 8)->default('bank')->after('payment_term'); // cash | bank | cheque
            $table->string('division', 80)->nullable()->after('payment_method');
            $table->string('section', 80)->nullable()->after('division');
            $table->string('job_grade', 40)->nullable()->after('section');
            $table->string('category', 40)->nullable()->after('job_grade');
            $table->string('line', 40)->nullable()->after('category');
            $table->text('employment_remark')->nullable()->after('line');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['confirmed_at', 'resigned_at', 'last_working_day', 'probation_months', 'probation_days',
                'resign_notice_months', 'resign_notice_days', 'short_notice_months', 'short_notice_days',
                'pay_mode', 'payment_term', 'payment_method', 'division', 'section', 'job_grade', 'category', 'line', 'employment_remark']);
        });
    }
};
```

- [ ] **Step 4: Model**

`app/Models/EmployeeProgression.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One employment event (hired, confirmed, updated, resigned, rehired). Append-only: the
 * Timeline tab is built from these rows, so a row is never edited or deleted.
 */
class EmployeeProgression extends Model
{
    use BelongsToTenant;

    public const TYPES = ['hired', 'confirmed', 'updated', 'resigned', 'rehired'];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'effective_on' => 'date',
            'snapshot' => 'array',
            'changed_fields' => 'array',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'recorded_by_employee_id');
    }
}
```

In `app/Models/Employee.php`: add every new column to `$fillable`; add casts `'confirmed_at' => 'date', 'resigned_at' => 'date', 'last_working_day' => 'date'`; add:

```php
/** Employment columns that the Timeline snapshots and the Progression forms edit. */
public const EMPLOYMENT_FIELDS = [
    'department_id', 'branch_id', 'position_id', 'reports_to_id', 'employment_type_id',
    'division', 'section', 'job_grade', 'category', 'line',
    'probation_months', 'probation_days', 'resign_notice_months', 'resign_notice_days',
    'short_notice_months', 'short_notice_days',
    'salary', 'pay_mode', 'payment_term', 'payment_method', 'employment_remark',
];

public function progressions(): HasMany
{
    return $this->hasMany(EmployeeProgression::class)->orderByDesc('effective_on')->orderByDesc('id');
}
```

- [ ] **Step 5: Run test, expect PASS.** Then `vendor/bin/pint --dirty --format agent`.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_09_26_100000_create_employee_progressions_table.php database/migrations/2026_09_26_100100_add_employment_record_columns_to_employees.php app/Models/EmployeeProgression.php app/Models/Employee.php tests/Feature/EmployeeProgressionSchemaTest.php
git commit -m "feat(employment): progression table and employment record columns"
```

---

### Task 2: `EmploymentRecordService` — snapshot, diff, and the five transitions

**Files:**
- Create: `app/Services/EmploymentRecordService.php`
- Test: `tests/Feature/EmploymentRecordServiceTest.php`

**Interfaces:**
- Consumes: Task 1 models.
- Produces:

```php
namespace App\Services;

final class EmploymentRecordService
{
    /** @return array<string, mixed> the snapshot the Timeline renders */
    public function snapshot(Employee $e): array;

    /** @param array<string, mixed> $fields subset of Employee::EMPLOYMENT_FIELDS */
    public function hire(Employee $e, ?Employee $by = null): EmployeeProgression;               // backfill / new staff
    public function confirm(Employee $e, string $confirmedOn, array $fields, ?string $remark, ?Employee $by): EmployeeProgression;
    public function update(Employee $e, string $effectiveOn, array $fields, ?string $remark, ?Employee $by): ?EmployeeProgression; // null when nothing changed
    public function resign(Employee $e, string $resignedOn, string $lastWorkingDay, string $reason, ?string $remark, ?Employee $by): EmployeeProgression;
    public function rehire(Employee $e, string $hiredOn, array $fields, ?string $remark, ?Employee $by): EmployeeProgression;
}
```

All throw `\App\Services\EmploymentTransitionException` (extends `\RuntimeException`, message is user-facing) on an illegal transition or a date before `joined_at`.

- [ ] **Step 1: Write the failing tests**

```php
public function test_confirm_moves_probation_to_active_and_writes_a_row(): void
{
    $hr = $this->login('hr');
    $e = $this->emp('Adibah');

    $row = app(EmploymentRecordService::class)->confirm($e, '2026-07-05', ['division' => 'Senior'], 'Passed review', $hr);

    $e->refresh();
    $this->assertSame('active', $e->status);
    $this->assertSame('2026-07-05', $e->confirmed_at->toDateString());
    $this->assertSame('Senior', $e->division);
    $this->assertSame('confirmed', $row->type);
    $this->assertSame(['division'], $row->changed_fields);
    $this->assertSame($hr->id, $row->recorded_by_employee_id);
    $this->assertDatabaseHas('audit_logs', ['action' => 'Confirmed employee', 'target' => 'Adibah']);
}

public function test_confirm_refuses_an_active_employee(): void
{
    $e = $this->emp('Adibah', ['status' => 'active']);
    $this->expectException(EmploymentTransitionException::class);
    app(EmploymentRecordService::class)->confirm($e, '2026-07-05', [], null, null);
}

public function test_update_with_no_change_writes_no_row(): void
{
    $e = $this->emp('Adibah', ['division' => 'Senior']);
    $this->assertNull(app(EmploymentRecordService::class)->update($e, '2026-03-01', ['division' => 'Senior'], null, null));
    $this->assertSame(0, EmployeeProgression::count());
}

public function test_update_records_changed_fields_and_snapshot_names(): void
{
    $dept = Department::create(['tenant_id' => $this->tenant->id, 'name' => 'DevOps']);
    $e = $this->emp('Adibah', ['status' => 'active', 'salary' => 5000]);

    $row = app(EmploymentRecordService::class)->update($e, '2026-03-01', ['department_id' => $dept->id, 'salary' => 5500], 'Promo', null);

    $this->assertEqualsCanonicalizing(['department', 'basic_salary'], $row->changed_fields);
    $this->assertSame('DevOps', $row->snapshot['department']);
    $this->assertSame(5500.0, $row->snapshot['basic_salary']);
    $this->assertSame('Promo', $row->remark);
}

public function test_effective_date_before_hire_date_is_refused(): void
{
    $e = $this->emp('Adibah', ['status' => 'active']);
    $this->expectException(EmploymentTransitionException::class);
    app(EmploymentRecordService::class)->update($e, '2025-12-31', ['division' => 'X'], null, null);
}

public function test_resign_then_rehire(): void
{
    $e = $this->emp('Adibah', ['status' => 'active', 'confirmed_at' => '2026-07-05']);
    $svc = app(EmploymentRecordService::class);

    $svc->resign($e, '2026-08-01', '2026-08-31', 'resigned', null, null);
    $e->refresh();
    $this->assertSame('resigned', $e->status);
    $this->assertSame('2026-08-31', $e->last_working_day->toDateString());

    $svc->rehire($e, '2026-10-01', ['division' => 'Mid'], null, null);
    $e->refresh();
    $this->assertSame('probation', $e->status);
    $this->assertSame('2026-10-01', $e->joined_at->toDateString());
    $this->assertNull($e->resigned_at);
    $this->assertNull($e->confirmed_at);
    $this->assertSame(['resigned', 'rehired'], EmployeeProgression::orderBy('id')->pluck('type')->all());
}

public function test_rehire_refuses_a_non_resigned_employee(): void
{
    $e = $this->emp('Adibah', ['status' => 'active']);
    $this->expectException(EmploymentTransitionException::class);
    app(EmploymentRecordService::class)->rehire($e, '2026-10-01', [], null, null);
}

public function test_resign_last_day_before_resigned_date_is_refused(): void
{
    $e = $this->emp('Adibah', ['status' => 'active']);
    $this->expectException(EmploymentTransitionException::class);
    app(EmploymentRecordService::class)->resign($e, '2026-08-10', '2026-08-01', 'resigned', null, null);
}
```

Add `use App\Models\Department; use App\Models\EmployeeProgression; use App\Services\EmploymentRecordService; use App\Services\EmploymentTransitionException;`.

- [ ] **Step 2: Run, expect FAIL** (class not found).

- [ ] **Step 3: Implement**

`app/Services/EmploymentTransitionException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services;

/** Illegal employment transition; the message is safe to show to the user. */
final class EmploymentTransitionException extends \RuntimeException {}
```

`app/Services/EmploymentRecordService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeProgression;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The one place employment state changes. Both the profile's Employment edit modal and the
 * Progression screen call these, so every path leaves the same append-only timeline row.
 */
final class EmploymentRecordService
{
    /** Keys in the snapshot. Lookup names stored as strings so renames never rewrite history. */
    public function snapshot(Employee $e): array
    {
        $e->loadMissing(['department', 'branch', 'positionBand', 'reportsTo', 'employmentType']);

        return [
            'status' => $e->status,
            'department' => $e->department?->name,
            'division' => $e->division,
            'section' => $e->section,
            'position' => $e->positionBand?->title,
            'job_grade' => $e->job_grade,
            'category' => $e->category,
            'line' => $e->line,
            'branch' => $e->branch?->name,
            'reports_to' => $e->reportsTo ? ['id' => $e->reportsTo->id, 'name' => $e->reportsTo->name] : null,
            'employment_type' => $e->employmentType?->name,
            'probation_months' => $e->probation_months,
            'probation_days' => $e->probation_days,
            'basic_salary' => $e->salary === null ? null : (float) $e->salary,
            'pay_mode' => $e->pay_mode,
            'payment_term' => $e->payment_term,
            'payment_method' => $e->payment_method,
        ];
    }

    public function hire(Employee $e, ?Employee $by = null): EmployeeProgression
    {
        return $this->record($e, 'hired', $e->joined_at?->toDateString() ?? now()->toDateString(), [], null, $by, previous: null);
    }

    public function confirm(Employee $e, string $confirmedOn, array $fields, ?string $remark, ?Employee $by): EmployeeProgression
    {
        $this->assertStatus($e, ['probation'], 'Only staff on probation can be confirmed.');
        $this->assertOnOrAfterHire($e, $confirmedOn);

        return DB::transaction(function () use ($e, $confirmedOn, $fields, $remark, $by) {
            $previous = $this->snapshot($e);
            $e->fill($this->only($fields))->forceFill(['confirmed_at' => $confirmedOn, 'status' => 'active'])->save();
            $row = $this->record($e, 'confirmed', $confirmedOn, $fields, $remark, $by, $previous);
            AuditLog::record('Confirmed employee', $e->name);

            return $row;
        });
    }

    public function update(Employee $e, string $effectiveOn, array $fields, ?string $remark, ?Employee $by): ?EmployeeProgression
    {
        $this->assertStatus($e, ['active', 'probation', 'on_leave'], 'Resigned staff must be rehired before their record can change.');
        $this->assertOnOrAfterHire($e, $effectiveOn);

        return DB::transaction(function () use ($e, $effectiveOn, $fields, $remark, $by) {
            $previous = $this->snapshot($e);
            $e->fill($this->only($fields))->save();
            $e->unsetRelations();
            if ($this->diff($previous, $this->snapshot($e)) === []) {
                return null;
            }
            $row = $this->record($e, 'updated', $effectiveOn, $fields, $remark, $by, $previous);
            AuditLog::record('Updated employment record', $e->name);

            return $row;
        });
    }

    public function resign(Employee $e, string $resignedOn, string $lastWorkingDay, string $reason, ?string $remark, ?Employee $by): EmployeeProgression
    {
        $this->assertStatus($e, ['active', 'probation', 'on_leave'], 'This person has already left.');
        $this->assertOnOrAfterHire($e, $resignedOn);
        if (CarbonImmutable::parse($lastWorkingDay)->lt(CarbonImmutable::parse($resignedOn))) {
            throw new EmploymentTransitionException('Last working day cannot be before the resignation date.');
        }

        return DB::transaction(function () use ($e, $resignedOn, $lastWorkingDay, $reason, $remark, $by) {
            $previous = $this->snapshot($e);
            $e->forceFill(['resigned_at' => $resignedOn, 'last_working_day' => $lastWorkingDay, 'status' => 'resigned'])->save();
            $row = $this->record($e, 'resigned', $resignedOn, [], trim($reason.($remark ? " — $remark" : '')), $by, $previous, extra: ['reason' => $reason, 'last_working_day' => $lastWorkingDay]);
            AuditLog::record('Recorded resignation', $e->name);

            return $row;
        });
    }

    public function rehire(Employee $e, string $hiredOn, array $fields, ?string $remark, ?Employee $by): EmployeeProgression
    {
        $this->assertStatus($e, ['resigned'], 'Only resigned staff can be rehired.');

        return DB::transaction(function () use ($e, $hiredOn, $fields, $remark, $by) {
            $previous = $this->snapshot($e);
            $e->fill($this->only($fields))->forceFill([
                'joined_at' => $hiredOn, 'resigned_at' => null, 'last_working_day' => null, 'confirmed_at' => null, 'status' => 'probation',
            ])->save();
            $row = $this->record($e, 'rehired', $hiredOn, $fields, $remark, $by, $previous);
            AuditLog::record('Rehired employee', $e->name);

            return $row;
        });
    }

    /** @return list<string> snapshot keys whose value differs */
    public function diff(array $before, array $after): array
    {
        return array_values(array_keys(array_filter($after, fn ($v, $k) => ($before[$k] ?? null) != $v, ARRAY_FILTER_USE_BOTH)));
    }

    private function record(Employee $e, string $type, string $on, array $fields, ?string $remark, ?Employee $by, ?array $previous, array $extra = []): EmployeeProgression
    {
        $e->unsetRelations();
        $snapshot = $this->snapshot($e) + $extra;

        return EmployeeProgression::create([
            'tenant_id' => $e->tenant_id,
            'employee_id' => $e->id,
            'type' => $type,
            'effective_on' => $on,
            'snapshot' => $snapshot,
            'changed_fields' => $previous === null ? [] : $this->diff($previous, $snapshot),
            'remark' => $remark ?: null,
            'recorded_by_employee_id' => $by?->id,
        ]);
    }

    private function only(array $fields): array
    {
        return array_intersect_key($fields, array_flip(Employee::EMPLOYMENT_FIELDS));
    }

    private function assertStatus(Employee $e, array $allowed, string $message): void
    {
        if (! in_array($e->status, $allowed, true)) {
            throw new EmploymentTransitionException($message);
        }
    }

    private function assertOnOrAfterHire(Employee $e, string $date): void
    {
        if ($e->joined_at && CarbonImmutable::parse($date)->lt($e->joined_at)) {
            throw new EmploymentTransitionException('Date cannot be before the hire date ('.$e->joined_at->format('d M Y').').');
        }
    }
}
```

Note: `diff` uses loose `!=` so `5000` and `5000.0` compare equal; `changed_fields` for `resign` will naturally include `status`, which the Timeline shows.

- [ ] **Step 4: Run, expect PASS.** Pint.

- [ ] **Step 5: Commit**

```bash
git add app/Services/EmploymentRecordService.php app/Services/EmploymentTransitionException.php tests/Feature/EmploymentRecordServiceTest.php
git commit -m "feat(employment): EmploymentRecordService owns hire/confirm/update/resign/rehire"
```

---

### Task 3: Backfill migration and retire `career_timeline`

**Files:**
- Create: `database/migrations/2026_09_26_100200_backfill_hired_progressions.php`
- Create: `database/migrations/2026_09_26_100300_drop_career_timeline_table.php`
- Delete: `app/Models/CareerTimelineEntry.php`
- Modify: `app/Models/Employee.php` (remove `careerTimeline()` relation), `app/Http/Controllers/Concerns/BuildsPeopleData.php:120` (remove `'careerTimeline'` from `$with`), `resources/views/screens/profile.blade.php:276-285` (remove the "Career timeline" Overview card), `database/seeders/DatabaseSeeder.php:12,~250` (remove the `CareerTimelineEntry` import and seed loop).
- Test: `tests/Feature/EmployeeProgressionBackfillTest.php`

- [ ] **Step 1: Failing test**

```php
public function test_backfill_writes_one_hired_row_per_non_archived_employee(): void
{
    $a = $this->emp('A', ['joined_at' => '2025-02-01']);
    $b = $this->emp('B', ['joined_at' => '2025-03-01']);
    $this->emp('Gone', ['archived_at' => now()]);
    EmployeeProgression::query()->delete(); // RefreshDatabase already ran the migration on an empty table

    (require database_path('migrations/2026_09_26_100200_backfill_hired_progressions.php'))->up();

    $this->assertSame(2, EmployeeProgression::where('type', 'hired')->count());
    $this->assertSame('2025-02-01', EmployeeProgression::where('employee_id', $a->id)->first()->effective_on->toDateString());
    $this->assertFalse(Schema::hasTable('career_timeline'));
}
```

- [ ] **Step 2: Run, expect FAIL** (migration file missing / table still exists).

- [ ] **Step 3: Backfill migration**

```php
<?php

use App\Models\Employee;
use App\Models\EmployeeProgression;
use App\Services\EmploymentRecordService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $svc = app(EmploymentRecordService::class);
        Employee::withoutGlobalScopes()->whereNull('archived_at')->each(function (Employee $e) use ($svc) {
            if (EmployeeProgression::where('employee_id', $e->id)->exists()) {
                return;
            }
            $svc->hire($e);
        });
    }

    public function down(): void
    {
        EmployeeProgression::where('type', 'hired')->whereNull('recorded_by_employee_id')->delete();
    }
};
```

Drop migration:

```php
return new class extends Migration
{
    public function up(): void { Schema::dropIfExists('career_timeline'); }
    public function down(): void
    {
        Schema::create('career_timeline', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('date_label')->nullable();
            $table->string('category', 8)->default('muted');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });
    }
};
```

Then remove the model, relation, `$with` entry, the Overview "Career timeline" block, and the seeder lines. Grep to confirm nothing else references it: `grep -rn "careerTimeline\|CareerTimelineEntry\|career_timeline" app resources database tests`.

- [ ] **Step 4: Run new test + `php artisan test --compact tests/Feature/AllScreensRenderTest.php`**, expect PASS. Pint.

- [ ] **Step 5: Commit**

```bash
git add -A database/migrations app/Models resources/views/screens/profile.blade.php app/Http/Controllers/Concerns/BuildsPeopleData.php database/seeders/DatabaseSeeder.php tests/Feature/EmployeeProgressionBackfillTest.php
git commit -m "feat(employment): backfill hired rows, drop the placeholder career_timeline"
```

---

### Task 4: New staff and generic edit write through the service

**Files:**
- Modify: `app/Http/Controllers/EmployeeController.php` (`store` ~line 27-78, `update` line 79-160, `import` ~319)
- Test: `tests/Feature/EmployeeProgressionOnStoreTest.php`

Rule: `store` and `import` call `hire()` after creating the row. `update` (the generic modal) stops accepting `position_id`, `salary`, `branch_id`, `employment_type_id`, `reports_to_id`, `joined_at`; those move to the Employment tab (Task 6). Status stays on the generic modal but `resigned` is removed from its options (resignation goes through Progression). Keep `name, nickname, email, staff_id, date_of_birth, work_arrangement, status(active|probation|on_leave)`.

- [ ] **Step 1: Failing tests**

```php
public function test_store_writes_a_hired_row(): void
{
    $this->login('hr');
    $this->post('/app/employees', ['name' => 'New Person', 'joined_at' => '2026-02-02', 'status' => 'probation'])->assertRedirect();
    $e = Employee::where('name', 'New Person')->firstOrFail();
    $this->assertSame('hired', $e->progressions()->first()->type);
    $this->assertSame('2026-02-02', $e->progressions()->first()->effective_on->toDateString());
}

public function test_generic_update_ignores_employment_fields(): void
{
    $this->login('hr');
    $e = $this->emp('Adibah', ['salary' => 5000, 'status' => 'active']);
    $this->post("/app/employees/{$e->id}", ['name' => 'Adibah B', 'status' => 'active', 'salary' => 9999, 'joined_at' => '2020-01-01'])->assertRedirect();
    $e->refresh();
    $this->assertSame('Adibah B', $e->name);
    $this->assertSame(5000.0, (float) $e->salary);
    $this->assertSame('2026-01-05', $e->joined_at->toDateString());
    $this->assertSame(0, EmployeeProgression::count());
}
```

Check `store`'s required fields first (`sed -n 27,78p app/Http/Controllers/EmployeeController.php`) and add whatever else is `required` to the first test's POST body.

- [ ] **Step 2: Run, expect FAIL.**

- [ ] **Step 3: Implement.** In `store` and `import`, after the `Employee::create(...)` call: `app(EmploymentRecordService::class)->hire($employee, $request->attributes->get('employee'));`. In `update`, delete the validation rules and the write lines for `joined_at`, `position_id`, `salary`, `branch_id`, `employment_type_id`, `reports_to_id` (and the `bandFields` call, `$canSetSalary`, `wouldCycle` usage if now unused; keep the private helpers because Task 6 reuses them). Change `'status' => ['required', 'in:active,probation,on_leave,resigned']` to `'in:active,probation,on_leave'`. In `profile.blade.php` remove the matching inputs from the generic modal (Joined, Position band, Salary, Branch, Employment type, Reports to) and the `resigned` option from `$stOpts`. Existing `EmployeeController` tests that POST those fields must be updated to the new contract, not deleted.

- [ ] **Step 4: Run** `php artisan test --compact --filter=Employee` expect PASS. Pint.

- [ ] **Step 5: Commit** `feat(employment): new staff get a hired row; employment fields leave the generic edit`.

---

### Task 5: Profile data — gates and payload for the two tabs

**Files:**
- Modify: `app/Http/Controllers/Concerns/BuildsPeopleData.php` (`profileData`, ~line 118-200)
- Test: `tests/Feature/ProfileEmploymentTabsTest.php`

**Produces** (view variables): `$employmentGate` bool (tab visible), `$canEditEmployment` bool, `$canSeeSalary` bool (already exists in some screens; set it here too), `$progressions` Collection of `EmployeeProgression` with `recordedBy`, `$allDepartments`, `$allBranches`, `$allPositions`, `$allEmploymentTypes`, `$allManagers` (the last four already exist on this payload; add departments).

Gate rules:
- `$employmentGate = $e && (($own && $own->id === $e->id) || $this->hasTenantRole($request, ['management', 'hr']))` — note `manager` is excluded on purpose even though `canViewFull` lets them see other tabs.
- `$canEditEmployment = $this->hasTenantRole($request, ['management', 'hr'])`.
- `$canSeeSalary = $this->hasTenantRole($request, ['director', 'hr'])`. Self-view does NOT get salary here (spec decision).

- [ ] **Step 1: Failing tests**

```php
public function test_hr_sees_employment_and_timeline_tabs(): void
{
    $this->login('hr');
    $e = $this->emp('Adibah');
    app(EmploymentRecordService::class)->hire($e);
    $this->get("/app/profile?emp={$e->id}")->assertOk()
        ->assertSee('data-tab="employment"', false)
        ->assertSee('data-tab="timeline"', false);
}

public function test_manager_sees_neither_tab(): void
{
    $m = $this->login('manager');
    $e = $this->emp('Adibah', ['reports_to_id' => $m->id]);
    $this->get("/app/profile?emp={$e->id}")->assertOk()
        ->assertDontSee('data-tab="employment"', false)
        ->assertDontSee('data-tab="timeline"', false);
}

public function test_employee_sees_own_tabs_without_salary(): void
{
    $me = $this->login('employee', ['salary' => 4321, 'status' => 'active']);
    app(EmploymentRecordService::class)->hire($me);
    $this->get('/app/profile')->assertOk()
        ->assertSee('data-tab="employment"', false)
        ->assertDontSee('4,321');
}
```

These assert on `data-tab="…"` attributes that Task 6 adds to the tab buttons. Write the tests now; Task 6 makes them green. Run to confirm they FAIL, commit them with Task 6.

- [ ] **Step 2: Implement in `profileData`:** add `'progressions.recordedBy'` to `$with`; compute the three gates; add `'allDepartments' => Department::orderBy('name')->get(['id', 'name'])` and pass `'progressions' => $e?->progressions ?? collect()` plus the gates into the returned array (find the existing `return array_merge([...` near the end of the method and add the keys there).

- [ ] **Step 3: Continue to Task 6** (tests go green there).

---

### Task 6: Employment tab and Timeline tab on the profile

**Files:**
- Create: `resources/views/partials/profile/employment-tab.blade.php`
- Create: `resources/views/partials/profile/timeline-tab.blade.php`
- Create: `resources/views/partials/profile/employment-form-fields.blade.php` (shared with Task 8)
- Create: `app/Http/Controllers/EmploymentRecordController.php` (`update`)
- Modify: `resources/views/screens/profile.blade.php` (tab list ~line 239-262, tab panels after `overview`), `routes/web.php` (next to line 326)
- Test: `tests/Feature/ProfileEmploymentTabsTest.php` (from Task 5, plus below)

**Produces:** route `POST /app/employees/{employee}/employment` named `employees.employment.update`.

- [ ] **Step 1: Add controller tests**

```php
public function test_hr_updates_employment_and_gets_a_timeline_row(): void
{
    $hr = $this->login('hr');
    $e = $this->emp('Adibah', ['status' => 'active']);
    $this->post("/app/employees/{$e->id}/employment", [
        'effective_on' => '2026-03-01', 'division' => 'Senior', 'pay_mode' => 'monthly', 'payment_term' => 'monthly', 'payment_method' => 'bank',
    ])->assertRedirect("/app/profile?emp={$e->id}&tab=employment");
    $this->assertSame('Senior', $e->fresh()->division);
    $this->assertSame('updated', $e->progressions()->first()->type);
}

public function test_manager_cannot_update_employment(): void
{
    $m = $this->login('manager');
    $e = $this->emp('Adibah', ['reports_to_id' => $m->id, 'status' => 'active']);
    $this->post("/app/employees/{$e->id}/employment", ['effective_on' => '2026-03-01', 'division' => 'X'])->assertForbidden();
}

public function test_management_cannot_change_salary_but_hr_can(): void
{
    $this->login('management');
    $e = $this->emp('Adibah', ['status' => 'active', 'salary' => 5000]);
    $this->post("/app/employees/{$e->id}/employment", ['effective_on' => '2026-03-01', 'salary' => 9000])->assertRedirect();
    $this->assertSame(5000.0, (float) $e->fresh()->salary);
}

public function test_illegal_transition_message_is_shown(): void
{
    $this->login('hr');
    $e = $this->emp('Adibah', ['status' => 'resigned']);
    $this->post("/app/employees/{$e->id}/employment", ['effective_on' => '2026-03-01', 'division' => 'X'])
        ->assertSessionHasErrors('effective_on');
}
```

- [ ] **Step 2: Run, expect FAIL** (404 route).

- [ ] **Step 3: Route + controller**

`routes/web.php`, after line 326 (`employees.update`):

```php
Route::post('/app/employees/{employee}/employment', [EmploymentRecordController::class, 'update'])->name('employees.employment.update');
```

`app/Http/Controllers/EmploymentRecordController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Services\EmploymentRecordService;
use App\Services\EmploymentTransitionException;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Employment tab save on the profile. Progression actions live in ProgressionController. */
class EmploymentRecordController extends Controller
{
    public function update(Request $request, Employee $employee, EmploymentRecordService $service): RedirectResponse
    {
        $this->authorizeTenantRole($request, ['management', 'hr']);
        $tenantId = app(CurrentTenant::class)->id();
        abort_unless($employee->tenant_id === $tenantId, 403);

        $data = $request->validate(self::rules($tenantId, $employee) + ['effective_on' => ['required', 'date']]);
        $fields = self::fields($data, $this->hasTenantRole($request, ['director', 'hr']));

        try {
            $service->update($employee, $data['effective_on'], $fields, $data['employment_remark'] ?? null, $request->attributes->get('employee'));
        } catch (EmploymentTransitionException $ex) {
            return back()->withInput()->withErrors(['effective_on' => $ex->getMessage()]);
        }

        return redirect(route('app.screen', 'profile').'?emp='.$employee->id.'&tab=employment')->with('ok', $employee->name.' updated.');
    }

    /** Shared with ProgressionController: the Worksy employment field set. */
    public static function rules(int $tenantId, ?Employee $self = null): array
    {
        $inTenant = fn (string $table) => Rule::exists($table, 'id')->where('tenant_id', $tenantId);

        return [
            'department_id' => ['nullable', 'integer', $inTenant('departments')],
            'branch_id' => ['nullable', 'integer', $inTenant('branches')],
            'position_id' => ['nullable', 'integer', $inTenant('positions')],
            'employment_type_id' => ['nullable', 'integer', $inTenant('employment_types')],
            'reports_to_id' => ['nullable', 'integer', Rule::notIn([$self?->id]), Rule::exists('employees', 'id')->where(fn ($q) => $q->where('tenant_id', $tenantId)->whereNull('archived_at'))],
            'division' => ['nullable', 'string', 'max:80'],
            'section' => ['nullable', 'string', 'max:80'],
            'job_grade' => ['nullable', 'string', 'max:40'],
            'category' => ['nullable', 'string', 'max:40'],
            'line' => ['nullable', 'string', 'max:40'],
            'probation_months' => ['nullable', 'integer', 'between:0,24'],
            'probation_days' => ['nullable', 'integer', 'between:0,31'],
            'resign_notice_months' => ['nullable', 'integer', 'between:0,24'],
            'resign_notice_days' => ['nullable', 'integer', 'between:0,31'],
            'short_notice_months' => ['nullable', 'integer', 'between:0,24'],
            'short_notice_days' => ['nullable', 'integer', 'between:0,31'],
            'salary' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'pay_mode' => ['nullable', 'in:monthly,daily,hourly'],
            'payment_term' => ['nullable', 'in:daily,weekly,biweekly,monthly'],
            'payment_method' => ['nullable', 'in:cash,bank,cheque'],
            'employment_remark' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** Strip salary unless the caller may set it; empty strings become null. */
    public static function fields(array $data, bool $canSetSalary): array
    {
        $fields = array_intersect_key($data, array_flip(Employee::EMPLOYMENT_FIELDS));
        if (! $canSetSalary) {
            unset($fields['salary']);
        }

        return array_map(fn ($v) => $v === '' ? null : $v, $fields);
    }
}
```

Reports-to cycle check: reuse `EmployeeController::wouldCycle` by moving it to a small trait `app/Http/Controllers/Concerns/ChecksReportingCycles.php` used by both controllers, and add it as a closure rule on `reports_to_id` the same way `EmployeeController::update` did.

- [ ] **Step 4: Views**

In `profile.blade.php` `$tabs` block, after the `overview` entry:

```php
if ($employmentGate ?? false) {
    $tabs[] = ['employment', 'Employment', 'Pekerjaan'];
    $tabs[] = ['timeline', 'Timeline', 'Garis Masa'];
}
```

Change the tab card root to read the query string: `x-data="{ tab: new URLSearchParams(location.search).get('tab') || 'overview' }"` and add `data-tab="{{ $tab[0] }}"` to each tab button.

After the overview panel add:

```blade
@if ($employmentGate ?? false)
    <div x-show="tab === 'employment'" x-cloak class="uj-tab-stack" style="padding:20px;">@include('partials.profile.employment-tab')</div>
    <div x-show="tab === 'timeline'" x-cloak class="uj-tab-stack" style="padding:20px;">@include('partials.profile.timeline-tab')</div>
@endif
```

`partials/profile/employment-tab.blade.php` — header strip then a two-column read-only grid, then the edit modal for `$canEditEmployment`:

```blade
@php
    $L = fn ($en, $ms) => "<span x-text=\"\$store.ui.lang==='en' ? ".json_encode($en)." : ".json_encode($ms)."\">$en</span>";
    $d = fn ($v) => $v ? $v->format('d/m/Y') : '—';
    $period = fn ($m, $dd) => ($m || $dd) ? trim(($m ? "{$m}M " : '').($dd ? "{$dd}D" : '')) : '—';
    $end = $p->last_working_day ?? now();
    $service = $p->joined_at ? $p->joined_at->diff($end) : null;
    $due = ($p->joined_at && ($p->probation_months || $p->probation_days)) ? $p->joined_at->copy()->addMonths((int) $p->probation_months)->addDays((int) $p->probation_days) : null;
    $payModeL = ['monthly' => 'Monthly Rate', 'daily' => 'Daily Rate', 'hourly' => 'Hourly Rate'];
    $termL = ['daily' => 'Daily', 'weekly' => 'Weekly', 'biweekly' => 'Bi-Weekly', 'monthly' => 'Monthly'];
    $methodL = ['cash' => 'Cash', 'bank' => 'Bank', 'cheque' => 'Cheque'];
    $rows = [
        ['Hire Date', 'Tarikh Mula', $d($p->joined_at)],
        ['Probation Period', 'Tempoh Percubaan', $period($p->probation_months, $p->probation_days)],
        ['Confirmation Date', 'Tarikh Pengesahan', $d($p->confirmed_at)],
        ['Resign Notice Period', 'Tempoh Notis Berhenti', $period($p->resign_notice_months, $p->resign_notice_days)],
        ['Resigned Date', 'Tarikh Berhenti', $d($p->resigned_at)],
        ['Short Notice Period', 'Tempoh Notis Singkat', $period($p->short_notice_months, $p->short_notice_days)],
        ['Branch', 'Cawangan', $p->branch?->name ?? '—'],
        ['Department', 'Jabatan', $p->department?->name ?? '—'],
        ['Division', 'Bahagian', $p->division ?? '—'],
        ['Position', 'Jawatan', $p->positionBand?->title ?? '—'],
        ['Reporting To', 'Melapor Kepada', $p->reportsTo?->name ?? '—'],
        ['Category', 'Kategori', $p->category ?? '—'],
        ['Job Grade', 'Gred', $p->job_grade ?? '—'],
        ['Line', 'Barisan', $p->line ?? '—'],
        ['Section', 'Seksyen', $p->section ?? '—'],
        ['Employment Type', 'Jenis Pekerjaan', $p->employmentType?->name ?? '—'],
    ];
    if ($canSeeSalary ?? false) {
        $rows[] = ['Basic Salary', 'Gaji Pokok', $p->salary === null ? '—' : 'MYR '.number_format((float) $p->salary, 2).' · '.($payModeL[$p->pay_mode] ?? $p->pay_mode)];
    }
    $rows[] = ['Payment Term', 'Tempoh Bayaran', $termL[$p->payment_term] ?? '—'];
    $rows[] = ['Payment Method', 'Kaedah Bayaran', $methodL[$p->payment_method] ?? '—'];
    $rows[] = ['Remark', 'Catatan', $p->employment_remark ?? '—'];
@endphp

<div style="display:flex;flex-wrap:wrap;gap:16px 32px;font-size:12.5px;color:var(--muted);">
    <div>{!! $L('Date Hired', 'Tarikh Mula') !!}: <b style="color:var(--ink);">{{ $d($p->joined_at) }}</b></div>
    <div>{!! $L('Years of Service', 'Tempoh Perkhidmatan') !!}: <b style="color:var(--ink);">{{ $service ? "{$service->y}Y {$service->m}M {$service->d}D" : '—' }}</b></div>
    <div>{!! $L('Due for Confirmation', 'Tarikh Pengesahan Dijangka') !!}: <b style="color:var(--ink);">{{ $d($due) }}</b></div>
    @if ($canEditEmployment ?? false)
        <button type="button" @click="editEmployment = true" class="uj-btn-ghost" style="margin-left:auto;height:32px;padding:0 14px;font-size:12.5px;">{!! $L('Edit', 'Sunting') !!}</button>
    @endif
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px 32px;">
    @foreach ($rows as [$en, $ms, $val])
        <div><div style="font-size:11px;color:var(--muted);margin-bottom:2px;">{!! $L($en, $ms) !!}</div><div style="font-size:13px;color:var(--ink);">{{ $val }}</div></div>
    @endforeach
</div>

@if ($canEditEmployment ?? false)
    <template x-teleport="body">
    <div x-show="editEmployment" x-cloak @click.self="editEmployment = false" @keydown.escape.window="editEmployment = false"
         style="position:fixed;inset:0;z-index:120;display:flex;padding:40px 16px;background:rgba(18,18,30,.42);overflow-y:auto;">
        <form method="post" action="{{ route('employees.employment.update', $p) }}" class="uj-card" style="width:100%;max-width:720px;margin:auto;padding:20px;display:flex;flex-direction:column;gap:12px;max-height:calc(100vh - 80px);overflow-y:auto;">
            @csrf
            <div style="font-size:13px;font-weight:600;color:var(--ink);">{!! $L('Edit employment', 'Sunting pekerjaan') !!} · {{ $p->name }}</div>
            @if ($errors->any())<div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12px;border-radius:8px;padding:8px 11px;">{{ $errors->first() }}</div>@endif
            <div><label style="display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;">{!! $L('Effective date', 'Tarikh berkuat kuasa') !!}</label><input type="date" name="effective_on" value="{{ old('effective_on', now()->toDateString()) }}" required style="{{ $fs }}" /></div>
            @include('partials.profile.employment-form-fields', ['e' => $p, 'canSeeSalary' => $canSeeSalary ?? false])
            <button type="submit" class="uj-btn-primary" style="height:40px;font-size:13px;">{!! $L('Save changes', 'Simpan perubahan') !!}</button>
        </form>
    </div>
    </template>
@endif
```

Add `editEmployment: {{ $errors->has('effective_on') ? 'true' : 'false' }}` to the profile's outer `x-data` at line 59 so the modal reopens on a validation error. `$fs` is the input style string already defined in `profile.blade.php`; make sure the partial is included inside that scope (it is, as long as the include sits inside the same Blade file's flow after `$fs` is set).

`partials/profile/employment-form-fields.blade.php` — a two-column grid of the Worksy fields, every input `name` matching `Employee::EMPLOYMENT_FIELDS`, values from `old('x', $e->x)`. Selects: `department_id` from `$allDepartments`, `branch_id` from `$allBranches`, `position_id` from `$allPositions` (grouped by department like the existing modal), `employment_type_id` from `$allEmploymentTypes`, `reports_to_id` from `$allManagers` skipping `$e->id`. Text inputs: division, section, job_grade, category, line. Number inputs paired with "Month(s)" / "Day(s)" for the three periods. `salary` + `pay_mode` select only `@if ($canSeeSalary)`. `payment_term`, `payment_method` selects. `employment_remark` textarea. Labels via the same `$L` helper (redefine it at the top of the partial).

`partials/profile/timeline-tab.blade.php`:

```blade
@php
    $L = fn ($en, $ms) => "<span x-text=\"\$store.ui.lang==='en' ? ".json_encode($en)." : ".json_encode($ms)."\">$en</span>";
    $titles = ['hired' => ['Hired', 'Diambil Bekerja'], 'confirmed' => ['Confirmed', 'Disahkan'], 'updated' => ['Updated', 'Dikemas kini'], 'resigned' => ['Resigned', 'Berhenti'], 'rehired' => ['Rehired', 'Diambil Semula']];
    $labels = ['department' => 'Department', 'division' => 'Division', 'section' => 'Section', 'position' => 'Position', 'job_grade' => 'Job Grade', 'category' => 'Category', 'line' => 'Line', 'branch' => 'Branch', 'reports_to' => 'Reporting To', 'employment_type' => 'Employment Type', 'probation_months' => 'Probation (months)', 'probation_days' => 'Probation (days)', 'basic_salary' => 'Basic Salary', 'pay_mode' => 'Pay Mode', 'payment_term' => 'Payment Term', 'payment_method' => 'Payment Method', 'status' => 'Status', 'reason' => 'Reason', 'last_working_day' => 'Last Working Day'];
    $fmt = function (string $k, $v) {
        if ($v === null || $v === '') return '—';
        if ($k === 'reports_to') return is_array($v) ? ($v['name'] ?? '—') : $v;
        if ($k === 'basic_salary') return 'MYR '.number_format((float) $v, 2);
        return is_scalar($v) ? (string) $v : json_encode($v);
    };
@endphp
<div style="font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:0.6px;">{!! $L('Employment History', 'Sejarah Pekerjaan') !!}</div>
@forelse ($progressions as $row)
    @php
        $snap = $row->snapshot;
        if (! ($canSeeSalary ?? false)) { unset($snap['basic_salary']); }
        $changed = array_flip($row->changed_fields ?? []);
    @endphp
    <div x-data="{ open: {{ $loop->first ? 'true' : 'false' }} }" style="border-left:2px solid var(--info);padding-left:16px;margin-left:6px;position:relative;">
        <span style="position:absolute;left:-7px;top:8px;width:12px;height:12px;border-radius:50%;background:#fff;border:2px solid var(--info);"></span>
        <span style="display:inline-block;background:var(--info);color:#fff;font-size:11.5px;font-weight:600;border-radius:6px;padding:4px 10px;">{{ $row->effective_on->format('D, jS F Y') }}</span>
        <div class="uj-card" style="margin-top:8px;padding:16px;">
            <button type="button" @click="open = !open" style="display:flex;justify-content:space-between;width:100%;background:transparent;cursor:pointer;font-size:18px;font-weight:600;color:var(--ink);">{!! $L(...$titles[$row->type]) !!}<span x-text="open ? '▴' : '▾'"></span></button>
            <div x-show="open" x-collapse style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:14px 32px;margin-top:12px;">
                @foreach ($snap as $k => $v)
                    @continue(! isset($labels[$k]))
                    <div style="{{ isset($changed[$k]) ? 'background:var(--amber-tint,#fff7e6);border-radius:6px;padding:6px 8px;margin:-6px -8px;' : '' }}">
                        <div style="font-size:11px;color:var(--muted);">{{ $labels[$k] }}</div>
                        <div style="font-size:13px;color:var(--ink);">{{ $fmt($k, $v) }}</div>
                    </div>
                @endforeach
                @if ($row->remark)<div style="grid-column:1/-1;font-size:12.5px;color:var(--body);">{{ $row->remark }}</div>@endif
                <div style="grid-column:1/-1;font-size:11.5px;color:var(--muted);margin-top:8px;">{!! $L('Last edited on', 'Dikemas kini pada') !!} {{ $row->created_at->format('jS F Y') }}@if ($row->recordedBy), {!! $L('by', 'oleh') !!} {{ $row->recordedBy->name }}@endif</div>
            </div>
        </div>
    </div>
@empty
    <p style="font-size:12.5px;color:var(--muted);margin:0;">{!! $L('No employment history yet.', 'Tiada sejarah pekerjaan lagi.') !!}</p>
@endforelse
```

If `x-collapse` is not registered in `resources/js/app.js` (check `grep -n collapse resources/js/app.js`), replace with plain `x-show`.

- [ ] **Step 5: Run** `php artisan test --compact tests/Feature/ProfileEmploymentTabsTest.php tests/Feature/AllScreensRenderTest.php` expect PASS. Pint.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/EmploymentRecordController.php app/Http/Controllers/Concerns/ChecksReportingCycles.php app/Http/Controllers/EmployeeController.php app/Http/Controllers/Concerns/BuildsPeopleData.php routes/web.php resources/views/screens/profile.blade.php resources/views/partials/profile tests/Feature/ProfileEmploymentTabsTest.php
git commit -m "feat(profile): Employment and Timeline tabs"
```

---

### Task 7: Progression screen — registration, gate, data

**Files:**
- Create: `app/Http/Controllers/ProgressionController.php` (`screenData` only for now)
- Modify: `app/Support/Amanahku.php` (sidebar `$s(...)` list ~line 88, `page()` map ~line 391), `app/Http/Controllers/AppController.php` (admin gate list line 163, `screenData` switch ~line 564), `resources/views/layouts/app.blade.php:192` (`$wideScreens`)
- Create: `resources/views/screens/progression.blade.php` (skeleton: staff list + empty right pane)
- Test: `tests/Feature/ProgressionScreenTest.php`

**Produces:** screen slug `progression`, view vars `$staff` (Collection of active + resigned employees with `positionBand`, `department`, ordered by name), `$selected` (?Employee with `progressions.recordedBy`, `reportsTo`, `department`, `branch`, `positionBand`, `employmentType`), `$action` (one of confirmation|update|resignation|rehire, default confirmation), `$canSeeSalary`, plus `$allDepartments`, `$allBranches`, `$allPositions`, `$allEmploymentTypes`, `$allManagers`.

- [ ] **Step 1: Failing tests**

```php
public function test_hr_and_director_open_the_screen_manager_and_employee_are_forbidden(): void
{
    $this->login('hr');
    $this->get('/app/progression')->assertOk()->assertSee('Progression');
    $this->login('director');
    $this->get('/app/progression')->assertOk();
    $this->login('manager');
    $this->get('/app/progression')->assertForbidden();
    $this->login('employee');
    $this->get('/app/progression')->assertForbidden();
}

public function test_selecting_an_employee_shows_their_header(): void
{
    $this->login('hr');
    $e = $this->emp('Adibah');
    $this->get("/app/progression?emp={$e->id}&action=confirmation")->assertOk()->assertSee('Adibah')->assertSee('data-action="confirmation"', false);
}

public function test_cannot_select_an_employee_from_another_tenant(): void
{
    $this->login('hr');
    $other = Tenant::create(['slug' => 'zed', 'name' => 'Zed', 'initials' => 'ZD']);
    $stranger = Employee::withoutGlobalScopes()->create(['tenant_id' => $other->id, 'name' => 'Stranger', 'status' => 'active', 'workload' => 'green']);
    $this->get("/app/progression?emp={$stranger->id}")->assertOk()->assertDontSee('Stranger');
}
```

Note: `login()` in this file must use distinct emails per call (`$role.'@example.com'` already does, and each role is used once per test).

- [ ] **Step 2: Run, expect FAIL** (404).

- [ ] **Step 3: Register the screen**

`Amanahku.php` sidebar, directly after the `orgchart` entry:

```php
$s('My Team', 'Pasukan Saya', ['id' => 'progression', 'label' => 'Progression', 'label_ms' => 'Kemajuan Kerjaya', 'icon' => 'M12 20V10M18 20V4M6 20v-4']),
```

`Amanahku.php` page map, next to `'orgchart' => [...]`:

```php
'progression' => ['title' => 'Progression', 'title_ms' => 'Kemajuan Kerjaya', 'sub' => 'Confirm, update, resign or rehire a staff member. Every change lands on their timeline.', 'sub_ms' => 'Sahkan, kemas kini, berhenti atau ambil semula pekerja. Setiap perubahan direkod pada garis masa mereka.', 'crumb' => ['People', 'Progression']],
```

`AppController.php` line 163: add `'progression'` to the `['setup', 'settings', 'roles', …]` admin list (management + hr only; director collapses to management). Line ~564: `'progression' => app(ProgressionController::class)->screenData($request),`. `app.blade.php:192`: add `'progression'` to `$wideScreens`. Check `BuildsNav::navModel` hides it from managers automatically (it hides the My Team section for non management/hr unless the id is in `EMPLOYEE_TEAM_SCREENS`; do not add it there).

`ProgressionController::screenData`:

```php
public function screenData(Request $request): array
{
    $staff = Employee::query()->whereNull('archived_at')->with(['positionBand', 'department'])->orderBy('name')->get();
    $selected = $request->filled('emp') ? $staff->firstWhere('id', (int) $request->query('emp')) : null;
    $selected?->load(['progressions.recordedBy', 'reportsTo', 'branch', 'employmentType']);
    $action = in_array($request->query('action'), self::ACTIONS, true) ? $request->query('action') : 'confirmation';

    return [
        'staff' => $staff,
        'selected' => $selected,
        'action' => $action,
        'canSeeSalary' => $this->hasTenantRole($request, ['director', 'hr']),
        'allDepartments' => Department::orderBy('name')->get(['id', 'name']),
        'allBranches' => Branch::orderBy('name')->get(['id', 'name']),
        'allPositions' => Position::with(['department', 'staffLevel'])->orderBy('sort')->orderBy('title')->get(),
        'allEmploymentTypes' => EmploymentType::orderBy('name')->get(['id', 'name']),
        'allManagers' => Employee::active()->orderBy('name')->get(['id', 'name', 'nickname']),
    ];
}

public const ACTIONS = ['confirmation', 'update', 'resignation', 'rehire'];
```

`$staff->firstWhere` on the tenant-scoped collection is what keeps a foreign `?emp=` from resolving.

- [ ] **Step 4: Skeleton view** `screens/progression.blade.php`: two columns. Left `uj-card` (width 300px): search input (Alpine `x-model="q"` filtering rows client-side by name / staff_id), list of `$staff` rows as links to `?emp={id}&action={{ $action }}`, status chip. Right: if `$selected` null, a muted "Pick a staff member". Else header (avatar initials, name, phone, position, status chip, "Currently Reporting To: {name}") and four sub-tab links `?emp=…&action=confirmation|update|resignation|rehire` each with `data-action="…"` on the active one; the pane below is `@include("partials.progression.$action")` (partials created in Task 8, so for this task create four stub partials that print the action name).

- [ ] **Step 5: Run tests + `AllScreensRenderTest`,** expect PASS. Pint.

- [ ] **Step 6: Commit** `feat(progression): screen shell, gate and staff picker`.

---

### Task 8: Progression actions — four forms and their POST routes

**Files:**
- Modify: `app/Http/Controllers/ProgressionController.php` (add `confirm`, `update`, `resign`, `rehire`)
- Modify: `routes/web.php`
- Create: `resources/views/partials/progression/confirmation.blade.php`, `update.blade.php`, `resignation.blade.php`, `rehire.blade.php`, `current-vs-new.blade.php`, `history.blade.php`
- Test: `tests/Feature/ProgressionActionsTest.php`

**Produces:** routes (all POST, `->whereNumber('employee')`):
- `/app/progression/{employee}/confirm` → `progression.confirm`
- `/app/progression/{employee}/update` → `progression.update`
- `/app/progression/{employee}/resign` → `progression.resign`
- `/app/progression/{employee}/rehire` → `progression.rehire`

- [ ] **Step 1: Failing tests**

```php
public function test_confirm_via_screen(): void
{
    $this->login('hr');
    $e = $this->emp('Adibah');
    $this->post("/app/progression/{$e->id}/confirm", ['confirmed_on' => '2026-07-05', 'division' => 'Senior'])
        ->assertRedirect("/app/progression?emp={$e->id}&action=confirmation");
    $this->assertSame('active', $e->fresh()->status);
    $this->assertSame('confirmed', $e->progressions()->first()->type);
}

public function test_update_via_screen(): void
{
    $this->login('hr');
    $e = $this->emp('Adibah', ['status' => 'active']);
    $this->post("/app/progression/{$e->id}/update", ['effective_on' => '2026-03-01', 'section' => 'PMO', 'remark' => 'Moved'])->assertRedirect();
    $this->assertSame('PMO', $e->fresh()->section);
    $this->assertSame('Moved', $e->progressions()->first()->remark);
}

public function test_resign_and_rehire_via_screen(): void
{
    $this->login('hr');
    $e = $this->emp('Adibah', ['status' => 'active']);
    $this->post("/app/progression/{$e->id}/resign", ['resigned_on' => '2026-08-01', 'last_working_day' => '2026-08-31', 'reason' => 'resigned'])->assertRedirect();
    $this->assertSame('resigned', $e->fresh()->status);
    $this->post("/app/progression/{$e->id}/rehire", ['hired_on' => '2026-10-01', 'division' => 'Mid'])->assertRedirect();
    $this->assertSame('probation', $e->fresh()->status);
}

public function test_transition_error_returns_to_the_form(): void
{
    $this->login('hr');
    $e = $this->emp('Adibah', ['status' => 'active']);
    $this->post("/app/progression/{$e->id}/confirm", ['confirmed_on' => '2026-07-05'])->assertSessionHasErrors('confirmed_on');
}

public function test_manager_is_forbidden(): void
{
    $this->login('manager');
    $e = $this->emp('Adibah');
    $this->post("/app/progression/{$e->id}/confirm", ['confirmed_on' => '2026-07-05'])->assertForbidden();
}

public function test_other_tenant_employee_is_forbidden(): void
{
    $this->login('hr');
    $other = Tenant::create(['slug' => 'zed', 'name' => 'Zed', 'initials' => 'ZD']);
    $stranger = Employee::withoutGlobalScopes()->create(['tenant_id' => $other->id, 'name' => 'S', 'status' => 'probation', 'workload' => 'green']);
    $this->post("/app/progression/{$stranger->id}/confirm", ['confirmed_on' => '2026-07-05'])->assertForbidden();
}
```

- [ ] **Step 2: Run, expect FAIL.**

- [ ] **Step 3: Routes** (inside the same authenticated tenant group as `employees.update`):

```php
Route::post('/app/progression/{employee}/confirm', [ProgressionController::class, 'confirm'])->whereNumber('employee')->name('progression.confirm');
Route::post('/app/progression/{employee}/update', [ProgressionController::class, 'update'])->whereNumber('employee')->name('progression.update');
Route::post('/app/progression/{employee}/resign', [ProgressionController::class, 'resign'])->whereNumber('employee')->name('progression.resign');
Route::post('/app/progression/{employee}/rehire', [ProgressionController::class, 'rehire'])->whereNumber('employee')->name('progression.rehire');
```

- [ ] **Step 4: Controller actions**

```php
public function confirm(Request $request, Employee $employee, EmploymentRecordService $service): RedirectResponse
{
    $employee = $this->guard($request, $employee);
    $data = $request->validate(EmploymentRecordController::rules($employee->tenant_id, $employee) + ['confirmed_on' => ['required', 'date'], 'remark' => ['nullable', 'string', 'max:2000']]);

    return $this->run('confirmed_on', 'confirmation', $employee, fn () => $service->confirm(
        $employee, $data['confirmed_on'], EmploymentRecordController::fields($data, $this->hasTenantRole($request, ['director', 'hr'])), $data['remark'] ?? null, $request->attributes->get('employee')
    ));
}

public function update(Request $request, Employee $employee, EmploymentRecordService $service): RedirectResponse
{
    $employee = $this->guard($request, $employee);
    $data = $request->validate(EmploymentRecordController::rules($employee->tenant_id, $employee) + ['effective_on' => ['required', 'date'], 'remark' => ['nullable', 'string', 'max:2000']]);

    return $this->run('effective_on', 'update', $employee, fn () => $service->update(
        $employee, $data['effective_on'], EmploymentRecordController::fields($data, $this->hasTenantRole($request, ['director', 'hr'])), $data['remark'] ?? null, $request->attributes->get('employee')
    ));
}

public function resign(Request $request, Employee $employee, EmploymentRecordService $service): RedirectResponse
{
    $employee = $this->guard($request, $employee);
    $data = $request->validate([
        'resigned_on' => ['required', 'date'], 'last_working_day' => ['required', 'date'],
        'reason' => ['required', 'in:resigned,contract_ended,terminated,retired,other'], 'remark' => ['nullable', 'string', 'max:2000'],
    ]);

    return $this->run('resigned_on', 'resignation', $employee, fn () => $service->resign(
        $employee, $data['resigned_on'], $data['last_working_day'], $data['reason'], $data['remark'] ?? null, $request->attributes->get('employee')
    ));
}

public function rehire(Request $request, Employee $employee, EmploymentRecordService $service): RedirectResponse
{
    $employee = $this->guard($request, $employee);
    $data = $request->validate(EmploymentRecordController::rules($employee->tenant_id, $employee) + ['hired_on' => ['required', 'date'], 'remark' => ['nullable', 'string', 'max:2000']]);

    return $this->run('hired_on', 'rehire', $employee, fn () => $service->rehire(
        $employee, $data['hired_on'], EmploymentRecordController::fields($data, $this->hasTenantRole($request, ['director', 'hr'])), $data['remark'] ?? null, $request->attributes->get('employee')
    ));
}

private function guard(Request $request, Employee $employee): Employee
{
    $this->authorizeTenantRole($request, ['management', 'hr']);
    abort_unless($employee->tenant_id === app(CurrentTenant::class)->id(), 403);

    return $employee;
}

private function run(string $errorKey, string $action, Employee $employee, \Closure $do): RedirectResponse
{
    try {
        $do();
    } catch (EmploymentTransitionException $ex) {
        return back()->withInput()->withErrors([$errorKey => $ex->getMessage()]);
    }

    return redirect(route('app.screen', 'progression').'?emp='.$employee->id.'&action='.$action)->with('ok', $employee->name.' · '.ucfirst($action).' saved.');
}
```

- [ ] **Step 5: Views**

`partials/progression/current-vs-new.blade.php` — the Worksy two-column layout. Left column "CURRENT" read-only list of the same rows the Employment tab shows (reuse the `$rows` construction: extract that `@php` block from `employment-tab.blade.php` into `partials/profile/employment-rows.blade.php` that sets `$rows`, and include it from both places). Right column "NEW" = `@include('partials.profile.employment-form-fields', ['e' => $selected, 'canSeeSalary' => $canSeeSalary])`. A dashed arrow between the headers: `<div style="flex:1;border-top:2px dashed var(--info);margin:0 12px;"></div>`.

`confirmation.blade.php`:

```blade
@if ($selected->status !== 'probation')
    <p style="font-size:12.5px;color:var(--muted);">{!! $L('Already confirmed', 'Sudah disahkan') !!}{{ $selected->confirmed_at ? ' · '.$selected->confirmed_at->format('d/m/Y') : '' }}</p>
@else
<form method="post" action="{{ route('progression.confirm', $selected) }}" style="display:flex;flex-direction:column;gap:16px;">
    @csrf
    @if ($errors->any())<div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12px;border-radius:8px;padding:8px 11px;">{{ $errors->first() }}</div>@endif
    <div style="max-width:320px;"><label style="display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;">{!! $L('Date Confirmed', 'Tarikh Disahkan') !!} *</label><input type="date" name="confirmed_on" required value="{{ old('confirmed_on', now()->toDateString()) }}" style="{{ $fs }}" /></div>
    @include('partials.progression.current-vs-new')
    <div><label style="display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;">{!! $L('Remark', 'Catatan') !!}</label><textarea name="remark" rows="2" style="{{ $fs }}">{{ old('remark') }}</textarea></div>
    <button type="submit" class="uj-btn-primary" style="height:40px;font-size:13px;align-self:flex-start;padding:0 24px;">{!! $L('Confirm', 'Sahkan') !!}</button>
</form>
@endif
@include('partials.progression.history', ['type' => 'confirmed'])
```

`update.blade.php`: same shape, `effective_on` instead of `confirmed_on`, route `progression.update`, shown for status in active/probation/on_leave else "Rehire first". History type `updated`.

`resignation.blade.php`: hidden when status is `resigned` (show "Left on {last_working_day}"). Fields: `resigned_on` (date, default today), `last_working_day` (date; Alpine default = resigned_on + resign_notice_months months + resign_notice_days days, computed from `@js($selected->resign_notice_months)` etc.), `reason` select (Resigned / Contract ended / Terminated / Retired / Other), `remark`. Route `progression.resign`. History type `resigned`.

`rehire.blade.php`: only when status is `resigned`, else "Only resigned staff can be rehired." Fields: `hired_on` date + `@include('partials.profile.employment-form-fields', …)` (no Current column) + remark. Route `progression.rehire`. History type `rehired`.

`history.blade.php`: `$selected->progressions->where('type', $type)`; empty → "No Record Found" / "Tiada Rekod"; else the same card markup as the Timeline tab (extract the per-row card from `timeline-tab.blade.php` into `partials/profile/timeline-row.blade.php` taking `$row` and include it from both).

Define `$L` and `$fs` once at the top of `screens/progression.blade.php` (`$fs` copy the string from `profile.blade.php`).

- [ ] **Step 6: Run** `php artisan test --compact tests/Feature/ProgressionActionsTest.php tests/Feature/ProgressionScreenTest.php tests/Feature/ProfileEmploymentTabsTest.php tests/Feature/AllScreensRenderTest.php`, expect PASS. Pint.

- [ ] **Step 7: Commit** `feat(progression): confirm, update, resign and rehire forms`.

---

### Task 9: Dev DB migrate, browser check, assets, full suite

**Files:**
- Modify: `public/build/*` (rebuilt)
- Modify: `docs/superpowers/specs/2026-09-15-employee-record-and-progression-design.md` only if the build deviated from it.

- [ ] **Step 1:** `lerd artisan migrate` (dev MySQL). Confirm: `lerd artisan tinker --execute 'echo App\Models\EmployeeProgression::count();'` equals active headcount.

- [ ] **Step 2:** `lerd artisan view:clear && lerd artisan view:cache && bun run build`.

- [ ] **Step 3: Browser walk (integrated browser MCP, `http://localhost:9100`):**
  1. Quick-login as HR (hidayahsuffya). Open Employees → any staff → Employment tab shows the header strip and grid; Timeline tab shows one Hired card.
  2. Edit employment: change Division, save → returns on the Employment tab, Timeline now has an Updated card with Division highlighted.
  3. Sidebar → Progression. Pick a `probation` person → Confirmation form → save → status chip reads Active, Confirmation history shows the row.
  4. Resignation → save → Rehire tab now enabled → rehire → status Probation.
  5. Quick-login as Kussairi (manager): profile of a report has no Employment/Timeline tabs; `/app/progression` is 403; sidebar has no Progression item.
  6. Quick-login as Shazwan (employee): own profile shows both tabs, no salary line.
  Screenshot each state to the scratchpad.

- [ ] **Step 4:** `php artisan test --compact` (whole suite, 80% coverage floor applies in CI). Fix anything red.

- [ ] **Step 5: Commit**

```bash
git add public/build
git commit -m "build: assets for Employment/Timeline tabs and Progression screen"
```

- [ ] **Step 6:** Report: what changed, what was skipped, next sub-project (Personal + Family) per the spec.

---

## Self-review against the spec (section 1)

| Spec item | Task |
|---|---|
| `employee_progressions` table, columns, append-only | 1, 2 |
| New `employees` columns | 1 |
| Backfill `hired` rows, drop `career_timeline` | 3 |
| Service with hire/confirm/update/resign/rehire, transition table, date rules, no-op update | 2 |
| Both edit paths use the service | 4, 6, 8 |
| Employment tab fields, header strip, edit modal, salary gate | 5, 6 |
| Timeline tab, newest-first, highlighted changes, footer, salary hidden on self-view | 6 |
| Progression screen: placement, gate, staff list, header, four sub-tabs, history per type | 7, 8 |
| Confirmation only for probation; rehire only for resigned; last working day default | 8 |
| Resignation does not archive | 2 (never touches `archived_at`) |
| Audit on every write | 2 |
| Tenant re-check | 6, 8 |
| Tests listed in the spec | 2, 3, 5, 6, 7, 8 |
| Mockup approval before build | Not in this plan: the user said "I trust you, proceed". The Task 9 browser walk stands in. |
| Batch actions | Sub-project 5, separate plan |
