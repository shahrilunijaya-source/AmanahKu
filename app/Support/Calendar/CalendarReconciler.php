<?php

declare(strict_types=1);

namespace App\Support\Calendar;

use App\Jobs\SyncWorkItemCalendarEventJob;
use App\Models\AppNotification;
use App\Models\Employee;
use App\Models\WorkItem;
use App\Models\WorkItemComment;
use App\Ports\Data\CalendarEvent;
use App\Support\AutoDone;

/**
 * CR-01, the calendar-to-Amanahku leg. Applies one employee's pulled calendar changes
 * to their cards under the Date & Calendar Rules:
 *
 *  - echo of our own push (same version stamp): ignored, no ping-pong (rule 4);
 *  - Event card moved in the calendar: due date follows, history line written (rule 2/3);
 *  - Event card deleted or cancelled in the calendar: card Cancelled (calendar), never
 *    deleted, owner told (rules 3, 6);
 *  - work item moved in the calendar: due date stays locked, the entry is pushed back
 *    to the locked date and the card history says so (rule 4 of the dates contract);
 *  - work item entry deleted in the calendar: re-created on this pass, card untouched;
 *  - unknown event on the Amanahku calendar: becomes an Event card the person can
 *    convert (rule 1); nothing from any other calendar ever gets here.
 *
 * Only writes that are real edits go through the model (audit rows, observers); the
 * version stamp is a query-builder update so it never counts as an edit.
 */
final class CalendarReconciler
{
    /** Two edits inside this window count as a conflict (rule 4): keep Amanahku's, tell the owner. */
    public const CONFLICT_WINDOW_SECONDS = 60;

    /** @param  list<CalendarEvent>  $changes */
    public function reconcile(Employee $for, array $changes): void
    {
        foreach ($changes as $change) {
            if ($change->externalId === null) {
                continue;
            }

            $card = WorkItem::withoutGlobalScopes()
                ->where('employee_id', $for->id)
                ->where('google_event_id', $change->externalId)
                ->first();

            if ($card === null) {
                $this->import($for, $change);

                continue;
            }

            if ($change->version !== null && $change->version === $card->calendar_version) {
                continue; // our own push coming back
            }

            $change->cancelled ? $this->cancelled($card, $for) : $this->moved($card, $for, $change);
        }
    }

    private function cancelled(WorkItem $card, Employee $for): void
    {
        if ($card->type === 'event') {
            if ($card->cancelled_at === null) {
                AutoDone::cancelled($card, 'Cancelled (calendar)');
                AppNotification::send($for->user_id, "Cancelled in Google Calendar: {$card->title}",
                    'The calendar event was deleted or cancelled, so the T.A.A. Event is marked Cancelled (calendar). The card is kept.',
                    route('work.show', $card));
            }

            return;
        }

        // A work item is only visible in the calendar; losing the entry changes nothing here.
        $this->trail($card, 'Calendar entry was deleted in Google Calendar; restored, due date unchanged');
        WorkItem::withoutGlobalScopes()->where('id', $card->id)->update(['google_event_id' => null, 'calendar_version' => null]);
        $this->repush($card);
    }

    private function moved(WorkItem $card, Employee $for, CalendarEvent $change): void
    {
        $newDue = $change->startsAt->setTimezone(config('app.timezone'))->toDateString();
        if ($card->due_at?->toDateString() === $newDue) {
            $this->stamp($card, $change->version);

            return;
        }

        if ($card->type !== 'event') {
            $this->trail($card, "Calendar entry moved to {$change->startsAt->format('j M Y')}; due date stays {$card->due_at->format('j M Y')}, entry restored");
            $this->repush($card);

            return;
        }

        if ($card->updated_at && $card->updated_at->diffInSeconds(now(), true) <= self::CONFLICT_WINDOW_SECONDS) {
            $this->trail($card, "Conflict: moved to {$change->startsAt->format('j M Y')} in Google Calendar while being edited here; Amanahku's date kept, please resolve");
            AppNotification::send($for->user_id, "Calendar conflict on {$card->title}",
                'The event was moved in Google Calendar and in Amanahku within a minute of each other. Amanahku kept its date; check the card.',
                route('work.show', $card));
            $this->repush($card);

            return;
        }

        $old = $card->due_at?->format('j M Y') ?? 'no date';
        $card->due_at = $newDue;
        $card->save();
        $this->stamp($card, $change->version);
        $this->trail($card, "Rescheduled from {$old} to {$change->startsAt->format('j M Y')} via Google Calendar");
    }

    /** A new event on the Amanahku calendar: an Event card the person can convert to a Task/Assignment. */
    private function import(Employee $for, CalendarEvent $change): void
    {
        if ($change->cancelled) {
            return;
        }

        $card = $for->workItems()->create([
            'tenant_id' => $for->tenant_id,
            'title' => mb_substr($change->title, 0, 200),
            'type' => 'event',
            'priority' => 'medium',
            'status' => 'todo',
            'progress' => 0,
            'due_at' => $change->startsAt->setTimezone(config('app.timezone'))->toDateString(),
            'description' => $change->description,
            'google_event_id' => $change->externalId,
            'calendar_version' => $change->version,
        ]);
        $this->trail($card, 'Imported from Google Calendar');
    }

    private function repush(WorkItem $card): void
    {
        SyncWorkItemCalendarEventJob::dispatch(tenantId: $card->tenant_id, action: 'upsert', workItemId: $card->id);
    }

    private function stamp(WorkItem $card, ?string $version): void
    {
        WorkItem::withoutGlobalScopes()->where('id', $card->id)->update(['calendar_version' => $version]);
    }

    private function trail(WorkItem $card, string $line): void
    {
        WorkItemComment::create(['tenant_id' => $card->tenant_id, 'work_item_id' => $card->id, 'employee_id' => null, 'body' => $line]);
    }
}
