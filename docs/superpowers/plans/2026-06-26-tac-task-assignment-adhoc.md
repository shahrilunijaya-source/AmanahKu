# Task Assignment Adhoc (tac) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let roles `manager`/`management`/`hr` assign an adhoc task ("tac") onto any staff member's work board, with the assignee locked to move+comment, plus notifications and a per-profile tracking panel.

**Architecture:** A tac is a `WorkItem` with `assigned_by_id` set and `employee_id` = the assignee, so it lands on the existing board and reuses the move/comment pipeline. `WorkItemController`'s blanket owner check is split into `authorizeAccess` (view/comment/move: owner or assigner) and `authorizeManage` (edit/delete: owner of a self-made card, or assigner of a tac). A new `assign()` action creates the tac and notifies the assignee; `move()` notifies the assigner when the tac first reaches Done. The assigner UI lives on the employee profile (form + tracking list); the board card gets an "Assigned by" badge and locks fields for the assignee.

**Tech Stack:** Laravel 13, Blade, Alpine.js, SortableJS, PHPUnit (`php artisan test`), MySQL.

**Spec:** `docs/superpowers/specs/2026-06-26-tac-task-assignment-adhoc-design.md`

---

## File Structure

- **Create** `database/migrations/2026_06_26_000002_add_assignment_to_work_items.php` — adds `assigned_by_id`, `assigned_at`.
- **Modify** `app/Models/WorkItem.php` — add `assignedBy()` relation + `assigned_at` cast.
- **Modify** `app/Http/Controllers/WorkItemController.php` — split authorization, add `assign()`, completion notification in `move()`, extend `cardPayload()`, load `assignedBy` in `show()`.
- **Modify** `routes/web.php` — register `work.assign`.
- **Modify** `app/Http/Controllers/AppController.php` — `profileData()` adds `canAssign` + `assignedTasks`; `boardColumns()` eager-loads `assignedBy`.
- **Modify** `resources/views/screens/profile.blade.php` — "Assign task" form/modal + "Tasks assigned" panel.
- **Modify** `resources/views/screens/board.blade.php` — "Assigned by" badge on server-rendered cards.
- **Modify** `resources/js/work-board.js` — badge in `cardInner()`, lock fields when `card.assigned_by`.
- **Modify** `tests/Feature/BoardCardTest.php` — add tac tests (reuses existing harness).

---

## Task 1: Migration + model relation

**Files:**
- Create: `database/migrations/2026_06_26_000002_add_assignment_to_work_items.php`
- Modify: `app/Models/WorkItem.php`
- Test: `tests/Feature/BoardCardTest.php`

- [ ] **Step 1: Write the migration**

Create `database/migrations/2026_06_26_000002_add_assignment_to_work_items.php`:

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
            // assigned_by_id = the superior who created this tac. Null = a normal
            // self-created board card (unchanged behaviour). This single column is
            // the marker that a work item is an assigned task.
            $table->foreignId('assigned_by_id')->nullable()->after('employee_id')
                ->constrained('employees')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable()->after('assigned_by_id');
        });
    }

    public function down(): void
    {
        Schema::table('work_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assigned_by_id');
            $table->dropColumn('assigned_at');
        });
    }
};
```

- [ ] **Step 2: Add the relation + cast to `WorkItem`**

In `app/Models/WorkItem.php`, update the `casts()` method and add the relation. The final file:

```php
<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkItem extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['due_at' => 'date', 'assigned_at' => 'datetime'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** The superior who assigned this task. Null for self-created cards. */
    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assigned_by_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(WorkItemComment::class)->oldest();
    }
}
```

- [ ] **Step 3: Run the migration against the test DB and the existing board tests**

Run: `php artisan test --filter=BoardCardTest`
Expected: PASS — `RefreshDatabase` applies the new migration; existing tests are unaffected by the nullable columns.

- [ ] **Step 4: Commit**

```bash
git add database/migrations/2026_06_26_000002_add_assignment_to_work_items.php app/Models/WorkItem.php
git commit -m "feat(tac): add assignment columns + assignedBy relation to work items"
```

---

## Task 2: Assign endpoint + authorization split

**Files:**
- Modify: `routes/web.php:129`
- Modify: `app/Http/Controllers/WorkItemController.php`
- Test: `tests/Feature/BoardCardTest.php`

- [ ] **Step 1: Add a tac helper to the test harness**

In `tests/Feature/BoardCardTest.php`, after the existing `card()` method (around line 50), add a helper that makes a manager + an assigned tac. Add this inside the class:

```php
    /** A second user with a privileged role + their employee record. */
    private function manager(string $role = 'manager'): Employee
    {
        $u = User::create(['name' => 'Mgr', 'email' => 'mgr@example.com', 'password' => Hash::make('password')]);
        $u->tenants()->attach($this->tenant->id, ['role' => $role]);

        return Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $u->id,
            'name' => 'Mgr', 'status' => 'active', 'workload' => 'green',
        ]);
    }

    private function actingAsManager(Employee $mgr): self
    {
        $this->actingAs($mgr->user)->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }
