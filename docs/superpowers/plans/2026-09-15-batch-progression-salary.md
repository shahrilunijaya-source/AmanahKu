# Batch Progression Update + Batch Salary Adjustment Implementation Plan (sub-project 5)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Two batch modes on the Progression screen: change the same employment fields for many staff at once, and adjust many salaries by amount or percentage, both all-or-nothing through `EmploymentRecordService::update`.

**Architecture:** One new `BatchProgressionController` with `update` and `salary` actions. Each validates the whole request, then runs one outer `DB::transaction` calling `EmploymentRecordService::update` per person (its inner transaction becomes a savepoint), so any `EmploymentTransitionException` or band refusal rolls everything back. The screen gets a `?batch=update|salary` mode that replaces the staff picker + person panel with a full-width picker table (filters, tick boxes) and the field/adjustment form; the preview is computed in Alpine from data attributes on the rows, the server recomputes on confirm.

**Tech Stack:** Laravel 13 / PHP 8.5, Blade + Alpine, PHPUnit on sqlite, Pint.

**Spec:** `docs/superpowers/specs/2026-09-15-employee-record-and-progression-design.md` § 5 and "Shared rules".

## Global Constraints

- Progression screen gate: `authorizeTenantRole($request, ['management','hr'])` (director collapses into management). Batch salary additionally `hasTenantRole($request, ['director','hr'])` → 403 otherwise.
- Max 200 employee ids per run. All-or-nothing.
- Every person gets one `updated` progression row (the service skips people where nothing changed) and one audit entry (the service writes "Updated employment record"); the batch adds one summary `AuditLog::record` line.
- Salary band: refuse when `positionBand->max_salary > 0` and new salary exceeds it, unless `override_band` is ticked; an override writes `AuditLog::record('Batch salary band override', ...)`.
- Money maths: `round($x, 2)`.
- Route-model binding is NOT tenant-safe; ids are validated with `Rule::exists(...)->where('tenant_id', ...)` and loaded via the tenant-scoped `Employee` query.
- Bilingual labels via the `$L` helper already defined in `screens/progression.blade.php`. Section headings `class="uj-section-head"`.
- Tests `php artisan test --compact <file>`; Pint; assets `lerd artisan view:clear && lerd artisan view:cache && bun run build`, commit `public/build` if changed. Work on `dev`, commit per task.

---

## File map

| File | Responsibility |
|---|---|
| `app/Http/Controllers/BatchProgressionController.php` | `update`, `salary`; `BATCH_FIELDS`; salary maths helper |
| `routes/web.php` | 2 POST routes before the `{employee}` progression routes |
| `app/Http/Controllers/ProgressionController.php` | `screenData` gains `batch`, `batchStaff`, `canBatchSalary` |
| `resources/views/screens/progression.blade.php` | mode links; batch layout when `$batch` set |
| `resources/views/partials/progression/batch-picker.blade.php` | filters + tick table (shared) |
| `resources/views/partials/progression/batch-update.blade.php` | ticked fields form + preview + confirm |
| `resources/views/partials/progression/batch-salary.blade.php` | mode/value form + preview + confirm |
| `tests/Feature/BatchProgressionUpdateTest.php`, `tests/Feature/BatchSalaryAdjustmentTest.php`, `tests/Feature/ProgressionScreenTest.php` (+2 tests) | tests |

---

### Task 1: BatchProgressionController::update + routes

**Files:**
- Create: `app/Http/Controllers/BatchProgressionController.php`
- Modify: `routes/web.php` (insert before `progression.confirm`)
- Test: `tests/Feature/BatchProgressionUpdateTest.php`

**Interfaces:**
- `progression.batch.update` POST `/app/progression/batch/update`. Body: `employee_ids[]`, `effective_on`, `fields[]` (subset of `BATCH_FIELDS`), one input per ticked field (same names as `Employee::EMPLOYMENT_FIELDS`), `remark`. Redirect `?batch=update` with `ok`.
- `BatchProgressionController::BATCH_FIELDS = ['department_id','branch_id','division','section','position_id','reports_to_id','employment_type_id','payment_term','payment_method']`.

- [ ] **Step 1: Failing test** (helpers `setUp`/`login`/`emp` copied verbatim from `tests/Feature/ProgressionScreenTest.php`; note `emp()` defaults to `probation`)

