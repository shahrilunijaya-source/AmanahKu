<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Employee;
use App\Models\WorkItem;
use App\Services\DataScope;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Board (work item) authorization, extracted out of WorkItemController so the
 * MCP write tools (App\Mcp\Tools\{UpdateCard,MoveCard,ArchiveCard,RestoreCard,
 * AssignTask}Tool) can apply exactly the same rules a browser request would —
 * one implementation, so the two surfaces cannot drift on who can touch what.
 *
 * Every method here is unchanged behaviour lifted verbatim from
 * WorkItemController; see git blame on that file for the history behind each
 * rule (AK-AUTHZ-01 etc.) if a docblock here references it.
 */
class BoardRules
{
    public function __construct(private DataScope $dataScope) {}

    /**
     * Roles permitted to assign a tac onto another employee's board. Compared
     * against Permissions::effectiveRole(), which collapses `director` into
     * `management` — a director outranks a manager, so listing the raw roles here
     * would silently lock out the one role above them all.
     */
    public const ASSIGNER_ROLES = ['manager', 'management', 'hr'];

    /**
     * View / comment / move: the owner, a (tac) assigner, an included participant, a
     * manager whose data scope covers the card's owner, or anyone whose role passes
     * Permissions::canSeeAll() (management, HR, or an immediate superior) — bounded,
     * same as the manager clause, by coversCardOwner().
     *
     * The canSeeAll() clause is what lets a director (or HR, or any employee with a
     * direct report) open a card from the team board without a 403 — see the design
     * doc's "Permissions" section. It is deliberately strictly wider than canManage():
     * canSeeAll() decides *whether* someone oversees people, coversCardOwner() decides
     * *whose* records, and without that second half a team-scoped manager (or anyone
     * else canSeeAll() admits) could open any card in the tenant by putting its id in
     * the URL — the same hole AK-AUTHZ-01 exists to close, reintroduced through a
     * different door.
     */
    public function authorizeAccess(Request $request, WorkItem $item, Employee $employee): void
    {
        abort_unless($item->tenant_id === app(CurrentTenant::class)->id(), 403);
        $role = $request->attributes->get('tenantRole', 'employee');
        abort_unless(
            $item->employee_id === $employee->id
            || $this->isAssigner($item, $employee)
            || $item->participants()->whereKey($employee->id)->exists()
            // A manager who may edit the card must also be able to open it. Without
            // this they hold edit rights they can never reach: show() would 403 and
            // the drawer would never render.
            || $this->isManagerOver($request, $item, $employee)
            || (Permissions::canSeeAll($employee, $role) && $this->coversCardOwner($request, $item, $employee)),
            403,
        );
    }

    /**
     * Edit fields / delete: the owner of a self-made card, or the assigner of a tac.
     * The assignee of a tac is deliberately locked out — their intent stays the
     * assigner's; they can only move it and comment.
     */
    public function authorizeManage(Request $request, WorkItem $item, Employee $employee): void
    {
        abort_unless($item->tenant_id === app(CurrentTenant::class)->id(), 403);
        abort_unless($this->canManage($request, $item, $employee), 403);
    }

    /**
     * May this viewer edit the card's fields, set its participants, or delete it?
     *
     * Three ways in: the owner of a self-made card, the assigner of a tac, or a
     * manager whose data scope covers the card's owner. Moving and commenting are
     * a wider grant handled by authorizeAccess() — a participant does both without
     * ever passing this check.
     *
     * The manager grant is deliberately bounded by DataScope. A bare role check
     * would let any manager in the tenant edit any card, including one belonging
     * to another branch or department they cannot otherwise see (AK-AUTHZ-01). A
     * company-scoped manager still reaches every card, which is the point of that
     * scope; a team-scoped one reaches only their reporting line.
     *
     * This is the single source for both the 403 gate and the drawer's read-only
     * state, so the lock a viewer sees can never disagree with what the server
     * will accept.
     */
    public function canManage(Request $request, WorkItem $item, Employee $employee): bool
    {
        $owns = $item->assigned_by_id === null
            ? $item->employee_id === $employee->id
            : $this->isAssigner($item, $employee);

        return $owns || $this->isManagerOver($request, $item, $employee);
    }

    /**
     * A `manager` whose data scope includes the employee whose board this card sits
     * on. The edit grant: role check plus the DataScope leg (coversCardOwner()). Kept
     * as its own method — rather than inlined at its one call site in canManage() —
     * because its name documents what it means there; behaviour is unchanged from
     * before the DataScope leg was split out.
     */
    public function isManagerOver(Request $request, WorkItem $item, Employee $employee): bool
    {
        $role = $request->attributes->get('tenantRole', 'employee');

        return Permissions::effectiveRole($role) === 'manager'
            && $this->coversCardOwner($request, $item, $employee);
    }

    /**
     * Whether $employee's data scope reaches the card's owner — the DataScope leg
     * alone, no role check. Shared by the edit grant (isManagerOver(), above) and the
     * wider view grant in authorizeAccess(): canSeeAll() decides *whether* a viewer
     * oversees people at all, this decides *whose* records that reaches.
     */
    public function coversCardOwner(Request $request, WorkItem $item, Employee $employee): bool
    {
        // A null return means company scope — every employee is in reach.
        $visible = $this->dataScope->visibleEmployeeIds(
            $request->attributes->get('tenantScope', 'company'),
            $employee,
        );

        return $visible === null || in_array($item->employee_id, $visible, true);
    }

    public function isAssigner(WorkItem $item, Employee $employee): bool
    {
        return $item->assigned_by_id !== null && $item->assigned_by_id === $employee->id;
    }

    /**
     * A card that involves anyone but its owner (a tac, or a card with
     * participants) must carry a due date. Checked against the state the change
     * would LEAVE BEHIND, not the raw input, because the drawer autosaves one
     * field at a time: a participant-add PATCH carries no due_at, and a
     * due_at-clearing PATCH carries no participants. One check covers both
     * directions — adding people to a due-less card, and clearing the due off a
     * shared one. due_label doesn't count; it's free display text.
     *
     * @param  array<string, mixed>  $data  validated, partial update data (as update() receives it)
     */
    public function assertDueDateRetained(WorkItem $item, array $data): void
    {
        $due = array_key_exists('due_at', $data) ? $data['due_at'] : $item->due_at;
        $hasOthers = array_key_exists('participant_ids', $data)
            ? $data['participant_ids'] !== []
            : $item->participants()->exists();

        if (! $due && ($hasOthers || $item->assigned_by_id)) {
            throw ValidationException::withMessages([
                'due_at' => 'A task shared with someone else needs a due date.',
            ]);
        }
    }
}
