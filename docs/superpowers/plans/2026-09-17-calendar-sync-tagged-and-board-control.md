# Calendar sync for tagged people + board sync control — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Mirror T.A.A. cards into the Google Calendar of every Helper/FYI on them (not just the owner), and replace the Profile calendar section with a rate-limited status + Sync control on the task board.

**Architecture:** Owner mirroring stays on `work_items` columns. A new `work_item_calendar_copies` table holds one row per tagged person per card. One class (`TaggedCopies`) decides who gets a copy and dispatches the existing `SyncWorkItemCalendarEventJob` with a new `recipientEmployeeId`. A custom pivot model fires on every tag/untag. A `CalendarFullSyncJob` pushes everything for one user and records progress in cache; a small JSON controller exposes status/sync/retry behind a per-user 2-minute limiter; an Alpine component on the board renders it.

**Tech Stack:** Laravel 13, PHP 8.5, PHPUnit 12, Alpine 3, bun/Vite.

**Spec:** `docs/superpowers/specs/2026-09-17-calendar-sync-tagged-and-board-control-design.md`. Mockup: `docs/build/design/calendar-sync/index.html`.

## Global Constraints

- Tagged recipients = participants with pivot role `helper`, `fyi` or null, never the card owner. Reviewer is not a recipient.
- Owner columns `work_items.google_event_id`, `calendar_version`, `calendar_sync_error` keep their meaning. Never edit `tests/Acceptance/*` (CR11Test reads those columns).
- Company-event cards (`company_event_id` not null) never get tagged copies.
- Rate limit: 1 sync per user per 120 s, key `calendar-sync:{userId}`, shared by Sync now and Retry. 429 body carries `retry_after` (seconds).
- Revoked = Google token refresh answered `error: invalid_grant`. Revoked connections are skipped by pushes and pulls.
- No full-page reloads for board actions (JSON + Alpine). Bilingual EN/BM copy via `$store.ui.lang`.
- Tests: `php artisan test --compact <file>` on host (sqlite, `QUEUE_CONNECTION=sync`, `CACHE_STORE=array`). Never run `migrate:fresh` or phpunit with `--no-configuration` (wipes dev MySQL).
- Dev DB migration: `lerd artisan migrate` only.
- Format: `vendor/bin/pint --dirty --format agent`. Assets: `lerd artisan view:clear && lerd artisan view:cache && bun run build`, commit `public/build`.
- Commit on `dev`, do not push.

---

### Task 1: Data layer — copies table, revoked flag, models, recipient rule

**Files:**
- Create: `database/migrations/2026_09_26_100000_create_work_item_calendar_copies_table.php`
- Create: `app/Models/WorkItemCalendarCopy.php`
- Create: `app/Models/WorkItemParticipant.php`
- Modify: `app/Models/WorkItem.php` (participants relation ~line 353; add `calendarCopies()`)
- Modify: `app/Models/GoogleCalendarConnection.php` (cast + phpdoc)
- Create: `app/Support/Calendar/TaggedCopies.php` (only `recipients()` in this task)
- Test: `tests/Feature/CalendarTaggedCopiesTest.php`

