<?php

namespace App\Ports\Stub;

use App\Models\Employee;
use App\Ports\CalendarPort;
use App\Ports\Data\CalendarEvent;
use App\Ports\Outbox;
use App\Ports\PortResult;
use Carbon\CarbonImmutable;

/** Records the intent in port_outbox and reaches no calendar. The only calendar driver enabled during the run. */
final class StubCalendarPort implements CalendarPort
{
    public function __construct(private Outbox $outbox) {}

    public function upsertEvent(Employee $for, CalendarEvent $event): PortResult
    {
        return $this->outbox->call('calendar', 'upsertEvent', $event->subject,
            ['for_employee_id' => $for->id] + $event->toPayload(),
            fn ($row) => ["stub-calendar-{$row->id}", []],
            $for->tenant_id);
    }

    public function deleteEvent(Employee $for, string $externalId): PortResult
    {
        return $this->outbox->call('calendar', 'deleteEvent', null,
            ['for_employee_id' => $for->id, 'external_id' => $externalId],
            fn ($row) => ["stub-calendar-{$row->id}", []],
            $for->tenant_id);
    }

    public function pullChanges(Employee $for, CarbonImmutable $since): PortResult
    {
        return $this->outbox->call('calendar', 'pullChanges', null,
            ['for_employee_id' => $for->id, 'since' => $since->toIso8601String()],
            fn ($row) => ["stub-calendar-{$row->id}", []],
            $for->tenant_id);
    }
}
