<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Project;
use App\Models\ProjectSubPillar;
use App\Models\TimesheetCategory;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * HR setup for timesheet master data: the categories, projects and sub-pillars
 * staff pick from when allocating their week. Privileged (management / HR) only.
 *
 * Records in use are never hard-deleted (that would null historical entries via
 * the nullOnDelete FKs and erase report history) — they are deactivated instead.
 */
class TimesheetAdminController extends Controller
{
    private const PRIVILEGED_ROLES = ['management', 'hr'];

    /** Data for the Timesheet Setup screen. */
    public function screenData(Request $request): array
    {
        return [
            'categories' => TimesheetCategory::orderBy('sort')->orderBy('name')->get(),
            'projects' => Project::with(['subPillars' => fn ($q) => $q->orderBy('sort')->orderBy('name')])
                ->orderBy('sort')->orderBy('name')->get(),
        ];
    }

    // ---- Categories -------------------------------------------------------

    public function storeCategory(Request $request): RedirectResponse
    {
        $this->authorize($request);
        $category = TimesheetCategory::create($this->validateCategory($request));
        AuditLog::record('Added timesheet category', $category->name);

        return back()->with('ok', $category->name.' category added.');
    }

    public function updateCategory(Request $request, TimesheetCategory $category): RedirectResponse
    {
        $this->authorize($request);
        $this->assertTenant($category->tenant_id);

        $category->update($this->validateCategory($request, $category->id));
        AuditLog::record('Updated timesheet category', $category->name);

        return back()->with('ok', $category->name.' updated.');
    }

    public function deleteCategory(Request $request, TimesheetCategory $category): RedirectResponse
    {
        $this->authorize($request);
        $this->assertTenant($category->tenant_id);

        if ($category->entries()->exists()) {
            $category->update(['is_active' => false]);

            return back()->with('ok', $category->name.' is in use — deactivated instead of deleted.');
        }

        $name = $category->name;
        $category->delete();
        AuditLog::record('Removed timesheet category', $name);

        return back()->with('ok', $name.' removed.');
    }

    // ---- Projects ---------------------------------------------------------

    public function storeProject(Request $request): RedirectResponse
    {
        $this->authorize($request);
        $project = Project::create($this->validateProject($request));
        AuditLog::record('Added project', $project->name);

        return back()->with('ok', $project->name.' added.');
    }

    public function updateProject(Request $request, Project $project): RedirectResponse
    {
        $this->authorize($request);
        $this->assertTenant($project->tenant_id);

        $project->update($this->validateProject($request, $project->id));
        AuditLog::record('Updated project', $project->name);

        return back()->with('ok', $project->name.' updated.');
    }

    public function deleteProject(Request $request, Project $project): RedirectResponse
    {
        $this->authorize($request);
        $this->assertTenant($project->tenant_id);

        if ($project->entries()->exists()) {
            $project->update(['is_active' => false]);

            return back()->with('ok', $project->name.' is in use — deactivated instead of deleted.');
        }

        $name = $project->name;
        $project->delete(); // sub-pillars cascade
        AuditLog::record('Removed project', $name);

        return back()->with('ok', $name.' removed.');
    }

    // ---- Sub-pillars ------------------------------------------------------

    public function storeSubPillar(Request $request, Project $project): RedirectResponse
    {
        $this->authorize($request);
        $this->assertTenant($project->tenant_id);

        $data = $this->validateSubPillar($request, $project);
        $sub = $project->subPillars()->create($data);
        AuditLog::record('Added sub-pillar', $project->name.' · '.$sub->name);

        return back()->with('ok', $sub->name.' added to '.$project->name.'.');
    }

    public function updateSubPillar(Request $request, ProjectSubPillar $subPillar): RedirectResponse
    {
        $this->authorize($request);
        $this->assertTenant($subPillar->tenant_id);

        $subPillar->update($this->validateSubPillar($request, $subPillar->project, $subPillar->id));
        AuditLog::record('Updated sub-pillar', $subPillar->name);

        return back()->with('ok', $subPillar->name.' updated.');
    }

    public function deleteSubPillar(Request $request, ProjectSubPillar $subPillar): RedirectResponse
    {
        $this->authorize($request);
        $this->assertTenant($subPillar->tenant_id);

        if ($subPillar->entries()->exists()) {
            $subPillar->update(['is_active' => false]);

            return back()->with('ok', $subPillar->name.' is in use — deactivated instead of deleted.');
        }

        $name = $subPillar->name;
        $subPillar->delete();
        AuditLog::record('Removed sub-pillar', $name);

        return back()->with('ok', $name.' removed.');
    }

    // ---- Validation -------------------------------------------------------

    /** @return array<string,mixed> */
    private function validateCategory(Request $request, ?int $ignoreId = null): array
    {
        $tid = app(CurrentTenant::class)->id();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('timesheet_categories', 'name')->where('tenant_id', $tid)->ignore($ignoreId)],
            'name_ms' => ['nullable', 'string', 'max:80'],
            'requires_project' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'integer', 'between:0,9999'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $data['requires_project'] = $request->boolean('requires_project');
        $data['is_active'] = $request->boolean('is_active', true);

        return $data;
    }

    /** @return array<string,mixed> */
    private function validateProject(Request $request, ?int $ignoreId = null): array
    {
        $tid = app(CurrentTenant::class)->id();

        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:160', Rule::unique('projects', 'name')->where('tenant_id', $tid)->ignore($ignoreId)],
            'sort' => ['nullable', 'integer', 'between:0,9999'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $data['is_active'] = $request->boolean('is_active', true);

        return $data;
    }

    /** @return array<string,mixed> */
    private function validateSubPillar(Request $request, Project $project, ?int $ignoreId = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160', Rule::unique('project_sub_pillars', 'name')->where('project_id', $project->id)->ignore($ignoreId)],
            'sort' => ['nullable', 'integer', 'between:0,9999'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $data['is_active'] = $request->boolean('is_active', true);

        return $data;
    }

    private function authorize(Request $request): void
    {
        $this->authorizeTenantRole($request, self::PRIVILEGED_ROLES);
    }

    private function assertTenant(int $tenantId): void
    {
        abort_unless($tenantId === app(CurrentTenant::class)->id(), 403);
    }
}
