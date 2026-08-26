# Work Item → Google Calendar Sync Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** When a work item is assigned to someone (or its due date changes), and that person has connected their Google Calendar, an all-day event for it appears on their calendar automatically — kept in sync one-way (app → Google), and removed when the item is done, archived, unassigned-from-them, or deleted.

**Architecture:** A per-user `google_calendar_connections` table holds encrypted OAuth tokens from a dedicated "Connect Google Calendar" consent flow (separate from the existing generic OIDC login). A new `WorkItemObserver` watches `employee_id`/`due_at`/`archived_at`/`status` changes and dispatches a queued `SyncWorkItemCalendarEventJob` that talks to the Calendar v3 REST API via Laravel's `Http` facade — no new Composer dependency, mirroring the existing hand-rolled `OidcClient` pattern.

**Tech Stack:** Laravel 13 / PHP 8.5, `Http` facade, `database` queue connection (already relied on for invite/digest mail), PHPUnit + `Http::fake()`/`Queue::fake()`.

**Spec:** `docs/superpowers/specs/2026-08-26-calendar-sync-design.md`

## Global Constraints

- One-way sync only: app → Google Calendar. Never read changes back.
- Sync target is always the work item's current assignee (`WorkItem::employee()->user`), never the viewer.
- No new Composer dependency — raw `Http` facade calls only.
- Tokens (`access_token`, `refresh_token`) must use Laravel's `encrypted` cast — never stored plaintext.
- Every queued job must explicitly set `CurrentTenant` (via the tenant id captured at dispatch time) before touching any `BelongsToTenant` model, and clear it afterward — queued jobs run outside the request lifecycle that normally sets tenant context.
- All-day events use Google's **exclusive** end-date convention: `end.date = due_at + 1 day`.
- `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` env vars will be supplied later by the user — code must work correctly with `Http::fake()` in tests and degrade to "feature not configured" (404 on connect routes) when unset, exactly like `OidcClient::configured()`.
- All tests use `Http::fake()` — no real network calls.
- Follow `vendor/bin/pint --dirty --format agent` after every PHP change.
- **Every command and file path in this plan runs inside the worktree** at
  `.claude/worktrees/profile-work-items-calendar` (branch `feature/profile-work-items-calendar`),
  not the main checkout. The main checkout is shared with another active session working on
  `dev` — a file or commit that lands there instead of the worktree can get swept into that
  session's work. Before every commit, confirm with `git branch --show-current` that it prints
  `feature/profile-work-items-calendar`; abort the commit if it doesn't.

---

## File Structure

| File | Responsibility |
|---|---|
| `database/migrations/2026_08_26_100000_create_google_calendar_connections_table.php` | New table for per-user OAuth tokens |
| `database/migrations/2026_08_26_100100_add_google_event_id_to_work_items_table.php` | Tracks the synced event id per work item |
| `app/Models/GoogleCalendarConnection.php` | Eloquent model, encrypted token casts |
| `config/services.php` | New `google_calendar` config block (mirrors `oidc`) |
| `.env.example` | New `GOOGLE_CALENDAR_*` placeholders |
| `app/Services/GoogleCalendarClient.php` | OAuth + Calendar API HTTP calls |
| `app/Http/Controllers/GoogleCalendarConnectionController.php` | Connect/callback/disconnect endpoints |
| `routes/web.php` | 3 new routes for the controller above |
| `resources/views/screens/profile.blade.php` | "Connect/Disconnect Google Calendar" control in the Work & Tasks tab |
| `app/Http/Controllers/Concerns/BuildsPeopleData.php` | Adds `googleCalendarConnected` to profile view data |
| `app/Jobs/SyncWorkItemCalendarEventJob.php` | Create/update/delete decision logic |
| `app/Observers/WorkItemObserver.php` | Detects relevant `WorkItem` changes, dispatches the job |
| `app/Providers/AppServiceProvider.php` | Registers the observer + binds `GoogleCalendarClient` |
| `tests/Feature/GoogleCalendarConnectionTest.php` | Connect/callback/disconnect tests |
| `tests/Feature/GoogleCalendarSyncTest.php` | Observer-dispatch + job lifecycle tests |

---

## Task 1: `GoogleCalendarConnection` model + migration

**Files:**
- Create: `database/migrations/2026_08_26_100000_create_google_calendar_connections_table.php`
- Create: `app/Models/GoogleCalendarConnection.php`
- Test: `tests/Unit/GoogleCalendarConnectionTest.php`

**Interfaces:**
- Produces: `GoogleCalendarConnection` model with `user_id`, `access_token` (encrypted), `refresh_token` (encrypted), `expires_at` (datetime), a `user(): BelongsTo` relation. `$model->guarded = []` (matches `WorkItem` style — mass-assignable).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit;

