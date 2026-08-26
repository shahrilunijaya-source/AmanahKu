<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\SyncWorkItemCalendarEventJob;
use App\Models\Employee;
use App\Models\WorkItem;

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
        // Check if any relevant field is currently dirty (has unsaved changes).
        // Use isDirty() instead of wasChanged() because wasChanged() depends on original
        // state sync timing, while isDirty() directly checks current vs original regardless
        // of sync order. Deliberately NOT using wasRecentlyCreated: Laravel sets that flag
        // true on insert and never resets it back to false on the same in-memory instance
        // across later save()/update() calls, so it stays stuck true forever — wrongly
        // treating every later unrelated update as "just created".
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