**Interfaces:**
- Produces: table `work_item_calendar_copies(id, tenant_id, work_item_id, employee_id, google_event_id?, calendar_version?, sync_error?, timestamps, unique(work_item_id, employee_id))`; column `google_calendar_connections.revoked_at`; `WorkItemCalendarCopy` (guarded `[]`); `WorkItem::calendarCopies(): HasMany`; `WorkItemParticipant extends Pivot` (events wired in Task 3); `TaggedCopies::recipients(WorkItem $item): Collection<int, Employee>`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\GoogleCalendarConnection;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkItem;
use App\Ports\CalendarPort;
use App\Ports\Data\CalendarEvent;
use App\Ports\PortResult;
use App\Support\Calendar\TaggedCopies;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Tagged Helper/FYI people get their own copy of a card in their Google Calendar. */
class CalendarTaggedCopiesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Employee $owner;

    private Employee $helper;

    private RecordingCalendarPort $port;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $this->owner = $this->person('Owner', 'owner@example.com');
        $this->helper = $this->person('Helper', 'helper@example.com');
        $this->port = new RecordingCalendarPort;
        $this->app->instance(CalendarPort::class, $this->port);
        app(CurrentTenant::class)->set($this->tenant);
    }

    protected function tearDown(): void
    {
        app(CurrentTenant::class)->set(null);
        parent::tearDown();
    }

    private function person(string $name, string $email, bool $connected = true): Employee
    {
        $user = User::create(['name' => $name, 'email' => $email, 'password' => Hash::make('password')]);
        $user->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        if ($connected) {
            GoogleCalendarConnection::create(['user_id' => $user->id, 'access_token' => 't', 'refresh_token' => 'r', 'expires_at' => now()->addHour()]);
        }

        return Employee::create(['tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'name' => $name, 'status' => 'active', 'workload' => 'green']);
    }

    private function card(array $attrs = []): WorkItem
    {
        return $this->owner->workItems()->create($attrs + [
            'tenant_id' => $this->tenant->id, 'title' => 'Budget', 'type' => 'task',
            'priority' => 'medium', 'status' => 'todo', 'progress' => 0, 'due_at' => '2026-10-05',
        ]);
    }

    public function test_recipients_are_helpers_and_fyi_but_not_the_owner_or_a_reviewer(): void
    {
        $fyi = $this->person('Fyi', 'fyi@example.com');
        $reviewer = $this->person('Reviewer', 'reviewer@example.com');
        $card = $this->card(['reviewer_id' => $reviewer->id]);
        $card->participants()->attach([
            $this->helper->id => ['role' => 'helper'],
            $fyi->id => ['role' => 'fyi'],
            $this->owner->id => ['role' => 'helper'],
        ]);

        $ids = TaggedCopies::recipients($card->fresh())->pluck('id')->sort()->values()->all();

        $this->assertSame(collect([$this->helper->id, $fyi->id])->sort()->values()->all(), $ids);
    }

    public function test_company_event_cards_have_no_tagged_recipients(): void
    {
        $card = $this->card(['type' => 'event']);
        WorkItem::withoutGlobalScopes()->where('id', $card->id)->update(['company_event_id' => 999]);
        $card->participants()->attach($this->helper->id, ['role' => 'helper']);

        $this->assertCount(0, TaggedCopies::recipients($card->fresh()));
    }

    public function test_a_connection_can_be_marked_revoked(): void
    {
        $connection = GoogleCalendarConnection::where('user_id', $this->helper->user_id)->first();
        $connection->forceFill(['revoked_at' => now()])->save();

        $this->assertNotNull($connection->fresh()->revoked_at);
    }
}

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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact tests/Feature/CalendarTaggedCopiesTest.php`
Expected: FAIL, `Class "App\Support\Calendar\TaggedCopies" not found` / unknown column `revoked_at`.

- [ ] **Step 3: Implement**

Migration (`php artisan make:migration create_work_item_calendar_copies_table --no-interaction`, then rename the file to `2026_09_26_100000_create_work_item_calendar_copies_table.php` so it sorts after `2026_09_25_100000_add_two_way_calendar_sync_columns.php`):

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A tagged Helper/FYI's own copy of a card in their Google Calendar. The owner's entry
 * stays on work_items; this holds everyone else's, one row per person per card.
 * `revoked_at` marks a connection Google has stopped honouring (invalid_grant).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_item_calendar_copies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('google_event_id')->nullable();
            $table->string('calendar_version', 64)->nullable();
            $table->string('sync_error', 500)->nullable();
            $table->timestamps();
            $table->unique(['work_item_id', 'employee_id']);
            $table->index(['employee_id', 'google_event_id']);
        });

        Schema::table('google_calendar_connections', function (Blueprint $table) {
            $table->timestamp('revoked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('google_calendar_connections', function (Blueprint $table) {
            $table->dropColumn('revoked_at');
        });
        Schema::dropIfExists('work_item_calendar_copies');
    }
};
```

`app/Models/WorkItemCalendarCopy.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One tagged person's copy of a card in their Google Calendar. Not tenant-scoped via
 * the trait: it is written from queued jobs that run without a tenant context, and
 * always looked up by work_item_id / employee_id.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $work_item_id
 * @property int $employee_id
 * @property string|null $google_event_id
 * @property string|null $calendar_version
 * @property string|null $sync_error
 */
class WorkItemCalendarCopy extends Model
{
    protected $guarded = [];

    /** @return BelongsTo<WorkItem, $this> */
    public function workItem(): BelongsTo
    {
        return $this->belongsTo(WorkItem::class)->withoutGlobalScopes();
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withoutGlobalScopes();
    }
}
```

`app/Models/WorkItemParticipant.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * The work_item_participant row as a model, so tagging and untagging fire events no
 * matter which of the many call sites writes the pivot (see Task 3 booted()).
 *
 * @property int $work_item_id
 * @property int $employee_id
 * @property string|null $role
 */
class WorkItemParticipant extends Pivot
{
    protected $table = 'work_item_participant';
}
```

In `app/Models/WorkItem.php`, change the `participants()` body and add a relation below it:

```php
    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'work_item_participant')
            ->using(WorkItemParticipant::class)
            ->withPivot('role');
    }

    /**
     * Tagged people's own calendar entries for this card (the owner's lives on this row).
     *
     * @return HasMany<WorkItemCalendarCopy, $this>
     */
    public function calendarCopies(): HasMany
    {
        return $this->hasMany(WorkItemCalendarCopy::class);
    }
```

In `app/Models/GoogleCalendarConnection.php` add `'revoked_at' => 'datetime',` to `casts()` and `@property Carbon|null $revoked_at` to the docblock.

`app/Support/Calendar/TaggedCopies.php`:

```php
<?php

declare(strict_types=1);

namespace App\Support\Calendar;

use App\Models\Employee;
use App\Models\WorkItem;
use Illuminate\Support\Collection;

/**
 * Who, besides the owner, gets a card in their Google Calendar, and keeping their
 * copies in step. Tagged Helper and FYI people qualify; the Reviewer does not, and
 * company-event cards are mirrored per attendee by EventController instead.
 */
final class TaggedCopies
{
    /** Pivot roles that earn a calendar copy. Null is the historical default (helper). */
    public const ROLES = ['helper', 'fyi', null];

    /** @return Collection<int, Employee> */
    public static function recipients(WorkItem $item): Collection
    {
        if ($item->company_event_id !== null) {
            return collect();
        }

        return $item->participants()->withoutGlobalScopes()->get()
            ->filter(fn (Employee $e) => $e->id !== $item->employee_id
                && in_array($e->pivot->role, self::ROLES, true))
            ->values();
    }
}
```

- [ ] **Step 4: Run tests**

Run: `php artisan test --compact tests/Feature/CalendarTaggedCopiesTest.php tests/Feature/WorkItemObserverTest.php tests/Feature/CalendarTwoWaySyncTest.php`
Expected: PASS. If `participants()->withoutGlobalScopes()` errors, drop it (Employee's tenant scope is fine inside the test's tenant context) and re-run.

- [ ] **Step 5: Migrate dev DB and commit**

```bash
lerd artisan migrate
vendor/bin/pint --dirty --format agent
git add database/migrations/2026_09_26_100000_create_work_item_calendar_copies_table.php app/Models/WorkItemCalendarCopy.php app/Models/WorkItemParticipant.php app/Models/WorkItem.php app/Models/GoogleCalendarConnection.php app/Support/Calendar/TaggedCopies.php tests/Feature/CalendarTaggedCopiesTest.php
git commit -m "feat(calendar): store tagged people's calendar copies and revoked connections"
```

---

### Task 2: Sync job pushes to a tagged recipient; revoked connections are skipped

**Files:**
- Modify: `app/Jobs/SyncWorkItemCalendarEventJob.php`
- Modify: `app/Support/Calendar/CalendarMirror.php` (`event()` signature)
- Test: `tests/Feature/CalendarTaggedCopiesTest.php` (append)

**Interfaces:**
- Consumes: `WorkItemCalendarCopy`, `TaggedCopies::recipients()`, `revoked_at`.
- Produces: `new SyncWorkItemCalendarEventJob(tenantId, action, workItemId, userId, googleEventId, recipientEmployeeId)` — `recipientEmployeeId` null = owner (unchanged behaviour). `CalendarMirror::event(WorkItem $item, ?WorkItemCalendarCopy $copy = null): CalendarEvent`. Public `SyncWorkItemCalendarEventJob::failed(?Throwable $e)` records the error on the copy when a recipient is set.

- [ ] **Step 1: Append failing tests**

```php
    private function runJob(string $action, WorkItem $card, ?Employee $recipient = null, ?string $eventId = null): void
    {
        (new \App\Jobs\SyncWorkItemCalendarEventJob(
            tenantId: $this->tenant->id, action: $action, workItemId: $card->id,
            userId: $recipient?->user_id, googleEventId: $eventId, recipientEmployeeId: $recipient?->id,
        ))->handle(app(CurrentTenant::class), app(CalendarPort::class));
    }

    public function test_upsert_for_a_tagged_recipient_writes_their_copy_not_the_card(): void
    {
        $card = $this->card();
        WorkItem::withoutGlobalScopes()->where('id', $card->id)->update(['google_event_id' => 'owner-evt']);
        \Illuminate\Support\Facades\DB::table('work_item_participant')->insert(['work_item_id' => $card->id, 'employee_id' => $this->helper->id, 'role' => 'helper']);
        $this->port->upserts = [];

        $this->runJob('upsert', $card, $this->helper);

        $this->assertSame([$this->helper->id], $this->port->pushedTo());
        $this->assertNull($this->port->upserts[0]['event']->externalId, 'a copy never reuses the owner event id');
        $copy = \App\Models\WorkItemCalendarCopy::where('work_item_id', $card->id)->where('employee_id', $this->helper->id)->first();
        $this->assertStringStartsWith("evt-{$this->helper->id}-", $copy->google_event_id);
        $this->assertSame('owner-evt', $card->fresh()->google_event_id);
    }

    public function test_upsert_for_someone_no_longer_tagged_does_nothing(): void
    {
        $card = $this->card();
        $this->port->upserts = [];

        $this->runJob('upsert', $card, $this->helper);

        $this->assertSame([], $this->port->upserts);
    }

    public function test_delete_for_a_recipient_removes_their_copy_only(): void
    {
        $card = $this->card();
        WorkItem::withoutGlobalScopes()->where('id', $card->id)->update(['google_event_id' => 'owner-evt']);
        \App\Models\WorkItemCalendarCopy::create(['tenant_id' => $this->tenant->id, 'work_item_id' => $card->id, 'employee_id' => $this->helper->id, 'google_event_id' => 'copy-evt']);

        $this->runJob('delete', $card, $this->helper, 'copy-evt');

        $this->assertSame([['employee' => $this->helper->id, 'id' => 'copy-evt']], $this->port->deletes);
        $this->assertDatabaseMissing('work_item_calendar_copies', ['work_item_id' => $card->id, 'employee_id' => $this->helper->id]);
        $this->assertSame('owner-evt', $card->fresh()->google_event_id);
    }

    public function test_giving_up_on_a_copy_records_the_error_on_the_copy(): void
    {
        $card = $this->card();
        \App\Models\WorkItemCalendarCopy::create(['tenant_id' => $this->tenant->id, 'work_item_id' => $card->id, 'employee_id' => $this->helper->id]);

        (new \App\Jobs\SyncWorkItemCalendarEventJob(tenantId: $this->tenant->id, action: 'upsert', workItemId: $card->id, recipientEmployeeId: $this->helper->id))
            ->failed(new \RuntimeException('Google said no'));

        $this->assertSame('Google said no', \App\Models\WorkItemCalendarCopy::first()->sync_error);
        $this->assertNull($card->fresh()->calendar_sync_error);
    }

    public function test_a_revoked_connection_is_not_pushed_to(): void
    {
        GoogleCalendarConnection::where('user_id', $this->owner->user_id)->update(['revoked_at' => now()]);
        $this->port->upserts = [];

        $card = $this->card();
        $this->runJob('upsert', $card);

        $this->assertSame([], $this->port->upserts);
    }
```

- [ ] **Step 2: Run to see them fail**

Run: `php artisan test --compact tests/Feature/CalendarTaggedCopiesTest.php`
Expected: FAIL, unknown named parameter `recipientEmployeeId`.

- [ ] **Step 3: Implement**

`CalendarMirror::event` — change signature and the two fields:

```php
    public static function event(WorkItem $item, ?WorkItemCalendarCopy $copy = null): CalendarEvent
    {
        // ...existing $start/$end block unchanged...

        return new CalendarEvent(
            title: $item->title,
            startsAt: $start,
            endsAt: $end,
            description: self::description($item),
            subject: $item,
            externalId: $copy ? $copy->google_event_id : $item->google_event_id,
            allDay: $companyEvent === null,
            version: $copy ? $copy->calendar_version : $item->calendar_version,
        );
    }
```

(add `use App\Models\WorkItemCalendarCopy;`)

`SyncWorkItemCalendarEventJob` — full new body of the changed parts:

```php
    public function __construct(
        public readonly int $tenantId,
        public readonly string $action,
        public readonly ?int $workItemId = null,
        public readonly ?int $userId = null,
        public readonly ?string $googleEventId = null,
        /** A tagged person's copy instead of the owner's entry. Null = the owner. */
        public readonly ?int $recipientEmployeeId = null,
    ) {}

    public function uniqueId(): string
    {
        return "{$this->action}:{$this->workItemId}:{$this->userId}:{$this->googleEventId}:{$this->recipientEmployeeId}";
    }

    public function failed(?Throwable $e): void
    {
        if (! $this->workItemId) {
            return;
        }
        $message = mb_substr($e?->getMessage() ?? 'Calendar sync failed', 0, 500);

        if ($this->recipientEmployeeId !== null) {
            WorkItemCalendarCopy::where('work_item_id', $this->workItemId)
                ->where('employee_id', $this->recipientEmployeeId)
                ->update(['sync_error' => $message]);

            return;
        }

        WorkItem::withoutGlobalScopes()->where('id', $this->workItemId)->update(['calendar_sync_error' => $message]);
    }

    private function runUpsert(CalendarPort $port): void
    {
        $item = WorkItem::withoutGlobalScopes()->find($this->workItemId);
        if (! $item || ! CalendarMirror::syncable($item)) {
            return;
        }

        if ($this->recipientEmployeeId !== null) {
            $this->upsertCopy($port, $item);

            return;
        }

        // ...existing owner code unchanged from `$employee = Employee::...` down...
    }

    private function upsertCopy(CalendarPort $port, WorkItem $item): void
    {
        $employee = Employee::withoutGlobalScope('tenant')->find($this->recipientEmployeeId);
        if (! $employee?->user_id || ! $this->connected($employee)) {
            return;
        }
        // Untagged between dispatch and run: nothing to send.
        if (! TaggedCopies::recipients($item)->contains('id', $employee->id)) {
            return;
        }

        $copy = WorkItemCalendarCopy::firstOrNew(
            ['work_item_id' => $item->id, 'employee_id' => $employee->id],
            ['tenant_id' => $item->tenant_id],
        );

        $result = $port->upsertEvent($employee, CalendarMirror::event($item, $copy));
        if (! $result->ok) {
            throw new RuntimeException("Calendar push failed (outbox #{$result->outboxId}).");
        }

        $copy->fill([
            'google_event_id' => $result->externalId,
            'calendar_version' => $result->payload['version'] ?? null,
            'sync_error' => null,
        ])->save();
    }

    private function runDelete(CalendarPort $port): void
    {
        if (! $this->userId || ! $this->googleEventId) {
            return;
        }

        $employee = Employee::withoutGlobalScope('tenant')->where('user_id', $this->userId)->where('tenant_id', $this->tenantId)->first();
        if ($employee && $this->connected($employee)) {
            $result = $port->deleteEvent($employee, $this->googleEventId);
            if (! $result->ok) {
                throw new RuntimeException("Calendar delete failed (outbox #{$result->outboxId}).");
            }
        }

        if ($this->recipientEmployeeId !== null) {
            // Only the row still holding the event we just removed: a re-tag may have
            // written a newer one.
            WorkItemCalendarCopy::where('work_item_id', $this->workItemId)
                ->where('employee_id', $this->recipientEmployeeId)
                ->where('google_event_id', $this->googleEventId)
                ->delete();

            return;
        }

        // ...existing `if ($this->workItemId) { ... }` block unchanged...
    }

    /** No live connection, nothing to mirror: silently done, not a failure to retry. */
    private function connected(Employee $employee): bool
    {
        return GoogleCalendarConnection::where('user_id', $employee->user_id)->whereNull('revoked_at')->exists();
    }
```

(add `use App\Models\WorkItemCalendarCopy;` and `use App\Support\Calendar\TaggedCopies;`)

- [ ] **Step 4: Run**

Run: `php artisan test --compact tests/Feature/CalendarTaggedCopiesTest.php tests/Feature/SyncWorkItemCalendarEventJobTest.php tests/Feature/CalendarTwoWaySyncTest.php tests/Acceptance/CR11Test.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Jobs/SyncWorkItemCalendarEventJob.php app/Support/Calendar/CalendarMirror.php tests/Feature/CalendarTaggedCopiesTest.php
git commit -m "feat(calendar): sync job can push a tagged person's copy and skips revoked access"
```

---

### Task 3: Keep copies in step — card edits, tag/untag, card delete

**Files:**
- Modify: `app/Support/Calendar/TaggedCopies.php` (add `sync`, `pushOne`, `removeFor`, `remove`)
- Modify: `app/Models/WorkItemParticipant.php` (`booted()`)
- Modify: `app/Observers/WorkItemObserver.php`
- Test: `tests/Feature/CalendarTaggedCopiesTest.php` (append)

**Interfaces:**
- Consumes: Task 2 job signature.
- Produces: `TaggedCopies::sync(WorkItem $item): void` (push every recipient when syncable, remove copies for everyone else); `TaggedCopies::pushOne(WorkItem $item, int $employeeId): void`; `TaggedCopies::removeFor(int $workItemId, int $employeeId): void`; `TaggedCopies::remove(WorkItemCalendarCopy $copy): void`.

- [ ] **Step 1: Append failing tests**

```php
    public function test_tagging_a_helper_sends_them_the_card_and_untagging_removes_it(): void
    {
        $card = $this->card();
        $this->port->upserts = [];

        $card->participants()->attach($this->helper->id, ['role' => 'helper']);
        $this->assertSame([$this->helper->id], $this->port->pushedTo());
        $eventId = \App\Models\WorkItemCalendarCopy::where('employee_id', $this->helper->id)->value('google_event_id');

        $card->participants()->detach($this->helper->id);
        $this->assertSame([['employee' => $this->helper->id, 'id' => $eventId]], $this->port->deletes);
        $this->assertDatabaseCount('work_item_calendar_copies', 0);
    }

    public function test_sync_through_the_relation_fires_for_every_added_and_removed_person(): void
    {
        $fyi = $this->person('Fyi', 'fyi@example.com');
        $card = $this->card();
        $card->participants()->sync([$this->helper->id => ['role' => 'helper']]);
        $this->port->upserts = [];

        $card->participants()->sync([$fyi->id => ['role' => 'fyi']]);

        $this->assertSame([$fyi->id], $this->port->pushedTo());
        $this->assertSame([$this->helper->id], array_column($this->port->deletes, 'employee'));
    }

    public function test_a_title_change_updates_the_owner_and_every_tagged_copy(): void
    {
        $card = $this->card();
        $card->participants()->attach($this->helper->id, ['role' => 'fyi']);
        $this->port->upserts = [];

        $card->update(['title' => 'Budget v2']);

        $this->assertEqualsCanonicalizing([$this->owner->id, $this->helper->id], $this->port->pushedTo());
        foreach ($this->port->upserts as $u) {
            $this->assertSame('Budget v2', $u['event']->title);
        }
    }

    public function test_finishing_a_card_removes_every_copy(): void
    {
        $card = $this->card();
        $card->participants()->attach($this->helper->id, ['role' => 'helper']);

        $card->update(['status' => 'done']);

        $this->assertContains($this->helper->id, array_column($this->port->deletes, 'employee'));
        $this->assertDatabaseCount('work_item_calendar_copies', 0);
    }

    public function test_deleting_a_card_removes_tagged_copies_from_google(): void
    {
        $card = $this->card();
        $card->participants()->attach($this->helper->id, ['role' => 'helper']);
        $eventId = \App\Models\WorkItemCalendarCopy::value('google_event_id');

        $card->delete();

        $this->assertContains(['employee' => $this->helper->id, 'id' => $eventId], $this->port->deletes);
    }

    public function test_an_unconnected_helper_gets_nothing_and_no_error(): void
    {
        $offline = $this->person('Offline', 'offline@example.com', connected: false);
        $card = $this->card();
        $this->port->upserts = [];

        $card->participants()->attach($offline->id, ['role' => 'helper']);

        $this->assertSame([], $this->port->upserts);
    }

    public function test_tagging_someone_on_an_undated_card_sends_nothing(): void
    {
        $card = $this->card(['due_at' => null]);

        $card->participants()->attach($this->helper->id, ['role' => 'helper']);

        $this->assertSame([], $this->port->upserts);
    }
```

- [ ] **Step 2: Run to see them fail**

Run: `php artisan test --compact tests/Feature/CalendarTaggedCopiesTest.php`
Expected: the new tests FAIL (no pushes to the helper).

- [ ] **Step 3: Implement**

Add to `TaggedCopies`:

```php
    /** Bring every tagged copy of this card in line with the card as it is now. */
    public static function sync(WorkItem $item): void
    {
        $recipients = CalendarMirror::syncable($item) ? self::recipients($item)->pluck('id') : collect();

        foreach ($recipients as $employeeId) {
            self::dispatchUpsert($item, $employeeId);
        }

        WorkItemCalendarCopy::where('work_item_id', $item->id)
            ->whereNotIn('employee_id', $recipients->all())
            ->get()
            ->each(fn (WorkItemCalendarCopy $copy) => self::remove($copy));
    }

    /** One person was just tagged. */
    public static function pushOne(WorkItem $item, int $employeeId): void
    {
        if (CalendarMirror::syncable($item) && self::recipients($item)->contains('id', $employeeId)) {
            self::dispatchUpsert($item, $employeeId);
        }
    }

    /** One person was just untagged. */
    public static function removeFor(int $workItemId, int $employeeId): void
    {
        $copy = WorkItemCalendarCopy::where('work_item_id', $workItemId)->where('employee_id', $employeeId)->first();
        if ($copy) {
            self::remove($copy);
        }
    }

    /** Take a copy out of the person's calendar, or just forget it if it never got there. */
    public static function remove(WorkItemCalendarCopy $copy): void
    {
        $userId = Employee::withoutGlobalScope('tenant')->whereKey($copy->employee_id)->value('user_id');
        if (! $copy->google_event_id || ! $userId) {
            $copy->delete();

            return;
        }

        SyncWorkItemCalendarEventJob::dispatch(
            tenantId: $copy->tenant_id,
            action: 'delete',
            workItemId: $copy->work_item_id,
            userId: $userId,
            googleEventId: $copy->google_event_id,
            recipientEmployeeId: $copy->employee_id,
        );
    }

    private static function dispatchUpsert(WorkItem $item, int $employeeId): void
    {
        SyncWorkItemCalendarEventJob::dispatch(
            tenantId: $item->tenant_id,
            action: 'upsert',
            workItemId: $item->id,
            recipientEmployeeId: $employeeId,
        );
    }
```

(imports: `App\Jobs\SyncWorkItemCalendarEventJob`, `App\Models\WorkItemCalendarCopy`.)

`WorkItemParticipant`:

```php
    protected static function booted(): void
    {
        static::created(function (self $pivot): void {
            $item = WorkItem::withoutGlobalScopes()->find($pivot->work_item_id);
            if ($item) {
                TaggedCopies::pushOne($item, (int) $pivot->employee_id);
            }
        });

        static::deleted(function (self $pivot): void {
            TaggedCopies::removeFor((int) $pivot->work_item_id, (int) $pivot->employee_id);
        });
    }
```

(import `App\Support\Calendar\TaggedCopies`.)

`WorkItemObserver::saved` — replace the section from `$syncable = CalendarMirror::syncable($item);` to the end of the method with:

```php
        $syncable = CalendarMirror::syncable($item);

        // Tagged Helper/FYI copies follow the same edits (and leave with the owner's).
        TaggedCopies::sync($item);

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
```

Add a `deleting` hook (the FK cascade removes copy rows before `deleted` runs):

```php
    /** Copies vanish with the card via the FK cascade, so take them out of Google first. */
    public function deleting(WorkItem $item): void
    {
        WorkItemCalendarCopy::where('work_item_id', $item->id)->get()
            ->each(fn (WorkItemCalendarCopy $copy) => TaggedCopies::remove($copy));
    }
```

(imports `App\Models\WorkItemCalendarCopy`, `App\Support\Calendar\TaggedCopies`.)

Check the observer is registered for `deleting`: observers get every method automatically when registered with `WorkItem::observe(...)` / `#[ObservedBy]`. Confirm with `grep -rn "WorkItemObserver" app/Providers app/Models`.

- [ ] **Step 4: Run**

Run: `php artisan test --compact tests/Feature/CalendarTaggedCopiesTest.php tests/Feature/WorkItemObserverTest.php tests/Feature/CalendarTwoWaySyncTest.php tests/Acceptance/CR11Test.php`
Then the other pivot writers: `php artisan test --compact --filter='Tot|OfficeRequest|Recurring|UpdateCard|WorkItem'`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Support/Calendar/TaggedCopies.php app/Models/WorkItemParticipant.php app/Observers/WorkItemObserver.php tests/Feature/CalendarTaggedCopiesTest.php
git commit -m "feat(calendar): tagging, untagging and card edits keep tagged copies in step"
```

---

### Task 4: Changes made in a tagged person's calendar snap back

**Files:**
- Modify: `app/Support/Calendar/CalendarReconciler.php`
- Test: `tests/Feature/CalendarTaggedCopiesTest.php` (append)

**Interfaces:**
- Consumes: `WorkItemCalendarCopy`, `TaggedCopies::pushOne`.

- [ ] **Step 1: Append failing tests**

```php
    private function taggedCopy(): array
    {
        $card = $this->card(['type' => 'event']);
        $card->participants()->attach($this->helper->id, ['role' => 'helper']);
        $copy = \App\Models\WorkItemCalendarCopy::first();
        $this->port->upserts = [];

        return [$card, $copy];
    }

    private function pullFor(Employee $who, CalendarEvent ...$changes): void
    {
        app(\App\Support\Calendar\CalendarReconciler::class)->reconcile($who, $changes);
    }

    public function test_a_tagged_person_moving_their_entry_snaps_it_back_and_leaves_the_card(): void
    {
        [$card, $copy] = $this->taggedCopy();

        $this->pullFor($this->helper, new CalendarEvent('Budget', CarbonImmutable::parse('2026-10-20'), CarbonImmutable::parse('2026-10-21'), externalId: $copy->google_event_id, allDay: true, version: 'moved'));

        $this->assertSame('2026-10-05', $card->fresh()->due_at->toDateString());
        $this->assertSame([$this->helper->id], $this->port->pushedTo());
        $this->assertSame($copy->google_event_id, $this->port->upserts[0]['event']->externalId);
        $this->assertDatabaseCount('work_items', 1);
    }

    public function test_a_tagged_person_deleting_their_entry_gets_it_back(): void
    {
        [$card, $copy] = $this->taggedCopy();

        $this->pullFor($this->helper, new CalendarEvent('Budget', CarbonImmutable::parse('2026-10-05'), CarbonImmutable::parse('2026-10-06'), externalId: $copy->google_event_id, cancelled: true, version: 'gone'));

        $this->assertNull($card->fresh()->cancelled_at, 'the card is not cancelled by a tagged person');
        $this->assertSame([$this->helper->id], $this->port->pushedTo());
        $this->assertNull($this->port->upserts[0]['event']->externalId, 're-created as a new event');
    }

    public function test_the_echo_of_our_own_push_to_a_copy_is_ignored(): void
    {
        [, $copy] = $this->taggedCopy();

        $this->pullFor($this->helper, new CalendarEvent('Budget', CarbonImmutable::parse('2026-10-05'), CarbonImmutable::parse('2026-10-06'), externalId: $copy->google_event_id, version: $copy->calendar_version));

        $this->assertSame([], $this->port->upserts);
    }

    public function test_a_copy_event_is_never_imported_as_a_new_card(): void
    {
        [, $copy] = $this->taggedCopy();

        $this->pullFor($this->helper, new CalendarEvent('Budget', CarbonImmutable::parse('2026-10-05'), CarbonImmutable::parse('2026-10-06'), externalId: $copy->google_event_id, version: 'edited'));

        $this->assertDatabaseCount('work_items', 1);
    }
```

- [ ] **Step 2: Run to see them fail**

Run: `php artisan test --compact tests/Feature/CalendarTaggedCopiesTest.php`
Expected: FAIL (the change is imported as a new Event card).

- [ ] **Step 3: Implement**

In `CalendarReconciler::reconcile`, replace

```php
            if ($card === null) {
                $this->import($for, $change);

                continue;
            }
```

with

```php
            if ($card === null) {
                $copy = WorkItemCalendarCopy::where('employee_id', $for->id)
                    ->where('google_event_id', $change->externalId)
                    ->first();

                $copy ? $this->taggedCopyChanged($copy, $change) : $this->import($for, $change);

                continue;
            }
```

and add:

```php
    /**
     * A tagged person's own copy changed in their calendar. Their calendar never edits
     * the card: a move is pushed back to the card's date, a delete is re-created.
     */
    private function taggedCopyChanged(WorkItemCalendarCopy $copy, CalendarEvent $change): void
    {
        if ($change->version !== null && $change->version === $copy->calendar_version) {
            return; // our own push coming back
        }

        $card = WorkItem::withoutGlobalScopes()->find($copy->work_item_id);
        if ($card === null) {
            return;
        }

        if ($change->cancelled) {
            $copy->update(['google_event_id' => null, 'calendar_version' => null]);
        }

        TaggedCopies::pushOne($card, $copy->employee_id);
    }
```

(imports `App\Models\WorkItemCalendarCopy`, `App\Support\Calendar\TaggedCopies` is same namespace so no import.) Update the class docblock list with one line: `- a tagged person's copy moved or deleted: pushed back / re-created, card untouched;`.

- [ ] **Step 4: Run**

Run: `php artisan test --compact tests/Feature/CalendarTaggedCopiesTest.php tests/Feature/CalendarTwoWaySyncTest.php tests/Acceptance/DateCalendarRulesTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Support/Calendar/CalendarReconciler.php tests/Feature/CalendarTaggedCopiesTest.php
git commit -m "feat(calendar): a tagged person's calendar edits snap back instead of changing the card"
```

---

### Task 5: Revoked access is detected; reconnect clears it and returns to the board

**Files:**
- Modify: `app/Services/GoogleCalendarClient.php` (`accessTokenFor`)
- Modify: `app/Console/Commands/PullCalendarChanges.php`, `app/Jobs/PullCalendarChangesJob.php`
- Modify: `app/Http/Controllers/GoogleCalendarConnectionController.php` (callback, disconnect; delete `retry()`)
- Modify: `routes/web.php` (delete the `google-calendar.retry` route)
- Test: `tests/Feature/GoogleCalendarConnectionTest.php` (edit + append), `tests/Feature/GoogleCalendarClientTest.php` (append)

**Interfaces:**
- Consumes: `revoked_at`.
- Produces: `PullCalendarChangesJob::handle(...)` returns `int` (number of changes reconciled). Callback dispatches `CalendarFullSyncJob` (created in Task 6 — until then, guard with `class_exists`? No: do Task 6 first if executing out of order. Within this task, dispatch it and create an empty stub class `App\Jobs\CalendarFullSyncJob` with a constructor `(public readonly int $userId)` and an empty `handle()`; Task 6 fills it in).

- [ ] **Step 1: Tests**

In `tests/Feature/GoogleCalendarConnectionTest.php`, change the two existing `->assertRedirect()` calls in `test_callback_stores_the_connection_against_only_the_authenticated_user` and `test_disconnect_removes_the_authenticated_users_connection_only` to `->assertRedirect(route('app.screen', 'board').'?calendar=connected')` and `->assertRedirect(route('app.screen', 'board'))` respectively, and append:

```php
    public function test_reconnecting_clears_a_revoked_flag_and_starts_a_full_sync(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['access_token' => 'new', 'refresh_token' => 'r2', 'expires_in' => 3600])]);
        GoogleCalendarConnection::create(['user_id' => $this->user->id, 'access_token' => 'old', 'refresh_token' => 'r1', 'expires_at' => now(), 'revoked_at' => now(), 'calendar_id' => 'cal-old', 'sync_token' => 'tok']);
        $this->actingInTenant();
        session(['google_calendar.state' => 's']);

        $this->get(route('google-calendar.callback', ['state' => 's', 'code' => 'c']));

        $connection = GoogleCalendarConnection::where('user_id', $this->user->id)->first();
        $this->assertNull($connection->revoked_at);
        $this->assertNull($connection->calendar_id, 'a reconnect may be a different Google account, find the calendar again');
        $this->assertNull($connection->sync_token);
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\CalendarFullSyncJob::class, fn ($job) => $job->userId === $this->user->id);
    }
```

In `tests/Feature/GoogleCalendarClientTest.php` append (match its existing setup; `new GoogleCalendarClient(['client_id' => 'a', 'client_secret' => 'b', 'redirect' => 'http://x'])`):

```php
    public function test_invalid_grant_on_refresh_marks_the_connection_revoked(): void
    {
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.'], 400)]);
        $user = \App\Models\User::create(['name' => 'X', 'email' => 'x@example.com', 'password' => 'x']);
        $connection = GoogleCalendarConnection::create(['user_id' => $user->id, 'access_token' => 'a', 'refresh_token' => 'r', 'expires_at' => now()->subHour()]);
        $client = new GoogleCalendarClient(['client_id' => 'a', 'client_secret' => 'b', 'redirect' => 'http://x']);

        try {
            $client->accessTokenFor($connection);
            $this->fail('expected an exception');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('revoked', $e->getMessage());
        }

        $this->assertNotNull($connection->fresh()->revoked_at);
    }

    public function test_other_refresh_failures_do_not_mark_revoked(): void
    {
        Http::fake(['oauth2.googleapis.com/token' => Http::response('down', 503)]);
        $user = \App\Models\User::create(['name' => 'Y', 'email' => 'y@example.com', 'password' => 'x']);
        $connection = GoogleCalendarConnection::create(['user_id' => $user->id, 'access_token' => 'a', 'refresh_token' => 'r', 'expires_at' => now()->subHour()]);

        try {
            (new GoogleCalendarClient(['client_id' => 'a', 'client_secret' => 'b', 'redirect' => 'http://x']))->accessTokenFor($connection);
        } catch (\RuntimeException) {
        }

        $this->assertNull($connection->fresh()->revoked_at);
    }
```

Append to `tests/Feature/CalendarTwoWaySyncTest.php`:

```php
    public function test_the_pull_command_skips_revoked_connections(): void
    {
        Queue::fake();
        GoogleCalendarConnection::where('user_id', $this->user->id)->update(['revoked_at' => now()]);

        $this->artisan('calendar:pull')->assertSuccessful();

        Queue::assertNotPushed(PullCalendarChangesJob::class);
    }
```

- [ ] **Step 2: Run to see them fail**

Run: `php artisan test --compact tests/Feature/GoogleCalendarConnectionTest.php tests/Feature/GoogleCalendarClientTest.php tests/Feature/CalendarTwoWaySyncTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement**

`GoogleCalendarClient::accessTokenFor`, replace the failure branch:

```php
        if (! $response->successful() || blank($response->json('access_token'))) {
            if ($response->json('error') === 'invalid_grant') {
                $connection->forceFill(['revoked_at' => now()])->save();

                throw new RuntimeException('Google Calendar access was revoked. Reconnect to resume syncing.');
            }

            throw new RuntimeException('Google Calendar token refresh failed.');
        }
```

`PullCalendarChanges::handle`: `GoogleCalendarConnection::query()->whereNull('revoked_at')->each(...)`.

`PullCalendarChangesJob::handle`: return type `int`; after `find`, `if (! $connection || $connection->revoked_at) { return 0; }`; count `$pulled += count($result->payload)` inside the `if ($result->ok)`; `return $pulled;` at the end.

`GoogleCalendarConnectionController`:
- In `callback`, every `redirect('/app/profile')` becomes `redirect()->route('app.screen', 'board')`.
- `updateOrCreate` second array gains `'revoked_at' => null, 'calendar_id' => null, 'sync_token' => null,`.
- After it: `CalendarFullSyncJob::dispatch($request->user()->id);` then `return redirect()->to(route('app.screen', 'board').'?calendar=connected');`
- `disconnect` returns `redirect()->route('app.screen', 'board')->with('ok', 'Google Calendar disconnected.');`
- Delete `retry()` and its now-unused imports (`SyncWorkItemCalendarEventJob`, `WorkItem`).

`routes/web.php`: delete the `google-calendar.retry` line.

Stub `app/Jobs/CalendarFullSyncJob.php` (filled in Task 6):

```php
<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class CalendarFullSyncJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct(public readonly int $userId) {}

    public function handle(): void {}
}
```

- [ ] **Step 4: Run** the same command as Step 2. Expected: PASS.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/GoogleCalendarClient.php app/Console/Commands/PullCalendarChanges.php app/Jobs/PullCalendarChangesJob.php app/Http/Controllers/GoogleCalendarConnectionController.php app/Jobs/CalendarFullSyncJob.php routes/web.php tests/
git commit -m "feat(calendar): notice revoked Google access; reconnect clears it and lands on the board"
```

