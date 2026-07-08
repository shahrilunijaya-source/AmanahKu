<?php

namespace App\Support;

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
     * Permissions that a per-user override can actually change. An override only bites
     * where a controller gates on canInTenant(); today that is the staff domain
     * (EmployeeController create/update/import). The override UI + writer are scoped to
     * this set so admins are never shown — or able to save — a toggle that does nothing
     * (AK-AUTHZ-04). Widen this list only in lockstep with new canInTenant() enforcement.
     *
     * @return array<int, string>
     */
    public static function overridable(): array
    {
        return ['staff.create', 'staff.update', 'staff.import'];
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