```

Note: `Employee` has no `user()` relation yet for `$mgr->user`. Use the user id directly instead — replace `$mgr->user` with `User::find($mgr->user_id)`:

```php
    private function actingAsManager(Employee $mgr): self
    {
        $this->actingAs(User::find($mgr->user_id))->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }
```

- [ ] **Step 2: Write the failing assign-authorization tests**

Add these test methods to `tests/Feature/BoardCardTest.php`:

```php
    public function test_manager_assigns_tac_to_staff_board(): void
    {
        $mgr = $this->manager('manager');

        $this->actingAsManager($mgr)->postJson("/app/board/assign/{$this->employee->id}", [
            'title' => 'Prepare report', 'type' => 'adhoc', 'priority' => 'high',
            'due_at' => '2026-07-01', 'description' => 'By Friday',
        ])->assertCreated()->assertJsonPath('card.title', 'Prepare report');

        $this->assertDatabaseHas('work_items', [
            'employee_id' => $this->employee->id, 'assigned_by_id' => $mgr->id,
            'title' => 'Prepare report', 'status' => 'todo',
        ]);
    }

    public function test_plain_employee_cannot_assign(): void
    {
        // $this->employee's user has role 'employee'.
        $colleague = Employee::create(['tenant_id' => $this->tenant->id, 'name' => 'C', 'status' => 'active', 'workload' => 'green']);

        $this->actingInTenant()->postJson("/app/board/assign/{$colleague->id}", [
            'title' => 'No', 'type' => 'adhoc', 'priority' => 'low',
        ])->assertForbidden();
    }

    public function test_assign_notifies_the_assignee(): void
    {
        $mgr = $this->manager('management');

        $this->actingAsManager($mgr)->postJson("/app/board/assign/{$this->employee->id}", [
            'title' => 'Ping', 'type' => 'task', 'priority' => 'medium',
        ])->assertCreated();

        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $this->user->id, 'title' => 'Mgr assigned you a task', 'body' => 'Ping',
        ]);
    }
```

- [ ] **Step 3: Run them to verify they fail**

Run: `php artisan test --filter=BoardCardTest`
Expected: FAIL — route `work.assign` and method `assign()` do not exist (404 / method-not-found).

- [ ] **Step 4: Register the route**

In `routes/web.php`, immediately after line 129 (`work.comment.destroy`), add:

```php
        Route::post('/app/board/assign/{employee}', [WorkItemController::class, 'assign'])->name('work.assign');
```

- [ ] **Step 5: Add the import + assign method + authorization split to the controller**

In `app/Http/Controllers/WorkItemController.php`:

(a) Add the import near the other model imports at the top:

```php
use App\Models\AppNotification;
```

(b) Add the assigner-roles constant under the existing `STATUS_LABELS` constant:

```php
    /** Roles permitted to assign a tac onto another employee's board. */
    private const ASSIGNER_ROLES = ['manager', 'management', 'hr'];