use App\Models\GoogleCalendarConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class GoogleCalendarConnectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_tokens_are_encrypted_at_rest_and_readable_through_the_model(): void
    {
        $user = User::create(['name' => 'Demo', 'email' => 'demo@example.com', 'password' => Hash::make('password')]);

        $connection = GoogleCalendarConnection::create([
            'user_id' => $user->id,
            'access_token' => 'plain-access-token',
            'refresh_token' => 'plain-refresh-token',
            'expires_at' => now()->addHour(),
        ]);

        $raw = DB::table('google_calendar_connections')->where('id', $connection->id)->first();
        $this->assertStringNotContainsString('plain-access-token', $raw->access_token);
        $this->assertStringNotContainsString('plain-refresh-token', $raw->refresh_token);

        $fresh = GoogleCalendarConnection::find($connection->id);
        $this->assertSame('plain-access-token', $fresh->access_token);
        $this->assertSame('plain-refresh-token', $fresh->refresh_token);
        $this->assertTrue($fresh->user->is($user));
    }

    public function test_one_connection_per_user(): void
    {
        $user = User::create(['name' => 'Demo', 'email' => 'demo@example.com', 'password' => Hash::make('password')]);
        GoogleCalendarConnection::create([
            'user_id' => $user->id, 'access_token' => 'a', 'refresh_token' => 'b', 'expires_at' => now(),
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        GoogleCalendarConnection::create([
            'user_id' => $user->id, 'access_token' => 'c', 'refresh_token' => 'd', 'expires_at' => now(),
        ]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact tests/Unit/GoogleCalendarConnectionTest.php`
Expected: FAIL — class `App\Models\GoogleCalendarConnection` not found / table doesn't exist.

- [ ] **Step 3: Write the migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_calendar_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('access_token');
            $table->text('refresh_token');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_calendar_connections');
    }
};
```

- [ ] **Step 4: Write the model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per user who has connected their Google account for calendar sync.
 * Not tenant-scoped: the Google account belongs to the person, not a tenant —
 * a user who belongs to multiple tenants still has exactly one connection.
 */
class GoogleCalendarConnection extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --compact tests/Unit/GoogleCalendarConnectionTest.php`
Expected: PASS

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/2026_08_26_100000_create_google_calendar_connections_table.php app/Models/GoogleCalendarConnection.php tests/Unit/GoogleCalendarConnectionTest.php
git commit -m "feat(calendar): add GoogleCalendarConnection model + table"
```

---

## Task 2: `google_event_id` on `work_items`

**Files:**
- Create: `database/migrations/2026_08_26_100100_add_google_event_id_to_work_items_table.php`
- Test: `tests/Unit/WorkItemGoogleEventIdTest.php`

**Interfaces:**
- Produces: `WorkItem::$google_event_id` (nullable string) — read/written directly like any other guarded-off column (`WorkItem::$guarded = []`, same as the model already is).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class WorkItemGoogleEventIdTest extends TestCase
{
    use RefreshDatabase;

    public function test_google_event_id_is_nullable_and_storable(): void
    {
        $user = User::create(['name' => 'Demo', 'email' => 'demo@example.com', 'password' => Hash::make('password')]);
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $user->tenants()->attach($tenant->id, ['role' => 'employee']);
        $employee = Employee::create([
            'tenant_id' => $tenant->id, 'user_id' => $user->id,
            'name' => 'Demo', 'status' => 'active', 'workload' => 'green',
        ]);

        $item = $employee->workItems()->create([
            'tenant_id' => $tenant->id, 'title' => 'X', 'type' => 'task',
            'priority' => 'low', 'status' => 'todo', 'progress' => 0,
        ]);
        $this->assertNull($item->google_event_id);

        $item->update(['google_event_id' => 'evt_123']);
        $this->assertSame('evt_123', $item->fresh()->google_event_id);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact tests/Unit/WorkItemGoogleEventIdTest.php`
Expected: FAIL — "Unknown column 'google_event_id'"

- [ ] **Step 3: Write the migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_items', function (Blueprint $table) {
            $table->string('google_event_id')->nullable()->after('done_at');
        });
    }

    public function down(): void
    {
        Schema::table('work_items', function (Blueprint $table) {
            $table->dropColumn('google_event_id');
        });
    }
};
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact tests/Unit/WorkItemGoogleEventIdTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_08_26_100100_add_google_event_id_to_work_items_table.php tests/Unit/WorkItemGoogleEventIdTest.php
git commit -m "feat(calendar): track synced Google event id on work items"
```

---

## Task 3: `GoogleCalendarClient` service

**Files:**
- Create: `app/Services/GoogleCalendarClient.php`
- Modify: `config/services.php` (add `google_calendar` block after the `oidc` block)
- Modify: `.env.example` (add placeholders after the `OIDC_*` block)
- Test: `tests/Unit/GoogleCalendarClientTest.php`

**Interfaces:**
- Consumes: `config('services.google_calendar')` array; `App\Models\GoogleCalendarConnection`; `App\Models\WorkItem` (`title`, `due_at` (Carbon), `google_event_id`).
- Produces (used by Task 5 controller and Task 7 job):
  - `GoogleCalendarClient::fromConfig(): self`
  - `->configured(): bool`
  - `->newState(): string`
  - `->redirectUrl(string $state): string`
  - `->exchangeCode(string $code): array{access_token:string, refresh_token:string, expires_in:int}` — throws `RuntimeException` on failure
  - `->accessTokenFor(GoogleCalendarConnection $connection): string` — refreshes + persists if `expires_at` has passed
  - `->createOrUpdateEvent(WorkItem $item, GoogleCalendarConnection $connection): string` — returns the Google event id
  - `->deleteEvent(string $eventId, GoogleCalendarConnection $connection): void` — treats 404/410 as success

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Unit;

use App\Models\Employee;
use App\Models\GoogleCalendarConnection;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkItem;
use App\Services\GoogleCalendarClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleCalendarClientTest extends TestCase
{
    use RefreshDatabase;

    private function client(): GoogleCalendarClient
    {
        return new GoogleCalendarClient([
            'client_id' => 'client-123',
            'client_secret' => 'secret-456',
            'redirect' => 'https://example.test/callback',
        ]);
    }

    private function connection(array $overrides = []): GoogleCalendarConnection
    {
        $user = User::create(['name' => 'Demo', 'email' => 'demo@example.com', 'password' => Hash::make('password')]);

        return GoogleCalendarConnection::create(array_merge([
            'user_id' => $user->id,
            'access_token' => 'valid-token',
            'refresh_token' => 'refresh-token',
            'expires_at' => now()->addHour(),
        ], $overrides));
    }

    private function workItem(): WorkItem
    {
        $user = User::create(['name' => 'Assignee', 'email' => 'assignee@example.com', 'password' => Hash::make('password')]);
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $user->tenants()->attach($tenant->id, ['role' => 'employee']);
        $employee = Employee::create([
            'tenant_id' => $tenant->id, 'user_id' => $user->id,
            'name' => 'Assignee', 'status' => 'active', 'workload' => 'green',
        ]);

        return $employee->workItems()->create([
            'tenant_id' => $tenant->id, 'title' => 'Ship the report', 'type' => 'task',
            'priority' => 'low', 'status' => 'todo', 'progress' => 0, 'due_at' => '2026-09-30',
        ]);
    }

    public function test_configured_requires_client_id_and_secret(): void
    {
        $this->assertTrue($this->client()->configured());
        $this->assertFalse((new GoogleCalendarClient([]))->configured());
    }

    public function test_redirect_url_requests_offline_access_and_the_calendar_events_scope(): void
    {
        $url = $this->client()->redirectUrl('state-abc');

        $this->assertStringContainsString('access_type=offline', $url);
        $this->assertStringContainsString(urlencode('https://www.googleapis.com/auth/calendar.events'), $url);
        $this->assertStringContainsString('state=state-abc', $url);
    }

    public function test_exchange_code_returns_tokens(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'new-access', 'refresh_token' => 'new-refresh', 'expires_in' => 3600,
            ]),
        ]);

        $tokens = $this->client()->exchangeCode('auth-code');

        $this->assertSame('new-access', $tokens['access_token']);
        $this->assertSame('new-refresh', $tokens['refresh_token']);
        $this->assertSame(3600, $tokens['expires_in']);
    }

    public function test_exchange_code_throws_on_failure(): void
    {
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400)]);

        $this->expectException(\RuntimeException::class);
        $this->client()->exchangeCode('bad-code');
    }

    public function test_access_token_for_reuses_unexpired_token_without_a_network_call(): void
    {
        Http::fake();
        $connection = $this->connection(['expires_at' => now()->addMinutes(30)]);

        $token = $this->client()->accessTokenFor($connection);

        $this->assertSame('valid-token', $token);
        Http::assertNothingSent();
    }

    public function test_access_token_for_refreshes_and_persists_when_expired(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'refreshed-token', 'expires_in' => 3600]),
        ]);
        $connection = $this->connection(['expires_at' => now()->subMinute()]);

        $token = $this->client()->accessTokenFor($connection);

        $this->assertSame('refreshed-token', $token);
        $this->assertSame('refreshed-token', $connection->fresh()->access_token);
    }

    public function test_create_or_update_event_posts_a_new_event_with_exclusive_end_date(): void
    {
        Http::fake([
            'www.googleapis.com/calendar/v3/*' => Http::response(['id' => 'evt_new']),
        ]);
        $connection = $this->connection();
        $item = $this->workItem();

        $eventId = $this->client()->createOrUpdateEvent($item, $connection);

        $this->assertSame('evt_new', $eventId);
        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && $request['start']['date'] === '2026-09-30'
                && $request['end']['date'] === '2026-10-01';
        });
    }

    public function test_create_or_update_event_patches_when_a_google_event_id_already_exists(): void
    {
        Http::fake(['www.googleapis.com/calendar/v3/*' => Http::response(['id' => 'evt_existing'])]);
        $connection = $this->connection();
        $item = $this->workItem();
        $item->update(['google_event_id' => 'evt_existing']);

        $this->client()->createOrUpdateEvent($item, $connection);

        Http::assertSent(fn ($request) => $request->method() === 'PATCH'
            && str_contains((string) $request->url(), 'evt_existing'));
    }

    public function test_delete_event_treats_404_as_success(): void
    {
        Http::fake(['www.googleapis.com/calendar/v3/*' => Http::response(null, 404)]);
        $connection = $this->connection();

        $this->client()->deleteEvent('evt_gone', $connection);
        $this->assertTrue(true); // no exception
    }

    public function test_delete_event_throws_on_real_failure(): void
    {
        Http::fake(['www.googleapis.com/calendar/v3/*' => Http::response(['error' => 'server_error'], 500)]);
        $connection = $this->connection();

        $this->expectException(\RuntimeException::class);
        $this->client()->deleteEvent('evt_x', $connection);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact tests/Unit/GoogleCalendarClientTest.php`
Expected: FAIL — class `App\Services\GoogleCalendarClient` not found

- [ ] **Step 3: Add config + env placeholders**

In `config/services.php`, immediately after the closing `],` of the `'oidc' => [...]` block:

```php
    /*
    | Google Calendar sync (one-way, app -> Calendar). Separate from `oidc` above:
    | that block is a generic OIDC login and never requests Calendar scope. This is
    | a dedicated Google OAuth client with offline access to
    | https://www.googleapis.com/auth/calendar.events. "Configured" only when both
    | client_id and client_secret are present; the connect routes 404 otherwise —
    | same gating pattern as OidcClient::configured().
    */
    'google_calendar' => [
        'client_id' => env('GOOGLE_CALENDAR_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CALENDAR_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_CALENDAR_REDIRECT_URL'),
    ],
