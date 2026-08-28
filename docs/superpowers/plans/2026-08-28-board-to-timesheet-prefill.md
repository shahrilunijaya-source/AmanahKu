# Board-to-timesheet prefill Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Record when a work item card sits in the In Progress column, then use those windows to prefill each day of the timesheet capture week with the cards worked that day, leaving only description and percentage to fill.

**Architecture:** `WorkItemObserver` writes a stint row when a card enters and leaves `prog`. A new read-only `App\Timesheet\BoardSuggestions` turns those stints into per-day suggested rows for a week, filling category/project from the card's last logged entry (falling back to the card's project). The capture screen renders them into the grid as unsaved suggestions; only rows the person gives a percentage reach the server. `timesheet_entries.work_item_id` ties a saved row back to its card so it is not suggested twice and its category carries forward.

**Tech Stack:** Laravel 13, PHP 8.5, PHPUnit 12, Alpine 3, Tailwind 4. Tests run on sqlite in-memory (`phpunit.xml:32-33`).

**Spec:** `docs/superpowers/specs/2026-08-28-board-to-timesheet-prefill-design.md`

## Global Constraints

- Branch: `feature/board-timesheet-prefill`, off `dev`. Do not push to any remote.
- Commands run through lerd: `lerd artisan …`, `bun run build`. Never `npm`/`npx`/`node`.
- Run `vendor/bin/pint --dirty --format agent` before every commit that touches PHP.
- Every tenant-owned model uses `App\Models\Concerns\BelongsToTenant`. `Tenant` itself must not.
- `BelongsToTenant` **throws** on a create with no active tenant and no explicit `tenant_id` (fail-closed, AK-DB-06). Any write from an observer must pass `tenant_id` explicitly.
- Route-model binding is not tenant-scoped in this app. Any id arriving from a request must have its tenant and its ownership checked explicitly in the controller.
- `timesheet_entries.source` keeps its existing meaning — "generated and owned by the server" (leave / public holiday). Board rows leave it **null**; `TimesheetController` drops non-null-source rows from the grid payload.
- All user-facing strings are bilingual, driven by `$store.ui.lang === 'en'` in Blade, matching the surrounding markup.
- No new Composer or JS dependencies.
- Tests: PHPUnit classes, no Pest. Models are created inline (this repo has only `UserFactory`).
- Type hints and return types on every method; PHPDoc array shapes where an array is passed.

---

### Task 1: Record In Progress stints

**Files:**
- Create: `database/migrations/2026_08_28_100000_create_work_item_progress_stints_table.php`
- Create: `app/Models/WorkItemProgressStint.php`
- Modify: `app/Models/WorkItem.php` (add `progressStints()` relation)
- Modify: `app/Observers/WorkItemObserver.php` (add stint bookkeeping to `saved()`)
- Test: `tests/Feature/WorkItemProgressStintTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - table `work_item_progress_stints` with columns `id, tenant_id, work_item_id, started_at, ended_at, created_at, updated_at`
  - `App\Models\WorkItemProgressStint` (uses `BelongsToTenant`, `$guarded = []`, casts `started_at` and `ended_at` to `datetime`, `workItem(): BelongsTo`)
  - `WorkItem::progressStints(): HasMany`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/WorkItemProgressStintTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkItem;
use App\Models\WorkItemProgressStint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * A card's time in the In Progress column is the only record of which days it was
 * worked — the timesheet's prefill reads nothing else. Each move in opens a stint,
 * each move out closes it, and a card that bounces back in gets a second one.
 */
class WorkItemProgressStintTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-26 09:00:00');

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);

        $user = User::create(['name' => 'Demo', 'email' => 'demo@example.com', 'password' => Hash::make('password')]);
        $user->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $this->employee = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'name' => 'Demo', 'status' => 'active', 'workload' => 'green',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function card(array $attrs = []): WorkItem
    {
        return $this->employee->workItems()->create(array_merge([
            'tenant_id' => $this->tenant->id, 'title' => 'X', 'type' => 'task',
            'priority' => 'low', 'status' => 'todo', 'progress' => 0,
        ], $attrs));
    }

    public function test_moving_a_card_into_in_progress_opens_a_stint(): void
    {
        $card = $this->card();

        $card->update(['status' => 'prog']);

        $stints = WorkItemProgressStint::where('work_item_id', $card->id)->get();
        $this->assertCount(1, $stints);
        $this->assertNull($stints->first()->ended_at);
        $this->assertSame('2026-08-26 09:00:00', $stints->first()->started_at->toDateTimeString());
    }

    public function test_moving_a_card_out_of_in_progress_closes_the_stint(): void
    {
        $card = $this->card(['status' => 'prog']);

        Carbon::setTestNow('2026-08-27 17:00:00');
        $card->update(['status' => 'review']);

        $stint = WorkItemProgressStint::where('work_item_id', $card->id)->sole();
        $this->assertSame('2026-08-27 17:00:00', $stint->ended_at->toDateTimeString());
    }

    public function test_a_card_that_bounces_back_gets_a_second_stint(): void
    {
        $card = $this->card(['status' => 'prog']);
        $card->update(['status' => 'review']);

        Carbon::setTestNow('2026-08-28 09:00:00');
        $card->update(['status' => 'prog']);

        $stints = WorkItemProgressStint::where('work_item_id', $card->id)->orderBy('started_at')->get();
        $this->assertCount(2, $stints);
        $this->assertNotNull($stints[0]->ended_at);
        $this->assertNull($stints[1]->ended_at);
    }

    public function test_creating_a_card_straight_into_in_progress_opens_a_stint(): void
    {
        $card = $this->card(['status' => 'prog']);

        $this->assertSame(1, WorkItemProgressStint::where('work_item_id', $card->id)->count());
    }

    public function test_archiving_an_in_progress_card_closes_its_stint(): void
    {
        $card = $this->card(['status' => 'prog']);

        Carbon::setTestNow('2026-08-28 12:00:00');
        $card->update(['archived_at' => Carbon::now()]);

        $stint = WorkItemProgressStint::where('work_item_id', $card->id)->sole();
        $this->assertSame('2026-08-28 12:00:00', $stint->ended_at->toDateTimeString());
    }

    public function test_a_status_change_that_never_touches_in_progress_writes_no_stint(): void
    {
        $card = $this->card();

        $card->update(['status' => 'review']);

        $this->assertSame(0, WorkItemProgressStint::where('work_item_id', $card->id)->count());
    }

    public function test_a_stint_carries_the_cards_tenant_even_with_no_active_tenant_context(): void
    {
        $card = $this->card();

        app(\App\Tenancy\CurrentTenant::class)->forget();
        $card->update(['status' => 'prog']);

        $stint = WorkItemProgressStint::withoutGlobalScope('tenant')
            ->where('work_item_id', $card->id)->sole();
        $this->assertSame($this->tenant->id, $stint->tenant_id);
    }
}
```

Before writing this test, open `app/Tenancy/CurrentTenant.php` and confirm the
method that clears the active tenant. If it is not `forget()`, use the real
method name in `test_a_stint_carries_the_cards_tenant_even_with_no_active_tenant_context`.

- [ ] **Step 2: Run the test to verify it fails**

Run: `lerd artisan test --compact tests/Feature/WorkItemProgressStintTest.php`
Expected: FAIL — `Class "App\Models\WorkItemProgressStint" not found`

- [ ] **Step 3: Create the migration**

```bash
lerd artisan make:migration create_work_item_progress_stints_table --no-interaction
```