```

(c) Add the `assign()` method (place it after `store()`):

```php
    /** A privileged user assigns an adhoc task onto a staff member's board. */
    public function assign(Request $request, Employee $employee): RedirectResponse|JsonResponse
    {
        $assigner = $this->employee($request);
        $role = $request->attributes->get('tenantRole', 'employee');
        abort_unless(in_array($role, self::ASSIGNER_ROLES, true), 403, 'Your role cannot assign tasks.');
        abort_unless($employee->tenant_id === app(CurrentTenant::class)->id(), 403);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'type' => ['required', 'in:assignment,task,adhoc'],
            'priority' => ['required', 'in:high,medium,low'],
            'due_at' => ['nullable', 'date'],
            'description' => ['nullable', 'string', 'max:5000'],
        ]);

        $item = $employee->workItems()->create([
            'title' => $data['title'],
            'type' => $data['type'],
            'priority' => $data['priority'],
            'due_at' => $data['due_at'] ?? null,
            'description' => $data['description'] ?? null,
            'status' => 'todo',
            'progress' => 0,
            'assigned_by_id' => $assigner->id,
            'assigned_at' => now(),
            // Bottom of the assignee's To Do column.
            'sort_order' => (int) $employee->workItems()->where('status', 'todo')->max('sort_order') + 1,
        ]);

        AppNotification::send(
            $employee->user_id,
            $assigner->name.' assigned you a task',
            $item->title,
            route('app.screen', 'board'),
        );

        if ($request->expectsJson()) {
            return response()->json(['card' => $this->cardPayload($item)], 201);
        }

        return back()->with('ok', 'Task assigned to '.$employee->name.'.');
    }
```

(d) Replace the single `authorizeItem()` method with the split below. Delete the old `authorizeItem` method and add:

```php
    /** View / comment / move: the owner, or (for a tac) the assigner. */
    private function authorizeAccess(WorkItem $item, Employee $employee): void
    {
        abort_unless($item->tenant_id === app(CurrentTenant::class)->id(), 403);
        abort_unless($item->employee_id === $employee->id || $this->isAssigner($item, $employee), 403);
    }

    /**
     * Edit fields / delete: the owner of a self-made card, or the assigner of a tac.
     * The assignee of a tac is deliberately locked out — their intent stays the
     * assigner's; they can only move it and comment.
     */
    private function authorizeManage(WorkItem $item, Employee $employee): void
    {
        abort_unless($item->tenant_id === app(CurrentTenant::class)->id(), 403);
        $allowed = $item->assigned_by_id === null
            ? $item->employee_id === $employee->id
            : $this->isAssigner($item, $employee);
        abort_unless($allowed, 403);
    }

    private function isAssigner(WorkItem $item, Employee $employee): bool
    {
        return $item->assigned_by_id !== null && $item->assigned_by_id === $employee->id;
    }
```

(e) Update the existing method calls:
- In `show()` (line ~58): change `$this->authorizeItem($workItem, $employee);` → `$this->authorizeAccess($workItem, $employee);` and change the load to `$workItem->load(['comments.employee', 'assignedBy']);`
- In `update()` (line ~72): change to `$this->authorizeManage($workItem, $employee);`
- In `move()` (line ~96): change to `$this->authorizeAccess($workItem, $employee);`
- In `destroy()` (line ~126): change to `$this->authorizeManage($workItem, $employee);`
- In `comment()` (line ~138): change to `$this->authorizeAccess($workItem, $employee);`

(f) Extend `cardPayload()` to expose the tac fields. Replace the return array with:

```php
        return [
            'id' => $item->id,
            'title' => $item->title,
            'type' => $item->type,
            'priority' => $item->priority,
            'status' => $item->status,
            'due_label' => $item->due_label,
            'due_at' => $item->due_at?->format('Y-m-d'),
            'estimate_hours' => $item->estimate_hours,
            'comments_count' => $item->comments_count ?? $item->comments()->count(),
            'assigned_by' => $item->assigned_by_id ? [
                'name' => $item->assignedBy?->name,
                'initials' => $item->assignedBy?->initials,
                'color' => $item->assignedBy?->avatar_color,
            ] : null,
        ];
