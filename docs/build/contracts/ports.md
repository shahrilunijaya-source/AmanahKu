# Contract: ports

Frozen by S00. S07 builds the interfaces, stubs and outbox; every session from S08 on codes against them. Nothing in the run calls Google, Track or a mail provider directly.

## What exists today

- Google Calendar: `app/Services/GoogleCalendarClient.php` (hand-rolled OAuth, `calendar.events` scope), `google_calendar_connections` table, `work_items.google_event_id`, `app/Jobs/SyncWorkItemCalendarEventJob.php`. One-way, app to Calendar, per-user token. This becomes the real `CalendarPort` adapter; it is not deleted and not called directly by feature code after S07.
- Mail: Laravel notifications (`AppNotificationMail`, `MemberInvited`, `TotTomorrowMail`, `WeeklyHrDigest`), queued on the `database` connection, Mailpit locally. Existing notification classes keep working. New outbound mail in this run goes through `MailPort`.
- Track: nothing. No client, no config, no table.
- Outbox: nothing.

## Interfaces (namespace `App\Ports`)

```php
interface CalendarPort {
    public function upsertEvent(Employee $for, CalendarEvent $event): PortResult;   // returns external id
    public function deleteEvent(Employee $for, string $externalId): PortResult;
    public function pullChanges(Employee $for, CarbonImmutable $since): PortResult;  // list<CalendarEvent>
}
interface TrackPort {
    public function pullProjects(): PortResult;                       // list<TrackProject>
    public function pushComment(string $trackRef, string $body, Employee $by): PortResult;
    public function withdrawComment(string $trackRef, string $commentRef): PortResult;
}
interface MailPort {
    public function send(MailMessage $message): PortResult;           // to, subject, body_en, body_ms, kind
}
```

`PortResult` = `{ok: bool, externalId: ?string, payload: array, outboxId: int}`. Value objects are plain readonly classes under `App\Ports\Data`. Bound in a `PortsServiceProvider`; `config('ports.driver')` per port, `stub` default, real adapters selected by env. Stub is the only driver enabled in this run.

## Outbox

Table `port_outbox`: `id, tenant_id, port, method, subject_type, subject_id, payload (json), status (pending|sent|failed|skipped), attempts, external_id, error, sent_at, created_at`. Every port call writes one row first, then the adapter runs. The stub adapter marks the row `sent` with a synthetic `external_id` (`stub-<port>-<id>`) and writes nothing outside the app. Acceptance tests for deferred items assert on this table.

## Rules

- No `Http::` call, no SDK, no `Mail::` outside `MailPort`, anywhere in files a session touches after S07. `/qa grade` greps for it.
- A port call never throws to the caller; failure is a `PortResult` with `ok = false` and an `error` on the outbox row.
- Real adapters (`GoogleCalendarAdapter` wrapping the existing client, `BrevoMailAdapter`, `TrackAdapter`) are written by Shazwan after the run, one per port, behind the same interface. The run may scaffold `GoogleCalendarAdapter` since the client exists, but leaves it unbound.
