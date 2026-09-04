# Board Child Cards (Subtasks) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A T.A.A. board card can hold child cards (subtasks). The parent shows as a stack, opening it shows an overview beside the drawer, the parent cannot reach Done until every child is done, and a child ticked done feeds the parent's timesheet note for that day.

**Architecture:** A child is a `work_items` row with `parent_id`. A global scope `ParentOnly` hides children from every existing query, and the two places that want children (`children()` relation, route binding) opt out. Authorization on a child is evaluated against its parent inside `BoardRules`, so every controller and MCP tool gets the rule for free. The drawer is reused unchanged for a child; a new overview partial sits inside the scrim.

**Tech Stack:** Laravel 13 / PHP 8.5, PHPUnit 12, Alpine 3, Blade, Tailwind 4 (design tokens in `resources/css/app.css`), bun for the asset build.

**Spec:** `docs/superpowers/specs/2026-09-03-board-child-cards-design.html`

## Global Constraints

- Shell for tool calls is zsh; write POSIX. Use `bun`, never npm/node.
- Tests: `php artisan test --compact --filter=<Name>` or a file path. Run `vendor/bin/pint --dirty --format agent` before every commit. PHPStan: `vendor/bin/phpstan analyse --memory-limit=1G <files>`.
- Dev DB and test DB are separate: run `php artisan migrate` after adding a migration or the app 500s while tests pass.
- Assets: `php artisan view:clear && php artisan view:cache && bun run build`, commit `public/build` with the change. Done once, in the last task.
- No new base folders, no new dependencies. Follow sibling file conventions and docblock style (prose docblocks explaining *why*).
- Commit on `dev`. Do not push.
- Copy: every user-facing string has an English and a Malay form via `$store.ui.lang==='en' ? '…' : '…'` in Blade or `this.t('en','ms')` in JS.
- Status values: parents `todo|prog|review|done`; children `todo|done` only. A child's "Open" in the UI is `todo` in the database.
- The 422 message for the Done gate is exactly: `1 subtask still open. Tick it off before moving this card to Done.` / `{n} subtasks still open. Tick them off before moving this card to Done.`

---

## File map

| File | Responsibility |
|---|---|
| `database/migrations/2026_09_03_120000_add_parent_id_to_work_items.php` | `parent_id` FK, drops the dead `subtasks` column if present |
| `app/Models/Scopes/ParentOnly.php` | global scope `whereNull(parent_id)` |
| `app/Models/WorkItem.php` | scope registration, `parent()`, `children()`, `isChild()`, `openChildCount()`, `childSummary()`, `resolveRouteBinding()` |
| `app/Support/BoardRules.php` | `subject()` (child → parent), `assertChildrenDoneForStatus()` |
| `app/Observers/WorkItemObserver.php` | early return for children |
| `app/Console/Commands/ArchiveDoneWorkItems.php` | children follow an archived parent |
| `app/Services/StaffArchiver.php` | drop the scope so children follow the owner |
| `app/Http/Controllers/WorkItemController.php` | `store()` with `parent_id`, `show()` family payload, `move()` child rules + gate, `archive()`/`restore()` cascade, `cardHtml()` loads children |
| `app/Http/Controllers/Concerns/BuildsWorkData.php` | eager-load `children` for the board |
| `app/Mcp/Tools/{Create,Move,Update,Archive,Restore}CardTool.php` | unscoped lookups, parent_id on create, gate on move, refuse child archive |
| `app/Timesheet/BoardSuggestions.php` | child titles into the parent row's note |
| `resources/views/partials/work-card.blade.php` | `wc--stack`, `wc-sub` counter |
| `resources/views/partials/work-overview.blade.php` | new: overview panel in the scrim |
| `resources/views/partials/work-drawer.blade.php` | child mode: back link, Open/Done toggle, hidden pickers, Subtasks add row |
| `resources/js/work-board.js`, `resources/js/team-board.js` | family state, `openChild`, `backToParent`, `addChild`, `tickChild` |
| `resources/css/app.css` | `.wc--stack`, `.wd-ov*` |
| `tests/Feature/WorkItemChildTest.php` | new, the behaviour suite |
| `tests/Feature/WorkItemCardHtmlTest.php`, `tests/Feature/BoardSuggestionsTest.php`, `tests/Feature/Mcp/AmanahkuWriteToolsTest.php` | additions |

---

### Task 1: Remove the checklist build from the working tree

The JSON checklist built on 2026-09-03 is uncommitted. Nothing shipped. Revert it wholesale, keep only the spec, the plan and the changelog entry (rewritten in Task 12).

**Files:**
- Revert: everything under `app/`, `resources/js`, `resources/css`, `resources/views`, `routes/`, `public/build`, `tests/Feature/WorkItemSubtaskTest.php`, `tests/Feature/Mcp/AmanahkuWriteToolsTest.php`
- Delete: `database/migrations/2026_09_03_100000_add_subtasks_to_work_items.php`
- Keep: `docs/superpowers/**`, `resources/changelog.yaml`, `tests/Feature/ChangelogScreenTest.php`

- [ ] **Step 1: Roll the dev DB back one migration while the file still exists**

Run: `php artisan migrate:rollback --step=1`
Expected: `Rolling back: 2026_09_03_100000_add_subtasks_to_work_items` … DONE

- [ ] **Step 2: Revert the tracked files and delete the untracked ones**

```bash
git checkout -- app resources/js resources/css resources/views routes public/build tests/Feature/Mcp/AmanahkuWriteToolsTest.php
rm tests/Feature/WorkItemSubtaskTest.php database/migrations/2026_09_03_100000_add_subtasks_to_work_items.php
git status --short
```
Expected: only `docs/superpowers/...`, `resources/changelog.yaml`, `tests/Feature/ChangelogScreenTest.php` remain modified/untracked.

- [ ] **Step 3: Confirm the board suite is green on the reverted tree**

Run: `php artisan test --compact --filter='WorkItem|BoardCard|Changelog'`
Expected: all pass.

- [ ] **Step 4: Commit the spec and plan**

```bash
git add docs/superpowers/specs/2026-09-03-board-child-cards-design.html docs/superpowers/plans/2026-09-03-board-child-cards.md
git commit -m "docs(board): spec and plan for child cards, replaces the JSON checklist idea"
```

---

### Task 2: Migration, scope, model relations

**Files:**
- Create: `database/migrations/2026_09_03_120000_add_parent_id_to_work_items.php`
- Create: `app/Models/Scopes/ParentOnly.php`
- Modify: `app/Models/WorkItem.php`
- Test: `tests/Feature/WorkItemChildTest.php` (new)

**Interfaces:**
- Produces: `WorkItem::parent(): BelongsTo`, `WorkItem::children(): HasMany` (unscoped, ordered by `sort_order`, `id`), `WorkItem::isChild(): bool`, `WorkItem::openChildCount(): int`, `WorkItem::childSummary(): ?array{done:int,total:int}`, `App\Models\Scopes\ParentOnly`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/WorkItemChildTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Scopes\ParentOnly;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Child cards (subtasks): rows on work_items with parent_id set, hidden from every
 * ordinary query by the ParentOnly scope, opened from the parent's overview. See
 * docs/superpowers/specs/2026-09-03-board-child-cards-design.html.
 */
class WorkItemChildTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private Employee $ownerEmp;

    private User $participant;

    private Employee $participantEmp;

    private User $stranger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);

        [$this->owner, $this->ownerEmp] = $this->person('Owner', 'owner@example.com');
        [$this->participant, $this->participantEmp] = $this->person('Pat', 'pat@example.com');
        [$this->stranger] = $this->person('Stranger', 'stranger@example.com');
    }

    /** @return array{0: User, 1: Employee} */
    private function person(string $name, string $email): array
    {
        $user = User::create(['name' => $name, 'email' => $email, 'password' => Hash::make('password')]);
        $user->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $employee = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'name' => $name, 'status' => 'active', 'workload' => 'green',
        ]);

        return [$user, $employee];
    }

    private function as(User $user): self
    {
        $this->actingAs($user)->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    private function parent(array $attrs = []): WorkItem
    {
        return $this->ownerEmp->workItems()->create(array_merge([
            'tenant_id' => $this->tenant->id, 'title' => 'Parent', 'type' => 'task',
            'priority' => 'low', 'status' => 'todo', 'progress' => 0,
        ], $attrs));
    }

    private function child(WorkItem $parent, array $attrs = []): WorkItem
    {
        return WorkItem::withoutGlobalScope(ParentOnly::class)->create(array_merge([
            'tenant_id' => $this->tenant->id, 'employee_id' => $parent->employee_id,
            'parent_id' => $parent->id, 'title' => 'Child', 'type' => $parent->type,
            'priority' => 'medium', 'status' => 'todo', 'progress' => 0,
        ], $attrs));
    }

    public function test_children_are_hidden_from_ordinary_queries_and_reachable_through_the_relation(): void
    {
        $parent = $this->parent();
        $child = $this->child($parent);

        $this->assertSame([$parent->id], WorkItem::query()->pluck('id')->all());
        $this->assertSame([$child->id], $parent->children()->pluck('id')->all());
        $this->assertTrue($child->fresh()->isChild());
        $this->assertSame($parent->id, WorkItem::withoutGlobalScope(ParentOnly::class)->find($child->id)->parent->id);
    }

    public function test_child_summary_counts_done_over_total(): void
    {
        $parent = $this->parent();
        $this->child($parent, ['status' => 'done']);
        $this->child($parent);

        $this->assertSame(['done' => 1, 'total' => 2], $parent->childSummary());
        $this->assertSame(1, $parent->openChildCount());
        $this->assertNull($this->parent()->childSummary());
    }

    public function test_deleting_the_parent_deletes_its_children(): void
    {
        $parent = $this->parent();
        $child = $this->child($parent);

        $parent->delete();

        $this->assertNull(WorkItem::withoutGlobalScope(ParentOnly::class)->find($child->id));
    }
}
```

- [ ] **Step 2: Run to verify they fail**

Run: `php artisan test --compact tests/Feature/WorkItemChildTest.php`
Expected: FAIL (unknown column `parent_id`, class `ParentOnly` not found).

- [ ] **Step 3: Migration**

Create `database/migrations/2026_09_03_120000_add_parent_id_to_work_items.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_items', function (Blueprint $table) {
            // A child card (subtask) points at its parent. One level only, enforced in
            // code (WorkItemController::store). Deleting the parent deletes the children.
            $table->foreignId('parent_id')->nullable()->after('employee_id')
                ->constrained('work_items')->cascadeOnDelete();
        });

        // The JSON checklist tried on the same day never shipped; a dev database that
        // ran its migration still carries the column.
        if (Schema::hasColumn('work_items', 'subtasks')) {
            Schema::table('work_items', fn (Blueprint $table) => $table->dropColumn('subtasks'));
        }
    }

    public function down(): void
    {
        Schema::table('work_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
        });
    }
};
```

- [ ] **Step 4: Scope**

Create `app/Models/Scopes/ParentOnly.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Hides child cards (work_items.parent_id set) from every ordinary WorkItem query.
 *
 * A child lives under its parent and nowhere else: not in a board column, not in a
 * count, not on a timesheet, not in the archive list. Twenty-odd query sites already
 * read work_items and every one of them wants parents only, so the rule lives on the
 * model rather than being repeated at each of them. The two places that want children
 * opt out: WorkItem::children() and WorkItem::resolveRouteBinding().
 */