```

- [ ] **Step 6: Run the assign tests**

Run: `php artisan test --filter=BoardCardTest`
Expected: PASS for the three new assign tests and all existing tests.

- [ ] **Step 7: Write + run the assignee/assigner permission tests**

Add to `tests/Feature/BoardCardTest.php`:

```php
    /** Make a tac owned by $this->employee, assigned by a fresh manager. */
    private function tac(Employee $mgr, array $attrs = []): WorkItem
    {
        return $this->employee->workItems()->create(array_merge([
            'tenant_id' => $this->tenant->id, 'title' => 'T', 'type' => 'adhoc',
            'priority' => 'medium', 'status' => 'todo', 'progress' => 0,
            'assigned_by_id' => $mgr->id, 'assigned_at' => now(),
        ], $attrs));
    }

    public function test_assignee_can_move_and_comment_a_tac(): void
    {
        $mgr = $this->manager('manager');
        $item = $this->tac($mgr);

        $this->actingInTenant()->postJson("/app/board/{$item->id}/move", ['status' => 'prog'])->assertOk();
        $this->actingInTenant()->postJson("/app/board/{$item->id}/comments", ['body' => 'on it'])->assertCreated();
    }

    public function test_assignee_cannot_edit_or_delete_a_tac(): void
    {
        $mgr = $this->manager('manager');
        $item = $this->tac($mgr);

        $this->actingInTenant()->patchJson("/app/board/{$item->id}", [
            'title' => 'Hijack', 'type' => 'adhoc', 'priority' => 'low',
        ])->assertForbidden();
        $this->actingInTenant()->deleteJson("/app/board/{$item->id}")->assertForbidden();
    }

    public function test_assigner_can_edit_and_delete_their_tac(): void
    {
        $mgr = $this->manager('manager');
        $item = $this->tac($mgr);

        $this->actingAsManager($mgr)->patchJson("/app/board/{$item->id}", [
            'title' => 'Updated', 'type' => 'adhoc', 'priority' => 'high',
        ])->assertOk()->assertJsonPath('card.title', 'Updated');

        $this->actingAsManager($mgr)->deleteJson("/app/board/{$item->id}")->assertOk();
        $this->assertDatabaseMissing('work_items', ['id' => $item->id]);
    }
```

Run: `php artisan test --filter=BoardCardTest`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add routes/web.php app/Http/Controllers/WorkItemController.php tests/Feature/BoardCardTest.php
git commit -m "feat(tac): assign endpoint + owner/assigner authorization split"
```

---

## Task 3: Notify the assigner on completion

**Files:**
- Modify: `app/Http/Controllers/WorkItemController.php` (`move()`)
- Test: `tests/Feature/BoardCardTest.php`

- [ ] **Step 1: Write the failing completion-notification test**

Add to `tests/Feature/BoardCardTest.php`:

```php
    public function test_moving_a_tac_to_done_notifies_the_assigner(): void
    {
        $mgr = $this->manager('manager');
        $item = $this->tac($mgr, ['title' => 'Wrap up']);

        $this->actingInTenant()->postJson("/app/board/{$item->id}/move", ['status' => 'done'])->assertOk();

        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $mgr->user_id, 'title' => 'Demo completed: Wrap up',
        ]);
        // Moving it again must not duplicate the notification.
        $this->actingInTenant()->postJson("/app/board/{$item->id}/move", ['status' => 'done'])->assertOk();
        $this->assertSame(1, \App\Models\AppNotification::where('title', 'Demo completed: Wrap up')->count());
    }
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --filter=test_moving_a_tac_to_done_notifies_the_assigner`
Expected: FAIL — no notification row is created.

- [ ] **Step 3: Add the completion notification to `move()`**

In `move()`, capture the prior status before the update and notify after. Replace the body from the validate call through the update with:

