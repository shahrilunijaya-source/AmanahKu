<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Project;
use App\Models\TimesheetCategory;
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
    /** Data for the screen — the create form plus the categories it may fall under. */
    public function screenData(Request $request): array
    {
        return [
            'categories' => TimesheetCategory::projectLinkable()->where('is_active', true)->orderBy('sort')->orderBy('name')->get(),
        ];
    }

    public function store(Request $request): RedirectResponse
    {
        $tid = app(CurrentTenant::class)->id();

        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:160', Rule::unique('projects', 'name')->where('tenant_id', $tid)],
            'categories' => ['nullable', 'array'],
            'categories.*' => [Rule::exists('timesheet_categories', 'id')->where('tenant_id', $tid)],
        ]);

        $categories = $data['categories'] ?? [];
        unset($data['categories']);

        $project = Project::create($data + ['is_active' => true]);
        $project->categories()->sync($categories);
        AuditLog::record('Added project', $project->name);

        return back()->with('ok', $project->name.' created. Link it from Track next.');
    }
}
