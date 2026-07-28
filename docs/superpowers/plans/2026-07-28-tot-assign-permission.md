# TOT assign permission Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let one named person who is not HR set, change and clear who presents each month's TOT session, granted through a toggle on the existing Roles screen.

**Architecture:** No new screen and no new table. `tot.assign` joins the per-user permission override system that already exists (`Permissions::overridable()` plus `User::canInTenant()` plus `UserPermission` rows plus the `/app/roles` toggle grid). `TotController` gains one flag, `$canAssignPresenter`, which gates the presenter field on update, a narrow create path, and whether the picker renders.

**Tech Stack:** Laravel 13, PHP 8.5, PHPUnit 12, Larastan 3, Pint, Blade, Alpine 3.

Spec: [2026-07-28-tot-panel-and-assign-permission-design.md](../specs/2026-07-28-tot-panel-and-assign-permission-design.md), Part A.

## Global Constraints

- **No em dashes** in code, comments, Blade copy or commit messages. Use connector words.
- Commit messages say what changed and why, not just "updated file".
- Run `vendor/bin/pint --dirty --format agent` before every commit. It must report `"result":"passed"`.
- Run `composer analyse` before the final commit of each task. It must report `"errors":0`.
- Every screen string is bilingual. Follow the existing pattern in `resources/views/screens/tot.blade.php`: `x-text="$store.ui.lang==='en' ? 'English' : 'Bahasa Melayu'"`.
- `/app/roles` stays gated to `management` and `hr`. Do not open it to managers. That screen grants every overridable permission, `staff.create` and `staff.update` included.
- A `tot.assign` holder may change the presenter and nothing else. Title, description, links, status, held date, cross-link, delete and nickname linking stay privileged.
- Never revoke a Knowledge Bank contribution. Clearing a presenter does not touch `knowledge_monthly_contributions`.
- The permission key is exactly `tot.assign`.
- Tests run with `php artisan test --compact`. Use a filter to keep runs small.

---

## File Structure

| File | Responsibility |
|---|---|
| `app/Support/Permissions.php` | Declares `tot.assign` on the HR and management role sets, and adds it to `overridable()` so the Roles screen renders a toggle for it. |
| `app/Http/Controllers/TotController.php` | Resolves `$canAssignPresenter` and enforces it on `update()`, `store()` and `screenData()`. Sends the notification and writes the audit row. |
| `resources/views/screens/tot.blade.php` | Renders the presenter picker for a holder without exposing any privileged field. |
| `tests/Feature/TotAssignPermissionTest.php` | All new coverage for this plan. |
| `tests/Feature/PermissionsTest.php` | Existing. Extend only if it already asserts on `overridable()`. |

---

## Task 1: Declare the permission

**Files:**
- Modify: `app/Support/Permissions.php`
- Test: `tests/Feature/TotAssignPermissionTest.php` (create)

**Interfaces:**
- Consumes: nothing.
- Produces: the string `'tot.assign'` present in `Permissions::forRole('hr')`, `Permissions::forRole('management')`, `Permissions::all()` and `Permissions::overridable()`; and a `'tot'` key in `Permissions::overridableGrouped()`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/TotAssignPermissionTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Support\Permissions;
use Tests\TestCase;

class TotAssignPermissionTest extends TestCase
{
    public function test_hr_and_management_hold_tot_assign_by_role(): void
    {
        $this->assertTrue(Permissions::roleHas('hr', 'tot.assign'));
        $this->assertTrue(Permissions::roleHas('management', 'tot.assign'));
        $this->assertTrue(Permissions::roleHas('director', 'tot.assign'));
    }

    public function test_manager_and_employee_do_not_hold_it_by_role(): void
    {
        $this->assertFalse(Permissions::roleHas('manager', 'tot.assign'));
        $this->assertFalse(Permissions::roleHas('employee', 'tot.assign'));
    }