```php
    public function test_hr_updates_many_staff_in_one_run(): void
    {
        $this->login('hr');
        $d = Department::create(['tenant_id' => $this->tenant->id, 'name' => 'Ops']);
        $a = $this->emp('A'); $b = $this->emp('B', ['status' => 'active']);
        $this->post('/app/progression/batch/update', ['employee_ids' => [$a->id, $b->id], 'effective_on' => '2026-03-01', 'fields' => ['department_id', 'payment_term'], 'department_id' => $d->id, 'payment_term' => 'weekly', 'remark' => 'Batch'])
            ->assertRedirect('/app/progression?batch=update');
        $this->assertSame($d->id, $a->fresh()->department_id);
        $this->assertSame('weekly', $b->fresh()->payment_term);
        $this->assertSame(2, EmployeeProgression::where('type', 'updated')->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'Batch progression update']);
    }

    public function test_one_resigned_person_rolls_back_the_whole_batch(): void
    {
        $this->login('hr');
        $d = Department::create(['tenant_id' => $this->tenant->id, 'name' => 'Ops']);
        $a = $this->emp('A'); $gone = $this->emp('Gone', ['status' => 'resigned']);
        $this->from('/app/progression?batch=update')->post('/app/progression/batch/update', ['employee_ids' => [$a->id, $gone->id], 'effective_on' => '2026-03-01', 'fields' => ['department_id'], 'department_id' => $d->id])
            ->assertRedirect('/app/progression?batch=update')->assertSessionHasErrors('employee_ids');
        $this->assertNull($a->fresh()->department_id);
        $this->assertSame(0, EmployeeProgression::where('type', 'updated')->count());
    }

    public function test_unticked_fields_are_ignored_and_over_200_is_refused(): void
    {
        $this->login('hr');
        $a = $this->emp('A');
        $this->post('/app/progression/batch/update', ['employee_ids' => [$a->id], 'effective_on' => '2026-03-01', 'fields' => ['division'], 'division' => 'North', 'section' => 'Ignored'])->assertRedirect();
        $this->assertSame('North', $a->fresh()->division);
        $this->assertNull($a->fresh()->section);
        $ids = array_fill(0, 201, $a->id);
        $this->post('/app/progression/batch/update', ['employee_ids' => $ids, 'effective_on' => '2026-03-01', 'fields' => ['division'], 'division' => 'X'])->assertSessionHasErrors('employee_ids');
    }

    public function test_manager_and_employee_are_forbidden(): void
    {
        $a = $this->emp('A');
        $this->login('manager');
        $this->post('/app/progression/batch/update', ['employee_ids' => [$a->id], 'effective_on' => '2026-03-01', 'fields' => ['division'], 'division' => 'X'])->assertForbidden();
        $this->login('employee');
        $this->post('/app/progression/batch/update', ['employee_ids' => [$a->id], 'effective_on' => '2026-03-01', 'fields' => ['division'], 'division' => 'X'])->assertForbidden();
    }
```
Imports: `App\Models\Department`, `App\Models\EmployeeProgression`, `App\Models\Employee`, `App\Models\Tenant`, `App\Models\User`, `App\Tenancy\CurrentTenant`, `Illuminate\Support\Facades\Hash`, `RefreshDatabase`.

- [ ] **Step 2: Run → FAIL** (404).

- [ ] **Step 3: Routes** — before the line with `progression.confirm`:
```php
        Route::post('/app/progression/batch/update', [BatchProgressionController::class, 'update'])->name('progression.batch.update');
        Route::post('/app/progression/batch/salary', [BatchProgressionController::class, 'salary'])->name('progression.batch.salary');
```
Import `App\Http\Controllers\BatchProgressionController`.

- [ ] **Step 4: Controller**

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Services\EmploymentRecordService;
use App\Services\EmploymentTransitionException;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Batch Progression Update and Batch Salary Adjustment (Progression screen).
 * Whole run inside one transaction: the service's per-person transaction becomes a
 * savepoint, so one refused person rolls back everyone.
 */
class BatchProgressionController extends EmploymentRecordController
{
    public const BATCH_FIELDS = ['department_id', 'branch_id', 'division', 'section', 'position_id', 'reports_to_id', 'employment_type_id', 'payment_term', 'payment_method'];

    public const MAX_ROWS = 200;

    public function update(Request $request, EmploymentRecordService $service): RedirectResponse
    {
        $this->authorizeTenantRole($request, ['management', 'hr']);
        $tenantId = app(CurrentTenant::class)->id();

        $data = $request->validate($this->batchRules($tenantId) + [
            'fields' => ['required', 'array', 'min:1'],
            'fields.*' => [Rule::in(self::BATCH_FIELDS)],
        ] + array_intersect_key($this->rules($tenantId), array_flip(self::BATCH_FIELDS)));

        // Only the ticked fields travel; an empty value on a ticked field clears it.
        $fields = [];
        foreach ($data['fields'] as $key) {
            $fields[$key] = ($data[$key] ?? '') === '' ? null : $data[$key];
        }

        return $this->run($request, 'update', $data['employee_ids'], function (Employee $e) use ($service, $data, $fields, $request) {
            $service->update($e, $data['effective_on'], $fields, $data['remark'] ?? null, $request->attributes->get('employee'));
        }, 'Batch progression update');
    }

