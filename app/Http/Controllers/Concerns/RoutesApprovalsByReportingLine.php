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
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Two-step approval routing driven by the organisation chart's reporting line.
 *
 * One flow for leave, claims, overtime (and any future request type):
 *
 *   submitted ──verify(immediate superior)──▶ verified ──approve(HR / director)──▶ approved
 *
 * - VERIFY is the requester's direct manager only (employees.reports_to_id) — the link the
 *   org chart configures. They recommend; they cannot give final approval.
 * - APPROVE is HR or the management tier (director), and only once a request is verified.
 * - THE TOP OF THE CHART SKIPS VERIFY: a requester who is themselves in the final-approval
 *   tier (HR reports straight to the directors; a director/management requester has no
 *   superior at all) has no intermediate superior to recommend their own request. Theirs
 *   opens already `verified` (with no verifier recorded) and goes straight to the
 *   final-approval queue, where someone else in that tier signs it off — nobody approves
 *   their own request.
 * - Segregation of duties: nobody acts on their own request, and the verifier may not also
 *   approve. A request with no superior set stays at `submitted` until one is assigned.
 *
 * Used by the controllers that authorise each step and by the screen-data builders that
 * assemble the "to verify" and "to approve" queues.
 */
trait RoutesApprovalsByReportingLine
{
    /**
     * Roles that give final approval (after verification): HR, management and director.
     * Listed explicitly (not just via effectiveRole) because notifyManagementToApprove()
     * queries the tenant_user pivot by literal role — a director must be pinged, not only
     * permitted.
     */
    private function approvalManagerRoles(): array
    {
        return Permissions::FINAL_APPROVAL_ROLES;
    }

    /**
     * True when the acting user's own request needs no verification step: they already sit in
     * the final-approval tier, so nobody above them can recommend their request. HR reports
     * directly to the directors, and a director/management requester is the top of the org
     * chart with no superior at all — their submission would otherwise land in nobody's verify
     * queue and sit at `submitted` forever. Gated on ROLE, not on a missing reports_to_id: a
     * plain employee with no superior is a broken org chart (see StuckRequests) and must not
     * quietly self-route past the manager they should have.
     */
    protected function skipsVerification(Request $request, ?Employee $requester = null): bool
    {
        // Filed on somebody's behalf (HR for a member of staff): the ROUTE follows the
        // requester's role, not the filer's — HR filing for a plain employee must still
        // send it to that employee's manager, and HR filing for a director must not.
        if ($requester && $requester->user_id !== auth()->id()) {
            $tenant = app(CurrentTenant::class)->get();
            $role = $tenant && $requester->user ? $requester->user->roleIn($tenant) : 'employee';

            return in_array($role, Permissions::FINAL_APPROVAL_ROLES, true)
                || in_array(Permissions::effectiveRole($role), Permissions::FINAL_APPROVAL_ROLES, true);
        }

        return $this->hasTenantRole($request, Permissions::FINAL_APPROVAL_ROLES);
    }

    /**
     * Who a new request is FOR. Normally the acting user's own employee record. HR may
     * file on behalf of any active member of staff by posting `employee_id`; anyone else
     * posting that field is ignored and files for themselves. Returns null when there is
     * nobody to file for at all (no employee profile).
     */
    protected function requesterFor(Request $request): ?Employee
    {
        $actor = $request->attributes->get('employee');

        if (! $request->filled('employee_id') || ! $this->hasTenantRole($request, ['hr'])) {
            return $actor;
        }

        // Employee is tenant-scoped, so an id from another tenant finds nothing.
        $target = Employee::active()->whereKey((int) $request->input('employee_id'))->first();
        abort_unless($target !== null, 422, 'That person is not an active member of staff.');

        return $target;
    }

    /** The filer to record on a request created by requesterFor(): the actor, only when filing for someone else. */
    protected function filedByIdFor(Request $request, Employee $requester): ?int
    {
        $actor = $request->attributes->get('employee');

        return $actor && $actor->id !== $requester->id ? $actor->id : null;
    }

