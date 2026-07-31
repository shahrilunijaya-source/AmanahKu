<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Permissions;
use Illuminate\Http\Request;

abstract class Controller
{
    /** The acting user's role in the current tenant (set by ResolveTenant). */
    protected function tenantRole(Request $request): ?string
    {
        return $request->attributes->get('tenantRole');
    }

    /**
     * True when the acting user's tenant role is one of $roles. A `director` also satisfies
     * any check that lists `management` — director is a management super-set (see
     * Permissions::effectiveRole), so this one line carries director access through every
     * management-gated controller without editing each call site.
     */
    protected function hasTenantRole(Request $request, array $roles): bool
    {
        $role = $this->tenantRole($request);

        if ($role === null) {
            return false;
        }

        return in_array($role, $roles, true)
            || in_array(Permissions::effectiveRole($role), $roles, true);
    }

    /** 403 unless the acting user's tenant role is one of $roles. */
    protected function authorizeTenantRole(Request $request, array $roles): void
    {
        abort_unless($this->hasTenantRole($request, $roles), 403);
    }
}
