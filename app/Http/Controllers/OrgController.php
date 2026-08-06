<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Support\Amanahku;
use App\Support\Permissions;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class OrgController extends Controller
{
    /**
     * Build the reporting graph for the current tenant.
     *
     * The screen navigates one seat at a time rather than drawing the whole tree, so the
     * view is handed a FLAT graph (person → their manager) and walks it in the browser.
     * The nested tree is still built here, but only to measure depth. Everything comes
     * from a SINGLE query: group every employee by reports_to_id once, then recurse over
     * the in-memory map. Tenant isolation is automatic via BelongsToTenant.
     *
     * @return array{
     *     chart: array{people: array<int, mixed>, parents: array<int, int|null>, verifiers: array<int, array<int, int>>, roots: array<int, int>, directors: array<int, int>},
     *     headcount: int,
     *     maxDepth: int,
     *     byDept: Collection<string, int>,
     * }
     */
    public function screenData(Request $request, ?Employee $employee): array
    {
        $all = Employee::active()->with(['department', 'additionalManagers:id,name'])->get();

        // Optional department lens. When a valid department is selected, the tree is built
        // from that department's members only — anyone whose manager sits outside the
        // department (or has none) becomes a root of the filtered view. The full set still
        // drives the department chips so the user can switch lens at any time.
        $departments = $all
            ->map(fn (Employee $e) => $e->department?->name)
            ->filter()
            ->unique()
            ->sort()
            ->values();
        $deptParam = trim((string) $request->query('dept', ''));
        $selectedDept = $departments->contains($deptParam) ? $deptParam : null;

        $scope = $selectedDept
            ? $all->filter(fn (Employee $e) => $e->department?->name === $selectedDept)->values()
            : $all;

        $byManager = $scope->groupBy('reports_to_id');
        $ids = $scope->pluck('id')->all();

        // Directors sit in a FLAT leadership band above everything — no subtree hangs under
        // them (a lone director with an empty branch next to one with a full team only invited
        // "why is nobody under Suandy" disputes). They are co-equal cards, the approval
        // authority on top. Drawn from the FULL set, not the filtered scope, so the band stays
        // above every department lens. Anyone whose primary manager is a director surfaces as a
        // root of the tree below, so the chart proper starts at the manager tier.
        //
        // Two independent signals make someone a director, unioned:
        //   1. Their assigned position (rank band) is flagged a director band — a STAFF
        //      attribute, so a directory-only director with no login account still pins.
        //   2. Their login account holds a management-tier tenant role. Permissions already
        //      collapse `director` into `management` (Permissions::effectiveRole), so the band
        //      mirrors that: both `management` and `director` pin as directors. Platform admins
        //      are cross-tenant and hold no tenant role, so they never appear here.
        $directorUserIds = app(CurrentTenant::class)->get()
            ->users()->wherePivotIn('role', Permissions::MANAGEMENT_TIER)->pluck('users.id')->all();
        $directors = $all
            ->filter(fn (Employee $e) => (bool) $e->positionBand?->is_director
                || ($e->user_id !== null && in_array($e->user_id, $directorUserIds, true)))
            ->sortBy('name')
            ->values();
        $directorIds = $directors->pluck('id')->all();

        // Roots = non-directors with no manager, a manager outside scope, OR a director for a
        // manager (their line is drawn to the band, not nested beneath it).
        $roots = $scope
            ->filter(fn (Employee $e) => ! in_array($e->id, $directorIds, true) && (
                ! $e->reports_to_id
                || ! in_array($e->reports_to_id, $ids, true)
                || in_array($e->reports_to_id, $directorIds, true)
            ))
            ->values();

        // Build each root's subtree, recursing through the grouped map.
        $tree = $roots->map(fn (Employee $e) => $this->node($e, $byManager, $directorIds))->all();

        // The whole graph the navigator needs, in one payload. Every person in scope is
        // carried, placed or not: an unplaced person is exactly one the chart must let
        // someone place, so the rail lists them from the same source as the seats.
        $canEdit = $this->hasTenantRole($request, ['management', 'hr']);

        return [
            'directors' => $directors,
            'chart' => [
                'people' => $scope->merge($directors)->unique('id')
                    ->sortBy('name')
                    ->map(fn (Employee $e) => [
                        'id' => $e->id,
                        'name' => $e->display_name,
                        // Only set when it differs from the nickname above — otherwise the
                        // same string would render twice in the payload for no reason.
                        'fullName' => blank($e->nickname) ? null : $e->name,
                        'role' => $e->position ?: null,
                        'dept' => $e->department?->name,
                        'initials' => $e->initials,
                        'color' => $e->avatar_color,
                        'photo' => $e->photo && str_starts_with($e->photo, '/') ? $e->photo : null,
                        'swatch' => Amanahku::SWATCH[$e->workload] ?? null,
                    ])->values()->all(),
                'parents' => $scope->mapWithKeys(fn (Employee $e) => [
                    $e->id => in_array($e->reports_to_id, $ids, true) && ! in_array($e->reports_to_id, $directorIds, true)
                        ? $e->reports_to_id
                        : null,
                ])->all(),
                'verifiers' => $scope->mapWithKeys(fn (Employee $e) => [
                    $e->id => $e->additionalManagers->modelKeys(),
                ])->all(),
                'roots' => $roots->modelKeys(),
                'directors' => $directorIds,
            ],
            'headcount' => $scope->count(),
            'maxDepth' => $this->depth($tree),
            'byDept' => $this->headcountByDept($all),
            'selectedDept' => $selectedDept,
            'canEdit' => $canEdit,
        ];
    }

    /**
     * Replace one employee's additional (dotted-line) managers. Any of them may verify
     * that person's leave, claims and overtime, so this is a permission change, not
     * decoration: HR/management only. The primary line is untouched — it moves through
     * `move` — and an id that is the employee, their primary manager, or outside the
     * tenant's active set is dropped rather than rejected, so a stale rail cannot wedge
     * the save.
     */
    public function setVerifiers(Request $request, Employee $employee): JsonResponse
    {
        if (! $this->hasTenantRole($request, ['management', 'hr'])) {
            return response()->json(['error' => 'Not allowed.'], 403);
        }

        // Route-model binding resolves across every tenant, so ownership is checked here.
        if (! Employee::active()->whereKey($employee->id)->exists()) {
            return response()->json(['error' => 'Staff member not found.'], 422);
        }

        $data = $request->validate(['verifiers' => ['array'], 'verifiers.*' => ['integer']]);
        $validIds = Employee::active()->pluck('id')->all();

        $ids = collect($data['verifiers'] ?? [])
            ->map(fn ($v) => (int) $v)
            ->filter(fn (int $id) => $id !== $employee->id
                && $id !== $employee->reports_to_id
                && in_array($id, $validIds, true))
            ->unique()
            ->values()
            ->all();

        $sync = $employee->additionalManagers()->sync($ids);
        if ($sync['attached'] !== [] || $sync['detached'] !== []) {
            AuditLog::record('Updated who verifies for', $employee->name);
        }

        return response()->json(['ok' => true, 'verifiers' => $ids]);
    }

    /**
     * Single drag-and-drop re-parent from the draggable chart. The dragged person
     * (employee_id) is dropped into a manager's reports zone (manager_id, or null for the
     * top level). Returns JSON so the front-end can keep the DOM it already arranged, or
     * surface the reason and reload on rejection. HR/management only; server is the source
     * of truth for the self/loop guards even though the UI prevents most bad drops.
     */
    public function move(Request $request): JsonResponse
    {
        if (! $this->hasTenantRole($request, ['management', 'hr'])) {
            return response()->json(['error' => 'Not allowed.'], 403);
        }

        $data = $request->validate([
            'employee_id' => ['required', 'integer'],
            'manager_id' => ['nullable', 'integer'],
        ]);

        // Both lookups go through Employee::active(), which is tenant-scoped — a foreign
        // or archived id simply resolves to null and is rejected below.
        $employee = Employee::active()->whereKey($data['employee_id'])->first();
        if (! $employee) {
            return response()->json(['error' => 'Staff member not found.'], 422);
        }

        $managerId = $data['manager_id'] ?? null;
        if ($managerId !== null) {
            if ($managerId === $employee->id) {
                return response()->json(['error' => 'A person cannot report to themselves.'], 422);
            }
            if (! Employee::active()->whereKey($managerId)->exists()) {
                return response()->json(['error' => 'Manager not found.'], 422);
            }
            if ($this->wouldCycle($employee->id, $managerId)) {
                return response()->json(['error' => 'That move creates a reporting loop.'], 422);
            }
        }

        if ($employee->reports_to_id !== $managerId) {
            $employee->update(['reports_to_id' => $managerId]);
            AuditLog::record('Moved reporting line', $employee->name);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Would pointing $employeeId at $managerId form a loop? Walks up the proposed
     * manager's existing chain; a cycle exists if we reach the employee being moved.
     * The visited guard also breaks on any pre-existing stored loop, so this never spins.
     */
    private function wouldCycle(int $employeeId, int $managerId): bool
    {
        $cursor = $managerId;
        $seen = [];

        while ($cursor !== null) {
            if ($cursor === $employeeId) {
                return true;
            }
            if (isset($seen[$cursor])) {
                break;
            }
            $seen[$cursor] = true;
            $cursor = Employee::whereKey($cursor)->value('reports_to_id');
        }

        return false;
    }

    /**
     * Assemble one tree node and its descendants from the pre-grouped manager map.
     *
     * @param  Collection<int|string, Collection<int, Employee>>  $byManager
     * @return array{emp: Employee, children: array<int, mixed>, count: int}
     */
    /**
     * @param  array<int, int>  $directorIds  Ids in the leadership band, never nested as children.
     */
    private function node(Employee $emp, Collection $byManager, array $directorIds): array
    {
        // Directors never render as someone's child — they live only in the top band, so a
        // director who happens to have a primary manager isn't drawn twice.
        $reports = $byManager->get($emp->id, collect())
            ->reject(fn (Employee $child) => in_array($child->id, $directorIds, true))
            ->values();

        return [
            'emp' => $emp,
            'count' => $reports->count(),
            'children' => $reports
                ->map(fn (Employee $child) => $this->node($child, $byManager, $directorIds))
                ->all(),
        ];
    }

    /**
     * Deepest level reached across the supplied nodes (1 = a single root with no reports).
     *
     * @param  array<int, array{children: array<int, mixed>}>  $nodes
     */
    private function depth(array $nodes): int
    {
        if ($nodes === []) {
            return 0;
        }

        $deepest = 0;
        foreach ($nodes as $n) {
            $deepest = max($deepest, $this->depth($n['children']));
        }

        return $deepest + 1;
    }

    /**
     * Headcount per department for the summary strip.
     *
     * @param  Collection<int, Employee>  $all
     * @return Collection<string, int>
     */
    private function headcountByDept(Collection $all): Collection
    {
        return $all
            ->groupBy(fn (Employee $e) => $e->department?->name ?? 'Unassigned')
            ->map(fn (Collection $g) => $g->count())
            ->sortKeys();
    }
}