    public function salary(Request $request, EmploymentRecordService $service): RedirectResponse
    {
        $this->authorizeTenantRole($request, ['management', 'hr']);
        abort_unless($this->hasTenantRole($request, ['director', 'hr']), 403);
        $tenantId = app(CurrentTenant::class)->id();

        $data = $request->validate($this->batchRules($tenantId) + [
            'mode' => ['required', 'in:increase_amount,increase_percent,set_amount'],
            'value' => ['required', 'numeric', 'min:0', 'max:10000000'],
            'override_band' => ['nullable', 'boolean'],
        ]);
        $override = (bool) ($data['override_band'] ?? false);

        return $this->run($request, 'salary', $data['employee_ids'], function (Employee $e) use ($service, $data, $override, $request) {
            $new = self::adjust((float) ($e->salary ?? 0), $data['mode'], (float) $data['value']);
            $max = (float) ($e->positionBand?->max_salary ?? 0);
            if ($max > 0 && $new > $max) {
                if (! $override) {
                    throw new EmploymentTransitionException($e->name.': RM '.number_format($new, 2).' is above the '.$e->positionBand->title.' band maximum of RM '.number_format($max, 2).'. Tick "override band" to allow it.');
                }
                AuditLog::record('Batch salary band override', $e->name.' · RM '.number_format($new, 2).' > RM '.number_format($max, 2));
            }
            $service->update($e, $data['effective_on'], ['salary' => $new], $data['remark'] ?? null, $request->attributes->get('employee'));
        }, 'Batch salary adjustment');
    }

    /** New salary for one mode; always 2 dp. */
    public static function adjust(float $current, string $mode, float $value): float
    {
        return round(match ($mode) {
            'increase_amount' => $current + $value,
            'increase_percent' => $current * (1 + $value / 100),
            'set_amount' => $value,
        }, 2);
    }

    /** @return array<string, array<int, mixed>> */
    private function batchRules(int $tenantId): array
    {
        return [
            'employee_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_ROWS],
            'employee_ids.*' => ['integer', 'distinct', Rule::exists('employees', 'id')->where(fn ($q) => $q->where('tenant_id', $tenantId)->whereNull('archived_at'))],
            'effective_on' => ['required', 'date'],
            'remark' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @param  list<int>  $ids
     * @param  \Closure(Employee): void  $each
     */
    private function run(Request $request, string $batch, array $ids, \Closure $each, string $summary): RedirectResponse
    {
        $people = Employee::with('positionBand')->whereIn('id', $ids)->get();

        try {
            DB::transaction(function () use ($people, $each) {
                foreach ($people as $e) {
                    $each($e);
                }
            });
        } catch (EmploymentTransitionException $ex) {
            return back()->withInput()->withErrors(['employee_ids' => $ex->getMessage()]);
        }

        AuditLog::record($summary, $people->count().' staff · effective '.$request->input('effective_on'));

        return redirect(route('app.screen', 'progression').'?batch='.$batch)->with('ok', $summary.' saved for '.$people->count().' staff.');
    }
}
```
`EmploymentTransitionException` thrown inside `DB::transaction` propagates after rollback — that is the all-or-nothing. The service's own `assertStatus` runs before its inner transaction but still inside ours, so a resigned person mid-list rolls back the earlier people. Check `EmploymentRecordController::rules` is `protected` (it is) and that `EmploymentTransitionException` message for resigned staff mentions rehire (test asserts only the error key).

- [ ] **Step 5: Run → PASS.** Pint.
- [ ] **Step 6: Commit** `git add app routes tests && git commit -m "feat(progression): batch progression update, all-or-nothing"`

---

### Task 2: Batch salary adjustment tests

**Files:**
- Test: `tests/Feature/BatchSalaryAdjustmentTest.php` (controller already written in Task 1)

- [ ] **Step 1: Tests** (same helpers)

```php
    public function test_percentage_fixed_and_set_maths_round_to_2dp(): void
    {
        $this->login('hr');
        $a = $this->emp('A', ['salary' => 1234.56]); $b = $this->emp('B', ['salary' => 1000]);
        $this->post('/app/progression/batch/salary', ['employee_ids' => [$a->id, $b->id], 'effective_on' => '2026-03-01', 'mode' => 'increase_percent', 'value' => 10])->assertRedirect('/app/progression?batch=salary');
        $this->assertSame(1358.02, (float) $a->fresh()->salary);
        $this->assertSame(1100.0, (float) $b->fresh()->salary);
        $this->post('/app/progression/batch/salary', ['employee_ids' => [$a->id], 'effective_on' => '2026-03-02', 'mode' => 'increase_amount', 'value' => 100.005])->assertRedirect();
        $this->assertSame(1458.03, (float) $a->fresh()->salary);
        $this->post('/app/progression/batch/salary', ['employee_ids' => [$a->id], 'effective_on' => '2026-03-03', 'mode' => 'set_amount', 'value' => 2000])->assertRedirect();
        $this->assertSame(2000.0, (float) $a->fresh()->salary);
        $this->assertSame(3, EmployeeProgression::where('employee_id', $a->id)->where('type', 'updated')->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'Batch salary adjustment']);
    }

