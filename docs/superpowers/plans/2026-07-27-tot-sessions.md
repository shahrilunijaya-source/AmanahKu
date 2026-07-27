# TOT Sessions Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the Google Sheet TOT roster with a screen in Amanahku where the whole company browses past monthly sessions, reacts, comments, marks what they watched, and rates the presenter privately.

**Architecture:** A new `tot` screen registered under the **existing** `module.knowledge` feature (no new module). TOT carries its own four tables rather than reusing `knowledge_entries` and its social tables, because reusing them would force every session to create a knowledge entry and pollute the Knowledge Bank monthly-share feed. The single deliberate write into Knowledge Bank is the contribution credit: marking a session `done` marks the presenter's `knowledge_monthly_contributions` row for that session's month.

**Tech Stack:** Laravel 13, PHP 8.5, Blade + Alpine.js 3, Tailwind CSS 4, MySQL, PHPUnit 12, Larastan 3, Pint.

## Global Constraints

- Design spec: `docs/superpowers/specs/2026-07-27-tot-sessions-design.md`. Visual reference: `docs/superpowers/specs/2026-07-27-tot-sessions-design.html` (open it in a browser; the desktop lineup is interactive).
- Work directly on the `dev` branch. This repo does not use feature branches.
- Every tenant-owned model uses `App\Models\Concerns\BelongsToTenant`. Never set `tenant_id` manually in a controller when a tenant context exists.
- Use `php artisan make:*` with `--no-interaction` to create files.
- Run `vendor/bin/pint --dirty --format agent` after any PHP change, before committing.
- Tests are PHPUnit classes under `tests/Feature`. Copy the harness (`setUp`, `actingInTenant`, `hrActor`) from `tests/Feature/IdeaTest.php`.
- Run tests with `php artisan test --compact --filter=<name>`.
- Never run `php artisan key:generate`, never edit `.env`.
- Privileged roles for this feature are `['management', 'hr']`. `Controller::hasTenantRole` already carries `director` through `management`.
- No em dashes in any user-facing copy or comment.
- Every user-facing string needs an English and a Malay variant where the surrounding screen convention provides one (`partials.guide`, nav labels, screen meta).

---

## File Structure

| File | Responsibility |
|---|---|
| `database/migrations/2026_07_28_000000_create_tot_tables.php` | The four TOT tables. |
| `app/Models/TotSession.php` | One monthly slot: year, month, presenter, title, status, links. |
| `app/Models/TotComment.php` | Flat discussion under a session. |
| `app/Models/TotReaction.php` | One emoji from one person on one session. |
| `app/Models/TotParticipation.php` | One person against one session: watched flag plus optional 1-5 score and note. |
| `app/Http/Controllers/TotController.php` | Screen data plus every write path. |
| `app/Console/Commands/TotReminder.php` | Daily reminder sweep across tenants. |
| `database/seeders/TotHistorySeeder.php` | Imports the 2024 to 2026 sheet history. |
| `resources/views/screens/tot.blade.php` | The year lineup screen. |
| `app/Models/KnowledgeContribution.php` | Gains a static `mark()` so both Knowledge Bank and TOT credit a month. |
| `tests/Feature/TotTest.php` | Permissions, reactions, ratings, comments, contribution credit. |
| `tests/Feature/TotReminderTest.php` | Reminder stages and dedupe. |
| `tests/Feature/TotHistorySeederTest.php` | Import counts and link parsing. |

---

### Task 1: Schema and models

**Files:**
- Create: `database/migrations/2026_07_28_000000_create_tot_tables.php`
- Create: `app/Models/TotSession.php`, `app/Models/TotComment.php`, `app/Models/TotReaction.php`, `app/Models/TotParticipation.php`
- Test: `tests/Feature/TotTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `TotSession` with `$fillable`-free `$guarded = []`, casts `links` to array, relations `presenter(): BelongsTo`, `comments(): HasMany`, `reactions(): HasMany`, `participations(): HasMany`, `entry(): BelongsTo`; constants `TotSession::STATUSES` (array of five strings) and `TotSession::EMOJI` (array of six strings). Static helper `TotSession::firstSaturday(int $year, int $month): \Illuminate\Support\Carbon`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/TotTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\TotSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Feature coverage for the TOT sessions board.
 * Harness (setUp / actingInTenant / hrActor) copied from IdeaTest.
 */
class TotTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Tenant $tenant;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create(['name' => 'Demo', 'email' => 'demo@example.com', 'password' => Hash::make('password')]);
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $this->user->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $this->employee = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->user->id,
            'name' => 'Demo', 'status' => 'active', 'workload' => 'green',
        ]);
    }

    private function actingInTenant(): self
    {
        $this->actingAs($this->user)->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    private function hrActor(): User
    {
        $hr = User::create(['name' => 'Boss', 'email' => 'boss@example.com', 'password' => Hash::make('password')]);
        $hr->tenants()->attach($this->tenant->id, ['role' => 'hr']);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $hr->id,
            'name' => 'Boss', 'status' => 'active', 'workload' => 'green',
        ]);

        return $hr;
    }

    private function makeSession(array $overrides = []): TotSession
    {
        return TotSession::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'year' => 2026,
            'month' => 3,
            'presenter_employee_id' => $this->employee->id,
            'title' => 'Install git on our own server',
            'status' => 'done',
            'links' => [['label' => 'Slides', 'url' => 'https://example.com/slides']],
        ], $overrides));
    }

    // ── Schema ────────────────────────────────────────────────────

    public function test_a_session_stores_its_links_as_an_array(): void
    {
        $session = $this->makeSession();

        $this->assertSame('Slides', $session->fresh()->links[0]['label']);
    }

    public function test_one_slot_per_tenant_year_and_month(): void
    {
        $this->makeSession();

        $this->expectException(\Illuminate\Database\QueryException::class);

        $this->makeSession(['title' => 'Duplicate slot']);
    }

    public function test_first_saturday_is_computed_per_month(): void
    {
        $this->assertSame('2026-03-07', TotSession::firstSaturday(2026, 3)->toDateString());
        $this->assertSame('2026-08-01', TotSession::firstSaturday(2026, 8)->toDateString());
        $this->assertSame('2027-01-02', TotSession::firstSaturday(2027, 1)->toDateString());
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact --filter=TotTest`
Expected: FAIL with `Class "App\Models\TotSession" not found`.

- [ ] **Step 3: Create the migration**

Run: `php artisan make:migration create_tot_tables --no-interaction`