---

### Task 6: Full sync job, progress, status, and the rate-limited JSON endpoints

**Files:**
- Create: `app/Support/Calendar/CalendarSyncProgress.php`
- Create: `app/Support/Calendar/CalendarSyncStatus.php`
- Modify: `app/Jobs/CalendarFullSyncJob.php`
- Create: `app/Http/Controllers/CalendarSyncController.php`
- Modify: `routes/web.php` (next to the google-calendar routes)
- Test: `tests/Feature/CalendarSyncControlTest.php`

**Interfaces:**
- Consumes: Tasks 2–5.
- Produces:
  - `CalendarSyncProgress::get(int $userId): ?array`, `::start(int $userId, int $total): void`, `::tick(int $userId, bool $ok): void`, `::finish(int $userId, string $state, int $pulled = 0): void`. Array shape `{state: 'running'|'done'|'expired', total: int, done: int, failed: int, pulled: int, finished_at: ?string}`. Cache key `calendar-sync-progress:{userId}`, TTL 600 s.
  - `CalendarSyncStatus::for(User $user): array` shape `{configured: bool, state: 'off'|'connected'|'expired', last_synced_at: ?string, last_synced_human: ?string, mirrored: int, issues: list<{id: int, title: string, message: string}>, progress: ?array, retry_after: int}`.
  - `CalendarSyncController::LIMIT_SECONDS = 120`, `CalendarSyncController::limiterKey(int $userId): string`.
  - Routes `calendar-sync.status` (GET `/app/calendar-sync/status`), `calendar-sync.sync` (POST `/app/calendar-sync/sync`), `calendar-sync.retry` (POST `/app/calendar-sync/retry/{workItem}`).

