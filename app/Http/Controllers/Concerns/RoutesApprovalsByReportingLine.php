<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Models\AppNotification;
use App\Models\Employee;
use App\Models\Tenant;
use App\Support\Permissions;
use App\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Two-step approval routing driven by the organisation chart's reporting line.
 *
 * One flow for leave, claims, overtime (and any future request type):
 *
 *   submitted ──verify(immediate superior)──▶ verified ──approve(management)──▶ approved
 *
 * - VERIFY is the requester's direct manager only (employees.reports_to_id) — the link the
 *   org chart configures. They recommend; they cannot give final approval.
 * - APPROVE is the `management` role only, and only once a request is verified. HR no
 *   longer gives final approval here (HR still runs settings).
 * - Segregation of duties: nobody acts on their own request, and the verifier may not also
 *   approve. A request with no superior set stays at `submitted` until one is assigned.
 *
 * Used by the controllers that authorise each step and by the screen-data builders that
 * assemble the "to verify" and "to approve" queues.
 */
trait RoutesApprovalsByReportingLine
{
    /**
     * Roles that give final approval (after verification): management and director. Listed
     * explicitly (not just via effectiveRole) because notifyManagementToApprove() queries the
     * tenant_user pivot by literal role — a director must be pinged, not only permitted.
     */
    private function approvalManagerRoles(): array
    {
        return Permissions::MANAGEMENT_TIER;
    }

    private function assertSameTenant(int $recordTenantId): void
    {
        abort_unless($recordTenantId === app(CurrentTenant::class)->id(), 403);
    }

    /**
     * The display-only approval chain for a requester, so the applicant knows up front who
     * signs off their leave / claim / overtime — before they even submit. Shared by every
     * two-step request screen.
     *
     * - verifiers: the requester's immediate superior (reports_to_id) plus any dotted-line
     *   managers — the exact set allowed to verify (see verifierIds()/verifiers()).
     * - approvers: the tenant's management tier. Final approval is any ONE of them (no single
     *   person is pre-assigned), so this is the pool, not a named approver. The requester is
     *   excluded — nobody approves their own request.
     *
     * @return array{verifiers: \Illuminate\Support\Collection, approvers: \Illuminate\Support\Collection}
     */
    protected function approvalChain(?Employee $employee): array
    {
        $verifiers = $employee ? $employee->verifiers() : collect();

        $tenant = app(CurrentTenant::class)->get();
        $managementUserIds = $tenant
            ? $tenant->users()->wherePivotIn('role', $this->approvalManagerRoles())->pluck('users.id')->all()
            : [];

        $approvers = empty($managementUserIds)
            ? collect()
            : Employee::active()
                ->whereIn('user_id', $managementUserIds)
                ->when($employee, fn (Builder $q) => $q->whereKeyNot($employee->id))
                ->orderBy('name')
                ->get();

        return ['verifiers' => collect($verifiers)->values(), 'approvers' => $approvers->values()];
    }

    /**
     * Authorise the VERIFY step: the acting user must be one of the requester's managers —
     * the primary superior (reports_to_id) OR any additional (dotted-line) manager. Either
     * may verify. Self-guard first so nobody verifies their own request.
     */
    protected function assertVerifier(Request $request, ?Employee $requester, int $recordTenantId): void
    {
        $this->assertSameTenant($recordTenantId);

        $actor = $request->attributes->get('employee');
        abort_if($actor && $actor->isArchived(), 403, 'An archived staff member cannot act on requests.');
        abort_if($actor && $requester && $actor->id === $requester->id, 403, 'You cannot verify your own request.');

        $isManager = $actor && $requester && in_array($actor->id, $requester->verifierIds(), true);
        abort_unless($isManager, 403, 'Only this person\'s manager can verify their request.');
    }