```php
        $data = $request->validate([
            'status' => ['required', 'in:'.implode(',', self::STATUSES)],
            'ids' => ['sometimes', 'array'],
            'ids.*' => ['integer'],
        ]);

        $wasDone = $workItem->status === 'done';

        $workItem->update([
            'status' => $data['status'],
            'progress' => $data['status'] === 'done' ? 100 : $workItem->progress,
        ]);

        // Close the loop: when an assigned tac first reaches Done, tell the assigner.
        if (! $wasDone && $workItem->status === 'done' && $workItem->assigned_by_id) {
            $assigner = Employee::find($workItem->assigned_by_id);
            AppNotification::send(
                $assigner?->user_id,
                $employee->name.' completed: '.$workItem->title,
                null,
                route('app.screen', 'board'),
            );
        }
```

(Leave the `ids` re-sequencing block and the response below it unchanged.)

- [ ] **Step 4: Run the test**

Run: `php artisan test --filter=BoardCardTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/WorkItemController.php tests/Feature/BoardCardTest.php
git commit -m "feat(tac): notify assigner when a tac is completed"
```

---

## Task 4: Profile + board data wiring

**Files:**
- Modify: `app/Http/Controllers/AppController.php` (`profileData()`, `boardColumns()`)
- Test: `tests/Feature/BoardCardTest.php`

- [ ] **Step 1: Write the failing data-wiring test**

Add to `tests/Feature/BoardCardTest.php`:

```php
    public function test_profile_screen_lists_tasks_assigned_to_the_employee(): void
    {
        $mgr = $this->manager('manager');
        $this->tac($mgr, ['title' => 'Visible on profile']);

        $this->actingAsManager($mgr)->get("/app/profile?emp={$this->employee->id}")
            ->assertOk()
            ->assertSee('Visible on profile')
            ->assertSee('Assign task');
    }
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --filter=test_profile_screen_lists_tasks_assigned_to_the_employee`
Expected: FAIL — the profile view does not render "Assign task" or the task title yet.

- [ ] **Step 3: Add the import + wire `profileData()`**

In `app/Http/Controllers/AppController.php`, ensure `use App\Models\WorkItem;` is present in the imports (add it if missing). Then replace the `return` of `profileData()` (around line 519) with:

```php
        $role = $request->attributes->get('tenantRole', 'employee');

        $assignedTasks = $e
            ? WorkItem::where('employee_id', $e->id)
                ->whereNotNull('assigned_by_id')
                ->with('assignedBy')
                ->orderBy('due_at')
                ->get()
            : collect();

        return array_merge([
            'profile' => $e,
            'canAssign' => in_array($role, ['manager', 'management', 'hr'], true),
            'assignedTasks' => $assignedTasks,
        ], $this->orgOptions());
```

- [ ] **Step 4: Eager-load `assignedBy` in `boardColumns()`**

In `boardColumns()` (around line 578), change the `$items` query to eager-load the relation so the badge has no N+1:

```php
        $items = $employee?->workItems()->with('assignedBy')->withCount('comments')->orderBy('sort_order')->orderBy('id')->get() ?? collect();
```

