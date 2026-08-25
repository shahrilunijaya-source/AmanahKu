<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Employee;
use App\Models\Project;
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
            'due_at' => '2026-07-01', 'assigned_by_id' => $mgr->id, 'assigned_at' => now(),
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
            'type' => 'adhoc', 'priority' => 'high', 'due_label' => 'Mon',
        ])->assertOk()->assertJsonPath('card.title', 'Renamed');

        $fresh = $item->fresh();
        $this->assertSame('Renamed', $fresh->title);
        $this->assertSame('adhoc', $fresh->type);
    }

    /** The drawer dropped estimate_hours from its UI (Stage 2), and Stage 4 dropped
     *  the column itself; a request still carrying the field is a stale client and
     *  is rejected outright rather than erroring on an unknown column. */
    public function test_update_rejects_estimate_hours(): void
    {
        $item = $this->card(['title' => 'Original']);

        $this->actingInTenant()->patchJson("/app/board/{$item->id}", [
            'title' => 'X', 'estimate_hours' => 99,
        ])->assertStatus(422)->assertJsonValidationErrors(['estimate_hours']);

        $this->assertSame('Original', $item->fresh()->title);
    }

    /** The drawer autosaves one field at a time, so a PATCH may carry only the
     *  field that changed — the rest of the card must be left untouched. */
    public function test_update_accepts_a_single_field_without_the_others(): void
    {
        $item = $this->card(['title' => 'Original', 'priority' => 'low', 'type' => 'task']);

        $this->actingInTenant()->patchJson("/app/board/{$item->id}", ['priority' => 'high'])
            ->assertOk()->assertJsonPath('card.priority', 'high');

        $fresh = $item->fresh();
        $this->assertSame('high', $fresh->priority);
        $this->assertSame('Original', $fresh->title);
        $this->assertSame('task', $fresh->type);
    }

    /** A participant on a shared (locked) card may still move it and comment — the
     *  drawer keeps those affordances even though it hides the editable fields. */
    public function test_locked_participant_can_move_and_comment_but_not_patch_properties(): void
    {
        $mgr = $this->manager('manager');
        $card = $this->ownedByManager($mgr, ['title' => 'Shared card']);
        $card->participants()->attach($this->employee->id);

        $this->actingInTenant()->patchJson("/app/board/{$card->id}", [
            'title' => 'Hijack', 'type' => 'task', 'priority' => 'low',
        ])->assertForbidden();

        $this->actingInTenant()->postJson("/app/board/{$card->id}/move", ['status' => 'prog'])->assertOk();
        $this->actingInTenant()->postJson("/app/board/{$card->id}/comments", ['body' => 'joining in'])->assertCreated();

        $this->assertSame('prog', $card->fresh()->status);
        $this->assertSame('Shared card', $card->fresh()->title);
    }

    public function test_owner_sets_labels_and_real_due_date(): void
    {
        $item = $this->card();

        $this->actingInTenant()->patchJson("/app/board/{$item->id}", [
            'title' => 'X', 'type' => 'task', 'priority' => 'low',
            'due_at' => '2026-08-01', 'labels' => ['blocked', 'client'],
        ])->assertOk()
            ->assertJsonPath('card.labels', ['blocked', 'client'])
            ->assertJsonPath('card.due_at', '2026-08-01')
            // The real date wins over any free-text label in the card face text.
            ->assertJsonPath('card.due_label', '01 Aug 2026');

        $fresh = $item->fresh();
        $this->assertSame(['blocked', 'client'], $fresh->labels);
        $this->assertSame('2026-08-01', $fresh->due_at->format('Y-m-d'));
    }

    public function test_links_column_casts_to_array(): void
    {
        $item = $this->card(['links' => [['label' => 'Doc', 'url' => 'https://example.com']]]);

        $this->assertSame([['label' => 'Doc', 'url' => 'https://example.com']], $item->fresh()->links);
    }

    public function test_owner_sets_links(): void
    {
        $item = $this->card();

        $this->actingInTenant()->patchJson("/app/board/{$item->id}", [
            'title' => 'X', 'type' => 'task', 'priority' => 'low',
            'links' => [
                ['label' => 'Doc', 'url' => 'https://example.com/doc'],
                ['label' => 'Meet', 'url' => 'https://example.com/meet'],
            ],
        ])->assertOk()->assertJsonPath('card.links', [
            ['label' => 'Doc', 'url' => 'https://example.com/doc'],
            ['label' => 'Meet', 'url' => 'https://example.com/meet'],
        ]);

        $this->assertSame([
            ['label' => 'Doc', 'url' => 'https://example.com/doc'],
            ['label' => 'Meet', 'url' => 'https://example.com/meet'],
        ], $item->fresh()->links);
    }

    public function test_link_missing_url_is_rejected(): void
    {
        $item = $this->card();

        $this->actingInTenant()->patchJson("/app/board/{$item->id}", [
            'title' => 'X', 'type' => 'task', 'priority' => 'low',
            'links' => [['label' => 'Doc', 'url' => '']],
        ])->assertStatus(422)->assertJsonValidationErrors(['links.0.url']);
    }

    public function test_blank_link_row_is_dropped_not_rejected(): void
    {
        $item = $this->card();

        $this->actingInTenant()->patchJson("/app/board/{$item->id}", [
            'title' => 'X', 'type' => 'task', 'priority' => 'low',
            'links' => [
                ['label' => '', 'url' => ''],
                ['label' => 'Doc', 'url' => 'https://example.com/doc'],
            ],
        ])->assertOk()->assertJsonPath('card.links', [
            ['label' => 'Doc', 'url' => 'https://example.com/doc'],
        ]);
    }

    public function test_more_than_twelve_links_is_rejected(): void
    {
        $item = $this->card();

        $links = array_map(fn ($n) => ['label' => "Link {$n}", 'url' => "https://example.com/{$n}"], range(1, 13));

        $this->actingInTenant()->patchJson("/app/board/{$item->id}", [
            'title' => 'X', 'type' => 'task', 'priority' => 'low',
            'links' => $links,
        ])->assertStatus(422)->assertJsonValidationErrors(['links']);
    }

    public function test_board_marks_overdue_open_cards_and_emits_label_data(): void
    {
        // Open past-due card: carries a label and gets the overdue marker.
        $this->card(['labels' => ['blocked'], 'due_at' => now()->subDay()->toDateString(), 'status' => 'todo']);
        // A Done card that is also past its date must NOT be flagged overdue.
        $this->card(['due_at' => now()->subDay()->toDateString(), 'status' => 'done', 'title' => 'Shipped']);

        $res = $this->actingInTenant()->get('/app/board')->assertOk();
        $res->assertSee('data-labels="blocked"', false);
        $res->assertSee('wc-when--over', false);
        // Exactly one overdue marker — the Done card is excluded.
        $this->assertSame(1, substr_count($res->getContent(), 'wc-when--over'));
    }

    public function test_board_emits_project_data_attribute_for_filtering(): void
    {
        $project = Project::create(['tenant_id' => $this->tenant->id, 'name' => 'KPT: RMS', 'is_active' => true]);
        $this->card(['project_id' => $project->id, 'title' => 'Booked card']);

        $this->actingInTenant()->get('/app/board')->assertOk()
            ->assertSee('data-project="'.$project->id.'"', false);
    }

    public function test_board_card_emits_priority_data_attribute(): void
    {
        $this->card(['priority' => 'high', 'title' => 'Priority card']);

        $this->actingInTenant()->get('/app/board')->assertOk()
            ->assertSee('data-priority="high"', false);
    }

    public function test_board_card_never_emits_owner_id_on_the_personal_board(): void
    {
        $this->card(['title' => 'No owner here']);

        $res = $this->actingInTenant()->get('/app/board')->assertOk();
        $this->assertStringNotContainsString('data-owner-id=', $res->getContent());
    }

    public function test_owner_books_a_card_to_a_project(): void
    {
        $project = Project::create(['tenant_id' => $this->tenant->id, 'name' => 'KPT: RMS', 'is_active' => true]);
        $item = $this->card();

        $this->actingInTenant()->patchJson("/app/board/{$item->id}", [
            'title' => 'X', 'type' => 'task', 'priority' => 'low', 'project_id' => $project->id,
        ])->assertOk()
            ->assertJsonPath('card.project.id', $project->id)
            ->assertJsonPath('card.project.name', 'KPT: RMS');

        $this->assertSame($project->id, (int) $item->fresh()->project_id);
    }

    public function test_project_from_another_tenant_is_rejected(): void
    {
        $otherTenant = Tenant::create(['slug' => 'other', 'name' => 'Other', 'initials' => 'OT']);
        $foreign = Project::create(['tenant_id' => $otherTenant->id, 'name' => 'Not yours', 'is_active' => true]);
        $item = $this->card();

        $this->actingInTenant()->patchJson("/app/board/{$item->id}", [
            'title' => 'X', 'type' => 'task', 'priority' => 'low', 'project_id' => $foreign->id,
        ])->assertStatus(422);
    }

    public function test_unknown_label_key_is_rejected(): void
    {
        $item = $this->card();

        $this->actingInTenant()->patchJson("/app/board/{$item->id}", [
            'title' => 'X', 'type' => 'task', 'priority' => 'low',
            'labels' => ['not-a-real-label'],
        ])->assertStatus(422);
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

    /** `director` is management-tier: effectiveRole() collapses it, so the raw slug must not lock it out. */
    public function test_director_assigns_tac_to_staff_board(): void
    {
        $director = $this->manager('director');

        $this->actingAsManager($director)->postJson("/app/board/assign/{$this->employee->id}", [
            'title' => 'Board paper', 'type' => 'adhoc', 'priority' => 'high', 'due_at' => '2026-07-01',
        ])->assertCreated();

        $this->assertDatabaseHas('work_items', [
            'employee_id' => $this->employee->id, 'assigned_by_id' => $director->id, 'title' => 'Board paper',
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
            'title' => 'Ping', 'type' => 'task', 'priority' => 'medium', 'due_at' => '2026-07-01',
        ])->assertCreated();

        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $this->user->id, 'title' => 'Mgr assigned you a task', 'body' => 'Ping',
        ]);
    }

    public function test_assign_accepts_links(): void
    {
        $mgr = $this->manager('management');

        $response = $this->actingAsManager($mgr)->postJson("/app/board/assign/{$this->employee->id}", [
            'title' => 'With links', 'type' => 'task', 'priority' => 'medium', 'due_at' => '2026-07-01',
            'links' => [['label' => 'Spec', 'url' => 'https://example.com/spec']],
        ]);
        $response->assertCreated();

        $item = WorkItem::where('title', 'With links')->firstOrFail();
        $this->assertSame([['label' => 'Spec', 'url' => 'https://example.com/spec']], $item->links);
    }

    /** Same rule update() applies (WorkItemController::isUntouchedLinkRow) — a row nobody filled in is dropped, not rejected. */
    public function test_assign_drops_a_blank_link_row(): void
    {
        $mgr = $this->manager('management');

        $response = $this->actingAsManager($mgr)->postJson("/app/board/assign/{$this->employee->id}", [
            'title' => 'Blank row', 'type' => 'task', 'priority' => 'medium', 'due_at' => '2026-07-01',
            'links' => [['label' => '', 'url' => '']],
        ]);
        $response->assertCreated();

        $item = WorkItem::where('title', 'Blank row')->firstOrFail();
        $this->assertSame([], $item->links);
    }

    public function test_assign_rejects_a_half_filled_link_row(): void
    {
        $mgr = $this->manager('management');

        $this->actingAsManager($mgr)
            ->from('/app/team-board')
            ->post("/app/board/assign/{$this->employee->id}", [
                'title' => 'Half link', 'type' => 'task', 'priority' => 'medium',
                'links' => [['label' => 'Spec', 'url' => '']],
            ])
            ->assertSessionHasErrors(['links.0.url'], null, 'assign');
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

    // ───────── Card participants: one shared card visible on many boards ─────────
    // A manager/HR includes people on a card they own; the same card then appears on
    // each included person's board. Participants may view / move / comment, but only
    // the owner (or a tac's assigner) may edit fields, set participants, or delete.

    /** A card owned by a privileged user, used as the sharing source. */
    private function ownedByManager(Employee $mgr, array $attrs = []): WorkItem
    {
        return $mgr->workItems()->create(array_merge([
            'tenant_id' => $this->tenant->id, 'title' => 'Team task', 'type' => 'task',
            'priority' => 'medium', 'status' => 'todo', 'progress' => 0,
            // Shared cards must carry a due date, so the sharing source always has one.
            'due_at' => '2026-07-01',
        ], $attrs));
    }

    public function test_manager_includes_people_and_the_pivot_persists(): void
    {
        $mgr = $this->manager('manager');
        $card = $this->ownedByManager($mgr);

        $this->actingAsManager($mgr)->patchJson("/app/board/{$card->id}", [
            'title' => 'Team task', 'type' => 'task', 'priority' => 'medium',
            'participant_ids' => [$this->employee->id],
        ])->assertOk()->assertJsonPath('card.participants.0.id', $this->employee->id);

        $this->assertDatabaseHas('work_item_participant', [
            'work_item_id' => $card->id, 'employee_id' => $this->employee->id,
        ]);
    }

    public function test_participant_sees_the_shared_card_on_their_own_board(): void
    {
        $mgr = $this->manager('manager');
        $card = $this->ownedByManager($mgr, ['title' => 'Shared deliverable']);
        $card->participants()->attach($this->employee->id);

        $this->actingInTenant()->get('/app/board')->assertOk()->assertSee('Shared deliverable');
    }

    public function test_participant_can_view_move_and_comment(): void
    {
        $mgr = $this->manager('manager');
        $card = $this->ownedByManager($mgr);
        $card->participants()->attach($this->employee->id);

        $this->actingInTenant()->getJson("/app/board/{$card->id}")->assertOk();
        $this->actingInTenant()->postJson("/app/board/{$card->id}/move", ['status' => 'prog'])->assertOk();
        $this->actingInTenant()->postJson("/app/board/{$card->id}/comments", ['body' => 'joining in'])->assertCreated();
    }

    public function test_participant_cannot_edit_or_delete_the_shared_card(): void
    {
        $mgr = $this->manager('manager');
        $card = $this->ownedByManager($mgr);
        $card->participants()->attach($this->employee->id);

        $this->actingInTenant()->patchJson("/app/board/{$card->id}", [
            'title' => 'Hijack', 'type' => 'task', 'priority' => 'low',
        ])->assertForbidden();
        $this->actingInTenant()->deleteJson("/app/board/{$card->id}")->assertForbidden();
    }

    public function test_added_participant_is_notified(): void
    {
        $mgr = $this->manager('manager');
        $card = $this->ownedByManager($mgr, ['title' => 'Notify me']);

        $this->actingAsManager($mgr)->patchJson("/app/board/{$card->id}", [
            'title' => 'Notify me', 'type' => 'task', 'priority' => 'medium',
            'participant_ids' => [$this->employee->id],
        ])->assertOk();

        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $this->user->id, 'title' => 'Mgr added you to a task', 'body' => 'Notify me',
        ]);
    }

    public function test_removing_a_participant_does_not_re_notify_the_survivors(): void
    {
        $mgr = $this->manager('manager');
        $card = $this->ownedByManager($mgr, ['title' => 'Keep me']);
        $card->participants()->attach($this->employee->id);
        AppNotification::query()->delete(); // clear the "added" notice from the attach above is N/A; start clean

        // Re-saving with the same participant must not fire a fresh notification.
        $this->actingAsManager($mgr)->patchJson("/app/board/{$card->id}", [
            'title' => 'Keep me', 'type' => 'task', 'priority' => 'medium',
            'participant_ids' => [$this->employee->id],
        ])->assertOk();

        $this->assertSame(0, AppNotification::where('user_id', $this->user->id)->count());
    }

    public function test_show_flags_manage_rights_so_participants_get_a_read_only_modal(): void
    {
        $mgr = $this->manager('manager');
        $card = $this->ownedByManager($mgr);
        $card->participants()->attach($this->employee->id);

        // The owner may manage the card.
        $this->actingAsManager($mgr)->getJson("/app/board/{$card->id}")
            ->assertOk()->assertJsonPath('card.can_manage', true);

        // A participant opens it read-only (move + comment only).
        $this->actingInTenant()->getJson("/app/board/{$card->id}")
            ->assertOk()->assertJsonPath('card.can_manage', false);
    }

    // Including people is open to every role — it is collaboration on a card you
    // already manage, not an assignment. The bound is canManage(), not the role.

    public function test_plain_employee_sets_participants_on_their_own_card(): void
    {
        $card = $this->card(['due_at' => '2026-07-01']); // owned by the plain employee
        $colleague = Employee::create(['tenant_id' => $this->tenant->id, 'name' => 'C', 'status' => 'active', 'workload' => 'green']);

        $this->actingInTenant()->patchJson("/app/board/{$card->id}", [
            'title' => 'X', 'type' => 'task', 'priority' => 'low',
            'participant_ids' => [$colleague->id],
        ])->assertOk();

        $this->assertDatabaseHas('work_item_participant', [
            'work_item_id' => $card->id, 'employee_id' => $colleague->id,
        ]);
    }

    /**
     * Every name the board shows is the display name (nickname, else legal name),
     * so the picker, the participant chips and the mention roster all read the way
     * people are actually addressed. `name` stays legal for payroll and documents.
     */
    public function test_the_board_shows_nicknames_not_legal_names(): void
    {
        $colleague = Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Muhammad Hakim bin Ali',
            'nickname' => 'hakime', 'status' => 'active', 'workload' => 'green',
        ]);
        $card = $this->card(['due_at' => '2026-07-01']);

        // The picker roster on the board screen. Its `search` haystack still carries
        // the legal name, so typing "muhammad" finds someone listed as "Hakime".
        $this->actingInTenant()->get('/app/board')->assertOk()
            ->assertViewHas('people', fn ($people) => $people->contains('name', 'Hakime')
                && $people->contains('search', 'hakime muhammad hakim bin ali'));

        // The participant chips on a saved card.
        $this->actingInTenant()->patchJson("/app/board/{$card->id}", [
            'participant_ids' => [$colleague->id],
        ])->assertOk()->assertJsonPath('card.participants.0.name', 'Hakime');

        // The mention roster in the detail drawer.
        $this->actingInTenant()->getJson("/app/board/{$card->id}")
            ->assertOk()->assertJsonPath('card.mentionable.0.name', 'Hakime');
    }

    public function test_a_director_sets_participants_on_their_own_card(): void
    {
        $director = $this->manager('director');
        $card = $this->ownedByManager($director);

        $this->actingAsManager($director)->patchJson("/app/board/{$card->id}", [
            'title' => 'Team task', 'type' => 'task', 'priority' => 'medium',
            'participant_ids' => [$this->employee->id],
        ])->assertOk()->assertJsonPath('card.participants.0.id', $this->employee->id);
    }

    public function test_setting_participants_on_someone_elses_card_is_still_forbidden(): void
    {
        $mgr = $this->manager('manager');
        $card = $this->ownedByManager($mgr);
        $colleague = Employee::create(['tenant_id' => $this->tenant->id, 'name' => 'C', 'status' => 'active', 'workload' => 'green']);

        $this->actingInTenant()->patchJson("/app/board/{$card->id}", [
            'title' => 'Team task', 'type' => 'task', 'priority' => 'medium',
            'participant_ids' => [$colleague->id],
        ])->assertForbidden();

        $this->assertDatabaseMissing('work_item_participant', ['work_item_id' => $card->id]);
    }

    // ── Manager edit rights, bounded by data scope ────────────────────────────

    /** Attach a membership with an explicit data scope, which manager() leaves at the default. */
    private function scopedManager(string $scope): Employee
    {
        $u = User::create(['name' => 'Scoped', 'email' => 'scoped@example.com', 'password' => Hash::make('password')]);
        $u->tenants()->attach($this->tenant->id, ['role' => 'manager', 'data_scope' => $scope]);

        return Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $u->id,
            'name' => 'Scoped', 'status' => 'active', 'workload' => 'green',
        ]);
    }

    public function test_a_company_scoped_manager_may_edit_someone_elses_card(): void
    {
        $mgr = $this->scopedManager('company');
        $card = $this->card(['due_at' => '2026-07-01']); // owned by the plain employee, no assigner

        $this->actingAsManager($mgr)
            ->patchJson("/app/board/{$card->id}", ['title' => 'Manager edited this'])
            ->assertOk();

        $this->assertSame('Manager edited this', $card->fresh()->title);
    }

    public function test_a_team_scoped_manager_may_edit_a_direct_reports_card(): void
    {
        $mgr = $this->scopedManager('team');
        $this->employee->update(['reports_to_id' => $mgr->id]);
        $card = $this->card();

        $this->actingAsManager($mgr)
            ->patchJson("/app/board/{$card->id}", ['title' => 'In my line'])
            ->assertOk();

        $this->assertSame('In my line', $card->fresh()->title);
    }

    /**
     * The hole this guards (AK-AUTHZ-01): a bare role check would let any manager in
     * the tenant edit any card, including one belonging to a branch or department
     * they cannot otherwise see.
     */
    public function test_a_team_scoped_manager_may_not_edit_a_card_outside_their_line(): void
    {
        $mgr = $this->scopedManager('team');
        // $this->employee does not report to $mgr.
        $card = $this->card(['title' => 'Untouched']);

        $this->actingAsManager($mgr)
            ->patchJson("/app/board/{$card->id}", ['title' => 'Should not land'])
            ->assertForbidden();

        $this->assertSame('Untouched', $card->fresh()->title);
    }

    public function test_the_drawers_lock_state_agrees_with_the_write_gate(): void
    {
        $mgr = $this->scopedManager('team');
        $card = $this->card();

        // Out of scope the card is not theirs to see at all, so it is refused on the
        // way in rather than opening read-only. Read and write agree.
        $this->actingAsManager($mgr)->getJson("/app/board/{$card->id}")->assertForbidden();
        $this->actingAsManager($mgr)->patchJson("/app/board/{$card->id}", ['title' => 'No'])
            ->assertForbidden();

        // Bring the owner into scope: the card opens editable, and the write lands.
        $this->employee->update(['reports_to_id' => $mgr->id]);

        $this->actingAsManager($mgr)->getJson("/app/board/{$card->id}")
            ->assertOk()->assertJsonPath('card.can_manage', true);
        $this->actingAsManager($mgr)->patchJson("/app/board/{$card->id}", ['title' => 'Yes'])
            ->assertOk();

        $this->assertSame('Yes', $card->fresh()->title);
    }

    public function test_an_ordinary_employee_gains_nothing_from_this(): void
    {
        $colleague = Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Colleague', 'status' => 'active', 'workload' => 'green',
        ]);
        $card = $colleague->workItems()->create([
            'tenant_id' => $this->tenant->id, 'title' => 'Theirs', 'type' => 'task',
            'priority' => 'low', 'status' => 'todo', 'progress' => 0,
        ]);

        $this->actingInTenant()
            ->patchJson("/app/board/{$card->id}", ['title' => 'Nope'])
            ->assertForbidden();

        $this->assertSame('Theirs', $card->fresh()->title);
    }

    // ── Task 3: the read grant ──────────────────────────────────────────────
    // canSeeAll() widens who may VIEW a card (management, HR, or an immediate
    // superior), still bounded by coversCardOwner() exactly like the edit grant.
    // Nothing here should ever let anyone EDIT more than canManage() already did.

    public function test_a_director_may_open_but_not_edit_another_persons_card(): void
    {
        $director = $this->manager('director');
        $card = $this->card(['title' => 'Not the directors']); // owned by the plain employee

        $this->actingAsManager($director)->getJson("/app/board/{$card->id}")
            ->assertOk()->assertJsonPath('card.can_manage', false);

        $this->actingAsManager($director)
            ->patchJson("/app/board/{$card->id}", ['title' => 'Hijack'])
            ->assertForbidden();

        $this->assertSame('Not the directors', $card->fresh()->title);
    }

    public function test_hr_may_open_another_persons_card_read_only(): void
    {
        $hr = $this->manager('hr');
        $card = $this->card();

        $this->actingAsManager($hr)->getJson("/app/board/{$card->id}")
            ->assertOk()->assertJsonPath('card.can_manage', false);
    }

    /**
     * The hole this guards (AK-AUTHZ-01, reintroduced through the view grant instead
     * of the edit grant): the `manager` role alone passes canSeeAll(), so without the
     * coversCardOwner() check a team-scoped manager could open any card in the
     * tenant just by knowing its id. Their role passes canSeeAll() — this proves the
     * DataScope check, not the role check, is what stops them.
     */
    public function test_a_team_scoped_manager_may_not_open_a_card_outside_their_line(): void
    {
        $mgr = $this->scopedManager('team');
        // $this->employee does not report to $mgr.
        $card = $this->card(['title' => 'Outside the line']);

        $this->actingAsManager($mgr)->getJson("/app/board/{$card->id}")
            ->assertForbidden();

        $this->assertSame('Outside the line', $card->fresh()->title);
    }

    public function test_an_employee_with_no_reports_may_not_open_a_colleagues_card(): void
    {
        $colleague = Employee::create(['tenant_id' => $this->tenant->id, 'name' => 'Colleague', 'status' => 'active', 'workload' => 'green']);
        $card = $colleague->workItems()->create([
            'tenant_id' => $this->tenant->id, 'title' => 'Theirs', 'type' => 'task',
            'priority' => 'low', 'status' => 'todo', 'progress' => 0,
        ]);

        $this->actingInTenant()->getJson("/app/board/{$card->id}")->assertForbidden();
    }

    /** This is the canSeeAll() fallback clause: an 'employee'-role user with at
     *  least one direct report still qualifies, with no role change needed. */
    public function test_an_employee_with_a_direct_report_may_open_that_reports_card(): void
    {
        $report = Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Report', 'status' => 'active',
            'workload' => 'green', 'reports_to_id' => $this->employee->id,
        ]);
        $card = $report->workItems()->create([
            'tenant_id' => $this->tenant->id, 'title' => 'From my report', 'type' => 'task',
            'priority' => 'low', 'status' => 'todo', 'progress' => 0,
        ]);

        $this->actingInTenant()->getJson("/app/board/{$card->id}")
            ->assertOk()->assertJsonPath('card.can_manage', false);
    }

    public function test_team_board_still_403s_for_an_unprivileged_user(): void
    {
        // The base actor is a plain 'employee' with no direct reports — canSeeAll()
        // rejects them, so the screen itself must still 403.
        $this->actingInTenant()->get('/app/team-board')->assertForbidden();
    }
}
