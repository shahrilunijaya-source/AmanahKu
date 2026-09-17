<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Ports\CalendarPort;
use App\Ports\Data\CalendarEvent;
use App\Ports\PortResult;
use Carbon\CarbonImmutable;

/** Records every call; each push gets a fresh id and version. */
final class RecordingCalendarPort implements CalendarPort
{
    /** @var list<array{employee: int, event: CalendarEvent}> */
    public array $upserts = [];

    /** @var list<array{employee: int, id: string}> */
    public array $deletes = [];

    /** @var list<CalendarEvent> */
    public array $pending = [];

    public bool $fail = false;

    public function upsertEvent(Employee $for, CalendarEvent $event): PortResult
    {
        $this->upserts[] = ['employee' => $for->id, 'event' => $event];
        $n = count($this->upserts);

        return new PortResult(ok: ! $this->fail, externalId: $event->externalId ?? "evt-{$for->id}-{$n}", payload: ['version' => "v{$n}"], outboxId: $n);
    }

    public function deleteEvent(Employee $for, string $externalId): PortResult
    {
        $this->deletes[] = ['employee' => $for->id, 'id' => $externalId];

        return new PortResult(ok: true, externalId: $externalId, payload: [], outboxId: 0);
    }

    public function pullChanges(Employee $for, CarbonImmutable $since): PortResult
    {
        $changes = $this->pending;
        $this->pending = [];

        return new PortResult(ok: true, externalId: null, payload: $changes, outboxId: 0);
    }

    /** @return list<int> employee ids that received a push */
    public function pushedTo(): array
    {
        return array_column($this->upserts, 'employee');
    }
}
