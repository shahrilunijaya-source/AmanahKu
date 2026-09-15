<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmploymentType;
use App\Models\Position;
use App\Services\EmploymentRecordService;
use App\Services\EmploymentTransitionException;
use App\Tenancy\CurrentTenant;
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

        return redirect(route('app.screen', 'progression').'?emp='.$employee->id.'&action='.$action)->with('ok', $employee->name.' · '.ucfirst($action).' saved.');
    }
}
