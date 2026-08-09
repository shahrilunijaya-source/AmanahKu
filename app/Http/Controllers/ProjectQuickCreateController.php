<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Project;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Minimal project creation for manager/management/hr — code + name only.
 * Separate from TimesheetAdminController's full Timesheet Setup screen (which
 * stays HR/management-only, with categories/sub-pillars/full project list). A
 * project created here is immediately linkable from Track via GET /api/v1/projects.
 */
class ProjectQuickCreateController extends Controller
{
    /** Data for the screen (empty — the view is just the create form). */
    public function screenData(Request $request): array
    {
        return [];
    }

    public function store(Request $request): RedirectResponse
    {
        $tid = app(CurrentTenant::class)->id();

        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:160', Rule::unique('projects', 'name')->where('tenant_id', $tid)],
        ]);

        $project = Project::create($data + ['is_active' => true]);
        AuditLog::record('Added project', $project->name);

        return back()->with('ok', $project->name.' created. Link it from Track next.');
    }
}
