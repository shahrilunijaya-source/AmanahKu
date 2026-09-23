<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeProgression;
use App\Models\EmploymentType;
use App\Models\Position;
use App\Services\EmploymentRecordService;
use App\Services\EmploymentTransitionException;
use App\Services\Payroll\BackPay;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Progression screen (HR / management only, manager excluded): confirm, update,
 * resign or rehire one staff member. Every action goes through
 * EmploymentRecordService so the profile Timeline gets its row.
 */
class ProgressionController extends EmploymentRecordController
{
    public const ACTIONS = ['confirmation', 'update', 'resignation', 'rehire'];

    /** Which tab a saved row belongs to, for the redirect after a correction. */
    public const ACTION_FOR_TYPE = ['hired' => 'confirmation', 'confirmed' => 'confirmation', 'updated' => 'update', 'resigned' => 'resignation', 'rehired' => 'rehire'];

    /** @return array<string, mixed> */
    public function screenData(Request $request): array
    {
        $staff = Employee::query()->whereNull('archived_at')->with(['positionBand', 'department'])->orderBy('name')->get();
        // firstWhere on the tenant-scoped collection is what keeps a foreign ?emp= from resolving.
        $selected = $request->filled('emp') ? $staff->firstWhere('id', (int) $request->query('emp')) : null;
        $selected?->load(['progressions.recordedBy', 'reportsTo', 'branch', 'employmentType']);
        $action = in_array($request->query('action'), self::ACTIONS, true) ? $request->query('action') : 'confirmation';
        $canBatchSalary = $this->hasTenantRole($request, ['director', 'hr']);
        $batch = in_array($request->query('batch'), ['update', 'salary'], true) ? $request->query('batch') : null;
        if ($batch === 'salary' && ! $canBatchSalary) {
            $batch = null;
        }

        return [
            'batch' => $batch,
            'canBatchSalary' => $canBatchSalary,
            'batchStaff' => $batch ? $staff->load(['branch', 'reportsTo', 'employmentType']) : collect(),
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

    public function confirm(Request $request, Employee $employee, EmploymentRecordService $service): RedirectResponse
    {
        $this->guard($request, $employee);
        $data = $request->validate($this->rules($employee->tenant_id, $employee) + ['confirmed_on' => ['required', 'date'], 'remark' => ['nullable', 'string', 'max:2000']]);

        return $this->run('confirmed_on', 'confirmation', $employee, fn () => $service->confirm(
            $employee, $data['confirmed_on'], self::fields($data, $this->hasTenantRole($request, ['director', 'hr'])), $data['remark'] ?? null, $request->attributes->get('employee')
        ));
    }

    public function update(Request $request, Employee $employee, EmploymentRecordService $service): RedirectResponse
    {
        $this->guard($request, $employee);
        $data = $request->validate($this->rules($employee->tenant_id, $employee) + ['effective_on' => ['required', 'date'], 'update_type' => ['required', Rule::in(array_keys(EmploymentRecordService::UPDATE_TYPES))], 'remark' => ['nullable', 'string', 'max:2000']]);

        return $this->run('effective_on', 'update', $employee, fn () => $service->update(
            $employee, $data['effective_on'], self::fields($data, $this->hasTenantRole($request, ['director', 'hr'])), $data['remark'] ?? null, $request->attributes->get('employee'), $data['update_type']
        ));
    }

    public function resign(Request $request, Employee $employee, EmploymentRecordService $service): RedirectResponse
    {
        $this->guard($request, $employee);
        $data = $request->validate([
            'resigned_on' => ['required', 'date'],
            'last_working_day' => ['required', 'date', 'after_or_equal:resigned_on'],
            'reason' => ['required', 'in:resigned,contract_ended,terminated,retired,other'],
            'remark' => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->run('resigned_on', 'resignation', $employee, fn () => $service->resign(
            $employee, $data['resigned_on'], $data['last_working_day'], $data['reason'], $data['remark'] ?? null, $request->attributes->get('employee')
        ));
    }

    public function rehire(Request $request, Employee $employee, EmploymentRecordService $service): RedirectResponse
    {
        $this->guard($request, $employee);
        $data = $request->validate($this->rules($employee->tenant_id, $employee) + ['hired_on' => ['required', 'date'], 'remark' => ['nullable', 'string', 'max:2000']]);

        return $this->run('hired_on', 'rehire', $employee, fn () => $service->rehire(
            $employee, $data['hired_on'], self::fields($data, $this->hasTenantRole($request, ['director', 'hr'])), $data['remark'] ?? null, $request->attributes->get('employee')
        ));
    }

    /**
     * Corrects a saved row: remark, effective date, and — for a resigned row — the last
     * working day held in its snapshot. Everything else in the snapshot stays as it was
     * recorded, so the timeline still shows what the record actually held at the time.
     */
    public function updateRecord(Request $request, EmployeeProgression $progression): RedirectResponse
    {
        $this->authorizeTenantRole($request, ['management', 'hr']);
        abort_unless($progression->tenant_id === app(CurrentTenant::class)->id(), 403);
        $data = $request->validate([
            'effective_on' => ['required', 'date'],
            'remark' => ['nullable', 'string', 'max:2000'],
            'last_working_day' => ['nullable', 'date'],
        ]);
        $employee = $progression->employee;
        $on = CarbonImmutable::parse($data['effective_on']);

        if (! in_array($progression->type, ['hired', 'rehired'], true) && $employee->joined_at && $on->lt($employee->joined_at)) {
            return back()->withInput()->withErrors(['effective_on' => 'Date cannot be before the hire date ('.$employee->joined_at->format('d M Y').').']);
        }

        // has(), not the validated value: a submitted-but-blank field clears the date, an absent one leaves it alone.
        $editsLastWorkingDay = $progression->type === 'resigned' && $request->has('last_working_day');
        $lastWorkingDay = $editsLastWorkingDay && ($data['last_working_day'] ?? null) !== null
            ? CarbonImmutable::parse($data['last_working_day'])
            : null;

        if ($lastWorkingDay && $lastWorkingDay->lt($on)) {
            return back()->withInput()->withErrors(['last_working_day' => 'Last working day cannot be before the resignation date.']);
        }

        $snapshot = $progression->snapshot ?? [];
        if ($editsLastWorkingDay) {
            $snapshot['last_working_day'] = $lastWorkingDay?->toDateString();
        }

        $progression->forceFill(['effective_on' => $on->toDateString(), 'remark' => ($data['remark'] ?? null) ?: null, 'snapshot' => $snapshot])->save();
        $this->syncEmployeeDate($progression, $employee, $on->toDateString());
        if ($editsLastWorkingDay) {
            $this->syncEmployeeLastWorkingDay($progression, $employee, $lastWorkingDay?->toDateString());
        }
        AuditLog::record('Edited progression record', $employee->name.' · '.$progression->type);

        return redirect(route('app.screen', 'progression').'?emp='.$employee->id.'&action='.self::ACTION_FOR_TYPE[$progression->type])
            ->with('ok', $employee->name.' · Record corrected.');
    }

    /** The employee column the row drives, kept in step only when this is their latest row of that type. */
    private function syncEmployeeDate(EmployeeProgression $row, Employee $employee, string $on): void
    {
        $column = ['hired' => 'joined_at', 'rehired' => 'joined_at', 'confirmed' => 'confirmed_at', 'resigned' => 'resigned_at'][$row->type] ?? null;
        if ($column === null) {
            return;
        }
        $latest = EmployeeProgression::where('employee_id', $employee->id)->where('type', $row->type)->orderByDesc('id')->first();
        if ($latest?->id === $row->id && ! $this->undoneByRehire($row)) {
            $employee->forceFill([$column => $on])->save();
        }
    }

    /** Mirrors a corrected resigned row's last working day onto the employee, only when that resignation still stands. */
    private function syncEmployeeLastWorkingDay(EmployeeProgression $row, Employee $employee, ?string $on): void
    {
        $latest = EmployeeProgression::where('employee_id', $employee->id)->where('type', 'resigned')->orderByDesc('id')->first();
        if ($latest?->id === $row->id && ! $this->undoneByRehire($row)) {
            $employee->forceFill(['last_working_day' => $on])->save();
        }
    }

    /**
     * True when a rehire came after this resignation. That person is back on the payroll, so
     * correcting the old resigned row must not write leaving dates onto their live record.
     */
    private function undoneByRehire(EmployeeProgression $row): bool
    {
        return $row->type === 'resigned' && EmployeeProgression::where('employee_id', $row->employee_id)
            ->where('type', 'rehired')->where('id', '>', $row->id)->exists();
    }

    private function guard(Request $request, Employee $employee): void
    {
        $this->authorizeTenantRole($request, ['management', 'hr']);
        abort_unless($employee->tenant_id === app(CurrentTenant::class)->id(), 403);
    }

    private function run(string $errorKey, string $action, Employee $employee, \Closure $do): RedirectResponse
    {
        try {
            $do();
        } catch (EmploymentTransitionException $ex) {
            return back()->withInput()->withErrors([$errorKey => $ex->getMessage()]);
        }

        return redirect(route('app.screen', 'progression').'?emp='.$employee->id.'&action='.$action)->with('ok', $employee->name.' · '.ucfirst($action).' saved.'.BackPay::noteSuffix());
    }
}
