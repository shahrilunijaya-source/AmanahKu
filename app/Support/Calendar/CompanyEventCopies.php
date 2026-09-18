<?php

declare(strict_types=1);

namespace App\Support\Calendar;

use App\Jobs\SyncCompanyEventCalendarCopyJob;
use App\Models\CompanyEvent;
use App\Models\CompanyEventCalendarCopy;
use App\Models\Employee;
use App\Models\GoogleCalendarConnection;
use App\Models\WorkItem;
use Illuminate\Support\Collection;

/**
 * Every upcoming company event goes into every connected employee's Google Calendar,
 * RSVP'd or not. An attendee already gets an entry through their event card (see
 * EventController::createEventCard/archiveEventCard, WorkItemCalendarCopy) so this
 * only ever pushes to non-attendees.
 */
final class CompanyEventCopies
{
    /** @return Collection<int, Employee> */
    public static function recipients(CompanyEvent $event): Collection
    {
        $attendeeIds = WorkItem::withoutGlobalScopes()
            ->where('company_event_id', $event->id)
            ->whereNull('archived_at')->whereNull('cancelled_at')
            ->pluck('employee_id');

        return Employee::withoutGlobalScope('tenant')
            ->where('tenant_id', $event->tenant_id)
            ->active()
            ->whereNotNull('user_id')
            ->whereNotIn('id', $attendeeIds)
            ->whereIn('user_id', GoogleCalendarConnection::whereNull('revoked_at')->pluck('user_id'))
            ->get();
    }

    /** Bring every copy of this event in line with the event as it is now. */
    public static function sync(CompanyEvent $event): void
    {
        $recipients = $event->isOver() ? collect() : self::recipients($event)->pluck('id');

        foreach ($recipients as $employeeId) {
            self::dispatchUpsert($event, (int) $employeeId);
        }

        CompanyEventCalendarCopy::where('company_event_id', $event->id)
            ->whereNotIn('employee_id', $recipients->all())
            ->get()
            ->each(fn (CompanyEventCalendarCopy $copy) => self::remove($copy));
    }

    /** One person just stopped being a recipient (newly tagged as attendee, or over). */
    public static function pushOne(CompanyEvent $event, int $employeeId): void
    {
        if (! $event->isOver() && self::recipients($event)->contains('id', $employeeId)) {
            self::dispatchUpsert($event, $employeeId);
        }
    }

    /** Take a copy out of the person's calendar, or just forget it if it never got there. */
    public static function remove(CompanyEventCalendarCopy $copy): void
    {
        if (! $copy->google_event_id) {
            $copy->delete();

            return;
        }

        SyncCompanyEventCalendarCopyJob::dispatch(
            tenantId: $copy->tenant_id,
            action: 'delete',
            companyEventId: $copy->company_event_id,
            employeeId: $copy->employee_id,
            googleEventId: $copy->google_event_id,
        );
    }

    /** Remove every copy of this event, before the event itself is deleted. */
    public static function removeAll(CompanyEvent $event): void
    {
        CompanyEventCalendarCopy::where('company_event_id', $event->id)->get()
            ->each(fn (CompanyEventCalendarCopy $copy) => self::remove($copy));
    }

    private static function dispatchUpsert(CompanyEvent $event, int $employeeId): void
    {
        $userId = Employee::withoutGlobalScope('tenant')->whereKey($employeeId)->value('user_id');
        if (! $userId || ! GoogleCalendarConnection::where('user_id', $userId)->whereNull('revoked_at')->exists()) {
            return;
        }

        SyncCompanyEventCalendarCopyJob::dispatch(
            tenantId: $event->tenant_id,
            action: 'upsert',
            companyEventId: $event->id,
            employeeId: $employeeId,
        );
    }
}
