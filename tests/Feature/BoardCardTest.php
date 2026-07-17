<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Trello-style card detail, drag-reorder, and comment thread. */
class BoardCardTest extends TestCase
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

    private function card(array $attrs = []): WorkItem
    {
        return $this->employee->workItems()->create(array_merge([
            'tenant_id' => $this->tenant->id, 'title' => 'X', 'type' => 'task',
            'priority' => 'low', 'status' => 'todo', 'progress' => 0,
        ], $attrs));
    }

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

    /** A second distinct privileged user+employee (different email from manager()). */
    private function manager2(string $role = 'manager'): Employee
    {
        $u = User::create(['name' => 'Mgr2', 'email' => 'mgr2@example.com', 'password' => Hash::make('password')]);
        $u->tenants()->attach($this->tenant->id, ['role' => $role]);

        return Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $u->id,
            'name' => 'Mgr2', 'status' => 'active', 'workload' => 'green',
        ]);
    }

    private function actingAsManager(Employee $mgr): self
    {
        $this->actingAs(User::find($mgr->user_id))->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    /** Make a tac owned by $this->employee, assigned by a fresh manager. */
    private function tac(Employee $mgr, array $attrs = []): WorkItem
    {
        return $this->employee->workItems()->create(array_merge([
            'tenant_id' => $this->tenant->id, 'title' => 'T', 'type' => 'adhoc',
            'priority' => 'medium', 'status' => 'todo', 'progress' => 0,
            'assigned_by_id' => $mgr->id, 'assigned_at' => now(),
        ], $attrs));
    }

    public function test_inline_add_returns_card_json(): void
    {
        $this->actingInTenant()->postJson('/app/board', [
            'title' => 'Quick card', 'type' => 'assignment', 'priority' => 'medium', 'status' => 'prog',
        ])->assertCreated()->assertJsonPath('card.title', 'Quick card')->assertJsonPath('card.status', 'prog');

        $this->assertDatabaseHas('work_items', ['title' => 'Quick card', 'status' => 'prog']);
    }

    public function test_show_returns_detail_and_comments(): void
    {
        $item = $this->card(['description' => 'Body text']);
        $item->comments()->create(['tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id, 'body' => 'First note']);

        $this->actingInTenant()->getJson("/app/board/{$item->id}")
            ->assertOk()
            ->assertJsonPath('card.description', 'Body text')
            ->assertJsonPath('comments.0.body', 'First note')
            ->assertJsonPath('comments.0.mine', true);
    }

    public function test_owner_updates_card_fields(): void
    {
        $item = $this->card();

        $this->actingInTenant()->patchJson("/app/board/{$item->id}", [
            'title' => 'Renamed', 'description' => 'Now with detail',
            'type' => 'adhoc', 'priority' => 'high', 'due_label' => 'Mon', 'estimate_hours' => 6,
        ])->assertOk()->assertJsonPath('card.title', 'Renamed');

        $fresh = $item->fresh();
        $this->assertSame('Renamed', $fresh->title);
        $this->assertSame('adhoc', $fresh->type);
        $this->assertSame(6, (int) $fresh->estimate_hours);
    }

    public function test_drag_reorders_destination_column(): void
    {
        $a = $this->card(['title' => 'A', 'status' => 'todo', 'sort_order' => 0]);
        $b = $this->card(['title' => 'B', 'status' => 'todo', 'sort_order' => 1]);

        // Drag A to "prog" and place it after B's slot: ids reflect the new order.
        $this->actingInTenant()->postJson("/app/board/{$a->id}/move", [
            'status' => 'prog', 'ids' => [$b->id, $a->id],
        ])->assertOk()->assertJsonPath('status', 'prog');

        $this->assertSame('prog', $a->fresh()->status);
        $this->assertSame(0, (int) $b->fresh()->sort_order);
        $this->assertSame(1, (int) $a->fresh()->sort_order);
    }

    public function test_owner_comments_and_deletes_own_comment(): void
    {
        $item = $this->card();

        $res = $this->actingInTenant()->postJson("/app/board/{$item->id}/comments", ['body' => 'Hello'])
            ->assertCreated()->assertJsonPath('count', 1);
        $commentId = $res->json('comment.id');

        $this->assertDatabaseHas('work_item_comments', ['work_item_id' => $item->id, 'body' => 'Hello']);

        $this->actingInTenant()->deleteJson("/app/board/comments/{$commentId}")
            ->assertOk()->assertJsonPath('count', 0);
        $this->assertDatabaseMissing('work_item_comments', ['id' => $commentId]);
    }

    public function test_cannot_view_or_edit_another_employees_card(): void
    {
        $colleague = Employee::create(['tenant_id' => $this->tenant->id, 'name' => 'Other', 'status' => 'active', 'workload' => 'green']);
        $item = $colleague->workItems()->create(['tenant_id' => $this->tenant->id, 'title' => 'Y', 'type' => 'task', 'priority' => 'low', 'status' => 'todo', 'progress' => 0]);

        $this->actingInTenant()->getJson("/app/board/{$item->id}")->assertForbidden();
        $this->actingInTenant()->patchJson("/app/board/{$item->id}", ['title' => 'Hijack', 'type' => 'task', 'priority' => 'low'])->assertForbidden();
        $this->actingInTenant()->deleteJson("/app/board/{$item->id}")->assertForbidden();
        $this->actingInTenant()->postJson("/app/board/{$item->id}/comments", ['body' => 'sneak'])->assertForbidden();
    }

    public function test_cannot_delete_another_employees_comment(): void
    {
        $item = $this->card();
        $colleague = Employee::create(['tenant_id' => $this->tenant->id, 'name' => 'Other', 'status' => 'active', 'workload' => 'green']);
        $comment = $item->comments()->create(['tenant_id' => $this->tenant->id, 'employee_id' => $colleague->id, 'body' => 'theirs']);

        $this->actingInTenant()->deleteJson("/app/board/comments/{$comment->id}")->assertForbidden();
        $this->assertDatabaseHas('work_item_comments', ['id' => $comment->id]);
    }

    public function test_owner_deletes_own_card(): void
    {
        $item = $this->card();

        $this->actingInTenant()->deleteJson("/app/board/{$item->id}")->assertOk();
        $this->assertDatabaseMissing('work_items', ['id' => $item->id]);
    }

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
        $colleague = Employee::create(['tenant_id' => $this->tenant->id, 'name' => 'C', 'status' => 'active', 'workload' => 'green']);

        $this->actingInTenant()->postJson("/app/board/assign/{$colleague->id}", [
            'title' => 'No', 'type' => 'adhoc', 'priority' => 'low',
        ])->assertForbidden();
    }

    public function test_assign_validation_errors_use_named_bag(): void
    {
        $mgr = $this->manager('manager');

        $this->actingAsManager($mgr)
            ->from("/app/profile?emp={$this->employee->id}")
            ->post("/app/board/assign/{$this->employee->id}", ['title' => '', 'type' => 'adhoc', 'priority' => 'low'])
            ->assertSessionHasErrors(['title'], null, 'assign');
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

    public function test_third_party_cannot_edit_or_delete_a_tac(): void
    {
        $mgr = $this->manager('manager');
        $item = $this->tac($mgr);
        // A different privileged manager who is neither the assignee nor the assigner.
        $other = $this->manager2('management');

        $this->actingAs(User::find($other->user_id))->withSession(['current_tenant' => $this->tenant->id])
            ->patchJson("/app/board/{$item->id}", ['title' => 'X', 'type' => 'adhoc', 'priority' => 'low'])
            ->assertForbidden();
        $this->actingAs(User::find($other->user_id))->withSession(['current_tenant' => $this->tenant->id])
            ->deleteJson("/app/board/{$item->id}")->assertForbidden();
    }

    public function test_assigned_tac_shows_its_due_date_on_the_card(): void
    {
        $mgr = $this->manager('manager');
        // A tac carries a real due_at and no free-text label.
        $item = $this->tac($mgr, ['due_at' => '2026-07-01', 'due_label' => null]);

        $this->actingInTenant()->getJson("/app/board/{$item->id}")
            ->assertOk()
            ->assertJsonPath('card.due_label', '01 Jul 2026');
    }

    public function test_moving_a_tac_to_done_notifies_the_assigner(): void
    {
        $mgr = $this->manager('manager');
        $item = $this->tac($mgr, ['title' => 'Wrap up']);

        $this->actingInTenant()->postJson("/app/board/{$item->id}/move", ['status' => 'done'])->assertOk();

        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $mgr->user_id, 'title' => $this->employee->name.' completed: Wrap up',
        ]);
        // Must land on the assigner, never the assignee.
        $this->assertDatabaseMissing('app_notifications', ['user_id' => $this->user->id]);
        // Moving it again must not duplicate the notification.
        $this->actingInTenant()->postJson("/app/board/{$item->id}/move", ['status' => 'done'])->assertOk();
        $this->assertSame(1, AppNotification::where('title', $this->employee->name.' completed: Wrap up')->count());
    }
}