    public function test_band_maximum_refused_without_override_and_logged_with_it(): void
    {
        $this->login('hr');
        $band = Position::create(['tenant_id' => $this->tenant->id, 'title' => 'Junior', 'max_salary' => 3000]);
        $a = $this->emp('A', ['salary' => 2900, 'position_id' => $band->id]);
        $this->from('/app/progression?batch=salary')->post('/app/progression/batch/salary', ['employee_ids' => [$a->id], 'effective_on' => '2026-03-01', 'mode' => 'increase_amount', 'value' => 500])
            ->assertSessionHasErrors('employee_ids');
        $this->assertSame(2900.0, (float) $a->fresh()->salary);
        $this->post('/app/progression/batch/salary', ['employee_ids' => [$a->id], 'effective_on' => '2026-03-01', 'mode' => 'increase_amount', 'value' => 500, 'override_band' => 1])->assertRedirect();
        $this->assertSame(3400.0, (float) $a->fresh()->salary);
        $this->assertDatabaseHas('audit_logs', ['action' => 'Batch salary band override']);
    }

    public function test_management_without_director_is_forbidden_director_allowed(): void
    {
        $a = $this->emp('A', ['salary' => 1000]);
        $this->login('management');
        $this->post('/app/progression/batch/salary', ['employee_ids' => [$a->id], 'effective_on' => '2026-03-01', 'mode' => 'set_amount', 'value' => 1])->assertForbidden();
        $this->login('director');
        $this->post('/app/progression/batch/salary', ['employee_ids' => [$a->id], 'effective_on' => '2026-03-01', 'mode' => 'set_amount', 'value' => 1500])->assertRedirect();
        $this->assertSame(1500.0, (float) $a->fresh()->salary);
    }
```
Imports add `App\Models\Position`. If `Position::create` needs more required columns (check `2026_06_25_000001_create_positions_table.php`), add them.

- [ ] **Step 2: Run → PASS** (if the `management` role login fails because `hasTenantRole(['director','hr'])` treats management as director, read `Permissions::effectiveRole` and adjust the expectation; the spec rule is director/hr only).
- [ ] **Step 3: Commit** `git add tests && git commit -m "test(progression): batch salary maths, band override, salary role"`

---

### Task 3: Screen — mode links, picker, two batch forms

**Files:**
- Modify: `app/Http/Controllers/ProgressionController.php::screenData`, `resources/views/screens/progression.blade.php`
- Create: `resources/views/partials/progression/batch-picker.blade.php`, `batch-update.blade.php`, `batch-salary.blade.php`
- Test: add to `tests/Feature/ProgressionScreenTest.php`

- [ ] **Step 1: Failing tests**

```php
    public function test_batch_modes_render_for_hr_and_salary_mode_hidden_from_plain_management(): void
    {
        $this->login('hr');
        $a = $this->emp('A', ['salary' => 1000]);
        $this->get('/app/progression?batch=update')->assertOk()->assertSee('name="fields[]"', false)->assertSee('data-emp="'.$a->id.'"', false)->assertSee(route('progression.batch.update'));
        $this->get('/app/progression?batch=salary')->assertOk()->assertSee('name="mode"', false)->assertSee('data-salary="1000.00"', false)->assertSee(route('progression.batch.salary'));
        $this->login('management');
        $this->get('/app/progression')->assertOk()->assertDontSee('?batch=salary');
        $this->get('/app/progression?batch=salary')->assertOk()->assertDontSee('name="mode"', false);
    }
```

- [ ] **Step 2: screenData** — add before `return`:
```php
        $batch = in_array($request->query('batch'), ['update', 'salary'], true) ? $request->query('batch') : null;
        $canBatchSalary = $this->hasTenantRole($request, ['director', 'hr']);
        if ($batch === 'salary' && ! $canBatchSalary) {
            $batch = null;
        }
```
and return keys `'batch' => $batch, 'canBatchSalary' => $canBatchSalary, 'batchStaff' => $batch ? $staff->load(['branch', 'reportsTo', 'employmentType']) : collect()`.

- [ ] **Step 3: progression.blade.php**

After `$screenUrl = ...` in the `@php` block add:
```php
    $batchModes = ['update' => ['Batch Progression Update', 'Kemas Kini Berkumpulan'], 'salary' => ['Batch Salary Adjustment', 'Pelarasan Gaji Berkumpulan']];
    if (! ($canBatchSalary ?? false)) { unset($batchModes['salary']); }
```
Before the outer `<div style="display:flex;gap:16px;...">` insert a mode strip:
```blade
<div style="display:flex;gap:6px;margin-bottom:14px;flex-wrap:wrap;">
    <a href="{{ $screenUrl }}" class="uj-btn-ghost" style="height:32px;display:inline-flex;align-items:center;padding:0 14px;font-size:12.5px;{{ $batch ? '' : 'background:var(--red);color:#fff;border-color:var(--red);' }}">{!! $L('Single staff', 'Seorang') !!}</a>
    @foreach ($batchModes as $key => [$en, $ms])
        <a href="{{ $screenUrl }}?batch={{ $key }}" class="uj-btn-ghost" style="height:32px;display:inline-flex;align-items:center;padding:0 14px;font-size:12.5px;{{ $batch === $key ? 'background:var(--red);color:#fff;border-color:var(--red);' : '' }}">{!! $L($en, $ms) !!}</a>
    @endforeach
</div>
@if ($batch)
    <div class="uj-card" style="padding:20px;display:flex;flex-direction:column;gap:20px;">@include("partials.progression.batch-$batch")</div>
@else
```
and close with `@endif` right before `@endsection` (wrapping the existing two-column layout).

- [ ] **Step 4: `batch-picker.blade.php`** — expects `$batchStaff`, `$allDepartments`, `$allBranches`, `$allPositions`, `$fs`, `$L`, `$stL`. Lives inside the parent form and the parent `x-data`.

```blade
{{-- Filters + tick table. Parent x-data must define: ids (array of selected ids as strings), f {dept, branch, pos, status}.
     Each row carries data attributes the preview reads. --}}
<div class="uj-section-head">{!! $L('1 · Pick staff', '1 · Pilih staf') !!}</div>
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:8px 12px;">
    <select x-model="f.dept" style="{{ $fs }}"><option value="">{!! $L('All departments', 'Semua jabatan') !!}</option>@foreach ($allDepartments as $o)<option value="{{ $o->id }}">{{ $o->name }}</option>@endforeach</select>
    <select x-model="f.branch" style="{{ $fs }}"><option value="">{!! $L('All branches', 'Semua cawangan') !!}</option>@foreach ($allBranches as $o)<option value="{{ $o->id }}">{{ $o->name }}</option>@endforeach</select>
    <select x-model="f.pos" style="{{ $fs }}"><option value="">{!! $L('All positions', 'Semua jawatan') !!}</option>@foreach ($allPositions as $o)<option value="{{ $o->id }}">{{ $o->title }}</option>@endforeach</select>
    <select x-model="f.status" style="{{ $fs }}"><option value="">{!! $L('All statuses', 'Semua status') !!}</option>@foreach ($stL as $k => [$en, $ms])<option value="{{ $k }}">{{ $en }}</option>@endforeach</select>
</div>
<div style="display:flex;gap:8px;align-items:center;font-size:12.5px;color:var(--muted);">
    <button type="button" class="uj-btn-ghost" style="height:30px;padding:0 12px;font-size:12px;" @click="ids = [...new Set([...ids, ...visible()])]">{!! $L('Select all shown', 'Pilih semua') !!}</button>
    <button type="button" class="uj-btn-ghost" style="height:30px;padding:0 12px;font-size:12px;" @click="ids = []">{!! $L('Clear', 'Kosongkan') !!}</button>
    <span x-text="ids.length + ' selected'"></span><span x-show="ids.length > 200" style="color:var(--red);">· max 200</span>
</div>
<div style="max-height:360px;overflow:auto;border:1px solid var(--hairline-soft);border-radius:8px;">
    <table style="width:100%;border-collapse:collapse;font-size:12.5px;">
        <thead><tr style="background:var(--canvas);color:var(--muted);font-size:11px;text-align:left;"><th style="padding:8px;"></th><th style="padding:8px;">{!! $L('Name', 'Nama') !!}</th><th style="padding:8px;">{!! $L('Department', 'Jabatan') !!}</th><th style="padding:8px;">{!! $L('Branch', 'Cawangan') !!}</th><th style="padding:8px;">{!! $L('Position', 'Jawatan') !!}</th><th style="padding:8px;">Status</th></tr></thead>
        <tbody>
        @foreach ($batchStaff as $s)
            <tr data-emp="{{ $s->id }}" data-dept="{{ $s->department_id }}" data-branch="{{ $s->branch_id }}" data-pos="{{ $s->position_id }}" data-status="{{ $s->status }}"
                data-name="{{ $s->name }}" data-cur-department_id="{{ $s->department?->name ?? '—' }}" data-cur-branch_id="{{ $s->branch?->name ?? '—' }}" data-cur-position_id="{{ $s->positionBand?->title ?? '—' }}"
                data-cur-reports_to_id="{{ $s->reportsTo?->name ?? '—' }}" data-cur-employment_type_id="{{ $s->employmentType?->name ?? '—' }}" data-cur-division="{{ $s->division ?? '—' }}" data-cur-section="{{ $s->section ?? '—' }}"
                data-cur-payment_term="{{ $s->payment_term ?? '—' }}" data-cur-payment_method="{{ $s->payment_method ?? '—' }}" data-salary="{{ number_format((float) ($s->salary ?? 0), 2, '.', '') }}"
                x-show="show($el)" style="border-top:1px solid var(--hairline-soft);">
                <td style="padding:6px 8px;"><input type="checkbox" name="employee_ids[]" value="{{ $s->id }}" x-model="ids" /></td>
                <td style="padding:6px 8px;color:var(--ink);font-weight:500;">{{ $s->name }}</td>
                <td style="padding:6px 8px;">{{ $s->department?->name ?? '—' }}</td><td style="padding:6px 8px;">{{ $s->branch?->name ?? '—' }}</td><td style="padding:6px 8px;">{{ $s->positionBand?->title ?? '—' }}</td><td style="padding:6px 8px;">{{ $stL[$s->status][0] ?? $s->status }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
```

- [ ] **Step 5: `batch-update.blade.php`**

```blade
@php $fieldL = ['department_id' => ['Department', 'Jabatan'], 'branch_id' => ['Branch', 'Cawangan'], 'division' => ['Division', 'Bahagian'], 'section' => ['Section', 'Seksyen'], 'position_id' => ['Position', 'Jawatan'], 'reports_to_id' => ['Reporting To', 'Melapor Kepada'], 'employment_type_id' => ['Employment Type', 'Jenis Pekerjaan'], 'payment_term' => ['Payment Term', 'Tempoh Bayaran'], 'payment_method' => ['Payment Method', 'Kaedah Bayaran']]; @endphp
<form method="post" action="{{ route('progression.batch.update') }}" style="display:flex;flex-direction:column;gap:18px;"
      x-data="{ ids: @js(array_map('strval', (array) old('employee_ids', []))), f: { dept: '', branch: '', pos: '', status: '' }, fields: @js((array) old('fields', [])), vals: {},
                show(row) { const d = row.dataset; return (!this.f.dept || d.dept === this.f.dept) && (!this.f.branch || d.branch === this.f.branch) && (!this.f.pos || d.pos === this.f.pos) && (!this.f.status || d.status === this.f.status); },
                visible() { return [...$el.querySelectorAll('tr[data-emp]')].filter(r => this.show(r)).map(r => r.dataset.emp); },
                rows() { return [...$el.querySelectorAll('tr[data-emp]')].filter(r => this.ids.includes(r.dataset.emp)); },
                newText(k) { const el = $el.querySelector('[name=' + k + ']'); if (!el) return ''; return el.tagName === 'SELECT' ? (el.selectedOptions[0]?.text ?? '') : el.value; } }">
    @csrf
    @if ($errors->any())<div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12px;border-radius:8px;padding:8px 11px;">{{ $errors->first() }}</div>@endif
    @include('partials.progression.batch-picker')

