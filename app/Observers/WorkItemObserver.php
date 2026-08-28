<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\SyncWorkItemCalendarEventJob;
use App\Models\Employee;
use App\Models\WorkItem;
use App\Models\WorkItemProgressStint;

/**
 * Detects the WorkItem changes that matter for Google Calendar sync and
 * dispatches SyncWorkItemCalendarEventJob accordingly. Only employee_id,
 * due_at, archived_at and status are watched — an edit to title/priority/etc.
 * with none of those changed does not re-sync (the calendar event's summary
 * can go stale on a title-only edit; out of scope per the spec's trigger list).
 */
class WorkItemObserver
{
    public function saved(WorkItem $item): void
    {
        $this->recordProgressStint($item);

        // isDirty() works here because Eloquent's `saved` event fires BEFORE
        // syncOriginal() runs (see Model::finishSave()) — so at this point $original
        // still holds the pre-save values, and isDirty() correctly reflects what this
        // save just changed, on both create and update. Deliberately NOT using
        // wasRecentlyCreated: Laravel sets that flag true on insert and never resets
        // it back to false on the same in-memory instance across later save()/update()
        // calls, so it stays stuck true forever — wrongly treating every later
        // unrelated update as "just created".
        $relevant = $item->isDirty(['employee_id', 'due_at', 'archived_at', 'status']);

        if (! $relevant) {
            return;
        }

        // Genuine reassignment: the field changed (isDirty) AND it had a prior value
        // (getOriginal is not null). On create, getOriginal('employee_id') is null so
        // reassigned stays false; on true reassignment, both are true.
        $reassigned = $item->isDirty('employee_id') && $item->getOriginal('employee_id') !== null;

        if ($reassigned) {
            $this->deleteFromOldAssignee($item);
        }

        $syncable = $item->due_at !== null && $item->archived_at === null && $item->status !== 'done';

        if (! $syncable) {
            if (! $reassigned && $item->google_event_id) {
                $this->deleteCurrentEvent($item);
            }

            return;
        }

        SyncWorkItemCalendarEventJob::dispatch(
            tenantId: $item->tenant_id,
            action: 'upsert',
            workItemId: $item->id,
        );
    }

    public function deleted(WorkItem $item): void
    {
        if (! $item->google_event_id) {
            return;
        }

        $this->deleteCurrentEvent($item);
    }

    /**
     * Keep work_item_progress_stints in step with the card's column. This is the only
     * record of which days a card was worked — the timesheet prefill reads nothing else,
     * and nothing can reconstruct it after the fact.
     *
     * Runs on every save, ahead of the calendar-sync early return: a status move is
     * relevant here even when it changes nothing the calendar cares about.
     *
     * tenant_id is passed explicitly rather than left to BelongsToTenant: this observer
     * fires on paths with no active tenant context (the MCP tools, console commands),
     * where the trait's fail-closed guard would throw.
     */
    private function recordProgressStint(WorkItem $item): void
    {
        // In Review is worked time too: the card is out of the writer's hands but the
        // work happened, and reviewing it is itself work. Only To Do, Done and archive
        // stop the clock.
        $active = ['prog', 'review'];
        $wasProg = in_array($item->getOriginal('status'), $active, true);
        $isProg = in_array($item->status, $active, true);

        // Archiving parks a card without moving it out of the column; the stint must
        // still close, or an archived card keeps being suggested for every later day.
        $justArchived = $item->isDirty('archived_at') && $item->archived_at !== null;

        // A card moving between the two worked columns is not entering either of them, so
        // nothing here would open a stint — including the cards that were already parked in
        // In Review when In Review started counting, whose stint was closed on the way out
        // of In Progress. Treat a move within the worked columns with no stint running as an
        // entry, so those cards heal themselves rather than staying invisible to the
        // timesheet forever.
        $healing = $isProg && $wasProg && $item->isDirty('status') && ! $this->hasOpenStint($item);

        if ($isProg && ! $justArchived && (! $wasProg || $healing)) {
            $this->closeOpenStints($item);

            WorkItemProgressStint::create([
                'tenant_id' => $item->tenant_id,
                'work_item_id' => $item->id,
                'started_at' => now(),
            ]);

            return;
        }

        if ($wasProg && ! $isProg) {
            $this->closeOpenStints($item);

            return;
        }

        if ($isProg && $justArchived) {
            $this->closeOpenStints($item);
        }
    }

    private function hasOpenStint(WorkItem $item): bool
    {
        return WorkItemProgressStint::withoutGlobalScope('tenant')
            ->where('work_item_id', $item->id)
            ->whereNull('ended_at')
            ->exists();
    }

    /**
     * Close every stint still open on a card. Normally there is at most one; the loop
     * is what makes a dangling stint (a status change that somehow bypassed this
     * observer) self-heal on the card's next move rather than leaving the card
     * suggested forever.
     */
    private function closeOpenStints(WorkItem $item): void
    {
        WorkItemProgressStint::withoutGlobalScope('tenant')
            ->where('work_item_id', $item->id)
            ->whereNull('ended_at')
            ->update(['ended_at' => now()]);
    }

    private function deleteFromOldAssignee(WorkItem $item): void
    {
        $oldEventId = $item->getOriginal('google_event_id');
        $oldEmployeeId = $item->getOriginal('employee_id');

        if (! $oldEventId || ! $oldEmployeeId) {
            return;
        }

        $oldUserId = Employee::withoutGlobalScope('tenant')->find($oldEmployeeId)?->user_id;
        if (! $oldUserId) {
            return;
        }

        SyncWorkItemCalendarEventJob::dispatch(
            tenantId: $item->tenant_id,
            action: 'delete',
            workItemId: $item->id,
            userId: $oldUserId,
            googleEventId: $oldEventId,
        );
    }

    private function deleteCurrentEvent(WorkItem $item): void
    {
        $userId = Employee::withoutGlobalScope('tenant')->find($item->employee_id)?->user_id;
        if (! $userId) {
            return;
        }

        SyncWorkItemCalendarEventJob::dispatch(
            tenantId: $item->tenant_id,
            action: 'delete',
            workItemId: $item->id,
            userId: $userId,
            googleEventId: $item->google_event_id,
        );
    }
}
