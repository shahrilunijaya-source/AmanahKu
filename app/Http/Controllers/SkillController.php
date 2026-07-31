<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeSkill;
use App\Models\Skill;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

class SkillController extends Controller
{
    /** Roles allowed to see the full team matrix + verify ratings. */
    private const MATRIX_ROLES = ['manager', 'management', 'hr'];

    /** Roles allowed to manage (add to) the skill catalogue. */
    private const CATALOG_ROLES = ['management', 'hr'];

    private const CATEGORIES = ['Technical', 'Leadership', 'Communication', 'Domain'];

    private const MIN_LEVEL = 1;

    private const MAX_LEVEL = 5;

    /** A skill is considered a coverage gap when its team average sits below this. */
    private const GAP_AVG_THRESHOLD = 3.0;

    /**
     * Everyone sees the catalogue plus their OWN ratings so they can self-rate.
     * Matrix roles (manager/management/hr) additionally get a full team matrix
     * (employees × skills) and a gap analysis; catalogue roles (management/hr)
     * get an add-skill form flag. The team matrix is built only for privileged
     * roles — gated at the data layer, never just hidden in the template (mirrors
     * GoalController). Tenant isolation comes from the BelongsToTenant scope.
     *
     * @return array<string, mixed>
     */
    public function screenData(Request $request, ?Employee $employee): array
    {
        $role = $request->attributes->get('tenantRole', 'employee');
        $canViewMatrix = in_array($role, self::MATRIX_ROLES, true);
        $canManageCatalog = in_array($role, self::CATALOG_ROLES, true);

        $skills = Skill::orderBy('category')->orderBy('name')->get();

        // The employee's own ratings keyed by skill id for O(1) lookup in the view.
        $myRatings = $employee
            ? EmployeeSkill::where('employee_id', $employee->id)->get()->keyBy('skill_id')
            : new Collection;

        // Privileged-only: the full team matrix + gap analysis. Built behind the
        // role gate so a plain employee never receives other employees' levels.
        $matrixEmployees = new Collection;
        $matrix = [];
        $gaps = new Collection;

        if ($canViewMatrix) {
            $matrixEmployees = Employee::active()->orderBy('name')->get();

            // [employee_id][skill_id] => EmployeeSkill, for fast cell lookup.
            $matrix = EmployeeSkill::all()
                ->groupBy('employee_id')
                ->map(fn (Collection $rows) => $rows->keyBy('skill_id'))
                ->all();

            // Eager-load ratings once (single query) before gap analysis reads
            // $skill->employeeSkills per row — avoids an N+1 over the catalogue.
            $gaps = $this->gapAnalysis($skills->loadMissing('employeeSkills'), $matrixEmployees);
        }

        return [
            'canViewMatrix' => $canViewMatrix,
            'canManageCatalog' => $canManageCatalog,
            'canRate' => (bool) $employee,
            'skills' => $skills,
            'myRatings' => $myRatings,
            'matrixEmployees' => $matrixEmployees,
            'matrix' => $matrix,
            'gaps' => $gaps,
            'categories' => self::CATEGORIES,
            'minLevel' => self::MIN_LEVEL,
            'maxLevel' => self::MAX_LEVEL,
        ];
    }