    <div class="uj-section-head">{!! $L('2 · What changes', '2 · Apa yang berubah') !!}</div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px 16px;">
        <div><label style="display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;">{!! $L('Effective date', 'Tarikh berkuat kuasa') !!}</label><input type="date" name="effective_on" required value="{{ old('effective_on', now()->toDateString()) }}" style="{{ $fs }}" /></div>
        <div><label style="display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;">{!! $L('Remark', 'Catatan') !!}</label><input name="remark" maxlength="2000" value="{{ old('remark') }}" style="{{ $fs }}" /></div>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px 16px;">
        @foreach ($fieldL as $k => [$en, $ms])
            <div>
                <label style="display:flex;align-items:center;gap:8px;font-size:12.5px;color:var(--ink);margin-bottom:4px;"><input type="checkbox" name="fields[]" value="{{ $k }}" x-model="fields" /> {!! $L($en, $ms) !!}</label>
                <div x-show="fields.includes('{{ $k }}')" x-cloak>
                    @switch($k)
                        @case('department_id') <select name="department_id" style="{{ $fs }}"><option value="">—</option>@foreach ($allDepartments as $o)<option value="{{ $o->id }}" @selected(old('department_id') == $o->id)>{{ $o->name }}</option>@endforeach</select> @break
                        @case('branch_id') <select name="branch_id" style="{{ $fs }}"><option value="">—</option>@foreach ($allBranches as $o)<option value="{{ $o->id }}" @selected(old('branch_id') == $o->id)>{{ $o->name }}</option>@endforeach</select> @break
                        @case('position_id') <select name="position_id" style="{{ $fs }}"><option value="">—</option>@foreach ($allPositions as $o)<option value="{{ $o->id }}" @selected(old('position_id') == $o->id)>{{ $o->title }}</option>@endforeach</select> @break
                        @case('reports_to_id') <select name="reports_to_id" style="{{ $fs }}"><option value="">—</option>@foreach ($allManagers as $o)<option value="{{ $o->id }}" @selected(old('reports_to_id') == $o->id)>{{ $o->name }}</option>@endforeach</select> @break
                        @case('employment_type_id') <select name="employment_type_id" style="{{ $fs }}"><option value="">—</option>@foreach ($allEmploymentTypes as $o)<option value="{{ $o->id }}" @selected(old('employment_type_id') == $o->id)>{{ $o->name }}</option>@endforeach</select> @break
                        @case('payment_term') <select name="payment_term" style="{{ $fs }}">@foreach (['daily' => 'Daily', 'weekly' => 'Weekly', 'biweekly' => 'Bi-Weekly', 'monthly' => 'Monthly'] as $v => $t)<option value="{{ $v }}" @selected(old('payment_term', 'monthly') === $v)>{{ $t }}</option>@endforeach</select> @break
                        @case('payment_method') <select name="payment_method" style="{{ $fs }}">@foreach (['cash' => 'Cash', 'bank' => 'Bank', 'cheque' => 'Cheque'] as $v => $t)<option value="{{ $v }}" @selected(old('payment_method', 'bank') === $v)>{{ $t }}</option>@endforeach</select> @break
                        @default <input name="{{ $k }}" maxlength="80" value="{{ old($k) }}" style="{{ $fs }}" />
                    @endswitch
                </div>
            </div>
        @endforeach
    </div>

