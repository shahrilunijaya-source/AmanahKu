<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * A card that involves anyone but its owner must carry a due date: a task
 * assigned onto someone's board, or a card shared with participants. A card
 * you keep to yourself needs none.
 *
 * The rule is enforced against the state a request would leave behind rather
 * than the request body, because the drawer autosaves one field at a time —
 * see WorkItemController::update().
 */
class WorkItemDueDateTest extends TestCase
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

    private function actingInTenant(?User $as = null): self
    {
        $this->actingAs($as ?? $this->user)->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    private function person(string $name, string $email, string $role = 'employee'): Employee
    {
        $u = User::create(['name' => $name, 'email' => $email, 'password' => Hash::make('password')]);
        $u->tenants()->attach($this->tenant->id, ['role' => $role]);

        return Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $u->id,
            'name' => $name, 'status' => 'active', 'workload' => 'green',
        ]);
    }

    private function card(array $attrs = []): WorkItem
    {
        return $this->employee->workItems()->create(array_merge([
            'tenant_id' => $this->tenant->id, 'title' => 'X', 'type' => 'task',
            'priority' => 'low', 'status' => 'todo', 'progress' => 0,
        ], $attrs));
    }

    public function test_assigning_a_task_without_a_due_date_is_rejected(): void
    {
        $mgr = $this->person('Mgr', 'mgr@example.com', 'manager');

        $this->actingInTenant(User::find($mgr->user_id))
            ->postJson("/app/board/assign/{$this->employee->id}", [
                'title' => 'Ship it', 'type' => 'adhoc', 'priority' => 'high',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('due_at');

        $this->assertSame(0, WorkItem::where('title', 'Ship it')->count());
    }

    public function test_assigning_a_task_with_a_due_date_succeeds(): void
    {
        $mgr = $this->person('Mgr', 'mgr@example.com', 'manager');

        $this->actingInTenant(User::find($mgr->user_id))
            ->postJson("/app/board/assign/{$this->employee->id}", [
                'title' => 'Ship it', 'type' => 'adhoc', 'priority' => 'high',
                'due_at' => '2026-09-30',
            ])
            ->assertCreated();

        $this->assertNotNull(WorkItem::where('title', 'Ship it')->firstOrFail()->due_at);
    }

    public function test_adding_a_participant_to_a_card_without_a_due_date_is_rejected(): void
    {
        $alice = $this->person('Alice', 'alice@example.com');
        $card = $this->card();

        $this->actingInTenant()
            ->patchJson("/app/board/{$card->id}", ['participant_ids' => [$alice->id]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('due_at');

        $this->assertSame(0, $card->participants()->count());
    }

    public function test_adding_a_participant_to_a_card_with_a_due_date_succeeds(): void
    {
        $alice = $this->person('Alice', 'alice@example.com');
        $card = $this->card(['due_at' => '2026-09-30']);

        $this->actingInTenant()
            ->patchJson("/app/board/{$card->id}", ['participant_ids' => [$alice->id]])
            ->assertOk();

        $this->assertSame(1, $card->participants()->count());
    }

    public function test_clearing_the_due_date_on_a_shared_card_is_rejected(): void
    {
        $alice = $this->person('Alice', 'alice@example.com');
        $card = $this->card(['due_at' => '2026-09-30']);
        $card->participants()->attach($alice->id);

        $this->actingInTenant()
            ->patchJson("/app/board/{$card->id}", ['due_at' => null])
            ->assertStatus(422)
            ->assertJsonValidationErrors('due_at');

        $this->assertNotNull($card->fresh()->due_at);
    }

    public function test_clearing_the_due_date_on_an_assigned_task_is_rejected(): void
    {
        $mgr = $this->person('Mgr', 'mgr@example.com', 'manager');
        $card = $this->card(['due_at' => '2026-09-30', 'assigned_by_id' => $mgr->id, 'assigned_at' => now()]);

        $this->actingInTenant(User::find($mgr->user_id))
            ->patchJson("/app/board/{$card->id}", ['due_at' => null])
            ->assertStatus(422)
            ->assertJsonValidationErrors('due_at');

        $this->assertNotNull($card->fresh()->due_at);
    }

    public function test_a_solo_card_needs_no_due_date(): void
    {
        $card = $this->card();

        $this->actingInTenant()
            ->patchJson("/app/board/{$card->id}", ['priority' => 'high'])
            ->assertOk();

        $this->assertSame('high', $card->fresh()->priority);
    }

    public function test_dropping_the_last_participant_leaves_the_card_editable_without_a_due(): void
    {
        $alice = $this->person('Alice', 'alice@example.com');
        $card = $this->card(['due_at' => '2026-09-30']);
        $card->participants()->attach($alice->id);

        $this->actingInTenant()
            ->patchJson("/app/board/{$card->id}", ['participant_ids' => []])
            ->assertOk();

        $this->actingInTenant()
            ->patchJson("/app/board/{$card->id}", ['due_at' => null])
            ->assertOk();

        $this->assertNull($card->fresh()->due_at);
    }
}