- [ ] **Step 1: Write failing tests** — `tests/Feature/CalendarSyncControlTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Http\Controllers\CalendarSyncController;
use App\Models\Employee;
use App\Models\GoogleCalendarConnection;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkItem;
use App\Models\WorkItemCalendarCopy;
use App\Ports\CalendarPort;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/** The board's Google Calendar control: status, Sync now (rate limited), Retry. */
class CalendarSyncControlTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    private Employee $me;

    private Employee $colleague;

    private RecordingCalendarPort $port;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        config(['services.google_calendar.client_id' => 'id', 'services.google_calendar.client_secret' => 'secret']);
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $this->user = User::create(['name' => 'Me', 'email' => 'me@example.com', 'password' => Hash::make('password')]);
        $this->user->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $this->me = Employee::create(['tenant_id' => $this->tenant->id, 'user_id' => $this->user->id, 'name' => 'Me', 'status' => 'active', 'workload' => 'green']);
        $other = User::create(['name' => 'Col', 'email' => 'col@example.com', 'password' => Hash::make('password')]);
        $other->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $this->colleague = Employee::create(['tenant_id' => $this->tenant->id, 'user_id' => $other->id, 'name' => 'Col', 'status' => 'active', 'workload' => 'green']);
        $this->port = new RecordingCalendarPort;
        $this->app->instance(CalendarPort::class, $this->port);
        RateLimiter::clear(CalendarSyncController::limiterKey($this->user->id));
    }

    protected function tearDown(): void
    {
        app(CurrentTenant::class)->set(null);
        parent::tearDown();
    }

    private function connect(array $attrs = []): GoogleCalendarConnection
    {
        return GoogleCalendarConnection::create($attrs + ['user_id' => $this->user->id, 'access_token' => 't', 'refresh_token' => 'r', 'expires_at' => now()->addHour()]);
    }

    private function as(): self
    {
        return $this->actingAs($this->user)->withSession(['current_tenant' => $this->tenant->id]);
    }

    /** Cards are created before the connection exists, so nothing is pushed yet. */
    private function cardFor(Employee $owner, array $attrs = []): WorkItem
    {
        app(CurrentTenant::class)->set($this->tenant);
        $card = $owner->workItems()->create($attrs + [
            'tenant_id' => $this->tenant->id, 'title' => 'Card '.uniqid(), 'type' => 'task',
            'priority' => 'medium', 'status' => 'todo', 'progress' => 0, 'due_at' => '2026-10-05',
        ]);
        app(CurrentTenant::class)->set(null);

        return $card;
    }

    public function test_status_says_off_when_not_connected(): void
    {
        $this->as()->getJson(route('calendar-sync.status'))
            ->assertOk()
            ->assertJson(['configured' => true, 'state' => 'off', 'issues' => [], 'retry_after' => 0]);
    }

    public function test_status_says_expired_when_revoked(): void
    {
        $this->connect(['revoked_at' => now()]);

        $this->as()->getJson(route('calendar-sync.status'))->assertJson(['state' => 'expired']);
    }

    public function test_status_lists_failed_owner_cards_and_failed_copies(): void
    {
        $mine = $this->cardFor($this->me);
        WorkItem::withoutGlobalScopes()->where('id', $mine->id)->update(['calendar_sync_error' => 'boom']);
        $theirs = $this->cardFor($this->colleague);
        WorkItemCalendarCopy::create(['tenant_id' => $this->tenant->id, 'work_item_id' => $theirs->id, 'employee_id' => $this->me->id, 'sync_error' => 'nope']);
        $this->connect();

        $issues = $this->as()->getJson(route('calendar-sync.status'))->assertJson(['state' => 'connected'])->json('issues');

        $this->assertEqualsCanonicalizing([$mine->id, $theirs->id], array_column($issues, 'id'));
    }

    public function test_sync_now_pushes_owned_and_tagged_cards_and_reports_progress(): void
    {
        $owned = $this->cardFor($this->me);
        $tagged = $this->cardFor($this->colleague);
        \Illuminate\Support\Facades\DB::table('work_item_participant')->insert(['work_item_id' => $tagged->id, 'employee_id' => $this->me->id, 'role' => 'fyi']);
        $this->cardFor($this->me, ['status' => 'done']);
        $this->cardFor($this->colleague);
        $this->connect();

        $this->as()->postJson(route('calendar-sync.sync'))->assertStatus(202);

        $this->assertSame([$this->me->id, $this->me->id], $this->port->pushedTo());
        $this->assertNotNull($owned->fresh()->google_event_id);
        $this->assertDatabaseHas('work_item_calendar_copies', ['work_item_id' => $tagged->id, 'employee_id' => $this->me->id]);
        $progress = $this->as()->getJson(route('calendar-sync.status'))->json('progress');
        $this->assertSame(['state' => 'done', 'total' => 2, 'done' => 2, 'failed' => 0], array_intersect_key($progress, array_flip(['state', 'total', 'done', 'failed'])));
    }

    public function test_sync_now_counts_failures_and_keeps_going(): void
    {
        $this->cardFor($this->me);
        $this->cardFor($this->me);
        $this->connect();
        $this->port->fail = true;

        $this->as()->postJson(route('calendar-sync.sync'))->assertStatus(202);

        $progress = $this->as()->getJson(route('calendar-sync.status'))->json('progress');
        $this->assertSame(2, $progress['failed']);
        $this->assertSame(2, WorkItem::withoutGlobalScopes()->whereNotNull('calendar_sync_error')->count());
    }

    public function test_second_sync_within_two_minutes_is_refused_with_the_wait(): void
    {
        $this->connect();
        $this->as()->postJson(route('calendar-sync.sync'))->assertStatus(202);

        $response = $this->as()->postJson(route('calendar-sync.sync'))->assertStatus(429);

        $this->assertGreaterThan(100, $response->json('retry_after'));
        $this->assertLessThanOrEqual(120, $response->json('retry_after'));
    }

    public function test_the_limit_resets_after_two_minutes(): void
    {
        $this->connect();
        $this->as()->postJson(route('calendar-sync.sync'))->assertStatus(202);

        $this->travel(121)->seconds();

        $this->as()->postJson(route('calendar-sync.sync'))->assertStatus(202);
    }

    public function test_sync_needs_a_live_connection(): void
    {
        $this->as()->postJson(route('calendar-sync.sync'))->assertStatus(409);
        $this->connect(['revoked_at' => now()]);
        $this->as()->postJson(route('calendar-sync.sync'))->assertStatus(409);
    }

    public function test_retry_resends_my_card_and_shares_the_limit(): void
    {
        $card = $this->cardFor($this->me);
        WorkItem::withoutGlobalScopes()->where('id', $card->id)->update(['calendar_sync_error' => 'boom']);
        $this->connect();

        $this->as()->postJson(route('calendar-sync.retry', $card))->assertOk();

        $this->assertNull($card->fresh()->calendar_sync_error);
        $this->assertSame([$this->me->id], $this->port->pushedTo());
        $this->as()->postJson(route('calendar-sync.sync'))->assertStatus(429);
    }

    public function test_retry_resends_my_tagged_copy(): void
    {
        $card = $this->cardFor($this->colleague);
        \Illuminate\Support\Facades\DB::table('work_item_participant')->insert(['work_item_id' => $card->id, 'employee_id' => $this->me->id, 'role' => 'helper']);
        WorkItemCalendarCopy::create(['tenant_id' => $this->tenant->id, 'work_item_id' => $card->id, 'employee_id' => $this->me->id, 'sync_error' => 'nope']);
        $this->connect();

        $this->as()->postJson(route('calendar-sync.retry', $card))->assertOk();

        $this->assertNull(WorkItemCalendarCopy::first()->sync_error);
        $this->assertSame([$this->me->id], $this->port->pushedTo());
    }

    public function test_retry_refuses_a_card_i_am_not_on(): void
    {
        $card = $this->cardFor($this->colleague);
        $this->connect();

        $this->as()->postJson(route('calendar-sync.retry', $card))->assertForbidden();
    }

    public function test_routes_404_when_google_is_not_configured(): void
    {
        config(['services.google_calendar.client_id' => null]);

        $this->as()->getJson(route('calendar-sync.status'))->assertNotFound();
        $this->as()->postJson(route('calendar-sync.sync'))->assertNotFound();
    }
}
```

