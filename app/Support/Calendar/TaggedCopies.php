<?php

declare(strict_types=1);

namespace App\Support\Calendar;

use App\Jobs\SyncWorkItemCalendarEventJob;
use App\Models\Employee;
use App\Models\GoogleCalendarConnection;
use App\Models\WorkItem;
use App\Models\WorkItemCalendarCopy;
use Illuminate\Support\Collection;

/**
 * Who, besides the owner, gets a card in their Google Calendar, and keeping their
 * copies in step. Tagged Helper and FYI people qualify; the Reviewer does not, and
 * company-event cards are mirrored per attendee by EventController instead.
 */
final class TaggedCopies
{
    /** Pivot roles that earn a calendar copy. Null is the historical default (helper). */
    public const ROLES = ['helper', 'fyi', null];

    /** @return Collection<int, Employee> */
    public static function recipients(WorkItem $item): Collection
    {
        if ($item->company_event_id !== null) {
            return collect();
        }

        return $item->participants()->withoutGlobalScopes()->get()
            ->filter(fn (Employee $e) => $e->id !== $item->employee_id
                && in_array($e->pivot->role, self::ROLES, true))
            ->values();
    }

    /** Bring every tagged copy of this card in line with the card as it is now. */
    public static function sync(WorkItem $item): void
    {
        $recipients = CalendarMirror::syncable($item) ? self::recipients($item)->pluck('id') : collect();

        foreach ($recipients as $employeeId) {
            self::dispatchUpsert($item, $employeeId);
        }

        WorkItemCalendarCopy::where('work_item_id', $item->id)
            ->whereNotIn('employee_id', $recipients->all())
            ->get()
            ->each(fn (WorkItemCalendarCopy $copy) => self::remove($copy));
    }

    /** One person was just tagged. */
    public static function pushOne(WorkItem $item, int $employeeId): void
    {
        if (CalendarMirror::syncable($item) && self::recipients($item)->contains('id', $employeeId)) {
            self::dispatchUpsert($item, $employeeId);
        }
    }

    /** One person was just untagged. */
    public static function removeFor(int $workItemId, int $employeeId): void
    {
        $copy = WorkItemCalendarCopy::where('work_item_id', $workItemId)->where('employee_id', $employeeId)->first();
        if ($copy) {
            self::remove($copy);
        }
    }

    /** Take a copy out of the person's calendar, or just forget it if it never got there. */
    public static function remove(WorkItemCalendarCopy $copy): void
    {
        $userId = Employee::withoutGlobalScope('tenant')->whereKey($copy->employee_id)->value('user_id');
        if (! $copy->google_event_id || ! $userId) {
            $copy->delete();

            return;
        }

        SyncWorkItemCalendarEventJob::dispatch(
            tenantId: $copy->tenant_id,
            action: 'delete',
            workItemId: $copy->work_item_id,
            userId: $userId,
            googleEventId: $copy->google_event_id,
            recipientEmployeeId: $copy->employee_id,
        );
    }

    private static function dispatchUpsert(WorkItem $item, int $employeeId): void
    {
        // No live Google connection: no job to queue (the job checks again when it runs).
        $userId = Employee::withoutGlobalScope('tenant')->whereKey($employeeId)->value('user_id');
        if (! $userId || ! GoogleCalendarConnection::where('user_id', $userId)->whereNull('revoked_at')->exists()) {
            return;
        }

        SyncWorkItemCalendarEventJob::dispatch(
            tenantId: $item->tenant_id,
            action: 'upsert',
            workItemId: $item->id,
            recipientEmployeeId: $employeeId,
        );
    }
}
