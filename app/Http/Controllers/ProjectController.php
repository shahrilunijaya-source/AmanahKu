<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Project;
use App\Models\SubPillar;
use App\Models\TimesheetCategory;
use App\Models\WorkItem;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * The Projects register: every project in the tenant plus the shared sub-pillar
 * list they all draw on. Readable by anyone signed in; written by manager,
 * management and HR (director folds into management).
 *
 * Split out of TimesheetAdminController because the edit roles diverged —
 * categories stay management/HR, projects admit managers. Records in use are
 * deactivated, never hard-deleted, so reports keep their labels.
 */
class ProjectController extends Controller
{
    private const EDITOR_ROLES = ['manager', 'management', 'hr'];

    /** Data for the Projects screen. */
    public function screenData(Request $request): array
    {
        return [
            'projects' => Project::with('categories')
                ->orderBy('sort')->orderBy('name')->get(),
            'subPillars' => SubPillar::orderBy('sort')->orderBy('name')->get(),
            // Two lists on purpose: the ADD form offers active categories only (a
            // retired category should not be pickable on a brand-new project), while
            // an EDIT form must show every category a project might already be tied
            // to, or saving an unrelated field would silently drop that link.
            'addCategories' => $this->projectCategories()->where('is_active', true)->values(),
            'projectCategories' => $this->projectCategories(),
            'canEdit' => $this->hasTenantRole($request, self::EDITOR_ROLES),
        ];
    }

    // ---- Projects ---------------------------------------------------------

    public function storeProject(Request $request): JsonResponse|RedirectResponse
    {
        $this->authorizeEditor($request);
        $data = $this->validateProject($request);
        $categories = $data['categories'] ?? [];
        unset($data['categories']);

        $project = Project::create($data);
        $project->categories()->sync($categories);
        AuditLog::record('Added project', $project->name);

        if ($request->wantsJson()) {
            $project->load('categories');

            return response()->json([
                'html' => view('partials.ts-project-row', [
                    'project' => $project,
                    'categories' => $this->projectCategories(),
                    'canEdit' => true,
                ])->render(),
                'count_sel' => '#ts-proj-count',
            ]);
        }

        return back()->with('ok', $project->name.' added.');
    }

    public function updateProject(Request $request, Project $project): RedirectResponse
    {
        $this->authorizeEditor($request);
        $this->assertTenant($project->tenant_id);

        $data = $this->validateProject($request, $project->id);
        $categories = $data['categories'] ?? [];
        unset($data['categories']);

        $project->update($data);
        $project->categories()->sync($categories);
        $this->unbookCardsThisProjectNoLongerFits($project, $categories);
        AuditLog::record('Updated project', $project->name);

        return back()->with('ok', $project->name.' updated.');
    }

    /**
     * Retagging a project decides which categories that project answers for. A board card
     * booked to it under a category no longer on the list is unbooked — the project is
     * dropped, not the category.
     *
     * This used to run the other way and clear the card's category instead. That is now
     * the wrong half to take: the staffer picks the category on the card, so wiping it
     * because someone else edited a project throws away an answer a person actually gave,
     * and the card's rows stop reaching the timesheet at all. The project is the derived
     * half, and it is the one that gives way.
     *
     * Untagging a project entirely leaves cards alone: an untagged project has said
     * nothing rather than said "none".
     *
     * @param  list<int|string>  $categories
     */
    private function unbookCardsThisProjectNoLongerFits(Project $project, array $categories): void
    {
        if ($categories === []) {
            return;
        }

        WorkItem::where('project_id', $project->id)
            ->whereNotNull('timesheet_category_id')
            ->whereNotIn('timesheet_category_id', $categories)
            ->update(['project_id' => null]);
    }

