<?php

namespace App\Ports\Adapters;

use App\Models\Employee;
use App\Models\GoogleCalendarConnection;
use App\Ports\CalendarPort;
use App\Ports\Data\CalendarEvent;
use App\Ports\Outbox;
use App\Ports\PortResult;
use App\Services\GoogleCalendarClient;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * The real calendar adapter (CR-01) over the hand-rolled client. Selected with
 * PORT_CALENDAR_DRIVER=google; the stub stays the default. Every call still writes
 * its outbox row first, so the Sync issues list and the audit trail read the same
 * table whichever driver is on.
 */
final class GoogleCalendarAdapter implements CalendarPort
{
    public function __construct(private Outbox $outbox, private GoogleCalendarClient $client) {}

    public function upsertEvent(Employee $for, CalendarEvent $event): PortResult
    {
        return $this->outbox->call('calendar', 'upsertEvent', $event->subject,
            ['for_employee_id' => $for->id] + $event->toPayload(),
            function () use ($for, $event) {
                [$id, $version] = $this->client->upsertEvent($event, $this->connectionFor($for));

                return [$id, ['version' => $version]];
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
            function () use ($for, $since) {
                $changes = $this->client->listChanges($this->connectionFor($for), $since);

                return [null, $changes];
            },
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
