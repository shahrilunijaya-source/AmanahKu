# Session S07 handoff: ports and stubs

## Delivered
- The three port interfaces `App\Ports\CalendarPort`, `TrackPort`, `MailPort` with the contract's signatures, `App\Ports\PortResult` and the readonly value objects `App\Ports\Data\{CalendarEvent, TrackProject, MailMessage}`, verified by PortsTest item 1.
- `App\Providers\PortsServiceProvider` (registered in `bootstrap/providers.php`) binds each interface from `config('ports.driver.<port>')`, `stub` by default (`config/ports.php`, env `PORT_CALENDAR_DRIVER`, `PORT_TRACK_DRIVER`, `PORT_MAIL_DRIVER`). A driver name that is not enabled logs a warning and still resolves to the stub, so no env change can switch a real adapter on during the run. Item 2 and `tests/Feature/PortsTest.php`.
- `port_outbox` and `App\Ports\Outbox`: every port call writes its row first (`pending`, tenant from the employee the call is for, else `CurrentTenant`, else the subject row), runs the adapter body, then marks the row `sent` with the external id and `sent_at`, or `failed` with the error. Nothing thrown inside reaches the caller. Items 3, 4, 5, 6.
- Stubs `App\Ports\Stub\{StubCalendarPort, StubTrackPort, StubMailPort}`: mark the row `sent` with `stub-<port>-<id>`, answer empty lists for `pullChanges` and `pullProjects`, send nothing (Mail, Notification and Http fakes all see nothing). The one stub failure is a mail with no recipient. Items 3 to 7.
- `App\Ports\Adapters\GoogleCalendarAdapter`: the real calendar adapter scaffolded over the existing `GoogleCalendarClient`, unbound. It fails closed (outbox `failed`) when Google is not configured or the person has no connection, and `pullChanges` fails as "deferred". Feature test only.

## Schema changes
- `port_outbox`: `id, tenant_id (nullable FK), port, method, subject_type/subject_id (nullable morph), payload json, status default pending, attempts default 0, external_id, error, sent_at, timestamps`, migration `2026_09_12_100000_create_port_outbox.php`, run on the dev DB.

## Contracts touched
- none

## Port calls stubbed
- none from feature code yet; this session builds the path. Sessions S08 onward call `app(MailPort::class)->send(...)`, `app(CalendarPort::class)->upsertEvent(...)`, `app(TrackPort::class)->...` and assert on `port_outbox`.

## Deferred
- Real adapters (`BrevoMailAdapter`, `TrackAdapter`, binding the Google one): after the run, by Shazwan, one entry each in `PortsServiceProvider::realAdapters()`.
- The existing `SyncWorkItemCalendarEventJob` still calls `GoogleCalendarClient` directly. The contract keeps it working and says feature code after S07 goes through the port; that job predates S07 and was not touched (one CR per session). Whoever next edits the calendar sync (CR-01, deferred) routes it through `CalendarPort`.

## OPEN, decided without Shazwan
- Config layout, stub names, value-object fields, the outbox subject and the stub's failure case: "QA / ports / shapes fixed by PortsTest".
- Tenant fallback order for the outbox row and the unbound-driver guard: "S07 / ports / tenant on the outbox row and the driver guard".

## Requested contract change (generator may not make it itself)
- none

## Traps for the next session
- `MailMessage::about` (not `subject`) is the model the mail concerns; `subject` is the mail subject line. `CalendarEvent::subject` is the model.
- `Outbox::call()` returns `outboxId = 0` only when the row itself could not be written (no tenant anywhere). Assert on `ok`, not on the id being non-zero, unless you set the tenant.
- The stub answers `ok = true` for every calendar or Track call, so a feature cannot use the result to learn whether a real calendar exists. Deferred features record intent and stop there, as RULES says.
- `PortOutbox` uses `BelongsToTenant`: reads are scoped to the current tenant, and a create with no tenant throws (caught inside `Outbox`). Use `withoutGlobalScopes()` for cross-tenant admin reads.
- Acceptance item 7 greps `app/Ports` (outside `Adapters/`) for `Http::`, `Mail::`, `Notification::`, curl, Guzzle, `Google\`, Brevo, including comments. Keep those words out of docblocks there.
- `vendor/bin/phpstan analyse` still reports the pre-existing errors outside this diff; the new files pass on their own.