Rename the generated file to `2026_08_28_100000_create_work_item_progress_stints_table.php` and write:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per visit a card makes to the In Progress column. A separate table rather
     * than a pair of columns on work_items: two columns remember only the most recent
     * visit, so a card that bounces prog -> review -> prog would lose its earlier days,
     * and those days are exactly what the timesheet prefill reads.
     */
    public function up(): void
    {
        Schema::create('work_item_progress_stints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_item_id')->constrained()->cascadeOnDelete();
            $table->dateTime('started_at');
            $table->dateTime('ended_at')->nullable();
            $table->timestamps();

            $table->index(['work_item_id', 'started_at']);
            // The week lookup filters by tenant over a date range.
            $table->index(['tenant_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_item_progress_stints');
    }
};
```

- [ ] **Step 4: Create the model**

```bash
lerd artisan make:model WorkItemProgressStint --no-interaction
```

Write `app/Models/WorkItemProgressStint.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One visit a work item card made to the In Progress column. `ended_at` is null while
 * the card is still there.
 *
 * @property Carbon $started_at
 * @property Carbon|null $ended_at
 */
class WorkItemProgressStint extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'ended_at' => 'datetime'];
    }

    /** @return BelongsTo<WorkItem, $this> */
    public function workItem(): BelongsTo
    {
        return $this->belongsTo(WorkItem::class);
    }
}
```

- [ ] **Step 5: Add the relation on WorkItem**

In `app/Models/WorkItem.php`, after `comments()`:

```php
    /** Every visit this card has made to the In Progress column, oldest first. */
    /** @return HasMany<WorkItemProgressStint, $this> */
    public function progressStints(): HasMany
    {
        return $this->hasMany(WorkItemProgressStint::class)->orderBy('started_at');
    }
```

- [ ] **Step 6: Write the observer bookkeeping**

In `app/Observers/WorkItemObserver.php`, add to the top of `saved()` — **before** the
existing calendar-sync early return, which would otherwise skip stints on saves that
are irrelevant to the calendar:

```php
        $this->recordProgressStint($item);
```

Then add the private method:

```php
    /**
     * Keep work_item_progress_stints in step with the card's column. This is the only
     * record of which days a card was worked — the timesheet prefill reads nothing else,
     * and nothing can reconstruct it after the fact.
     *
     * Runs on every save, ahead of the calendar-sync early return: a status move is
     * relevant here even when it changes nothing the calendar cares about.
     *
     * tenant_id is passed explicitly rather than left to BelongsToTenant: this observer
     * fires on paths with no active tenant context (the MCP tools, console commands),
     * where the trait's fail-closed guard would throw.
     */
    private function recordProgressStint(WorkItem $item): void
    {
        $wasProg = $item->getOriginal('status') === 'prog';
        $isProg = $item->status === 'prog';

        // Archiving parks a card without moving it out of the column; the stint must
        // still close, or an archived card keeps being suggested for every later day.
        $justArchived = $item->isDirty('archived_at') && $item->archived_at !== null;

        if ($isProg && ! $justArchived && ! $wasProg) {
            $this->closeOpenStints($item);

            WorkItemProgressStint::create([
                'tenant_id' => $item->tenant_id,
                'work_item_id' => $item->id,
                'started_at' => now(),
            ]);

            return;
        }

        if ($wasProg && ! $isProg) {
            $this->closeOpenStints($item);

            return;
        }

        if ($isProg && $justArchived) {
            $this->closeOpenStints($item);
        }
    }

    /**
     * Close every stint still open on a card. Normally there is at most one; the loop
     * is what makes a dangling stint (a status change that somehow bypassed this
     * observer) self-heal on the card's next move rather than leaving the card
     * suggested forever.
     */
    private function closeOpenStints(WorkItem $item): void
    {
        WorkItemProgressStint::withoutGlobalScope('tenant')
            ->where('work_item_id', $item->id)
            ->whereNull('ended_at')
            ->update(['ended_at' => now()]);
    }
```

Add `use App\Models\WorkItemProgressStint;` to the file's imports.

Note on `getOriginal('status')`: on create it returns null, so a card created directly
in `prog` takes the "entered" branch — which is what
`test_creating_a_card_straight_into_in_progress_opens_a_stint` asserts. The existing
docblock in this file explains why `isDirty`/`getOriginal` are correct inside `saved()`.

- [ ] **Step 7: Run the tests to verify they pass**

Run: `lerd artisan test --compact tests/Feature/WorkItemProgressStintTest.php`
Expected: PASS, 7 tests.

- [ ] **Step 8: Check the existing observer tests still pass**

Run: `lerd artisan test --compact tests/Feature/WorkItemObserverTest.php tests/Feature/SyncWorkItemCalendarEventJobTest.php`
Expected: PASS. If a calendar test now fails, the `recordProgressStint()` call was
placed after the early return or is mutating something it should not — fix that, do
not change the calendar assertions.

- [ ] **Step 9: Migrate the dev database**

Run: `lerd artisan migrate`

The dev database is a production copy and does not get rebuilt by the test suite; the
app 500s on a missing table even when tests are green.

- [ ] **Step 10: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations app/Models/WorkItemProgressStint.php app/Models/WorkItem.php app/Observers/WorkItemObserver.php tests/Feature/WorkItemProgressStintTest.php
git commit -m "feat(board): record when a card enters and leaves In Progress

The board only ever knew where a card is now. The timesheet needs to know which
days it was worked, which nothing can reconstruct after the fact, so each visit
to the In Progress column is written down as it happens."
```

---

### Task 2: Tie a timesheet entry to the card it came from

**Files:**
- Create: `database/migrations/2026_08_28_100100_add_work_item_id_to_timesheet_entries.php`
- Modify: `app/Models/TimesheetEntry.php` (add `workItem()` relation)
- Modify: `app/Http/Controllers/TimesheetController.php:117-133` (`existingGrid`), `:220-235` (validation), plus a new ownership guard
- Modify: `app/Timesheet/WeekWriter.php:296-312` (`lineKey`), `:375-412` (`normaliseEntries`)
- Modify: `resources/js/timesheet-capture.js:102-114` (grid seed), and the save payload builder
- Test: `tests/Feature/TimesheetWorkItemLinkTest.php`

**Interfaces:**
- Consumes: Task 1's `work_items` cards (only as a foreign key target).
- Produces:
  - `timesheet_entries.work_item_id` (nullable FK, `nullOnDelete`)
  - `TimesheetEntry::workItem(): BelongsTo`
  - `WeekWriter::lineKey()` gains `work_item_id` as its fifth part
  - `existingGrid` rows gain a `work_item_id` key (int or null)
  - `POST /app/timesheets` accepts `entries.*.work_item_id`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/TimesheetWorkItemLinkTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\TimesheetCategory;
use App\Models\TimesheetEntry;
use App\Models\User;
use App\Models\WorkItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * A saved row remembers the board card it came from. That link is what stops the same
 * card being suggested twice for a day, and what carries its category to the rest of
 * the week — so it has to survive a second save of the same week, and it has to be
 * refused when it points at somebody else's card.
 */
class TimesheetWorkItemLinkTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Employee $employee;

    private User $user;

    private TimesheetCategory $work;

    protected function setUp(): void
    {
        parent::setUp();

        // A Wednesday, so the whole test week is in the past and inside the backfill window.
        Carbon::setTestNow('2026-08-26 09:00:00');

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $this->work = TimesheetCategory::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Others', 'requires_project' => false,
        ]);

        $this->user = User::create(['name' => 'Staffer', 'email' => 'staffer@example.com', 'password' => Hash::make('password')]);
        $this->user->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $this->employee = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->user->id,
            'name' => 'Staffer', 'status' => 'active', 'workload' => 'green',
        ]);

        $this->actingAs($this->user)->withSession(['current_tenant' => $this->tenant->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function card(?Employee $owner = null, array $attrs = []): WorkItem
    {
        $owner ??= $this->employee;

        return $owner->workItems()->create(array_merge([
            'tenant_id' => $owner->tenant_id, 'title' => 'Card', 'type' => 'task',
            'priority' => 'low', 'status' => 'prog', 'progress' => 0,
        ], $attrs));
    }

    /** @param array<int, array<string, mixed>> $entries */
    private function save(array $entries): TestResponse
    {
        return $this->post('/app/timesheets', [
            'week_start' => '2026-08-24',
            'entries' => $entries,
        ]);
    }

    public function test_a_saved_row_keeps_the_card_it_came_from(): void
    {
        $card = $this->card();

        $this->save([
            ['entry_date' => '2026-08-25', 'category_id' => $this->work->id, 'percentage' => 100, 'work_item_id' => $card->id],
        ])->assertSessionHasNoErrors();

        $this->assertSame($card->id, TimesheetEntry::sole()->work_item_id);
    }

    public function test_the_link_survives_a_second_save_of_the_same_week(): void
    {
        $card = $this->card();

        $this->save([
            ['entry_date' => '2026-08-25', 'category_id' => $this->work->id, 'percentage' => 60, 'work_item_id' => $card->id],
        ])->assertSessionHasNoErrors();

        // The capture screen reloads the week and saves it back whole. Rebuild the payload
        // the way the screen does — from what the server hands back — not from the card.
        $reloaded = TimesheetEntry::sole();
        $this->save([
            [
                'entry_date' => '2026-08-25', 'category_id' => $reloaded->category_id,
                'percentage' => 80, 'work_item_id' => $reloaded->work_item_id,
            ],
        ])->assertSessionHasNoErrors();

        $this->assertSame($card->id, TimesheetEntry::sole()->work_item_id);
    }

    public function test_two_cards_on_the_same_category_and_project_are_not_a_duplicate_line(): void
    {
        $one = $this->card();
        $two = $this->card();

        $this->save([
            ['entry_date' => '2026-08-25', 'category_id' => $this->work->id, 'percentage' => 50, 'work_item_id' => $one->id],
            ['entry_date' => '2026-08-25', 'category_id' => $this->work->id, 'percentage' => 50, 'work_item_id' => $two->id],
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, TimesheetEntry::count());
    }

    public function test_the_same_card_twice_on_one_day_is_still_a_duplicate_line(): void
    {
        $card = $this->card();

        $response = $this->save([
            ['entry_date' => '2026-08-25', 'category_id' => $this->work->id, 'percentage' => 50, 'work_item_id' => $card->id],
            ['entry_date' => '2026-08-25', 'category_id' => $this->work->id, 'percentage' => 50, 'work_item_id' => $card->id],
        ]);

        $response->assertSessionHasErrors('entries');
    }

    public function test_a_card_belonging_to_another_employee_is_refused(): void
    {
        $other = User::create(['name' => 'Other', 'email' => 'other@example.com', 'password' => Hash::make('password')]);
        $other->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $otherEmployee = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $other->id,
            'name' => 'Other', 'status' => 'active', 'workload' => 'green',
        ]);

        $foreign = $this->card($otherEmployee);

        $this->save([
            ['entry_date' => '2026-08-25', 'category_id' => $this->work->id, 'percentage' => 100, 'work_item_id' => $foreign->id],
        ])->assertSessionHasErrors('entries.0.work_item_id');

        $this->assertSame(0, TimesheetEntry::count());
    }

    public function test_a_card_from_another_tenant_is_refused(): void
    {
        $otherTenant = Tenant::create(['slug' => 'other', 'name' => 'Other Co', 'initials' => 'OC']);
        $otherUser = User::create(['name' => 'Far', 'email' => 'far@example.com', 'password' => Hash::make('password')]);
        $otherUser->tenants()->attach($otherTenant->id, ['role' => 'employee']);
        $farEmployee = Employee::create([
            'tenant_id' => $otherTenant->id, 'user_id' => $otherUser->id,
            'name' => 'Far', 'status' => 'active', 'workload' => 'green',
        ]);

        $foreign = $farEmployee->workItems()->create([
            'tenant_id' => $otherTenant->id, 'title' => 'Theirs', 'type' => 'task',
            'priority' => 'low', 'status' => 'prog', 'progress' => 0,
        ]);

        $this->save([
            ['entry_date' => '2026-08-25', 'category_id' => $this->work->id, 'percentage' => 100, 'work_item_id' => $foreign->id],
        ])->assertSessionHasErrors('entries.0.work_item_id');
    }

    public function test_a_card_the_person_participates_in_is_accepted(): void
    {
        $other = User::create(['name' => 'Owner', 'email' => 'owner@example.com', 'password' => Hash::make('password')]);
        $other->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $owner = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $other->id,
            'name' => 'Owner', 'status' => 'active', 'workload' => 'green',
        ]);

        $shared = $this->card($owner);
        $shared->participants()->attach($this->employee->id);

        $this->save([
            ['entry_date' => '2026-08-25', 'category_id' => $this->work->id, 'percentage' => 100, 'work_item_id' => $shared->id],
        ])->assertSessionHasNoErrors();

        $this->assertSame($shared->id, TimesheetEntry::sole()->work_item_id);
    }

    public function test_a_row_with_no_card_still_saves(): void
    {
        $this->save([
            ['entry_date' => '2026-08-25', 'category_id' => $this->work->id, 'percentage' => 100],
        ])->assertSessionHasNoErrors();

        $this->assertNull(TimesheetEntry::sole()->work_item_id);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `lerd artisan test --compact tests/Feature/TimesheetWorkItemLinkTest.php`
Expected: FAIL — `work_item_id` is not a column, so the first test errors on the insert
or returns null.

- [ ] **Step 3: Create the migration**

```bash
lerd artisan make:migration add_work_item_id_to_timesheet_entries --no-interaction
```

Rename to `2026_08_28_100100_add_work_item_id_to_timesheet_entries.php` and write:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('timesheet_entries', function (Blueprint $table) {
            // The board card this row was logged against. Null for a row the staffer
            // typed. Two jobs: stop the prefill offering a card on a day it is already
            // logged, and carry that card's category to the rest of its week.
            $table->foreignId('work_item_id')->nullable()->after('source')
                ->constrained('work_items')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('timesheet_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('work_item_id');
        });
    }
};
```

- [ ] **Step 4: Add the relation on TimesheetEntry**

In `app/Models/TimesheetEntry.php`, after `subPillar()`:

```php
    /** The board card this row was logged against, if it came from one. */
    /** @return BelongsTo<WorkItem, $this> */
    public function workItem(): BelongsTo
    {
        return $this->belongsTo(WorkItem::class);
    }
