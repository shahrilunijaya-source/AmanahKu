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
            // Team = direct reports, additional (dotted-line) reports, and self. A manager
            // who can verify someone's requests also sees them in team-scoped views.
            'team' => $query->where(fn ($q) => $q
                ->where('reports_to_id', $self->id)
                ->orWhere('id', $self->id)
                ->orWhereHas('additionalManagers', fn ($m) => $m->whereKey($self->id))),
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
}
