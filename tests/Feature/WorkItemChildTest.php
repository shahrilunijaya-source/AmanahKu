<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Scopes\ParentOnly;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkItem;
use App\Models\WorkItemProgressStint;
use App\Support\BoardRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
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

    private User $assignee;

    private Employee $assigneeEmp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);

        [$this->owner, $this->ownerEmp] = $this->person('Owner', 'owner@example.com');
        [$this->participant, $this->participantEmp] = $this->person('Pat', 'pat@example.com');
        [$this->stranger] = $this->person('Stranger', 'stranger@example.com');
        [$this->assignee, $this->assigneeEmp] = $this->person('Alex', 'alex@example.com');
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

    public function test_deleting_a_child_keeps_the_parent_and_returns_its_refreshed_face(): void
    {
        $parent = $this->parent();
        $child = $this->child($parent);
        $this->child($parent, ['status' => 'done']);

        $response = $this->as($this->owner)->deleteJson("/app/board/{$child->id}")->assertOk();

        $this->assertNotNull($parent->fresh());
        $this->assertNull(WorkItem::withoutGlobalScope(ParentOnly::class)->find($child->id));
        $this->assertStringContainsString('1/1', $response->json('parent_html'));
    }

    public function test_deleting_a_parent_returns_no_parent_face(): void
    {
        $parent = $this->parent();

        $this->as($this->owner)->deleteJson("/app/board/{$parent->id}")->assertOk()->assertJson(['parent_html' => null]);
    }

    public function test_done_gate_refuses_a_parent_with_an_open_child(): void
    {
        $parent = $this->parent();
        $this->child($parent, ['title' => 'Open one']);
        $this->child($parent, ['status' => 'done']);

        try {
            app(BoardRules::class)->assertChildrenDoneForStatus($parent, 'done');
            $this->fail('expected a ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame('Still open: Open one. Tick them off before moving this card to Done.', $e->errors()['status'][0]);
        }

        // Other columns carry no gate.
        app(BoardRules::class)->assertChildrenDoneForStatus($parent, 'review');
        $this->assertTrue(true);
    }

    public function test_done_gate_passes_once_every_child_is_done(): void
    {
        $parent = $this->parent();
        $this->child($parent, ['status' => 'done']);

        app(BoardRules::class)->assertChildrenDoneForStatus($parent, 'done');
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
        Queue::fake();
        $parent = $this->parent();
        $child = $this->child($parent, ['due_at' => now()->addDay()]);
        $child->update(['status' => 'done']);

        $this->assertSame(0, WorkItemProgressStint::withoutGlobalScope('tenant')->where('work_item_id', $child->id)->count());
        Queue::assertNothingPushed();
    }

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

    public function test_moving_a_parent_to_done_is_refused_while_a_child_is_open(): void
    {
        $parent = $this->parent();
        $this->child($parent, ['title' => 'A']);
        $this->child($parent, ['title' => 'B']);

        $this->as($this->owner)->postJson("/app/board/{$parent->id}/move", ['status' => 'done'])
            ->assertStatus(422)
            ->assertJsonPath('errors.status.0', 'Still open: A, B. Tick them off before moving this card to Done.');
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

    // ───────── CR-05: owner/helpers, due date, auto In Review, Done-guard names ─────────

    public function test_a_child_can_be_given_its_own_assignee_due_date_and_helpers_on_creation(): void
    {
        $parent = $this->parent();

        $res = $this->as($this->owner)->postJson('/app/board', [
            'title' => 'Do the thing',
            'parent_id' => $parent->id,
            'employee_id' => $this->assigneeEmp->id,
            'due_at' => now()->addDays(3)->toDateString(),
            'helper_ids' => [$this->participantEmp->id],
        ])->assertCreated();

        $child = WorkItem::withoutGlobalScope(ParentOnly::class)->find($res->json('card.id'));
        $this->assertSame($this->assigneeEmp->id, $child->employee_id);
        $this->assertNotNull($child->due_at);
        $this->assertSame([$this->participantEmp->id], $child->participants()->pluck('employees.id')->all());
    }

    public function test_a_subtask_assigned_elsewhere_appears_on_the_assignees_board_not_duplicated_on_the_owners(): void
    {
        $parent = $this->parent(['title' => 'Big rock']);
        $this->child($parent, ['title' => 'Farmed out', 'employee_id' => $this->assigneeEmp->id]);

        // On the assignee's own board it shows as a normal card, muted-prefixed.
        $assigneeView = $this->as($this->assignee)->get('/app/board')->assertOk();
        $assigneeView->assertSee('Farmed out');
        $assigneeView->assertSee('Subtask of Big rock');

        // The owner's board never lists it as a standalone card (only the "1/1" badge on Big rock).
        $ownerView = $this->as($this->owner)->get('/app/board')->assertOk();
        $ownerView->assertDontSee('Farmed out');
    }

    public function test_a_child_left_at_the_parents_own_owner_still_never_appears_standalone(): void
    {
        $parent = $this->parent();
        $this->child($parent, ['title' => 'Same owner child']);

        $this->as($this->owner)->get('/app/board')->assertOk()->assertDontSee('Same owner child');
    }

    public function test_parent_face_shows_the_earliest_overdue_open_subtask_date_in_red(): void
    {
        $parent = $this->parent(['title' => 'Overdue parent']);
        $this->child($parent, ['title' => 'Late one', 'due_at' => now()->subDays(5)]);
        $this->child($parent, ['title' => 'Later one', 'due_at' => now()->subDays(1)]);
        // Done subtasks past due don't count, neither does a future one.
        $this->child($parent, ['title' => 'Done late', 'status' => 'done', 'due_at' => now()->subDays(9)]);
        $this->child($parent, ['title' => 'Future', 'due_at' => now()->addDays(9)]);

        $res = $this->as($this->owner)->get('/app/board')->assertOk();

        $res->assertSee('wc-sub-overdue', false);
        $res->assertSee(now()->subDays(5)->format('d M'));
    }

    public function test_last_child_done_moves_parent_to_review_and_notifies_owner_and_assigner(): void
    {
        $parent = $this->ownerEmp->workItems()->create([
            'tenant_id' => $this->tenant->id, 'title' => 'Assigned parent', 'type' => 'task',
            'priority' => 'low', 'status' => 'prog', 'progress' => 0,
            'assigned_by_id' => $this->assigneeEmp->id, 'assigned_at' => now(), 'due_at' => now()->addWeek(),
        ]);
        $this->child($parent, ['status' => 'done']);
        $open = $this->child($parent);

        $this->as($this->owner)->postJson("/app/board/{$open->id}/move", ['status' => 'done'])->assertOk();

        $this->assertSame('review', $parent->fresh()->status);
        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $this->owner->id,
            'title' => 'Owner finished the last subtask of: Assigned parent',
        ]);
        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $this->assignee->id,
            'title' => 'Owner finished the last subtask of: Assigned parent',
        ]);
    }

    public function test_a_parent_already_past_todo_prog_is_not_pulled_back_to_review(): void
    {
        $parent = $this->parent(['status' => 'review']);
        $child = $this->child($parent);

        $this->as($this->owner)->postJson("/app/board/{$child->id}/move", ['status' => 'done'])->assertOk();

        $this->assertSame('review', $parent->fresh()->status);
    }

    public function test_deleting_the_last_open_child_does_not_move_the_parent_to_review(): void
    {
        $parent = $this->parent();
        $child = $this->child($parent);

        $this->as($this->owner)->deleteJson("/app/board/{$child->id}")->assertOk();

        $this->assertSame('todo', $parent->fresh()->status);
        $this->assertSame(0, DB::table('app_notifications')->where('title', 'like', '%finished the last subtask%')->count());
    }

    public function test_done_guard_message_lists_open_subtask_titles_capped_at_five(): void
    {
        $parent = $this->parent();
        foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $t) {
            $this->child($parent, ['title' => $t]);
        }

        try {
            app(BoardRules::class)->assertChildrenDoneForStatus($parent, 'done');
            $this->fail('expected a ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(
                'Still open: A, B, C, D, E, +1 more. Tick them off before moving this card to Done.',
                $e->errors()['status'][0],
            );
        }
    }

    public function test_deleting_a_subtask_writes_an_audit_log_entry(): void
    {
        $parent = $this->parent(['title' => 'Parent card']);
        $child = $this->child($parent, ['title' => 'Doomed subtask']);

        $this->as($this->owner)->deleteJson("/app/board/{$child->id}")->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'Deleted subtask',
            'target' => 'Doomed subtask (of Parent card)',
        ]);
    }

    public function test_a_participant_of_the_parent_can_move_a_subtask_assigned_to_them(): void
    {
        $parent = $this->parent(['due_at' => now()->addWeek()]);
        $child = $this->child($parent, ['employee_id' => $this->assigneeEmp->id]);

        $this->as($this->assignee)->postJson("/app/board/{$child->id}/move", ['status' => 'done'])->assertOk();
        $this->assertSame('done', WorkItem::withoutGlobalScope(ParentOnly::class)->find($child->id)->status);
    }
}