`RecordingCalendarPort` lives in `CalendarTaggedCopiesTest.php` in the same namespace; PHPUnit loads that file first only if it runs. To make this file standalone, move `RecordingCalendarPort` into its own file `tests/Feature/RecordingCalendarPort.php` (namespace `Tests\Feature`, same body) and delete it from `CalendarTaggedCopiesTest.php`. The `tests/` directory is PSR-4 autoloaded (`Tests\\` => `tests/`), so the class resolves by file name.

- [ ] **Step 2: Run to see them fail**

Run: `php artisan test --compact tests/Feature/CalendarSyncControlTest.php`
Expected: FAIL, route `calendar-sync.status` not defined.

- [ ] **Step 3: Implement**

`app/Support/Calendar/CalendarSyncProgress.php`:

```php
<?php

declare(strict_types=1);

namespace App\Support\Calendar;

use Illuminate\Support\Facades\Cache;

/**
 * How far a person's "Sync now" has got, for the board to poll. Cache only: it is a
 * progress bar, not a record, and it expires on its own after ten minutes.
 */
final class CalendarSyncProgress
{
    private const TTL = 600;

    /** @return array{state: string, total: int, done: int, failed: int, pulled: int, finished_at: ?string}|null */
    public static function get(int $userId): ?array
    {
        return Cache::get(self::key($userId));
    }

    public static function start(int $userId, int $total): void
    {
        Cache::put(self::key($userId), ['state' => 'running', 'total' => $total, 'done' => 0, 'failed' => 0, 'pulled' => 0, 'finished_at' => null], self::TTL);
    }

    public static function tick(int $userId, bool $ok): void
    {
        $p = self::get($userId) ?? ['state' => 'running', 'total' => 0, 'done' => 0, 'failed' => 0, 'pulled' => 0, 'finished_at' => null];
        $p['done']++;
        $p['failed'] += $ok ? 0 : 1;
        Cache::put(self::key($userId), $p, self::TTL);
    }

    public static function finish(int $userId, string $state, int $pulled = 0): void
    {
        $p = self::get($userId) ?? ['total' => 0, 'done' => 0, 'failed' => 0];
        Cache::put(self::key($userId), ['state' => $state, 'pulled' => $pulled, 'finished_at' => now()->toIso8601String()] + $p, self::TTL);
    }

    private static function key(int $userId): string
    {
        return "calendar-sync-progress:{$userId}";
    }
}
```

`app/Jobs/CalendarFullSyncJob.php` (replace stub):

```php
<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Employee;
use App\Models\GoogleCalendarConnection;
use App\Models\WorkItem;
use App\Ports\CalendarPort;
use App\Support\Calendar\CalendarReconciler;
use App\Support\Calendar\CalendarSyncProgress;
use App\Support\Calendar\TaggedCopies;
use App\Tenancy\CurrentTenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * "Sync now", and the first sync after (re)connecting: push every open dated card the
 * person owns or is tagged on, in every company they belong to, then pull Google's
 * changes. One card failing does not stop the rest; revoked access does.
 */
class CalendarFullSyncJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 600;

    public function __construct(public readonly int $userId) {}

    public function uniqueId(): string
    {
        return (string) $this->userId;
    }

    public function handle(CurrentTenant $context, CalendarPort $port, CalendarReconciler $reconciler): void
    {
        $connection = GoogleCalendarConnection::where('user_id', $this->userId)->whereNull('revoked_at')->first();
        if (! $connection) {
            CalendarSyncProgress::finish($this->userId, 'expired');

            return;
        }

        $targets = $this->targets();
        CalendarSyncProgress::start($this->userId, count($targets));

        foreach ($targets as [$tenantId, $workItemId, $recipientId]) {
            $job = new SyncWorkItemCalendarEventJob(tenantId: $tenantId, action: 'upsert', workItemId: $workItemId, recipientEmployeeId: $recipientId);
            try {
                $job->handle($context, $port);
                CalendarSyncProgress::tick($this->userId, true);
            } catch (Throwable $e) {
                $job->failed($e);
                CalendarSyncProgress::tick($this->userId, false);
                if ($connection->fresh()?->revoked_at) {
                    CalendarSyncProgress::finish($this->userId, 'expired');

                    return;
                }
            }
        }

        $pulled = (new PullCalendarChangesJob($connection->id))->handle($context, $port, $reconciler);
        CalendarSyncProgress::finish($this->userId, 'done', $pulled);
    }

    /** @return list<array{0: int, 1: int, 2: ?int}> tenant id, card id, recipient (null = owner) */
    private function targets(): array
    {
        $out = [];
        $open = fn (Builder $q) => $q->whereNotNull('due_at')->whereNull('parent_id')
            ->whereNull('archived_at')->whereNull('cancelled_at')->where('status', '!=', 'done');

        foreach (Employee::withoutGlobalScope('tenant')->where('user_id', $this->userId)->get() as $employee) {
            $owned = WorkItem::withoutGlobalScopes()->where('employee_id', $employee->id)->tap($open)->pluck('id');
            foreach ($owned as $id) {
                $out[] = [$employee->tenant_id, $id, null];
            }

            $tagged = WorkItem::withoutGlobalScopes()->tap($open)
                ->where('employee_id', '!=', $employee->id)
                ->whereNull('company_event_id')
                ->whereIn('id', fn ($q) => $q->select('work_item_id')->from('work_item_participant')
                    ->where('employee_id', $employee->id)
                    ->where(fn ($r) => $r->whereIn('role', array_filter(TaggedCopies::ROLES))->orWhereNull('role')))
                ->pluck('id');
            foreach ($tagged as $id) {
                $out[] = [$employee->tenant_id, $id, $employee->id];
            }
        }

        return $out;
    }
}
```

