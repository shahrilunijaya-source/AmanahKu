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

    public function test_done_gate_refuses_a_parent_with_an_open_child(): void
    {
        $parent = $this->parent();
        $this->child($parent);
        $this->child($parent, ['status' => 'done']);

        try {
            app(BoardRules::class)->assertChildrenDoneForStatus($parent, 'done');
            $this->fail('expected a ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame('1 subtask still open. Tick it off before moving this card to Done.', $e->errors()['status'][0]);
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
}
