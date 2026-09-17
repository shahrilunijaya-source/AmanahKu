<?php

declare(strict_types=1);

namespace App\Support\Calendar;

use App\Models\Employee;
use App\Models\WorkItem;
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
}
