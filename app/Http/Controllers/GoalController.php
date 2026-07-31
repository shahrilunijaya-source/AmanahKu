<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Goal;
use App\Models\KeyResult;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class GoalController extends Controller
{
    /** Roles allowed to see a team-goals overview across other employees. */
    private const PRIVILEGED_ROLES = ['manager', 'management', 'hr'];

    private const CATEGORIES = ['growth', 'delivery', 'culture'];

    private const STATUSES = ['active', 'achieved', 'missed', 'archived'];

    /**
     * The employee's own objectives (with key results + computed overall progress).
     * Privileged roles additionally get a team-goals overview of other employees'
     * goals — role-gated at the data layer (never just hidden in the template),
     * mirroring reviewsData. Tenant isolation comes from the BelongsToTenant scope.
     */
    public function screenData(Request $request, ?Employee $employee): array
    {
        $role = $request->attributes->get('tenantRole', 'employee');
        $privileged = in_array($role, self::PRIVILEGED_ROLES, true);

        $myGoals = $employee
            ? Goal::with('keyResults')
                ->where('employee_id', $employee->id)
                ->orderByRaw("status = 'active' desc")
                ->orderByDesc('id')
                ->get()
            : collect();

        $teamGoals = $privileged
            ? Goal::with(['keyResults', 'employee'])
                ->whereHas('employee', fn ($q) => $q->active()) // archived staff hold no live goals
                ->when($employee, fn ($q) => $q->where('employee_id', '!=', $employee->id))
                ->orderByDesc('updated_at')
                ->take(12)
                ->get()
            : collect();

        return [
            'privileged' => $privileged,
            'canManage' => (bool) $employee,
            'myGoals' => $myGoals,
            'teamGoals' => $teamGoals,
            'overallProgress' => $myGoals->isEmpty() ? 0 : (int) round($myGoals->avg('progress')),
            'categories' => self::CATEGORIES,
        ];
    }

    /** An employee creates an objective for themselves. employee_id is forced to own. */
    public function store(Request $request): RedirectResponse
    {
        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403, 'No employee profile in this workspace.');

        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:1000'],
            'category' => ['nullable', 'in:'.implode(',', self::CATEGORIES)],
            'period' => ['required', 'string', 'max:40'],
        ]);

        $goal = Goal::create([
            'tenant_id' => app(CurrentTenant::class)->id(),
            'employee_id' => $employee->id,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'category' => $data['category'] ?? null,
            'period' => $data['period'],
            'status' => 'active',
        ]);

        AuditLog::record('Created goal', $goal->title);

        return back()->with('ok', 'Objective "'.$goal->title.'" created.');
    }

    /** Owner-only: add a key result to one of the employee's own goals. */
    public function addKeyResult(Request $request, Goal $goal): RedirectResponse
    {
        $employee = $this->authorizeGoalOwner($request, $goal);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'target_label' => ['nullable', 'string', 'max:80'],
            'progress' => ['nullable', 'integer', 'min:0', 'max:100'],
        ]);

        $goal->keyResults()->create([
            'tenant_id' => $goal->tenant_id,
            'employee_id' => $employee->id,
            'title' => $data['title'],
            'target_label' => $data['target_label'] ?? null,
            'progress' => $data['progress'] ?? 0,
        ]);

        AuditLog::record('Added key result', $goal->title);

        return back()->with('ok', 'Key result added.');
    }

    /**
     * Owner-only: update a key result's progress. When every key result of the
     * parent goal reaches 100, the goal is marked achieved.
     */
    public function updateProgress(Request $request, KeyResult $keyResult): RedirectResponse
    {
        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403, 'No employee profile in this workspace.');
        abort_unless($keyResult->tenant_id === app(CurrentTenant::class)->id(), 403);

        $goal = $keyResult->goal;
        abort_unless($goal && $goal->employee_id === $employee->id, 403);

        $data = $request->validate([
            'progress' => ['required', 'integer', 'min:0', 'max:100'],
        ]);

        $keyResult->update(['progress' => $data['progress']]);

        // Auto-mark the goal achieved once all its key results are complete.
        if ($goal->status === 'active') {
            $results = $goal->keyResults()->get();
            if ($results->isNotEmpty() && $results->every(fn (KeyResult $kr) => (int) $kr->progress === 100)) {
                $goal->update(['status' => 'achieved']);
            }
        }

        AuditLog::record('Updated goal progress', $goal->title);

        return back()->with('ok', 'Progress updated.');
    }

    /**
     * Assert the actor owns this goal (own employee profile + active tenant).
     * Returns the acting employee for reuse.
     */
    private function authorizeGoalOwner(Request $request, Goal $goal): Employee
    {
        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403, 'No employee profile in this workspace.');
        abort_unless($goal->tenant_id === app(CurrentTenant::class)->id(), 403);
        abort_unless($goal->employee_id === $employee->id, 403);

        return $employee;
    }
}