    /**
     * Status columns for a two-step request the acting user is creating for themselves —
     * `submitted` normally, already `verified` for an HR or management-tier requester (see
     * skipsVerification()).
     * Spread into the create() array so every module opens at the same stage.
     *
     * @return array{status: string, verified_at?: Carbon}
     */
    protected function openingStatusColumns(Request $request, ?Employee $requester = null): array
    {
        return $this->skipsVerification($request, $requester)
            ? ['status' => 'verified', 'verified_at' => now()]
            : ['status' => 'submitted'];
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
     * @return array{verifiers: Collection, approvers: Collection}
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
    protected function assertApprover(Request $request, ?Employee $requester, int $recordTenantId, ?int $verifiedById = null, ?int $filedById = null): void
    {
        $this->assertSameTenant($recordTenantId);

        $actor = $request->attributes->get('employee');
        abort_if($actor && $actor->isArchived(), 403, 'An archived staff member cannot act on requests.');
        abort_if($actor && $requester && $actor->id === $requester->id, 403, 'You cannot approve your own request.');

        abort_unless(
            $this->hasTenantRole($request, $this->approvalManagerRoles()),
            403,
            'Only HR or a director can give final approval.',
        );

        abort_if(
            $actor && $verifiedById && $actor->id === $verifiedById,
            403,
            'The person who verified a request cannot also approve it.',
        );

        abort_if(
            $actor && $filedById && $actor->id === $filedById,
            403,
            'The person who filed a request on someone\'s behalf cannot also approve it.',
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
            $this->assertApprover($request, $record->employee, $record->tenant_id, $record->verified_by_id, $record->filed_by_id);

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
     * The acting person's employee id, or 0 when the request carries no employee — a user
     * who belongs to the tenant but has no employee record. 0 matches nothing, so every
     * scope built on it closes rather than opening, which is the safe direction.
     */
    private function actingEmployeeId(Request $request): int
    {
        return $request->attributes->get('employee')?->id ?? 0;
    }

    /**
     * The viewer's VERIFY queue: still-submitted requests from anyone they manage — their
     * direct reports (reports_to_id) plus anyone who lists them as an additional manager.
     * Empty for anyone who manages nobody. Assumes the model has an `employee` relation.
     */
    protected function scopeToVerify(Builder $query, Request $request): Builder
    {
        $actorId = $this->actingEmployeeId($request);

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
     * The viewer's APPROVED history: requests they personally signed off this year, plus
     * any the applicant later withdrew (still their approval — it happened, then it was
     * pulled). Matched on approved_by_id, never on verified_by_id: a verifier recommends,
     * the approver decides, and the two must not be conflated or a manager who passed a
     * request up would see somebody else's decision listed as their own.
     *
     * Scoped by the date of the DECISION, not of the submission, so the "this year" label
     * and the column agree — a request filed in December and approved in January belongs
     * to January's figures.
     *
     * Claims decided before the 2026_09_02 decision trail carry no approver and cannot
     * appear here — there is nothing recorded to match against (see that migration).
     *
     * @param  list<string>  $statuses  the states that still count as approved. Claims pass
     *                                  'paid' as well: payroll flips an approved claim to
     *                                  paid when it reimburses it, and being reimbursed is
     *                                  not the approver un-approving it.
     */
    protected function scopeApprovedByViewer(Builder $query, Request $request, array $statuses = ['approved', 'cancelled']): Builder
    {
        return $query
            ->whereIn('status', $statuses)
            ->where('approved_by_id', $this->actingEmployeeId($request))
            ->whereYear('approved_at', now()->year);
    }

    /** The same for refusals, matched on the rejecter alone. See scopeApprovedByViewer(). */
    protected function scopeRejectedByViewer(Builder $query, Request $request): Builder
    {
        return $query
            ->where('status', 'rejected')
            ->where('rejected_by_id', $this->actingEmployeeId($request))
            ->whereYear('rejected_at', now()->year);
    }

    /**
     * Can this viewer approve anything at all — either as somebody's superior or as
     * management? Decides whether the Approvals tab exists, which must NOT depend on
     * something currently being pending: a cleared queue would otherwise take the
     * viewer's whole decision history off the screen with it.
     */
    protected function canReviewAnything(Request $request): bool
    {
        if ($this->hasTenantRole($request, $this->approvalManagerRoles())) {
            return true;
        }

        $actorId = $this->actingEmployeeId($request);

        return $actorId > 0 && Employee::query()->active()
            ->where(fn (Builder $w) => $w
                ->where('reports_to_id', $actorId)
                ->orWhereHas('additionalManagers', fn (Builder $m) => $m->whereKey($actorId)))
            ->exists();
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

    /**
     * Notify every final approver (HR + management tier) that a verified request awaits their
     * approval. The acting user is skipped: they either just verified it, or they are the HR
     * requester whose own request opened pre-verified — neither needs telling.
     */
    protected function notifyManagementToApprove(int $tenantId, string $title, ?string $body, string $url): void
    {
        $userIds = Tenant::find($tenantId)
            ?->users()
            ->wherePivotIn('role', $this->approvalManagerRoles())
            ->whereKeyNot(auth()->id() ?? 0)
            ->pluck('users.id')
            ->all() ?? [];

        AppNotification::sendMany($userIds, $title, $body, $url);
    }
}
