<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\TimesheetCategory;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * HR setup for the timesheet categories staff pick from when allocating their
 * week. Privileged (management / HR) only. Projects and sub-pillars moved to
 * ProjectController — their edit roles now include managers.
 *
 * Records in use are never hard-deleted (that would null historical entries via
 * the nullOnDelete FKs and erase report history) — they are deactivated instead.
 */
class TimesheetAdminController extends Controller
{
    private const PRIVILEGED_ROLES = ['management', 'hr'];

    /** Data for the Timesheet Setup screen — categories only; projects live on their own screen. */
    public function screenData(Request $request): array
    {
        return [
            'categories' => TimesheetCategory::orderBy('sort')->orderBy('name')->get(),
        ];
    }

    // ---- Categories -------------------------------------------------------

    public function storeCategory(Request $request): JsonResponse|RedirectResponse
    {
        $this->authorize($request);
        $category = TimesheetCategory::create($this->validateCategory($request));
        AuditLog::record('Added timesheet category', $category->name);

        // AJAX add (the setup screen) — return the rendered row so it can be appended
        // in place with no full reload. Same partial the initial render uses.
        if ($request->wantsJson()) {
            return response()->json([
                'html' => view('partials.ts-category-row', ['cat' => $category])->render(),
                'count_sel' => '#ts-cat-count',
            ]);
        }

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

    private function authorize(Request $request): void
    {
        $this->authorizeTenantRole($request, self::PRIVILEGED_ROLES);
    }

    private function assertTenant(int $tenantId): void
    {
        abort_unless($tenantId === app(CurrentTenant::class)->id(), 403);
    }
}