(The profile-screen test still fails until Task 5 adds the blade markup — that is expected; this step only provides the data.)

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/AppController.php
git commit -m "feat(tac): supply assigned-tasks list + assigner eager-load to views"
```

---

## Task 5: Profile assign form + tracking panel

**Files:**
- Modify: `resources/views/screens/profile.blade.php`
- Test: `tests/Feature/BoardCardTest.php` (the Task 4 test now passes)

- [ ] **Step 1: Add the assign form + tracking panel to the profile**

In `resources/views/screens/profile.blade.php`, inside the left column `div` (after the closing `</div>` of the "Employment" card, around line 60+ — anywhere inside the `flex:1;min-width:280px` column), insert this block. It renders only for privileged viewers and uses a teleported, centered modal per the project's modal rule:

```blade
@if ($canAssign ?? false)
    <div class="uj-card" style="padding:20px;" x-data="{ assign: false }">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
            <div style="font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:0.6px;">
                <span x-text="$store.ui.lang==='en' ? 'Assigned tasks' : 'Tugas diberi'">Assigned tasks</span>
            </div>
            <button type="button" @click="assign = true" class="uj-btn-ghost" style="height:30px;padding:0 12px;font-size:12px;">
                <span x-text="$store.ui.lang==='en' ? 'Assign task' : 'Beri tugas'">Assign task</span>
            </button>
        </div>

        {{-- Tracking list: tasks already assigned to this person, soonest due first. --}}
        @forelse ($assignedTasks ?? [] as $t)
            @php
                $tcol = ['todo' => 'var(--muted)', 'prog' => 'var(--info)', 'review' => 'var(--amber)', 'done' => 'var(--success)'][$t->status] ?? 'var(--muted)';
                $tlab = ['todo' => 'To Do', 'prog' => 'In Progress', 'review' => 'In Review', 'done' => 'Done'][$t->status] ?? $t->status;
                $overdue = $t->due_at && $t->status !== 'done' && $t->due_at->isPast();
            @endphp
            <div style="display:flex;align-items:flex-start;gap:10px;padding:10px 0;border-top:1px solid var(--hairline-soft);">
                <span style="flex-shrink:0;margin-top:3px;width:8px;height:8px;border-radius:50%;background:{{ $tcol }};"></span>
                <div style="flex:1;min-width:0;">
                    <div style="font-size:13px;color:var(--ink);font-weight:500;line-height:1.35;">{{ $t->title }}</div>
                    <div style="font-size:11.5px;color:var(--muted);margin-top:3px;">
                        {{ $tlab }} · {{ $t->assignedBy?->name ?? '—' }}
                        @if ($t->due_at)<span style="color:{{ $overdue ? 'var(--error)' : 'var(--muted)' }};">· {{ $t->due_at->format('d M') }}{{ $overdue ? ' · overdue' : '' }}</span>@endif
                    </div>
                </div>
            </div>
        @empty
            <p style="font-size:12.5px;color:var(--muted-soft);margin:0;" x-text="$store.ui.lang==='en' ? 'No tasks assigned to this person yet.' : 'Tiada tugas diberi kepada orang ini lagi.'">No tasks assigned to this person yet.</p>
        @endforelse

        {{-- Assign modal — teleported to body + centered. --}}
        <template x-teleport="body">
        <div x-show="assign" x-cloak @click.self="assign = false"
             style="position:fixed;inset:0;z-index:120;display:flex;padding:40px 16px;background:rgba(18,18,30,.42);overflow-y:auto;"
             @keydown.escape.window="assign = false">
            <form method="post" action="{{ route('work.assign', $p) }}" class="uj-card"
                  style="width:100%;max-width:520px;margin:auto;padding:0;overflow:hidden;">
                @csrf
                <div style="display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid var(--hairline);">
                    <span style="font-size:13px;font-weight:600;color:var(--ink);">
                        <span x-text="$store.ui.lang==='en' ? 'Assign a task to' : 'Beri tugas kepada'">Assign a task to</span> {{ $p->name }}
                    </span>
                    <button type="button" @click="assign = false" style="font-size:20px;line-height:1;color:var(--muted);background:transparent;cursor:pointer;">×</button>
                </div>
                <div style="padding:20px;display:flex;flex-direction:column;gap:14px;">
                    @if ($errors->any())<div style="background:var(--red-tint);border:1px solid var(--red);color:var(--red);font-size:12px;border-radius:8px;padding:8px 11px;">{{ $errors->first() }}</div>@endif
                    <div>
                        <label style="display:block;font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.4px;margin-bottom:5px;" x-text="$store.ui.lang==='en' ? 'Title' : 'Tajuk'">Title</label>
                        <input name="title" maxlength="160" required value="{{ old('title') }}" style="{{ $fs }}" />
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                        <div>
                            <label style="display:block;font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.4px;margin-bottom:5px;" x-text="$store.ui.lang==='en' ? 'Type' : 'Jenis'">Type</label>
                            <select name="type" style="{{ $fs }}">@foreach (['adhoc' => 'Adhoc', 'task' => 'Task', 'assignment' => 'Assignment'] as $v => $l)<option value="{{ $v }}" @selected(old('type', 'adhoc') === $v)>{{ $l }}</option>@endforeach</select>
                        </div>
                        <div>
                            <label style="display:block;font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.4px;margin-bottom:5px;" x-text="$store.ui.lang==='en' ? 'Priority' : 'Keutamaan'">Priority</label>
                            <select name="priority" style="{{ $fs }}">@foreach (['high' => 'High', 'medium' => 'Medium', 'low' => 'Low'] as $v => $l)<option value="{{ $v }}" @selected(old('priority', 'medium') === $v)>{{ $l }}</option>@endforeach</select>
                        </div>
                    </div>
                    <div>
                        <label style="display:block;font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.4px;margin-bottom:5px;" x-text="$store.ui.lang==='en' ? 'Due date' : 'Tarikh akhir'">Due date</label>
                        <input name="due_at" type="date" value="{{ old('due_at') }}" style="{{ $fs }}" />
                    </div>
                    <div>
                        <label style="display:block;font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.4px;margin-bottom:5px;" x-text="$store.ui.lang==='en' ? 'Description' : 'Penerangan'">Description</label>
                        <textarea name="description" rows="3" maxlength="5000" style="width:100%;border:1px solid var(--hairline);border-radius:8px;padding:9px 11px;font-size:13px;color:var(--ink);outline:none;resize:vertical;font-family:inherit;">{{ old('description') }}</textarea>
                    </div>
                    <button type="submit" class="uj-btn-primary" style="height:40px;font-size:13px;">
                        <span x-text="$store.ui.lang==='en' ? 'Assign task' : 'Beri tugas'">Assign task</span>
                    </button>
                </div>
            </form>
        </div>
        </template>
    </div>