    /**
     * Authorise the APPROVE step: management role only, and never the person who verified
     * (or the requester themselves). Stage validity (must be `verified`) is checked by the
     * caller against the record status.
     */
    protected function assertApprover(Request $request, ?Employee $requester, int $recordTenantId, ?int $verifiedById = null): void
    {
        $this->assertSameTenant($recordTenantId);

        $actor = $request->attributes->get('employee');
        abort_if($actor && $actor->isArchived(), 403, 'An archived staff member cannot act on requests.');
        abort_if($actor && $requester && $actor->id === $requester->id, 403, 'You cannot approve your own request.');

        abort_unless(
            $this->hasTenantRole($request, $this->approvalManagerRoles()),
            403,
            'Only management can give final approval.',
        );

        abort_if(
            $actor && $verifiedById && $actor->id === $verifiedById,
            403,
            'The person who verified a request cannot also approve it.',
        );
    }

    /**
     * Authorise a REJECT at whatever stage the record sits. A submitted request is rejected
     * by the immediate superior (or management as an override); a verified request is
     * rejected by management.
     */
    protected function assertCanReject(Request $request, Model $record): void
    {
        abort_unless(in_array($record->status, ['submitted', 'verified'], true), 422, 'Only a pending request can be rejected.');

        $isManagement = $this->hasTenantRole($request, $this->approvalManagerRoles());

        if ($record->status === 'verified') {
            $this->assertApprover($request, $record->employee, $record->tenant_id, $record->verified_by_id);

            return;
        }

        // Submitted: management may override-reject, otherwise the immediate superior.
        if ($isManagement) {
            $this->assertSameTenant($record->tenant_id);
            $actor = $request->attributes->get('employee');
            abort_if($actor && $actor->isArchived(), 403, 'An archived staff member cannot act on requests.');
            abort_if($actor && $actor->id === $record->employee_id, 403, 'You cannot reject your own request.');

            return;
        }

        $this->assertVerifier($request, $record->employee, $record->tenant_id);
    }

    /**
     * The viewer's VERIFY queue: still-submitted requests from anyone they manage — their
     * direct reports (reports_to_id) plus anyone who lists them as an additional manager.
     * Empty for anyone who manages nobody. Assumes the model has an `employee` relation.
     */
    protected function scopeToVerify(Builder $query, Request $request): Builder
    {
        $actorId = $request->attributes->get('employee')?->id ?? 0;

        // active() on the requester so a submitted request from a since-archived person
        // drops out of their manager's queue — an archived person holds no live obligation.
        return $query
            ->where('status', 'submitted')
            ->whereHas('employee', fn (Builder $q) => $q
                ->active()
                ->where(fn (Builder $w) => $w
                    ->where('reports_to_id', $actorId)
                    ->orWhereHas('additionalManagers', fn (Builder $m) => $m->whereKey($actorId))));
    }

    /**
     * The viewer's APPROVE queue: every verified request, but only for management. Returns
     * an always-empty query for everyone else.
     */
    protected function scopeToApprove(Builder $query, Request $request): Builder
    {
        if (! $this->hasTenantRole($request, $this->approvalManagerRoles())) {
            return $query->whereRaw('1 = 0');
        }

        // active() on the requester: a verified request whose owner was archived after
        // verification drops out of management's approve queue (no balance decrement for a
        // detached person). On-archive cancellation is the primary guard; this backs it up.
        return $query->where('status', 'verified')
            ->whereHas('employee', fn (Builder $q) => $q->active());
    }

    /**
     * Notify everyone who can verify this request — the primary superior and any additional
     * managers — that something awaits their verification. Deduplicated so a manager listed
     * on both links is pinged once.
     */
    protected function notifyManagerToVerify(Employee $requester, string $title, ?string $body, string $url): void
    {
        foreach ($requester->verifiers() as $manager) {
            if ($manager->user_id) {
                AppNotification::send($manager->user_id, $title, $body, $url);
            }
        }
    }

    /** Notify every management user that a verified request awaits final approval. */
    protected function notifyManagementToApprove(int $tenantId, string $title, ?string $body, string $url): void
    {
        $userIds = Tenant::find($tenantId)
            ?->users()
            ->wherePivotIn('role', $this->approvalManagerRoles())
            ->pluck('users.id')
            ->all() ?? [];

        AppNotification::sendMany($userIds, $title, $body, $url);
    }
}
