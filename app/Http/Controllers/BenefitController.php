<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\BenefitEnrollment;
use App\Models\BenefitPlan;
use App\Models\Employee;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class BenefitController extends Controller
{
    /** HR/management manage the benefit plan catalogue. */
    private const PRIVILEGED_ROLES = ['management', 'hr'];

    private const TYPES = ['medical', 'dental', 'life', 'other'];

    private const STATUSES = ['enrolled', 'waived'];

    /**
     * Everyone sees the active plans plus their own enrollment state per plan.
     * Privileged roles additionally receive a plan-management form flag, the full
     * plan list (including inactive), and an enrolled-count per plan. Tenant scope
     * is automatic via BelongsToTenant; counts are computed in PHP to stay
     * DB-agnostic and never escape the active tenant.
     *
     * @return array<string, mixed>
     */
    public function screenData(Request $request, ?Employee $employee): array
    {
        $privileged = $this->hasTenantRole($request, self::PRIVILEGED_ROLES);

        $activePlans = BenefitPlan::where('active', true)->orderBy('type')->orderBy('name')->get();

        // The employee's own enrollment per plan, keyed by plan id for O(1) lookup.
        $myEnrollments = $employee
            ? BenefitEnrollment::where('employee_id', $employee->id)->get()->keyBy('benefit_plan_id')
            : new Collection;

        $allPlans = $privileged
            ? BenefitPlan::withCount(['enrollments as enrolled_count' => fn ($q) => $q->where('status', 'enrolled')])
                ->orderByDesc('active')->orderBy('type')->orderBy('name')->get()
            : new Collection;

        return [
            'privileged' => $privileged,
            'plans' => $activePlans,
            'myEnrollments' => $myEnrollments,
            'allPlans' => $allPlans,
            'canEnroll' => (bool) $employee,
        ];
    }

    /** Any employee may enroll in (or waive) an active plan — one record per plan. */
    public function enroll(Request $request, BenefitPlan $plan): RedirectResponse
    {
        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403, 'No employee profile in this workspace.');
        abort_unless($plan->tenant_id === app(CurrentTenant::class)->id(), 403);
        abort_unless((bool) $plan->active, 422, 'This plan is no longer available.');

        $data = $request->validate([
            'status' => ['required', 'in:'.implode(',', self::STATUSES)],
            'dependents' => ['required', 'integer', 'min:0', 'max:20'],
        ]);

        $enrolled = $data['status'] === 'enrolled';

        // The unique (benefit_plan_id, employee_id) constraint guarantees a single
        // enrollment record per employee per plan; updateOrCreate upserts it.
        BenefitEnrollment::updateOrCreate(
            [
                'benefit_plan_id' => $plan->id,
                'employee_id' => $employee->id,
            ],
            [
                'tenant_id' => $plan->tenant_id,
                'status' => $data['status'],
                'dependents' => $enrolled ? $data['dependents'] : 0,
                'enrolled_at' => $enrolled ? now()->toDateString() : null,
            ],
        );

        $message = $enrolled
            ? 'Enrolled in '.$plan->name.'.'
            : 'You have waived '.$plan->name.'.';

        return back()->with('ok', $message);
    }

    /** Privileged-only: add a new plan to the benefit catalogue. */
    public function storePlan(Request $request): RedirectResponse
    {
        $this->authorizePrivileged($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', 'in:'.implode(',', self::TYPES)],
            'provider' => ['nullable', 'string', 'max:120'],
            'coverage' => ['nullable', 'string', 'max:1000'],
            'monthly_cost' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
        ]);

        $plan = BenefitPlan::create([
            'tenant_id' => app(CurrentTenant::class)->id(),
            'name' => $data['name'],
            'type' => $data['type'],
            'provider' => $data['provider'] ?? null,
            'coverage' => $data['coverage'] ?? null,
            'monthly_cost' => $data['monthly_cost'] ?? null,
            'active' => true,
        ]);

        AuditLog::record('Added benefit plan', $plan->name);

        return back()->with('ok', $plan->name.' added to the benefit catalogue.');
    }

    private function authorizePrivileged(Request $request): void
    {
        abort_unless(
            $this->hasTenantRole($request, self::PRIVILEGED_ROLES),
            403,
            'Only HR and management can manage benefit plans.'
        );
    }
}
