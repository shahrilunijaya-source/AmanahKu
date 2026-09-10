# QA grade: S07 ports and stubs

**Result: PASS.** Zero FAIL. Graded on the dev DB (commit 7446034c) with tinker for the
port calls, `mysql` to read `port_outbox`, the Mailpit API to prove nothing was sent, and
Playwright on the worktree vhost for the standing checks. No screen changed in this
session, so the browser part is the dashboard only (`grade-ports-dashboard.png`).

## Acceptance items (contract paragraphs, as PortsTest numbers them)

| # | Item | Result |
|---|------|--------|
| 1 | `CalendarPort`, `TrackPort`, `MailPort` with the contract's methods returning `PortResult`; readonly value objects | **PASS** |
| 2 | Every port bound to the stub by default; Google adapter present but not what resolves; `PortsServiceProvider` registered | **PASS** |
| 3 | Calendar call writes one outbox row first, stub marks it `sent` with `stub-calendar-<id>` | **PASS** |
| 4 | Track calls write rows, `pullProjects` answers an empty list | **PASS** |
| 5 | Mail goes into the outbox with `to, subject, body_en, body_ms, kind`; nothing leaves the app | **PASS** |
| 6 | A port call never throws; failure is `ok = false` with the error on the row | **PASS** |
| 7 | Stub side of `app/Ports` has no outbound call | **PASS** |

Live detail on the dev DB. With tenant 1 set, four tinker calls in a row:

| Call | Result | Outbox row |
|------|--------|-----------|
| `MailPort::send` to one address, kind `grade_probe` | ok, `stub-mail-1` | 1: mail / send / sent / attempts 1 / sent_at set |
| `CalendarPort::upsertEvent` for Shazwan, subject card 310 | ok, `stub-calendar-2` | 2: calendar / upsertEvent / subject `App\Models\WorkItem` 310 / sent |
| `TrackPort::pushComment` TRK-1 by Shazwan | ok, `stub-track-3` | 3: track / pushComment / sent |
| `MailPort::send` with no recipient | ok false, no exception | 4: mail / send / **failed** / error "No recipient: the message names nobody to send to." / sent_at null |

`app(CalendarPort::class)` resolved to `App\Ports\Stub\StubCalendarPort`. Mailpit's newest
messages after the calls are the pre-existing task notifications from the CR-18 grade; no
"Grade probe" mail arrived. `tests/Feature/PortsTest.php` covers the unbound-driver guard
(`PORT_CALENDAR_DRIVER=google` still resolves the stub) and the Google scaffold failing
closed without an HTTP call.

## Every-session checks

| Check | Result |
|-------|--------|
| `php artisan test --compact tests/Acceptance/PortsTest.php` | PASS, 8 tests, 123 assertions |
| Due date change via API (`PATCH /app/board/310 {due_at}` as Shazwan) | PASS, 422 "Due dates are locked after the first save" |
| Audit-log row edit and delete via the model | PASS, both throw "audit_logs rows are append-only" (re-run this session inside `AlwaysChecks`, and by tinker during the CR-18 grade an hour earlier on the same build) |
| Dashboard as Shazwan on quiet 2026-09-09 | PASS, left summary, clock, tasks, leave, style; right calendar, notices, flowers, claims, work; no band; same as baseline and S06 |
| Keep it plain | PASS, greeting "Good morning, Shazwan.", cards unchanged, no new text anywhere (no screen changed) |
| Diff grep for outbound calls in 7446034c (Http::, guzzle, googleapis, brevo, smtp, Mail::, Notification::, curl) | PASS, no added line outside `app/Ports/Adapters/GoogleCalendarAdapter.php`, which only calls the pre-existing client and is unbound |
| OPEN entries name alternatives and reversal cost | PASS, "QA / ports" and "S07 / ports" both do |
| Protected files untouched | PASS, `git show --stat 7446034c` touches none of CLAUDE.md, RULES.md, contracts, tests/Acceptance |
| Full suite | PASS, 2715 tests, 2710 passed, 5 skipped, 9 incomplete |

## Notes for the next session

- Dev DB: `port_outbox` rows 1 to 4 are the grade probes above; leave them, they are the
  first proof the outbox works. Dev clock reset, plain pref off.
- `SyncWorkItemCalendarEventJob` still talks to `GoogleCalendarClient` directly. It predates
  the ports and the contract keeps it working; not a FAIL for S07, but the next session
  that touches calendar sync must route it through `CalendarPort`.