Rename the generated file to `database/migrations/2026_07_28_000000_create_tot_tables.php` and replace its contents:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The monthly TOT roster. One slot per tenant per calendar month, mirroring the
        // Google Sheet it replaces (Tahun / Bulan / PIC / Tajuk / Link). presenter_name is
        // the fallback for a PIC with no employee record: imported nicknames, "Team", or a
        // non-TOT calendar entry. Everything is tenant-scoped (BelongsToTenant).
        Schema::create('tot_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');           // 1-12
            $table->foreignId('presenter_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('presenter_name', 120)->nullable();
            $table->string('title', 200)->nullable();       // null when the topic is not set yet
            $table->text('description')->nullable();
            $table->string('status', 16)->default('planned'); // planned|confirmed|done|skipped|not_tot
            $table->date('held_on')->nullable();
            $table->json('links')->nullable();              // [{label, url}]
            $table->foreignId('entry_id')->nullable()->constrained('knowledge_entries')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'year', 'month']);
            $table->index(['tenant_id', 'year']);
        });

        Schema::create('tot_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('session_id')->constrained('tot_sessions')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();
        });

        // One row per person per emoji per session. The unique key is what makes a repeat
        // POST a toggle rather than a duplicate.
        Schema::create('tot_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('session_id')->constrained('tot_sessions')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('emoji', 16);
            $table->timestamps();

            $table->unique(['session_id', 'employee_id', 'emoji']);
        });

        // Attendance and rating are the same person against the same session, so they share
        // one row. score stays nullable because people watch without rating.
        Schema::create('tot_participation', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('session_id')->constrained('tot_sessions')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->timestamp('watched_at')->nullable();
            $table->unsignedTinyInteger('score')->nullable();   // 1-5
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['session_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tot_participation');
        Schema::dropIfExists('tot_reactions');
        Schema::dropIfExists('tot_comments');
        Schema::dropIfExists('tot_sessions');
    }
};
```

- [ ] **Step 4: Create the models**

Run:

```bash
php artisan make:model TotSession --no-interaction
php artisan make:model TotComment --no-interaction
php artisan make:model TotReaction --no-interaction
php artisan make:model TotParticipation --no-interaction
```

Replace `app/Models/TotSession.php`:

```php
<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class TotSession extends Model
{
    use BelongsToTenant;

    /**
     * planned  - slot exists, may or may not have a PIC, not yet held
     * confirmed- PIC and title both set, expected to run
     * done     - the session happened (this is what credits the PIC's month)
     * skipped  - no session that month
     * not_tot  - a non-TOT calendar entry kept for fidelity, never credits anybody
     */
    public const STATUSES = ['planned', 'confirmed', 'done', 'skipped', 'not_tot'];

    /** The only reactions the UI offers. Anything else is rejected with a 422. */
    public const EMOJI = ['👍', '👏', '🔥', '💡', '🤔', '❤️'];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'links' => 'array',
            'held_on' => 'date',
        ];
    }

    /**
     * The session date: the first Saturday of the slot's month. It is computed rather than
     * stored, so a slot can exist before anybody decides anything about it.
     */
    public static function firstSaturday(int $year, int $month): Carbon
    {
        return Carbon::parse(sprintf('first saturday of %04d-%02d', $year, $month));
    }

    public function presenter(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'presenter_employee_id');
    }

    /** Optional pointer at a Knowledge Bank lesson on the same topic. Never creates one. */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(KnowledgeEntry::class, 'entry_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TotComment::class, 'session_id');
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(TotReaction::class, 'session_id');
    }

    public function participations(): HasMany
    {
        return $this->hasMany(TotParticipation::class, 'session_id');
    }
}
```

Replace `app/Models/TotComment.php`:

```php
<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TotComment extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    public function session(): BelongsTo
    {
        return $this->belongsTo(TotSession::class, 'session_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
```

Replace `app/Models/TotReaction.php`:

```php
<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TotReaction extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    public function session(): BelongsTo
    {
        return $this->belongsTo(TotSession::class, 'session_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
```

Replace `app/Models/TotParticipation.php`:

```php
<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TotParticipation extends Model
{
    use BelongsToTenant;

    protected $table = 'tot_participation';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['watched_at' => 'datetime'];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(TotSession::class, 'session_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --compact --filter=TotTest`
Expected: PASS, 3 tests.

- [ ] **Step 6: Migrate the dev database**

The test suite uses a separate database, so a green test does not mean the app works. Run the migration against the dev database too:

Run: `lerd artisan migrate`
Expected: `2026_07_28_000000_create_tot_tables ... DONE`

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/2026_07_28_000000_create_tot_tables.php app/Models/Tot*.php tests/Feature/TotTest.php
git commit -m "feat(tot): add the monthly session roster tables and models

One slot per tenant per month mirrors the sheet this replaces. Comments,
reactions and participation get their own tables rather than reusing the
Knowledge Bank social tables, which hang off knowledge_entries and would drag
every session into the monthly-share feed."
```

---

### Task 2: Share the contribution credit helper

**Files:**
- Modify: `app/Models/KnowledgeContribution.php`
- Modify: `app/Http/Controllers/KnowledgeController.php:404-410` (the private `markContributed` method)
- Test: `tests/Feature/TotTest.php`

**Interfaces:**
- Consumes: `KnowledgeContribution` from Task 1's environment (it already exists).
- Produces: `KnowledgeContribution::mark(Employee $employee, int $year, int $month): void` for Task 5.

**Why:** `markContributed` hardcodes `now()`. TOT must credit the **session's** month, not the month somebody happened to click the button in.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/TotTest.php`, inside the class:

```php
    // ── Contribution credit helper ────────────────────────────────

    public function test_mark_credits_the_month_it_is_given_not_the_current_month(): void
    {
        \App\Models\KnowledgeContribution::mark($this->employee, 2026, 3);

        $this->assertDatabaseHas('knowledge_monthly_contributions', [
            'employee_id' => $this->employee->id,
            'year' => 2026,
            'month' => 3,
            'submitted' => true,
        ]);
    }

    public function test_mark_is_idempotent_for_the_same_month(): void
    {
        \App\Models\KnowledgeContribution::mark($this->employee, 2026, 3);
        \App\Models\KnowledgeContribution::mark($this->employee, 2026, 3);

        $this->assertSame(1, \App\Models\KnowledgeContribution::where('employee_id', $this->employee->id)->count());
    }
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact --filter=TotTest`
Expected: FAIL with `Call to undefined method App\Models\KnowledgeContribution::mark()`.

- [ ] **Step 3: Add the static helper**

In `app/Models/KnowledgeContribution.php`, add this method inside the class, after `casts()`:

```php
    /**
     * Mark an employee's monthly contribution as fulfilled for a specific calendar month.
     *
     * Shared by the Knowledge Bank (a written lesson) and the TOT board (presenting a
     * session). Takes an explicit year and month rather than reading now(), because a TOT
     * session marked done late must still credit the month it was held in.
     */
    public static function mark(Employee $employee, int $year, int $month): void
    {
        static::updateOrCreate(
            ['employee_id' => $employee->id, 'year' => $year, 'month' => $month],
            ['submitted' => true],
        );
    }
```

- [ ] **Step 4: Point KnowledgeController at the shared helper**

In `app/Http/Controllers/KnowledgeController.php`, replace the whole private `markContributed` method body:

```php
    private function markContributed(Employee $employee): void
    {
        KnowledgeContribution::mark($employee, (int) now()->year, (int) now()->month);
    }
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=TotTest`
Expected: PASS, 5 tests.

Run: `php artisan test --compact --filter=Knowledge`
Expected: PASS, no regressions in the Knowledge Bank suite.

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Models/KnowledgeContribution.php app/Http/Controllers/KnowledgeController.php tests/Feature/TotTest.php
git commit -m "refactor(knowledge): let a contribution be credited to an explicit month

markContributed hardcoded now(), which is wrong for any caller that credits a
past month. TOT needs to credit the month a session was held in, not the month
somebody marked it done. No behaviour change for the Knowledge Bank itself."
```

---

### Task 3: Register the screen and render the year

**Files:**
- Create: `app/Http/Controllers/TotController.php`
- Create: `resources/views/screens/tot.blade.php` (minimal for now, filled in Task 9)
- Modify: `app/Support/Features.php:54`
- Modify: `app/Support/Amanahku.php` (nav entry near line 99, screen meta near line 260)
- Modify: `app/Http/Controllers/AppController.php:350`
- Modify: `routes/web.php`
- Modify: `tests/Feature/AllScreensRenderTest.php:34-46` (the `SCREENS` constant)
- Test: `tests/Feature/TotTest.php`

**Interfaces:**
- Consumes: `TotSession` from Task 1.
- Produces: `TotController::screenData(Request $request, ?Employee $employee): array` returning exactly these keys: `year` (int), `years` (list<int>), `sessions` (list of 12 `TotSession`, each with a `session_date` Carbon attached), `privileged` (bool), `canManage` (bool), `reactionCounts` (array<int, array<string,int>>), `myReactions` (array<int, list<string>>), `myParticipation` (array<int, TotParticipation>), `watchedCounts` (array<int,int>), `scores` (array<int, array{average: float, count: int, notes: list<string>}>). Task 8 adds one more key, `comments`. Later tasks add write methods to the same controller.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/TotTest.php`:

```php
    // ── Screen ────────────────────────────────────────────────────

    public function test_the_screen_renders_twelve_slots_for_the_requested_year(): void
    {
        $this->makeSession(['month' => 3, 'title' => 'Install git on our own server']);

        $response = $this->actingInTenant()->get('/app/tot?year=2026');

        $response->assertOk();
        $response->assertViewHas('sessions', fn ($sessions) => count($sessions) === 12);
        $response->assertSee('Install git on our own server');
    }

    public function test_the_screen_defaults_to_the_current_year(): void
    {
        $response = $this->actingInTenant()->get('/app/tot');

        $response->assertOk();
        $response->assertViewHas('year', (int) now()->year);
    }
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact --filter=TotTest`
Expected: FAIL. The catch-all screen route renders the `empty` screen or 404s because `tot` is not a known screen.

- [ ] **Step 3: Create the controller**

Run: `php artisan make:controller TotController --no-interaction`

Replace `app/Http/Controllers/TotController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\TotParticipation;
use App\Models\TotReaction;
use App\Models\TotSession;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class TotController extends Controller
{
    /** HR and management own the roster; everyone else reads, reacts, comments and rates. */
    private const PRIVILEGED_ROLES = ['management', 'hr'];

    /**
     * The year lineup. Always twelve slots, even for months nobody has filled: an absent
     * month is information, not a gap, so missing rows are filled with unsaved placeholder
     * models carrying the computed first-Saturday date.
     *
     * @return array<string, mixed>
     */
    public function screenData(Request $request, ?Employee $employee): array
    {
        $privileged = $this->hasTenantRole($request, self::PRIVILEGED_ROLES);
        $year = (int) ($request->query('year') ?: now()->year);

        $saved = TotSession::with(['presenter', 'entry'])
            ->withCount('comments')
            ->where('year', $year)
            ->get()
            ->keyBy('month');

        $sessions = collect(range(1, 12))->map(function (int $month) use ($saved, $year) {
            $session = $saved->get($month) ?? new TotSession([
                'year' => $year,
                'month' => $month,
                'status' => 'planned',
            ]);

            $session->session_date = TotSession::firstSaturday($year, $month);

            return $session;
        })->all();

        $ids = $saved->pluck('id')->all();

        return [
            'year' => $year,
            'years' => $this->availableYears($year),
            'sessions' => $sessions,
            'privileged' => $privileged,
            'canManage' => $privileged,
            'reactionCounts' => $this->reactionCounts($ids),
            'myReactions' => $this->myReactions($ids, $employee),
            'myParticipation' => $this->myParticipation($ids, $employee),
            'watchedCounts' => $this->watchedCounts($ids),
            'scores' => $this->visibleScores($saved, $employee, $privileged),
        ];
    }

    /**
     * Every year that has at least one slot, plus the requested year and the current year,
     * newest first. Keeps the switcher useful before any history exists.
     *
     * @return list<int>
     */
    private function availableYears(int $requested): array
    {
        return TotSession::query()
            ->select('year')->distinct()->pluck('year')
            ->push($requested)->push((int) now()->year)
            ->unique()->sortDesc()->values()->all();
    }

    /**
     * Per-session emoji tallies, keyed session id => emoji => count.
     *
     * @param  list<int>  $ids
     * @return array<int, array<string, int>>
     */
    private function reactionCounts(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return TotReaction::whereIn('session_id', $ids)
            ->get()
            ->groupBy('session_id')
            ->map(fn (Collection $rows) => $rows->groupBy('emoji')->map->count()->all())
            ->all();
    }

    /**
     * The acting employee's own reactions, keyed session id => list of emoji, so the view
     * can highlight what they already pressed.
     *
     * @param  list<int>  $ids
     * @return array<int, list<string>>
     */
    private function myReactions(array $ids, ?Employee $employee): array
    {
        if ($ids === [] || ! $employee) {
            return [];
        }

        return TotReaction::whereIn('session_id', $ids)
            ->where('employee_id', $employee->id)
            ->get()
            ->groupBy('session_id')
            ->map(fn (Collection $rows) => $rows->pluck('emoji')->all())
            ->all();
    }

    /**
     * The acting employee's own participation row per session (watched flag plus their own
     * score), keyed by session id.
     *
     * @param  list<int>  $ids
     * @return array<int, TotParticipation>
     */
    private function myParticipation(array $ids, ?Employee $employee): array
    {
        if ($ids === [] || ! $employee) {
            return [];
        }

        return TotParticipation::whereIn('session_id', $ids)
            ->where('employee_id', $employee->id)
            ->get()->keyBy('session_id')->all();
    }

    /**
     * How many people marked each session watched.
     *
     * @param  list<int>  $ids
     * @return array<int, int>
     */
    private function watchedCounts(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return TotParticipation::whereIn('session_id', $ids)
            ->whereNotNull('watched_at')
            ->get()->groupBy('session_id')->map->count()->all();
    }

    /**
     * Score summaries, and only for sessions the viewer is allowed to see them on: their
     * own sessions as the presenter, or every session when privileged. Names never leave
     * this method; only the average, the count, and the anonymous notes do.
     *
     * @param  \Illuminate\Support\Collection<int, TotSession>  $saved
     * @return array<int, array{average: float, count: int, notes: list<string>}>
     */
    private function visibleScores(Collection $saved, ?Employee $employee, bool $privileged): array
    {
        $visible = $saved->filter(
            fn (TotSession $s) => $privileged || ($employee && $s->presenter_employee_id === $employee->id)
        );

        if ($visible->isEmpty()) {
            return [];
        }

        return TotParticipation::whereIn('session_id', $visible->pluck('id')->all())
            ->whereNotNull('score')
            ->get()
            ->groupBy('session_id')
            ->map(fn (Collection $rows) => [
                'average' => round($rows->avg('score'), 1),
                'count' => $rows->count(),
                'notes' => $rows->pluck('note')->filter()->values()->all(),
            ])
            ->all();
    }
}
```

- [ ] **Step 4: Create a minimal screen so the render has something to show**

Create `resources/views/screens/tot.blade.php`:

```blade
@extends('layouts.app')

@section('screen')
<div class="uj-card">
    <div class="uj-card-head">
        <h3 class="uj-card-title">TOT {{ $year }}</h3>
    </div>
    <div style="padding:18px 20px;">
        @foreach ($sessions as $session)
            <div style="padding:6px 0;border-bottom:1px solid var(--hairline-soft);">
                {{ $session->session_date->format('M d') }} ·
                {{ $session->presenter?->name ?? $session->presenter_name ?? 'Nobody assigned' }} ·
                {{ $session->title ?? 'Topic not set' }}
            </div>
        @endforeach
    </div>
</div>
@endsection
```

- [ ] **Step 5: Register the screen id under the existing knowledge module**

In `app/Support/Features.php`, change line 54 from:

```php
        'module.knowledge' => ['Knowledge Bank', ['knowledge-bank'], 2],
```

to:

```php
        'module.knowledge' => ['Knowledge Bank', ['knowledge-bank', 'tot'], 2],
```

The module count does not change, so no new admin toggle appears.

- [ ] **Step 6: Add the nav entry and screen meta**

In `app/Support/Amanahku.php`, immediately after the `knowledge-bank` nav line (around line 99), add:

```php
            $s('Talent & Growth', 'Bakat & Pembangunan', ['id' => 'tot', 'label' => 'TOT Sessions', 'label_ms' => 'Sesi TOT', 'icon' => 'M3 3v18h18M7 14l4-4 3 3 5-6']),
```

In the screen-meta array (around line 260), immediately after the `knowledge-bank` entry, add:

```php
            'tot' => ['title' => 'TOT Sessions', 'title_ms' => 'Sesi TOT', 'sub' => 'Transfer of Technology, first Saturday of every month. One person, one topic.', 'sub_ms' => 'Transfer of Technology, Sabtu pertama setiap bulan. Satu orang, satu topik.', 'crumb' => ['TOT Sessions']],
```

- [ ] **Step 7: Dispatch the screen in AppController**

In `app/Http/Controllers/AppController.php`, immediately after the `'knowledge-bank'` arm (line 350), add:

```php
            'tot' => app(TotController::class)->screenData($request, $employee),
```

Add the import at the top of the file, in alphabetical position with the other controller imports:

```php
use App\Http\Controllers\TotController;
```

If the file's controllers are referenced without imports (check the existing `KnowledgeController` line first), match whatever that line does instead of adding an import.

- [ ] **Step 8: Add the screen to the all-screens render test**

In `tests/Feature/AllScreensRenderTest.php`, inside the `SCREENS` constant, add `'tot'` to the line that already contains `'training', 'learning', 'handbook',`:

```php
        'recruitment', 'referrals', 'cases', 'training', 'learning', 'handbook', 'tot',
```

- [ ] **Step 9: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=TotTest`
Expected: PASS, 7 tests.

Run: `php artisan test --compact --filter=AllScreensRenderTest`
Expected: PASS.

- [ ] **Step 10: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/TotController.php app/Http/Controllers/AppController.php app/Support/Features.php app/Support/Amanahku.php resources/views/screens/tot.blade.php tests/Feature/TotTest.php tests/Feature/AllScreensRenderTest.php
git commit -m "feat(tot): render the year lineup screen under the knowledge module

Adds the tot screen id to module.knowledge rather than creating a new module,
so the admin toggle list is unchanged. The year always shows twelve slots,
filling months with no saved row from the computed first-Saturday date, because
a missing month is information rather than a gap."
```

---

### Task 4: Roster write paths and permissions

**Files:**
- Modify: `app/Http/Controllers/TotController.php`
- Modify: `routes/web.php` (write-path group, above the `/app/{screen?}` catch-all)
- Test: `tests/Feature/TotTest.php`

**Interfaces:**
- Consumes: `TotController::screenData` and `TotSession` from Task 3.
- Produces: routes `tot.store`, `tot.update`, `tot.destroy`; controller methods `store(Request $request): RedirectResponse`, `update(Request $request, TotSession $session): RedirectResponse`, `destroy(Request $request, TotSession $session): RedirectResponse`; private helper `TotController::authorizeSlotEdit(Request $request, TotSession $session, ?Employee $employee): void`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/TotTest.php`:

```php
    // ── Roster permissions ────────────────────────────────────────

    public function test_an_employee_cannot_create_a_slot(): void
    {
        $response = $this->actingInTenant()->post('/app/tot', [
            'year' => 2026, 'month' => 9, 'status' => 'planned',
        ]);

        $response->assertForbidden();
    }

    public function test_hr_creates_a_slot(): void
    {
        $hr = $this->hrActor();

        $response = $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id])
            ->post('/app/tot', [
                'year' => 2026, 'month' => 9,
                'presenter_employee_id' => $this->employee->id,
                'title' => 'Queue workers in production',
                'status' => 'confirmed',
            ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('tot_sessions', [
            'year' => 2026, 'month' => 9, 'title' => 'Queue workers in production', 'status' => 'confirmed',
        ]);
    }

    public function test_the_presenter_edits_their_own_slot(): void
    {
        $session = $this->makeSession(['status' => 'confirmed', 'title' => null]);

        $response = $this->actingInTenant()->post("/app/tot/{$session->id}", [
            'title' => 'Install git on our own server',
        ]);

        $response->assertRedirect();
        $this->assertSame('Install git on our own server', $session->fresh()->title);
    }

    public function test_an_employee_cannot_edit_a_slot_they_do_not_present(): void
    {
        $other = Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Someone else',
            'status' => 'active', 'workload' => 'green',
        ]);
        $session = $this->makeSession(['presenter_employee_id' => $other->id]);

        $response = $this->actingInTenant()->post("/app/tot/{$session->id}", ['title' => 'Hijacked']);

        $response->assertForbidden();
    }

    public function test_the_presenter_cannot_change_the_status(): void
    {
        $session = $this->makeSession(['status' => 'confirmed']);

        $this->actingInTenant()->post("/app/tot/{$session->id}", [
            'title' => 'Install git on our own server',
            'status' => 'done',
        ]);

        $this->assertSame('confirmed', $session->fresh()->status);
    }

    public function test_an_unknown_status_is_rejected(): void
    {
        $hr = $this->hrActor();
        $session = $this->makeSession();

        $response = $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/tot/{$session->id}", ['status' => 'cancelled']);

        $response->assertSessionHasErrors('status');
    }

    public function test_only_privileged_roles_delete_a_slot(): void
    {
        $session = $this->makeSession();

        $this->actingInTenant()->post("/app/tot/{$session->id}/delete")->assertForbidden();

        $hr = $this->hrActor();
        $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/tot/{$session->id}/delete")->assertRedirect();

        $this->assertDatabaseMissing('tot_sessions', ['id' => $session->id]);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter=TotTest`
Expected: FAIL with 404s, because `/app/tot` accepts no POST yet.

- [ ] **Step 3: Add the write methods**

Add to `app/Http/Controllers/TotController.php`, inside the class, after `screenData`:

```php
    /** Privileged-only: create a slot for a month that has none yet. */
    public function store(Request $request): RedirectResponse
    {
        $this->authorizeTenantRole($request, self::PRIVILEGED_ROLES);

        $data = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'presenter_employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'presenter_name' => ['nullable', 'string', 'max:120'],
            'title' => ['nullable', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', 'in:'.implode(',', TotSession::STATUSES)],
        ]);

        // Refuse rather than overwrite. The table has a unique key on (tenant_id, year,
        // month), so a plain create would crash on a duplicate; refusing explicitly avoids
        // both the crash and the silent data loss that updateOrCreate would cause when a
        // stale tab or a mis-aimed submit lands on a month that is already filled.
        $exists = TotSession::where('year', $data['year'])->where('month', $data['month'])->exists();
        abort_if($exists, 422, 'That slot already exists. Edit it instead of creating it again.');

        $session = TotSession::create([
            'year' => $data['year'],
            'month' => $data['month'],
            'presenter_employee_id' => $data['presenter_employee_id'] ?? null,
            'presenter_name' => $data['presenter_name'] ?? null,
            'title' => $data['title'] ?? null,
            'description' => $data['description'] ?? null,
            'status' => $data['status'],
            'created_by' => $request->attributes->get('employee')?->id,
        ]);

        AuditLog::record('Created TOT slot', sprintf('%04d-%02d', $session->year, $session->month));

        return back()->with('ok', 'TOT slot saved.');
    }

    /**
     * Update a slot. HR and management may change everything; the presenter of the slot may
     * only change the material (title, description, links, cross-link). Status is
     * privileged because flipping it to done credits a Knowledge Bank month.
     */
    public function update(Request $request, TotSession $session): RedirectResponse
    {
        $employee = $request->attributes->get('employee');
        $privileged = $this->hasTenantRole($request, self::PRIVILEGED_ROLES);

        $this->authorizeSlotEdit($request, $session, $employee);

        $rules = [
            'title' => ['nullable', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'links' => ['nullable', 'array', 'max:12'],
            'links.*.label' => ['required_with:links', 'string', 'max:60'],
            'links.*.url' => ['required_with:links', 'url', 'max:2000'],
            'entry_id' => ['nullable', 'integer', 'exists:knowledge_entries,id'],
        ];

        if ($privileged) {
            $rules['presenter_employee_id'] = ['nullable', 'integer', 'exists:employees,id'];
            $rules['presenter_name'] = ['nullable', 'string', 'max:120'];
            $rules['status'] = ['nullable', 'in:'.implode(',', TotSession::STATUSES)];
            $rules['held_on'] = ['nullable', 'date'];
        }

        // validate() returns only the keys it was given rules for. A non-privileged
        // presenter has no status rule, so a hand-crafted POST carrying status never
        // reaches $data and cannot promote the slot.
        $data = $request->validate($rules);

        $session->fill($data)->save();

        AuditLog::record('Updated TOT slot', sprintf('%04d-%02d', $session->year, $session->month));

        return back()->with('ok', 'TOT slot updated.');
    }

    /** Privileged-only: remove a slot entirely. */
    public function destroy(Request $request, TotSession $session): RedirectResponse
    {
        $this->authorizeTenantRole($request, self::PRIVILEGED_ROLES);

        $label = sprintf('%04d-%02d', $session->year, $session->month);
        $session->delete();

        AuditLog::record('Deleted TOT slot', $label);

        return back()->with('ok', 'TOT slot removed.');
    }

    /** 403 unless the actor is privileged or is the presenter of this slot. */
    private function authorizeSlotEdit(Request $request, TotSession $session, ?Employee $employee): void
    {
        if ($this->hasTenantRole($request, self::PRIVILEGED_ROLES)) {
            return;
        }

        abort_unless(
            $employee && $session->presenter_employee_id === $employee->id,
            403,
            'Only HR, management, or the presenter of this session can edit it.'
        );
    }
```

Add these imports at the top of `app/Http/Controllers/TotController.php`:

```php
use App\Models\AuditLog;
use Illuminate\Http\RedirectResponse;
```

- [ ] **Step 4: Add the routes**

In `routes/web.php`, inside the `Route::middleware(['tenant', 'company.active', 'not.archived', 'module.enabled'])` group and **above** the `/app/{screen?}` catch-all, add this block next to the knowledge-bank routes (around line 318):

```php
        // TOT sessions — the monthly Transfer of Technology board. Paths share the `tot`
        // first segment so EnsureModuleEnabled gates them under module.knowledge.
        Route::post('/app/tot', [TotController::class, 'store'])->name('tot.store');
        Route::post('/app/tot/{session}', [TotController::class, 'update'])->name('tot.update');
        Route::post('/app/tot/{session}/delete', [TotController::class, 'destroy'])->name('tot.destroy');
```

Add the import at the top of `routes/web.php`, alphabetically among the other controller imports:

```php
use App\Http\Controllers\TotController;
```

Note on ordering: the later tasks add `/app/tot/{session}/react`, `/watched`, `/rate`, `/comment`, and `/app/tot/comments/{comment}`. None of them collide with `/app/tot/{session}`, because a wildcard segment matches exactly one segment and the only same-shape path (`/app/tot/comments/{comment}`) uses the DELETE verb while the wildcard uses POST. Keep every TOT route in this one block for readability.

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=TotTest`
Expected: PASS, 14 tests.

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/TotController.php routes/web.php tests/Feature/TotTest.php
git commit -m "feat(tot): let HR own the roster and the presenter own their material

HR and management set the PIC, the status and the dates. The presenter can edit
only the title, description and links of their own slot. Status stays privileged
because flipping a slot to done credits a Knowledge Bank month."
```

---

### Task 5: Credit the presenter's Knowledge Bank month

**Files:**
- Modify: `app/Http/Controllers/TotController.php` (the `update` method)
- Test: `tests/Feature/TotTest.php`

**Interfaces:**
- Consumes: `KnowledgeContribution::mark()` from Task 2, `TotController::update` from Task 4.
- Produces: private `TotController::creditContribution(TotSession $session): void`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/TotTest.php`:

```php
    // ── Knowledge Bank contribution credit ────────────────────────

    public function test_marking_a_session_done_credits_the_presenter_for_that_month(): void
    {
        $hr = $this->hrActor();
        $session = $this->makeSession(['year' => 2026, 'month' => 3, 'status' => 'confirmed']);

        // Deliberately act in a different month to prove the credit follows the session.
        $this->travelTo('2026-04-20 09:00:00');

        $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/tot/{$session->id}", ['status' => 'done'])
            ->assertRedirect();

        $this->assertDatabaseHas('knowledge_monthly_contributions', [
            'employee_id' => $this->employee->id, 'year' => 2026, 'month' => 3, 'submitted' => true,
        ]);
        $this->assertDatabaseMissing('knowledge_monthly_contributions', [
            'employee_id' => $this->employee->id, 'year' => 2026, 'month' => 4,
        ]);
    }

    public function test_reverting_a_session_out_of_done_keeps_the_credit(): void
    {
        $hr = $this->hrActor();
        $session = $this->makeSession(['status' => 'confirmed']);

        $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/tot/{$session->id}", ['status' => 'done']);
        $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/tot/{$session->id}", ['status' => 'planned']);

        $this->assertDatabaseHas('knowledge_monthly_contributions', [
            'employee_id' => $this->employee->id, 'year' => 2026, 'month' => 3,
        ]);
    }

    public function test_a_session_with_no_employee_presenter_credits_nobody(): void
    {
        $hr = $this->hrActor();
        $session = $this->makeSession([
            'presenter_employee_id' => null, 'presenter_name' => 'Team', 'status' => 'confirmed',
        ]);

        $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/tot/{$session->id}", ['status' => 'done'])
            ->assertRedirect();

        $this->assertSame(0, \App\Models\KnowledgeContribution::count());
    }

    public function test_a_not_tot_entry_never_credits_a_month(): void
    {
        $hr = $this->hrActor();
        $session = $this->makeSession(['status' => 'not_tot', 'title' => 'Jamuan raya']);

        $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/tot/{$session->id}", ['status' => 'not_tot'])
            ->assertRedirect();

        $this->assertSame(0, \App\Models\KnowledgeContribution::count());
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter=TotTest`
Expected: FAIL on the first test, `Failed asserting that a row in the table [knowledge_monthly_contributions] matches`.

- [ ] **Step 3: Credit on the transition into `done`**

In `app/Http/Controllers/TotController.php`, inside `update()`, replace the save line and what follows it:

```php
        $wasDone = $session->status === 'done';

        $session->fill($data)->save();

        // Credit only on the transition INTO done, and only once. Presenting is the thing
        // that earns the month; editing the title afterwards is not.
        if (! $wasDone && $session->status === 'done') {
            $this->creditContribution($session);
        }

        AuditLog::record('Updated TOT slot', sprintf('%04d-%02d', $session->year, $session->month));

        return back()->with('ok', 'TOT slot updated.');
```

Add the private helper at the end of the class:

```php
    /**
     * Presenting a TOT counts as that month's Knowledge Bank contribution.
     *
     * Credits the SESSION's year and month, never now(), so a slot marked done late still
     * credits the month it was held in. Never revoked when a slot moves back out of done:
     * revoking could silently erase a contribution the person separately earned by writing
     * a real lesson, and that bug would be invisible.
     */
    private function creditContribution(TotSession $session): void
    {
        $presenter = $session->presenter;

        if (! $presenter) {
            return;
        }

        KnowledgeContribution::mark($presenter, (int) $session->year, (int) $session->month);
    }
```

Add the import:

```php
use App\Models\KnowledgeContribution;
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=TotTest`
Expected: PASS, 18 tests.

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/TotController.php tests/Feature/TotTest.php
git commit -m "feat(tot): a held session counts as the presenter's month in Knowledge Bank

Fires on the transition into done, credits the session's own month rather than
the current one, and is never revoked. Revoking could erase a contribution the
person separately earned by writing a lesson, which would fail silently."
```

---

### Task 6: Emoji reactions

**Files:**
- Modify: `app/Http/Controllers/TotController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/TotTest.php`

**Interfaces:**
- Consumes: `TotSession::EMOJI` from Task 1.
- Produces: route `tot.react`; method `TotController::react(Request $request, TotSession $session): RedirectResponse`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/TotTest.php`:

```php
    // ── Reactions ─────────────────────────────────────────────────

    public function test_an_employee_reacts_to_a_session(): void
    {
        $session = $this->makeSession();

        $this->actingInTenant()->post("/app/tot/{$session->id}/react", ['emoji' => '👍'])
            ->assertRedirect();

        $this->assertDatabaseHas('tot_reactions', [
            'session_id' => $session->id, 'employee_id' => $this->employee->id, 'emoji' => '👍',
        ]);
    }

    public function test_reacting_twice_with_the_same_emoji_removes_it(): void
    {
        $session = $this->makeSession();

        $this->actingInTenant()->post("/app/tot/{$session->id}/react", ['emoji' => '👍']);
        $this->actingInTenant()->post("/app/tot/{$session->id}/react", ['emoji' => '👍']);

        $this->assertDatabaseMissing('tot_reactions', [
            'session_id' => $session->id, 'employee_id' => $this->employee->id, 'emoji' => '👍',
        ]);
    }

    public function test_one_person_may_hold_several_different_emoji(): void
    {
        $session = $this->makeSession();

        $this->actingInTenant()->post("/app/tot/{$session->id}/react", ['emoji' => '👍']);
        $this->actingInTenant()->post("/app/tot/{$session->id}/react", ['emoji' => '🔥']);

        $this->assertSame(2, \App\Models\TotReaction::where('session_id', $session->id)->count());
    }

    public function test_an_emoji_outside_the_whitelist_is_rejected(): void
    {
        $session = $this->makeSession();

        $this->actingInTenant()->post("/app/tot/{$session->id}/react", ['emoji' => '💩'])
            ->assertSessionHasErrors('emoji');

        $this->assertSame(0, \App\Models\TotReaction::count());
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter=TotTest`
Expected: FAIL with 404 on the `/react` path.

- [ ] **Step 3: Add the method**

Add to `app/Http/Controllers/TotController.php`:

```php
    /**
     * Toggle one whitelisted emoji for the acting employee. A repeat POST of the same emoji
     * removes it; different emoji stack, one row each, guarded by the unique key.
     */
    public function react(Request $request, TotSession $session): RedirectResponse
    {
        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403, 'No employee profile in this workspace.');

        $data = $request->validate([
            'emoji' => ['required', 'string', 'in:'.implode(',', TotSession::EMOJI)],
        ]);

        $existing = TotReaction::where('session_id', $session->id)
            ->where('employee_id', $employee->id)
            ->where('emoji', $data['emoji'])
            ->first();

        if ($existing) {
            $existing->delete();

            return back();
        }

        TotReaction::create([
            'session_id' => $session->id,
            'employee_id' => $employee->id,
            'emoji' => $data['emoji'],
        ]);

        return back();
    }
```

- [ ] **Step 4: Add the route**

In `routes/web.php`, in the TOT block, **above** the `/app/tot/{session}` wildcard line:

```php
        Route::post('/app/tot/{session}/react', [TotController::class, 'react'])->name('tot.react');
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=TotTest`
Expected: PASS, 22 tests.

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/TotController.php routes/web.php tests/Feature/TotTest.php
git commit -m "feat(tot): add whitelisted emoji reactions on a session

Six emoji, several per person but one of each, enforced by a unique key so a
repeat press is a toggle rather than a duplicate. Anything outside the list is
rejected by validation, not silently stored."
```

---

### Task 7: Watched marker and private rating

**Files:**
- Modify: `app/Http/Controllers/TotController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/TotTest.php`

**Interfaces:**
- Consumes: `TotParticipation` from Task 1, `TotController::screenData` from Task 3.
- Produces: routes `tot.watched`, `tot.rate`; methods `TotController::watched(Request $request, TotSession $session): RedirectResponse` and `TotController::rate(Request $request, TotSession $session): RedirectResponse`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/TotTest.php`:

```php
    // ── Watched and rating ────────────────────────────────────────

    public function test_an_employee_marks_a_session_watched(): void
    {
        $session = $this->makeSession();

        $this->actingInTenant()->post("/app/tot/{$session->id}/watched")->assertRedirect();

        $row = \App\Models\TotParticipation::where('session_id', $session->id)->firstOrFail();
        $this->assertNotNull($row->watched_at);
    }

    public function test_rating_twice_updates_the_same_row(): void
    {
        $session = $this->makeSession();

        $this->actingInTenant()->post("/app/tot/{$session->id}/rate", ['score' => 3]);
        $this->actingInTenant()->post("/app/tot/{$session->id}/rate", ['score' => 5, 'note' => 'Clearer than I expected.']);

        $this->assertSame(1, \App\Models\TotParticipation::where('session_id', $session->id)->count());
        $row = \App\Models\TotParticipation::where('session_id', $session->id)->firstOrFail();
        $this->assertSame(5, (int) $row->score);
        $this->assertSame('Clearer than I expected.', $row->note);
    }

    public function test_a_score_outside_one_to_five_is_rejected(): void
    {
        $session = $this->makeSession();

        $this->actingInTenant()->post("/app/tot/{$session->id}/rate", ['score' => 9])
            ->assertSessionHasErrors('score');
    }

    public function test_rating_also_marks_the_session_watched(): void
    {
        $session = $this->makeSession();

        $this->actingInTenant()->post("/app/tot/{$session->id}/rate", ['score' => 4]);

        $row = \App\Models\TotParticipation::where('session_id', $session->id)->firstOrFail();
        $this->assertNotNull($row->watched_at);
    }

    // ── Rating privacy ────────────────────────────────────────────

    public function test_a_plain_employee_never_receives_scores(): void
    {
        $other = Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Presenter',
            'status' => 'active', 'workload' => 'green',
        ]);
        $session = $this->makeSession(['presenter_employee_id' => $other->id]);
        \App\Models\TotParticipation::create([
            'tenant_id' => $this->tenant->id, 'session_id' => $session->id,
            'employee_id' => $this->employee->id, 'score' => 5, 'note' => 'Very good',
        ]);

        $response = $this->actingInTenant()->get('/app/tot?year=2026');

        $response->assertViewHas('scores', fn ($scores) => $scores === []);
        $response->assertDontSee('Very good');
    }

    public function test_the_presenter_sees_their_own_average_and_notes(): void
    {
        $session = $this->makeSession();
        $rater = Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Rater',
            'status' => 'active', 'workload' => 'green',
        ]);
        \App\Models\TotParticipation::create([
            'tenant_id' => $this->tenant->id, 'session_id' => $session->id,
            'employee_id' => $rater->id, 'score' => 4, 'note' => 'Useful',
        ]);

        $response = $this->actingInTenant()->get('/app/tot?year=2026');

        $response->assertViewHas('scores', function ($scores) use ($session) {
            return isset($scores[$session->id])
                && $scores[$session->id]['average'] === 4.0
                && $scores[$session->id]['count'] === 1
                && $scores[$session->id]['notes'] === ['Useful'];
        });
    }

    public function test_hr_sees_scores_on_every_session(): void
    {
        $other = Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Presenter',
            'status' => 'active', 'workload' => 'green',
        ]);
        $session = $this->makeSession(['presenter_employee_id' => $other->id]);
        \App\Models\TotParticipation::create([
            'tenant_id' => $this->tenant->id, 'session_id' => $session->id,
            'employee_id' => $this->employee->id, 'score' => 2,
        ]);

        $hr = $this->hrActor();
        $response = $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/tot?year=2026');

        $response->assertViewHas('scores', fn ($scores) => isset($scores[$session->id]));
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter=TotTest`
Expected: FAIL with 404 on `/watched` and `/rate`.

- [ ] **Step 3: Add the methods**

Add to `app/Http/Controllers/TotController.php`:

```php
    /** Mark the session watched for the acting employee. Idempotent. */
    public function watched(Request $request, TotSession $session): RedirectResponse
    {
        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403, 'No employee profile in this workspace.');

        $row = TotParticipation::firstOrNew([
            'session_id' => $session->id,
            'employee_id' => $employee->id,
        ]);

        $row->watched_at ??= now();
        $row->save();

        return back()->with('ok', 'Marked as watched.');
    }

    /**
     * Record or replace the acting employee's rating.
     *
     * The row carries employee_id so a person can rate once and edit it later, which makes
     * the rating pseudonymous rather than anonymous. No screen ever renders who scored what,
     * and only the presenter and privileged roles see scores at all. Rating implies watching,
     * so the same call stamps watched_at.
     */
    public function rate(Request $request, TotSession $session): RedirectResponse
    {
        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403, 'No employee profile in this workspace.');

        $data = $request->validate([
            'score' => ['required', 'integer', 'min:1', 'max:5'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $row = TotParticipation::firstOrNew([
            'session_id' => $session->id,
            'employee_id' => $employee->id,
        ]);

        $row->fill([
            'score' => $data['score'],
            'note' => $data['note'] ?? null,
        ]);
        $row->watched_at ??= now();
        $row->save();

        return back()->with('ok', 'Thanks, your rating was saved.');
    }
```

- [ ] **Step 4: Add the routes**

In `routes/web.php`, in the TOT block, above the `/app/tot/{session}` wildcard:

```php
        Route::post('/app/tot/{session}/watched', [TotController::class, 'watched'])->name('tot.watched');
        Route::post('/app/tot/{session}/rate', [TotController::class, 'rate'])->name('tot.rate');
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=TotTest`
Expected: PASS, 29 tests.

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/TotController.php routes/web.php tests/Feature/TotTest.php
git commit -m "feat(tot): record who watched a session and let them rate it privately

Attendance and rating share one row per person per session, since score is
simply optional. Scores reach only the presenter and privileged roles, and never
carry a name, so the feedback is honest without pretending to be anonymous."
```

---

### Task 8: Discussion

**Files:**
- Modify: `app/Http/Controllers/TotController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/TotTest.php`

**Interfaces:**
- Consumes: `TotComment` from Task 1.
- Produces: routes `tot.comment`, `tot.comments.delete`; methods `TotController::comment(Request $request, TotSession $session): RedirectResponse` and `TotController::deleteComment(Request $request, TotComment $comment): RedirectResponse`. `screenData` gains a `comments` key: `array<int, \Illuminate\Support\Collection<int, TotComment>>` keyed by session id.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/TotTest.php`:

```php
    // ── Discussion ────────────────────────────────────────────────

    public function test_an_employee_posts_a_comment(): void
    {
        $session = $this->makeSession();

        $this->actingInTenant()->post("/app/tot/{$session->id}/comment", [
            'body' => 'Will this cover access control, or only the install?',
        ])->assertRedirect();

        $this->assertDatabaseHas('tot_comments', [
            'session_id' => $session->id,
            'employee_id' => $this->employee->id,
            'body' => 'Will this cover access control, or only the install?',
        ]);
    }

    public function test_an_empty_comment_is_rejected(): void
    {
        $session = $this->makeSession();

        $this->actingInTenant()->post("/app/tot/{$session->id}/comment", ['body' => ''])
            ->assertSessionHasErrors('body');
    }

    public function test_a_person_deletes_their_own_comment(): void
    {
        $session = $this->makeSession();
        $comment = \App\Models\TotComment::create([
            'tenant_id' => $this->tenant->id, 'session_id' => $session->id,
            'employee_id' => $this->employee->id, 'body' => 'Mine',
        ]);

        $this->actingInTenant()->delete("/app/tot/comments/{$comment->id}")->assertRedirect();

        $this->assertDatabaseMissing('tot_comments', ['id' => $comment->id]);
    }

    public function test_an_employee_cannot_delete_someone_elses_comment(): void
    {
        $session = $this->makeSession();
        $other = Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Other',
            'status' => 'active', 'workload' => 'green',
        ]);
        $comment = \App\Models\TotComment::create([
            'tenant_id' => $this->tenant->id, 'session_id' => $session->id,
            'employee_id' => $other->id, 'body' => 'Theirs',
        ]);

        $this->actingInTenant()->delete("/app/tot/comments/{$comment->id}")->assertForbidden();
    }

    public function test_hr_deletes_any_comment(): void
    {
        $session = $this->makeSession();
        $comment = \App\Models\TotComment::create([
            'tenant_id' => $this->tenant->id, 'session_id' => $session->id,
            'employee_id' => $this->employee->id, 'body' => 'Needs moderating',
        ]);

        $hr = $this->hrActor();
        $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id])
            ->delete("/app/tot/comments/{$comment->id}")->assertRedirect();

        $this->assertDatabaseMissing('tot_comments', ['id' => $comment->id]);
    }

    public function test_the_screen_carries_comments_per_session(): void
    {
        $session = $this->makeSession();
        \App\Models\TotComment::create([
            'tenant_id' => $this->tenant->id, 'session_id' => $session->id,
            'employee_id' => $this->employee->id, 'body' => 'Saved this one.',
        ]);

        $response = $this->actingInTenant()->get('/app/tot?year=2026');

        $response->assertViewHas('comments', fn ($comments) => count($comments[$session->id]) === 1);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter=TotTest`
Expected: FAIL with 404 on `/comment`.

- [ ] **Step 3: Add the methods**

Add to `app/Http/Controllers/TotController.php`:

```php
    /** Anybody in the workspace may post to a session thread. */
    public function comment(Request $request, TotSession $session): RedirectResponse
    {
        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403, 'No employee profile in this workspace.');

        $data = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ]);

        TotComment::create([
            'session_id' => $session->id,
            'employee_id' => $employee->id,
            'body' => $data['body'],
        ]);

        return back()->with('ok', 'Comment posted.');
    }

    /** The author removes their own comment; HR and management may remove any. */
    public function deleteComment(Request $request, TotComment $comment): RedirectResponse
    {
        $employee = $request->attributes->get('employee');

        abort_unless(
            $this->hasTenantRole($request, self::PRIVILEGED_ROLES)
                || ($employee && $comment->employee_id === $employee->id),
            403,
            'You can only delete your own comment.'
        );

        $comment->delete();

        return back()->with('ok', 'Comment removed.');
    }
```

Add the import:

```php
use App\Models\TotComment;
```

- [ ] **Step 4: Load comments into the screen data**

In `screenData`, add this key to the returned array, after `'scores' => ...`:

```php
            'comments' => $this->commentsBySession($ids),
```

Add the private helper:

```php
    /**
     * Thread contents per session, oldest first, so a question asked before the Saturday and
     * a follow-up posted after it read in the order they happened.
     *
     * @param  list<int>  $ids
     * @return array<int, \Illuminate\Support\Collection<int, TotComment>>
     */
    private function commentsBySession(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return TotComment::with('employee')
            ->whereIn('session_id', $ids)
            ->orderBy('created_at')
            ->get()
            ->groupBy('session_id')
            ->all();
    }
```

- [ ] **Step 5: Add the routes**

In `routes/web.php`, in the TOT block. The literal `comments` path must sit **above** the `{session}` wildcard, otherwise `comments` is parsed as a session id:

```php
        Route::delete('/app/tot/comments/{comment}', [TotController::class, 'deleteComment'])->name('tot.comments.delete');
        Route::post('/app/tot/{session}/comment', [TotController::class, 'comment'])->name('tot.comment');
```

Add the model import for the route binding if the file imports models (check the top of `routes/web.php`; if it binds by type-hint only, no import is needed there).

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=TotTest`
Expected: PASS, 35 tests.

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/TotController.php routes/web.php tests/Feature/TotTest.php
git commit -m "feat(tot): add a discussion thread under each session

Flat thread, oldest first, so a question asked before the Saturday and the
answer posted after it sit together. Authors delete their own; HR and management
moderate."
```

---

### Task 9: The year lineup screen

**Files:**
- Modify: `resources/views/screens/tot.blade.php` (replace the Task 3 placeholder entirely)
- Test: `tests/Feature/TotTest.php`

**Interfaces:**
- Consumes: every key produced by `TotController::screenData` in Tasks 3, 7, and 8.
- Produces: no new PHP interfaces.

**Visual reference:** open `docs/superpowers/specs/2026-07-27-tot-sessions-design.html` in a browser before writing any markup. Section 1 is the desktop lineup, section 2 the mobile stack, section 3 the HR edit row, section 4 the discussion, section 5 the empty year. The CSS in that file is the source of truth for the treatment described below; port it into the Blade file using the app's own tokens.

**Required treatments, from the design spec section 7:**

- Masthead in `var(--red)`: the year at 54px in `var(--font-mono)` white, the "First Saturday of every month. One person, one topic." line, the year switcher stacked at the top right, and four counts as chips on `rgba(0,0,0,.15)`.
- Twelve rows, always all twelve. Each row: a date tile (month in letter-spaced caps above a 26px mono day), the presenter name at 19px semibold as the headline, the topic as a 13px subline, then the top three reactions, the watched count, and a chevron.
- Row hover fills the date tile solid `var(--red)` with white numerals and lifts it 1px; the row washes to `var(--red-tint)`; the presenter name goes `var(--red-active)`. 170ms `cubic-bezier(.23,1,.32,1)`. Pressing scales the tile to `.96`.
- Status is carried by weight, not chips: `skipped` at 45% opacity, `not_tot` at 72%, a blank topic reading "Topic still blank" in `var(--amber)`.
- Rows expand in place with `grid-template-rows: 0fr → 1fr` over 280ms. No modal, no side panel.
- The expanded panel holds: labelled link chips, the six-emoji bar with counts and the viewer's own reactions tinted, an "I watched this" control, the rating row with the notice naming the presenter, the score summary when `$scores[$session->id]` is set, the comment thread from `$comments[$session->id]`, and a "Related Knowledge Bank entry" line linking to `$session->entry` when `entry_id` is set.
- The HR edit form is an inline panel in the row, never a modal, matching section 3 of the design file. It posts to `tot.update` and includes the link repeater and the `entry_id` picker.

**One deliberate deviation from the usual plan rules:** this task states required treatments and gives the snippets the tests depend on, rather than reproducing about 400 lines of Blade. The committed design file is the literal, runnable source for the markup and CSS, so copying it into this plan would duplicate a reference that already exists and can drift. Port from the design file; do not invent a different treatment.
- Mobile at 640px: single column, year switcher becomes a pill row, counts become a 2 by 2 grid, reactions move below the topic subline, the expanded panel loses the 72px indent, and the five rating buttons become `flex: 1`.
- Every transform is disabled under `prefers-reduced-motion: reduce`.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/TotTest.php`:

```php
    // ── Screen contents ───────────────────────────────────────────

    public function test_the_screen_shows_the_computed_saturday_and_the_status_wording(): void
    {
        $this->makeSession(['month' => 3]);
        $this->makeSession(['month' => 6, 'title' => null, 'status' => 'planned']);

        $this->travelTo('2026-07-27 09:00:00');   // June 2026 is in the past, so its blank topic is overdue

        $response = $this->actingInTenant()->get('/app/tot?year=2026');

        $response->assertOk();
        $response->assertViewHas('sessions', fn ($sessions) => $sessions[2]->session_date->toDateString() === '2026-03-07');
        $response->assertSee('Topic still blank');
        $response->assertSee('Install git on our own server');
    }

    public function test_the_rating_notice_names_the_presenter(): void
    {
        $this->makeSession();

        $response = $this->actingInTenant()->get('/app/tot?year=2026');

        $response->assertSee('Only Demo and management see scores', false);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter=TotTest`
Expected: FAIL, the placeholder screen renders neither string.

- [ ] **Step 3: Build the screen**

Replace `resources/views/screens/tot.blade.php`. Follow the conventions in `resources/views/screens/knowledge-bank.blade.php`: `@extends('layouts.app')`, an `@include('partials.guide', [...])` block with English and Malay copy, the `session('ok')` flash banner, `x-data` / `$store.ui.lang` for the language switch, and `@csrf` inside every form.

The guide block copy:

```blade
@include('partials.guide', [
    'key' => 'tot',
    'en'  => [
        'title' => 'TOT sessions',
        'body'  => 'Transfer of Technology runs on the first Saturday of every month. One person presents one topic. This board holds the whole year: who is presenting, what they covered, the slides and recordings, and what everyone thought.',
        'who'   => 'Everyone reads, reacts and rates · HR and management set the roster',
        'steps' => [
            'Pick a year at the top right. The board always shows all twelve months.',
            'Click a month to open it. Slides and recordings sit inside.',
            'React with an emoji, ask a question in the thread, and mark it watched.',
            'Rate the session 1 to 5. Only the presenter and management see scores, and never with your name.',
        ],
    ],
    'ms'  => [
        'title' => 'Sesi TOT',
        'body'  => 'Transfer of Technology diadakan pada Sabtu pertama setiap bulan. Seorang membentang satu topik. Papan ini menyimpan sepanjang tahun: siapa membentang, apa yang dibincangkan, slaid dan rakaman, serta pandangan semua orang.',
        'who'   => 'Semua baca, beri reaksi dan nilai · HR dan pengurusan tetapkan jadual',
        'steps' => [
            'Pilih tahun di bahagian kanan atas. Papan sentiasa tunjuk kesemua dua belas bulan.',
            'Klik satu bulan untuk membukanya. Slaid dan rakaman ada di dalam.',
            'Beri reaksi emoji, tanya soalan dalam bicara, dan tanda sudah tonton.',
            'Nilai sesi 1 hingga 5. Hanya pembentang dan pengurusan nampak skor, dan tidak sekali dengan nama anda.',
        ],
    ],
])
```

The rating notice must render exactly this English string so the test matches, with the presenter's name interpolated:

```blade
Only {{ $session->presenter?->name ?? $session->presenter_name ?? 'the presenter' }} and management see scores, and never with your name.
```

The subline wording, matching the design:

```blade
@php
    $subline = match (true) {
        $session->status === 'skipped' => 'No session held',
        $session->status === 'not_tot' => $session->title,
        filled($session->title) => $session->title,
        $session->status === 'planned' && $session->session_date->isPast() => 'Topic still blank',
        default => 'Topic to be confirmed',
    };
@endphp
```

Note the amber "Topic still blank" applies only to a past month, per the spec. For a future month with no topic the subline reads "Topic to be confirmed" in muted grey.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=TotTest`
Expected: PASS, 37 tests.

- [ ] **Step 5: Build the assets and check the screen in a browser**

```bash
bun run build
```

Then open `http://localhost:9100/dev/login?email=hr@amanahku.test&tenant=unijaya` and navigate to the TOT screen. Confirm against the design file:
- the red masthead and the year switcher,
- twelve rows including empty months,
- the date tile filling red on hover,
- a row expanding in place,
- the six emoji bar and the rating row,
- the layout at a 375px viewport width.

- [ ] **Step 6: Run the design detector**

```bash
node /home/shzwn/.claude-work/plugins/cache/impeccable/impeccable/4.0.2/skills/impeccable/scripts/detect.mjs --json resources/views/screens/tot.blade.php
```

Fix any `low-contrast`, `tiny-text`, or `undersized-ui-text` findings. A finding you believe is a false positive must be explained in the commit message, not silenced with an ignore rule.

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/views/screens/tot.blade.php public/build tests/Feature/TotTest.php
git commit -m "feat(tot): build the year lineup screen

The presenter's name is the headline and the date is the spine, so twelve rows
scan as a rhythm rather than a table. Status is carried by weight instead of
chips, and rows expand in place so the reader never loses their position in the
year. Follows docs/superpowers/specs/2026-07-27-tot-sessions-design.html."
```

---

### Task 10: Reminders

**Files:**
- Create: `app/Console/Commands/TotReminder.php`
- Modify: `bootstrap/app.php` (the `withSchedule` closure, after the `attendance:remind` entry near line 60)
- Test: `tests/Feature/TotReminderTest.php`

**Interfaces:**
- Consumes: `TotSession::firstSaturday()` from Task 1, `AppNotification::send()` and `AppNotification::sendMany()` (already exist).
- Produces: Artisan command `tot:remind`.

**Pattern to copy:** `app/Console/Commands/TimesheetReminder.php`. Loop tenants, set `CurrentTenant` per iteration, wrap each tenant in try/catch so one bad tenant does not skip the rest, clear the context at the end.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/TotReminderTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\TotSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Coverage for the tot:remind sweep. Dates are frozen with travelTo so each stage
 * (14 days out with no topic, 7 days out, 1 day out) can be triggered exactly.
 */
class TotReminderTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $presenter;

    private Employee $presenterEmployee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $this->presenter = User::create(['name' => 'Nabil', 'email' => 'nabil@example.com', 'password' => Hash::make('password')]);
        $this->presenter->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $this->presenterEmployee = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->presenter->id,
            'name' => 'Nabil', 'status' => 'active', 'workload' => 'green',
        ]);
    }

    private function makeSlot(array $overrides = []): TotSession
    {
        return TotSession::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'year' => 2026, 'month' => 3,
            'presenter_employee_id' => $this->presenterEmployee->id,
            'title' => 'Install git on our own server',
            'status' => 'confirmed',
        ], $overrides));
    }

    public function test_a_blank_topic_nudges_the_presenter_fourteen_days_out(): void
    {
        $this->makeSlot(['title' => null]);

        // First Saturday of March 2026 is the 7th; 14 days before is 21 February.
        $this->travelTo('2026-02-21 08:00:00');
        $this->artisan('tot:remind')->assertExitCode(0);

        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $this->presenter->id,
            'dedupe_key' => 'tot:'.TotSession::first()->id.':topic',
        ]);
    }

    public function test_a_slot_with_a_topic_is_not_nudged_fourteen_days_out(): void
    {
        $this->makeSlot();

        $this->travelTo('2026-02-21 08:00:00');
        $this->artisan('tot:remind');

        $this->assertSame(0, AppNotification::count());
    }

    public function test_the_presenter_is_reminded_seven_days_out(): void
    {
        $slot = $this->makeSlot();

        $this->travelTo('2026-02-28 08:00:00');
        $this->artisan('tot:remind');

        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $this->presenter->id,
            'dedupe_key' => 'tot:'.$slot->id.':prepare',
        ]);
    }

    public function test_everyone_is_reminded_one_day_out(): void
    {
        $other = User::create(['name' => 'Emy', 'email' => 'emy@example.com', 'password' => Hash::make('password')]);
        $other->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $other->id,
            'name' => 'Emy', 'status' => 'active', 'workload' => 'green',
        ]);

        $slot = $this->makeSlot();

        $this->travelTo('2026-03-06 08:00:00');
        $this->artisan('tot:remind');

        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $other->id, 'dedupe_key' => 'tot:'.$slot->id.':tomorrow',
        ]);
        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $this->presenter->id, 'dedupe_key' => 'tot:'.$slot->id.':tomorrow',
        ]);
    }

    public function test_running_twice_on_the_same_day_notifies_once(): void
    {
        $this->makeSlot();

        $this->travelTo('2026-02-28 08:00:00');
        $this->artisan('tot:remind');
        $this->artisan('tot:remind');

        $this->assertSame(1, AppNotification::count());
    }

    public function test_a_skipped_or_non_tot_slot_never_fires(): void
    {
        $this->makeSlot(['status' => 'skipped']);
        TotSession::create([
            'tenant_id' => $this->tenant->id, 'year' => 2026, 'month' => 4,
            'title' => 'Jamuan raya', 'status' => 'not_tot',
        ]);

        $this->travelTo('2026-02-28 08:00:00');
        $this->artisan('tot:remind');

        $this->assertSame(0, AppNotification::count());
    }

    public function test_a_slot_with_no_employee_presenter_still_sends_the_all_hands_reminder(): void
    {
        $slot = $this->makeSlot(['presenter_employee_id' => null, 'presenter_name' => 'Team']);

        $this->travelTo('2026-03-06 08:00:00');
        $this->artisan('tot:remind');

        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $this->presenter->id, 'dedupe_key' => 'tot:'.$slot->id.':tomorrow',
        ]);
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter=TotReminderTest`
Expected: FAIL with `The command "tot:remind" does not exist.`

- [ ] **Step 3: Create the command**

Run: `php artisan make:command TotReminder --no-interaction`

Replace `app/Console/Commands/TotReminder.php`:

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AppNotification;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\TotSession;
use App\Tenancy\CurrentTenant;
use Illuminate\Console\Command;

/**
 * Daily TOT reminder sweep.
 *
 * Three stages per slot, measured against the first Saturday of the slot's month:
 *   14 days out, topic still blank  -> nudge the presenter to pick one
 *    7 days out                     -> nudge the presenter to upload material
 *    1 day out                      -> tell everybody the session is tomorrow
 *
 * Every send carries a dedupe key, so a cron retry or a second run on the same day is a
 * no-op. Tenant-aware like the leave and timesheet commands: the active tenant is set per
 * loop so notification rows land under the right tenant, and the context is cleared at the
 * end. Per-tenant failures are logged and skipped rather than aborting the sweep.
 */
class TotReminder extends Command
{
    protected $signature = 'tot:remind';

    protected $description = 'Notify TOT presenters about an upcoming session, and everybody the day before.';

    /** Slot statuses that never produce a reminder. */
    private const SILENT_STATUSES = ['skipped', 'not_tot'];

    public function handle(CurrentTenant $context): int
    {
        $today = now()->startOfDay();
        $sent = 0;

        foreach (Tenant::query()->orderBy('id')->get() as $tenant) {
            $context->set($tenant);

            try {
                $sent += $this->sweepTenant($today);
            } catch (\Throwable $e) {
                report($e);
                $this->error("TOT reminder failed for tenant {$tenant->id}: {$e->getMessage()}");
            }
        }

        $context->set(null);

        $this->info("TOT reminders sent: {$sent}.");

        return self::SUCCESS;
    }

    /** Notify for every slot in this tenant whose session date is 14, 7, or 1 day away. */
    private function sweepTenant(\Illuminate\Support\Carbon $today): int
    {
        $sent = 0;

        $slots = TotSession::with('presenter.user')
            ->whereNotIn('status', self::SILENT_STATUSES)
            ->where('year', '>=', $today->year)
            ->get();

        foreach ($slots as $slot) {
            $date = TotSession::firstSaturday((int) $slot->year, (int) $slot->month);
            $url = route('app.screen', 'tot').'?year='.$slot->year;

            // Compare whole days by walking back from the session date rather than by
            // diffing. Carbon's signed diff semantics have changed between major versions;
            // isSameDay is unambiguous everywhere.
            if ($date->copy()->subDays(14)->isSameDay($today) && blank($slot->title)) {
                $sent += (int) AppNotification::send(
                    $slot->presenter?->user_id,
                    'Your TOT is in two weeks',
                    'The topic is still blank. Pick one so people can prepare.',
                    $url,
                    "tot:{$slot->id}:topic",
                );
            }

            if ($date->copy()->subDays(7)->isSameDay($today)) {
                $sent += (int) AppNotification::send(
                    $slot->presenter?->user_id,
                    'Your TOT is next Saturday',
                    'Upload your slides or notes to the TOT board before the session.',
                    $url,
                    "tot:{$slot->id}:prepare",
                );
            }

            if ($date->copy()->subDay()->isSameDay($today)) {
                $title = $slot->title ?: 'topic to be announced';
                $before = AppNotification::count();

                // active() excludes archived staff. Archiving sets archived_at and never
                // touches the status column, so filtering on status alone still notifies
                // people who have left.
                AppNotification::sendMany(
                    Employee::active()->where('status', 'active')->whereNotNull('user_id')->pluck('user_id'),
                    'TOT tomorrow',
                    $title.'. Material is on the TOT board.',
                    $url,
                    "tot:{$slot->id}:tomorrow",
                );

                $sent += AppNotification::count() - $before;
            }
        }

        return $sent;
    }
}
```

- [ ] **Step 4: Schedule it**

In `bootstrap/app.php`, inside the `withSchedule` closure, after the `attendance:remind` entry, add:

```php
        // TOT reminders: 14 days out when the topic is blank, 7 days out for the presenter,
        // 1 day out for everybody. Every send is deduped, so a retry is harmless.
        $schedule->command('tot:remind')->dailyAt('08:00')
            ->withoutOverlapping()
            ->onOneServer();
```

Match the exact chain used by the neighbouring entries; copy their `->withoutOverlapping()` / `->onOneServer()` / `->runInBackground()` usage rather than assuming.

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=TotReminderTest`
Expected: PASS, 7 tests.

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Console/Commands/TotReminder.php bootstrap/app.php tests/Feature/TotReminderTest.php
git commit -m "feat(tot): remind the presenter and the company before a session

Three stages against the computed first Saturday: a topic nudge at 14 days, a
prepare nudge at 7, and an all-hands note the day before. The 14-day stage
targets the failure the sheet shows plainly, which is a PIC assigned and a topic
never filled in. Every send is deduped so cron retries cannot double-notify."
```

---

### Task 11: Import the sheet history

**Files:**
- Create: `database/seeders/TotHistorySeeder.php`
- Test: `tests/Feature/TotHistorySeederTest.php`

**Interfaces:**
- Consumes: `TotSession` from Task 1.
- Produces: seeder class `Database\Seeders\TotHistorySeeder` with a public `run(): void`.

**Source data:** the exported sheet at `2025 2026 TOT Sabtu/Sheet1.html` (untracked, present in the working copy). The rows are transcribed into the seeder below so it does not depend on that file staying around.

**Status rules:**
- Title present and the month is past → `done`.
- The row is completely blank → `skipped`.
- The `jamuan raya 4/4/2026` row → `not_tot`.
- A PIC is present but the title is `?` or empty → `planned`, whether the month is past or future. The sheet gives no evidence those sessions ran, so the import must not invent one. Past ones render with the amber "Topic still blank" wording for HR to resolve.

**Presenter matching:** case-insensitive match of the sheet nickname against `employees.name`. On a hit set `presenter_employee_id`; on a miss leave it null and keep the raw string in `presenter_name`. `Roy` and `ROY` normalise to the same lookup. `Team` never matches. The seeder prints unmatched names so HR can fix them.

**No backfilled credit:** the seeder must not call `KnowledgeContribution::mark()`. Backfilling two years would rewrite a counter people currently read as lessons written.

**Two corrections made during implementation. The shipped seeder is the truth, not the code block below:**

1. The seeder resolves the `unijaya` tenant by slug and threads `tenant_id` explicitly through both the session upsert and the employee lookup. Nothing sets a tenant context outside a request, so the ambient-context version below throws when run as `db:seed --class=TotHistorySeeder`. It throws a `RuntimeException` when that tenant is missing, matching `DevLoginSeeder`, because a silent no-op on a data import is the worst failure mode available.
2. The `updateOrCreate` key is `['tenant_id', 'year', 'month']`, not `['year', 'month']`. That matches the table's actual unique constraint. The two-key version would have been a latent multi-tenant bug.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/TotHistorySeederTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\TotSession;
use App\Tenancy\CurrentTenant;
use Database\Seeders\TotHistorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TotHistorySeederTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['slug' => 'unijaya', 'name' => 'Unijaya', 'initials' => 'UJ']);
        app(CurrentTenant::class)->set($this->tenant);
    }

    public function test_it_imports_thirty_six_slots_with_the_expected_statuses(): void
    {
        $this->seed(TotHistorySeeder::class);

        $this->assertSame(36, TotSession::count());
        $this->assertSame(22, TotSession::where('status', 'done')->count());
        $this->assertSame(6, TotSession::where('status', 'skipped')->count());
        $this->assertSame(1, TotSession::where('status', 'not_tot')->count());
        $this->assertSame(7, TotSession::where('status', 'planned')->count());
    }

    public function test_a_multi_link_row_keeps_every_link_with_its_own_label(): void
    {
        $this->seed(TotHistorySeeder::class);

        $selenium = TotSession::where('year', 2024)->where('month', 9)->firstOrFail();

        $this->assertCount(2, $selenium->links);
        $this->assertSame(['Slides', 'Video'], array_column($selenium->links, 'label'));
    }

    public function test_an_unmatched_nickname_falls_back_to_free_text(): void
    {
        $this->seed(TotHistorySeeder::class);

        $team = TotSession::where('year', 2025)->where('month', 1)->firstOrFail();

        $this->assertNull($team->presenter_employee_id);
        $this->assertSame('Team', $team->presenter_name);
    }

    public function test_a_matching_employee_is_linked(): void
    {
        Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Nabil',
            'status' => 'active', 'workload' => 'green',
        ]);

        $this->seed(TotHistorySeeder::class);

        $session = TotSession::where('year', 2026)->where('month', 3)->firstOrFail();

        $this->assertNotNull($session->presenter_employee_id);
        $this->assertNull($session->presenter_name);
    }

    public function test_running_it_twice_does_not_duplicate(): void
    {
        $this->seed(TotHistorySeeder::class);
        $this->seed(TotHistorySeeder::class);

        $this->assertSame(36, TotSession::count());
    }

    public function test_it_never_backfills_knowledge_bank_credit(): void
    {
        $this->seed(TotHistorySeeder::class);

        $this->assertSame(0, \App\Models\KnowledgeContribution::count());
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter=TotHistorySeederTest`
Expected: FAIL with `Class "Database\Seeders\TotHistorySeeder" not found`.

- [ ] **Step 3: Create the seeder**

Run: `php artisan make:seeder TotHistorySeeder --no-interaction`

Replace `database/seeders/TotHistorySeeder.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\TotSession;
use Illuminate\Database\Seeder;

/**
 * Imports the TOT history from the Google Sheet this board replaces
 * ("2025 2026 TOT Sabtu"), covering 2024 through 2026.
 *
 * Idempotent: keyed on (year, month) via updateOrCreate, so a re-run is a no-op.
 *
 * Two rules worth knowing before changing anything here:
 *  - A past month with a PIC but no title imports as `planned`, not `done`. The sheet gives
 *    no evidence the session ran, so the import must not invent one. HR resolves those.
 *  - Nothing here credits a Knowledge Bank month. Backfilling two years would rewrite a
 *    counter that people currently read as lessons written.
 */
class TotHistorySeeder extends Seeder
{
    /**
     * year => month => [presenter, title, status, [[label, url], ...]]
     * A null presenter and null title means the sheet row was blank.
     *
     * @var array<int, array<int, array{0: ?string, 1: ?string, 2: string, 3: list<array{0: string, 1: string}>}>>
     */
    private const HISTORY = [
        2024 => [
            1 => ['Hakime', 'e-Filing (LHDN)', 'done', []],
            2 => [null, null, 'skipped', []],
            3 => ['Aizat', 'Power BI', 'done', []],
            4 => [null, null, 'skipped', []],
            5 => [null, null, 'skipped', []],
            6 => ['Kak Lin', 'Guidance for attendance and leave in the new HR system', 'done', []],
            7 => ['Roy', 'Power BI', 'done', []],
            8 => ['Qisti', 'Livewire Laravel', 'done', [
                ['Slides', 'https://drive.google.com/file/d/1nqwcX6cEiyqP9oqZ7J0Gv_x3fv4VQWw9/view?usp=drive_link'],
            ]],
            9 => ['Hiel', 'Selenium', 'done', [
                ['Slides', 'https://drive.google.com/file/d/1XHr4demW-zWjcRN2xLbk6LABHj_xD-HL/view?usp=drive_link'],
                ['Video', 'https://drive.google.com/file/d/1U1lpvT9nr8KAvkt3LYU4zbzEPX593WxT/view?usp=drive_link'],
            ]],
            10 => ['Rubmin', 'ETL tools', 'done', [
                ['Recording', 'https://app.read.ai/analytics/meetings/01J9D850JZXA9GK3C8WR73281V?utm_source=Share_CopyLink'],
                ['Slides', 'https://docs.google.com/presentation/d/17kHadxXmu-0fxXiBihXOXhlRXy9gGYtc/edit?usp=sharing'],
            ]],
            11 => ['PE', 'PE presentation review', 'done', [
                ['Recording', 'https://app.read.ai/analytics/meetings/01JBND503P22WSYD4H05NAEMXP?utm_source=Share_Nav'],
            ]],
            12 => [null, null, 'skipped', []],
        ],
        2025 => [
            1 => ['Team', 'Bad news social experiment', 'done', []],
            2 => ['Team', 'Catch the hacker', 'done', [
                ['Brief', 'https://www.canva.com/design/DAGdRRWvFh8/SRJeFyLaZdlCUHeAPBiu9w/edit'],
                ['Findings team A', 'https://www.canva.com/design/DAGee6ZoyGw/fgYHKyFX9QDPHfWfvFFUiQ/edit'],
                ['Findings team B', 'https://www.canva.com/design/DAGefOoJFlg/m8KkixzQpIW7I9BS3NZo7g/edit'],
            ]],
            3 => [null, null, 'skipped', []],
            4 => ['Emy', 'Testing tools', 'done', []],
            5 => ['Rubmin', 'Cordova', 'done', []],
            6 => ['Syakir', 'Web admin', 'done', []],
            7 => ['Kussairi', 'Proses kerja baru', 'done', []],
            8 => [null, null, 'skipped', []],
            9 => ['Kussairi', 'Cara kawal stress', 'done', []],
            10 => ['Faris', 'Flutter', 'done', []],
            11 => ['Shuk', 'Middleware', 'done', []],
            12 => ['Hafiz', 'Vue.js', 'done', []],
        ],
        2026 => [
            1 => ['Solihin', 'Google AI Antigravity', 'done', [
                ['Slides', 'https://docs.google.com/presentation/d/1zpU1_4khixjqtvP6hylAKLKSMAQIf5LR/edit?usp=drive_link'],
            ]],
            2 => ['Roy', null, 'planned', []],
            3 => ['Nabil', 'Install git on our own server', 'done', [
                ['Slides', 'https://drive.google.com/file/d/1q-O_zengvENin2wZlRaNVQl1wgtpin-I/view?usp=drive_link'],
            ]],
            4 => [null, 'Jamuan raya', 'not_tot', []],
            5 => ['Syafiq', 'Laravel AI chatbox v1', 'done', [
                ['Slides', 'https://docs.google.com/presentation/d/1jVvGoqBohSNGFeWiO1MalWhwtva14i8eIf_vO3pqqdw/edit'],
            ]],
            6 => ['Huraidi', null, 'planned', []],
            7 => ['Ah Yew', 'Content template manager', 'done', [
                ['Slides', 'https://drive.google.com/file/d/15-mIWngpyNET0pPGzWMYANIZqKg-yD68/view?usp=sharing'],
            ]],
            8 => ['Rubmin', null, 'planned', []],
            9 => ['Emy', null, 'planned', []],
            10 => ['Salam', null, 'planned', []],
            11 => ['Hafiz', null, 'planned', []],
            12 => ['Faris', null, 'planned', []],
        ],
    ];

    public function run(): void
    {
        $unmatched = [];

        foreach (self::HISTORY as $year => $months) {
            foreach ($months as $month => [$presenter, $title, $status, $links]) {
                $employee = $presenter ? $this->matchEmployee($presenter) : null;

                if ($presenter && ! $employee) {
                    $unmatched[$presenter] = true;
                }

                TotSession::updateOrCreate(
                    ['year' => $year, 'month' => $month],
                    [
                        'presenter_employee_id' => $employee?->id,
                        'presenter_name' => $employee ? null : $presenter,
                        'title' => $title,
                        'status' => $status,
                        'held_on' => $status === 'done'
                            ? TotSession::firstSaturday($year, $month)->toDateString()
                            : null,
                        'links' => $links === []
                            ? null
                            : array_map(fn (array $l) => ['label' => $l[0], 'url' => $l[1]], $links),
                    ],
                );
            }
        }

        if ($unmatched !== []) {
            $this->command?->warn(
                'TOT import: no employee matched for '.implode(', ', array_keys($unmatched))
                .'. These slots keep the name as free text; fix them on the TOT screen.'
            );
        }
    }

    /** Case-insensitive name match so "Roy" and "ROY" resolve to the same person. */
    private function matchEmployee(string $name): ?Employee
    {
        return Employee::whereRaw('LOWER(name) = ?', [mb_strtolower(trim($name))])->first();
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=TotHistorySeederTest`
Expected: PASS, 6 tests.

- [ ] **Step 5: Import into the dev database and look at it**

```bash
lerd artisan db:seed --class=TotHistorySeeder
```

Then open `http://localhost:9100/dev/login?email=hr@amanahku.test&tenant=unijaya`, go to the TOT screen, and step through 2024, 2025, and 2026. Confirm the counts in the masthead match 22 held, 6 with no session, 1 not TOT, 7 planned across the three years, and that the two 2026 slots with no topic show the amber wording.

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/seeders/TotHistorySeeder.php tests/Feature/TotHistorySeederTest.php
git commit -m "feat(tot): import three years of TOT history from the sheet

36 slots across 2024 to 2026. A past month with a PIC but no title imports as
planned rather than done, because the sheet gives no evidence the session ran and
the import must not invent one. Nothing here backfills Knowledge Bank credit,
which would rewrite two years of a counter people read as lessons written."
```

---

### Task 12: Full suite and static analysis

**Files:** none created; this task proves the whole feature sits cleanly in the codebase.

- [ ] **Step 1: Run the full test suite**

Run: `php artisan test --compact`
Expected: PASS. Pay attention to `AllScreensRenderTest`, `FeatureEnforcementTest`, `CrossTenantDenialTest`, and `CoreWritePathsTest`, which are the suites most likely to notice a new screen and new write routes.

- [ ] **Step 2: Run static analysis**

Run: `vendor/bin/phpstan analyse --memory-limit=1G`
Expected: no new errors. Fix any that name the TOT files.

- [ ] **Step 3: Run the formatter across everything touched**

Run: `vendor/bin/pint --dirty --format agent`
Expected: no changes left to make.

- [ ] **Step 4: Commit anything the previous steps changed**

```bash
git add -A
git commit -m "chore(tot): satisfy static analysis and the full suite"
```

If nothing changed, skip the commit.

---

## Out of scope

Confirmed with the user during brainstorming, do not build these:

- File upload. Links only, same as the sheet.
- Nested comment replies.
- Public score leaderboards or presenter rankings.
- Automatic Knowledge Bank entry creation from a session.
- Backfilled contribution credit for imported history.
- Calendar or Events integration.

## Open items

1. **Roster assignment (KIV).** Who sets the yearly roster is deferred. `TotController::PRIVILEGED_ROLES` is the single constant to change when the user decides.
2. **Unmatched presenter nicknames.** The import leaves some slots with a `presenter_name` and no employee. HR resolves these in the UI after the first deploy.

## Deploy note

`resources/views/screens/tot.blade.php` and `resources/css/app.css` changes require `bun run build` locally and the committed `public/build` before deploying, per `CLAUDE.md`. The migration runs automatically through `deploy.sh`, so take a `mysqldump` first.
