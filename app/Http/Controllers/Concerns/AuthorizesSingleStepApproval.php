<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Single-step approval authorization for the money-request + shift-swap modules
 * (loan/advance, travel, expense, shift swap).
 *
 * These approve in ONE step by design (AK-PROC-01): one manager/management/HR decides,
 * with self-approval blocked (segregation of duties). This is deliberate — the two-step
 * verify→approve gate (RoutesApprovalsByReportingLine, used for leave/claims/overtime) is
 * intentionally NOT applied here. The guard was copy-pasted across four controllers;
 * it lives in one place now (AK-CODE-01).
 */
trait AuthorizesSingleStepApproval
{
    /** Roles that may approve or reject a single-step request. */
    protected function singleStepApproverRoles(): array
    {
        return ['manager', 'management', 'hr'];
    }

    /**
     * Authorise an approve/reject: the actor must hold a privileged role, act within the
     * record's own tenant, and never be the requester (segregation of duties). Pass the id
     * of the employee who OWNS the request — the column differs per module
     * (requester_employee_id on a shift swap, employee_id elsewhere).
     */
    protected function authorizeSingleStepApprover(Request $request, Model $record, int $ownerEmployeeId): void
    {
        $this->authorizeTenantRole($request, $this->singleStepApproverRoles());
        abort_unless($record->tenant_id === app(CurrentTenant::class)->id(), 403);

        $actor = $request->attributes->get('employee');
        abort_if($actor && $actor->id === $ownerEmployeeId, 403, 'You cannot decide your own request.');
    }
}
