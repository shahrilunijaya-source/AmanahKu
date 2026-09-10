<?php

namespace App\Ports;

use App\Models\Employee;
use App\Ports\Data\CalendarEvent;
use Carbon\CarbonImmutable;

/** Frozen by docs/build/contracts/ports.md. One calendar per employee, app to calendar and back. */
interface CalendarPort
{
    /** Create or update the event in the employee's calendar; `externalId` is the calendar's id for it. */
    public function upsertEvent(Employee $for, CalendarEvent $event): PortResult;

    public function deleteEvent(Employee $for, string $externalId): PortResult;

    /** `payload` is a list<CalendarEvent> changed since the given moment. */
    public function pullChanges(Employee $for, CarbonImmutable $since): PortResult;
}
