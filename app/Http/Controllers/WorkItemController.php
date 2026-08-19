<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AppNotification;
use App\Models\Employee;
use App\Models\WorkItem;
use App\Models\WorkItemComment;
use App\Services\DataScope;
use App\Support\Permissions;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

class WorkItemController extends Controller
{
    private const STATUSES = ['todo', 'prog', 'review', 'done'];

    private const STATUS_LABELS = ['todo' => 'To Do', 'prog' => 'In Progress', 'review' => 'In Review', 'done' => 'Done'];

    /**
     * Roles permitted to assign a tac onto another employee's board. Compared
     * against Permissions::effectiveRole(), which collapses `director` into
     * `management` — a director outranks a manager, so listing the raw roles here
     * would silently lock out the one role above them all.
     */
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
            'project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')->where('tenant_id', app(CurrentTenant::class)->id())],
        ]);

        $status = $data['status'] ?? 'todo';

        $item = $employee->workItems()->create([
            'title' => $data['title'],
            'type' => $data['type'],
            'priority' => $data['priority'],
            'due_label' => $data['due_label'] ?? null,
            'project_id' => $data['project_id'] ?? null,
            'status' => $status,
            'progress' => 0,
            'done_at' => $status === 'done' ? now() : null,
            // Place new cards at the bottom of their column.
            'sort_order' => (int) $employee->workItems()->where('status', $status)->max('sort_order') + 1,
        ]);

        if ($request->expectsJson()) {
            return response()->json(['card' => $this->cardPayload($item), 'html' => $this->cardHtml($item)], 201);
        }

        return back()->with('ok', 'Work item added.');
    }

    /** A privileged user assigns an adhoc task onto a staff member's board. */
    public function assign(Request $request, Employee $employee): RedirectResponse|JsonResponse
    {
        $assigner = $this->employee($request);
        $role = Permissions::effectiveRole($request->attributes->get('tenantRole', 'employee'));
        abort_unless(in_array($role, self::ASSIGNER_ROLES, true), 403, 'Your role cannot assign tasks.');
        abort_unless($employee->tenant_id === app(CurrentTenant::class)->id(), 403);
        // No new work onto an archived person — they hold no live responsibility (H8).
        abort_if($employee->isArchived(), 422, 'You cannot assign a task to an archived staff member.');

        // Same rule update() applies (isUntouchedLinkRow(), below) — a link row left
        // completely blank is dropped rather than rejected, so a form always carrying
        // one empty row (see team-board.blade.php) doesn't fail validation by default.
        if (is_array($request->input('links'))) {
            $request->merge(['links' => array_values(array_filter(
                $request->input('links'),
                fn ($link) => is_array($link) && ! $this->isUntouchedLinkRow($link),
            ))]);
        }

        $data = $request->validateWithBag('assign', [
            'title' => ['required', 'string', 'max:160'],
            'type' => ['required', 'in:assignment,task,adhoc'],
            'priority' => ['required', 'in:high,medium,low'],
            'due_at' => ['nullable', 'date'],
            'description' => ['nullable', 'string', 'max:5000'],
            'links' => ['sometimes', 'array', 'max:12'],
            'links.*.label' => ['required_with:links', 'string', 'max:60'],
            'links.*.url' => ['required_with:links', 'url', 'max:2000'],
        ]);

        $item = $employee->workItems()->create([
            'title' => $data['title'],
            'type' => $data['type'],
            'priority' => $data['priority'],
            'due_at' => $data['due_at'] ?? null,
            'description' => $data['description'] ?? null,
            'links' => $data['links'] ?? [],
            'status' => 'todo',
            'progress' => 0,
            'assigned_by_id' => $assigner->id,
            'assigned_at' => now(),
            // Bottom of the assignee's To Do column.
            'sort_order' => (int) $employee->workItems()->where('status', 'todo')->max('sort_order') + 1,
        ]);

        AppNotification::send(
            $employee->user_id,
            $assigner->display_name.' assigned you a task',
            $item->title,
            route('app.screen', 'board'),
            mail: true,
        );

        // The assigner is the assignedBy — set it so cardPayload() needs no extra query.
        $item->setRelation('assignedBy', $assigner);

        if ($request->expectsJson()) {
            return response()->json(['card' => $this->cardPayload($item)], 201);
        }

        return back()->with('ok', 'Task assigned to '.$employee->display_name.'.');
    }

    /** Full card detail + comment thread for the detail drawer. */
    public function show(Request $request, WorkItem $workItem): JsonResponse
    {
        $employee = $this->employee($request);
        $this->authorizeAccess($request, $workItem, $employee);

        $workItem->load(['comments.employee', 'assignedBy', 'participants', 'projectRef', 'employee']);

        // The same call the write gate makes, so the drawer's read-only state can
        // never disagree with what the server will accept. A participant opens the
        // card read-only and may still move it and comment.
        $canManage = $this->canManage($request, $workItem, $employee);

        return response()->json([
            'card' => $this->cardPayload($workItem) + [
                'description' => $workItem->description,
                'can_manage' => $canManage,
                // Drawer subline only: "Opened 12 Jul 2026 by X" for a self-made card,
                // "Assigned 12 Jul 2026 by X" for a tac. Fetched once on open — later
                // write responses don't repeat these, so the merge in the client just
                // leaves them as-is.
                'opened_at' => ($workItem->assigned_by_id ? $workItem->assigned_at : $workItem->created_at)?->format('d M Y'),
                'owner_name' => $workItem->assigned_by_id ? $workItem->assignedBy?->display_name : $workItem->employee?->display_name,
                // Feeds the drawer's @-mention picker. Participants + assigner only —
                // see mentionableEmployees() for why the roster stops there.
                'mentionable' => $this->mentionablePayload($workItem),
            ],
            'comments' => $workItem->comments->map(fn (WorkItemComment $c) => $this->commentPayload($c, $employee))->values(),
        ]);
    }

    /**
     * Edit a card's fields from the detail drawer. The drawer autosaves one
     * field at a time (Stage 2 of the board redesign), so every field here is
     * `sometimes`: a PATCH body carrying only `{ priority: 'high' }` must not
     * be rejected for lacking a title, and `$workItem->update($data)` must
     * only touch the columns actually present in the request. Without
     * `sometimes`, Laravel's validator backfills every absent-but-nullable key
     * as null, and a single-field autosave would silently wipe every other
     * field on the card.
     *
     * `estimate_hours` is `prohibited`: the drawer dropped the field from its
     * UI, and Stage 4 dropped the column itself, so a request still carrying
     * it is a stale client, not a partial edit, and gets rejected outright
     * rather than erroring on an unknown column.
     */
    public function update(Request $request, WorkItem $workItem): JsonResponse
    {
        $employee = $this->employee($request);
        $this->authorizeManage($request, $workItem, $employee);

        // The drawer's link editor keeps a blank "+ Add a link" row in local state
        // until it's filled in — drop rows nobody touched before validating, same
        // rule TotController::store() uses for TOT session links.
        if (is_array($request->input('links'))) {
            $request->merge(['links' => array_values(array_filter(
                $request->input('links'),
                fn ($link) => is_array($link) && ! $this->isUntouchedLinkRow($link),
            ))]);
        }

        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'type' => ['sometimes', 'required', 'in:assignment,task,adhoc'],
            'priority' => ['sometimes', 'required', 'in:high,medium,low'],
            'due_at' => ['sometimes', 'nullable', 'date'],
            'due_label' => ['sometimes', 'nullable', 'string', 'max:60'],
            'estimate_hours' => ['prohibited'],
            'project_id' => ['sometimes', 'nullable', 'integer', Rule::exists('projects', 'id')->where('tenant_id', app(CurrentTenant::class)->id())],
            'labels' => ['sometimes', 'array'],
            'labels.*' => ['string', Rule::in(array_keys(WorkItem::LABELS))],
            'links' => ['sometimes', 'array', 'max:12'],
            'links.*.label' => ['required_with:links', 'string', 'max:60'],
            'links.*.url' => ['required_with:links', 'url', 'max:2000'],
            'participant_ids' => ['sometimes', 'array'],
            'participant_ids.*' => ['integer'],
        ]);

        // Participants are a relation, not a column — pull them out before the fill.
        if (array_key_exists('participant_ids', $data)) {
            $this->syncParticipants($workItem, $data['participant_ids'], $employee);
            unset($data['participant_ids']);
        }

        $workItem->update($data);
        $workItem->load('participants');

        return response()->json([
            'card' => $this->cardPayload($workItem) + ['description' => $workItem->description],
            'html' => $this->cardHtml($workItem),
        ]);
    }

    /**
     * A link row nobody filled in: no label and no URL. Dropped on save rather
     * than rejected — mirrors TotController::isUntouchedLinkRow(), simplified
     * since WorkItem has no pre-seeded default labels to also check for.
     *
     * @param  array<string, mixed>  $link
     */
    private function isUntouchedLinkRow(array $link): bool
    {
        return blank($link['label'] ?? null) && blank($link['url'] ?? null);
    }

    /**
     * Move / reorder cards. Two shapes:
     *  - Drag: { status, ids: [...] } re-sequences the whole destination column.
     *  - Dropdown fallback: { status } moves a single card.
     */
    public function move(Request $request, WorkItem $workItem): RedirectResponse|JsonResponse
    {
        $employee = $this->employee($request);
        $this->authorizeAccess($request, $workItem, $employee);

        $data = $request->validate([
            'status' => ['required', 'in:'.implode(',', self::STATUSES)],
            'ids' => ['sometimes', 'array'],
            'ids.*' => ['integer'],
        ]);

        $wasDone = $workItem->status === 'done';

        $workItem->update([
            'status' => $data['status'],
            'progress' => $data['status'] === 'done' ? 100 : $workItem->progress,
            // Stamped only on the todo/prog/review -> done transition, not on every
            // move or reorder — the auto-archive clock (WorkItem::archive-done) reads
            // this, not updated_at, so reordering cards within Done doesn't reset it.
            'done_at' => (! $wasDone && $data['status'] === 'done') ? now() : $workItem->done_at,
        ]);

        // Close the loop: when an assigned tac first reaches Done, tell the assigner.
        if (! $wasDone && $workItem->status === 'done' && $workItem->assigned_by_id) {
            $assigner = Employee::find($workItem->assigned_by_id);
            AppNotification::send(
                $assigner?->user_id,
                $employee->display_name.' completed: '.$workItem->title,
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
            return response()->json(['ok' => true, 'status' => $workItem->status, 'html' => $this->cardHtml($workItem)]);
        }

        return back()->with('ok', 'Work item moved to '.(self::STATUS_LABELS[$workItem->status] ?? $workItem->status).'.');
    }

    /** Delete one of the employee's own cards. */
    public function destroy(Request $request, WorkItem $workItem): JsonResponse
    {
        $employee = $this->employee($request);
        $this->authorizeManage($request, $workItem, $employee);

        $workItem->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * Archive a Done card off the board. Only Done cards may be archived — a card
     * still in play has no "put it away" state. Reversible via restore(), unlike
     * destroy(), so this is the safe alternative to deleting finished work.
     */
    public function archive(Request $request, WorkItem $workItem): JsonResponse
    {
        $employee = $this->employee($request);
        $this->authorizeManage($request, $workItem, $employee);
        abort_unless($workItem->status === 'done', 422, 'Only a Done card can be archived.');

        $workItem->update(['archived_at' => now()]);

        return response()->json(['ok' => true]);
    }

    /** Bring an archived card back to the board, at To Do. */
    public function restore(Request $request, WorkItem $workItem): JsonResponse
    {
        $employee = $this->employee($request);
        $this->authorizeManage($request, $workItem, $employee);

        $workItem->update([
            'archived_at' => null,
            'status' => 'todo',
            'sort_order' => (int) $employee->workItems()->where('status', 'todo')->max('sort_order') + 1,
        ]);

        return response()->json(['ok' => true, 'html' => $this->cardHtml($workItem)]);
    }

    /** Archived cards on the current employee's board (own + participant), for the reopen list. */
    public function archived(Request $request): JsonResponse
    {
        $employee = $this->employee($request);

        $items = WorkItem::query()
            ->where(fn ($q) => $q->where('employee_id', $employee->id)
                ->orWhereHas('participants', fn ($p) => $p->whereKey($employee->id)))
            ->whereNotNull('archived_at')
            ->orderByDesc('archived_at')
            ->get(['id', 'title', 'archived_at']);

        return response()->json([
            'items' => $items->map(fn (WorkItem $i) => [
                'id' => $i->id,
                'title' => $i->title,
                'archived_at' => $i->archived_at?->format('d M Y'),
            ])->values(),
        ]);
    }

    /** Add a comment to a card. */
    public function comment(Request $request, WorkItem $workItem): JsonResponse
    {
        $employee = $this->employee($request);
        $this->authorizeAccess($request, $workItem, $employee);

        $data = $request->validate(['body' => ['required', 'string', 'max:2000']]);

        $comment = $workItem->comments()->create([
            'employee_id' => $employee->id,
            'body' => $data['body'],
        ]);
        $comment->setRelation('employee', $employee);

        $this->notifyMentions($workItem, $employee, $data['body']);

        return response()->json([
            'comment' => $this->commentPayload($comment, $employee),
            'count' => $workItem->comments()->count(),
            // The card's comment badge lives on its face, so a repaint needs the
            // card's HTML too, not just the count — repaintNode() no longer
            // rebuilds markup client-side.
            'html' => $this->cardHtml($workItem),
        ], 201);
    }

    /** Delete one's own comment. */
    public function commentDestroy(Request $request, WorkItemComment $comment): JsonResponse
    {
        $employee = $this->employee($request);
        abort_unless($comment->employee_id === $employee->id, 403);

        $workItem = $comment->workItem;
        $comment->delete();

        return response()->json([
            'ok' => true,
            'count' => WorkItemComment::where('work_item_id', $workItem->id)->count(),
            'html' => $this->cardHtml($workItem),
        ]);
    }

    private function employee(Request $request): Employee
    {
        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403, 'No employee profile in this workspace.');

        return $employee;
    }

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
    private function authorizeAccess(Request $request, WorkItem $item, Employee $employee): void
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
     * Set the people included on a shared card. Open to every role: including
     * someone is collaboration on a card you already manage, not an assignment,
     * so it is bounded by canManage() (checked in update() before this runs)
     * rather than by role. The owner is never their own participant. Newly added
     * people are notified once; re-saving with an unchanged set does not re-ping
     * the survivors.
     */
    private function syncParticipants(WorkItem $item, array $ids, Employee $actor): void
    {
        // Keep only real, active employees in this tenant; never the owner themselves.
        $target = Employee::active()
            ->whereIn('id', array_filter($ids))
            ->where('id', '!=', $item->employee_id)
            ->pluck('id');

        $before = $item->participants()->pluck('employees.id');
        $item->participants()->sync($target);

        foreach ($target->diff($before) as $addedId) {
            AppNotification::send(
                Employee::find($addedId)?->user_id,
                $actor->display_name.' added you to a task',
                $item->title,
                route('app.screen', 'board'),
                mail: true,
            );
        }
    }

    /**
     * Edit fields / delete: the owner of a self-made card, or the assigner of a tac.
     * The assignee of a tac is deliberately locked out — their intent stays the
     * assigner's; they can only move it and comment.
     */
    private function authorizeManage(Request $request, WorkItem $item, Employee $employee): void
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
    private function canManage(Request $request, WorkItem $item, Employee $employee): bool
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
    private function isManagerOver(Request $request, WorkItem $item, Employee $employee): bool
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
    private function coversCardOwner(Request $request, WorkItem $item, Employee $employee): bool
    {
        // A null return means company scope — every employee is in reach.
        $visible = app(DataScope::class)->visibleEmployeeIds(
            $request->attributes->get('tenantScope', 'company'),
            $employee,
        );

        return $visible === null || in_array($item->employee_id, $visible, true);
    }

    private function isAssigner(WorkItem $item, Employee $employee): bool
    {
        return $item->assigned_by_id !== null && $item->assigned_by_id === $employee->id;
    }

    /**
     * Who this card may mention: its participants plus its assigner — never the
     * owner, never the wider tenant roster. This mirrors authorizeAccess()
     * exactly (owner / assigner / participants may view a card), minus the
     * owner, so a mention can never notify someone who would 403 opening the
     * card. It can only be grown by someone who manages the card, since that is
     * what adding a participant requires (see syncParticipants()).
     *
     * @return Collection<int, Employee>
     */
    private function mentionableEmployees(WorkItem $item): Collection
    {
        $item->loadMissing(['participants', 'assignedBy']);

        // ->values() first: participants() returns the live relation collection,
        // and push()ing the assigner onto it directly would mutate that cached
        // relation in place, leaking the assigner into every other place the
        // card's participants are read (e.g. cardPayload()'s participant chips).
        $pool = $item->participants->values();
        if ($item->assigned_by_id && $item->assignedBy) {
            $pool->push($item->assignedBy);
        }

        return $pool->unique('id')->values();
    }

    /** @return array<int, array{id:int,name:string,initials:?string,color:?string}> */
    private function mentionablePayload(WorkItem $item): array
    {
        return $this->mentionableEmployees($item)
            ->map(fn (Employee $e) => ['id' => $e->id, 'name' => $e->display_name, 'initials' => $e->initials, 'color' => $e->avatar_color])
            ->values()
            ->all();
    }

    /**
     * Re-parses @Name out of the just-saved comment body against the card's own
     * mentionable set — never a client-supplied id list — so a crafted request
     * cannot notify someone who is not on the card. The author never notifies
     * themselves even if their own name appears (e.g. a participant mentioning
     * themselves out of habit).
     *
     * Names here are display names (nickname, else legal name), the same string
     * the mention picker inserts, so the match cannot drift from what the author
     * clicked. Two people sharing one display name on a card is an accepted
     * ambiguity (see
     * the design doc): disambiguating would mean storing resolved employee ids
     * on the comment, which is a migration this stage deliberately skips. The
     * first employee encountered with a given name wins; the rest of that name
     * is silently skipped rather than notifying every same-named person.
     */
    private function notifyMentions(WorkItem $item, Employee $author, string $body): void
    {
        $seenNames = [];
        $recipientUserIds = collect();

        foreach ($this->mentionableEmployees($item) as $employee) {
            if (isset($seenNames[$employee->display_name])) {
                continue;
            }
            $seenNames[$employee->display_name] = true;

            if ($employee->id === $author->id || ! str_contains($body, '@'.$employee->display_name)) {
                continue;
            }

            if ($employee->user_id) {
                $recipientUserIds->push($employee->user_id);
            }
        }

        if ($recipientUserIds->isEmpty()) {
            return;
        }

        AppNotification::sendMany(
            $recipientUserIds->unique(),
            $author->display_name.' mentioned you on a task',
            $item->title,
            route('app.screen', ['screen' => 'board', 'card' => $item->id]),
        );
    }

    /**
     * Render the card partial for a write response, so the client repaints by
     * swapping markup instead of rebuilding it from a JSON payload in JS. The
     * one place every write response's HTML comes from — keep it in sync with
     * cardPayload() below, which still feeds the detail modal's in-memory state.
     */
    private function cardHtml(WorkItem $item): string
    {
        $item->loadMissing(['participants', 'projectRef', 'assignedBy'])->loadCount('comments');

        return view('partials.work-card', ['c' => $item])->render();
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
            'labels' => $item->labels ?? [],
            'links' => $item->links ?? [],
            'project' => $item->projectRef ? ['id' => $item->projectRef->id, 'name' => $item->projectRef->name] : null,
            'comments_count' => $item->comments_count ?? $item->comments()->count(),
            'assigned_by' => $item->assigned_by_id ? [
                'name' => $item->assignedBy?->display_name,
                'initials' => $item->assignedBy?->initials,
                'color' => $item->assignedBy?->avatar_color,
            ] : null,
            // Only emit participants when the relation is loaded — store()/assign()
            // return fresh cards with none, and we avoid a stray query for them.
            'participants' => $item->relationLoaded('participants')
                ? $item->participants->map(fn (Employee $e) => [
                    'id' => $e->id,
                    'name' => $e->display_name,
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
            'author' => $c->employee?->display_name ?? 'Someone',
            'initials' => $c->employee?->initials ?? '··',
            'color' => $c->employee?->avatar_color ?? 'var(--muted)',
            'when' => $c->created_at?->diffForHumans(),
            'mine' => $c->employee_id === $viewer->id,
        ];
    }
}