class ParentOnly implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $builder->whereNull($model->getTable().'.parent_id');
    }
}
```

- [ ] **Step 5: Model**

In `app/Models/WorkItem.php` add imports `use App\Models\Scopes\ParentOnly;` and after the `LABELS` constant:

```php
    protected static function booted(): void
    {
        static::addGlobalScope(new ParentOnly);
    }

    /**
     * Route-model binding must reach a child: /app/board/{workItem} is how the drawer
     * opens one. Every other query keeps the ParentOnly scope.
     */
    public function resolveRouteBinding($value, $field = null): ?Model
    {
        return static::withoutGlobalScope(ParentOnly::class)
            ->where($field ?? $this->getRouteKeyName(), $value)
            ->first();
    }

    /** @return BelongsTo<WorkItem, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(WorkItem::class, 'parent_id');
    }

    /**
     * This card's subtasks, in the order they were added. Bypasses ParentOnly: it is
     * the one relation whose whole point is the rows that scope hides.
     *
     * @return HasMany<WorkItem, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(WorkItem::class, 'parent_id')
            ->withoutGlobalScope(ParentOnly::class)
            ->orderBy('sort_order')->orderBy('id');
    }

    public function isChild(): bool
    {
        return $this->parent_id !== null;
    }

    /** Subtasks not yet ticked. Reads the loaded relation when present, else one query. */
    public function openChildCount(): int
    {
        return $this->relationLoaded('children')
            ? $this->children->where('status', '!=', 'done')->count()
            : $this->children()->where('status', '!=', 'done')->count();
    }

    /**
     * Done/total for the card face's "2/3" badge. Null when the card has no subtasks,
     * so the badge simply does not render.
     *
     * @return array{done:int,total:int}|null
     */
    public function childSummary(): ?array
    {
        $children = $this->relationLoaded('children') ? $this->children : $this->children()->get();
        if ($children->isEmpty()) {
            return null;
        }

        return ['done' => $children->where('status', 'done')->count(), 'total' => $children->count()];
    }
```

Add `use Illuminate\Database\Eloquent\Model;` to the imports (needed for the return type) and add `@property int|null $parent_id` to the class docblock.

- [ ] **Step 6: Migrate dev DB, run tests**

Run: `php artisan migrate && php artisan test --compact tests/Feature/WorkItemChildTest.php`
Expected: 3 passed.

- [ ] **Step 7: Run the wider suite, the proof the scope broke nothing**

Run: `php artisan test --compact --filter='WorkItem|Board|Dashboard|Timesheet|Nav|Mcp|Workforce|StaffArchiver'`
Expected: all pass.

- [ ] **Step 8: Pint, phpstan, commit**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse --memory-limit=1G app/Models/WorkItem.php app/Models/Scopes/ParentOnly.php
git add database/migrations/2026_09_03_120000_add_parent_id_to_work_items.php app/Models/Scopes/ParentOnly.php app/Models/WorkItem.php tests/Feature/WorkItemChildTest.php
git commit -m "feat(board): child cards live on work_items behind a parent-only scope"
```

---

### Task 3: BoardRules, the observer, the archivers

**Files:**
- Modify: `app/Support/BoardRules.php`
- Modify: `app/Observers/WorkItemObserver.php:21`
- Modify: `app/Console/Commands/ArchiveDoneWorkItems.php:31-36`
- Modify: `app/Services/StaffArchiver.php:70`
- Test: `tests/Feature/WorkItemChildTest.php`

**Interfaces:**
- Produces: `BoardRules::assertChildrenDoneForStatus(WorkItem $item, string $status): void` (throws `ValidationException` keyed `status`); `authorizeAccess`, `authorizeManage`, `canManage`, `isManagerOver`, `coversCardOwner`, `isAssigner` all evaluate a child against its parent.

- [ ] **Step 1: Failing tests**

Append to `WorkItemChildTest`:

```php
    public function test_done_gate_refuses_a_parent_with_an_open_child(): void
    {
        $parent = $this->parent();
        $this->child($parent);
        $this->child($parent, ['status' => 'done']);

        try {
            app(\App\Support\BoardRules::class)->assertChildrenDoneForStatus($parent, 'done');
            $this->fail('expected a ValidationException');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertSame('1 subtask still open. Tick it off before moving this card to Done.', $e->errors()['status'][0]);
        }

        // Other columns carry no gate.
        app(\App\Support\BoardRules::class)->assertChildrenDoneForStatus($parent, 'review');
        $this->assertTrue(true);
    }

    public function test_done_gate_passes_once_every_child_is_done(): void
    {
        $parent = $this->parent();
        $this->child($parent, ['status' => 'done']);

        app(\App\Support\BoardRules::class)->assertChildrenDoneForStatus($parent, 'done');
        $this->assertTrue(true);
    }

    public function test_the_hourly_archiver_takes_children_with_the_parent(): void
    {
        $parent = $this->parent(['status' => 'done', 'done_at' => now()->subDays(2)]);
        $child = $this->child($parent, ['status' => 'done', 'done_at' => now()->subDays(2)]);

        $this->artisan('work:archive-done')->assertSuccessful();

        $this->assertNotNull($parent->fresh()->archived_at);
        $this->assertNotNull(WorkItem::withoutGlobalScope(ParentOnly::class)->find($child->id)->archived_at);
    }

    public function test_a_child_never_records_a_progress_stint_or_calendar_sync(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $parent = $this->parent();
        $child = $this->child($parent, ['due_at' => now()->addDay()]);
        $child->update(['status' => 'done']);

        $this->assertSame(0, \App\Models\WorkItemProgressStint::withoutGlobalScope('tenant')->where('work_item_id', $child->id)->count());
        \Illuminate\Support\Facades\Queue::assertNothingPushed();
    }
```

- [ ] **Step 2: Run, expect failures**

Run: `php artisan test --compact tests/Feature/WorkItemChildTest.php`
Expected: the four new tests fail (`assertChildrenDoneForStatus` undefined; archiver leaves the child; calendar job pushed).

- [ ] **Step 3: BoardRules**

In `app/Support/BoardRules.php`:

Add after `authorizeAccess()`'s docblock, before the method, a private helper and route every rule through it. Replace the bodies as follows (only the lines shown change; keep the docblocks and add the sentence noted):

```php
    /**
     * The card a rule is judged against. A child card (subtask) carries no rights of
     * its own: whoever may open, move or edit the parent may do the same to its
     * children, and nobody else may. Every public rule below resolves through this, so
     * the controller and the MCP tools never have to know which kind they hold.
     */
    private function subject(WorkItem $item): WorkItem
    {
        return $item->parent_id ? $item->parent : $item;
    }
```

- `authorizeAccess()`: keep the tenant check on `$item`, then `$item = $this->subject($item);` before the `abort_unless(...)` block.
- `authorizeManage()`: same, `$item = $this->subject($item);` after the tenant check.
- `canManage()`: first line `$item = $this->subject($item);`.
- `isAssigner()`: first line `$item = $this->subject($item);`.
- `coversCardOwner()`: first line `$item = $this->subject($item);`.

Add to each affected docblock the sentence: "A child card is judged as its parent, see subject()."

Replace nothing else. Then add after `assertDueDateRetained()`:

```php
    /**
     * A card cannot land on Done while any of its subtasks is still open. Lives here,
     * not in the controller, for the same reason assertDueDateRetained() does: one rule
     * shared by WorkItemController::move() and App\Mcp\Tools\MoveCardTool, so the
     * browser and MCP surfaces cannot disagree on when Done is allowed. A child has no
     * children of its own, so the check is free for it.
     */
    public function assertChildrenDoneForStatus(WorkItem $item, string $status): void
    {
        if ($status !== 'done') {
            return;
        }

        $open = $item->openChildCount();

        if ($open > 0) {
            throw ValidationException::withMessages([
                'status' => $open === 1
                    ? '1 subtask still open. Tick it off before moving this card to Done.'
                    : "{$open} subtasks still open. Tick them off before moving this card to Done.",
            ]);
        }
    }
```

- [ ] **Step 4: Observer**