```

In `.env.example`, after the `OIDC_*` lines:

```
GOOGLE_CALENDAR_CLIENT_ID=
GOOGLE_CALENDAR_CLIENT_SECRET=
GOOGLE_CALENDAR_REDIRECT_URL=
```

- [ ] **Step 4: Write the service**

```php
<?php

namespace App\Services;

use App\Models\GoogleCalendarConnection;
use App\Models\WorkItem;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Hand-rolled Google OAuth + Calendar v3 client, same shape as OidcClient (no
 * external SDK). One-way sync only: this class only ever writes to Google
 * Calendar, never reads events back.
 */
class GoogleCalendarClient
{
    private const AUTHORIZE_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const EVENTS_URL = 'https://www.googleapis.com/calendar/v3/calendars/primary/events';

    private const SCOPE = 'https://www.googleapis.com/auth/calendar.events';

    /** @param array{client_id?:?string,client_secret?:?string,redirect?:?string} $config */
    public function __construct(private array $config) {}

    public static function fromConfig(): self
    {
        return new self((array) config('services.google_calendar', []));
    }

    public function configured(): bool
    {
        foreach (['client_id', 'client_secret'] as $key) {
            if (blank($this->config[$key] ?? null)) {
                return false;
            }
        }

        return true;
    }

    public function newState(): string
    {
        return Str::random(40);
    }

    public function redirectUrl(string $state): string
    {
        $params = [
            'response_type' => 'code',
            'client_id' => $this->config['client_id'],
            'redirect_uri' => $this->redirectUri(),
            'scope' => self::SCOPE,
            // offline + consent: without both, Google only returns a refresh_token
            // on the account's very first-ever authorization for this app.
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ];

        return self::AUTHORIZE_URL.'?'.http_build_query($params);
    }

    /**
     * @return array{access_token:string, refresh_token:string, expires_in:int}
     *
     * @throws RuntimeException
     */
    public function exchangeCode(string $code): array
    {
        $response = Http::asForm()->post(self::TOKEN_URL, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri(),
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
        ]);

        if (! $response->successful() || blank($response->json('access_token')) || blank($response->json('refresh_token'))) {
            throw new RuntimeException('Google Calendar token exchange failed.');
        }

