<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\ClearanceItem;
use App\Models\Employee;
use App\Models\OffboardingCase;
use App\Services\OffboardingService;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OffboardingController extends Controller
{
    /** Offboarding is an HR-driven exit-clearance view. */
    private const PRIVILEGED_ROLES = ['management', 'hr'];

    private const REASONS = ['resignation', 'end_of_contract', 'termination', 'retirement'];

    /** Clearance departments — must match the enum on clearance_items.department. */
    private const DEPARTMENTS = ['IT', 'HR', 'Finance', 'Manager', 'Admin'];

    /**
     * Load the most recent offboarding case with its clearance items + employee.
     * Privileged roles (management/HR) also receive the tenant's employee list and
     * a "start case" form flag for when no active case exists. Non-privileged
     * employees see the current case read-only (mirrors the onboarding view).
     */
    public function screenData(Request $request, ?Employee $employee): array
    {
        // Director folds into management via Permissions::effectiveRole, so use the role-aware
        // helper (not a raw in_array) — otherwise a director is silently denied the whole view
        // while the write endpoints, which go through authorizeTenantRole, still accept them.
        $privileged = $this->hasTenantRole($request, self::PRIVILEGED_ROLES);

        $caseQuery = OffboardingCase::with(['clearanceItems', 'employee'])
            ->orderByDesc('status')   // 'in_progress' sorts before 'completed' lexically
            ->orderByDesc('last_day')
            ->orderByDesc('id');

        // A non-privileged employee may only ever see their OWN exit case — another
        // employee's reason/last-day/notes are sensitive and must not leak.
        if (! $privileged) {
            $caseQuery->where('employee_id', $employee?->id ?? 0);
        }

        $case = $caseQuery->first();

        return [
            'case' => $case,
            'privileged' => $privileged,
            'employees' => $privileged ? Employee::active()->orderBy('name')->get(['id', 'name', 'position']) : collect(),
        ];
    }

    /** Privileged-only: open an exit-clearance case and seed its standard checklist. */
    public function store(Request $request, OffboardingService $offboarding): RedirectResponse
    {
        $this->authorizePrivileged($request);
        $tenantId = app(CurrentTenant::class)->id();

        $data = $request->validate([
            'employee_id' => ['required', 'integer', Rule::exists('employees', 'id')->where('tenant_id', $tenantId)],
            'last_day' => ['required', 'date'],
            'reason' => ['required', Rule::in(self::REASONS)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $employee = Employee::findOrFail($data['employee_id']);
        $offboarding->openCase($employee, $data['last_day'], $data['reason'], $data['notes'] ?? null);

        return back()->with('ok', 'Offboarding case opened.');
    }

    /** Privileged-only: tick / untick a clearance checklist item. */
    public function toggleItem(Request $request, ClearanceItem $item): RedirectResponse
    {
        // The item's case resolves through the tenant global scope; assert tenant explicitly too.
        $case = OffboardingCase::find($item->offboarding_case_id);
        abort_unless($case && $case->tenant_id === app(CurrentTenant::class)->id(), 403);

        $this->authorizePrivileged($request);
        // An archived (completed) case is a frozen historical record — mirror add/removeItem
        // so a direct/replayed POST can't silently flip an item on a case already closed out.
        abort_if($case->status === 'completed', 403);

        $item->update(['done' => ! $item->done]);

        AuditLog::record($item->done ? 'Cleared offboarding item' : 'Reopened offboarding item', $item->title);

        return back()->with('ok', $item->done ? 'Item cleared.' : 'Item reopened.');
    }

    /**
     * Privileged-only: append an ad-hoc clearance item to an in-progress case.
     * Lets HR add tasks the standard checklist doesn't cover, per departure. A
     * new department string simply becomes a new clearance column on the screen.
     */
    public function addItem(Request $request, OffboardingCase $case): RedirectResponse
    {
        abort_unless($case->tenant_id === app(CurrentTenant::class)->id(), 403);
        $this->authorizePrivileged($request);
        // An archived (completed) case is a historical record — its checklist is frozen.
        abort_if($case->status === 'completed', 403);

        $data = $request->validate([
            'department' => ['required', Rule::in(self::DEPARTMENTS)],
            'title' => ['required', 'string', 'max:120'],
        ]);

        $case->clearanceItems()->create([
            'department' => $data['department'],
            'title' => trim($data['title']),
            'done' => false,
            'sort' => ((int) $case->clearanceItems()->max('sort')) + 1,
        ]);

        AuditLog::record('Added offboarding item', trim($data['title']));

        return back()->with('ok', 'Clearance item added.');
    }

    /** Privileged-only: remove a clearance item from an in-progress case (fat-finger fix). */
    public function removeItem(Request $request, ClearanceItem $item): RedirectResponse
    {
        // ClearanceItem carries no tenant scope of its own — assert via its case, like toggleItem.
        $case = OffboardingCase::find($item->offboarding_case_id);
        abort_unless($case && $case->tenant_id === app(CurrentTenant::class)->id(), 403);

        $this->authorizePrivileged($request);
        // An archived (completed) case is a frozen historical record.
        abort_if($case->status === 'completed', 403);

        $title = $item->title;
        $item->delete();

        AuditLog::record('Removed offboarding item', $title);

        return back()->with('ok', 'Clearance item removed.');
    }

    private function authorizePrivileged(Request $request): void
    {
        $this->authorizeTenantRole($request, self::PRIVILEGED_ROLES);
    }
}