@endif
```

Note: `$fs` (the shared field style) is defined at the top of the left column (line 27). If this block is placed outside that `@php` scope, copy the `$fs` definition into a local `@php` first.

- [ ] **Step 2: Run the profile test**

Run: `php artisan test --filter=test_profile_screen_lists_tasks_assigned_to_the_employee`
Expected: PASS.

- [ ] **Step 3: Verify the full board suite still passes**

Run: `php artisan test --filter=BoardCardTest`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add resources/views/screens/profile.blade.php
git commit -m "feat(tac): assign form + assigned-tasks tracking panel on profile"
```

---

## Task 6: Board badge + assignee field lock

**Files:**
- Modify: `resources/views/screens/board.blade.php`
- Modify: `resources/js/work-board.js`

- [ ] **Step 1: Add the badge to server-rendered cards**

In `resources/views/screens/board.blade.php`, the card markup starts around line 72. Add a `data-assigned` attribute and a badge. Replace the card's opening `<div ... data-card ...>` and the title block (lines 72–77) with:

```blade
                        <div class="uj-card uj-wi" data-card data-id="{{ $c->id }}" data-status="{{ $c->status }}" data-type="{{ $c->type }}" @if ($c->assigned_by_id) data-assigned="1" @endif style="padding:13px 14px;">
                            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
                                <span style="font-size:10px;font-weight:600;color:#fff;background:{{ $tcolor }};padding:2px 7px;border-radius:9999px;">{{ $tlabel }}</span>
                                <span class="wi-pri">@if ($c->priority)<span style="font-size:10.5px;font-weight:600;color:{{ $pri[$c->priority] }};">{{ $priLabel[$c->priority] ?? ucfirst($c->priority) }}</span>@endif</span>
                            </div>
                            @if ($c->assigned_by_id)
                                <div class="wi-assigned" style="font-size:10.5px;font-weight:600;color:var(--info);background:var(--canvas);border-radius:6px;padding:2px 7px;margin-bottom:8px;display:inline-block;">
                                    {{ $store_lang_assigned ?? 'Assigned by' }} {{ $c->assignedBy?->name ?? '—' }}
                                </div>
                            @endif
                            <div class="wi-title" style="font-size:13.5px;color:var(--ink);font-weight:500;line-height:1.4;margin-bottom:10px;">{{ $c->title }}</div>
```

