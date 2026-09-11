<?php

namespace Tests\Acceptance;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Throwable;

/**
 * The four cross-cutting checks every acceptance class runs, plus the fixture
 * builders they need. Frozen after the first /qa write: never edited.
 *
 * Each check is a plain assertion helper, not a test method, so a class calls
 * the ones that apply from its session onward and a check that a later session
 * builds (the due-date lock, S02) does not fail the session before it. From S02
 * on, every acceptance class calls all four in one `test_always_*` method.
 */
trait AlwaysChecks
{
    protected Tenant $tenant;

    /** A quiet Tuesday: no birthday, no holiday eve, not the 1st, not a Friday afternoon. */
    protected const QUIET_DAY = '2026-09-08 10:00:00';

    protected function tenant(): Tenant
    {
        return $this->tenant ??= Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
    }

    /** A user + employee in the tenant with the given membership role. */
    protected function person(string $name, string $role = 'employee', array $employeeAttrs = []): Employee
    {
        $user = User::create([
            'name' => $name,
            'email' => strtolower(preg_replace('/\W+/', '', $name)).'@example.com',
            'password' => Hash::make('password'),
        ]);
        $user->tenants()->attach($this->tenant()->id, ['role' => $role]);

        return Employee::create(array_merge([
            'tenant_id' => $this->tenant()->id, 'user_id' => $user->id,
            'name' => $name, 'status' => 'active', 'workload' => 'green',
        ], $employeeAttrs));
    }

    protected function actingInTenantAs(Employee $employee): static
    {
        $this->actingAs($employee->user)->withSession(['current_tenant' => $this->tenant()->id]);

        return $this;
    }

    protected function card(Employee $owner, array $attrs = []): WorkItem
    {
        return $owner->workItems()->create(array_merge([
            'tenant_id' => $this->tenant()->id, 'title' => 'Card', 'type' => 'task',
            'priority' => 'low', 'status' => 'todo', 'progress' => 0,
        ], $attrs));
    }

    /** Check 1 (from S02): a work item's due date cannot change after first save, UI and API. */
    protected function assertDueDateLocked(): void
    {
        $owner = $this->person('Lock Owner');
        $item = $this->card($owner, ['due_at' => '2026-10-01']);

        $this->actingInTenantAs($owner)
            ->patchJson("/app/board/{$item->id}", ['due_at' => '2026-10-15'])
            ->assertStatus(422);

        $this->assertSame('2026-10-01', $item->fresh()->due_at?->format('Y-m-d'), 'due date changed through the web API');

        $item->refresh();
        $threw = false;
        try {
            $item->update(['due_at' => '2026-10-20']);
        } catch (Throwable) {
            $threw = true;
        }
        $this->assertTrue($threw, 'a stray model update changed a locked due date');
        $this->assertSame('2026-10-01', $item->fresh()->due_at?->format('Y-m-d'));
    }

    /** Check 2 (from S01): an audit row can be neither updated nor deleted, and there is no route that tries. */
    protected function assertAuditLogImmutable(): void
    {
        $actor = $this->person('Audit Actor', 'hr');
        $this->actingInTenantAs($actor);

        $row = AuditLog::create([
            'tenant_id' => $this->tenant()->id, 'user_id' => $actor->user_id,
            'actor_name' => $actor->name, 'action' => 'acceptance.probe', 'target' => 'probe',
        ]);

        foreach (['update' => fn () => $row->update(['action' => 'tampered']), 'delete' => fn () => $row->delete()] as $op => $attempt) {
            $threw = false;
            try {
                $attempt();
            } catch (Throwable) {
                $threw = true;
            }
            $this->assertTrue($threw, "audit_logs row accepted {$op}");
        }

        $this->assertDatabaseHas('audit_logs', ['id' => $row->id, 'action' => 'acceptance.probe']);

        foreach (['patch', 'put', 'delete'] as $verb) {
            $this->{$verb.'Json'}("/app/audit/{$row->id}", ['action' => 'tampered'])->assertStatus(404);
        }
        $this->assertDatabaseHas('audit_logs', ['id' => $row->id, 'action' => 'acceptance.probe']);
    }

    /** Check 3 (from S01): on a quiet day a plain staff dashboard has no band and the existing cards in their order. */
    protected function assertDashboardUnchanged(): void
    {
        Carbon::setTestNow(self::QUIET_DAY);
        $staff = $this->person('Quiet Staff');

        $response = $this->actingInTenantAs($staff)->get('/app/dash')->assertOk();

        $response->assertDontSee('uj-db-band', false);
        $response->assertSeeInOrder([
            'Current month summary',
            'Daily clock log',
            'Pending tasks',
            'My leave summary',
            'My calendar',
            'Notice board',
            'My claim summary',
            'My work summary',
        ]);

        Carbon::setTestNow();
    }

    /** Check 4 (from S01): "Keep it plain" strips confetti and the cheeky greeting on a day that would otherwise animate. */
    protected function assertKeepItPlainHonoured(): void
    {
        Carbon::setTestNow(self::QUIET_DAY);
        $viewer = $this->person('Plain Viewer');
        $this->person('Birthday Colleague', 'employee', ['date_of_birth' => '1990-09-08']);

        $loud = $this->actingInTenantAs($viewer)->get('/app/dash')->assertOk();
        $loud->assertSee('uj-db-band', false);
        $loud->assertSee('uj-db-confetti', false);

        $this->actingInTenantAs($viewer)->postJson('/app/dashboard/prefs', ['plain' => true])->assertOk();

        $plain = $this->actingInTenantAs($viewer)->get('/app/dash')->assertOk();
        $plain->assertSee('uj-db-band', false);
        $plain->assertDontSee('uj-db-confetti', false);
        $plain->assertSee('Good morning, Plain', false);

        Carbon::setTestNow();
    }
}