```

- [ ] **Step 5: Make the card part of a line's identity**

In `app/Timesheet/WeekWriter.php`, extend `lineKey()`:

```php
    public static function lineKey(array $entry): string
    {
        return implode('|', [
            Carbon::parse($entry['entry_date'])->toDateString(),
            $entry['category_id'],
            $entry['project_id'] ?? '',
            $entry['sub_pillar_id'] ?? '',
            // Two different board cards are two different lines even when they share a
            // category and project — which they usually do, since most projects map to a
            // single category. Without this, a day with two cards from one project is
            // rejected as "the same work listed twice". A typed row carries no card and
            // keys exactly as it did before.
            $entry['work_item_id'] ?? '',
```

(keep the existing trailing part of the `implode` array and the closing `]);` as they are)

This deliberately loosens the guard in one direction: a card-linked row and a typed row
that otherwise match are no longer flagged as duplicates. That is accepted — the person
added the second line on purpose. Do not "fix" it.

- [ ] **Step 6: Carry the column through normalisation and the MCP merge**

In `app/Timesheet/WeekWriter.php`, inside `normaliseEntries()`'s `$out[] = [...]`, add
after the `'description'` line:

```php
                'work_item_id' => $e['work_item_id'] ?? null,
```

**Then `existingUserEntries()` in the same file.** It maps stored rows to a fixed array
that does not include `work_item_id`, and `mergePartialIntoExisting()` keys those rows
with `lineKey()` to decide upsert-versus-add. Left as it is, a stored board row keys as
`''` while the incoming row keys with the card id — the same line is treated as an
addition and duplicated — and the merged week loses the link entirely. Add to that map,
after `'description'`:

```php
                'work_item_id' => $e->work_item_id,
```

- [ ] **Step 7: Accept and authorise the field on save**

In `app/Http/Controllers/TimesheetController.php`'s `store()`, add to the validation
array after `entries.*.description`:

```php
            'entries.*.work_item_id' => ['nullable', 'integer'],
```

Then, immediately after `$data = $request->validate([...]);`, add the ownership guard:

```php
        $this->assertOwnedWorkItems($data['entries'], $employee);
```

And add the private method to the controller:

```php
    /**
     * work_item_id arrives from the browser, so it is a trust boundary. An `exists` rule
     * is not enough here: model lookups in this app are not tenant-scoped, so a bare
     * existence check would happily accept another tenant's — or another colleague's —
     * card id. A foreign id would corrupt the prefill's "already logged" check, hand the
     * staffer a category read off somebody else's entry, and skew per-card figures.
     *
     * Accepted: a card in the active tenant that this employee owns or participates in —
     * the same membership rule BoardSuggestions applies.
     *
     * @param  array<int, array<string, mixed>>  $entries
     */
    private function assertOwnedWorkItems(array $entries, Employee $employee): void
    {
        $ids = collect($entries)->pluck('work_item_id')->filter()->map(fn ($id) => (int) $id)->unique();

        if ($ids->isEmpty()) {
            return;
        }

        $allowed = WorkItem::whereIn('id', $ids)
            ->where(fn ($q) => $q->where('employee_id', $employee->id)
                ->orWhereHas('participants', fn ($p) => $p->where('employees.id', $employee->id)))
            ->pluck('id')
            ->all();

        $problems = [];
        foreach ($entries as $i => $entry) {
            $id = $entry['work_item_id'] ?? null;
            if ($id !== null && ! in_array((int) $id, $allowed, true)) {
                $problems["entries.$i.work_item_id"] = 'That task is not yours.';
            }
        }

        if ($problems !== []) {
            throw ValidationException::withMessages($problems);
        }
    }
```

`WorkItem`'s `BelongsToTenant` global scope covers the tenant half of that query, so
no explicit `tenant_id` filter is needed — but confirm by running the cross-tenant test,
not by reading. Add `use Illuminate\Validation\ValidationException;` to the controller's
imports if it is not already there.

- [ ] **Step 8: Round-trip the column through the grid**

In `app/Http/Controllers/TimesheetController.php`'s `existingGrid` loop, add to the row
array after `'description'`:

```php
                    'work_item_id' => $e->work_item_id,
```

In `resources/js/timesheet-capture.js`'s `init()` seed (around line 107), add to the
mapped row object:

```js
                    work_item_id: e.work_item_id || null,
```

Then find where the save payload's entries are built (search the file for
`entry_date:` inside the save/autosave method) and include `work_item_id: r.work_item_id || null`
on each row. Without this the second save of a week strips the link.

- [ ] **Step 9: Run the tests to verify they pass**

Run: `lerd artisan test --compact tests/Feature/TimesheetWorkItemLinkTest.php`
Expected: PASS, 8 tests.

- [ ] **Step 10: Run the timesheet suite for regressions**

Run: `lerd artisan test --compact --filter=Timesheet`
Expected: PASS. `lineKey()` is shared with the MCP merge path, so a failure in an MCP
or submit test means the key change broke an assumption — read the failure before
changing anything.

- [ ] **Step 11: Migrate and commit**

```bash
lerd artisan migrate
vendor/bin/pint --dirty --format agent
git add database/migrations app/Models/TimesheetEntry.php app/Timesheet/WeekWriter.php app/Http/Controllers/TimesheetController.php resources/js/timesheet-capture.js tests/Feature/TimesheetWorkItemLinkTest.php
git commit -m "feat(timesheet): remember which board card an entry came from

The link is what stops a card being offered twice for the same day and what
carries its category across the rest of its week, so it has to survive the
whole-week resave and be refused when it points at someone else's card."
```

---

### Task 3: Work out which cards belong on which day

**Files:**
- Create: `app/Timesheet/BoardSuggestions.php`
- Test: `tests/Feature/BoardSuggestionsTest.php`

**Interfaces:**
- Consumes: `WorkItemProgressStint` (Task 1), `timesheet_entries.work_item_id` (Task 2), `App\Timesheet\LockedDays`, `App\Timesheet\WeekWriter::BACKFILL_WEEKS`.
- Produces:
  ```php
  App\Timesheet\BoardSuggestions::forWeek(Employee $employee, CarbonInterface|string $weekStart): array
  // ['2026-08-25' => [[
  //     'work_item_id' => int, 'title' => string, 'category_id' => ?int,
  //     'project_id' => ?int, 'sub_pillar_id' => ?int, 'description' => string,
  // ], …], …]
  ```

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/BoardSuggestionsTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Project;
use App\Models\PublicHoliday;
use App\Models\Tenant;
use App\Models\TimesheetCategory;
use App\Models\User;
use App\Models\WorkItem;
use App\Models\WorkItemProgressStint;
use App\Timesheet\BoardSuggestions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Which In Progress cards belong on which day of a capture week. Read-only: this class
 * proposes rows, the staffer decides. Anything it gets wrong shows up as a row somebody
 * has to delete by hand every week, so the day boundaries matter more than they look.
 */
class BoardSuggestionsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Employee $employee;

    private TimesheetCategory $work;

    private BoardSuggestions $suggestions;

    /** Monday of the test week. Today is Wednesday of that same week. */
    private const WEEK = '2026-08-24';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-26 09:00:00');

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $this->work = TimesheetCategory::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Delivery', 'requires_project' => true,
        ]);

        $user = User::create(['name' => 'Staffer', 'email' => 'staffer@example.com', 'password' => Hash::make('password')]);
        $user->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $this->employee = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'name' => 'Staffer', 'status' => 'active', 'workload' => 'green',
        ]);

        $this->actingAs($user)->withSession(['current_tenant' => $this->tenant->id]);
        $this->suggestions = app(BoardSuggestions::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function card(array $attrs = [], ?Employee $owner = null): WorkItem
    {
        $owner ??= $this->employee;

        return $owner->workItems()->create(array_merge([
            'tenant_id' => $owner->tenant_id, 'title' => 'Build the thing', 'type' => 'task',
            'priority' => 'low', 'status' => 'prog', 'progress' => 0,
        ], $attrs));
    }

    private function stint(WorkItem $card, string $from, ?string $to): void
    {
        // Written directly, not through the observer: these tests are about reading
        // stints back, and hand-written ones let a stint start before "now".
        WorkItemProgressStint::withoutGlobalScope('tenant')->create([
            'tenant_id' => $card->tenant_id, 'work_item_id' => $card->id,
            'started_at' => $from, 'ended_at' => $to,
        ]);
    }

    /** @return array<int, int> the work_item_ids suggested for a day */
    private function idsOn(array $result, string $day): array
    {
        return array_column($result[$day] ?? [], 'work_item_id');
    }

    public function test_a_card_is_suggested_on_every_day_of_its_stint(): void
    {
        $card = $this->card();
        $this->stint($card, '2026-08-25 10:00:00', '2026-08-26 08:00:00');

        $result = $this->suggestions->forWeek($this->employee, self::WEEK);

        $this->assertSame([], $this->idsOn($result, '2026-08-24'));
        $this->assertSame([$card->id], $this->idsOn($result, '2026-08-25'));
        $this->assertSame([$card->id], $this->idsOn($result, '2026-08-26'));
        $this->assertSame([], $this->idsOn($result, '2026-08-27'));
    }

    public function test_an_open_stint_runs_to_today_and_no_further(): void
    {
        $card = $this->card();
        $this->stint($card, '2026-08-24 09:00:00', null);

        $result = $this->suggestions->forWeek($this->employee, self::WEEK);

        $this->assertSame([$card->id], $this->idsOn($result, '2026-08-24'));
        $this->assertSame([$card->id], $this->idsOn($result, '2026-08-26'));
        // Thursday and Friday have not happened yet.
        $this->assertSame([], $this->idsOn($result, '2026-08-27'));
        $this->assertSame([], $this->idsOn($result, '2026-08-28'));
    }

    public function test_a_card_already_logged_on_a_day_is_not_suggested_again_for_that_day(): void
    {
        $card = $this->card();
        $this->stint($card, '2026-08-24 09:00:00', null);
        $this->logEntry($card, '2026-08-25');

        $result = $this->suggestions->forWeek($this->employee, self::WEEK);

        $this->assertSame([], $this->idsOn($result, '2026-08-25'));
        $this->assertSame([$card->id], $this->idsOn($result, '2026-08-24'));
    }

    public function test_a_public_holiday_receives_no_suggestions(): void
    {
        PublicHoliday::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Merdeka', 'date' => '2026-08-25',
        ]);
        $card = $this->card();
        $this->stint($card, '2026-08-24 09:00:00', null);

        $result = $this->suggestions->forWeek($this->employee, self::WEEK);

        $this->assertSame([], $this->idsOn($result, '2026-08-25'));
        $this->assertSame([$card->id], $this->idsOn($result, '2026-08-24'));
    }

    public function test_a_colleagues_card_is_not_suggested_but_a_shared_one_is(): void
    {
        $otherUser = User::create(['name' => 'Other', 'email' => 'other@example.com', 'password' => Hash::make('password')]);
        $otherUser->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $other = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $otherUser->id,
            'name' => 'Other', 'status' => 'active', 'workload' => 'green',
        ]);

        $theirs = $this->card([], $other);
        $this->stint($theirs, '2026-08-24 09:00:00', null);

        $shared = $this->card([], $other);
        $shared->participants()->attach($this->employee->id);
        $this->stint($shared, '2026-08-24 09:00:00', null);

        $result = $this->suggestions->forWeek($this->employee, self::WEEK);

        $this->assertSame([$shared->id], $this->idsOn($result, '2026-08-24'));
    }

    public function test_an_archived_card_is_not_suggested(): void
    {
        $card = $this->card(['archived_at' => Carbon::parse('2026-08-25 12:00:00')]);
        $this->stint($card, '2026-08-24 09:00:00', null);

        $result = $this->suggestions->forWeek($this->employee, self::WEEK);

        $this->assertSame([], $this->idsOn($result, '2026-08-24'));
    }

    public function test_the_category_comes_from_the_cards_last_logged_entry(): void
    {
        $project = Project::create(['tenant_id' => $this->tenant->id, 'name' => 'Apollo']);
        $card = $this->card(['project_id' => $project->id]);
        $this->stint($card, '2026-08-24 09:00:00', null);
        $this->logEntry($card, '2026-08-24', $project->id);

        $result = $this->suggestions->forWeek($this->employee, self::WEEK);
        $row = $result['2026-08-25'][0];

        $this->assertSame($this->work->id, $row['category_id']);
        $this->assertSame($project->id, $row['project_id']);
    }

    public function test_the_category_falls_back_to_the_projects_only_category(): void
    {
        $project = Project::create(['tenant_id' => $this->tenant->id, 'name' => 'Apollo']);
        $project->categories()->attach($this->work->id);
        $card = $this->card(['project_id' => $project->id]);
        $this->stint($card, '2026-08-25 09:00:00', null);

        $result = $this->suggestions->forWeek($this->employee, self::WEEK);
        $row = $result['2026-08-25'][0];

        $this->assertSame($this->work->id, $row['category_id']);
        $this->assertSame($project->id, $row['project_id']);
    }

    public function test_a_stint_spanning_the_weekend_suggests_no_saturday_or_sunday(): void
    {
        // 2026-08-29 is the last Saturday of August, not the first — not a working day.
        $card = $this->card();
        $this->stint($card, '2026-08-24 09:00:00', null);
        Carbon::setTestNow('2026-08-31 09:00:00');

        $result = $this->suggestions->forWeek($this->employee, self::WEEK);

        $this->assertSame([], $this->idsOn($result, '2026-08-29'));
        $this->assertSame([$card->id], $this->idsOn($result, '2026-08-28'));
    }

    public function test_a_card_with_no_project_is_suggested_with_a_blank_category(): void
    {
        $card = $this->card();
        $this->stint($card, '2026-08-25 09:00:00', null);

        $result = $this->suggestions->forWeek($this->employee, self::WEEK);
        $row = $result['2026-08-25'][0];

        $this->assertNull($row['category_id']);
        $this->assertNull($row['project_id']);
        $this->assertSame('Build the thing', $row['description']);
    }

    /** Log a real timesheet entry for a card, so the "already logged" and carry-forward paths have data. */
    private function logEntry(WorkItem $card, string $day, ?int $projectId = null): void
    {
        $timesheet = \App\Models\Timesheet::firstOrCreate(
            ['tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id, 'week_start' => self::WEEK],
            ['status' => 'draft', 'total_hours' => 0],
        );

        \App\Models\TimesheetEntry::create([
            'tenant_id' => $this->tenant->id, 'timesheet_id' => $timesheet->id,
            'entry_date' => $day, 'category_id' => $this->work->id, 'project_id' => $projectId,
            'percentage' => 100, 'hours' => 8, 'work_item_id' => $card->id,
        ]);
    }
}
```

Before running, confirm against `database/migrations` that `PublicHoliday` and
`Timesheet` take the columns used above (`Timesheet` in particular — check whether
`status` defaults). Adjust the inline creates to the real columns; do not change what
the tests assert.

- [ ] **Step 2: Run the test to verify it fails**

Run: `lerd artisan test --compact tests/Feature/BoardSuggestionsTest.php`
Expected: FAIL — `Class "App\Timesheet\BoardSuggestions" not found`

- [ ] **Step 3: Write the class**

```bash
lerd artisan make:class Timesheet/BoardSuggestions --no-interaction
```

Write `app/Timesheet/BoardSuggestions.php`:

```php
<?php

declare(strict_types=1);

namespace App\Timesheet;

use App\Models\Employee;
use App\Models\Project;
use App\Models\TimesheetEntry;
use App\Models\WorkItem;
use App\Models\WorkItemProgressStint;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Which In Progress board cards belong on which day of a capture week.
 *
 * Sits beside LockedDays: both turn a fact the staffer did not type into rows for the
 * capture grid. The difference is ownership. LockedDays rows are HR's (approved leave,
 * public holidays) — locked, regenerated on every save. These are suggestions: offered
 * once, editable, deletable, and never written unless the staffer gives them a
 * percentage and saves. Read-only; this class never writes.
 */
final class BoardSuggestions
{
    public function __construct(private LockedDays $lockedDays) {}

    /**
     * @return array<string, array<int, array{work_item_id:int, title:string, category_id:?int, project_id:?int, sub_pillar_id:?int, description:string}>>
     *         keyed by ISO date, working days only
     */
    public function forWeek(Employee $employee, CarbonInterface|string $weekStart): array
    {
        $start = CarbonImmutable::parse($weekStart)->startOfDay();
        $end = $start->addDays(5);
        $today = CarbonImmutable::now()->startOfDay();

        $cards = $this->cardsFor($employee);

        if ($cards->isEmpty()) {
            return [];
        }

        $earliest = CarbonImmutable::now()->startOfWeek()->subWeeks(WeekWriter::BACKFILL_WEEKS);
        $locked = $this->lockedDays->forWeek($employee, $start);
        $logged = $this->loggedCardDays($employee, $start, $end);
        $defaults = $this->defaultsFor($employee, $cards);

        $out = [];

        foreach ($this->stintsFor($cards->keys()->all(), $start, $end) as $stint) {
            $from = CarbonImmutable::parse($stint->started_at)->startOfDay();
            $until = $stint->ended_at
                ? CarbonImmutable::parse($stint->ended_at)->startOfDay()
                : $today;

            for ($day = $from->max($start); $day->lessThanOrEqualTo($until->min($end)); $day = $day->addDay()) {
                $iso = $day->toDateString();

                // A day the staffer cannot log against, or a fact HR already owns.
                if ($day->lessThan($earliest) || $day->greaterThan($today)) {
                    continue;
                }

                // The capture grid renders Monday to Friday, plus the first Saturday of
                // the month (Unijaya's TOT half day) — see timesheet-capture.js's days
                // count. A stint running across a weekend must not propose a row for a
                // day that has no column to put it in. DayCapacity::for() cannot answer
                // this: it returns 100.0 for a plain Saturday, since it is asking how
                // full a day must be, not whether the day is worked.
                if ($day->isSunday() || ($day->isSaturday() && ! DayCapacity::isFirstSaturday($day))) {
                    continue;
                }

                $lockedDay = $locked[$iso] ?? null;
                if ($lockedDay !== null && $lockedDay['percentage'] >= DayCapacity::for($day)) {
                    continue;
                }

                $cardId = (int) $stint->work_item_id;

                // Already logged that day, or already suggested by an earlier stint of
                // the same card (a card can bounce in and out twice in one day).
                if (isset($logged[$iso][$cardId]) || isset($out[$iso][$cardId])) {
                    continue;
                }

                $card = $cards[$cardId];
                $default = $defaults[$cardId] ?? ['category_id' => null, 'project_id' => null, 'sub_pillar_id' => null];

                $out[$iso][$cardId] = [
                    'work_item_id' => $cardId,
                    'title' => $card->title,
                    'category_id' => $default['category_id'],
                    'project_id' => $default['project_id'],
                    'sub_pillar_id' => $default['sub_pillar_id'],
                    'description' => (string) ($card->description ?: $card->title),
                ];
            }
        }

        ksort($out);

        return array_map(fn (array $rows) => array_values($rows), $out);
    }

    /**
     * The cards this employee may log against: their own, plus any they were added to as
     * a participant. Archived cards are excluded — an archived card is finished business.
     *
     * @return \Illuminate\Support\Collection<int, WorkItem>
     */
    private function cardsFor(Employee $employee): \Illuminate\Support\Collection
    {
        return WorkItem::query()
            ->whereNull('archived_at')
            ->where(fn ($q) => $q->where('employee_id', $employee->id)
                ->orWhereHas('participants', fn ($p) => $p->where('employees.id', $employee->id)))
            ->get(['id', 'title', 'description', 'project_id'])
            ->keyBy('id');
    }

    /**
     * Every stint of those cards that touches the week.
     *
     * @param  array<int, int>  $cardIds
     * @return \Illuminate\Support\Collection<int, WorkItemProgressStint>
     */
    private function stintsFor(array $cardIds, CarbonImmutable $start, CarbonImmutable $end): \Illuminate\Support\Collection
    {
        return WorkItemProgressStint::query()
            ->whereIn('work_item_id', $cardIds)
            ->where('started_at', '<', $end->addDay()->toDateString())
            ->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>=', $start->toDateString()))
            ->orderBy('started_at')
            ->get();
    }

    /**
     * Cards already logged on a given day of this week, so the same card is never offered
     * twice for one day.
     *
     * @return array<string, array<int, true>>
     */
    private function loggedCardDays(Employee $employee, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rows = TimesheetEntry::query()
            ->whereNotNull('work_item_id')
            ->whereHas('timesheet', fn ($q) => $q->where('employee_id', $employee->id))
            ->whereDate('entry_date', '>=', $start->toDateString())
            ->whereDate('entry_date', '<=', $end->toDateString())
            ->get(['entry_date', 'work_item_id']);

        $out = [];
        foreach ($rows as $row) {
            $out[$row->entry_date->toDateString()][(int) $row->work_item_id] = true;
        }

        return $out;
    }

    /**
     * The category / project / sub-pillar a card's row should arrive with: whatever it was
     * logged as last time, so the staffer picks once and the rest of the week is filled in
     * for them. Falling back to the card's own project plus that project's category when it
     * has exactly one — and to nothing at all otherwise, which the picker then asks for.
     *
     * @param  \Illuminate\Support\Collection<int, WorkItem>  $cards
     * @return array<int, array{category_id:?int, project_id:?int, sub_pillar_id:?int}>
     */
    private function defaultsFor(Employee $employee, \Illuminate\Support\Collection $cards): array
    {
        $out = [];

        $previous = TimesheetEntry::query()
            ->whereIn('work_item_id', $cards->keys()->all())
            ->whereHas('timesheet', fn ($q) => $q->where('employee_id', $employee->id))
            ->orderBy('entry_date')
            ->get(['work_item_id', 'category_id', 'project_id', 'sub_pillar_id']);

        // Ordered oldest first, so the last write per card wins — its most recent logging.
        foreach ($previous as $entry) {
            $out[(int) $entry->work_item_id] = [
                'category_id' => $entry->category_id ? (int) $entry->category_id : null,
                'project_id' => $entry->project_id ? (int) $entry->project_id : null,
                'sub_pillar_id' => $entry->sub_pillar_id ? (int) $entry->sub_pillar_id : null,
            ];
        }

        $needProject = $cards->filter(fn (WorkItem $c) => ! isset($out[$c->id]) && $c->project_id !== null);

        if ($needProject->isNotEmpty()) {
            $projects = Project::with('categories:id')
                ->whereIn('id', $needProject->pluck('project_id')->unique()->all())
                ->get();

            foreach ($needProject as $card) {
                $project = $projects->firstWhere('id', $card->project_id);
                $categories = $project?->categories ?? collect();

                $out[(int) $card->id] = [
                    // Only an unambiguous project answers this. Two categories and the
                    // picker asks; guessing one would file work under the wrong heading.
                    'category_id' => $categories->count() === 1 ? (int) $categories->first()->id : null,
                    'project_id' => (int) $card->project_id,
                    'sub_pillar_id' => null,
                ];
            }
        }

        return $out;
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `lerd artisan test --compact tests/Feature/BoardSuggestionsTest.php`
Expected: PASS, 10 tests.

If the holiday test fails, check `LockedDays::forWeek()`'s returned shape and
`DayCapacity::for()`'s signature — the fully-locked comparison here mirrors
`WeekReconciler::mergeEntries()`; copy that comparison exactly rather than inventing one.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Timesheet/BoardSuggestions.php tests/Feature/BoardSuggestionsTest.php
git commit -m "feat(timesheet): work out which board cards belong on which day

Reads the In Progress stints for a week and proposes a row per card per day,
filling category and project from how that card was logged last time so the
staffer picks once instead of every day."
```

---

### Task 4: Hand the suggestions to the capture screen

**Files:**
- Modify: `app/Http/Controllers/TimesheetController.php` (`captureData()`, around `:174-206`)
- Modify: `resources/views/screens/timesheets.blade.php:98-99`
- Test: `tests/Feature/TimesheetScreenDataTest.php` (add cases)

**Interfaces:**
- Consumes: `BoardSuggestions::forWeek()` (Task 3).
- Produces: `tsSuggested` in the capture payload, and `suggested:` in the Alpine config.

- [ ] **Step 1: Write the failing test**

Open `tests/Feature/TimesheetScreenDataTest.php`, read its existing setup, and add
tests in its established style:

```php
    public function test_the_capture_payload_carries_the_days_board_suggestions(): void
    {
        // Build a card with an open In Progress stint inside the current week, using the
        // same helpers this test class already uses to create an employee and sign in.
        $card = $this->employee->workItems()->create([
            'tenant_id' => $this->tenant->id, 'title' => 'Build the thing', 'type' => 'task',
            'priority' => 'low', 'status' => 'prog', 'progress' => 0,
        ]);

        $response = $this->get('/app/timesheets');

        $response->assertOk();
        $suggested = $response->viewData('tsSuggested');
        $today = \Illuminate\Support\Carbon::now()->toDateString();

        $this->assertArrayHasKey($today, $suggested);
        $this->assertSame($card->id, $suggested[$today][0]['work_item_id']);
    }

    public function test_a_failure_building_suggestions_does_not_take_the_screen_down(): void
    {
        $this->mock(\App\Timesheet\BoardSuggestions::class, function ($mock) {
            $mock->shouldReceive('forWeek')->andThrow(new \RuntimeException('boom'));
        });

        $response = $this->get('/app/timesheets');

        $response->assertOk();
        $this->assertSame([], $response->viewData('tsSuggested'));
    }
```

Adjust the property names (`$this->employee`, `$this->tenant`) and the route to match
what that test class already uses. If it asserts on the payload differently (for example
through a different accessor than `viewData`), follow its convention.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `lerd artisan test --compact tests/Feature/TimesheetScreenDataTest.php`
Expected: FAIL — `tsSuggested` is not in the view data.

- [ ] **Step 3: Wire the controller**

In `captureData()`, just after `$tsBoardTasks` is built:

```php
        // Rows proposed from the board's In Progress cards, one per card per day it was
        // worked. Suggestions only: nothing is stored until the staffer gives a row a
        // percentage and saves. A failure here must never take the capture screen down —
        // an empty map just means the grid opens the way it always did.
        try {
            $tsSuggested = $employee ? app(BoardSuggestions::class)->forWeek($employee, $weekStart) : [];
        } catch (\Throwable $e) {
            report($e);
            $tsSuggested = [];
        }
```

Add `'tsSuggested' => $tsSuggested,` to the returned array, next to `'tsBoardTasks'`.
Add `use App\Timesheet\BoardSuggestions;` to the imports.

- [ ] **Step 4: Pass it into Alpine**

In `resources/views/screens/timesheets.blade.php`, beside line 98-99:

```blade
            suggested: @js($tsSuggested),
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `lerd artisan test --compact tests/Feature/TimesheetScreenDataTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/TimesheetController.php resources/views/screens/timesheets.blade.php tests/Feature/TimesheetScreenDataTest.php
git commit -m "feat(timesheet): pass the week's board suggestions to the capture screen"
```

---

### Task 5: Show suggested rows in the grid

**Files:**
- Modify: `resources/js/timesheet-capture.js` (`init()` around `:95-120`, the save payload builder, and wherever a row's fields are read for display)
- Modify: `resources/views/screens/timesheets.blade.php` (row markup: a marker on suggested rows)
- Modify: `resources/css/` — the stylesheet holding `.uj-ts-*` rules (find it with `grep -rl "uj-ts-add-btn" resources/css`)

**Interfaces:**
- Consumes: `cfg.suggested` (Task 4), the row shape from Task 2 (`work_item_id`).
- Produces: rows in `this.rows[iso]` carrying `work_item_id` and a client-only `suggested: true` flag.

- [ ] **Step 1: Seed the suggestions after the saved rows**

In `init()`, immediately after the existing `for (const iso of Object.keys(seed))` loop:

```js
            // Rows proposed from the board's In Progress cards. Appended after the saved
            // rows so what the staffer actually typed always comes first, and skipped on
            // fully locked days for the same reason the seed above skips them. The
            // `suggested` flag is client-only: it marks a row as not-yet-real, and is
            // cleared the moment the staffer gives it a percentage.
            const suggested = cfg.suggested || {};
            for (const iso of Object.keys(suggested)) {
                if (this.isFullyLocked(iso) || !this.isEditable(iso)) continue;
                this.rows[iso] = (this.rows[iso] || []).concat(suggested[iso].map((s) => ({
                    id: null,
                    work_item_id: s.work_item_id,
                    category_id: s.category_id || '',
                    project_id: s.project_id || '',
                    sub_pillar_id: s.sub_pillar_id || '',
                    description: s.description || '',
                    percentage: '',
                    suggested: true,
                })));
            }
```

Place this **before** the `this.selected = …` line, so `firstDayNeedingWork()` sees the
suggested rows.

- [ ] **Step 2: Clear the flag when the row is filled**

Find where a row's percentage is written (the picker's confirm/`chooseItem` path and any
direct percentage input binding). Wherever a row's `percentage` is set to a non-empty
value, also set `row.suggested = false`. A suggestion the staffer has costed is an
ordinary row.

- [ ] **Step 3: Drop untouched suggestions from the save payload**

In the method that builds the save payload, filter each day's rows:

```js
                // A suggestion nobody costed is not a claim — it must not reach the
                // server, where a 0% line would block the week's submit
                // (WeekWriter::assertNoBlankLines) and clutter the draft.
                .filter((r) => !(r.suggested && (r.percentage === '' || r.percentage === null)))
```

- [ ] **Step 4: Mark them visually**

In the day's row markup in `resources/views/screens/timesheets.blade.php`, add to the
row element:

```blade
:class="{ 'uj-ts-row--suggested': row.suggested }"
```

and in the stylesheet holding the other `.uj-ts-*` rules:

```css
/* A row proposed from the board, not yet costed by the staffer. Dashed, dimmed: it is
   an offer, not a claim, and must not read as something already filled in. */
.uj-ts-row--suggested {
    border-style: dashed;
    opacity: .72;
}
```

Match the surrounding row markup's variable name — it may be `entry` or `r` rather than
`row`. Read the template before editing.

- [ ] **Step 5: Build and check by hand**

```bash
lerd artisan view:clear
lerd artisan view:cache
bun run build
```

Then open `http://localhost:9100`, sign in, drag a card into In Progress on the board,
open Timesheets, and confirm:
- today's column shows the card as a dashed row with an empty percentage
- the day's total still reads what it did before (a suggestion adds nothing)
- saving without touching it stores nothing (`lerd artisan tinker --execute 'App\Models\TimesheetEntry::latest()->first();'`)
- typing a percentage and saving stores one row with `work_item_id` set

`view:cache` before `bun run build` is mandatory — it is what makes the Tailwind scan
see every class. `view:clear` first, because `view:cache` never empties the folder and a
stale compiled view leaks dead classes into the CSS and fails CI's asset-freshness job.

- [ ] **Step 6: Commit**

```bash
git add resources/js/timesheet-capture.js resources/views/screens/timesheets.blade.php resources/css public/build
git commit -m "feat(timesheet): show the board's In Progress cards as suggested rows

They arrive dashed and uncosted, and an untouched one is never sent — a
suggestion is an offer, not a claim."
```

---

### Task 6: One "Add what you worked on" button

**Files:**
- Modify: `resources/views/screens/timesheets.blade.php:356-368` (the two buttons), `:389-410` (picker header/back), and the picker step templates from `:424`
- Modify: `resources/js/timesheet-capture.js` (`openPicker()`, `openBoardPicker()`, `pickerBack()`)

**Interfaces:**
- Consumes: the existing picker state machine (`picker.step` values `board`, `category`, `project`, `sub`, `details`).
- Produces: a new first step `source`.

- [ ] **Step 1: Add the step to the picker's entry point**

In `resources/js/timesheet-capture.js`, replace the two separate entry points with one
that opens on the new step, keeping the existing state shape:

```js
        // One way in. The staffer says "add what I worked on", then chooses whether it
        // comes from a board card or gets typed. Two buttons side by side made the
        // difference look like a difference in what is being added, which it is not.
        openPicker() {
            this.picker = {
                open: true, step: this.boardTasks.length ? 'source' : 'category',
                category: null, project: null,
                pendingItem: null, pendingPct: null, pendingDesc: '', detailsFrom: null,
                editingIndex: null, viaBoard: false, boardProject: null, boardDesc: '',
                boardTaskTitle: '',
            };
        },
        chooseSource(source) {
            this.picker.step = source === 'board' ? 'board' : 'category';
        },
```

Keep `openBoardPicker()` only if something else calls it; otherwise delete it and its
call site. Check with `grep -n "openBoardPicker" resources/views resources/js -r`.

With no In Progress cards there is nothing to choose between, so the step is skipped —
a one-option question is not a question.

- [ ] **Step 2: Send Back to the new step**

In `pickerBack()`, replace the `picker.step === 'board'` branch and extend the
`category` branch:

```js
            } else if (this.picker.step === 'board') {
                this.picker.step = 'source';
            } else if (this.picker.step === 'category' && this.picker.viaBoard) {
                this.picker.step = 'board';
            } else if (this.picker.step === 'category') {
                // Typed entry: the source question is the only thing behind this.
                if (this.boardTasks.length) {
                    this.picker.step = 'source';
                } else {
                    this.closePicker();
                }
            }
```

Read the existing method first and fold these branches into its real structure rather
than pasting over it.

- [ ] **Step 3: Collapse the two buttons into one**

Replace `resources/views/screens/timesheets.blade.php:356-368` with:

```blade
        <div x-show="isEditable(selected)" x-cloak class="uj-ts-add-row">
            <button type="button" x-ref="addEntryBtn" @click="openPicker(); $nextTick(() => $refs.pickerCloseBtn?.focus())"
                class="uj-ts-add-btn">
                <span x-text="$store.ui.lang==='en' ? '+ Add what you worked on' : '+ Tambah apa yang anda kerjakan'"></span>
            </button>
        </div>
```

- [ ] **Step 4: Add the step's own template**

Beside the other `picker.step` templates (the first is at `:424`):

```blade
                    <template x-if="picker.step === 'source'">
                        <div class="uj-picker-list">
                            <button type="button" class="uj-picker-option" @click="chooseSource('board')">
                                <span x-text="$store.ui.lang==='en' ? 'Pull from T.A.A' : 'Tarik dari papan tugasan'"></span>
                            </button>
                            <button type="button" class="uj-picker-option" @click="chooseSource('manual')">
                                <span x-text="$store.ui.lang==='en' ? 'Enter manually' : 'Isi sendiri'"></span>
                            </button>
                        </div>
                    </template>
```

Use the real class names from the neighbouring step templates — read `:424-470` and
match the option-list markup exactly rather than using the placeholder classes above.

- [ ] **Step 5: Title and back-arrow for the new step**

In the header expressions at `:389-410`, add `source` as the step whose control is a
close `×` (it is now the first step, so there is nowhere back to go), and give it a
title: `'Add what you worked on'` / `'Tambah apa yang anda kerjakan'`. The `board` step's
control becomes a back arrow `←`.

- [ ] **Step 6: Make the manual pull link its card too**

`chooseBoardTask()` copies a card's title, description and project into the pending row
but never records which card it was. A row pulled by hand therefore has no
`work_item_id`, so the prefill offers that same card again on the next load and its
category never carries forward — the two paths behave differently for no reason a user
could guess.

In `chooseBoardTask(task)`, hold the id on the picker state:

```js
            this.picker.boardWorkItemId = task.id;
```

Then, wherever the picker's pending values are turned into a row (search for where
`pendingPct` and `pendingDesc` are written into `this.rows[...]`), carry it through:

