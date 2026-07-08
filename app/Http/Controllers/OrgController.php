<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class OrgController extends Controller
{
    /**
     * Build the full recursive reporting tree for the current tenant.
     *
     * Roots are employees with no manager (or whose manager_id points outside the
     * tenant set). The whole tree is built from a SINGLE query: group every employee
     * by reports_to_id once, then recurse over the in-memory map — no per-node queries.
     * Tenant isolation is automatic via BelongsToTenant.
     *
     * @return array{
     *     roots: array<int, array{emp: Employee, children: array<int, mixed>, count: int}>,
     *     headcount: int,
     *     rootCount: int,
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
        //   2. Their login account holds the tenant `director` role (kept so directors
        //      designated purely by role don't regress).
        $directorUserIds = app(CurrentTenant::class)->get()
            ->users()->wherePivot('role', 'director')->pluck('users.id')->all();
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

        // HR/management get the inline bulk editor: one row per person with a manager
        // picker, so the whole chart can be wired in a single screen. $editStaff carries
        // just the fields the editor binds to, sorted by name for a scannable list. It
        // always spans every active staff member, not just the filtered scope.
        $canEdit = $this->hasTenantRole($request, ['management', 'hr']);
        $editStaff = $canEdit
            ? $all->sortBy('name')->map(fn (Employee $e) => [
                'id' => $e->id,
                'name' => $e->name,
                'reports_to_id' => $e->reports_to_id,
                'extra_manager_ids' => $e->additionalManagers->modelKeys(),
            ])->values()
            : collect();

        return [
            'directors' => $directors,
            'roots' => $tree,
            'headcount' => $scope->count(),
            // Top-level entries = the directors band plus the remaining non-director roots.
            'rootCount' => $directors->count() + $roots->count(),
            'maxDepth' => $this->depth($tree),
            'byDept' => $this->headcountByDept($all),
            'selectedDept' => $selectedDept,
            'canEdit' => $canEdit,
            'editStaff' => $editStaff,
        ];
    }

    /**
     * Bulk-set reporting lines from the org-chart editor. Input is manager[employeeId] =
     * managerId for every active staff member. The whole submission is validated as one
     * graph — any self-link or loop rejects the entire save (nothing is written), so the
     * tree builder can never be handed a cycle. Only HR/management may reach this.
     */
    public function updateLines(Request $request): RedirectResponse
    {
        $this->authorizeTenantRole($request, ['management', 'hr']);

        $all = Employee::active()->with('additionalManagers:id')->get(['id', 'name', 'reports_to_id']);
        $validIds = $all->pluck('id')->all();
        $input = (array) $request->input('manager', []);
        $extrasInput = (array) $request->input('extra_managers', []);

        // Proposed graph: every active employee → chosen PRIMARY manager (or null). Values
        // outside the tenant's active set, and self-links, collapse to null up front.
        $proposed = [];
        foreach ($all as $e) {
            $managerId = isset($input[$e->id]) && $input[$e->id] !== '' ? (int) $input[$e->id] : null;
            if ($managerId === $e->id || ! in_array($managerId, $validIds, true)) {
                $managerId = null;
            }
            $proposed[$e->id] = $managerId;
        }

        // Only the PRIMARY line forms the tree, so only it is cycle-checked. Additional
        // managers never recurse (verify is a flat one-level lookup), so they can't loop.
        if ($this->graphHasCycle($proposed)) {
            return back()->with('error', 'Those reporting lines form a loop — no changes were saved. Check who reports to whom.');
        }

        // Additional (dotted-line) managers per employee: valid active ids, never self, and
        // never a duplicate of the primary line (that manager already verifies).
        $extras = [];
        foreach ($all as $e) {
            $extras[$e->id] = collect($extrasInput[$e->id] ?? [])
                ->map(fn ($v) => (int) $v)
                ->filter(fn (int $id) => $id !== $e->id && $id !== $proposed[$e->id] && in_array($id, $validIds, true))
                ->unique()
                ->values()
                ->all();
        }

        // Apply only the rows that actually change, so the audit/log stays meaningful.
        $changed = 0;
        foreach ($all as $e) {
            if ($e->reports_to_id !== $proposed[$e->id]) {
                $e->update(['reports_to_id' => $proposed[$e->id]]);
                $changed++;
            }

            $sync = $e->additionalManagers()->sync($extras[$e->id]);
            if ($sync['attached'] !== [] || $sync['detached'] !== []) {
                $changed++;
            }
        }

        AuditLog::record('Updated reporting lines', $changed.' change(s)');

        return back()->with('ok', $changed === 0 ? 'No reporting lines changed.' : "$changed reporting line(s) updated.");
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
     * Does the proposed [employeeId => managerId|null] map contain a cycle? Walks each
     * node up its manager chain with a per-walk visited guard; a node reachable from
     * itself is a loop. O(n²) worst case, fine for a single company's headcount.
     *
     * @param  array<int, int|null>  $proposed
     */
    private function graphHasCycle(array $proposed): bool
    {
        foreach (array_keys($proposed) as $start) {
            $cursor = $proposed[$start];
            $seen = [$start => true];
            while ($cursor !== null) {
                if (isset($seen[$cursor])) {
                    return true;
                }
                $seen[$cursor] = true;
                $cursor = $proposed[$cursor] ?? null;
            }
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
