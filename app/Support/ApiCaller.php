<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Employee;
use Illuminate\Http\Request;

/**
 * Caller helpers shared by every token-authed read surface: the REST API
 * (ApiController) and the MCP tools (App\Mcp\Tools\*). Both run behind the
 * same auth:sanctum + api.tenant middleware pair, so both read the same
 * request attributes ApiTenant sets — tokenAbilities, apiClient, tenantRole,
 * employee — and must apply the same rules against them. One implementation,
 * so the two surfaces cannot quietly drift apart on who can see what.
 */
class ApiCaller
{
    private const PRIVILEGED = ['management', 'hr'];

    /**
     * Whether the caller's token carries a scope. A person-token is minted ['*'],
     * which Sanctum treats as every ability, so this is true for all of them and the
     * guards using it are invisible to the existing token stack.
     */
    public static function can(Request $request, string $scope): bool
    {
        /** @var list<string> $abilities */
        $abilities = $request->attributes->get('tokenAbilities', []);

        return in_array('*', $abilities, true) || in_array($scope, $abilities, true);
    }

    /**
     * Whether the caller may see the whole tenant rather than only its own records.
     *
     * A machine caller always may: it cleared its scope guard to get here, and the
     * super-admin who ticked that scope was the authorization act. There is no "own
     * records" for an app — it has no employee record to own any. A person-token is
     * judged exactly as before, on its tenant role.
     */
    public static function isPrivileged(Request $request): bool
    {
        return $request->attributes->get('apiClient') !== null
            || in_array($request->attributes->get('tenantRole', 'employee'), self::PRIVILEGED, true);
    }

    public static function employee(Request $request): ?Employee
    {
        return $request->attributes->get('employee');
    }
}
