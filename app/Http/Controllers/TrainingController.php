<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\TrainingRecord;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class TrainingController extends Controller
{
    private const PRIVILEGED_ROLES = ['manager', 'management', 'hr'];

    /** Assign a course to a team member. */
    public function store(Request $request): RedirectResponse
    {
        $this->authorizeTenantRole($request, self::PRIVILEGED_ROLES);
        $tenantId = app(CurrentTenant::class)->id();

        // Multi-assign: the picker posts employee_ids[]. Fall back to a single employee_id
        // so older callers (and the API/tests) keep working.
        $ids = $request->input('employee_ids');
        if ($ids === null && $request->filled('employee_id')) {
            $ids = [$request->input('employee_id')];
        }
        $request->merge(['employee_ids' => is_array($ids) ? array_values($ids) : $ids]);

        $data = $request->validate([
            'employee_ids' => ['required', 'array', 'min:1'],
            'employee_ids.*' => ['integer', Rule::exists('employees', 'id')->where('tenant_id', $tenantId)],
            'course' => ['required', 'string', 'max:160'],
            'provider' => ['nullable', 'string', 'max:120'],
            'due_at' => ['nullable', 'date'],
            'mandatory' => ['nullable', 'boolean'],
        ], [], ['employee_ids' => 'employees', 'employee_ids.*' => 'employee']);

        $ids = array_values(array_unique(array_map('intval', $data['employee_ids'])));
        $mandatory = $request->boolean('mandatory');

        foreach ($ids as $employeeId) {
            TrainingRecord::create([
                'tenant_id' => $tenantId,
                'employee_id' => $employeeId,
                'course' => $data['course'],
                'provider' => $data['provider'] ?? null,
                'status' => 'not_started',
                'mandatory' => $mandatory,
                'due_at' => $data['due_at'] ?? null,
            ]);
        }

        $count = count($ids);
        AuditLog::record('Assigned training', $data['course'].' → '.$count.' '.Str::plural('person', $count));

        return back()->with('ok', 'Training assigned to '.$count.' '.Str::plural('person', $count).'.');
    }

    /** Mark a course complete (the assignee or a privileged role). */
    public function complete(Request $request, TrainingRecord $training): RedirectResponse
    {
        abort_unless($training->tenant_id === app(CurrentTenant::class)->id(), 403);

        $employee = $request->attributes->get('employee');
        $owns = $employee && $training->employee_id === $employee->id;
        abort_unless($this->hasTenantRole($request, self::PRIVILEGED_ROLES) || $owns, 403);

        $training->update(['status' => 'completed', 'completed_at' => now()->toDateString()]);

        return back()->with('ok', $training->course.' marked complete.');
    }
}