    <div class="uj-section-head">{!! $L('3 · Preview', '3 · Pratonton') !!}</div>
    <div x-show="!ids.length || !fields.length" style="font-size:12.5px;color:var(--muted);">{!! $L('Pick staff and tick at least one field to see the preview.', 'Pilih staf dan tanda sekurang-kurangnya satu medan untuk pratonton.') !!}</div>
    <div x-show="ids.length && fields.length" style="overflow:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:12.5px;">
            <thead><tr style="color:var(--muted);font-size:11px;text-align:left;"><th style="padding:6px 8px;">{!! $L('Name', 'Nama') !!}</th><template x-for="k in fields" :key="k"><th style="padding:6px 8px;" x-text="({{ json_encode(array_map(fn ($p) => $p[0], $fieldL)) }})[k]"></th></template></tr></thead>
            <tbody><template x-for="r in rows()" :key="r.dataset.emp"><tr style="border-top:1px solid var(--hairline-soft);"><td style="padding:6px 8px;color:var(--ink);font-weight:500;" x-text="r.dataset.name"></td>
                <template x-for="k in fields" :key="k"><td style="padding:6px 8px;"><span style="color:var(--muted);" x-text="r.dataset['cur-' + k] ?? r.getAttribute('data-cur-' + k)"></span> → <b x-text="newText(k) || '—'"></b></td></template></tr></template></tbody>
        </table>
    </div>
    <button type="submit" class="uj-btn-primary" :disabled="!ids.length || !fields.length || ids.length > 200" style="height:40px;padding:0 18px;font-size:13px;align-self:flex-start;">{!! $L('Confirm batch update', 'Sahkan kemas kini berkumpulan') !!}</button>
</form>
```
`dataset['cur-department_id']` — dataset camel-cases `data-cur-department_id` to `curDepartment_id`, so use `r.getAttribute('data-cur-' + k)` only (drop the `dataset[...] ??` part).

- [ ] **Step 6: `batch-salary.blade.php`**

```blade
<form method="post" action="{{ route('progression.batch.salary') }}" style="display:flex;flex-direction:column;gap:18px;"
      x-data="{ ids: @js(array_map('strval', (array) old('employee_ids', []))), f: { dept: '', branch: '', pos: '', status: '' }, mode: @js(old('mode', 'increase_percent')), value: @js(old('value', '')),
                show(row) { const d = row.dataset; return (!this.f.dept || d.dept === this.f.dept) && (!this.f.branch || d.branch === this.f.branch) && (!this.f.pos || d.pos === this.f.pos) && (!this.f.status || d.status === this.f.status); },
                visible() { return [...$el.querySelectorAll('tr[data-emp]')].filter(r => this.show(r)).map(r => r.dataset.emp); },
                rows() { return [...$el.querySelectorAll('tr[data-emp]')].filter(r => this.ids.includes(r.dataset.emp)); },
                calc(cur) { const v = parseFloat(this.value) || 0; const n = this.mode === 'increase_amount' ? cur + v : this.mode === 'increase_percent' ? cur * (1 + v / 100) : v; return Math.round(n * 100) / 100; },
                rm(n) { return 'RM ' + n.toLocaleString('en-MY', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); } }">
    @csrf
    @if ($errors->any())<div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12px;border-radius:8px;padding:8px 11px;">{{ $errors->first() }}</div>@endif
    @include('partials.progression.batch-picker')

    <div class="uj-section-head">{!! $L('2 · Adjustment', '2 · Pelarasan') !!}</div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px 16px;">
        <div><label style="display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;">{!! $L('Effective date', 'Tarikh berkuat kuasa') !!}</label><input type="date" name="effective_on" required value="{{ old('effective_on', now()->toDateString()) }}" style="{{ $fs }}" /></div>
        <div><label style="display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;">{!! $L('Mode', 'Mod') !!}</label>
            <select name="mode" x-model="mode" style="{{ $fs }}"><option value="increase_percent">{!! $L('Increase by %', 'Naik sebanyak %') !!}</option><option value="increase_amount">{!! $L('Increase by RM', 'Naik sebanyak RM') !!}</option><option value="set_amount">{!! $L('Set to RM', 'Tetapkan kepada RM') !!}</option></select></div>
        <div><label style="display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;" x-text="mode === 'increase_percent' ? '%' : 'RM'"></label><input type="number" step="0.01" min="0" name="value" x-model="value" required style="{{ $fs }}" /></div>
        <div><label style="display:block;font-size:11.5px;color:var(--muted);margin-bottom:4px;">{!! $L('Remark', 'Catatan') !!}</label><input name="remark" maxlength="2000" value="{{ old('remark') }}" style="{{ $fs }}" /></div>
    </div>
    <label style="display:flex;align-items:center;gap:8px;font-size:12.5px;color:var(--body);"><input type="hidden" name="override_band" value="0" /><input type="checkbox" name="override_band" value="1" @checked(old('override_band')) /> {!! $L('Override position band maximum (logged)', 'Abaikan had maksimum jawatan (direkodkan)') !!}</label>

    <div class="uj-section-head">{!! $L('3 · Preview', '3 · Pratonton') !!}</div>
    <div x-show="!ids.length" style="font-size:12.5px;color:var(--muted);">{!! $L('Pick staff to see the preview.', 'Pilih staf untuk pratonton.') !!}</div>
    <div x-show="ids.length" style="overflow:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:12.5px;">
            <thead><tr style="color:var(--muted);font-size:11px;text-align:left;"><th style="padding:6px 8px;">{!! $L('Name', 'Nama') !!}</th><th style="padding:6px 8px;">{!! $L('Current', 'Semasa') !!}</th><th style="padding:6px 8px;">{!! $L('New', 'Baharu') !!}</th><th style="padding:6px 8px;">Δ</th></tr></thead>
            <tbody><template x-for="r in rows()" :key="r.dataset.emp"><tr style="border-top:1px solid var(--hairline-soft);">
                <td style="padding:6px 8px;color:var(--ink);font-weight:500;" x-text="r.dataset.name"></td>
                <td style="padding:6px 8px;" x-text="rm(parseFloat(r.dataset.salary))"></td>
                <td style="padding:6px 8px;color:var(--ink);font-weight:600;" x-text="rm(calc(parseFloat(r.dataset.salary)))"></td>
                <td style="padding:6px 8px;" :style="calc(parseFloat(r.dataset.salary)) - parseFloat(r.dataset.salary) < 0 ? 'color:var(--red);' : 'color:var(--success);'" x-text="rm(calc(parseFloat(r.dataset.salary)) - parseFloat(r.dataset.salary))"></td>
            </tr></template></tbody>
        </table>
    </div>
    <button type="submit" class="uj-btn-primary" :disabled="!ids.length || ids.length > 200" style="height:40px;padding:0 18px;font-size:13px;align-self:flex-start;">{!! $L('Confirm salary adjustment', 'Sahkan pelarasan gaji') !!}</button>
