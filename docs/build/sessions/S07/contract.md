# Session S07 contract: ports and stubs

Spec: `docs/build/contracts/ports.md` (no CR; the contract is the requirement). Tests: `tests/Acceptance/PortsTest.php`. Shapes not in the contract follow OPEN "QA / ports / shapes fixed by PortsTest".

## Files

- `config/ports.php`: `driver` map per port, `stub` default, overridable by env `PORT_CALENDAR_DRIVER`, `PORT_TRACK_DRIVER`, `PORT_MAIL_DRIVER`.
- `database/migrations/2026_09_12_100000_create_port_outbox.php`: table `port_outbox` exactly as the contract lists it.
- `app/Models/PortOutbox.php`: the row.
- `app/Ports/CalendarPort.php`, `TrackPort.php`, `MailPort.php`: the frozen interfaces.
- `app/Ports/PortResult.php`: readonly `{ok, externalId, payload, outboxId}`.
- `app/Ports/Data/CalendarEvent.php`, `TrackProject.php`, `MailMessage.php`: readonly value objects.
- `app/Ports/Outbox.php`: writes the row first, runs the adapter body, marks `sent` or `failed`, never lets an exception reach the caller.
- `app/Ports/Stub/StubCalendarPort.php`, `StubTrackPort.php`, `StubMailPort.php`: mark the row sent with `stub-<port>-<id>`, write nothing outside the app.
- `app/Ports/Adapters/GoogleCalendarAdapter.php`: scaffold over the existing `GoogleCalendarClient`, not bound.
- `app/Providers/PortsServiceProvider.php` + `bootstrap/providers.php`: binds each interface from `config('ports.driver.<port>')`; anything other than `stub` resolves to the stub during the run with a log line, so no real adapter can be switched on by env alone.
- `tests/Feature/PortsTest.php`: driver override handling and the Google scaffold's refusal paths.

## Schema

`port_outbox`: `id, tenant_id (nullable FK), port (20), method (40), subject_type, subject_id (nullable morph), payload json, status (pending|sent|failed|skipped) default pending, attempts smallint default 0, external_id nullable, error text nullable, sent_at nullable, timestamps`. Run on the dev DB with `lerd artisan migrate`.

## Verification per acceptance item (PortsTest)

1. Interfaces and value objects: reflection over the three interfaces and the readonly classes; `PortResult` named constructor.
2. Binding: `config('ports.driver.*') === 'stub'`; `app(CalendarPort::class)` etc. resolve under `App\Ports\Stub\`; the Google adapter class exists but is not what resolves; `PortsServiceProvider` exists.
3. Calendar calls: `upsertEvent`, `deleteEvent`, `pullChanges` each leave exactly one row with the contract columns, `sent`, `attempts` 1, `stub-calendar-<id>`, subject from `CalendarEvent::subject`.
4. Track calls: same for `pullProjects` (empty list), `pushComment`, `withdrawComment`.
5. Mail: row payload carries `to, subject, body_en, body_ms, kind`; `Mail`, `Notification`, `Http` fakes see nothing.
6. Never throws: `MailMessage` with no recipient gives `ok = false`, row `failed` with an error, no exception.
7. Grep of `app/Ports` (outside `Adapters/`) for `Http::`, `Mail::`, `Notification::`, curl, Guzzle, Google, Brevo.
8. The four always checks.

Browser: nothing user-facing changes. The QA grade can confirm the migration on the dev DB and that a tinker call to `app(MailPort::class)->send(...)` lands in `port_outbox` and not in Mailpit.