    /** Management/HR only: add a skill to the competency catalogue. */
    public function storeSkill(Request $request): RedirectResponse
    {
        $this->authorizeCatalog($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'category' => ['required', 'in:'.implode(',', self::CATEGORIES)],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $skill = Skill::create([
            'tenant_id' => app(CurrentTenant::class)->id(),
            'name' => $data['name'],
            'category' => $data['category'],
            'description' => $data['description'] ?? null,
        ]);

        AuditLog::record('Added skill', $skill->name);

        return back()->with('ok', $skill->name.' added to the skills catalogue.');
    }

    /**
     * Any employee self-rates a catalogue skill — one row per (employee, skill).
     * The level is forced onto the CURRENT employee only; the unique constraint
     * plus updateOrCreate guarantee a re-rate updates the same row, never a
     * duplicate. Re-rating clears the prior verification (the level changed).
     */
    public function rate(Request $request): RedirectResponse
    {
        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403, 'No employee profile in this workspace.');

        $tenantId = app(CurrentTenant::class)->id();

        $data = $request->validate([
            // exists rule is scoped to the tenant as defence-in-depth alongside the
            // BelongsToTenant global scope on the findOrFail below.
            'skill_id' => ['required', 'integer', Rule::exists('skills', 'id')->where('tenant_id', $tenantId)],
            'level' => ['required', 'integer', 'min:'.self::MIN_LEVEL, 'max:'.self::MAX_LEVEL],
        ]);

        // Resolve the skill through the tenant-scoped catalogue so a foreign
        // tenant's skill id can never be rated into this workspace.
        $skill = Skill::findOrFail($data['skill_id']);

        EmployeeSkill::updateOrCreate(
            [
                'skill_id' => $skill->id,
                'employee_id' => $employee->id,
            ],
            [
                'tenant_id' => app(CurrentTenant::class)->id(),
                'level' => $data['level'],
                'verified' => false,
                'verified_by_id' => null,
                'self_rated_at' => now(),
            ],
        );

        return back()->with('ok', 'Your '.$skill->name.' rating was saved.');
    }

    /** Manager/management/HR: mark a self-rating as verified. */
    public function verify(Request $request, EmployeeSkill $employeeSkill): RedirectResponse
    {
        $verifier = $this->authorizeVerifier($request);
        abort_unless($employeeSkill->tenant_id === app(CurrentTenant::class)->id(), 403);
        // Segregation of duties: a rater must not verify their own self-rating.
        abort_if($verifier->id === $employeeSkill->employee_id, 403, 'You cannot verify your own skill rating.');

        $employeeSkill->update([
            'verified' => true,
            'verified_by_id' => $verifier->id,
        ]);

        AuditLog::record('Verified skill rating', $employeeSkill->skill?->name);

        return back()->with('ok', 'Rating verified.');
    }

    /**
     * Build a gap-analysis summary: skills whose team average proficiency falls
     * below the threshold, or which no one has rated at all (zero coverage).
     * Computed in PHP to stay DB-agnostic and within the active tenant scope.
     *
     * @param  Collection<int, Skill>  $skills
     * @param  Collection<int, Employee>  $employees
     * @return Collection<int, array<string, mixed>>
     */
    private function gapAnalysis(Collection $skills, Collection $employees): Collection
    {
        $headcount = max($employees->count(), 1);

        return $skills
            ->map(function (Skill $skill) use ($headcount) {
                $ratings = $skill->employeeSkills;   // tenant-scoped relation
                $rated = $ratings->count();
                $avg = $rated > 0 ? round($ratings->avg('level'), 1) : 0.0;

                return [
                    'skill' => $skill,
                    'rated' => $rated,
                    'coverage' => (int) round(($rated / $headcount) * 100),
                    'avg' => $avg,
                ];
            })
            ->filter(fn (array $row) => $row['rated'] === 0 || $row['avg'] < self::GAP_AVG_THRESHOLD)
            ->sortBy('avg')
            ->values();
    }

    private function authorizeCatalog(Request $request): void
    {
        abort_unless(
            $this->hasTenantRole($request, self::CATALOG_ROLES),
            403,
            'Only HR and management can manage the skills catalogue.'
        );
    }

    /** Assert the actor may verify ratings; returns their employee profile. */
    private function authorizeVerifier(Request $request): Employee
    {
        abort_unless(
            $this->hasTenantRole($request, self::MATRIX_ROLES),
            403,
            'Only managers, management, and HR can verify ratings.'
        );

        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403, 'No employee profile in this workspace.');

        return $employee;
    }
}