(The `{{ $store_lang_assigned ?? 'Assigned by' }}` is a static English label; BM users still read "Assigned by" here — acceptable for v1, the panel and modal carry the translated strings. Leave the rest of the card markup unchanged.)

- [ ] **Step 2: Add the badge to the JS `cardInner()` (repaint path)**

In `resources/js/work-board.js`, inside `cardInner(card)` (line 19), add an assigned badge between the priority row and the title. After the line that closes the priority row (`</div>` ending the first flex block, line 35) and before the `wi-title` div (line 36), insert:

```javascript
        ${
            card.assigned_by
                ? `<div class="wi-assigned" style="font-size:10.5px;font-weight:600;color:var(--info);background:var(--canvas);border-radius:6px;padding:2px 7px;margin-bottom:8px;display:inline-block;">Assigned by ${esc(card.assigned_by.name || '—')}</div>`
                : ''
        }
```

- [ ] **Step 3: Lock fields in the modal for an assigned card**

In `resources/js/work-board.js`, add a `locked` flag to `modal` state and set it in `openCard()`.

(a) In the `modal: { ... }` object (around line 69), add `locked: false,` after `error: '',`.

(b) In `openCard()` (around line 207), after `this.modal.card = { ... }`, add:

```javascript
                // An assigned tac on this board is opened by the assignee, who may
                // only move it and comment — core fields are the assigner's.
                this.modal.locked = !!this.modal.card.assigned_by;
```

(c) In `resources/views/screens/board.blade.php`, lock the inputs in the detail modal. Add `:disabled="modal.locked"` to the title input (line 144), the type select (line 150), the priority select (line 156), the due_label input (line 162), the estimate input (line 166), and the description textarea (line 178). Wrap the Save + Delete buttons (lines 184–189) so they hide when locked — change their container `div` (line 183) to:

```blade
                    <div x-show="!modal.locked" style="display:flex;gap:10px;align-items:center;margin-bottom:22px;">
```

The Column (status) `<select>` and the comments section stay enabled.

- [ ] **Step 4: Rebuild front-end assets**

Run: `npm run build`
Expected: Vite build completes with no errors.

- [ ] **Step 5: Manual verification**

Start the app (`php artisan serve` + `npm run dev` if not already), then:
1. As a `manager` user, open a staff member's profile (`/app/profile?emp=<id>`) → click "Assign task" → fill the modal → submit. Confirm the back redirect shows the success flash and the task appears in the "Assigned tasks" panel.
2. Sign in as that staff member → open the board → confirm the card shows the "Assigned by {Manager}" badge, opening it shows locked core fields and no Delete, and the status select still moves the card.
3. Move the card to Done → confirm the manager receives a notification.

- [ ] **Step 6: Commit**

```bash
git add resources/views/screens/board.blade.php resources/js/work-board.js
git commit -m "feat(tac): assigned-by badge + assignee field lock on the board"
```

---

## Self-Review Notes

- **Spec coverage:** role-gated assign (Task 2) · assignee move+comment only (Task 2 authorization split) · assign-from-profile + tracking panel (Tasks 4–5) · notify on assign (Task 2) · real `due_at` picker (Tasks 2, 5) · notify on completion (Task 3) · "Assigned by" badge (Task 6). All spec sections map to a task.
- **Reused, not rebuilt:** move/comment/show/destroy endpoints and the board render are reused via the authorization split — no duplicate kanban machinery, matching the design's chosen approach.
- **Regression safety:** existing `BoardCardTest` cases stay valid — a non-owner, non-assigner is still 403 on every action because `assigned_by_id` is null for self-made cards.
- **Out of scope (v1):** global cross-staff rollup, accept/decline gate, reassignment, recurring tasks — intentionally excluded.