```js
                work_item_id: this.picker.boardWorkItemId || null,
```

Set `boardWorkItemId: null` in the state object `openPicker()` builds, alongside
`boardProject` and `boardDesc`, so a manual entry never inherits a previous pull's card.

Verify by hand in Step 7: pull a card manually, save, reload — the card must not be
suggested again for that day.

- [ ] **Step 7: Build and check by hand**

```bash
lerd artisan view:clear && lerd artisan view:cache && bun run build
```

At `http://localhost:9100`:
- one button on the day, reading "+ Add what you worked on"
- it opens on the two-way choice; "Pull from T.A.A" reaches the card list, "Enter manually" reaches the category list
- Back from the card list returns to the choice, not the page; Back from the category list does too
- with no In Progress cards, the button opens straight on the category list and Back closes the popup
- Escape closes from every step

- [ ] **Step 8: Run the full timesheet suite**

Run: `lerd artisan test --compact --filter=Timesheet`
Expected: PASS.

- [ ] **Step 9: Commit**

```bash
git add resources/views/screens/timesheets.blade.php resources/js/timesheet-capture.js public/build
git commit -m "feat(timesheet): one way in to adding what you worked on

Two buttons side by side implied two different things were being added. Now one
button asks where it comes from: a board card, or typed."
```