    public function test_it_is_overridable_and_grouped_under_tot(): void
    {
        $this->assertContains('tot.assign', Permissions::overridable());
        $this->assertSame(['tot.assign'], Permissions::overridableGrouped()['tot'] ?? []);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact --filter=TotAssignPermissionTest`

Expected: FAIL. `assertTrue(Permissions::roleHas('hr', 'tot.assign'))` fails because the key does not exist yet.

- [ ] **Step 3: Add the key to both role sets**

In `app/Support/Permissions.php`, inside `ROLE_PERMISSIONS`, add `'tot.assign',` to the `'management'` array and to the `'hr'` array. Put it on its own line directly after the `'role.view', 'role.manage',` line in each, so both arrays keep the same order:

```php
            'role.view', 'role.manage',
            'tot.assign',
            'report.view', 'report.export',
```

Do not add it to `'manager'` or `'employee'`.

- [ ] **Step 4: Add it to the overridable set**

Replace the body of `overridable()`:

```php
    public static function overridable(): array
    {
        return ['staff.create', 'staff.update', 'staff.import', 'tot.assign'];
    }
```

Then update that method's docblock so it no longer claims the staff domain is the only enforced one. Replace the sentence starting "An override only bites where a controller gates on canInTenant(); today that is the staff domain" with:

```
     * An override only bites where a controller gates on canInTenant(): the staff domain
     * (EmployeeController create/update/import) and the TOT presenter field
     * (TotController). The override UI and writer are scoped to this set so admins are
     * never shown, or able to save, a toggle that does nothing (AK-AUTHZ-04). Widen this
     * list only in lockstep with new canInTenant() enforcement.
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --compact --filter=TotAssignPermissionTest`

Expected: PASS, 3 tests.

- [ ] **Step 6: Check nothing else asserted on the old list**

Run: `php artisan test --compact --filter=Permission`

Expected: PASS. If a test asserts an exact count or an exact array of overridable permissions, update that assertion to include `tot.assign` and say so in your report. Do not delete the test.

- [ ] **Step 7: Format, analyse and commit**

```bash
vendor/bin/pint --dirty --format agent
composer analyse
git add app/Support/Permissions.php tests/Feature/TotAssignPermissionTest.php
git commit -m "feat(tot): declare the tot.assign permission and make it overridable

HR and management hold it by role. Adding it to overridable() is what makes
the toggle appear on the Roles screen, so one named person can be given the
right to set presenters without being made HR."
```

---

## Task 2: Let a holder set the presenter

**Files:**
- Modify: `app/Http/Controllers/TotController.php:33-64` (`screenData`), `:117-170` (`update`)
- Test: `tests/Feature/TotAssignPermissionTest.php`

**Interfaces:**
- Consumes: `Permissions::overridable()` containing `tot.assign` from Task 1, and `User::canInTenant(Tenant $tenant, string $permission): bool` which already exists.
- Produces: `private function canAssignPresenter(Request $request): bool` on `TotController`, and a `'canAssignPresenter' => bool` key in the array `screenData()` returns.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/TotAssignPermissionTest.php`. Add these imports at the top of the file, keeping the existing `use App\Support\Permissions;`:

```php
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\TotSession;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
```

Add `use RefreshDatabase;` as the first line of the class body, then these members and tests:

```php
    private Tenant $tenant;

    private User $manager;

    private Employee $presenter;

    private function seedWorkspace(): void
    {
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);

        $this->manager = User::create([
            'name' => 'Kussairi', 'email' => 'kus@example.com', 'password' => Hash::make('password'),
        ]);
        $this->manager->tenants()->attach($this->tenant->id, ['role' => 'manager']);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->manager->id,
            'name' => 'Kussairi', 'status' => 'active', 'workload' => 'green',
        ]);

        $this->presenter = Employee::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Nabil', 'status' => 'active', 'workload' => 'green',
        ]);
    }

    private function grantAssign(): void
    {
        UserPermission::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->manager->id,
            'permission' => 'tot.assign',
            'granted' => true,
        ]);
    }

    private function actingAsManager(): self
    {
        $this->actingAs($this->manager)->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    private function slot(): TotSession
    {
        return TotSession::create([
            'tenant_id' => $this->tenant->id, 'year' => 2026, 'month' => 9,
            'title' => 'Original title', 'status' => 'planned',
        ]);
    }

    public function test_a_holder_can_set_the_presenter(): void
    {
        $this->seedWorkspace();
        $this->grantAssign();
        $session = $this->slot();

        $this->actingAsManager()
            ->post("/app/tot/{$session->id}", ['presenter_employee_id' => $this->presenter->id])
            ->assertRedirect();

        $this->assertSame($this->presenter->id, $session->fresh()->presenter_employee_id);
    }

    public function test_a_holder_cannot_change_anything_else(): void
    {
        $this->seedWorkspace();
        $this->grantAssign();
        $session = $this->slot();

        $this->actingAsManager()->post("/app/tot/{$session->id}", [
            'presenter_employee_id' => $this->presenter->id,
            'title' => 'Hijacked',
            'status' => 'done',
            'held_on' => '2026-09-05',
        ]);

        $fresh = $session->fresh();
        $this->assertSame($this->presenter->id, $fresh->presenter_employee_id);
        $this->assertSame('Original title', $fresh->title);
        $this->assertSame('planned', $fresh->status);
        $this->assertNull($fresh->held_on);
    }

    public function test_a_manager_without_the_override_cannot_set_a_presenter(): void
    {
        $this->seedWorkspace();
        $session = $this->slot();

        $this->actingAsManager()
            ->post("/app/tot/{$session->id}", ['presenter_employee_id' => $this->presenter->id])
            ->assertForbidden();

        $this->assertNull($session->fresh()->presenter_employee_id);
    }

    public function test_a_revoked_override_takes_the_ability_away(): void
    {
        $this->seedWorkspace();
        UserPermission::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->manager->id,
            'permission' => 'tot.assign',
            'granted' => false,
        ]);
        $session = $this->slot();

        $this->actingAsManager()
            ->post("/app/tot/{$session->id}", ['presenter_employee_id' => $this->presenter->id])
            ->assertForbidden();
    }

    public function test_a_holder_cannot_reach_the_roles_screen(): void
    {
        $this->seedWorkspace();
        $this->grantAssign();

        $this->actingAsManager()->get('/app/roles')->assertForbidden();
    }
```

Note on `test_a_manager_without_the_override_cannot_set_a_presenter`: the 403 comes from `authorizeSlotEdit()`, which already refuses anybody who is neither privileged nor the slot's presenter. That guard must keep working, and this test is what proves the new flag did not accidentally widen it.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter=TotAssignPermissionTest`

Expected: FAIL. `test_a_holder_can_set_the_presenter` returns 403, because `authorizeSlotEdit()` does not know about the new permission yet.

- [ ] **Step 3: Add the flag helper**

In `app/Http/Controllers/TotController.php`, add this private method directly above `assertSameTenant()`:

```php
    /**
     * May the actor set who presents a month? True for HR and management by role, and for
     * anybody given the tot.assign override on the Roles screen. It buys exactly one field,
     * presenter_employee_id, and nothing else on the slot.
     */
    private function canAssignPresenter(Request $request): bool
    {
        if ($this->hasTenantRole($request, self::PRIVILEGED_ROLES)) {
            return true;
        }

        $tenant = app(CurrentTenant::class)->get();

        return $tenant !== null
            && $request->user()?->canInTenant($tenant, 'tot.assign') === true;
    }
```

- [ ] **Step 4: Let the flag through the edit guard**

In `authorizeSlotEdit()`, add an early return for a holder. Replace the whole method with:

```php
    /** 403 unless the actor is privileged, holds tot.assign, or is the presenter of this slot. */
    private function authorizeSlotEdit(Request $request, TotSession $session, ?Employee $employee): void
    {
        if ($this->canAssignPresenter($request)) {
            return;
        }

        abort_unless(
            $employee && $session->presenter_employee_id === $employee->id,
            403,
            'Only HR, management, the TOT organiser, or the presenter of this session can edit it.'
        );
    }
```

- [ ] **Step 5: Gate the presenter rule on the flag, not on privilege**

**The material rules must become conditional too.** Widening `authorizeSlotEdit()` in Step 4 means a holder now passes that gate, and the `title`, `description`, `links` and `entry_id` rules are currently unconditional, so a holder would silently gain the right to rewrite a session's material. That is a real authorization hole, and `test_a_holder_cannot_change_anything_else` is what catches it.

In `update()`, capture whether the actor owns this slot, next to the existing `$privileged` line:

```php
        $isPresenterOfSlot = $employee && $session->presenter_employee_id === $employee->id;
```

Then replace the whole `$rules = [...]` assignment and the `if ($privileged) {` block that follows it with:

```php
        // The material (title, description, links, cross-link) belongs to the presenter of
        // THIS slot or a privileged role, not to a tot.assign holder: that override buys
        // exactly one field, presenter_employee_id, handled below.
        $rules = [];

        if ($privileged || $isPresenterOfSlot) {
            $rules['title'] = ['nullable', 'string', 'max:200'];
            $rules['description'] = ['nullable', 'string', 'max:2000'];
            $rules['links'] = ['nullable', 'array', 'max:12'];
            $rules['links.*.label'] = ['required_with:links', 'string', 'max:60'];
            $rules['links.*.url'] = ['required_with:links', 'url', 'max:2000'];
            $rules['entry_id'] = ['nullable', 'integer', Rule::exists('knowledge_entries', 'id')->where('tenant_id', $tenantId)];
        }

        if ($this->canAssignPresenter($request)) {
            $rules['presenter_employee_id'] = ['nullable', 'integer', Rule::exists('employees', 'id')->where('tenant_id', $tenantId)];
        }

        if ($privileged) {
            $rules['presenter_name'] = ['nullable', 'string', 'max:120'];
            $rules['status'] = ['nullable', 'in:'.implode(',', TotSession::STATUSES)];
            $rules['held_on'] = ['nullable', 'date'];
        }
```

A holder who also happens to present that month gets both sets, which is right: the two rights are independent and they add up.

`presenter_name` stays privileged on purpose. Linking an imported nickname to a real employee is HR work, and a holder never needs to type a free-text name.

- [ ] **Step 6: Expose the flag to the screen**

In `screenData()`, add one key to the returned array, directly after `'canManage' => $privileged,`:

```php
            'canAssignPresenter' => $this->canAssignPresenter($request),
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=TotAssignPermissionTest`

Expected: PASS, 8 tests.

- [ ] **Step 8: Run the whole TOT suite for regressions**

Run: `php artisan test --compact --filter=Tot`

Expected: PASS. `TotTest`, `TotHistorySeederTest` and `TotReminderTest` must all stay green. `screenData()` returning one extra key is additive, so nothing should break; if something does, it is asserting on the exact shape of that array and the assertion needs the new key added.

- [ ] **Step 9: Format, analyse and commit**

```bash
vendor/bin/pint --dirty --format agent
composer analyse
git add app/Http/Controllers/TotController.php tests/Feature/TotAssignPermissionTest.php
git commit -m "feat(tot): let a tot.assign holder set the presenter

The holder passes the slot-edit guard and gets one extra validation rule.
presenter_name, status and held_on stay privileged, and validate() drops any
key it has no rule for, so a hand-crafted POST cannot promote a slot."
```

---

## Task 3: Clearing a presenter

**Files:**
- Modify: `app/Http/Controllers/TotController.php` (`update`)
- Test: `tests/Feature/TotAssignPermissionTest.php`

**Interfaces:**
- Consumes: `canAssignPresenter()` from Task 2.
- Produces: nothing new. Changes the behaviour of `update()` when `presenter_employee_id` is present in the validated data.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/TotAssignPermissionTest.php`. Add `use App\Models\KnowledgeContribution;` to the imports:

```php
    public function test_a_holder_can_clear_the_presenter(): void
    {
        $this->seedWorkspace();
        $this->grantAssign();
        $session = $this->slot();
        $session->update(['presenter_employee_id' => $this->presenter->id]);

        $this->actingAsManager()->post("/app/tot/{$session->id}", ['presenter_employee_id' => '']);

        $fresh = $session->fresh();
        $this->assertNull($fresh->presenter_employee_id);
        $this->assertNull($fresh->presenter_name);
    }

    public function test_clearing_a_presenter_never_revokes_knowledge_bank_credit(): void
    {
        $this->seedWorkspace();
        $this->grantAssign();
        $session = $this->slot();
        $session->update(['presenter_employee_id' => $this->presenter->id]);
        KnowledgeContribution::mark($this->presenter, 2026, 9);

        $this->actingAsManager()->post("/app/tot/{$session->id}", ['presenter_employee_id' => '']);

        $this->assertSame(1, KnowledgeContribution::where('employee_id', $this->presenter->id)
            ->where('year', 2026)->where('month', 9)->where('submitted', true)->count());
    }

    public function test_assigning_an_employee_clears_an_imported_nickname(): void
    {
        $this->seedWorkspace();
        $this->grantAssign();
        $session = $this->slot();
        $session->update(['presenter_name' => 'Kak Lin']);

        $this->actingAsManager()
            ->post("/app/tot/{$session->id}", ['presenter_employee_id' => $this->presenter->id]);

        $fresh = $session->fresh();
        $this->assertSame($this->presenter->id, $fresh->presenter_employee_id);
        $this->assertNull($fresh->presenter_name);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter=TotAssignPermissionTest`

Expected: FAIL on `test_a_holder_can_clear_the_presenter` and `test_assigning_an_employee_clears_an_imported_nickname`, because `presenter_name` is never touched by a holder's request today.

- [ ] **Step 3: Reconcile the two presenter columns**

In `update()`, directly after the `$session->fill($data);` line, insert:

```php
        // The two presenter columns are one fact stored two ways: a linked employee, or a
        // free-text name for an imported nickname nobody has matched yet. Whenever this
        // request decides the linked employee, the stale free-text name goes with it,
        // unless the same request explicitly supplies one (privileged callers only).
        if (array_key_exists('presenter_employee_id', $data) && blank($data['presenter_name'] ?? null)) {
            $session->presenter_name = null;
        }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=TotAssignPermissionTest`

Expected: PASS, 11 tests.

- [ ] **Step 5: Run the whole TOT suite**

Run: `php artisan test --compact --filter=Tot`

Expected: PASS.

- [ ] **Step 6: Format, analyse and commit**

```bash
vendor/bin/pint --dirty --format agent
composer analyse
git add app/Http/Controllers/TotController.php tests/Feature/TotAssignPermissionTest.php
git commit -m "feat(tot): clearing a presenter drops the stale free-text name too

The two presenter columns hold one fact, so deciding the linked employee has
to settle the imported nickname in the same write. Knowledge Bank credit is
never revoked, because a revoke could erase a month earned by writing a
lesson and that failure would be invisible."
```

---

## Task 4: A holder can create the slot they are assigning

**Files:**
- Modify: `app/Http/Controllers/TotController.php:67-110` (`store`)
- Test: `tests/Feature/TotAssignPermissionTest.php`

**Interfaces:**
- Consumes: `canAssignPresenter()` from Task 2.
- Produces: nothing new.

Why this task exists: the history seeder filled 2024 through 2026. Without it, a holder cannot assign anybody in January 2027 and HR has to pre-create every year, which puts back the person this permission exists to remove.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/TotAssignPermissionTest.php`:

```php
    public function test_a_holder_can_create_a_slot_with_a_presenter(): void
    {
        $this->seedWorkspace();
        $this->grantAssign();

        $this->actingAsManager()->post('/app/tot', [
            'year' => 2027, 'month' => 1,
            'presenter_employee_id' => $this->presenter->id,
        ])->assertRedirect();

        $created = TotSession::where('year', 2027)->where('month', 1)->firstOrFail();
        $this->assertSame($this->presenter->id, $created->presenter_employee_id);
        $this->assertSame('planned', $created->status);
        $this->assertNull($created->title);
    }

    public function test_a_holder_creating_a_slot_cannot_set_anything_else(): void
    {
        $this->seedWorkspace();
        $this->grantAssign();

        $this->actingAsManager()->post('/app/tot', [
            'year' => 2027, 'month' => 2,
            'presenter_employee_id' => $this->presenter->id,
            'title' => 'Hijacked',
            'status' => 'done',
        ]);

        $created = TotSession::where('year', 2027)->where('month', 2)->firstOrFail();
        $this->assertSame('planned', $created->status);
        $this->assertNull($created->title);
    }

    public function test_a_holder_still_cannot_create_a_duplicate_slot(): void
    {
        $this->seedWorkspace();
        $this->grantAssign();
        $this->slot();

        $this->actingAsManager()->post('/app/tot', [
            'year' => 2026, 'month' => 9,
            'presenter_employee_id' => $this->presenter->id,
        ])->assertStatus(422);
    }

    public function test_a_manager_without_the_override_cannot_create_a_slot(): void
    {
        $this->seedWorkspace();

        $this->actingAsManager()->post('/app/tot', [
            'year' => 2027, 'month' => 3,
            'presenter_employee_id' => $this->presenter->id,
        ])->assertForbidden();

        $this->assertSame(0, TotSession::where('year', 2027)->count());
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter=TotAssignPermissionTest`

Expected: FAIL. `store()` calls `authorizeTenantRole($request, self::PRIVILEGED_ROLES)`, so a holder gets 403.

- [ ] **Step 3: Split the create path by privilege**

In `store()`, replace the `authorizeTenantRole(...)` call and the `$request->validate([...])` block with:

```php
        abort_unless($this->canAssignPresenter($request), 403);

        $privileged = $this->hasTenantRole($request, self::PRIVILEGED_ROLES);
        $tenantId = app(CurrentTenant::class)->id();

        $rules = [
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'presenter_employee_id' => ['nullable', 'integer', Rule::exists('employees', 'id')->where('tenant_id', $tenantId)],
        ];

        if ($privileged) {
            $rules['presenter_name'] = ['nullable', 'string', 'max:120'];
            $rules['title'] = ['nullable', 'string', 'max:200'];
            $rules['description'] = ['nullable', 'string', 'max:2000'];
            $rules['status'] = ['required', 'in:'.implode(',', TotSession::STATUSES)];
        }

        // A holder opens a month and puts a name on it. Everything else about the session,
        // including whether it happened, stays HR's decision, so their new slot is planned.
        $data = $request->validate($rules);
        $data['status'] ??= 'planned';
```

Leave the `$exists` check, the `TotSession::create([...])` call, the `QueryException` catch, the `AuditLog::record` line and the return exactly as they are. `create()` already reads `$data['title'] ?? null` and `$data['description'] ?? null`, so a holder's missing keys land as null with no further change.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=TotAssignPermissionTest`

Expected: PASS, 15 tests.

- [ ] **Step 5: Run the whole TOT suite**

Run: `php artisan test --compact --filter=Tot`

Expected: PASS. `TotTest` has existing coverage that HR creates slots and that a duplicate returns 422; both must stay green.

- [ ] **Step 6: Format, analyse and commit**

```bash
vendor/bin/pint --dirty --format agent
composer analyse
git add app/Http/Controllers/TotController.php tests/Feature/TotAssignPermissionTest.php
git commit -m "feat(tot): a tot.assign holder can open next year's slot

Without this the delegation breaks every January: the seeder filled 2024 to
2026, so assigning anybody in 2027 would need HR to pre-create the month.
The holder's create is narrow, year plus month plus presenter, forced planned."
```

---

## Task 5: Notify the presenter and record who assigned them

**Files:**
- Modify: `app/Http/Controllers/TotController.php` (`store`, `update`)
- Test: `tests/Feature/TotAssignPermissionTest.php`

**Interfaces:**
- Consumes: `AppNotification::send(?int $userId, string $title, ?string $body = null, ?string $url = null, ?string $dedupeKey = null): bool`, and `AuditLog::record(string $action, ?string $detail = null)`, both already in the codebase.
- Produces: `private function announcePresenter(TotSession $session, ?int $previousEmployeeId): void` on `TotController`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/TotAssignPermissionTest.php`. Add `use App\Models\AppNotification;` and `use App\Models\AuditLog;` to the imports:

```php
    public function test_assigning_notifies_the_new_presenter_once(): void
    {
        $this->seedWorkspace();
        $this->grantAssign();
        $presenterUser = User::create([
            'name' => 'Nabil', 'email' => 'nabil@example.com', 'password' => Hash::make('password'),
        ]);
        $presenterUser->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $this->presenter->update(['user_id' => $presenterUser->id]);
        $session = $this->slot();

        $this->actingAsManager()
            ->post("/app/tot/{$session->id}", ['presenter_employee_id' => $this->presenter->id]);
        $this->actingAsManager()
            ->post("/app/tot/{$session->id}", ['presenter_employee_id' => $this->presenter->id]);

        $this->assertSame(1, AppNotification::where('user_id', $presenterUser->id)->count());
        $this->assertStringContainsString(
            'presenting TOT',
            (string) AppNotification::where('user_id', $presenterUser->id)->value('title')
        );
    }

    public function test_clearing_a_presenter_sends_nothing(): void
    {
        $this->seedWorkspace();
        $this->grantAssign();
        $session = $this->slot();
        $session->update(['presenter_employee_id' => $this->presenter->id]);

        $this->actingAsManager()->post("/app/tot/{$session->id}", ['presenter_employee_id' => '']);

        $this->assertSame(0, AppNotification::count());
    }

    public function test_every_presenter_change_writes_an_audit_row(): void
    {
        $this->seedWorkspace();
        $this->grantAssign();
        $session = $this->slot();

        $this->actingAsManager()
            ->post("/app/tot/{$session->id}", ['presenter_employee_id' => $this->presenter->id]);

        $this->assertSame(1, AuditLog::where('action', 'Assigned TOT presenter')->count());
    }
```

The dedupe key makes the double post send once. That has one known edge, worth knowing and not worth code: if a slot is reassigned away from somebody and later back to them, the second assignment sends nothing. Reassignment churn on one month is rare, and a duplicate notification would be worse than a missing one.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter=TotAssignPermissionTest`

Expected: FAIL. No notification row is created and no `Assigned TOT presenter` audit row exists.

- [ ] **Step 3: Add the announcement helper**

Add `use App\Models\AppNotification;` to the imports of `app/Http/Controllers/TotController.php`, then add this private method directly above `canAssignPresenter()`:

```php
    /**
     * Tell the newly assigned presenter, and record who decided it.
     *
     * Only fires when the linked employee actually changed, so re-saving the same person
     * is silent. Clearing a presenter announces nothing: the person removed sees it on the
     * screen, and a "you are no longer presenting" message is noise. In-app only, never
     * email, matching the rest of the TOT board.
     */
    private function announcePresenter(TotSession $session, ?int $previousEmployeeId): void
    {
        if ($session->presenter_employee_id === $previousEmployeeId) {
            return;
        }

        AuditLog::record(
            'Assigned TOT presenter',
            sprintf('%04d-%02d', $session->year, $session->month)
        );

        if ($session->presenter_employee_id === null) {
            return;
        }

        $session->loadMissing('presenter');

        AppNotification::send(
            $session->presenter?->user_id,
            'You are presenting TOT on '.$session->session_date->format('j F Y'),
            'Pick your topic and upload your slides on the TOT board.',
            route('app.screen', 'tot').'?year='.$session->year,
            "tot:{$session->id}:assigned:{$session->presenter_employee_id}",
        );
    }
```

`session_date` is the computed accessor already on `TotSession`; it returns a Carbon instance for the first Saturday of the slot's month.

- [ ] **Step 4: Call it from update()**

In `update()`, capture the previous value before `$session->fill($data)`:

```php
        $wasDone = $session->status === 'done';
        $previousPresenterId = $session->presenter_employee_id;
```

Then, directly after the existing `$session->save();` line and before the `if (! $wasDone && ...)` credit block, add:

```php
        $this->announcePresenter($session, $previousPresenterId);
```

- [ ] **Step 5: Call it from store()**

In `store()`, directly after the existing `AuditLog::record('Created TOT slot', ...)` line, add:

```php
        $this->announcePresenter($session, null);
```

A new slot has no previous presenter, so passing `null` makes any presenter on it a change.

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=TotAssignPermissionTest`

Expected: PASS, 18 tests.

- [ ] **Step 7: Run the whole TOT suite**

Run: `php artisan test --compact --filter=Tot`

Expected: PASS. `TotReminderTest` counts notifications; confirm it still passes, because assignment notifications are a new source of rows in that table.

- [ ] **Step 8: Format, analyse and commit**

```bash
vendor/bin/pint --dirty --format agent
composer analyse
git add app/Http/Controllers/TotController.php tests/Feature/TotAssignPermissionTest.php
git commit -m "feat(tot): tell the presenter they were assigned, and log who did it

The audit row is the only way to answer who put somebody on a month once the
right lives outside HR. The notification is in-app, dedupe-keyed on the slot
and the employee, and a clear stays silent on purpose."
```

---

## Task 6: Render the picker for a holder

**Files:**
- Modify: `resources/views/screens/tot.blade.php`, `resources/views/screens/roles.blade.php:85`
- Test: `tests/Feature/TotAssignPermissionTest.php`

**Interfaces:**
- Consumes: `$canAssignPresenter` from `screenData()` (Task 2), and the existing `$canManage` and `$sessions` variables the view already receives.
- Produces: nothing.

**Also fix the Roles screen copy.** `resources/views/screens/roles.blade.php` line 85 describes the override grid as "Per-member overrides for staff actions (create / update / import)". That is now wrong: the grid renders a `tot` group too, and the help text above it says the feature does not cover it. The grid itself needs no change, it already loops over `overridableGrouped()` generically and prints the raw key. Replace only that opening sentence with:

```
Per-member overrides for staff actions (create, update, import) and for TOT presenter assignment.
```

Leave the rest of the paragraph exactly as it is.

- [ ] **Step 1: Read the view's current edit form**

Open `resources/views/screens/tot.blade.php` and find the block that starts with `@if ($canEditSlot)` (near line 444). Read it fully, including the nested `@if ($canManage)` blocks, before changing anything. You need to know which fields sit inside which gate.

- [ ] **Step 2: Write the failing tests**

Append to `tests/Feature/TotAssignPermissionTest.php`:

```php
    public function test_the_screen_shows_a_holder_the_presenter_picker(): void
    {
        $this->seedWorkspace();
        $this->grantAssign();
        $this->slot();

        $this->actingAsManager()->get('/app/tot')
            ->assertOk()
            ->assertSee('name="presenter_employee_id"', false);
    }

    public function test_the_screen_hides_privileged_fields_from_a_holder(): void
    {
        $this->seedWorkspace();
        $this->grantAssign();
        $this->slot();

        $this->actingAsManager()->get('/app/tot')
            ->assertOk()
            ->assertDontSee('name="status"', false)
            ->assertDontSee('name="held_on"', false);
    }

    public function test_the_screen_shows_no_picker_without_the_override(): void
    {
        $this->seedWorkspace();
        $this->slot();

        $this->actingAsManager()->get('/app/tot')
            ->assertOk()
            ->assertDontSee('name="presenter_employee_id"', false);
    }
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `php artisan test --compact --filter=TotAssignPermissionTest`

Expected: FAIL on `test_the_screen_shows_a_holder_the_presenter_picker`, because the picker only renders inside `@if ($canManage)`.

- [ ] **Step 4: Change the gates**

Two edits in `resources/views/screens/tot.blade.php`:

1. Widen the outer edit gate. Find the line computing `$canEditSlot` (near line 255) and change it to include holders:

```php
$canEditSlot = $canManage || $canAssignPresenter || $isPresenterOfSlot;
```

2. Move the presenter picker out of the `@if ($canManage)` block and into its own `@if ($canAssignPresenter)` block, leaving `presenter_name`, `status`, `held_on` and the delete control inside `@if ($canManage)`. Keep the picker's markup, its label and its bilingual `x-text` exactly as they are; only the surrounding directive changes.

Empty months need no extra work. `$isPresenterOfSlot` is false on an unsaved placeholder because it tests `$session->exists`, but `$canAssignPresenter` does not, so widening `$canEditSlot` in edit 1 already opens the form on all twelve rows. That form posts to `tot.store` for a placeholder, which Task 4 now accepts from a holder.

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=TotAssignPermissionTest`

Expected: PASS, 21 tests.

- [ ] **Step 6: Check the view in a browser**

The app runs under Lerd at `http://localhost:9100`. Sign in with no password by navigating to:

```
http://localhost:9100/dev/login?email=manager@amanahku.test&tenant=unijaya
```

Grant `tot.assign` to that manager first: sign in as HR at
`http://localhost:9100/dev/login?email=hr@amanahku.test&tenant=unijaya`, open `/app/roles`, find the manager row and switch the new "tot" toggle on.

Then as the manager, open `/app/tot` and confirm: the presenter picker is there, and no status control, no held date, no delete button and no title field are.

- [ ] **Step 7: Rebuild assets if Tailwind classes changed**

If you added or removed any class in the Blade file, run `bun run build` and stage `public/build`. Never use npm or node.

- [ ] **Step 8: Format, analyse and commit**

```bash
vendor/bin/pint --dirty --format agent
composer analyse
git add resources/views/screens/tot.blade.php tests/Feature/TotAssignPermissionTest.php
git commit -m "feat(tot): show the presenter picker to a tot.assign holder

The permission was unreachable from the screen: the picker lived inside the
canManage gate, so a holder could only assign by hand-crafting a POST."
```

---

## Task 7: Cross-tenant regression

**Files:**
- Modify: `tests/Feature/CrossTenantDenialTest.php`

**Interfaces:**
- Consumes: everything above.
- Produces: nothing.

Why this task exists: this codebase has a documented bug class, AK-SEC-04. `SubstituteBindings` resolves route-bound models before the `tenant` middleware sets `CurrentTenant`, so the `BelongsToTenant` global scope is inert at binding time. During the first TOT build, three separate reviewers cleared exactly this as safe and were all wrong; a live cross-tenant write hole was only found when somebody wrote the test. `UserPermission` rows carry `tenant_id`, so an override in one company must not grant anything in another. Prove it.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/CrossTenantDenialTest.php`, inside the class. Read the file's existing `setUp()` first to learn the names it gives the two tenants and the attacker; the snippet below uses `$this->tenantB`, `$this->attackerB` and `$this->tenantA`, which the file already defines.

```php
    /**
     * A tot.assign override is a per-tenant row. Holding it in company B must grant nothing
     * in company A, and the route-bound session must 404 before any of it is even reached.
     */
    public function test_a_tot_assign_override_does_not_cross_tenants(): void
    {
        UserPermission::create([
            'tenant_id' => $this->tenantB->id,
            'user_id' => $this->attackerB->id,
            'permission' => 'tot.assign',
            'granted' => true,
        ]);

        $session = TotSession::create([
            'tenant_id' => $this->tenantA->id, 'year' => 2026, 'month' => 4,
            'title' => 'Alpha only', 'status' => 'planned',
        ]);

        $this->denied("/app/tot/{$session->id}", ['presenter_employee_id' => $this->victimA->id]);

        $this->assertNull(TotSession::withoutGlobalScopes()->find($session->id)->presenter_employee_id);
    }
```

Add `use App\Models\UserPermission;` to the file's imports if it is not already there.

- [ ] **Step 2: Run the test**

Run: `php artisan test --compact --filter=CrossTenantDenialTest`

Expected: PASS. `assertSameTenant()` already 404s a foreign session, so this test should pass on the first run.

**If it fails, stop and report it.** A failure here means the new permission opened a real cross-tenant write hole, which is a Critical finding, not something to patch quietly.

- [ ] **Step 3: Prove the test can fail**

Temporarily comment out the body of `assertSameTenant()` in `app/Http/Controllers/TotController.php`, re-run the filter, and confirm the new test fails. Then restore the method exactly.

A test that passes whether or not the guard exists proves nothing. This step is what tells you the guard is what is doing the work.

- [ ] **Step 4: Run the full suite**

Run: `php artisan test --compact`

Expected: PASS, with the same skip count the branch started with.

- [ ] **Step 5: Format, analyse and commit**

```bash
vendor/bin/pint --dirty --format agent
composer analyse
git add tests/Feature/CrossTenantDenialTest.php
git commit -m "test(tot): a tot.assign override cannot cross tenants

AK-SEC-04 is a live bug class in this codebase, and the first TOT build shipped
an instance of it that three reviewers cleared as safe. A new permission that
gates a write on a route-bound model gets its own denial test."
```

---

## Verification before handing back

Run all of these and paste the real output:

```bash
php artisan test --compact
composer analyse
vendor/bin/pint --format agent
git status --short
```

Expected: the suite passes with the same skip count the branch started with, PHPStan reports `"errors":0`, Pint reports `"result":"passed"`, and the working tree is clean.

Do not deploy. Leave the work committed on `dev`.
