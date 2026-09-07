<?php

namespace App\Ports\Adapters;

use App\Models\Employee;
use App\Models\GoogleCalendarConnection;
use App\Models\WorkItem;
use App\Ports\CalendarPort;
use App\Ports\Data\CalendarEvent;
use App\Ports\Outbox;
use App\Ports\PortResult;
use App\Services\GoogleCalendarClient;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * The real calendar adapter, scaffolded over the hand-rolled client that already exists
 * (docs/build/contracts/ports.md, "What exists today"). NOT bound during the build run:
 * PortsServiceProvider resolves every calendar driver to the stub. Shazwan wires it up
 * after the run by setting PORT_CALENDAR_DRIVER=google and lifting the guard in the provider.
 *
 * The client only knows how to mirror a work item's due date as an all-day event, so
 * that is all this scaffold carries; other subjects and pull are left as failed results.
 */
final class GoogleCalendarAdapter implements CalendarPort
{
    public function __construct(private Outbox $outbox, private GoogleCalendarClient $client) {}

    public function upsertEvent(Employee $for, CalendarEvent $event): PortResult
    {
        return $this->outbox->call('calendar', 'upsertEvent', $event->subject,
            ['for_employee_id' => $for->id] + $event->toPayload(),
            function () use ($for, $event) {
                $item = $event->subject;
                if (! $item instanceof WorkItem) {
                    throw new RuntimeException('The Google adapter only mirrors work items today.');
                }

                return [$this->client->createOrUpdateEvent($item, $this->connectionFor($for)), []];
            },
            $for->tenant_id);
    }

    public function deleteEvent(Employee $for, string $externalId): PortResult
    {
        return $this->outbox->call('calendar', 'deleteEvent', null,
            ['for_employee_id' => $for->id, 'external_id' => $externalId],
            function () use ($for, $externalId) {
                $this->client->deleteEvent($externalId, $this->connectionFor($for));

                return [$externalId, []];
            },
            $for->tenant_id);
    }

    public function pullChanges(Employee $for, CarbonImmutable $since): PortResult
    {
        return $this->outbox->call('calendar', 'pullChanges', null,
            ['for_employee_id' => $for->id, 'since' => $since->toIso8601String()],
            fn () => throw new RuntimeException('Pulling changes from Google Calendar is not built yet (CR-01, deferred).'),
            $for->tenant_id);
    }

    private function connectionFor(Employee $for): GoogleCalendarConnection
    {
        if (! $this->client->configured()) {
            throw new RuntimeException('Google Calendar is not configured on this install.');
        }

        $connection = $for->user_id ? GoogleCalendarConnection::where('user_id', $for->user_id)->first() : null;
        if ($connection === null) {
            throw new RuntimeException("{$for->name} has not connected a Google Calendar.");
        }

        return $connection;
    }
}
