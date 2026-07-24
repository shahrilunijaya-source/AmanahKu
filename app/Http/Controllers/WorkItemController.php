<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AppNotification;
use App\Models\Employee;
use App\Models\WorkItem;
use App\Models\WorkItemComment;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WorkItemController extends Controller
{
    private const STATUSES = ['todo', 'prog', 'review', 'done'];

    private const STATUS_LABELS = ['todo' => 'To Do', 'prog' => 'In Progress', 'review' => 'In Review', 'done' => 'Done'];

    /** Roles permitted to assign a tac onto another employee's board. */
    private const ASSIGNER_ROLES = ['manager', 'management', 'hr'];

    /** The current employee adds a work item to their own board. */
    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $employee = $this->employee($request);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'type' => ['required', 'in:assignment,task,adhoc'],
            'priority' => ['required', 'in:high,medium,low'],
            'status' => ['nullable', 'in:'.implode(',', self::STATUSES)],
            'due_label' => ['nullable', 'string', 'max:60'],
            'estimate_hours' => ['nullable', 'integer', 'min:0', 'max:500'],
            'project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')->where('tenant_id', app(CurrentTenant::class)->id())],
        ]);

        $status = $data['status'] ?? 'todo';

        $item = $employee->workItems()->create([
            'title' => $data['title'],
            'type' => $data['type'],
            'priority' => $data['priority'],
            'due_label' => $data['due_label'] ?? null,
            'estimate_hours' => $data['estimate_hours'] ?? null,
            'project_id' => $data['project_id'] ?? null,
            'status' => $status,
            'progress' => 0,
            // Place new cards at the bottom of their column.
            'sort_order' => (int) $employee->workItems()->where('status', $status)->max('sort_order') + 1,
        ]);

        if ($request->expectsJson()) {
            return response()->json(['card' => $this->cardPayload($item)], 201);
        }

        return back()->with('ok', 'Work item added.');
    }

    /** A privileged user assigns an adhoc task onto a staff member's board. */
    public function assign(Request $request, Employee $employee): RedirectResponse|JsonResponse
    {
        $assigner = $this->employee($request);
        $role = $request->attributes->get('tenantRole', 'employee');
        abort_unless(in_array($role, self::ASSIGNER_ROLES, true), 403, 'Your role cannot assign tasks.');
        abort_unless($employee->tenant_id === app(CurrentTenant::class)->id(), 403);
        // No new work onto an archived person — they hold no live responsibility (H8).
        abort_if($employee->isArchived(), 422, 'You cannot assign a task to an archived staff member.');

        $data = $request->validateWithBag('assign', [
            'title' => ['required', 'string', 'max:160'],
            'type' => ['required', 'in:assignment,task,adhoc'],
            'priority' => ['required', 'in:high,medium,low'],
            'due_at' => ['nullable', 'date'],
            'description' => ['nullable', 'string', 'max:5000'],
        ]);

        $item = $employee->workItems()->create([
            'title' => $data['title'],
            'type' => $data['type'],
            'priority' => $data['priority'],
            'due_at' => $data['due_at'] ?? null,
            'description' => $data['description'] ?? null,
            'status' => 'todo',
            'progress' => 0,
            'assigned_by_id' => $assigner->id,
            'assigned_at' => now(),
            // Bottom of the assignee's To Do column.
            'sort_order' => (int) $employee->workItems()->where('status', 'todo')->max('sort_order') + 1,
        ]);

        AppNotification::send(
            $employee->user_id,
            $assigner->name.' assigned you a task',
            $item->title,
            route('app.screen', 'board'),
        );

        // The assigner is the assignedBy — set it so cardPayload() needs no extra query.
        $item->setRelation('assignedBy', $assigner);

        if ($request->expectsJson()) {
            return response()->json(['card' => $this->cardPayload($item)], 201);
        }

        return back()->with('ok', 'Task assigned to '.$employee->name.'.');
    }

    /** Full card detail + comment thread for the detail modal. */
    public function show(Request $request, WorkItem $workItem): JsonResponse
    {
        $employee = $this->employee($request);
        $this->authorizeAccess($workItem, $employee);

        $workItem->load(['comments.employee', 'assignedBy', 'participants', 'projectRef']);

        // Mirrors authorizeManage(): only the owner of a self-made card, or a tac's
        // assigner, may edit fields / set participants / delete. A participant opens
        // the card read-only (they may still move it and comment). The modal uses
        // this to lock its editable fields instead of letting a doomed save 403.
        $canManage = $workItem->assigned_by_id === null
            ? $workItem->employee_id === $employee->id
            : $this->isAssigner($workItem, $employee);

        return response()->json([
            'card' => $this->cardPayload($workItem) + ['description' => $workItem->description, 'can_manage' => $canManage],
            'comments' => $workItem->comments->map(fn (WorkItemComment $c) => $this->commentPayload($c, $employee))->values(),
        ]);
    }

    /** Edit a card's fields from the detail modal. */
    public function update(Request $request, WorkItem $workItem): JsonResponse
    {
        $employee = $this->employee($request);
        $this->authorizeManage($workItem, $employee);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:5000'],
            'type' => ['required', 'in:assignment,task,adhoc'],
            'priority' => ['required', 'in:high,medium,low'],
            'due_at' => ['nullable', 'date'],
            'due_label' => ['nullable', 'string', 'max:60'],
            'estimate_hours' => ['nullable', 'integer', 'min:0', 'max:500'],
            'project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')->where('tenant_id', app(CurrentTenant::class)->id())],
            'labels' => ['sometimes', 'array'],
            'labels.*' => ['string', Rule::in(array_keys(WorkItem::LABELS))],
            'participant_ids' => ['sometimes', 'array'],
            'participant_ids.*' => ['integer'],
        ]);

        // Participants are a relation, not a column — pull them out before the fill.
        // Present only when the client sends the people picker (privileged roles).
        if (array_key_exists('participant_ids', $data)) {
            $this->syncParticipants($request, $workItem, $data['participant_ids']);
            unset($data['participant_ids']);
        }

        $workItem->update($data);
        $workItem->load('participants');

        return response()->json(['card' => $this->cardPayload($workItem) + ['description' => $workItem->description]]);
    }

    /**
     * Move / reorder cards. Two shapes:
     *  - Drag: { status, ids: [...] } re-sequences the whole destination column.
     *  - Dropdown fallback: { status } moves a single card.
     */
    public function move(Request $request, WorkItem $workItem): RedirectResponse|JsonResponse
    {
        $employee = $this->employee($request);
        $this->authorizeAccess($workItem, $employee);

        $data = $request->validate([
            'status' => ['required', 'in:'.implode(',', self::STATUSES)],
            'ids' => ['sometimes', 'array'],
            'ids.*' => ['integer'],
        ]);

        $wasDone = $workItem->status === 'done';

        $workItem->update([
            'status' => $data['status'],
            'progress' => $data['status'] === 'done' ? 100 : $workItem->progress,
        ]);

        // Close the loop: when an assigned tac first reaches Done, tell the assigner.
        if (! $wasDone && $workItem->status === 'done' && $workItem->assigned_by_id) {
            $assigner = Employee::find($workItem->assigned_by_id);
            AppNotification::send(
                $assigner?->user_id,
                $employee->name.' completed: '.$workItem->title,
                null,
                route('app.screen', 'board'),
            );
        }

        // Persist the destination column order. Only the employee's own cards are touched.
        if (! empty($data['ids'])) {
            foreach (array_values($data['ids']) as $i => $id) {
                $employee->workItems()->whereKey($id)->update(['sort_order' => $i]);
            }
        }

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'status' => $workItem->status]);
        }

        return back()->with('ok', 'Work item moved to '.(self::STATUS_LABELS[$workItem->status] ?? $workItem->status).'.');
    }

    /** Delete one of the employee's own cards. */
    public function destroy(Request $request, WorkItem $workItem): JsonResponse
    {
        $employee = $this->employee($request);
        $this->authorizeManage($workItem, $employee);

        $workItem->delete();

        return response()->json(['ok' => true]);
    }

    /** Add a comment to a card. */
    public function comment(Request $request, WorkItem $workItem): JsonResponse
    {
        $employee = $this->employee($request);
        $this->authorizeAccess($workItem, $employee);

        $data = $request->validate(['body' => ['required', 'string', 'max:2000']]);

        $comment = $workItem->comments()->create([
            'employee_id' => $employee->id,
            'body' => $data['body'],
        ]);
        $comment->setRelation('employee', $employee);

        return response()->json([
            'comment' => $this->commentPayload($comment, $employee),
            'count' => $workItem->comments()->count(),
        ], 201);
    }

    /** Delete one's own comment. */
    public function commentDestroy(Request $request, WorkItemComment $comment): JsonResponse
    {
        $employee = $this->employee($request);
        abort_unless($comment->employee_id === $employee->id, 403);

        $workItemId = $comment->work_item_id;
        $comment->delete();

        return response()->json(['ok' => true, 'count' => WorkItemComment::where('work_item_id', $workItemId)->count()]);
    }

    private function employee(Request $request): Employee
    {
        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403, 'No employee profile in this workspace.');

        return $employee;
    }

    /** View / comment / move: the owner, a (tac) assigner, or an included participant. */
    private function authorizeAccess(WorkItem $item, Employee $employee): void
    {
        abort_unless($item->tenant_id === app(CurrentTenant::class)->id(), 403);
        abort_unless(
            $item->employee_id === $employee->id
            || $this->isAssigner($item, $employee)
            || $item->participants()->whereKey($employee->id)->exists(),
            403,
        );
    }

    /**
     * Set the people included on a shared card. Privileged roles only — adding
     * someone pushes the card onto their board, so it mirrors the assign() gate.
     * The owner is never their own participant. Newly added people are notified
     * once; re-saving with an unchanged set does not re-ping the survivors.
     */
    private function syncParticipants(Request $request, WorkItem $item, array $ids): void
    {
        $role = $request->attributes->get('tenantRole', 'employee');
        abort_unless(in_array($role, self::ASSIGNER_ROLES, true), 403, 'Your role cannot include people on a card.');

        // Keep only real, active employees in this tenant; never the owner themselves.
        $target = Employee::active()
            ->whereIn('id', array_filter($ids))
            ->where('id', '!=', $item->employee_id)
            ->pluck('id');

        $before = $item->participants()->pluck('employees.id');
        $item->participants()->sync($target);

        $actor = $this->employee($request);
        foreach ($target->diff($before) as $addedId) {
            AppNotification::send(
                Employee::find($addedId)?->user_id,
                $actor->name.' added you to a task',
                $item->title,
                route('app.screen', 'board'),
            );
        }
    }

    /**
     * Edit fields / delete: the owner of a self-made card, or the assigner of a tac.
     * The assignee of a tac is deliberately locked out — their intent stays the
     * assigner's; they can only move it and comment.
     */
    private function authorizeManage(WorkItem $item, Employee $employee): void
    {
        abort_unless($item->tenant_id === app(CurrentTenant::class)->id(), 403);
        $allowed = $item->assigned_by_id === null
            ? $item->employee_id === $employee->id
            : $this->isAssigner($item, $employee);
        abort_unless($allowed, 403);
    }

    private function isAssigner(WorkItem $item, Employee $employee): bool
    {
        return $item->assigned_by_id !== null && $item->assigned_by_id === $employee->id;
    }

    private function cardPayload(WorkItem $item): array
    {
        return [
            'id' => $item->id,
            'title' => $item->title,
            'type' => $item->type,
            'priority' => $item->priority,
            'status' => $item->status,
            'due_label' => $item->dueText(),
            'due_at' => $item->due_at?->format('Y-m-d'),
            'estimate_hours' => $item->estimate_hours,
            'labels' => $item->labels ?? [],
            'project' => $item->projectRef ? ['id' => $item->projectRef->id, 'name' => $item->projectRef->name] : null,
            'comments_count' => $item->comments_count ?? $item->comments()->count(),
            'assigned_by' => $item->assigned_by_id ? [
                'name' => $item->assignedBy?->name,
                'initials' => $item->assignedBy?->initials,
                'color' => $item->assignedBy?->avatar_color,
            ] : null,
            // Only emit participants when the relation is loaded — store()/assign()
            // return fresh cards with none, and we avoid a stray query for them.
            'participants' => $item->relationLoaded('participants')
                ? $item->participants->map(fn (Employee $e) => [
                    'id' => $e->id,
                    'name' => $e->name,
                    'initials' => $e->initials,
                    'color' => $e->avatar_color,
                ])->values()->all()
                : [],
        ];
    }

    private function commentPayload(WorkItemComment $c, Employee $viewer): array
    {
        return [
            'id' => $c->id,
            'body' => $c->body,
            'author' => $c->employee?->name ?? 'Someone',
            'initials' => $c->employee?->initials ?? '··',
            'color' => $c->employee?->avatar_color ?? 'var(--muted)',
            'when' => $c->created_at?->diffForHumans(),
            'mine' => $c->employee_id === $viewer->id,
        ];
    }
}
