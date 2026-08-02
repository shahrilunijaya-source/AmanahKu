# Observability without host access — Phase 0, 1 and 2

Production runs on devops-owned infrastructure. We have no SSH, no database shell,
no cron visibility. The only door we own is the super-admin web login. This plan puts
back what SSH used to give, using nothing but a normal code deploy.

- **Phase 0** fixes two pre-existing defects that Phase 2 would otherwise turn into a breach.
- **Phase 1** makes every error identifiable and every log line traceable.
- **Phase 2** puts a read-only diagnostics console behind the super-admin gate.

Phase 3 (external error tracking) stays out of scope, but note that the privacy decision
it was deferred for is **also required by Phase 2** — see [Privacy decision](#privacy-decision).

This document incorporates an adversarial security review. Findings are marked
`[SEC-n]` where they changed the design.

---

## Verified environment facts

Established before writing, do not re-derive:

- **Docroot is `public/` on both staging and production.** Probed 2026-07-31:
  `/composer.json` and `/deploy.sh` return 404 on both hosts. Those are ordinary
  non-dotfile files at the repo root; if the vhost served the repo root they would be
  200. So `.git`, `.env` and `storage/logs` are not reachable over HTTP. The 403s seen
  on `/.env` (prod) and `/.git/config` (both) are deny rules matching the path pattern,
  not evidence of a served file.
  **Consequence:** the super-admin gate is the *only* path to log data. That makes
  Phase 0 mandatory rather than advisable.
- Queue connection is `database`, so `failed_jobs` is a real readable table.
- The application contains **zero** shell-execution calls (`exec`, `shell_exec`,
  `proc_open`, `passthru`, `system`) in `app/`. Do not introduce the first one.
- There are only two explicit `Log::` call sites in the whole application, both in
  `app/Services/Ai/ClaudeAiProvider.php`. Everything else in `storage/logs` is a
  framework-reported exception. This is why keyword-based redaction is the wrong shape:
  there is no application-authored message to sanitize, only exception messages and traces.

---

## Phase 0 — Prerequisites

Neither item is part of the observability feature. Both are pre-existing defects that
Phase 2 would escalate from "latent" to "load-bearing". Phase 2 does not start until
both are merged and verified on staging.

### 0.1 `EnforceTwoFactor` does not run on the super-admin console `[SEC-C1]`

**The defect.** `app/Http/Middleware/EnforceTwoFactor.php:33-38` returns early when
there is no active workspace, and `activeTenant()` (`:66-71`) reads only
`session('current_tenant')`. The super-admin route group deliberately sits **outside**
the tenant group (`routes/web.php:111-113`), so a super-admin who logs in and goes
straight to `/admin/*` never has `current_tenant` set. Every super-admin request is
therefore single-factor today.

Compounding, verified:
- No `throttle` middleware on the `super.admin` group (`routes/web.php:113-125`), while
  the tenant group uses `throttle:20,1` in several places.
- `password.confirm` is not used anywhere in the application. The only occurrence is a
  comment in `config/fortify.php:176`.

**Why it blocks Phase 2.** Today that credential buys company provisioning and feature
flags. After Phase 2 it buys 30 days of production logs containing salaries and IC
numbers, the full failed-job exception corpus, and a job-retry primitive. Roughly an
order of magnitude more blast radius, reachable with a phished password and no OTP.

**Fix.** Enforce unconditionally when `$user->isSuperAdmin()`, independent of active
tenant and independent of the per-workspace `security.2fa` feature flag. A super-admin
is a cross-tenant principal; no single workspace's flag should govern them.

**Care required.** The allowlist at `EnforceTwoFactor::ALLOWED` must keep letting a
non-enrolled super-admin reach the enrolment screen, or the fix locks the only
production account out of production. The enrolment path for a tenant-less super-admin
needs to be established before this ships. **Test the lockout path on staging first,
with a second working super-admin account available.**

### 0.2 `QueryException` writes raw HR data into the log file `[SEC-C2]`

**The defect.** `vendor/laravel/framework/src/Illuminate/Database/QueryException.php:87`
interpolates bindings into the exception message:

```php
$previous->getMessage().' (Connection: '.$connectionName.$details.', SQL: '
    .Str::replaceArray('?', $bindings, $sql).')'
```

and `formatConnectionDetails()` (`:95-119`) appends host, port and database name.

Any DB-level failure on a write — data-too-long, duplicate key, deadlock, connection
dropped mid-statement — logs the literal bound values. On this schema that means
salaries, IC numbers, addresses, bank account numbers, dates of birth, and medical
claim free text, rendered as a plain `insert into … values (…)` string.

This is not hypothetical: seven scheduled commands wrap their entire per-tenant body in
`catch (\Throwable $e) { report($e); }` (`app/Console/Commands/AccrueLeave.php:67`,
`AttendanceReminder.php:76`, `CarryForwardLeave.php:72`, `TimesheetReminder.php:58`,
`WeeklyHrDigest.php:70`, `ArchiveDepartedStaff.php:103`, `TotReminder.php:50`), and
`attendance:remind` runs every fifteen minutes across every tenant, so deadlocks are a
routine event rather than an exotic one.

Two further leak paths in the same family:
- PHP's `getTraceAsString()` renders string arguments inline, truncated at 15
  characters. Most staff IDs and IC prefixes fit in 15 characters.
- `app/Services/Ai/ClaudeAiProvider.php:47` logs `$e->getMessage()` from an HTTP client
  exception, whose message embeds the response body. The system prompt built at `:59-69`
  is `'Workforce facts: '.json_encode($context)` — real employee data.

**Why viewer-side redaction cannot fix this.** The spec's original redactor matched
`email | IC pattern | password | token | secret | authorization`. Salary, address, bank
account and medical text match none of those. More fundamentally, redacting at render
time leaves the plaintext on disk, where it is also in backups and in whatever devops
copies around during an incident.

**Fix — suppress at the point of logging, not at the point of reading.** Register an
exception mapper in `bootstrap/app.php` that rewrites `QueryException` before Laravel
reports it, to something like:

```
SQLSTATE[23000] on `employees` (bindings and connection details suppressed)
```

Keep SQLSTATE, keep the table name, keep the exception class, keep the trace. Drop the
interpolated SQL and the connection detail suffix. That preserves everything actually
needed to diagnose a constraint violation and removes everything that constitutes a
disclosure.

**Retention gap this exposes `[SEC-omission-6]`.** `LOG_DAILY_DAYS=30` prunes on write,
and Phase 2 deliberately ships no delete button. If PII does land in a log file, there
is currently no way to remove it: no SSH, no delete button, and the daily channel only
prunes as it rotates. Decide and write down who can purge a log file and how, before
Phase 2 ships. An artisan command devops can run is an acceptable answer; "nobody can"
is not.

### Phase 0 tests

| Case | Assert |
|---|---|
| Super-admin, 2FA not enrolled, no `current_tenant` in session, GET `/admin/companies` | redirected to enrolment, not 200 |
| Super-admin, 2FA not enrolled, GET the enrolment screen | 200 (not a redirect loop) |
| Super-admin, 2FA enrolled and confirmed, GET `/admin/companies` | 200 |
| Ordinary tenant user in a workspace with `security.2fa` off | unchanged behaviour, 200 |
| `QueryException` raised by an insert with bindings `['900101-14-1234', 8500]` | the logged message contains neither `900101-14-1234` nor `8500`, and does contain the SQLSTATE and the table name |
| Same | the logged message does not contain the DB host or database name |

---

## Phase 1 — Trace ID and error reference

### Goal

A user reports "it broke". They read a short code off the error page. We paste that code
into the Phase 2 log viewer and land on the exact request, with the tenant, the user,
and every log line that request wrote.

### 1.1 `AssignTraceId` middleware

New file: `app/Http/Middleware/AssignTraceId.php`

```php
public function handle(Request $request, Closure $next): Response
{
    Context::add('trace_id', (string) Str::uuid());

    $response = $next($request);

    if ($response->getStatusCode() >= 400) {
        $response->headers->set('X-Trace-Id', context('trace_id'));
    }

    return $response;
}
```

Why `Context` and not `Log::withContext`: Laravel 13's `Context` is captured when a job
is dispatched and rehydrated inside the worker, so a queued mail that fails carries the
trace id of the web request that queued it. `Log::withContext` does not cross that boundary.

**Two constraints, written here so they are not optimised away later `[SEC-L1]`:**

1. `Str::uuid()` is v4 — no timestamp, no node id, not enumerable, not correlatable to a
   session. **Never** switch to `Str::orderedUuid()` or `Str::uuid7()`; both embed a
   timestamp and leak request-rate information to an unauthenticated user. Never derive
   the id from the session id or the user id.
2. The header is emitted on 4xx/5xx only `[SEC-L2]`. Successful responses do not need it
   and it should not be handed to every intermediary on every request.

**Dropped from the original spec: `Context::add('ip', …)` `[SEC-L5]`.** Adding client IP
to every log record is a new personal-data collection decision with a 30-day retention
tail, and it was not what anyone asked for. If it is wanted later it goes through the
same sign-off as Phase 3, and the IP joins the redaction list.

**Registration.** In `bootstrap/app.php`, prepend to both the `web` and `api` groups, so
it runs before `SetLocale` and `ResolveTenant`:

```php
$middleware->web(prepend: [AssignTraceId::class]);
$middleware->api(prepend: [AssignTraceId::class]);
```

Note this cannot run before `PreventRequestsDuringMaintenance`, which lives in the global
stack ahead of every route group. That is why the 503 page carries no reference (see 1.3).

### 1.2 Tenant and user in exception context

`bootstrap/app.php`, inside the existing `withExceptions` closure:

```php
$exceptions->context(function () {
    try {
        return array_filter([
            'trace_id' => context('trace_id'),
            'tenant'   => app(CurrentTenant::class)->get()?->slug,
            'user_id'  => auth()->id(),
            'route'    => request()?->route()?->getName(),
        ]);
    } catch (\Throwable) {
        return [];
    }
});
```

Two corrections against the original draft, both verified `[SEC-H3]`:

**(a) The binding key.** There is no `'tenant'` string binding. `ResolveTenant` writes to
a class-keyed singleton: `app(CurrentTenant::class)->set($tenant)`
(`app/Http/Middleware/ResolveTenant.php:29`), registered at
`app/Providers/AppServiceProvider.php:27`. The original draft's `app()->bound('tenant')`
is always false, so the tenant would silently always be absent — and nobody would notice
until the first cross-tenant investigation. Also note `CurrentTenant` has no `slug`
property; it is `->get()?->slug`.

**(b) The try/catch is mandatory, not defensive padding.** Laravel wraps its *own* context
builder in a try/catch (`Foundation/Exceptions/Handler.php:600-609`) but does **not** wrap
custom callbacks (`:586-591`, called from `buildExceptionContext()` at `:555`, invoked from
`report()` at `:411`). A throw inside this closure therefore escapes `report()`.

Concretely: the natural non-null-safe version (`->get()->slug`) throws on every exception
raised before a tenant is resolved — login-page 500s, `tenant.select` errors, every queued
job, every scheduled command. Inside the seven scheduled commands that throw escapes the
`catch (\Throwable $e) { report($e); }` block itself, aborting the whole per-tenant loop.
That destroys exactly the isolation those blocks were written to provide, loses the
original exception, and would stop leave accrual for every tenant after the first failure.

**Open item, lower confidence.** `CurrentTenant` is registered as a plain `singleton`, not
`scoped`, so it is not reset between queue jobs in a long-lived `queue:work` process.
`AccrueLeave` resets it with `set(null)` only on the success path. So in a worker, the
context can report the *wrong* tenant slug. Before Phase 2 relies on that field for
cross-tenant investigation, either re-register `CurrentTenant` as `scoped` or read the
tenant from `Context` in queue contexts. Not audited across all seven commands.

### 1.3 Error pages

New: `resources/views/errors/500.blade.php` and `resources/views/errors/419.blade.php`.
Neither exists today, so Laravel serves the framework default. 419 (session expired) is
the single most common confusing failure for real users.

Match the visual language of the two error views already present
(`company-suspended.blade.php`, `workspace-access-removed.blade.php`).

Content: a plain apology, and

```blade
@if ($ref = context('trace_id'))
    Error reference: {{ $ref }}
@endif
```

with a copy button. Nothing else. No stack trace, no exception message, no route name.
`APP_DEBUG=false` in production, but the view must stay safe if someone flips it.

**No 503 page `[SEC-M1]`.** The original draft proposed one carrying the same reference.
`deploy.sh:44` runs `php artisan down --render="errors::503"`, and `errors::503` resolves
to the application's view rather than the framework's (`RegisterErrorViewPaths` puts
`resources/views/errors` ahead of the framework path). That render happens once, in a CLI
process, with no request and an empty `Context`, and the output is cached to
`storage/framework/down` for the whole maintenance window. Every user would see either a
blank reference or — worse — the same reference, which actively misleads during an incident.

If a 503 page is wanted, it carries a timestamp and a support contact, never a trace
reference.

### 1.4 Production log channel

No code change. This is the one item in Phase 1 that needs devops. Give them this block:

```
LOG_CHANNEL=stack
LOG_STACK=daily
LOG_LEVEL=warning
LOG_DAILY_DAYS=30
```

`.env.production.example:59-60` currently sets `LOG_CHANNEL=stack` with `LOG_LEVEL=error`,
and `config/logging.php:57` defaults `LOG_STACK` to `single` — so today it resolves to one
unbounded file we cannot rotate without SSH. `daily` fixes that and matches how the Phase 2
viewer paginates.

**`warning` is a floor, not a default `[SEC-M4]`.** The original draft suggested dropping to
`info` "temporarily when hunting a bug". Do not. On this application:

- Laravel appends `Context` to every record, so at `info` and below every line carries the
  full context payload, for 30 days, readable in a browser.
- The standard query-logging snippet (`DB::listen(fn ($q) => Log::debug($q->sql, $q->bindings))`)
  dumps the entire HR dataset at `debug`. Nothing prevents someone adding it "temporarily"
  once a log viewer makes reading easy.
- `attendance:remind` runs every fifteen minutes across all tenants, so at `info` the
  viewer's tail window covers minutes rather than hours — the change destroys the
  usefulness of the tool it was made for.

When one specific class is too quiet, raise that class alone with
`$exceptions->level(SomeException::class, LogLevel::Info)`. If a global `info` is ever
genuinely required, it is a time-boxed change with a recorded decision, not a casual
`.env` edit.

### Phase 1 tests

`tests/Feature/TraceIdTest.php`

| Case | Assert |
|---|---|
| Request returning 500 | `X-Trace-Id` header present, valid UUID **v4** |
| Request returning 200 | no `X-Trace-Id` header |
| Two requests | different trace ids |
| Log written during a request | log line contains the same trace id as the header |
| Queued job dispatched in a request | job's log line carries the dispatching request's trace id |
| Unhandled exception inside a resolved tenant | logged context contains the correct **tenant slug** — guards `[SEC-H3(a)]` |
| Unhandled exception with no tenant resolved | context builder returns without throwing, original exception still reaches the log — guards `[SEC-H3(b)]` |
| Context builder made to throw deliberately | original exception still reaches the log |
| 500 page with `APP_DEBUG=false` | body contains the trace id, contains no exception message and no file path |

The last two rows go in first.

---

## Phase 2 — Super-admin diagnostics console

Blocked on Phase 0. Do not start it earlier.

### Goal

Answer, from a browser, the four questions that currently require devops:

1. What did the app log around the time it broke?
2. Are queued jobs failing?
3. Did the scheduled commands actually run?
4. Is the environment configured the way we think it is?

### 2.1 Placement and gate hardening `[SEC-C1]`

Extends the existing block in `routes/web.php`, inside `Route::middleware('super.admin')
->prefix('admin')->name('superadmin.')`. Reusing the gate is right for *authorization* —
a second gate is a second thing to misconfigure. It is not sufficient for
*authentication strength*, hence Phase 0.1 plus everything below.

```php
Route::prefix('diagnostics')->name('diagnostics.')
    ->middleware(['password.confirm', 'throttle:30,1'])
    ->group(function () {
        Route::get('/', [DiagnosticsController::class, 'index'])->name('index');
        Route::get('/logs', [DiagnosticsController::class, 'logs'])->name('logs');
        Route::get('/jobs', [DiagnosticsController::class, 'jobs'])->name('jobs');
        Route::post('/jobs/{uuid}/retry', [DiagnosticsController::class, 'retryJob'])
            ->whereUuid('uuid')->name('jobs.retry');
        Route::get('/schedule', [DiagnosticsController::class, 'schedule'])->name('schedule');
    });
```

`password.confirm` covers the whole group, not just the write route. Reading 30 days of
production logs deserves the same re-authentication as changing something.

**Kill switch.** `abort_unless(config('amanahku.diagnostics_enabled'), 404)` in the
controller constructor, backed by an env var. On a host where we cannot deploy, an env
flag devops can flip is the only way to turn this off at 2am.

**Audit.** Write an audit row on every screen view and on every retry. Note that
`AuditLog::record()` fills `tenant_id` from `CurrentTenant` via `BelongsToTenant`, which
**fails closed with no tenant** (`app/Models/Concerns/BelongsToTenant.php:36-45`), so the
convenience helper throws inside a tenant-less super-admin request. Use the explicit
`AuditLog::create([...])` form the SuperAdmin controllers already use
(`app/Http/Controllers/SuperAdmin/CompanyController.php:175`, `:250`, `:277`, `:294`, `:371`).

**Caching.** Every diagnostics response sends `Cache-Control: no-store, private`
`[SEC-L3]`, so log content does not sit in the browser disk cache or a corporate proxy.

Screens swap only their own region on navigation, per the project's no-full-page-reload
rule: each tab fetches a Blade partial rather than reloading the console shell.

### 2.2 Log viewer

New: `app/Http/Controllers/SuperAdmin/DiagnosticsController.php`, `app/Support/LogReader.php`

**Day selection — no filename ever crosses the boundary `[SEC-H2]`.**

```php
$day = $request->query('day', now()->toDateString());
abort_unless(preg_match('/^\d{4}-\d{2}-\d{2}$/', $day), 404);

$path = storage_path("logs/laravel-{$day}.log");
abort_unless(in_array($path, glob(storage_path('logs/laravel-*.log')), true), 404);
abort_if(is_link($path), 404);
```

The original draft said only "pick a day" and left the parameter shape unstated. A
filename parameter concatenated onto `storage_path('logs/')` gives `?file=../../.env`,
which reads APP_KEY, the DB password, the mail password and `ANTHROPIC_API_KEY` through a
screen whose stated premise is "no env dump" — and `?file=../../storage/app/backups/<dump>.sql`
reads the mysqldump `deploy.sh:59-60` writes. Validate the shape, rebuild the path, then
assert membership in the glob. All three steps, not one.

**Reading — bounded at every stage `[SEC-M5]`.**

- Read backwards from the end of the file, capped at 2 MB. Never `file_get_contents` a log
  file; one fatal loop produces a 400 MB log and that call OOMs the request.
- Split on the timestamp prefix `[YYYY-MM-DD HH:MM:SS]`, so multi-line stack traces stay
  attached to their entry. Check `preg_last_error() === PREG_NO_ERROR` after the split and
  fall back to a plain `strpos` scanner on failure. On `pcre.backtrack_limit` (default
  1,000,000, well under a 2 MB subject) `preg_split` returns `false`, not a partial array,
  and `foreach (false)` is a `TypeError` in PHP 8.5 — the incident tool would 500 exactly
  when it is needed.
- Cap each rendered entry at 8 KB with an explicit `(truncated)` marker appended **after**
  escaping. A single entry can otherwise be most of the 2 MB.
- Cap total rendered bytes per response.

**Rendering — deny by default `[SEC-C2, SEC-H1]`.**

Per entry, render: timestamp, level, channel, exception class, file, line, and the
`trace_id` / `tenant` / `route` context keys. The exception **message** is the leaky field
(Phase 0.2 removes the worst of it, but not all of it) — it sits behind an explicit
per-entry *reveal* click, and revealing writes an audit row.

Escaping is a hard requirement, stated here because the original draft left it implicit
and pointed an implementer straight at `{!! nl2br(e($line)) !!}`:

> Log content is rendered with `{{ }}` only, inside `<pre>`. Never `{!! !!}`, never
> `nl2br`, never `Str::markdown`, never an Alpine `x-html` binding.

Log content is attacker-influenced. An employee submits a claim containing
`<img src=x onerror=…>`; a DB error on that insert logs the payload; a super-admin opens
the viewer and it executes in an authenticated session on the production origin. There is
no CSP backstop — `app/Http/Middleware/SecurityHeaders.php:47` sets
`script-src 'self' 'unsafe-inline' 'unsafe-eval'`, so inline injected script runs. Grep the
diff for `{!!` in the diagnostics views as a merge gate.

**Redaction, as a second layer only.** Mask email addresses, IC numbers, Malaysian phone
numbers, long digit runs (bank accounts), and `RM \d` amounts. This is defence in depth
behind Phase 0.2, never the primary control.

**Explicitly not built:** no raw file download (a downloaded prod log is HR data on a
laptop), no delete or truncate button, no cross-file search. Pick a day, search within it.

### 2.3 Failed jobs

Screen shows a count badge, then the latest 50 rows: `failed_at`, queue, job class parsed
out of the payload (not the whole payload), and the exception subject to the same
reveal-and-audit treatment as the log viewer. The `failed_jobs.payload` column contains the
full serialised job, which for this application includes model attributes and notification
content — it is never rendered.

**Retry — never pass user input to Artisan `[SEC-H4]`.**

```php
$job = DB::table('failed_jobs')->where('uuid', $uuid)->firstOrFail();
Artisan::call('queue:retry', ['id' => [$job->id]]);
```

The original draft's `Artisan::call('queue:retry', ['id' => $uuid])` with an unconstrained
`{uuid}` accepts the literal string `all`:
`vendor/laravel/framework/src/Illuminate/Queue/Console/RetryCommand.php:68-77` treats
`['all']` as every failed job. So `POST /admin/diagnostics/jobs/all/retry` performs exactly
the mass retry the spec was written to prevent — re-sending stale leave, claim and payroll
notifications to real staff. There is no shell-injection risk (`Artisan::call` with an array
never touches a shell); the risk is the magic token. `->whereUuid()` plus the row lookup
closes it twice.

Retry is idempotent as a queue operation but its side effects (sending mail) are not. Say so
in the UI, keep the throttle, keep the audit row. No "retry all", no "flush".

### 2.4 Scheduler heartbeat

New migration: `schedule_runs`

| Column | Type | Note |
|---|---|---|
| `id` | id | |
| `command` | string, indexed | e.g. `leave:accrue` |
| `started_at` | datetime | |
| `finished_at` | datetime, nullable | null means started and never finished |
| `succeeded` | boolean, nullable | null while running |
| `duration_ms` | integer, nullable | |
| `exception_class` | string, nullable | class name only |

**No `summary` column `[SEC-M3]`.** The original draft stored "short output or exception
message, truncated". Those messages are the same `QueryException` strings carrying bound HR
values, and this table has a 90-day retention against the log's 30 — so it would move PII
into longer storage with no redaction path at all. Truncation makes it worse, not better:
it cuts *after* the SQLSTATE preamble so it keeps the start of the value list, a naive
`substr` splits a multi-byte character and produces invalid UTF-8, and it can split an HTML
tag mid-way and defeat a downstream sanitizer. Store the class; the message is already in
the log under the same `trace_id`, so link to it.

Written from the existing schedule hooks in `bootstrap/app.php`:

```php
$schedule->command('leave:accrue')->monthlyOn(1, '02:00')
    ->withoutOverlapping()
    ->before(fn () => ScheduleRun::begin('leave:accrue'))
    ->onSuccess(fn () => ScheduleRun::finish('leave:accrue', true))
    ->onFailure(fn () => ScheduleRun::finish('leave:accrue', false));
```

Seven commands repeat this, so wrap it in a helper rather than tripling the length of the
schedule block. The existing `$onFailure` closure folds into that helper.

The screen lists each command with expected cadence, last run, outcome, and a status colour
computed **against its own cadence**: `attendance:remind` runs every fifteen minutes, so two
hours of silence is red, while `leave:carry-forward` is annual and six months of silence is
fine.

This is the direct answer to "no cron visibility". If devops's cron entry breaks, the page
turns red on its own instead of us finding out when leave balances are wrong.

**Retention.** The fifteen-minute command writes roughly 64 rows a day. Prune rows older
than 90 days from an existing daily command, so the table never becomes the next thing we
have to ask devops about.

### 2.5 Environment sanity

Read-only panel derived from resolved config, not from `.env`. The original field list
leaked more than it needed to `[SEC-M2]`; this is the trimmed version.

| Field | Shown as | Why trimmed |
|---|---|---|
| `APP_ENV` | value, **red unless `production`** | see below |
| `APP_DEBUG` | boolean, red when true | |
| `APP_URL` | value | |
| Database | driver + reachable boolean + `SELECT 1` latency | **not** the host, port or database name — those are a reconnaissance target on a managed host |
| Cache / session / queue | driver names | |
| Mail | resolved transport name + "host configured" boolean | **not** the hostname. Read `config('mail.mailers.'.config('mail.default'))` directly — `artisan about` misreports mail on this project |
| Pending migrations | **count** + green/red | not the filenames; they are schema disclosure and the count is enough for the purpose |
| `storage/app/public` symlink | present boolean | |
| Deployed commit | contents of a `VERSION` file, or "unknown" | **no `git rev-parse` fallback** |
| dev-login route registered | boolean, **red when true** | |

**On the commit SHA.** The original draft proposed falling back to `git rev-parse HEAD`.
That requires `exec`/`proc_open` from the web process, and the application currently
contains zero shell-execution calls anywhere in `app/` (verified). Introducing the first
one, in a super-admin-reachable controller, for a cosmetic field, is a bad trade. Also
`deploy.sh` does not write a `VERSION` file today, and production does not run `deploy.sh`
at all — it releases from the GitLab pipeline — so the fallback is precisely the branch that
would execute in production. Have the GitLab CI job write `VERSION`, read it with
`file_get_contents`, show "unknown" when absent.

**On `APP_ENV` and dev-login `[SEC-L4]`.** `routes/web.php:82` requires both
`app()->environment('local')` and `file_exists()`, and `routes/dev-login.php` guards again,
so the current guard is sound. The compounding risk is worth one panel row: if `APP_ENV`
were ever mis-set to `local` on a real host *and* the gitignored file were present,
`/dev/login?email=superadmin@…` yields this console with no credentials at all. Both rows
render red, not informational.

**Never on this page:** no `.env` dump, no config dump, no `phpinfo()`, no SQL box, no
artisan runner. Each turns one hijacked super-admin session into full compromise of a
database holding salaries and IC numbers.

### Phase 2 tests

`tests/Feature/DiagnosticsConsoleTest.php`

| Case | Assert |
|---|---|
| Guest hits any `/admin/diagnostics/*` route | redirected to login |
| Employee, HR, manager, management | 403 on every route including the retry POST |
| Super-admin without confirmed password | redirected to password confirmation |
| Super-admin, confirmed | 200 on every read route |
| Kill switch off | 404 on every route, for a super-admin |
| `?day=../../.env` | 404, and the body contains no `APP_KEY` |
| `?day=2026-13-99` | 404, no exception |
| Log fixture containing `<script>alert(1)</script>` | body contains `&lt;script&gt;`, does not contain the raw tag |
| Log fixture with a 3-line stack trace | one entry returned with the trace attached, not three entries |
| Log fixture with an email address | masked in output |
| Log fixture, 5 MB single line | renders truncated, no `TypeError`, no OOM |
| No log files present | empty state, no exception |
| Failed jobs, two rows seeded | both listed, job class parsed, payload not in the response body |
| `POST /admin/diagnostics/jobs/all/retry` | 404, and `queue:retry` was not invoked |
| Retry with a real uuid | `queue:retry` invoked with that row's integer id, audit row written |
| Schedule screen, command inside its cadence | green |
| Schedule screen, `attendance:remind` last ran 3 hours ago | red |
| Schedule screen, command never ran | red, no exception |
| Env panel rendered | body contains no `DB_PASSWORD`, no `APP_KEY`, no mail password, no DB host |
| Any diagnostics response | `Cache-Control: no-store` |

`tests/Unit/LogReaderTest.php` covers the parser against a fixture containing a normal
entry, a multi-line stack trace, a JSON context blob, a malformed line, an oversized single
entry, and an entry containing HTML.

The path-traversal row, the XSS row, the retry-`all` row and the 403 row go in first. The
original test table had twelve rows and not one injection test.

---

## Privacy decision

The original plan deferred Phase 3 (external error tracking) on the grounds that it needs a
data-privacy decision first. That reasoning applies to Phase 2 as well: it puts the same
production log corpus on a screen and adds a 90-day scheduler table. The only difference is
that the data does not leave the server — a real difference, but not the difference between
"needs a decision" and "does not".

Get the decision once, covering both, before Phase 2. What is being decided: who may read
production logs containing staff PII, under what authentication, with what audit trail, and
who can purge a log file that turns out to contain something it should not.

---

## Build order

**Phase 0 (blocking).**

1. `EnforceTwoFactor` super-admin enforcement, with the enrolment path tested on staging
   against a spare super-admin account.
2. `QueryException` binding suppression, with the two log-content tests.
3. Write down the log-purge path.

**Phase 1 (independent, ships as soon as 1.2 is correct).**

4. `AssignTraceId` + exception context, with tests. Small, no UI.
5. Error pages (500, 419 only).
6. Hand devops the `LOG_*` block.

**Phase 2 (after Phase 0 is on staging and the privacy decision exists).**

7. Env sanity panel. Smallest screen, proves the route, the gate, `password.confirm`, the
   kill switch and the audit row all work.
8. Log viewer — `LogReader` unit-tested first, traversal and XSS tests before the UI.
9. Failed jobs.
10. Schedule heartbeat. Last: it touches a migration and all seven scheduled commands.

Each step ships to `dev`, then to `main`, then to staging, and is tested on staging before
the next begins. Staging is the gate.

## What this does not solve

- **Errors nobody reports.** A 500 at 3am that the user shrugs off leaves a log line we
  never look for. Only push-based error tracking (Phase 3) fixes that.
- **Reading data.** No screen here shows a leave balance or a payslip. Deliberate.
  Debugging data problems still goes through devops, and should.
- **Performance.** No slow-query or slow-request tracking. That is Pulse, later, if these
  phases prove insufficient.
- **A compromised super-admin session.** Phase 0 raises the cost of getting one and the
  audit trail records what it did. Neither prevents it.