In `app/Observers/WorkItemObserver.php`, first lines of `saved()`:

```php
    public function saved(WorkItem $item): void
    {
        // A child card (subtask) has no column, so no stint, and its due date is not
        // the one the calendar cares about: the parent's is.
        if ($item->parent_id !== null) {
            return;
        }

        $this->recordProgressStint($item);
```

- [ ] **Step 5: Hourly archiver**

In `app/Console/Commands/ArchiveDoneWorkItems.php`, after the `$count = ...->update([...]);` statement:

```php
        // Children follow their parent off the board. Their own done_at is irrelevant:
        // an archived parent is finished business, subtasks included.
        WorkItem::withoutGlobalScope(ParentOnly::class)
            ->whereNotNull('parent_id')
            ->whereNull('archived_at')
            ->whereHas('parent', fn ($q) => $q->whereNotNull('archived_at'))
            ->update(['archived_at' => now()]);
```

Add `use App\Models\Scopes\ParentOnly;`.

- [ ] **Step 6: Staff archiver**

`app/Services/StaffArchiver.php` lines 69-73 hand a leaver's open cards to their manager. Children carry the owner's `employee_id` too, so after that update add:

```php
            // Subtasks follow their parent to the manager, done or not: a child's
            // employee_id must always equal its parent's.
            WorkItem::withoutGlobalScope(ParentOnly::class)
                ->whereNotNull('parent_id')
                ->where('employee_id', $employee->id)
                ->whereHas('parent', fn ($q) => $q->where('employee_id', $employee->reports_to_id))
                ->update(['employee_id' => $employee->reports_to_id]);
```

Add `use App\Models\Scopes\ParentOnly;`. Extend the existing `tests/Feature/StaffArchiverTest.php` (or whichever test covers the hand-over, `grep -l "reports_to_id" tests/Feature/*Archiver*`) with one case: a parent with a child is handed over and the child's `employee_id` matches the manager's afterwards.

- [ ] **Step 7: Run tests**

Run: `php artisan test --compact tests/Feature/WorkItemChildTest.php tests/Feature/WorkItemArchiveTest.php tests/Feature/WorkItemObserverTest.php tests/Feature/TeamBoardAccessTest.php`
Expected: all pass.

- [ ] **Step 8: Pint, phpstan, commit**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse --memory-limit=1G app/Support/BoardRules.php app/Observers/WorkItemObserver.php app/Console/Commands/ArchiveDoneWorkItems.php app/Services/StaffArchiver.php
git add -A app/Support app/Observers app/Console app/Services tests/Feature/WorkItemChildTest.php
git commit -m "feat(board): child cards borrow the parent's rights, gate Done on open children, follow the parent into the archive"
```

---

### Task 4: Controller, create and read

**Files:**
- Modify: `app/Http/Controllers/WorkItemController.php` (`store()`, `show()`, `cardPayload()`, `cardHtml()`, new `familyPayload()`, `childPayload()`)
- Modify: `app/Http/Controllers/Concerns/BuildsWorkData.php:175-179`
- Test: `tests/Feature/WorkItemChildTest.php`

**Interfaces:**
- Produces: `POST /app/board` accepts `parent_id`; response `{card, html, parent_html}` (201). `GET /app/board/{id}` card payload gains `parent_id`, `child_summary`, `family: {parent: {id,title,type,due_label,child_summary}, children: [{id,title,status,due_label,people:[{initials,color,name}]}]}`. `cardPayload()` gains `parent_id`, `child_summary`.
- `familyPayload(WorkItem $top): array`, `childPayload(WorkItem $child): array` (private).

- [ ] **Step 1: Failing tests**

Append to `WorkItemChildTest`:

```php
    public function test_owner_creates_a_child_that_copies_board_type_and_project_from_the_parent(): void
    {
        $parent = $this->parent(['type' => 'adhoc']);

        $res = $this->as($this->owner)->postJson('/app/board', ['title' => 'Step one', 'parent_id' => $parent->id]);

        $res->assertCreated()->assertJsonPath('card.parent_id', $parent->id)->assertJsonStructure(['parent_html']);
        $child = WorkItem::withoutGlobalScope(ParentOnly::class)->find($res->json('card.id'));
        $this->assertSame($this->ownerEmp->id, $child->employee_id);
        $this->assertSame('adhoc', $child->type);
        $this->assertSame('todo', $child->status);
        $this->assertSame('medium', $child->priority);
        $this->assertStringContainsString('wc--stack', $res->json('parent_html'));
    }

    public function test_a_participant_of_the_parent_can_add_a_child_on_the_owners_board(): void
    {
        $parent = $this->parent(['due_at' => now()->addWeek()]);
        $parent->participants()->attach($this->participantEmp->id);

        $res = $this->as($this->participant)->postJson('/app/board', ['title' => 'Mine', 'parent_id' => $parent->id]);

        $res->assertCreated();
        $this->assertSame($this->ownerEmp->id, WorkItem::withoutGlobalScope(ParentOnly::class)->find($res->json('card.id'))->employee_id);
    }

    public function test_a_stranger_cannot_add_a_child(): void
    {
        $parent = $this->parent();

        $this->as($this->stranger)->postJson('/app/board', ['title' => 'Nope', 'parent_id' => $parent->id])->assertForbidden();
    }

    public function test_a_child_cannot_have_children(): void
    {
        $child = $this->child($this->parent());

        $this->as($this->owner)->postJson('/app/board', ['title' => 'Grandchild', 'parent_id' => $child->id])
            ->assertStatus(422)->assertJsonValidationErrors('parent_id');
    }

    public function test_show_returns_the_family_for_a_parent_and_for_a_child(): void
    {
        $parent = $this->parent();
        $done = $this->child($parent, ['title' => 'A', 'status' => 'done']);
        $open = $this->child($parent, ['title' => 'B']);

        $this->as($this->owner)->getJson("/app/board/{$parent->id}")
            ->assertOk()
            ->assertJsonPath('card.family.parent.id', $parent->id)
            ->assertJsonPath('card.family.children.0.id', $done->id)
            ->assertJsonPath('card.family.children.0.status', 'done')
            ->assertJsonPath('card.family.children.1.title', 'B')
            ->assertJsonPath('card.child_summary.total', 2);

        $this->as($this->owner)->getJson("/app/board/{$open->id}")
            ->assertOk()
            ->assertJsonPath('card.parent_id', $parent->id)
            ->assertJsonPath('card.family.parent.title', 'Parent')
            ->assertJsonPath('card.family.children.1.id', $open->id);
    }

    public function test_children_never_appear_in_the_board_columns(): void
    {
        $parent = $this->parent();
        $this->child($parent, ['title' => 'Hidden child']);

        $this->as($this->owner)->get('/app/board')->assertOk()->assertDontSee('Hidden child');
    }