`app/Support/Calendar/CalendarSyncStatus.php`:

```php
<?php

declare(strict_types=1);

namespace App\Support\Calendar;

use App\Http\Controllers\CalendarSyncController;
use App\Models\Employee;
use App\Models\GoogleCalendarConnection;
use App\Models\User;
use App\Models\WorkItem;
use App\Models\WorkItemCalendarCopy;
use App\Services\GoogleCalendarClient;
use Illuminate\Support\Facades\RateLimiter;

/** Everything the board's Google Calendar control shows, for one person, across their companies. */
final class CalendarSyncStatus
{
    /** @return array<string, mixed> */
    public static function for(User $user): array
    {
        $connection = GoogleCalendarConnection::where('user_id', $user->id)->first();
        $employeeIds = Employee::withoutGlobalScope('tenant')->where('user_id', $user->id)->pluck('id');

        $state = match (true) {
            $connection === null => 'off',
            $connection->revoked_at !== null => 'expired',
            default => 'connected',
        };

        $ownerIssues = WorkItem::withoutGlobalScopes()->whereIn('employee_id', $employeeIds)
            ->whereNotNull('calendar_sync_error')->orderByDesc('updated_at')
            ->get(['id', 'title', 'calendar_sync_error'])
            ->map(fn (WorkItem $w) => ['id' => $w->id, 'title' => $w->title, 'message' => $w->calendar_sync_error]);

        $copyIssues = WorkItemCalendarCopy::with('workItem:id,title')->whereIn('employee_id', $employeeIds)
            ->whereNotNull('sync_error')->orderByDesc('updated_at')->get()
            ->filter(fn (WorkItemCalendarCopy $c) => $c->workItem !== null)
            ->map(fn (WorkItemCalendarCopy $c) => ['id' => $c->work_item_id, 'title' => $c->workItem->title, 'message' => $c->sync_error]);

        $mirrored = WorkItem::withoutGlobalScopes()->whereIn('employee_id', $employeeIds)->whereNotNull('google_event_id')->count()
            + WorkItemCalendarCopy::whereIn('employee_id', $employeeIds)->whereNotNull('google_event_id')->count();

        $key = CalendarSyncController::limiterKey($user->id);

        return [
            'configured' => app(GoogleCalendarClient::class)->configured(),
            'state' => $state,
            'last_synced_at' => $connection?->last_pulled_at?->toIso8601String(),
            'last_synced_human' => $connection?->last_pulled_at?->diffForHumans(),
            'mirrored' => $mirrored,
            'issues' => $ownerIssues->concat($copyIssues)->values()->all(),
            'progress' => CalendarSyncProgress::get($user->id),
            'retry_after' => RateLimiter::tooManyAttempts($key, 1) ? RateLimiter::availableIn($key) : 0,
        ];
    }
}
```

`app/Http/Controllers/CalendarSyncController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\CalendarFullSyncJob;
use App\Jobs\SyncWorkItemCalendarEventJob;
use App\Models\GoogleCalendarConnection;
use App\Models\WorkItem;
use App\Models\WorkItemCalendarCopy;
use App\Services\GoogleCalendarClient;
use App\Support\Calendar\CalendarSyncProgress;
use App\Support\Calendar\CalendarSyncStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The task board's Google Calendar control. JSON only; the board swaps its own panel.
 * Sync now and Retry share one per-person limit so the button cannot be hammered.
 */
class CalendarSyncController extends Controller
{
    public const LIMIT_SECONDS = 120;

    public function __construct(GoogleCalendarClient $client)
    {
        abort_unless($client->configured(), 404);
    }

    public static function limiterKey(int $userId): string
    {
        return "calendar-sync:{$userId}";
    }

    public function status(Request $request): JsonResponse
    {
        return response()->json(CalendarSyncStatus::for($request->user()));
    }

    public function sync(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! GoogleCalendarConnection::where('user_id', $user->id)->whereNull('revoked_at')->exists()) {
            return response()->json(['message' => 'Connect Google Calendar first.'], 409);
        }
        if ($blocked = $this->limited($user->id)) {
            return $blocked;
        }

        CalendarSyncProgress::start($user->id, 0);
        CalendarFullSyncJob::dispatch($user->id);

        return response()->json(CalendarSyncStatus::for($user), 202);
    }

    public function retry(Request $request, WorkItem $workItem): JsonResponse
    {
        $employee = $request->attributes->get('employee');
        // Route binding ignores tenants: check this card is in the caller's company.
        abort_unless($employee && $workItem->tenant_id === $employee->tenant_id, 403);

        $isOwner = $workItem->employee_id === $employee->id;
        $copy = $isOwner ? null : WorkItemCalendarCopy::where('work_item_id', $workItem->id)->where('employee_id', $employee->id)->first();
        abort_unless($isOwner || $copy, 403);

        if ($blocked = $this->limited($request->user()->id)) {
            return $blocked;
        }

        if ($isOwner) {
            WorkItem::withoutGlobalScopes()->where('id', $workItem->id)->update(['calendar_sync_error' => null]);
            SyncWorkItemCalendarEventJob::dispatch(tenantId: $workItem->tenant_id, action: 'upsert', workItemId: $workItem->id);
        } else {
            $copy->update(['sync_error' => null]);
            SyncWorkItemCalendarEventJob::dispatch(tenantId: $workItem->tenant_id, action: 'upsert', workItemId: $workItem->id, recipientEmployeeId: $employee->id);
        }

        return response()->json(CalendarSyncStatus::for($request->user()));
    }

    private function limited(int $userId): ?JsonResponse
    {
        $key = self::limiterKey($userId);
        if (RateLimiter::tooManyAttempts($key, 1)) {
            $wait = RateLimiter::availableIn($key);

            return response()->json(['message' => "You can sync again in {$wait} seconds.", 'retry_after' => $wait], 429);
        }
        RateLimiter::hit($key, self::LIMIT_SECONDS);

        return null;
    }
}
```

Routes, directly under the `google-calendar.disconnect` line in `routes/web.php`:

```php
        // The task board's Google Calendar control (status, Sync now, Retry). JSON only.
        Route::get('/app/calendar-sync/status', [CalendarSyncController::class, 'status'])->name('calendar-sync.status');
        Route::post('/app/calendar-sync/sync', [CalendarSyncController::class, 'sync'])->name('calendar-sync.sync');
        Route::post('/app/calendar-sync/retry/{workItem}', [CalendarSyncController::class, 'retry'])->name('calendar-sync.retry');
```

(add `use App\Http\Controllers\CalendarSyncController;` at the top, alphabetical.)

Note on the retry test for an owned card: the `RecordingCalendarPort` returns ok, so the push succeeds and the error stays cleared.

- [ ] **Step 4: Run**

Run: `php artisan test --compact tests/Feature/CalendarSyncControlTest.php tests/Feature/CalendarTaggedCopiesTest.php tests/Feature/GoogleCalendarConnectionTest.php`
Expected: PASS. If `test_sync_now_pushes...` sees extra pushes, check that `cardFor` created cards before `connect()` (the observer skips unconnected owners).

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Support/Calendar/CalendarSyncProgress.php app/Support/Calendar/CalendarSyncStatus.php app/Jobs/CalendarFullSyncJob.php app/Http/Controllers/CalendarSyncController.php routes/web.php tests/Feature/
git commit -m "feat(calendar): Sync now for all owned and tagged cards, with progress and a 2-minute limit"
```

---

### Task 7: Board control UI; Profile section removed

**Files:**
- Create: `resources/views/partials/board-calendar-sync.blade.php`
- Create: `resources/js/calendar-sync.js`
- Modify: `resources/js/app.js` (import + register)
- Modify: `resources/views/screens/board.blade.php` (first filter row, after the search input, ~line 69)
- Modify: `app/Http/Controllers/Concerns/BuildsWorkData.php` (`boardScreenData`, add `calendarSync`)
- Modify: `resources/views/screens/profile.blade.php` (delete the Google Calendar block, ~lines 363-406: from `@if ($isOwn)` directly under the "Work items" heading to its matching `@endif`)
- Modify: `app/Http/Controllers/Concerns/BuildsPeopleData.php` (delete `googleCalendarConnected` and `calendarSyncIssues` keys and the `GoogleCalendarConnection` import)
- Test: `tests/Feature/CalendarSyncControlTest.php` (append)

**Interfaces:**
- Consumes: `CalendarSyncStatus::for()`, routes from Task 6, `google-calendar.redirect`, `google-calendar.disconnect`.
- Produces: Alpine component `calendarSync(initial, urls)` where `urls = {status, sync, retry, connect, disconnect}` and `retry` contains the literal `__ID__`.

- [ ] **Step 1: Append failing tests**

```php
    public function test_the_board_shows_the_calendar_control_when_configured(): void
    {
        $this->as()->get(route('app.screen', 'board'))
            ->assertOk()
            ->assertSee('data-testid="calendar-sync"', escape: false)
            ->assertSee(route('calendar-sync.sync'), escape: false);
    }

    public function test_the_board_hides_it_when_google_is_not_configured(): void
    {
        config(['services.google_calendar.client_id' => null]);

        $this->as()->get(route('app.screen', 'board'))
            ->assertOk()
            ->assertDontSee('data-testid="calendar-sync"', escape: false);
    }

    public function test_profile_no_longer_has_the_calendar_section(): void
    {
        $this->as()->get(route('app.screen', 'profile'))
            ->assertOk()
            ->assertDontSee(route('google-calendar.redirect'), escape: false);
    }
```

If `route('app.screen', 'profile')` is not the own-profile URL, find it with `php artisan route:list --name=app.screen` and `grep -n "'profile'" app/Http/Controllers/AppController.php`, and use that.

- [ ] **Step 2: Run to see them fail**

Run: `php artisan test --compact tests/Feature/CalendarSyncControlTest.php`
Expected: the three new tests FAIL.

- [ ] **Step 3: Implement**

`BuildsWorkData::boardScreenData` — add to the returned array:

```php
            // The Google Calendar control (status, Sync now, issues). Null hides it.
            'calendarSync' => app(GoogleCalendarClient::class)->configured() && $request->user()
                ? CalendarSyncStatus::for($request->user())
                : null,