---

### Task 7: Changelog and final verification

**Files:**
- Modify: the changelog (find it: `ls CHANGELOG.md docs/CHANGELOG.md resources/views/**/changelog* 2>/dev/null`)

- [ ] **Step 1: Read the current version and the last entry's shape**

The changelog is versioned MAJOR.MINOR against `main`; an unreleased `dev` section is
folded into the current in-flight minor rather than opening a new version. Read the top
of the file and follow whatever it already does.

- [ ] **Step 2: Add the entry**

Written for staff, not developers. Something close to:

> Your timesheet now fills itself in from the task board. Cards you had in
> **In Progress** show up on the days you worked them, with the project and category
> already set — you add the details and the percentage. The two "add" buttons on a day
> are now one.

- [ ] **Step 3: Run the whole suite**

Run: `lerd artisan test --compact`
Expected: PASS. This is the last gate before review; a failure anywhere is in scope.

- [ ] **Step 4: Confirm the committed assets are fresh**

```bash
lerd artisan view:clear && lerd artisan view:cache && bun run build
git status --porcelain public/build
```

Expected: empty output. Anything listed means the committed bundle is stale — commit it,
or CI's asset-freshness job fails the pipeline.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "docs(changelog): announce board cards prefilling the timesheet"
```

---

## Verification checklist

Before handing back for review:

- [ ] `lerd artisan test --compact` passes in full
- [ ] `vendor/bin/pint --dirty --format agent` reports nothing to fix
- [ ] `lerd artisan migrate` has been run against the dev database
- [ ] `git status --porcelain public/build` is empty after a rebuild
- [ ] The manual checks in Tasks 5 and 6 were actually performed in a browser, not assumed