```

- [ ] **Step 2: Run, expect failures**

Run: `php artisan test --compact tests/Feature/WorkItemChildTest.php`
Expected: the six new tests fail.

- [ ] **Step 3: `store()`**

Replace the validation and creation in `store()`:

```php
        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            // A child copies the parent's type; a parent must name its own.
            'type' => ['required_without:parent_id', 'in:assignment,task,adhoc'],
            'priority' => ['nullable', 'in:high,medium,low'],
            'status' => ['nullable', 'in:'.implode(',', self::STATUSES)],
            'due_label' => ['nullable', 'string', 'max:60'],
            'project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')->where('tenant_id', app(CurrentTenant::class)->id())],
            'timesheet_category_id' => ['nullable', 'integer', Rule::exists('timesheet_categories', 'id')->where('tenant_id', app(CurrentTenant::class)->id())],
            // Looked up through the default (ParentOnly) scope on purpose: a child's id
            // is not found, which is what refuses a grandchild.
            'parent_id' => ['nullable', 'integer', Rule::exists('work_items', 'id')->where('tenant_id', app(CurrentTenant::class)->id())->whereNull('parent_id')],
        ], [
            'parent_id.exists' => 'That card cannot take subtasks: it does not exist, or it is a subtask itself.',
        ]);

        if (! empty($data['parent_id'])) {
            $item = $this->storeChild($request, $employee, WorkItem::findOrFail($data['parent_id']), $data);

            return response()->json([
                'card' => $this->cardPayload($item),
                'html' => $this->cardHtml($item),
                'parent_html' => $this->cardHtml($item->parent->fresh()),
            ], 201);
        }

        $status = $data['status'] ?? 'todo';

        $item = $employee->workItems()->create([
            'title' => $data['title'],
            'type' => $data['type'],
            'priority' => $data['priority'] ?? 'medium',
            // … the rest of the existing create array unchanged …
```

Add after `store()`:

```php
    /**
     * A subtask lands on the PARENT's board, whoever adds it: it belongs to the parent,
     * and the parent belongs to its owner. Anyone who can open the parent may add one
     * (authorizeAccess, the same grant as moving or commenting). Type, project and
     * category are copied so the drawer has something to show and never asks again;
     * the child's only state is todo or done.
     *
     * @param  array<string, mixed>  $data
     */
    private function storeChild(Request $request, Employee $actor, WorkItem $parent, array $data): WorkItem
    {
        $this->boardRules->authorizeAccess($request, $parent, $actor);

        return $parent->children()->create([
            'tenant_id' => $parent->tenant_id,
            'employee_id' => $parent->employee_id,
            'title' => $data['title'],
            'type' => $parent->type,
            'priority' => $data['priority'] ?? 'medium',
            'due_label' => $data['due_label'] ?? null,
            'project_id' => $parent->project_id,
            'timesheet_category_id' => $parent->timesheet_category_id,
            'status' => 'todo',
            'progress' => 0,
            'sort_order' => (int) $parent->children()->max('sort_order') + 1,
        ]);
    }
```

Note `type` was previously `required`; the non-child branch still receives it because `required_without:parent_id` enforces it there. `priority` was `required`; the browser and MCP always send it, and `?? 'medium'` covers the child path.

- [ ] **Step 4: `show()` and payloads**

In `show()`, change the load to `$workItem->load(['comments.employee', 'assignedBy', 'participants', 'projectRef', 'employee', 'children.participants']);` and add to the `'card' => $this->cardPayload($workItem) + [ … ]` array:

```php
                'family' => $this->familyPayload($workItem->parent_id ? $workItem->parent : $workItem),
```

In `cardPayload()` add after `'status'`:

```php
            'parent_id' => $item->parent_id,
            'child_summary' => $item->parent_id ? null : $item->childSummary(),
```

Add the two private methods near `cardPayload()`:

```php
    /**
     * The overview beside the drawer: the top-level card and its subtasks, the same
     * shape whether the drawer is showing the parent or one of its children.
     *
     * @return array{parent: array<string, mixed>, children: array<int, array<string, mixed>>}
     */
    private function familyPayload(WorkItem $top): array
    {
        $top->loadMissing(['children.participants', 'projectRef']);

        return [
            'parent' => [
                'id' => $top->id,
                'title' => $top->title,
                'type' => $top->type,
                'due_label' => $top->dueText(),
                'project' => $top->projectRef?->name,
                'child_summary' => $top->childSummary(),
            ],
            'children' => $top->children->map(fn (WorkItem $c) => $this->childPayload($c))->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function childPayload(WorkItem $child): array
    {
        return [
            'id' => $child->id,
            'title' => $child->title,
            'status' => $child->status,
            'due_label' => $child->dueText(),
            'people' => $child->participants->map(fn (Employee $e) => [
                'name' => $e->display_name, 'initials' => $e->initials, 'color' => $e->avatar_color,
            ])->values()->all(),
        ];
    }
```

In `cardHtml()` change the load to `$item->loadMissing(['participants', 'projectRef', 'assignedBy', 'children'])->loadCount('comments');`.

- [ ] **Step 5: Board query**

In `app/Http/Controllers/Concerns/BuildsWorkData.php` line 178, change `->with(['assignedBy', 'participants', 'projectRef'])` to `->with(['assignedBy', 'participants', 'projectRef', 'children'])`. Do the same for the team board's item query in the same file (search for the second `with(['assignedBy'` in that file).

- [ ] **Step 6: Run tests**

Run: `php artisan test --compact tests/Feature/WorkItemChildTest.php tests/Feature/BoardCardTest.php tests/Feature/WorkItemCardHtmlTest.php tests/Feature/TeamBoardDataTest.php`
Expected: all pass except the `wc--stack` assertion (Task 6 adds the class). Mark that single test with `$this->markTestIncomplete('wc--stack lands in Task 6')` only if it blocks the commit, and remove the mark in Task 6.

- [ ] **Step 7: Pint, phpstan, commit**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse --memory-limit=1G app/Http/Controllers/WorkItemController.php app/Http/Controllers/Concerns/BuildsWorkData.php
git add app/Http/Controllers tests/Feature/WorkItemChildTest.php
git commit -m "feat(board): create a child card under a parent and return the family with the card"
```

---

### Task 5: Controller, move / update / archive / restore

**Files:**
- Modify: `app/Http/Controllers/WorkItemController.php` (`move()`, `update()`, `archive()`, `restore()`)
- Test: `tests/Feature/WorkItemChildTest.php`

**Interfaces:**
- `POST /app/board/{id}/move`: on a parent, the Done gate (422 keyed `status`). On a child, `status` must be `todo|done`, `ids` ignored, response `{ok, status, html, parent_html}`.
- `PATCH /app/board/{id}`: `parent_id` prohibited.
- `POST /app/board/{id}/archive`: child → 422; parent cascades. `restore` cascades.

- [ ] **Step 1: Failing tests**

Append to `WorkItemChildTest`:

```php
    public function test_moving_a_parent_to_done_is_refused_while_a_child_is_open(): void
    {
        $parent = $this->parent();
        $this->child($parent);
        $this->child($parent);

        $this->as($this->owner)->postJson("/app/board/{$parent->id}/move", ['status' => 'done'])
            ->assertStatus(422)
            ->assertJsonPath('errors.status.0', '2 subtasks still open. Tick them off before moving this card to Done.');
        $this->assertSame('todo', $parent->fresh()->status);

        $this->as($this->owner)->postJson("/app/board/{$parent->id}/move", ['status' => 'review'])->assertOk();
    }

    public function test_moving_a_parent_to_done_succeeds_once_children_are_done(): void
    {
        $parent = $this->parent();
        $this->child($parent, ['status' => 'done']);

        $this->as($this->owner)->postJson("/app/board/{$parent->id}/move", ['status' => 'done'])->assertOk();
        $this->assertNotNull($parent->fresh()->done_at);
    }

    public function test_a_participant_ticks_a_child_done_and_gets_the_parent_face_back(): void
    {
        $parent = $this->parent(['due_at' => now()->addWeek()]);
        $parent->participants()->attach($this->participantEmp->id);
        $child = $this->child($parent);

        $res = $this->as($this->participant)->postJson("/app/board/{$child->id}/move", ['status' => 'done']);

        $res->assertOk()->assertJsonPath('status', 'done');
        $this->assertStringContainsString('1/1', $res->json('parent_html'));
        $this->assertNotNull(WorkItem::withoutGlobalScope(ParentOnly::class)->find($child->id)->done_at);
    }

    public function test_a_child_cannot_be_moved_to_a_column(): void
    {
        $child = $this->child($this->parent());

        $this->as($this->owner)->postJson("/app/board/{$child->id}/move", ['status' => 'prog'])->assertStatus(422);
    }

    public function test_a_stranger_cannot_tick_a_child(): void
    {
        $child = $this->child($this->parent());

        $this->as($this->stranger)->postJson("/app/board/{$child->id}/move", ['status' => 'done'])->assertForbidden();
    }

    public function test_a_participant_cannot_rename_a_child_but_the_owner_can(): void
    {
        $parent = $this->parent(['due_at' => now()->addWeek()]);
        $parent->participants()->attach($this->participantEmp->id);
        $child = $this->child($parent);

        $this->as($this->participant)->patchJson("/app/board/{$child->id}", ['title' => 'Renamed'])->assertForbidden();
        $this->as($this->owner)->patchJson("/app/board/{$child->id}", ['title' => 'Renamed'])->assertOk();
        $this->as($this->owner)->patchJson("/app/board/{$child->id}", ['parent_id' => null])->assertStatus(422);
    }

    public function test_archiving_the_parent_archives_children_and_a_child_cannot_be_archived_alone(): void
    {
        $parent = $this->parent(['status' => 'done', 'done_at' => now()]);
        $child = $this->child($parent, ['status' => 'done']);

        $this->as($this->owner)->postJson("/app/board/{$child->id}/archive")->assertStatus(422);
        $this->as($this->owner)->postJson("/app/board/{$parent->id}/archive")->assertOk();
        $this->assertNotNull(WorkItem::withoutGlobalScope(ParentOnly::class)->find($child->id)->archived_at);

        $this->as($this->owner)->postJson("/app/board/{$parent->id}/restore")->assertOk();
        $this->assertNull(WorkItem::withoutGlobalScope(ParentOnly::class)->find($child->id)->archived_at);
    }
```

- [ ] **Step 2: Run, expect failures**

Run: `php artisan test --compact tests/Feature/WorkItemChildTest.php`

- [ ] **Step 3: `move()`**

After the `$data = $request->validate([...])` in `move()`:

```php
        if ($workItem->isChild()) {
            // A subtask is open or done, nothing in between, and it has no column to
            // be ordered in.
            abort_unless(in_array($data['status'], ['todo', 'done'], true), 422, 'A subtask is either open or done.');
            unset($data['ids']);
        }

        $this->boardRules->assertChildrenDoneForStatus($workItem, $data['status']);
```

At the JSON return of `move()`:

```php
        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'status' => $workItem->status,
                'html' => $this->cardHtml($workItem),
                // A ticked subtask changes the parent's face (the 1/3 badge), so hand it back.
                'parent_html' => $workItem->parent_id ? $this->cardHtml($workItem->parent->fresh()) : null,
            ]);
        }
```

- [ ] **Step 4: `update()`**

Add to the validation rules: `'parent_id' => ['prohibited'],` with a one-line comment: `// A card is born a child or a parent; it does not change sides.`

- [ ] **Step 5: `archive()` / `restore()`**

`archive()`: after `authorizeManage`, add `abort_if($workItem->isChild(), 422, 'A subtask is archived with its parent.');` and replace the update with:

```php
        DB::transaction(function () use ($workItem) {
            $workItem->update(['archived_at' => now()]);
            $workItem->children()->update(['archived_at' => now()]);
        });
```

`restore()`: wrap the existing update in the same transaction and add `$workItem->children()->update(['archived_at' => null]);` after it. Add `use Illuminate\Support\Facades\DB;`.

- [ ] **Step 6: Run tests**

Run: `php artisan test --compact tests/Feature/WorkItemChildTest.php tests/Feature/WorkItemArchiveTest.php tests/Feature/WorkItemDueDateTest.php`
Expected: all pass.

- [ ] **Step 7: Pint, phpstan, commit**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse --memory-limit=1G app/Http/Controllers/WorkItemController.php
git add app/Http/Controllers/WorkItemController.php tests/Feature/WorkItemChildTest.php
git commit -m "feat(board): tick children through move, gate the parent's Done, archive the family together"
```

---

### Task 6: Card face and CSS

**Files:**
- Modify: `resources/views/partials/work-card.blade.php`
- Modify: `resources/css/app.css` (next to `.wc-cmt`, line ~1053)
- Test: `tests/Feature/WorkItemCardHtmlTest.php`

- [ ] **Step 1: Failing test**

Append to `WorkItemCardHtmlTest` (its `card()` helper creates a parent on `$this->employee`):

```php
    public function test_a_parent_with_children_renders_as_a_stack_with_a_counter(): void
    {
        $parent = $this->card();
        $mk = fn (string $status) => \App\Models\WorkItem::withoutGlobalScope(\App\Models\Scopes\ParentOnly::class)->create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id, 'parent_id' => $parent->id,
            'title' => 'c', 'type' => 'task', 'priority' => 'low', 'status' => $status, 'progress' => 0,
        ]);
        $mk('done');
        $mk('todo');

        $html = view('partials.work-card', ['c' => $parent->fresh()->load(['participants', 'projectRef', 'assignedBy', 'children'])])->render();

        $this->assertStringContainsString('wc--stack', $html);
        $this->assertStringContainsString('1/2', $html);
        $this->assertStringNotContainsString('wc-sub--all', $html);

        $parent->children()->update(['status' => 'done']);
        $html = view('partials.work-card', ['c' => $parent->fresh()->load(['participants', 'projectRef', 'assignedBy', 'children'])])->render();
        $this->assertStringContainsString('wc-sub--all', $html);
    }

    public function test_a_card_without_children_has_no_stack_or_counter(): void
    {
        $html = view('partials.work-card', ['c' => $this->card()->load(['participants', 'projectRef', 'assignedBy', 'children'])])->render();

        $this->assertStringNotContainsString('wc--stack', $html);
        $this->assertStringNotContainsString('wc-sub', $html);
    }
```

- [ ] **Step 2: Run, expect failure**

Run: `php artisan test --compact tests/Feature/WorkItemCardHtmlTest.php`

- [ ] **Step 3: Card partial**

In `resources/views/partials/work-card.blade.php`, in the `@php` block add `$wcChildren = $c->childSummary();`. On the root `<div class="wc …">` add `@if ($wcChildren) wc--stack @endif` inside the class attribute. In `.wc-right`, before the comment count:

```blade
            @if ($wcChildren)
                <span class="wc-sub @if ($wcChildren['done'] === $wcChildren['total']) wc-sub--all @endif" title="Subtasks">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 11 12 14 22 4"></polyline><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path></svg>{{ $wcChildren['done'] }}/{{ $wcChildren['total'] }}
                </span>
            @endif
```

Update the partial's header comment: the caller must also load `children`.

- [ ] **Step 4: CSS**

In `resources/css/app.css` after `.wc-cmt svg { … }`:

```css
.wc-sub { display: inline-flex; align-items: center; gap: 3px; font-variant-numeric: tabular-nums; }
.wc-sub svg { width: 12px; height: 12px; }
.wc-sub--all { color: var(--success-ink); }
/* A parent with subtasks reads as a stack: two hairline layers peek out below it.
   Pure CSS so the card's markup and drag behaviour are untouched. */
.wc--stack { position: relative; margin-bottom: 14px; }
.wc--stack::before, .wc--stack::after {
    content: ""; position: absolute; left: 6px; right: 6px; bottom: -4px; height: 8px; z-index: -1;
    background: var(--card); border: 1px solid var(--hairline); border-top: 0; border-radius: 0 0 9px 9px;
}
.wc--stack::after { left: 12px; right: 12px; bottom: -8px; }
```

Check the column list container (`[data-list]`) has `position: relative; z-index: 0;` so the `z-index: -1` layers stay above the column background. Add it to the existing list rule if missing.

- [ ] **Step 5: Run tests, remove any Task 4 incomplete mark**

Run: `php artisan test --compact tests/Feature/WorkItemCardHtmlTest.php tests/Feature/WorkItemChildTest.php tests/Feature/BoardCardTest.php`
Expected: all pass.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/views/partials/work-card.blade.php resources/css/app.css tests/Feature/WorkItemCardHtmlTest.php tests/Feature/WorkItemChildTest.php
git commit -m "feat(board): a parent with subtasks shows as a stack with a done counter"
```

---

### Task 7: Overview partial and drawer child mode (Blade + CSS)

**Files:**
- Create: `resources/views/partials/work-overview.blade.php`
- Modify: `resources/views/partials/work-drawer.blade.php`
- Modify: `resources/css/app.css`

**Interfaces:**
- Consumes Alpine state (defined in Task 8): `drawer.family` (`{parent, children}` or `null`), `drawer.canAddChild` (bool), `drawer.newChildTitle` (string), `drawer.addingChild` (bool), methods `openChild(id)`, `backToParent()`, `addChild()`, `tickChild(child)`.

- [ ] **Step 1: Overview partial**

Create `resources/views/partials/work-overview.blade.php`:

```blade
{{--
    The overview beside the card drawer: the top-level card and its subtasks. Lives
    INSIDE .wd-scrim so a click on the dimmed area still closes the drawer, while the
    panel itself stops that click. Shown only when there is a family to show: a parent
    with at least one child, or a child (whose parent is then the top card).

    Read-only on the team board ($interactive = false): no add row, no ticks.
--}}
<div class="wd-ov" x-show="drawer.family && (drawer.family.children.length || drawer.card.parent_id)" x-cloak @click.stop>
    <p class="wd-ov-h" x-text="$store.ui.lang==='en' ? 'Parent' : 'Induk'"></p>
    <button type="button" class="wd-ov-parent" :class="{ 'is-active': drawer.family && String(drawer.card.id) === String(drawer.family.parent.id) }" @click="backToParent()">
        <span class="wd-ov-type" x-text="drawer.family?.parent.type"></span>
        <span class="wd-ov-title" x-text="drawer.family?.parent.title"></span>
        <span class="wd-ov-meta">
            <span x-text="drawer.family?.parent.due_label || ($store.ui.lang==='en' ? 'No due date' : 'Tiada tarikh akhir')"></span>
            <span x-show="drawer.family?.parent.child_summary" x-text="drawer.family?.parent.child_summary ? drawer.family.parent.child_summary.done + '/' + drawer.family.parent.child_summary.total : ''"></span>
        </span>
    </button>

    <p class="wd-ov-h">
        <span x-text="$store.ui.lang==='en' ? 'Subtasks' : 'Subtugas'"></span>
        <span x-show="drawer.family?.parent.child_summary" x-text="' · ' + (drawer.family?.parent.child_summary?.done ?? 0) + ' ' + ($store.ui.lang==='en' ? 'of' : 'daripada') + ' ' + (drawer.family?.parent.child_summary?.total ?? 0) + ' ' + ($store.ui.lang==='en' ? 'done' : 'siap')"></span>
    </p>
    <template x-for="child in (drawer.family ? drawer.family.children : [])" :key="child.id">
        <div class="wd-ov-child" :class="{ 'is-active': String(drawer.card.id) === String(child.id), 'is-done': child.status === 'done' }">
            @if ($interactive)
                <input type="checkbox" :checked="child.status === 'done'" @change="tickChild(child)"
                       :aria-label="$store.ui.lang==='en' ? 'Mark done' : 'Tanda siap'">
            @else
                <input type="checkbox" :checked="child.status === 'done'" disabled>
            @endif
            <button type="button" class="wd-ov-child-title" @click="openChild(child.id)" x-text="child.title"></button>
            <span class="wd-ov-meta">
                <template x-for="p in child.people.slice(0, 2)" :key="p.initials + p.name">
                    <span class="wa" :style="'background:' + p.color" :title="p.name" x-text="p.initials"></span>
                </template>
                <span x-show="!child.people.length" x-text="child.due_label"></span>
            </span>
        </div>
    </template>

    @if ($interactive)
        <div class="wd-ov-add" x-show="drawer.canAddChild">
            <input class="wd-inline" x-model="drawer.newChildTitle" maxlength="160" :disabled="drawer.addingChild"
                   :placeholder="$store.ui.lang==='en' ? '+ Add a subtask' : '+ Tambah subtugas'"
                   @keydown.enter.prevent="addChild()">
        </div>
    @endif
</div>
```

- [ ] **Step 2: Include the overview inside the scrim**

In `resources/views/partials/work-drawer.blade.php`, change the scrim line to:

```blade
    <div class="wd-scrim @unless ($interactive) wd--over-modal @endunless" x-show="drawer.show" x-cloak :data-open="drawer.open ? '' : null" @click="closeDrawer()">
        @include('partials.work-overview', ['interactive' => $interactive])
    </div>
```

- [ ] **Step 3: Drawer child mode**

In the same file:

(a) Status segment, interactive branch. Replace the `<div class="wd-seg" …>` block with two:

```blade
                <div class="wd-seg" role="group" aria-label="Status" x-show="!drawer.card.parent_id">
                    @foreach ($statusLabels as $sv => $sl)
                        <button type="button" :aria-pressed="drawer.card.status === '{{ $sv }}' ? 'true' : 'false'"
                                :disabled="drawer.locked" @click="setStatus('{{ $sv }}')">{{ $sl }}</button>
                    @endforeach
                </div>
                {{-- A subtask is open or done. Ticking is access-wide (a participant may), so it
                     is not disabled by drawer.locked: tickChild() goes through move, not update. --}}
                <div class="wd-seg" role="group" aria-label="Status" x-show="drawer.card.parent_id">
                    <button type="button" :aria-pressed="drawer.card.status !== 'done' ? 'true' : 'false'" @click="drawer.card.status === 'done' && tickChild(drawer.card)">
                        <span x-text="$store.ui.lang==='en' ? 'Open' : 'Buka'"></span>
                    </button>
                    <button type="button" :aria-pressed="drawer.card.status === 'done' ? 'true' : 'false'" @click="drawer.card.status !== 'done' && tickChild(drawer.card)">
                        <span x-text="$store.ui.lang==='en' ? 'Done' : 'Siap'"></span>
                    </button>
                </div>
```

(b) Non-interactive branch status label: replace the `x-text` with `x-text="drawer.card.parent_id ? (drawer.card.status === 'done' ? 'Done' : 'Open') : ((@js($statusLabels))[drawer.card.status] || '')"`.

(c) Archive menu item: add `&& !drawer.card.parent_id` to its `x-show`.

(d) Back link: directly above the `<h2 class="wd-title" …>` (both branches), add:

```blade
                    <button type="button" class="wd-back" x-show="drawer.card.parent_id" x-cloak @click="backToParent()">
                        <span aria-hidden="true">&larr;</span> <span x-text="drawer.family?.parent.title"></span>
                    </button>
```

(e) Hide the Type row and the Category · Project row for a child: wrap each property row's outer element with `x-show="!drawer.card.parent_id"` (find the `wd-plabel` for Type and for Category / Project; apply to the label and the value span of each).

(f) Subtasks add row in the drawer, for a parent with no children yet. Insert after the Description textarea, before the Links heading:

```blade
                    @if ($interactive)
                        <template x-if="!drawer.card.parent_id && drawer.canAddChild && drawer.family && !drawer.family.children.length">
                            <div>
                                <h3 class="wd-sech" x-text="$store.ui.lang==='en' ? 'Subtasks' : 'Subtugas'">Subtasks</h3>
                                <input class="wd-inline" style="margin:0 0 12px;" x-model="drawer.newChildTitle" maxlength="160" :disabled="drawer.addingChild"
                                       :placeholder="$store.ui.lang==='en' ? '+ Add a subtask' : '+ Tambah subtugas'"
                                       @keydown.enter.prevent="addChild()">
                            </div>
                        </template>
                    @endif
```

(g) Subline for a child: in Task 8 `subline()` prefixes "Subtask · ". No Blade change.

- [ ] **Step 4: CSS**

Append to `resources/css/app.css` after the `.wd[data-open]` rule:

```css
/* Overview beside the drawer: the parent card and its subtasks, inside the scrim. */
.wd-ov {
    position: fixed; top: 64px; left: 50%; transform: translateX(calc(-50% - 280px));
    width: 300px; max-width: calc(100vw - 600px); z-index: 61; cursor: default;
}
.wd-ov-h { font-size: 11px; letter-spacing: .06em; text-transform: uppercase; color: #fff; opacity: .85; margin: 12px 0 6px; }
.wd-ov-parent, .wd-ov-child {
    display: block; width: 100%; text-align: left; background: var(--card); border: 1px solid var(--hairline);
    border-radius: 10px; padding: 8px 10px; margin-bottom: 6px; font: inherit; color: var(--ink); cursor: pointer;
}
.wd-ov-parent.is-active, .wd-ov-child.is-active { outline: 2px solid var(--red); outline-offset: -1px; }
.wd-ov-type { display: block; font-size: 10px; color: var(--muted); }
.wd-ov-title { display: block; font-size: 13px; font-weight: 600; margin: 2px 0 4px; }
.wd-ov-meta { display: inline-flex; gap: 6px; align-items: center; font-size: 11px; color: var(--muted); }
.wd-ov-child { display: grid; grid-template-columns: auto 1fr auto; gap: 8px; align-items: center; cursor: default; }
.wd-ov-child input[type="checkbox"] { margin: 0; accent-color: var(--success); }
.wd-ov-child-title { background: none; border: 0; padding: 0; font: inherit; font-size: 13px; color: var(--ink); text-align: left; cursor: pointer; }
.wd-ov-child.is-done .wd-ov-child-title { text-decoration: line-through; color: var(--muted); }
.wd-ov-add .wd-inline { width: 100%; margin: 0; background: rgba(255,255,255,.85); border-style: dashed; }
.wd-back { background: none; border: 0; padding: 0 0 6px; font: inherit; font-size: 12px; color: var(--muted); cursor: pointer; }
.wd-back:hover { color: var(--ink); }
@media (max-width: 1100px) { .wd-ov { display: none; } }
```

The 1100px cut-off: below it the drawer (560px) plus the panel do not both fit; the drawer's own Subtasks row (Task 7 step 3f, extended in Task 8 to always show for a parent when the overview is hidden) is the fallback. Keep it simple: at that width the overview is gone and the drawer still lets you add and open children via the back link.

- [ ] **Step 5: Blade compiles**

Run: `php artisan view:clear && php artisan view:cache`
Expected: no error.

- [ ] **Step 6: Commit**

```bash
git add resources/views/partials/work-overview.blade.php resources/views/partials/work-drawer.blade.php resources/css/app.css
git commit -m "feat(board): overview panel beside the drawer and the drawer's subtask mode"
```

---

### Task 8: Alpine, personal board and team board

**Files:**
- Modify: `resources/js/work-board.js`
- Modify: `resources/js/team-board.js`

**Interfaces:**
- Consumes: `GET /app/board/{id}` → `card.family`, `card.parent_id`; `POST /app/board` → `{card, html, parent_html}`; `POST /app/board/{id}/move` → `{status, html, parent_html}`.
- Produces: `drawer.family`, `drawer.canAddChild`, `drawer.newChildTitle`, `drawer.addingChild`; `openChild(id)`, `backToParent()`, `addChild()`, `tickChild(child)`, `repaintCardById(id, html)`.

- [ ] **Step 1: State**

In `work-board.js` `drawer` object (near line 86, next to `locked: false`) add:

```js
            // The overview beside the drawer: { parent, children } from the server, for a
            // parent and for a child alike (null until loaded). See partials.work-overview.
            family: null,
            // Anyone who can open a parent may add a subtask to it (server: authorizeAccess),
            // so this is true whenever a parent is shown, locked or not. False for a child.
            canAddChild: false,
            newChildTitle: '',
            addingChild: false,
```

Same four keys in `team-board.js`'s drawer, with `canAddChild: false` and a comment: `// never true here: the team board is view + comment only.`

- [ ] **Step 2: Open**

In `work-board.js` `openCardCore()` after `this.drawer.card = {...}`:

```js
                this.drawer.family = card.family ?? null;
                this.drawer.canAddChild = !card.parent_id;
                this.drawer.newChildTitle = '';
```

Same three lines in `team-board.js` `openCard` path, with `this.drawer.canAddChild = false;`.

In both files' `subline(card)`, before the `verb` line:

```js
            if (card.parent_id) return `${this.t('Subtask', 'Subtugas')} · ${this.t('added', 'ditambah')} ${card.opened_at} ${this.t('by', 'oleh')} ${card.owner_name || ''} · #${card.id}`;
```

- [ ] **Step 3: Navigation and writes (work-board.js)**

Add after `closeDrawer()`:

```js
        // Repaint any card on the board from server HTML, by id. repaintNode() only
        // knows the drawer's own node; a subtask write changes the PARENT's face.
        repaintCardById(id, html) {
            if (!html) return;
            const node = this.$root.querySelector(`[data-card][data-id="${id}"]`);
            if (node) node.outerHTML = html;
            if (this.drawer.show && String(this.drawer.id) === String(id)) {
                this.drawer.node = this.$root.querySelector(`[data-card][data-id="${id}"]`);
            }
        },

        // Switch the drawer to a subtask. A child has no node on the board, so the
        // drawer's node stays the parent's: that is what the repaint-on-save targets.
        async openChild(id) {
            const parentNode = this.$root.querySelector(`[data-card][data-id="${this.drawer.family?.parent.id}"]`);
            await this.openCardCore(String(id), parentNode);
        },

        async backToParent() {
            const pid = this.drawer.family?.parent.id;
            if (!pid || String(pid) === String(this.drawer.id)) return;
            await this.openCardCore(String(pid), this.$root.querySelector(`[data-card][data-id="${pid}"]`));
        },

        async addChild() {
            const title = (this.drawer.newChildTitle || '').trim();
            const parentId = this.drawer.family?.parent.id ?? this.drawer.id;
            if (!title || this.drawer.addingChild || !this.drawer.canAddChild) return;
            this.drawer.addingChild = true;
            try {
                const { card, parent_html } = await this.api('/app/board', {
                    method: 'POST',
                    body: JSON.stringify({ title, parent_id: parentId }),
                });
                this.drawer.newChildTitle = '';
                const { children, parent } = this.drawer.family;
                children.push({ id: card.id, title: card.title, status: card.status, due_label: card.due_label, people: [] });
                parent.child_summary = { done: children.filter((c) => c.status === 'done').length, total: children.length };
                this.repaintCardById(parentId, parent_html);
            } catch (err) {
                this.drawer.error = err.validation ? err.message : this.t('Could not add that subtask.', 'Tidak dapat tambah subtugas itu.');
            } finally {
                this.drawer.addingChild = false;
            }
        },

        // Tick / untick a subtask: a move between todo and done. Access-wide on the
        // server, so it runs even while drawer.locked (a participant may tick).
        async tickChild(child) {
            const next = child.status === 'done' ? 'todo' : 'done';
            const was = child.status;
            child.status = next; // optimistic; rolled back below
            try {
                const { status, parent_html } = await this.api(`/app/board/${child.id}/move`, {
                    method: 'POST',
                    body: JSON.stringify({ status: next }),
                });
                child.status = status;
                const row = this.drawer.family?.children.find((c) => String(c.id) === String(child.id));
                if (row) row.status = status;
                if (String(this.drawer.card.id) === String(child.id)) this.drawer.card.status = status;
                const { children, parent } = this.drawer.family;
                parent.child_summary = { done: children.filter((c) => c.status === 'done').length, total: children.length };
                this.repaintCardById(parent.id, parent_html);
                this.flashSaved();
            } catch (err) {
                child.status = was;
                const row = this.drawer.family?.children.find((c) => String(c.id) === String(child.id));
                if (row) row.status = was;
                this.$store.toast.error(this.t('Could not update that subtask.', 'Tidak dapat kemas kini subtugas itu.'));
            }
        },
```

- [ ] **Step 4: Done gate feedback**

In `setStatus()`'s catch: `this.drawer.error = err.validation ? err.message : this.t('Could not move this card.', 'Tidak dapat gerakkan kad ini.');`

In `persistMove()`'s catch, the toast: `this.$store.toast.error(err.validation ? err.message : this.t('Could not move this card. It has been put back.', 'Tidak dapat gerakkan kad ini. Ia telah dikembalikan.'));`

- [ ] **Step 5: Team board**

In `team-board.js` add read-only twins so the shared partial never calls something undefined:

```js
        // The overview is navigation-only here: the team board is view + comment only.
        async openChild(id) { await this.openCardCore(String(id), this.drawer.node); },
        async backToParent() {
            const pid = this.drawer.family?.parent.id;
            if (pid && String(pid) !== String(this.drawer.id)) await this.openCardCore(String(pid), this.drawer.node);
        },
        addChild() {},
        tickChild() {},
```

(Use whatever the team board's open method is actually named; line ~425 of the file shows it. Match it.)

- [ ] **Step 6: Build and browser-check**

```bash
php artisan view:clear && php artisan view:cache && bun run build
```

Then in the browser at `http://localhost:9100` as `shazwanshah.unijaya@gmail.com` / `password` (Employee): open a card, add two subtasks from the drawer row, confirm the overview appears with both, tick one, confirm the card face reads `1/2` and shows the stack, try Done and read the refusal, tick the second, move to Done. Open a subtask from the overview, confirm the back link and the Open/Done toggle, and that Type and Category rows are hidden. Log in as `kussairi.unijaya@gmail.com` (Manager), open the team board, open the same card, confirm the overview is read-only. Restore the card you used to To Do afterwards and delete the test subtasks.

- [ ] **Step 7: Commit (without public/build; that comes in Task 12)**

```bash
git add resources/js/work-board.js resources/js/team-board.js
git commit -m "feat(board): drawer switches between parent and subtasks, ticks and adds from the overview"
```

---

### Task 9: MCP tools

**Files:**
- Modify: `app/Mcp/Tools/CreateCardTool.php`, `MoveCardTool.php`, `UpdateCardTool.php`, `ArchiveCardTool.php`, `RestoreCardTool.php`
- Test: `tests/Feature/Mcp/AmanahkuWriteToolsTest.php`

- [ ] **Step 1: Failing tests**

Append to `AmanahkuWriteToolsTest` (helpers `bearer()`, `card()`, `callTool()`, `confirm()`, `toolData()`, `toolIsError()` exist in the file; `card()` makes a `prog` card on `staffEmpA`):

```php
    public function test_create_card_with_a_parent_makes_a_subtask_on_the_parents_board(): void
    {
        $headers = $this->bearer($this->staffA, $this->tenantA, ['board:write']);
        $parent = $this->card();

        $preview = $this->callTool(CreateCardTool::class, [
            'title' => 'Sub', 'type' => 'task', 'priority' => 'low', 'parent_id' => $parent->id,
        ], $headers);
        $this->assertFalse($this->toolIsError($preview));
        $confirmed = $this->confirm($this->toolData($preview)['confirm_token'], $headers);

        app(CurrentTenant::class)->set($this->tenantA);
        $child = \App\Models\WorkItem::withoutGlobalScope(\App\Models\Scopes\ParentOnly::class)->find($this->toolData($confirmed)['card']['id']);
        $this->assertSame($parent->id, $child->parent_id);
        $this->assertSame('todo', $child->status);
        app(CurrentTenant::class)->set(null);
    }

    public function test_move_card_refuses_a_move_to_done_with_an_open_subtask(): void
    {
        $headers = $this->bearer($this->staffA, $this->tenantA, ['board:write']);
        $parent = $this->card();
        app(CurrentTenant::class)->set($this->tenantA);
        $parent->children()->create(['tenant_id' => $parent->tenant_id, 'employee_id' => $parent->employee_id, 'title' => 'Sub', 'type' => 'task', 'priority' => 'low', 'status' => 'todo', 'progress' => 0]);
        app(CurrentTenant::class)->set(null);

        $preview = $this->callTool(MoveCardTool::class, ['work_item_id' => $parent->id, 'status' => 'done'], $headers);

        $this->assertTrue($this->toolIsError($preview));
        $this->assertStringContainsString('subtask', $preview->json('result.content.0.text'));
    }

    public function test_archive_card_refuses_a_subtask(): void
    {
        $headers = $this->bearer($this->staffA, $this->tenantA, ['board:write']);
        $parent = $this->card();
        app(CurrentTenant::class)->set($this->tenantA);
        $child = $parent->children()->create(['tenant_id' => $parent->tenant_id, 'employee_id' => $parent->employee_id, 'title' => 'Sub', 'type' => 'task', 'priority' => 'low', 'status' => 'done', 'progress' => 0]);
        app(CurrentTenant::class)->set(null);

        $preview = $this->callTool(\App\Mcp\Tools\ArchiveCardTool::class, ['work_item_id' => $child->id], $headers);

        $this->assertTrue($this->toolIsError($preview));
    }
```

- [ ] **Step 2: Run, expect failures**

Run: `php artisan test --compact tests/Feature/Mcp/AmanahkuWriteToolsTest.php --filter='subtask|parent'`

- [ ] **Step 3: Lookups reach children**

In `MoveCardTool`, `UpdateCardTool`, `ArchiveCardTool`, `RestoreCardTool`: every `WorkItem::query()->whereKey(...)` becomes `WorkItem::withoutGlobalScope(ParentOnly::class)->whereKey(...)` (both the `handle()` and `applyConfirmed()` lookups). Add `use App\Models\Scopes\ParentOnly;` to each.

- [ ] **Step 4: Move gate and child rules**

In `MoveCardTool::handle()` inside the guarded closure after `authorizeAccess`:

```php
            if ($item->isChild()) {
                abort_unless(in_array($data['status'], ['todo', 'done'], true), 422, 'A subtask is either open (todo) or done.');
            }
            $this->boardRules->assertChildrenDoneForStatus($item, $data['status']);
```

Same three-line block in `applyConfirmed()` after `authorizeAccess` (with `$status` in place of `$data['status']`). Confirm `guarded()` turns a `ValidationException` into a readable `['error' => …]` (it does for the due-date rule already; if the message comes out generic, map `ValidationException` to its first message inside `guarded()` in `PreviewsWrites`).

- [ ] **Step 5: Archive / restore**

`ArchiveCardTool` (both `handle()` and `applyConfirmed()`), after `authorizeManage`: `abort_if($item->isChild(), 422, 'A subtask is archived with its parent.');`. In `applyConfirmed()` replace `$item->update(['archived_at' => now()]);` with the same transaction as the controller (parent + `children()->update`). `RestoreCardTool::applyConfirmed()`: add `$item->children()->update(['archived_at' => null]);` after the existing update.

- [ ] **Step 6: Create with parent**

`CreateCardTool::handle()` validation: `'type'` becomes `['required_without:parent_id', 'in:assignment,task,adhoc']`, add `'parent_id' => ['nullable', 'integer', Rule::exists('work_items', 'id')->where('tenant_id', $tid)->whereNull('parent_id')]`. When `parent_id` is set: load the parent through `WorkItem::query()` (scoped, so it must be a top-level card), `authorizeAccess` on it inside a `guarded()` block, set `$payload['employee_id'] = $parent->employee_id`, `$changes['board'] = "under '".$parent->title."'"`, summary `"Create subtask '…' under '…'."`.

`applyConfirmed()`: when `$payload['parent_id']` is set, re-load the parent (scoped, 404 if gone), `authorizeAccess` again, and create through `$parent->children()->create([...])` with the same field list as `WorkItemController::storeChild()` (type/project/category copied from the parent, `status => 'todo'`, `sort_order` next under the parent). Otherwise the existing path.

Schema: add `'parent_id' => $schema->integer()->description('Make this card a subtask of that card. The subtask lands on the parent\'s board, copies its type, project and category, and is only ever todo or done. A subtask cannot itself be a parent.')`. Type description: note it is ignored when `parent_id` is given.

- [ ] **Step 7: Run tests**

Run: `php artisan test --compact tests/Feature/Mcp/`
Expected: all pass.

- [ ] **Step 8: Pint, phpstan, commit**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse --memory-limit=1G app/Mcp/Tools
git add app/Mcp/Tools tests/Feature/Mcp/AmanahkuWriteToolsTest.php
git commit -m "feat(mcp): board tools create, tick and archive subtasks under the same rules as the browser"
```

---

### Task 10: Timesheet note prefill

**Files:**
- Modify: `app/Timesheet/BoardSuggestions.php` (`forWeek()`, new `childNotes()`)
- Test: `tests/Feature/BoardSuggestionsTest.php`

**Interfaces:**
- Produces: `forWeek()` rows carry `description` = `<ul><li>…</li></ul>` of the viewer's children ticked done that day, else `''`.

- [ ] **Step 1: Failing tests**

Append to `BoardSuggestionsTest` (test week `2026-08-24`, today `2026-08-26 09:00`, helpers `card()`, `stint()`, `idsOn()` exist):

```php
    private function childOf(WorkItem $parent, string $title, string $status, ?string $doneAt = null, array $people = []): WorkItem
    {
        $child = WorkItem::withoutGlobalScope(\App\Models\Scopes\ParentOnly::class)->create([
            'tenant_id' => $parent->tenant_id, 'employee_id' => $parent->employee_id, 'parent_id' => $parent->id,
            'title' => $title, 'type' => $parent->type, 'priority' => 'low', 'status' => $status, 'progress' => 0,
            'done_at' => $doneAt,
        ]);
        $child->participants()->sync($people);

        return $child;
    }

    private function noteOn(array $result, string $day, int $cardId): string
    {
        foreach ($result[$day] ?? [] as $row) {
            if ($row['work_item_id'] === $cardId) {
                return $row['description'];
            }
        }

        return 'ROW MISSING';
    }

    public function test_the_note_lists_my_subtasks_ticked_that_day_and_nothing_else(): void
    {
        $card = $this->card(['timesheet_category_id' => $this->work->id]);
        $this->stint($card, '2026-08-24 09:00:00', null);

        $other = Employee::create(['tenant_id' => $this->tenant->id, 'name' => 'Other', 'status' => 'active', 'workload' => 'green']);

        $this->childOf($card, 'Mine, Tuesday', 'done', '2026-08-25 15:00:00');                       // unassigned: the owner's
        $this->childOf($card, 'Mine too', 'done', '2026-08-25 16:00:00', [$this->employee->id]);
        $this->childOf($card, 'Theirs', 'done', '2026-08-25 16:30:00', [$other->id]);
        $this->childOf($card, 'Monday', 'done', '2026-08-24 10:00:00');
        $this->childOf($card, 'Still open', 'todo');

        $result = $this->suggestions->forWeek($this->employee, self::WEEK);

        $this->assertSame('<ul><li>Mine, Tuesday</li><li>Mine too</li></ul>', $this->noteOn($result, '2026-08-25', $card->id));
        $this->assertSame('<ul><li>Monday</li></ul>', $this->noteOn($result, '2026-08-24', $card->id));
        $this->assertSame('', $this->noteOn($result, '2026-08-26', $card->id));
    }

    public function test_a_participant_sees_only_the_subtasks_they_are_on(): void
    {
        $owner = Employee::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'status' => 'active', 'workload' => 'green']);
        $card = $this->card(['timesheet_category_id' => $this->work->id], $owner);
        $card->participants()->attach($this->employee->id);
        $this->stint($card, '2026-08-24 09:00:00', null);

        $this->childOf($card, 'Unassigned', 'done', '2026-08-25 15:00:00');
        $this->childOf($card, 'On me', 'done', '2026-08-25 16:00:00', [$this->employee->id]);

        $result = $this->suggestions->forWeek($this->employee, self::WEEK);

        $this->assertSame('<ul><li>On me</li></ul>', $this->noteOn($result, '2026-08-25', $card->id));
    }

    public function test_subtask_titles_are_escaped_in_the_note(): void
    {
        $card = $this->card(['timesheet_category_id' => $this->work->id]);
        $this->stint($card, '2026-08-24 09:00:00', null);
        $this->childOf($card, '<b>bold</b> & co', 'done', '2026-08-25 15:00:00');

        $result = $this->suggestions->forWeek($this->employee, self::WEEK);

        $this->assertSame('<ul><li>&lt;b&gt;bold&lt;/b&gt; &amp; co</li></ul>', $this->noteOn($result, '2026-08-25', $card->id));
    }
```

- [ ] **Step 2: Run, expect failures**

Run: `php artisan test --compact tests/Feature/BoardSuggestionsTest.php --filter='subtask'`

- [ ] **Step 3: Implementation**

In `forWeek()`, after `$categories = $this->categoryFor($cards);` add `$notes = $this->childNotes($employee, $cards, $start, $end);` and change the row's `'description' => '',` to `'description' => $notes[$cardId][$iso] ?? '',` (keep the existing comment, reworded: "The note starts as the viewer's subtasks ticked that day, see childNotes(); the card's own description is spec text, not a staffer note.").

Add the method:

```php
    /**
     * What goes in the parent row's note: the viewer's subtasks ticked done on that
     * day, one bullet each. "The viewer's" means they are on the child's people list,
     * or the child has nobody on it and the viewer owns the parent. A prefill only:
     * WeekWriter never reads this, so a saved row keeps whatever note it was saved with.
     *
     * @param  Collection<int, WorkItem>  $cards  keyed by id
     * @return array<int, array<string, string>>  [parent id][ISO day] => sanitised HTML
     */
    private function childNotes(Employee $employee, Collection $cards, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $children = WorkItem::withoutGlobalScope(ParentOnly::class)
            ->whereIn('parent_id', $cards->keys()->all())
            ->where('status', 'done')
            ->whereNotNull('done_at')
            ->whereDate('done_at', '>=', $start->toDateString())
            ->whereDate('done_at', '<=', $end->toDateString())
            ->with('participants:employees.id')
            ->orderBy('done_at')
            ->get(['id', 'parent_id', 'employee_id', 'title', 'done_at']);

        $titles = [];
        foreach ($children as $child) {
            $mine = $child->participants->isEmpty()
                ? $child->employee_id === $employee->id
                : $child->participants->contains('id', $employee->id);
            if (! $mine) {
                continue;
            }
            $titles[(int) $child->parent_id][CarbonImmutable::parse($child->done_at)->toDateString()][] = e($child->title);
        }

        $out = [];
        foreach ($titles as $parentId => $days) {
            foreach ($days as $iso => $list) {
                $out[$parentId][$iso] = '<ul><li>'.implode('</li><li>', $list).'</li></ul>';
            }
        }

        return $out;
    }
```

Add `use App\Models\Scopes\ParentOnly;`. Note `done_at` has a `datetime` cast on the model, so `CarbonImmutable::parse($child->done_at)` works on either representation; do not assert raw column strings (MySQL and sqlite differ).

- [ ] **Step 4: Run tests**

Run: `php artisan test --compact tests/Feature/BoardSuggestionsTest.php tests/Feature/TimesheetWorkItemLinkTest.php`
Expected: all pass.

- [ ] **Step 5: Pint, phpstan, commit**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse --memory-limit=1G app/Timesheet/BoardSuggestions.php
git add app/Timesheet/BoardSuggestions.php tests/Feature/BoardSuggestionsTest.php
git commit -m "feat(timesheet): a parent row's note starts as the subtasks you ticked that day"
```

---

### Task 11: Full suite, phpstan, browser pass

- [ ] **Step 1: Whole suite**

Run: `php artisan test --compact`
Expected: 0 failures (1 pre-existing skip is fine).

- [ ] **Step 2: phpstan on the app**

Run: `vendor/bin/phpstan analyse --memory-limit=1G`
Expected: 0 errors.

- [ ] **Step 3: Browser pass on the timesheet**

At `http://localhost:9100` as the Employee: put a card in In Progress, add a subtask, tick it, open Timesheets for this week and confirm today's suggested row for that card carries the subtask title as a bullet in its note. Save the row with a percentage, untick the subtask, reload: the saved note is unchanged. Put the test data back.

- [ ] **Step 4: Fix anything found, commit as `fix(board): …` with the reason.**

---

### Task 12: Changelog, assets, docs

**Files:**
- Modify: `resources/changelog.yaml` (the `1.8` entry written on 2026-09-03)
- Modify: `tests/Feature/ChangelogScreenTest.php`
- Modify: `resources/views/partials/work-drawer.blade.php` header comment, `docs/` where the board is described (grep `docs/ -l "work_items\|board"` and add one paragraph on child cards where the board's data model is explained)
- Build: `public/build`

- [ ] **Step 1: Rewrite the 1.8 entry**

Replace the `1.8` entry's `text` / `text_ms` with:

text: `"A T.A.A. card can now hold subtasks, and it cannot reach Done until every one of them is done.\nOpen a card and add subtasks from the panel beside it. Each subtask is a small card of its own: it can carry a description, a due date, people and comments, and it is either open or done. The parent shows on the board as a stack with a count (\"2/5\"), green once everything is ticked. Anyone on the parent can add a subtask or tick one; only the parent's owner can rename or remove one.\nOn your timesheet, the parent's row now starts with a note listing the subtasks you ticked that day, ready to edit or clear before you save."`

text_ms: `"Kad T.A.A. kini boleh mempunyai subtugas, dan tidak boleh masuk ke Done sehingga semuanya siap.\nBuka kad dan tambah subtugas dari panel di sebelahnya. Setiap subtugas ialah kad kecil tersendiri: boleh ada penerangan, tarikh akhir, orang dan komen, dan ia sama ada terbuka atau siap. Kad induk dipaparkan di papan sebagai timbunan dengan kiraan (\"2/5\"), hijau apabila semuanya ditanda. Sesiapa yang ada pada kad induk boleh menambah atau menanda subtugas; hanya pemilik kad induk boleh menamakan semula atau membuangnya.\nPada lembaran masa anda, baris kad induk kini bermula dengan nota yang menyenaraikan subtugas yang anda tanda pada hari itu, sedia untuk disunting atau dikosongkan sebelum disimpan."`

In `ChangelogScreenTest`, change the assertion `cannot reach Done until every one of them is ticked` to `cannot reach Done until every one of them is done`.

Run: `php artisan test --compact tests/Feature/ChangelogScreenTest.php`

- [ ] **Step 2: Build assets from a clean tree**

```bash
git status --short          # must be empty apart from changelog/test/docs edits you are about to commit
git add resources/changelog.yaml tests/Feature/ChangelogScreenTest.php docs
git commit -m "docs(changelog): 1.8, subtasks on board cards"
php artisan view:clear && php artisan view:cache && bun run build
git add public/build
git commit -m "build: assets for board subtasks"
```

- [ ] **Step 3: Report**

Summarise for Shazwan in plain words: what a subtask is, how to add and tick one, what blocks Done, what the timesheet does, and that the release branch is next (`git push gitlab dev:release/1.8`, MR into staging).