</form>
```

- [ ] **Step 7: Run** `php artisan test --compact tests/Feature/ProgressionScreenTest.php tests/Feature/BatchProgressionUpdateTest.php tests/Feature/BatchSalaryAdjustmentTest.php tests/Feature/AllScreensRenderTest.php` → PASS. Pint.
- [ ] **Step 8: Commit** `git add app resources tests && git commit -m "feat(progression): batch update and batch salary screens"`

---

### Task 4: Assets, walk, full suite

- [ ] `lerd artisan view:clear && lerd artisan view:cache && bun run build`; commit `public/build` if changed. (No migrations this sub-project.)
- [ ] Walk (headless Chromium, `~/mockups/batch-progression-walk/`): HR → `?batch=update`, filter by department, select two, tick Division = "Walk Div", screenshot preview, confirm, screenshot toast; open one person's profile Timeline to show the `updated` card. `?batch=salary` on the same two, +10%, preview, confirm; band refusal shot if any picked person has a band max. Manager (Kussairi) → `/app/progression` 403. Revert via tinker: set the two employees' `division` and `salary` back (read the values first, store them in the script), delete the progression rows created today of type `updated` for those two ids, delete today's batch audit rows.
- [ ] `php artisan test --compact` → green. Report in plain talk.

---

## Self-review

- Spec coverage: multi-select with filters (dept/branch/position/status) ✓; effective date ✓; tick fields + one value each ✓ (the nine spec fields); preview name/current/new ✓; confirm via service per person in one transaction ✓; one row + audit per person (service) + summary ✓. Salary: fixed/percent, increase/set ✓; preview current/new/delta ✓; director/hr only ✓; band max refusal + override tick + audit ✓; max 200 ✓; all-or-nothing ✓. Tests: N rows + rollback ✓; maths 2 dp ✓; band refused without tick ✓.
- Name consistency: `BATCH_FIELDS`, `MAX_ROWS`, `adjust()`, routes `progression.batch.update|salary`, screen vars `batch`, `canBatchSalary`, `batchStaff`, modes `increase_amount|increase_percent|set_amount`, input names `employee_ids[]`, `fields[]`, `mode`, `value`, `override_band`.