```

(imports `App\Services\GoogleCalendarClient`, `App\Support\Calendar\CalendarSyncStatus`.)

`resources/views/screens/board.blade.php` — right after the `<input type="search" ...>` element in the first row, before that row's closing `</div>`:

```blade
            @if ($calendarSync ?? null)
                @include('partials.board-calendar-sync', ['calendarSync' => $calendarSync])
            @endif
```

`resources/views/partials/board-calendar-sync.blade.php`:

```blade
{{-- Google Calendar control for the task board. State and copy live in resources/js/calendar-sync.js;
     every action is JSON so the board never reloads. Mockup: docs/build/design/calendar-sync. --}}
<style>
    .cs{position:relative;margin-left:auto}
    .cs-pill{display:inline-flex;align-items:center;gap:8px;height:32px;padding:0 12px 0 10px;border:1px solid var(--hairline);border-radius:9999px;background:#fff;color:var(--ink);font-size:12.5px;font-weight:600;cursor:pointer;transition:border-color .14s var(--ease)}
    .cs-pill:hover{border-color:#d4d2cb}
    .cs-pill.is-warn{border-color:#f0c9d3;background:#fdf3f6;color:var(--error-ink)}
    .cs-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0}
    .cs-sub{font-weight:500;color:var(--muted);font-size:11.5px}
    .cs-pill.is-warn .cs-sub{color:var(--error-ink)}
    .cs-badge{min-width:18px;height:18px;padding:0 5px;border-radius:9999px;background:var(--error);color:#fff;font-size:10.5px;font-family:var(--font-mono);display:grid;place-items:center}
    .cs-panel{position:absolute;right:0;top:40px;width:340px;max-width:calc(100vw - 32px);background:#fff;border:1px solid var(--hairline);border-radius:14px;box-shadow:var(--shadow-menu);z-index:40;overflow:hidden;text-align:left}
    .cs-head{padding:14px 16px 12px;display:flex;gap:11px;align-items:flex-start;border-bottom:1px solid var(--hairline-soft)}
    .cs-head svg{flex-shrink:0;margin-top:2px}
    .cs-title{font-weight:600;font-size:13.5px;color:var(--ink)}
    .cs-text{font-size:12px;color:var(--muted);margin-top:2px;line-height:1.45}
    .cs-body{padding:12px 16px 14px}
    .cs-btn{width:100%;height:34px;border-radius:9px;font-size:12.5px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;gap:8px;border:1px solid transparent;background:var(--red);color:#fff;text-decoration:none}
    .cs-btn:hover{background:var(--red-active)}
    .cs-btn[disabled]{background:#eceae4;color:var(--muted-soft);cursor:not-allowed}
    .cs-btn.is-busy[disabled]{background:var(--red);color:#fff;opacity:.85}
    .cs-btn-google{background:#fff;color:#3c4043;border-color:#dadce0;height:38px}
    .cs-btn-google:hover{background:#f8f9fa}
    .cs-note{font-size:11.5px;color:var(--muted);margin-top:8px;text-align:center;line-height:1.45}
    .cs-spin{width:14px;height:14px;border:2px solid rgba(255,255,255,.4);border-top-color:#fff;border-radius:50%;animation:cs-spin .8s linear infinite}
    .cs-pill .cs-spin{border-color:rgba(214,35,43,.25);border-top-color:var(--red)}
    @keyframes cs-spin{to{transform:rotate(360deg)}}
    .cs-bar{height:4px;border-radius:4px;background:var(--hairline-soft);overflow:hidden;margin-top:10px}
    .cs-bar i{display:block;height:100%;background:var(--red);border-radius:4px;transition:width .3s var(--ease)}
    .cs-result{display:flex;gap:9px;background:#eef7f3;border:1px solid #cfe7dc;color:var(--success-ink);border-radius:10px;padding:9px 11px;font-size:12px;margin-bottom:10px;line-height:1.45}
    .cs-result.is-bad{background:#fdf3f6;border-color:#f0c9d3;color:var(--error-ink)}
    .cs-issues{margin-top:12px;border-top:1px solid var(--hairline-soft);padding-top:10px}
    .cs-issues h4{margin:0 0 6px;font-size:10.5px;letter-spacing:.6px;text-transform:uppercase;color:var(--error)}
    .cs-issue{display:flex;gap:8px;align-items:center;padding:6px 0;font-size:12px}
    .cs-issue-name{flex:1;min-width:0}
    .cs-issue-name a{display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:var(--ink);font-weight:500;text-decoration:none}
    .cs-issue-name span{display:block;color:var(--muted);font-size:11px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .cs-retry{height:26px;padding:0 9px;font-size:11px;border-radius:7px;background:#fff;color:var(--body);border:1px solid var(--hairline);cursor:pointer}
    .cs-retry[disabled]{color:var(--muted-soft);cursor:not-allowed}
    .cs-foot{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:10px 16px;background:#fbfbf9;border-top:1px solid var(--hairline-soft);font-size:11.5px;color:var(--muted)}
    .cs-link{background:none;border:none;color:var(--muted);font-size:12px;text-decoration:underline;cursor:pointer;padding:0}
    @media (prefers-reduced-motion: reduce){.cs-spin{animation:none}.cs-bar i{transition:none}}
</style>

@php
    $gIcon = '<svg width="16" height="16" viewBox="0 0 18 18" aria-hidden="true"><path fill="#4285F4" d="M17.64 9.2c0-.64-.06-1.25-.16-1.84H9v3.48h4.84a4.14 4.14 0 0 1-1.8 2.72v2.26h2.92c1.7-1.57 2.68-3.88 2.68-6.62z"/><path fill="#34A853" d="M9 18c2.43 0 4.47-.8 5.96-2.18l-2.92-2.26c-.8.54-1.84.86-3.04.86-2.34 0-4.32-1.58-5.03-3.7H.96v2.33A9 9 0 0 0 9 18z"/><path fill="#FBBC05" d="M3.97 10.72A5.4 5.4 0 0 1 3.68 9c0-.6.1-1.18.29-1.72V4.95H.96A9 9 0 0 0 0 9c0 1.45.35 2.83.96 4.05l3.01-2.33z"/><path fill="#EA4335" d="M9 3.58c1.32 0 2.51.45 3.44 1.35l2.59-2.59C13.46.89 11.43 0 9 0A9 9 0 0 0 .96 4.95l3.01 2.33C4.68 5.16 6.66 3.58 9 3.58z"/></svg>';
@endphp

<div class="cs" data-testid="calendar-sync"
     x-data="calendarSync(@js($calendarSync), @js([
        'status' => route('calendar-sync.status'),
        'sync' => route('calendar-sync.sync'),
        'retry' => route('calendar-sync.retry', ['workItem' => '__ID__']),
        'card' => route('app.screen', 'board').'?card=__ID__',
     ]))"
     @keydown.escape.window="open = false" @click.outside="open = false">

    <button type="button" class="cs-pill" :class="{ 'is-warn': tone === 'warn' }" @click="open = !open" :aria-expanded="open" aria-haspopup="dialog">
        <span x-show="running" class="cs-spin" aria-hidden="true"></span>
        <span x-show="!running" class="cs-dot" :style="{ background: dotColor }" aria-hidden="true"></span>
        <span>Google Calendar</span>
        <span x-show="issueCount > 0 && s.state === 'connected' && !running" class="cs-badge" x-text="issueCount"></span>
        <span x-show="!(issueCount > 0 && s.state === 'connected' && !running)" class="cs-sub" x-text="pillSub"></span>
    </button>

    <div class="cs-panel" x-show="open" x-cloak x-transition.opacity.duration.120ms role="dialog" :aria-label="t('Google Calendar', 'Kalendar Google')">
        <div class="cs-head">
            {!! $gIcon !!}
            <div>
                <div class="cs-title" x-text="headTitle"></div>
                <div class="cs-text" x-text="headText"></div>
            </div>
        </div>

        {{-- Not connected / expired: the only Connect button in the app. --}}
        <template x-if="s.state !== 'connected'">
            <div class="cs-body">
                <a href="{{ route('google-calendar.redirect') }}" class="cs-btn cs-btn-google">{!! $gIcon !!}
                    <span x-text="s.state === 'expired' ? t('Reconnect Google Calendar', 'Sambung semula Kalendar Google') : t('Connect Google Calendar', 'Sambung Kalendar Google')"></span>
                </a>
                <div class="cs-note" x-text="s.state === 'expired'
                    ? t('This happens if access was removed in your Google account, or Google cancelled it.', 'Ini berlaku jika akses dibuang dalam akaun Google anda, atau Google membatalkannya.')
                    : t('Your cards are sent automatically right after you connect.', 'Kad anda dihantar secara automatik sebaik sahaja anda bersambung.')"></div>
            </div>
        </template>

        <template x-if="s.state === 'connected'">
            <div>
                <div class="cs-body">
                    <div x-show="result" class="cs-result" :class="{ 'is-bad': result && result.failed > 0 }" role="status">
                        <span x-text="result && result.failed > 0 ? '!' : '✓'" aria-hidden="true"></span>
                        <span x-text="resultText"></span>
                    </div>

                    <button type="button" class="cs-btn" :class="{ 'is-busy': running }" :disabled="running || cooldown > 0" @click="syncNow()">
                        <span x-show="running" class="cs-spin" aria-hidden="true"></span>
                        <span x-text="buttonText"></span>
                    </button>
                    <div x-show="running && progress.total > 0" class="cs-bar" aria-hidden="true"><i :style="{ width: percent + '%' }"></i></div>
                    <div class="cs-note" x-text="noteText"></div>

                    <div x-show="issueCount > 0" class="cs-issues">
                        <h4 x-text="t(issueCount + (issueCount === 1 ? ' card' : ' cards') + ' could not be sent', issueCount + ' kad tidak dapat dihantar')"></h4>
                        <template x-for="issue in s.issues" :key="issue.id">
                            <div class="cs-issue">
                                <div class="cs-issue-name">
                                    <a :href="urls.card.replace('__ID__', issue.id)" x-text="issue.title"></a>
                                    <span x-text="friendly(issue.message)" :title="issue.message"></span>
                                </div>
                                <button type="button" class="cs-retry" :disabled="running || cooldown > 0" @click="retry(issue.id)" x-text="t('Retry', 'Cuba lagi')"></button>
                            </div>
                        </template>
                    </div>
                </div>
                <div class="cs-foot">
                    <span x-text="t('Only your “Amanahku” calendar is used.', 'Hanya kalendar “Amanahku” anda digunakan.')"></span>
                    <form method="post" action="{{ route('google-calendar.disconnect') }}">
                        @csrf
                        <button type="submit" class="cs-link" x-text="t('Disconnect', 'Putuskan')"></button>
                    </form>
                </div>
            </div>
        </template>
    </div>
</div>
```

`resources/js/calendar-sync.js`:

```js
// The task board's Google Calendar control. Server state comes from
// CalendarSyncStatus (initial render + GET status); Sync now and Retry are JSON posts
// sharing a 2-minute server-side limit, mirrored here as a live countdown.

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

export function registerCalendarSync(Alpine) {
    Alpine.data('calendarSync', (initial, urls) => ({
        s: initial,
        urls,
        open: false,
        cooldown: initial.retry_after || 0,
        result: null,
        watching: false,
        poller: null,
        ticker: null,

        init() {
            if (this.cooldown > 0) this.countdown();
            if (this.running) this.watch();
            const params = new URLSearchParams(window.location.search);
            if (params.get('calendar') === 'connected') {
                this.open = true;
                this.watch();
                params.delete('calendar');
                const qs = params.toString();
                window.history.replaceState(window.history.state, '', window.location.pathname + (qs ? `?${qs}` : ''));
            }
        },

        destroy() {
            clearInterval(this.poller);
            clearInterval(this.ticker);
        },

        t(en, ms) { return this.$store.ui.lang === 'en' ? en : ms; },

        get progress() { return this.s.progress || { total: 0, done: 0, failed: 0, pulled: 0 }; },
        get running() { return this.s.progress?.state === 'running'; },
        get issueCount() { return this.s.issues.length; },
        get percent() { return this.progress.total ? Math.round((this.progress.done / this.progress.total) * 100) : 0; },
        get tone() { return this.s.state === 'expired' || (this.s.state === 'connected' && this.issueCount > 0) ? 'warn' : 'ok'; },
        get dotColor() {
            if (this.tone === 'warn') return 'var(--error)';
            return this.s.state === 'connected' ? 'var(--success)' : '#b9b6ad';
        },
        get synced() { return this.s.last_synced_human; },
        get pillSub() {
            if (this.running) return this.t('Syncing…', 'Menyegerak…');
            if (this.s.state === 'off') return this.t('Not connected', 'Tidak bersambung');
            if (this.s.state === 'expired') return this.t('Reconnect', 'Sambung semula');
            return this.synced ? this.t(`Synced ${this.synced}`, `Disegerak ${this.synced}`) : this.t('Connected', 'Bersambung');
        },
        get headTitle() {
            if (this.s.state === 'off') return this.t('Not connected', 'Tidak bersambung');
            if (this.s.state === 'expired') return this.t('Connection expired', 'Sambungan tamat');
            if (this.issueCount > 0) return this.t(`Connected, ${this.issueCount} ${this.issueCount === 1 ? 'card needs' : 'cards need'} attention`, `Bersambung, ${this.issueCount} kad perlu perhatian`);
            return this.t('Connected', 'Bersambung');
        },
        get headText() {
            if (this.s.state === 'off') {
                return this.t('Connect once and every card you own or are tagged on shows up in a separate “Amanahku” calendar. Your main calendar is never touched.',
                    'Sambung sekali dan setiap kad milik anda atau yang anda ditanda akan muncul dalam kalendar “Amanahku” berasingan. Kalendar utama anda tidak disentuh.');
            }
            if (this.s.state === 'expired') {
                return this.t('Google stopped letting AmanahKu update your calendar, so nothing is being sent right now. Your cards are safe; reconnect and they will be sent again.',
                    'Google berhenti membenarkan AmanahKu mengemas kini kalendar anda, jadi tiada apa dihantar sekarang. Kad anda selamat; sambung semula dan ia akan dihantar lagi.');
            }
            const cards = this.t(`${this.s.mirrored} ${this.s.mirrored === 1 ? 'card' : 'cards'} in your “Amanahku” calendar`, `${this.s.mirrored} kad dalam kalendar “Amanahku” anda`);
            return this.synced ? `${cards} · ${this.t(`last synced ${this.synced}`, `kali terakhir disegerak ${this.synced}`)}` : cards;
        },
        get buttonText() {
            if (this.running) {
                return this.progress.total
                    ? this.t(`Syncing ${this.progress.done} of ${this.progress.total} cards…`, `Menyegerak ${this.progress.done} daripada ${this.progress.total} kad…`)
                    : this.t('Starting…', 'Bermula…');
            }
            if (this.cooldown > 0) return this.t(`Sync again in ${this.clock}`, `Segerak lagi dalam ${this.clock}`);
            return this.t('Sync now', 'Segerak sekarang');
        },
        get noteText() {
            if (this.running) return this.t('You can keep working. This finishes in the background.', 'Anda boleh terus bekerja. Ini selesai di latar belakang.');
            if (this.cooldown > 0) return this.t('You can sync once every 2 minutes.', 'Anda boleh menyegerak sekali setiap 2 minit.');
            return this.t('Sends all your cards to Google and brings back changes made there. Changes also come in by themselves every 5 minutes.',
                'Menghantar semua kad anda ke Google dan membawa balik perubahan di sana. Perubahan juga masuk sendiri setiap 5 minit.');
        },
        get clock() {
            const m = Math.floor(this.cooldown / 60);
            return `${m}:${String(this.cooldown % 60).padStart(2, '0')}`;
        },
        get resultText() {
            const r = this.result;
            if (!r) return '';
            const sent = r.done - r.failed;
            const pulled = r.pulled ? this.t(` ${r.pulled} ${r.pulled === 1 ? 'change' : 'changes'} brought back from Google.`, ` ${r.pulled} perubahan dibawa balik dari Google.`) : '';
            if (r.failed > 0) {
                return this.t(`${sent} of ${r.total} cards sent. ${r.failed} could not be sent; use Retry below, or Sync now.`,
                    `${sent} daripada ${r.total} kad dihantar. ${r.failed} tidak dapat dihantar; guna Cuba lagi di bawah, atau Segerak sekarang.`) + pulled;
            }
            return (r.total === 0
                ? this.t('Nothing to send: no open cards with a due date.', 'Tiada apa untuk dihantar: tiada kad terbuka yang bertarikh akhir.')
                : this.t(`All ${r.total} ${r.total === 1 ? 'card' : 'cards'} sent.`, `Kesemua ${r.total} kad dihantar.`)) + pulled;
        },

        friendly(message) {
            if (/revoked/i.test(message || '')) return this.t('Google access was removed.', 'Akses Google telah dibuang.');
            return this.t('Google did not accept it. Tried 5 times.', 'Google tidak menerimanya. Dicuba 5 kali.');
        },

        async post(url) {
            let res;
            try {
                res = await fetch(url, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': csrf(), Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
            } catch {
                this.$store.toast.error(this.t('Could not reach AmanahKu. Check your connection.', 'Tidak dapat menghubungi AmanahKu. Semak sambungan anda.'));
                return null;
            }
            const data = await res.json().catch(() => ({}));
            if (res.status === 429) {
                this.cooldown = data.retry_after || 60;
                this.countdown();
                this.$store.toast.info(this.t(`You can sync again in ${this.clock}.`, `Anda boleh menyegerak lagi dalam ${this.clock}.`));
                return null;
            }
            if (!res.ok) {
                this.$store.toast.error(data.message || this.t('Something went wrong. Try again.', 'Ada masalah. Cuba lagi.'));
                if (res.status === 409) this.refresh();
                return null;
            }
            return data;
        },

        async syncNow() {
            if (this.running || this.cooldown > 0) return;
            this.result = null;
            const data = await this.post(this.urls.sync);
            if (!data) return;
            this.apply(data);
            this.watch();
        },

        async retry(id) {
            if (this.running || this.cooldown > 0) return;
            const data = await this.post(this.urls.retry.replace('__ID__', id));
            if (!data) return;
            this.apply(data);
            this.$store.toast.info(this.t('Sending that card again…', 'Menghantar kad itu semula…'));
            setTimeout(() => this.refresh(), 3000);
        },

        async refresh() {
            try {
                const res = await fetch(this.urls.status, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
                if (res.ok) this.apply(await res.json());
            } catch {
                // A missed poll is retried on the next tick.
            }
        },

        apply(data) {
            this.s = data;
            if ((data.retry_after || 0) > this.cooldown) {
                this.cooldown = data.retry_after;
                this.countdown();
            }
        },

        // Poll while a sync runs; announce the outcome once when it stops.
        watch() {
            this.watching = true;
            clearInterval(this.poller);
            this.poller = setInterval(async () => {
                await this.refresh();
                if (!this.running) {
                    clearInterval(this.poller);
                    if (this.watching) this.finished();
                    this.watching = false;
                }
            }, 2000);
        },

        finished() {
            const p = this.s.progress;
            if (!p) return;
            if (p.state === 'expired') {
                this.open = true;
                this.$store.toast.error(this.t('Google Calendar access has expired. Reconnect to keep syncing.', 'Akses Kalendar Google telah tamat. Sambung semula untuk terus menyegerak.'));
                return;
            }
            this.result = p;
            if (p.failed > 0) {
                this.$store.toast.error(this.t(`Google Calendar: ${p.failed} of ${p.total} cards could not be sent`, `Kalendar Google: ${p.failed} daripada ${p.total} kad tidak dapat dihantar`));
            } else {
                this.$store.toast.success(this.t(`Google Calendar synced · ${p.total} ${p.total === 1 ? 'card' : 'cards'} sent`, `Kalendar Google disegerak · ${p.total} kad dihantar`));
            }
        },

        countdown() {
            clearInterval(this.ticker);
            this.ticker = setInterval(() => {
                this.cooldown = Math.max(0, this.cooldown - 1);
                if (this.cooldown === 0) clearInterval(this.ticker);
            }, 1000);
        },
    }));
}
```

Check `$store.toast.info` exists in `resources/js/toast.js` (it documents `.info`). If not, use `.show(msg, 'info')`.

`resources/js/app.js`: add `import { registerCalendarSync } from './calendar-sync';` alphabetically after the `registerAppearanceCard` import, and `registerCalendarSync(Alpine);` next to `registerWorkBoard(Alpine);`.

Profile + BuildsPeopleData deletions as listed under Files. After deleting, `grep -n "googleCalendarConnected\|calendarSyncIssues\|google-calendar.retry" -r app resources routes` must return nothing.

- [ ] **Step 4: Run tests, build, look at it**

```bash
php artisan test --compact tests/Feature/CalendarSyncControlTest.php
php artisan test --compact --filter='Profile|Board'
lerd artisan view:clear && lerd artisan view:cache && bun run build
```

Browser check at `http://localhost:9100` (quick-login `shazwanshah.unijaya@gmail.com`, password `password`), open the T.A.A. board. Use real headless Chromium from `~/.cache/ms-playwright` (clicks don't work in Obscura). Check: pill renders at the right of the first chip row, opens and closes on click / Escape / outside click, "Not connected" copy + Connect link, phone width (400px) panel stays on screen, BM toggle switches copy, Profile tab has no calendar block, no console errors. Local `.env` has `PORT_CALENDAR_DRIVER=stub`: Sync now will "succeed" through the stub, which is fine for checking the progress, toast and countdown.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/views/partials/board-calendar-sync.blade.php resources/js/calendar-sync.js resources/js/app.js resources/views/screens/board.blade.php resources/views/screens/profile.blade.php app/Http/Controllers/Concerns/BuildsWorkData.php app/Http/Controllers/Concerns/BuildsPeopleData.php tests/Feature/CalendarSyncControlTest.php public/build
git commit -m "feat(board): Google Calendar status and Sync now on the task board; removed from Profile"
```

---

### Task 8: Whole-feature check

- [ ] **Step 1:** `php artisan test --compact` (full suite). All green. Any red outside the files touched: read it, fix only if caused by this work.
- [ ] **Step 2:** `vendor/bin/phpstan analyse --memory-limit=1G` (CI runs it). Fix new errors in touched files.
- [ ] **Step 3:** `git status` clean except intended files; `git diff --stat main...dev -- tests/Acceptance` shows nothing from this work.
- [ ] **Step 4:** Commit any fixes: `git commit -m "fix(calendar): <what> found in the full-suite run"`.
