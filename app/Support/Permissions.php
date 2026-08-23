<?php

namespace App\Support;

use App\Models\Employee;

/**
 * Role → permission map, layered on top of the existing 4-role enum. This is the
 * declarative ACL surface from the spec (§9). Today it is advisory/reference — the
 * authoritative gate remains the per-controller role check plus the company feature
 * entitlement and the per-member data scope (App\Services\DataScope). It gives a
 * single place to read "what can this role do", and a `roleHas()` helper that future
 * gates can adopt without changing any call sites.
 *
 * Final access = Company feature entitlement + role permission + (future) user
 * override + data scope.
 */
class Permissions
{
    /**
     * Roles that carry full management-tier authority. `director` is `management` plus a
     * board seat: it inherits every management permission and gate (see effectiveRole())
     * and, additionally, gives final approval on requests. It is a strict super-set — never
     * assign it expecting LESS access than management.
     */
    public const MANAGEMENT_TIER = ['management', 'director'];

    /**
     * Roles that give FINAL approval on a verified leave / claim / overtime request: the
     * management tier plus HR. HR sits directly under the directors, so the two of them
     * together are the last gate — the manager above the requester only verifies.
     */
    public const FINAL_APPROVAL_ROLES = ['management', 'director', 'hr'];

    /**
     * Roles that oversee company-wide attendance/tasks/timesheets without needing an
     * org-chart direct report: a plain 'manager' (an immediate superior by definition),
     * management, and HR. Backs canSeeAll()'s role branch below and
     * AttendanceReportController::LOCATION_ROLES, kept in one place so the two access
     * checks can't quietly drift apart.
     */
    public const OVERSIGHT_ROLES = ['manager', 'management', 'hr'];

    /**
     * Collapse a stored role to the role whose permission set / gates it inherits. Director
     * is management for every access decision; every other role maps to itself. This is the
     * single hinge that lets `director` exist without touching the ~30 `['management', …]`
     * gates scattered across controllers and views.
     */
    public static function effectiveRole(string $role): string
    {
        return $role === 'director' ? 'management' : $role;
    }

    /** Data scope options, narrowest → widest. */
    public const SCOPES = ['own', 'team', 'department', 'branch', 'company'];

    public const SCOPE_LABELS = [
        'own' => 'Own record only',
        'team' => 'Direct reports',
        'department' => 'Own department',
        'branch' => 'Own branch',
        'company' => 'Entire company',
    ];

    /**
     * The data scope a fresh membership starts on, decided by its role. A manager oversees
     * their own reporting line, not the whole company, so they start on 'team' — otherwise
     * every manager reads every employee's attendance records, clock remarks and timesheets
     * from day one. Management and HR do oversee everyone, so they stay company-wide. An
     * admin can still widen or narrow any membership afterwards in the Roles screen.
     */
    public static function defaultScopeForRole(string $role): string
    {
        return self::effectiveRole($role) === 'manager' ? 'team' : 'company';
    }

    /** Cumulative per-role permission sets. */
    public const ROLE_PERMISSIONS = [
        'employee' => [
            'company.view',
            'staff.view',
            'leave.view', 'leave.apply',
            'attendance.view',
        ],
        'manager' => [
            'company.view',
            'staff.view',
            'leave.view', 'leave.apply', 'leave.approve',
            'attendance.view', 'attendance.manage',
            'report.view',
        ],
        'management' => [
            'company.view', 'company.update',
            'branch.view', 'branch.create', 'branch.update', 'branch.delete', 'branch.manage',
            'department.view', 'department.create', 'department.update', 'department.delete',
            'position.view', 'position.create', 'position.update', 'position.delete',
            'staff.view', 'staff.create', 'staff.update', 'staff.delete', 'staff.import',
            'leave.view', 'leave.apply', 'leave.approve', 'leave.manage',
            'attendance.view', 'attendance.manage',
            'role.view', 'role.manage',
            'tot.assign',
            'report.view', 'report.export',
        ],
        'hr' => [
            'company.view', 'company.update',
            'branch.view', 'branch.create', 'branch.update', 'branch.delete', 'branch.manage',
            'department.view', 'department.create', 'department.update', 'department.delete',
            'position.view', 'position.create', 'position.update', 'position.delete',
            'staff.view', 'staff.create', 'staff.update', 'staff.delete', 'staff.import',
            'leave.view', 'leave.apply', 'leave.approve', 'leave.manage',
            'attendance.view', 'attendance.manage',
            'role.view', 'role.manage',
            'tot.assign',
            'report.view', 'report.export',
        ],
    ];

    /** @return array<int, string> permissions granted to a role (director inherits management). */
    public static function forRole(string $role): array
    {
        return self::ROLE_PERMISSIONS[self::effectiveRole($role)] ?? self::ROLE_PERMISSIONS['employee'];
    }

    /** Does a role grant a permission? */
    public static function roleHas(string $role, string $permission): bool
    {
        return in_array($permission, self::forRole($role), true);
    }

    public static function isValidScope(string $scope): bool
    {
        return in_array($scope, self::SCOPES, true);
    }

    /**
     * True when the signed-in user may see company-wide attendance / tasks / timesheets:
     * management, HR, or an immediate superior (has at least one direct report). Used to
     * gate both the dock "See all" links and the screens they open, and — via the
     * WorkItemController card-view grant — the individual cards those screens link to.
     *
     * Moved here (unchanged) from AppController so both the screen gate and the card
     * gate read from one definition rather than two copies drifting apart.
     */
    public static function canSeeAll(?Employee $employee, string $role): bool
    {
        // The 'manager' role is an immediate superior by definition; management and HR
        // oversee everyone. An 'employee'-role user still qualifies if the org chart
        // gives them at least one direct report (reports_to_id points at them).
        // effectiveRole() collapses 'director' → 'management' so a board-tier director
        // (a strict management super-set) reaches every oversight screen without a
        // direct report of their own — same single hinge hasTenantRole() relies on.
        if (in_array(self::effectiveRole($role), self::OVERSIGHT_ROLES, true)) {
            return true;
        }

        return $employee !== null
            && Employee::active()->where('reports_to_id', $employee->id)->exists();
    }

    /** Distinct, sorted catalogue of every permission key across all roles. */
    public static function all(): array
    {
        $all = [];
        foreach (self::ROLE_PERMISSIONS as $perms) {
            foreach ($perms as $p) {
                $all[$p] = true;
            }
        }
        $keys = array_keys($all);
        sort($keys);

        return $keys;
    }

    public static function exists(string $permission): bool
    {
        return in_array($permission, self::all(), true);
    }

    /**
     * Permissions that a per-user override can actually change. An override only bites where
     * a controller gates on canInTenant(): the staff domain (EmployeeController
     * create/update/import) and the TOT presenter field (TotController). The override UI and
     * writer are scoped to this set so admins are never shown, or able to save, a toggle that
     * does nothing (AK-AUTHZ-04). Widen this list only in lockstep with new canInTenant()
     * enforcement.
     *
     * @return array<int, string>
     */
    public static function overridable(): array
    {
        return ['staff.create', 'staff.update', 'staff.import', 'tot.assign'];
    }

    /** overridable() grouped by domain (the part before the dot), for the override UI. */
    public static function overridableGrouped(): array
    {
        $groups = [];
        foreach (self::overridable() as $perm) {
            $groups[explode('.', $perm)[0]][] = $perm;
        }

        return $groups;
    }
}
