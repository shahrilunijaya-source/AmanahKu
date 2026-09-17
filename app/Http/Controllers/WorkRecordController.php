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

        return redirect(route('app.screen', 'profile').'?emp='.$employee->id.'&tab=workinfo')->with('ok', 'Work details saved for '.$employee->name.'.');
    }
}
