<?php

namespace App\Services;

use App\Models\Employee;
use App\Support\Permissions;
use Illuminate\Database\Eloquent\Builder;

/**
 * Applies a member's data scope to a tenant-scoped Employee query. This is the
 * "data scope" leg of the access formula — it narrows WHICH records a privileged
 * user sees within their company (the role/feature gates decide WHETHER a screen is
 * reachable at all). 'company' is a no-op, so the default membership keeps full
 * visibility and nothing changes on upgrade.
 */
class DataScope
{
    /**
     * @param  Builder<Employee>  $query
     * @return Builder<Employee>
     */
    public function applyToEmployees(Builder $query, string $scope, ?Employee $self): Builder
    {
        if ($scope === 'company' || ! Permissions::isValidScope($scope)) {
            return $query;
        }

        // Narrow scope but the user has no employee record in this tenant → see nothing.
        if (! $self) {
            return $query->whereRaw('1 = 0');
        }

        return match ($scope) {
            'own' => $query->where('id', $self->id),
            // Team = self, the full reports_to_id subtree (direct reports and everyone
            // below them, per the org chart), plus direct dotted-line reports. A manager
            // who can verify someone's requests also sees them in team-scoped views.
            'team' => $query->whereIn('id', array_merge(
                $this->subtreeIds($self),
                Employee::whereHas('additionalManagers', fn ($m) => $m->whereKey($self->id))->pluck('id')->all(),
            )),
            'department' => $self->department_id
                ? $query->where('department_id', $self->department_id)
                : $query->where('id', $self->id),
            'branch' => $self->branch_id
                ? $query->where('branch_id', $self->branch_id)
                : $query->where('id', $self->id),
            default => $query,
        };
    }

    /**
     * The employee IDs visible under this scope, for screens that query BY employee_id
     * (attendance report, timesheet report, team board) rather than the Employee table
     * directly. Returns null when the scope is unrestricted ('company') so callers can
     * skip the constraint entirely; an empty array means "see nothing".
     *
     * @return list<int>|null
     */
    public function visibleEmployeeIds(string $scope, ?Employee $self): ?array
    {
        if ($scope === 'company' || ! Permissions::isValidScope($scope)) {
            return null;
        }

        return $this->applyToEmployees(Employee::query(), $scope, $self)->pluck('id')->all();
    }

    /**
     * Everyone under $self in the org chart: their direct reports, those reports'
     * reports, and so on down, plus anyone who lists $self as a dotted-line
     * manager. $self themselves is left out — callers want "my team", not "me and
     * my team". Ids only, unfiltered: callers add their own active()/archived rules.
     *
     * Same reporting line the 'team' data scope draws, so a manager's dashboard
     * counts the same people the attendance and timesheet reports count.
     *
     * @return list<int>
     */
    public function teamIds(Employee $self): array
    {
        $ids = array_merge(
            $this->subtreeIds($self),
            Employee::whereHas('additionalManagers', fn ($m) => $m->whereKey($self->id))->pluck('id')->all(),
        );

        return array_values(array_unique(array_diff($ids, [$self->id])));
    }

    /**
     * $self plus every employee below them in the reports_to_id chain, walked
     * breadth-first so any depth of org chart is covered, not just direct reports.
     *
     * @return list<int>
     */
    private function subtreeIds(Employee $self): array
    {
        $ids = [$self->id];
        $frontier = [$self->id];

        while (true) {
            $children = Employee::whereIn('reports_to_id', $frontier)->pluck('id')->all();
            $children = array_values(array_diff($children, $ids));

            if ($children === []) {
                break;
            }

            $ids = array_merge($ids, $children);
            $frontier = $children;
        }

        return $ids;
    }
}