    public function deleteProject(Request $request, Project $project): RedirectResponse
    {
        $this->authorizeEditor($request);
        $this->assertTenant($project->tenant_id);

        if ($project->entries()->exists()) {
            $project->update(['is_active' => false]);

            return back()->with('ok', $project->name.' is in use — deactivated instead of deleted.');
        }

        $name = $project->name;
        $project->delete();
        AuditLog::record('Removed project', $name);

        return back()->with('ok', $name.' removed.');
    }

    /** Toggles a project's is_active flag — one click, no need to open the edit form. */
    public function archiveProject(Request $request, Project $project): RedirectResponse
    {
        $this->authorizeEditor($request);
        $this->assertTenant($project->tenant_id);

        $project->update(['is_active' => ! $project->is_active]);

        $action = $project->is_active ? 'Restored' : 'Archived';
        AuditLog::record($action.' project', $project->name);

        return back()->with('ok', $project->name.' '.($project->is_active ? 'restored.' : 'archived.'));
    }

    // ---- Sub-pillars ------------------------------------------------------

    public function storeSubPillar(Request $request): JsonResponse|RedirectResponse
    {
        $this->authorizeEditor($request);

        $sub = SubPillar::create($this->validateSubPillar($request));
        AuditLog::record('Added sub-pillar', $sub->name);

        if ($request->wantsJson()) {
            return response()->json([
                'html' => view('partials.ts-subpillar-row', ['sp' => $sub, 'canEdit' => true])->render(),
                'count_sel' => '#ts-sub-count',
            ]);
        }

        return back()->with('ok', $sub->name.' added.');
    }

    public function updateSubPillar(Request $request, SubPillar $subPillar): RedirectResponse
    {
        $this->authorizeEditor($request);
        $this->assertTenant($subPillar->tenant_id);

        $subPillar->update($this->validateSubPillar($request, $subPillar->id));
        AuditLog::record('Updated sub-pillar', $subPillar->name);

        return back()->with('ok', $subPillar->name.' updated.');
    }

    public function deleteSubPillar(Request $request, SubPillar $subPillar): RedirectResponse
    {
        $this->authorizeEditor($request);
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
    private function validateProject(Request $request, ?int $ignoreId = null): array
    {
        $tid = app(CurrentTenant::class)->id();

        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:160', Rule::unique('projects', 'name')->where('tenant_id', $tid)->ignore($ignoreId)],
            'sort' => ['nullable', 'integer', 'between:0,9999'],
            'is_active' => ['nullable', 'boolean'],
            'categories' => ['nullable', 'array'],
            'categories.*' => [Rule::exists('timesheet_categories', 'id')->where('tenant_id', $tid)],
        ]);

        $data['is_active'] = $request->boolean('is_active', true);

        return $data;
    }

    /** @return array<string,mixed> */
    private function validateSubPillar(Request $request, ?int $ignoreId = null): array
    {
        $tid = app(CurrentTenant::class)->id();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:160', Rule::unique('sub_pillars', 'name')->where('tenant_id', $tid)->ignore($ignoreId)],
            'sort' => ['nullable', 'integer', 'between:0,9999'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $data['is_active'] = $request->boolean('is_active', true);

        return $data;
    }

    /**
     * The project-linkable categories, for the project form's category chips: the ones
     * flagged `requires_project`, since those are exactly the categories that cannot be
     * costed without naming a job.
     *
     * A deactivated category is kept only when some project is still tagged with it — that
     * project must keep showing its chip, or re-syncing the form would silently drop it.
     * A deactivated category nobody uses is left out: it can no longer be picked, so a
     * chip for it would filter the register down to nothing.
     */
    private function projectCategories(): Collection
    {
        return TimesheetCategory::projectLinkable()
            ->where(fn ($q) => $q->where('is_active', true)->orWhereHas('projects'))
            ->orderBy('sort')->orderBy('name')->get();
    }

    private function authorizeEditor(Request $request): void
    {
        $this->authorizeTenantRole($request, self::EDITOR_ROLES);
    }

    private function assertTenant(int $tenantId): void
    {
        abort_unless($tenantId === app(CurrentTenant::class)->id(), 403);
    }
}
