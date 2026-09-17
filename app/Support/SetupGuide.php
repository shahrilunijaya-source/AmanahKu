<?php

declare(strict_types=1);

namespace App\Support;

use App\Http\Controllers\SetupController;
use App\Models\CompanySetupProgress;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\Request;

/**
 * The live setup guide's data: Launch Center's ordered step list, surfaced to the
 * browser so the dock, the sidebar ring and the coachmark pointers can agree on
 * which step is current. No second source of truth — every flag comes from
 * SetupController::compute(). Which step is *current* is decided client-side
 * (first step not done and not skipped in this browser), so "Skip for now" never
 * touches the server.
 */
class SetupGuide
{
    /** Roles that run setup. A director collapses to management via Permissions::effectiveRole. */
    private const ROLES = ['hr', 'management'];

    /**
     * Null when the guide must not show: no tenant, setup already finished, or the
     * viewer is not HR / management tier. A superadmin browsing a tenant reads as
     * management (User::roleIn), so they see it too — intended.
     *
     * @return array{
     *   steps: list<array{key:string,label:string,label_ms:string,guide:string,guide_ms:string,screen:string,nav:string,url:string,done:bool,auto:bool}>,
     *   done: int,
     *   total: int
     * }|null
     */
    /**
     * One-time config screens have no sidebar row of their own (App\Support\Amanahku
     * nav: they are reached through Company Setup), so the sidebar ring for their steps
     * lands on the Company Setup row instead.
     */
    private const NAV_VIA_SETUP = ['settings', 'position', 'attendance-admin', 'leave-setup', 'timesheet-setup'];

    public static function forRequest(Request $request): ?array
    {
        if (app(CurrentTenant::class)->get() === null) {
            return null;
        }

        $role = $request->attributes->get('tenantRole');
        if (! is_string($role) || ! in_array(Permissions::effectiveRole($role), self::ROLES, true)) {
            return null;
        }

        // Cheap check first: the full compute() runs a dozen detectors, and most
        // requests on a live company should never pay for them.
        if (CompanySetupProgress::forCurrentTenant()->completed_at !== null) {
            return null;
        }

        $computed = app(SetupController::class)->compute();

        $steps = array_map(fn (array $row) => [
            'key' => $row['key'],
            'label' => $row['label'],
            'label_ms' => $row['label_ms'],
            'guide' => $row['guide'],
            'guide_ms' => $row['guide_ms'],
            'screen' => $row['screen'],
            // The sidebar row to ring: the screen itself, or Company Setup for config screens.
            'nav' => in_array($row['screen'], self::NAV_VIA_SETUP, true) ? 'setup' : $row['screen'],
            'url' => route('app.screen', ['screen' => $row['screen']] + $row['query']),
            'done' => $row['done'],
            'auto' => $row['auto'],
        ], $computed['rows']);

        return [
            'steps' => array_values($steps),
            'done' => $computed['done'],
            'total' => $computed['total'],
        ];
    }
}