        return [
            'access_token' => (string) $response->json('access_token'),
            'refresh_token' => (string) $response->json('refresh_token'),
            'expires_in' => (int) $response->json('expires_in', 3600),
        ];
    }

    /** Returns a valid access token, refreshing and persisting it first if expired. */
    public function accessTokenFor(GoogleCalendarConnection $connection): string
    {
        if ($connection->expires_at !== null && $connection->expires_at->isFuture()) {
            return $connection->access_token;
        }

        $response = Http::asForm()->post(self::TOKEN_URL, [
            'grant_type' => 'refresh_token',
            'refresh_token' => $connection->refresh_token,
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
        ]);

        if (! $response->successful() || blank($response->json('access_token'))) {
            throw new RuntimeException('Google Calendar token refresh failed.');
        }

        $connection->update([
            'access_token' => (string) $response->json('access_token'),
            'expires_at' => now()->addSeconds((int) $response->json('expires_in', 3600)),
        ]);

        return $connection->access_token;
    }

    /**
     * Create or update the all-day event for a work item's due date. Google's
     * all-day events use an EXCLUSIVE end date, so end.date is due_at + 1 day.
     */
    public function createOrUpdateEvent(WorkItem $item, GoogleCalendarConnection $connection): string
    {
        $token = $this->accessTokenFor($connection);

        $payload = [
            'summary' => $item->title,
            'description' => route('work.show', $item),
            'start' => ['date' => $item->due_at->toDateString()],
            'end' => ['date' => $item->due_at->copy()->addDay()->toDateString()],
        ];

        $response = $item->google_event_id
            ? Http::withToken($token)->patch(self::EVENTS_URL.'/'.$item->google_event_id, $payload)
            : Http::withToken($token)->post(self::EVENTS_URL, $payload);

        if (! $response->successful()) {
            throw new RuntimeException('Google Calendar event sync failed: '.$response->body());
        }

        return (string) $response->json('id');
    }

    /** Google returns 404/410 for an event already gone client-side; treat as success. */
    public function deleteEvent(string $eventId, GoogleCalendarConnection $connection): void
    {
        $token = $this->accessTokenFor($connection);
        $response = Http::withToken($token)->delete(self::EVENTS_URL.'/'.$eventId);

        if (! $response->successful() && ! in_array($response->status(), [404, 410], true)) {
            throw new RuntimeException('Google Calendar event delete failed: '.$response->body());
        }
    }

    private function redirectUri(): string
    {
        return $this->config['redirect'] ?: route('google-calendar.callback');
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --compact tests/Unit/GoogleCalendarClientTest.php`
Expected: PASS

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/GoogleCalendarClient.php config/services.php .env.example tests/Unit/GoogleCalendarClientTest.php
git commit -m "feat(calendar): add GoogleCalendarClient OAuth + Calendar v3 service"
```

---

## Task 4: Connection controller, routes, and profile UI

**Files:**
- Create: `app/Http/Controllers/GoogleCalendarConnectionController.php`
- Modify: `routes/web.php` (add 3 routes inside the `['tenant', 'company.active', 'not.archived', 'module.enabled']` group, near the `work.*` routes)
- Modify: `app/Http/Controllers/Concerns/BuildsPeopleData.php` (add `googleCalendarConnected` to `profileData()`'s return array)
- Modify: `resources/views/screens/profile.blade.php` (connect/disconnect control in the Work & Tasks tab)
- Modify: `app/Providers/AppServiceProvider.php` (bind `GoogleCalendarClient::class` like `OidcClient::class`)
- Test: `tests/Feature/GoogleCalendarConnectionTest.php`

**Interfaces:**
- Consumes: `GoogleCalendarClient` (Task 3), `GoogleCalendarConnection` (Task 1).
- Produces: routes `google-calendar.redirect` (GET), `google-calendar.callback` (GET), `google-calendar.disconnect` (POST). `googleCalendarConnected: bool` passed into the `profile` screen view.

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\GoogleCalendarConnection;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleCalendarConnectionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.google_calendar.client_id' => 'client-123', 'services.google_calendar.client_secret' => 'secret-456']);

        $this->user = User::create(['name' => 'Demo', 'email' => 'demo@example.com', 'password' => Hash::make('password')]);
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $this->user->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->user->id,
            'name' => 'Demo', 'status' => 'active', 'workload' => 'green',
        ]);
    }

    private function actingInTenant(): self
    {
        $this->actingAs($this->user)->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    public function test_redirect_stashes_state_and_sends_the_browser_to_google(): void
    {
        $response = $this->actingInTenant()->get(route('google-calendar.redirect'));

        $response->assertRedirect();
        $this->assertStringContainsString('accounts.google.com', $response->headers->get('Location'));
        $this->assertNotNull(session('google_calendar.state'));
    }

    public function test_connect_routes_404_when_not_configured(): void
    {
        config(['services.google_calendar.client_id' => null]);

        $this->actingInTenant()->get(route('google-calendar.redirect'))->assertNotFound();
    }

    public function test_callback_rejects_a_mismatched_state(): void
    {
        $this->actingInTenant();
        session(['google_calendar.state' => 'expected-state']);

        $this->get(route('google-calendar.callback', ['state' => 'wrong-state', 'code' => 'abc']))
            ->assertRedirect()
            ->assertSessionHasErrors('google_calendar');

        $this->assertDatabaseCount('google_calendar_connections', 0);
    }

    public function test_callback_stores_the_connection_against_only_the_authenticated_user(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'access-1', 'refresh_token' => 'refresh-1', 'expires_in' => 3600,
            ]),
        ]);
        $this->actingInTenant();
        session(['google_calendar.state' => 'expected-state']);

        $this->get(route('google-calendar.callback', ['state' => 'expected-state', 'code' => 'auth-code']))
            ->assertRedirect();

        $this->assertDatabaseHas('google_calendar_connections', ['user_id' => $this->user->id]);
        $this->assertSame('access-1', GoogleCalendarConnection::where('user_id', $this->user->id)->first()->access_token);
    }

    public function test_disconnect_removes_the_authenticated_users_connection_only(): void
    {
        $other = User::create(['name' => 'Other', 'email' => 'other@example.com', 'password' => Hash::make('password')]);
        GoogleCalendarConnection::create(['user_id' => $this->user->id, 'access_token' => 'a', 'refresh_token' => 'b', 'expires_at' => now()]);
        GoogleCalendarConnection::create(['user_id' => $other->id, 'access_token' => 'c', 'refresh_token' => 'd', 'expires_at' => now()]);

        $this->actingInTenant()->post(route('google-calendar.disconnect'))->assertRedirect();

        $this->assertDatabaseMissing('google_calendar_connections', ['user_id' => $this->user->id]);
        $this->assertDatabaseHas('google_calendar_connections', ['user_id' => $other->id]);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact tests/Feature/GoogleCalendarConnectionTest.php`
Expected: FAIL — routes/controller don't exist

- [ ] **Step 3: Write the controller**

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\GoogleCalendarConnection;
use App\Services\GoogleCalendarClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Personal "connect your Google Calendar" flow — separate from OidcController's
 * SSO login. SECURITY: the callback validates `state` against the session and
 * always writes the row against the authenticated user (auth()->id()), never
 * anything from the request — see the spec's connect-callback rule.
 */
class GoogleCalendarConnectionController extends Controller
{
    private const STATE_KEY = 'google_calendar.state';

    public function __construct(private GoogleCalendarClient $client)
    {
        abort_unless($this->client->configured(), 404);
    }

    public function redirect(Request $request): RedirectResponse
    {
        $state = $this->client->newState();
        $request->session()->put(self::STATE_KEY, $state);

        return redirect()->away($this->client->redirectUrl($state));
    }

    public function callback(Request $request): RedirectResponse
    {
        $expected = $request->session()->pull(self::STATE_KEY);
        $returned = (string) $request->query('state', '');

        if (blank($expected) || ! hash_equals((string) $expected, $returned)) {
            return redirect('/app/profile')->withErrors([
                'google_calendar' => 'Google Calendar connection could not be verified. Please try again.',
            ]);
        }

        $code = (string) $request->query('code', '');
        if (blank($code)) {
            return redirect('/app/profile')->withErrors([
                'google_calendar' => 'Google Calendar connection was cancelled.',
            ]);
        }

        try {
            $tokens = $this->client->exchangeCode($code);
        } catch (Throwable $e) {
            report($e);

            return redirect('/app/profile')->withErrors([
                'google_calendar' => 'Google could not be reached. Please try again.',
            ]);
        }

        GoogleCalendarConnection::updateOrCreate(
            ['user_id' => $request->user()->id],
            [
                'access_token' => $tokens['access_token'],
                'refresh_token' => $tokens['refresh_token'],
                'expires_at' => now()->addSeconds($tokens['expires_in']),
            ]
        );

        return redirect('/app/profile')->with('ok', 'Google Calendar connected.');
    }

    public function disconnect(Request $request): RedirectResponse
    {
        GoogleCalendarConnection::where('user_id', $request->user()->id)->delete();

        return redirect('/app/profile')->with('ok', 'Google Calendar disconnected.');
    }
}
```

- [ ] **Step 4: Add routes**

In `routes/web.php`, inside the `Route::middleware(['tenant', 'company.active', 'not.archived', 'module.enabled'])->group(...)` block, directly after the `work.comment.destroy` route:

```php
        Route::get('/app/settings/google-calendar/connect', [GoogleCalendarConnectionController::class, 'redirect'])->name('google-calendar.redirect');
        Route::get('/app/settings/google-calendar/callback', [GoogleCalendarConnectionController::class, 'callback'])->name('google-calendar.callback');
        Route::post('/app/settings/google-calendar/disconnect', [GoogleCalendarConnectionController::class, 'disconnect'])->name('google-calendar.disconnect');
```

Add the import near the other controller `use` statements at the top of the file:

```php
use App\Http\Controllers\GoogleCalendarConnectionController;
```

- [ ] **Step 5: Bind the service**

In `app/Providers/AppServiceProvider.php::register()`, directly after the existing `OidcClient` binding:

```php
        // Personal Google Calendar sync, built from config/services.php google_calendar block.
        $this->app->bind(GoogleCalendarClient::class, fn () => GoogleCalendarClient::fromConfig());
```

Add `use App\Services\GoogleCalendarClient;` to the top of the file.

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test --compact tests/Feature/GoogleCalendarConnectionTest.php`
Expected: PASS

- [ ] **Step 7: Expose connection status on the profile screen**

In `app/Http/Controllers/Concerns/BuildsPeopleData.php`, inside `profileData()`'s `return array_merge([...` array (next to `'assignedTasks' => $assignedTasks,`):

```php
            'googleCalendarConnected' => ($own && $e && $own->id === $e->id)
                ? \App\Models\GoogleCalendarConnection::where('user_id', $own->user_id)->exists()
                : false,
```

- [ ] **Step 8: Add the UI control**

In `resources/views/screens/profile.blade.php`, directly after the "Work items" header `<div>` at line 273 (before the `@forelse ($wItems as $w)` loop), add:

```blade
                @if ($isOwn)
                <div style="padding:0 20px 12px;display:flex;align-items:center;justify-content:space-between;gap:12px;">
                    <span style="font-size:12px;color:var(--muted);" x-text="$store.ui.lang==='en' ? 'Google Calendar' : 'Kalendar Google'">Google Calendar</span>
                    @if ($googleCalendarConnected ?? false)
                        <form method="post" action="{{ route('google-calendar.disconnect') }}">
                            @csrf
                            <button type="submit" class="uj-btn-ghost" style="height:30px;padding:0 12px;font-size:12px;">
                                <span x-text="$store.ui.lang==='en' ? 'Disconnect' : 'Putuskan'">Disconnect</span>
                            </button>
                        </form>
                    @else
                        <a href="{{ route('google-calendar.redirect') }}" class="uj-btn-ghost" style="display:inline-flex;height:30px;align-items:center;padding:0 12px;font-size:12px;text-decoration:none;">
                            <span x-text="$store.ui.lang==='en' ? 'Connect' : 'Sambung'">Connect</span>
                        </a>
                    @endif
                </div>
                @endif
```

This block only renders `route('google-calendar.redirect')`/`.disconnect` links — it never calls `->configured()` directly, so if credentials are absent the link 404s when clicked rather than the button silently vanishing. That's acceptable pre-launch (env vars are pending from the user per the spec) but worth a manual check once credentials exist.

- [ ] **Step 9: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/GoogleCalendarConnectionController.php routes/web.php app/Http/Controllers/Concerns/BuildsPeopleData.php resources/views/screens/profile.blade.php app/Providers/AppServiceProvider.php tests/Feature/GoogleCalendarConnectionTest.php
git commit -m "feat(calendar): add Google Calendar connect/disconnect flow"
```

---

## Task 5: `SyncWorkItemCalendarEventJob`

**Files:**
- Create: `app/Jobs/SyncWorkItemCalendarEventJob.php`
- Test: `tests/Feature/SyncWorkItemCalendarEventJobTest.php`

**Interfaces:**
- Consumes: `GoogleCalendarClient` (Task 3, resolved via the container in `handle()`), `GoogleCalendarConnection` (Task 1), `WorkItem` (Task 2's `google_event_id`), `App\Tenancy\CurrentTenant`, `App\Models\Tenant`.
- Produces:
  ```php
  new SyncWorkItemCalendarEventJob(
      tenantId: int,
      action: 'upsert' | 'delete',
      workItemId: ?int = null,   // required for 'upsert'; optional for 'delete' (nulls the column if given)
      userId: ?int = null,       // required for 'delete' — whose calendar to delete from
      googleEventId: ?string = null, // required for 'delete'
  )
  ```
  Public readonly constructor-promoted properties (so `Queue::fake()->assertPushed(fn ($job) => ...)` can read them directly). Consumed by Task 6's observer.

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Feature;

use App\Jobs\SyncWorkItemCalendarEventJob;
use App\Models\Employee;
use App\Models\GoogleCalendarConnection;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkItem;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncWorkItemCalendarEventJobTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $assigneeUser;

    private Employee $assignee;

    private WorkItem $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $this->assigneeUser = User::create(['name' => 'Assignee', 'email' => 'assignee@example.com', 'password' => Hash::make('password')]);
        $this->assigneeUser->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $this->assignee = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->assigneeUser->id,
            'name' => 'Assignee', 'status' => 'active', 'workload' => 'green',
        ]);
        $this->item = $this->assignee->workItems()->create([
            'tenant_id' => $this->tenant->id, 'title' => 'Ship it', 'type' => 'task',
            'priority' => 'low', 'status' => 'todo', 'progress' => 0, 'due_at' => '2026-09-30',
        ]);
    }

    private function connectAssignee(): GoogleCalendarConnection
    {
        return GoogleCalendarConnection::create([
            'user_id' => $this->assigneeUser->id, 'access_token' => 'tok', 'refresh_token' => 'ref', 'expires_at' => now()->addHour(),
        ]);
    }

    public function test_upsert_creates_an_event_and_stores_the_event_id(): void
    {
        $this->connectAssignee();
        Http::fake(['www.googleapis.com/calendar/v3/*' => Http::response(['id' => 'evt_new'])]);

        (new SyncWorkItemCalendarEventJob(
            tenantId: $this->tenant->id, action: 'upsert', workItemId: $this->item->id,
        ))->handle(app(CurrentTenant::class), app(\App\Services\GoogleCalendarClient::class));

        $this->assertSame('evt_new', $this->item->fresh()->google_event_id);
    }

    public function test_upsert_is_a_no_op_when_the_assignee_has_no_connection(): void
    {
        Http::fake();

        (new SyncWorkItemCalendarEventJob(
            tenantId: $this->tenant->id, action: 'upsert', workItemId: $this->item->id,
        ))->handle(app(CurrentTenant::class), app(\App\Services\GoogleCalendarClient::class));

        Http::assertNothingSent();
        $this->assertNull($this->item->fresh()->google_event_id);
    }

    public function test_upsert_is_a_no_op_when_the_item_is_no_longer_syncable(): void
    {
        $this->connectAssignee();
        $this->item->update(['status' => 'done', 'done_at' => now()]);
        Http::fake();

        (new SyncWorkItemCalendarEventJob(
            tenantId: $this->tenant->id, action: 'upsert', workItemId: $this->item->id,
        ))->handle(app(CurrentTenant::class), app(\App\Services\GoogleCalendarClient::class));

        Http::assertNothingSent();
    }

    public function test_delete_removes_the_remote_event_and_clears_the_column(): void
    {
        $this->connectAssignee();
        $this->item->update(['google_event_id' => 'evt_old']);
        Http::fake(['www.googleapis.com/calendar/v3/*' => Http::response(null, 204)]);

        (new SyncWorkItemCalendarEventJob(
            tenantId: $this->tenant->id, action: 'delete',
            workItemId: $this->item->id, userId: $this->assigneeUser->id, googleEventId: 'evt_old',
        ))->handle(app(CurrentTenant::class), app(\App\Services\GoogleCalendarClient::class));

        Http::assertSent(fn ($request) => $request->method() === 'DELETE' && str_contains((string) $request->url(), 'evt_old'));
        $this->assertNull($this->item->fresh()->google_event_id);
    }

    public function test_delete_is_a_no_op_without_a_connection(): void
    {
        Http::fake();

        (new SyncWorkItemCalendarEventJob(
            tenantId: $this->tenant->id, action: 'delete',
            userId: $this->assigneeUser->id, googleEventId: 'evt_old',
        ))->handle(app(CurrentTenant::class), app(\App\Services\GoogleCalendarClient::class));

        Http::assertNothingSent();
    }

    public function test_handle_scopes_queries_to_the_dispatched_tenant_and_clears_context_after(): void
    {
        $this->connectAssignee();
        Http::fake(['www.googleapis.com/calendar/v3/*' => Http::response(['id' => 'evt_new'])]);
        $context = app(CurrentTenant::class);

        (new SyncWorkItemCalendarEventJob(
            tenantId: $this->tenant->id, action: 'upsert', workItemId: $this->item->id,
        ))->handle($context, app(\App\Services\GoogleCalendarClient::class));

        $this->assertFalse($context->check());
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact tests/Feature/SyncWorkItemCalendarEventJobTest.php`
Expected: FAIL — class `App\Jobs\SyncWorkItemCalendarEventJob` not found

- [ ] **Step 3: Write the job**

```php
<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\GoogleCalendarConnection;
use App\Models\Tenant;
use App\Models\WorkItem;
use App\Services\GoogleCalendarClient;
use App\Tenancy\CurrentTenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Create/update/delete a work item's Google Calendar event. Takes scalars, not
 * an Eloquent WorkItem instance: on delete-after-reassignment or delete-after-
 * destroy the model may already be gone or already carry the NEW assignee by
 * the time this runs, so the caller (WorkItemObserver) captures whatever it
 * needs at dispatch time instead of relying on a serialized model.
 *
 * Tenant-aware like the queued digest commands: WorkItem/GoogleCalendarConnection
 * queries need CurrentTenant set explicitly because a queued job runs outside the
 * request lifecycle that normally resolves it.
 */
class SyncWorkItemCalendarEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $tenantId,
        public readonly string $action,
        public readonly ?int $workItemId = null,
        public readonly ?int $userId = null,
        public readonly ?string $googleEventId = null,
    ) {}

    public function handle(CurrentTenant $context, GoogleCalendarClient $client): void
    {
        $tenant = Tenant::find($this->tenantId);
        if (! $tenant) {
            return;
        }

        // Restore whatever was active before, not null — on the `sync` queue driver
        // (used in tests, and possibly locally) this job runs INLINE inside the
        // request that dispatched it, so blindly nulling context here would wipe
        // the request's own tenant scope out from under it after this job returns.
        $previous = $context->get();
        $context->set($tenant);

        try {
            $this->action === 'delete' ? $this->runDelete($client) : $this->runUpsert($client);
        } finally {
            $context->set($previous);
        }
    }

    private function runUpsert(GoogleCalendarClient $client): void
    {
        $item = WorkItem::find($this->workItemId);
        if (! $item || ! $item->due_at || $item->archived_at !== null || $item->status === 'done') {
            return;
        }

        $userId = $item->employee?->user_id;
        if (! $userId) {
            return;
        }

        $connection = GoogleCalendarConnection::where('user_id', $userId)->first();
        if (! $connection) {
            return;
        }

        $eventId = $client->createOrUpdateEvent($item, $connection);
        $item->update(['google_event_id' => $eventId]);
    }

    private function runDelete(GoogleCalendarClient $client): void
    {
        if (! $this->userId || ! $this->googleEventId) {
            return;
        }

        $connection = GoogleCalendarConnection::where('user_id', $this->userId)->first();
        if ($connection) {
            $client->deleteEvent($this->googleEventId, $connection);
        }

        if ($this->workItemId) {
            // Scoped by the event id we just deleted, not just the item id — if a
            // later upsert already wrote a newer event id onto this row, don't clobber it.
            WorkItem::where('id', $this->workItemId)
                ->where('google_event_id', $this->googleEventId)
                ->update(['google_event_id' => null]);
        }
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact tests/Feature/SyncWorkItemCalendarEventJobTest.php`
Expected: PASS

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Jobs/SyncWorkItemCalendarEventJob.php tests/Feature/SyncWorkItemCalendarEventJobTest.php
git commit -m "feat(calendar): add SyncWorkItemCalendarEventJob"
```

---

## Task 6: `WorkItemObserver`

**Files:**
- Create: `app/Observers/WorkItemObserver.php`
- Modify: `app/Providers/AppServiceProvider.php` (register the observer in `boot()`)
- Test: `tests/Feature/WorkItemObserverTest.php`

**Interfaces:**
- Consumes: `SyncWorkItemCalendarEventJob` (Task 5), `App\Models\Employee`, `App\Models\WorkItem`.
- Produces: automatic dispatch behavior — no new public interface consumed elsewhere.

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Feature;

use App\Jobs\SyncWorkItemCalendarEventJob;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class WorkItemObserverTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Employee $employee;

    private Employee $otherEmployee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);

        $user = User::create(['name' => 'Demo', 'email' => 'demo@example.com', 'password' => Hash::make('password')]);
        $user->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $this->employee = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'name' => 'Demo', 'status' => 'active', 'workload' => 'green',
        ]);

        $other = User::create(['name' => 'Other', 'email' => 'other@example.com', 'password' => Hash::make('password')]);
        $other->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $this->otherEmployee = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $other->id,
            'name' => 'Other', 'status' => 'active', 'workload' => 'green',
        ]);
    }

    private function card(array $attrs = []): WorkItem
    {
        return $this->employee->workItems()->create(array_merge([
            'tenant_id' => $this->tenant->id, 'title' => 'X', 'type' => 'task',
            'priority' => 'low', 'status' => 'todo', 'progress' => 0,
        ], $attrs));
    }

    public function test_creating_a_card_with_a_due_date_and_assignee_dispatches_an_upsert(): void
    {
        Bus::fake();

        $card = $this->card(['due_at' => '2026-09-30']);

        Bus::assertDispatched(SyncWorkItemCalendarEventJob::class, fn ($job) => $job->action === 'upsert'
            && $job->workItemId === $card->id && $job->tenantId === $this->tenant->id);
    }

    public function test_creating_a_card_without_a_due_date_dispatches_nothing(): void
    {
        Bus::fake();

        $this->card();

        Bus::assertNotDispatched(SyncWorkItemCalendarEventJob::class);
    }

    public function test_changing_due_date_dispatches_an_upsert(): void
    {
        $card = $this->card();
        Bus::fake();

        $card->update(['due_at' => '2026-10-15']);

        Bus::assertDispatched(SyncWorkItemCalendarEventJob::class, fn ($job) => $job->action === 'upsert');
    }

    public function test_changing_unrelated_fields_dispatches_nothing(): void
    {
        $card = $this->card(['due_at' => '2026-09-30']);
        Bus::fake();

        $card->update(['priority' => 'high']);

        Bus::assertNotDispatched(SyncWorkItemCalendarEventJob::class);
    }

    public function test_clearing_due_date_dispatches_a_delete_for_the_existing_event(): void
    {
        $card = $this->card(['due_at' => '2026-09-30', 'google_event_id' => 'evt_1']);
        Bus::fake();

        $card->update(['due_at' => null]);

        Bus::assertDispatched(SyncWorkItemCalendarEventJob::class, fn ($job) => $job->action === 'delete'
            && $job->googleEventId === 'evt_1' && $job->userId === $this->employee->user_id);
    }

    public function test_marking_done_dispatches_a_delete(): void
    {
        $card = $this->card(['due_at' => '2026-09-30', 'google_event_id' => 'evt_1']);
        Bus::fake();

        $card->update(['status' => 'done', 'done_at' => now()]);

        Bus::assertDispatched(SyncWorkItemCalendarEventJob::class, fn ($job) => $job->action === 'delete');
    }

    public function test_archiving_dispatches_a_delete(): void
    {
        $card = $this->card(['due_at' => '2026-09-30', 'google_event_id' => 'evt_1', 'status' => 'done', 'done_at' => now()]);
        Bus::fake();

        $card->update(['archived_at' => now()]);

        Bus::assertDispatched(SyncWorkItemCalendarEventJob::class, fn ($job) => $job->action === 'delete');
    }

    public function test_reassigning_deletes_from_the_old_assignees_calendar_and_upserts_for_the_new_one(): void
    {
        $card = $this->card(['due_at' => '2026-09-30', 'google_event_id' => 'evt_1']);
        Bus::fake();

        $card->update(['employee_id' => $this->otherEmployee->id]);

        Bus::assertDispatched(SyncWorkItemCalendarEventJob::class, fn ($job) => $job->action === 'delete'
            && $job->googleEventId === 'evt_1' && $job->userId === $this->employee->user_id);
        Bus::assertDispatched(SyncWorkItemCalendarEventJob::class, fn ($job) => $job->action === 'upsert'
            && $job->workItemId === $card->id);
    }

    public function test_deleting_a_card_with_a_synced_event_dispatches_a_delete(): void
    {
        $card = $this->card(['due_at' => '2026-09-30', 'google_event_id' => 'evt_1']);
        Bus::fake();

        $card->delete();

        Bus::assertDispatched(SyncWorkItemCalendarEventJob::class, fn ($job) => $job->action === 'delete'
            && $job->googleEventId === 'evt_1' && $job->userId === $this->employee->user_id);
    }

    public function test_deleting_a_card_with_no_synced_event_dispatches_nothing(): void
    {
        $card = $this->card();
        Bus::fake();

        $card->delete();

        Bus::assertNotDispatched(SyncWorkItemCalendarEventJob::class);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact tests/Feature/WorkItemObserverTest.php`
Expected: FAIL — observer not registered, so nothing dispatches

- [ ] **Step 3: Write the observer**

```php
<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\SyncWorkItemCalendarEventJob;
use App\Models\Employee;
use App\Models\WorkItem;

/**
 * Detects the WorkItem changes that matter for Google Calendar sync and
 * dispatches SyncWorkItemCalendarEventJob accordingly. Only employee_id,
 * due_at, archived_at and status are watched — an edit to title/priority/etc.
 * with none of those changed does not re-sync (the calendar event's summary
 * can go stale on a title-only edit; out of scope per the spec's trigger list).
 */
class WorkItemObserver
{
    public function saved(WorkItem $item): void
    {
        $relevant = $item->wasRecentlyCreated
            || $item->wasChanged(['employee_id', 'due_at', 'archived_at', 'status']);

        if (! $relevant) {
            return;
        }

        $reassigned = ! $item->wasRecentlyCreated && $item->wasChanged('employee_id');

        if ($reassigned) {
            $this->deleteFromOldAssignee($item);
        }

        $syncable = $item->due_at !== null && $item->archived_at === null && $item->status !== 'done';

        if (! $syncable) {
            if (! $reassigned && $item->google_event_id) {
                $this->deleteCurrentEvent($item);
            }

            return;
        }

        SyncWorkItemCalendarEventJob::dispatch(
            tenantId: $item->tenant_id,
            action: 'upsert',
            workItemId: $item->id,
        );
    }

    public function deleted(WorkItem $item): void
    {
        if (! $item->google_event_id) {
            return;
        }

        $this->deleteCurrentEvent($item);
    }

    private function deleteFromOldAssignee(WorkItem $item): void
    {
        $oldEventId = $item->getOriginal('google_event_id');
        $oldEmployeeId = $item->getOriginal('employee_id');

        if (! $oldEventId || ! $oldEmployeeId) {
            return;
        }

        $oldUserId = Employee::withoutGlobalScope('tenant')->find($oldEmployeeId)?->user_id;
        if (! $oldUserId) {
            return;
        }

        SyncWorkItemCalendarEventJob::dispatch(
            tenantId: $item->tenant_id,
            action: 'delete',
            workItemId: $item->id,
            userId: $oldUserId,
            googleEventId: $oldEventId,
        );
    }

    private function deleteCurrentEvent(WorkItem $item): void
    {
        $userId = Employee::withoutGlobalScope('tenant')->find($item->employee_id)?->user_id;
        if (! $userId) {
            return;
        }

        SyncWorkItemCalendarEventJob::dispatch(
            tenantId: $item->tenant_id,
            action: 'delete',
            workItemId: $item->id,
            userId: $userId,
            googleEventId: $item->google_event_id,
        );
    }
}
```

- [ ] **Step 4: Register the observer**

In `app/Providers/AppServiceProvider.php::boot()`, add near the top of the method:

```php
        WorkItem::observe(WorkItemObserver::class);
```

Add imports at the top of the file: `use App\Models\WorkItem;` and `use App\Observers\WorkItemObserver;`.

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --compact tests/Feature/WorkItemObserverTest.php`
Expected: PASS

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Observers/WorkItemObserver.php app/Providers/AppServiceProvider.php tests/Feature/WorkItemObserverTest.php
git commit -m "feat(calendar): observe work items and dispatch calendar sync jobs"
```

---

## Task 7: Full suite check

**Files:** none (verification only)

- [ ] **Step 1: Run the full test suite**

Run: `php artisan test --compact`
Expected: PASS, no regressions in existing `WorkItem*` tests (the observer must not break `WorkItemDueDateTest`, `WorkItemArchiveTest`, etc. — those tests don't fake the bus, so the observer's `SyncWorkItemCalendarEventJob::dispatch()` calls will actually queue jobs against the `database` queue connection in tests; confirm this doesn't error since nothing processes the queue synchronously in feature tests).

- [ ] **Step 2: If any pre-existing test now fails because a real job got queued and something asserts queue table state, add `Queue::fake()` to that test's `setUp()`**

Not expected, given `QUEUE_CONNECTION=database` only inserts a row and never runs the job inline — but check.

- [ ] **Step 3: Migrate the dev database**

Run: `php artisan migrate` (both new migrations are additive — a new table, a nullable column — safe against the shared dev DB the worktree uses). Green tests do not mean the dev DB is migrated; skipping this step 500s the app on next load, per this repo's own gotcha.

- [ ] **Step 4: Manually verify on the worktree's lerd vhost**

The credentials aren't set yet (per the spec, the user supplies `GOOGLE_CALENDAR_CLIENT_ID`/`SECRET` later), so the "Connect" button will 404 until then. Verify instead:
- The Work & Tasks tab renders normally for `$isOwn` and non-`$isOwn` views (no button leaking to a viewer looking at someone else's profile).
- `php artisan route:list --name=google-calendar` shows the 3 new routes.

---

## Known Limitation

The observer re-dispatches an upsert on every `status` change, not only a
flip into/out of `done` — a board drag from `todo` to `prog` to `review`
re-PATCHes the same event to Google with identical content each time.
Harmless (idempotent, same payload), just a few redundant API calls. Not
worth tightening unless it shows up as real traffic.

## Self-Review Notes

- **Spec coverage:** connection table + encrypted tokens (Task 1), `google_event_id` (Task 2), OAuth client with offline+consent scope (Task 3), connect/callback/disconnect + profile UI gated to the owner (Task 4), create/update/delete event logic incl. exclusive end date (Task 3+5), tenant context in the job (Task 5), observer covering assign/due-date/done/archive/delete/reassign (Task 6). All spec requirements have a task.
- **Type consistency checked:** `SyncWorkItemCalendarEventJob`'s constructor param names (`tenantId`, `action`, `workItemId`, `userId`, `googleEventId`) are used identically across Task 5 (definition) and Task 6 (dispatch calls + test assertions).
- **No placeholders:** every step has real code.
