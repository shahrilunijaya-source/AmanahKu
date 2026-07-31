<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Team board data shape: flat rows plus per-person aggregates.
 * Follows the fixture pattern established in BoardCardTest.
 */
class TeamBoardDataTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $managerUser;

    private Employee $managerEmployee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);

        // A manager user who can see the team board.
        $this->managerUser = User::create(['name' => 'Boss', 'email' => 'boss@example.com', 'password' => Hash::make('password')]);
        $this->managerUser->tenants()->attach($this->tenant->id, ['role' => 'manager']);
        $this->managerEmployee = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->managerUser->id,
            'name' => 'Boss', 'status' => 'active', 'workload' => 'green',
        ]);
    }

    private function actingAsManager(): self
    {
        $this->actingAs($this->managerUser)->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    /** Create an employee (no user account needed for the team board). */
    private function makeEmployee(string $name, array $attrs = []): Employee
    {
        return Employee::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'name' => $name, 'status' => 'active', 'workload' => 'green',
        ], $attrs));
    }

    /** Create a work item for a given employee. */
    private function makeCard(Employee $employee, array $attrs = []): WorkItem
    {
        return $employee->workItems()->create(array_merge([
            'tenant_id' => $this->tenant->id, 'title' => 'Card', 'type' => 'task',
            'priority' => 'low', 'status' => 'todo', 'progress' => 0,
        ], $attrs));
    }

    public function test_payload_carries_flat_rows_and_people(): void
    {
        // Give the manager at least one card so they show up in results.
        $this->makeCard($this->managerEmployee);

        $response = $this->actingAsManager()->get('/app/team-board');
        $response->assertOk();

        $data = $response->viewData('teamRows');
        $this->assertNotNull($data, 'teamRows must be present in the view data');

        $people = $response->viewData('teamPeople');
        $this->assertNotNull($people, 'teamPeople must be present in the view data');

        // teamOpenTotal and teamPeopleCount must also exist.
        $this->assertNotNull($response->viewData('teamOpenTotal'));
        $this->assertNotNull($response->viewData('teamPeopleCount'));
    }

    public function test_aggregates_match_the_rows(): void
    {
        $alice = $this->makeEmployee('Alice');

        // 1. Overdue card (due yesterday, status todo)
        $this->makeCard($alice, [
            'title' => 'Overdue only', 'status' => 'todo',
            'due_at' => now()->subDay()->toDateString(), 'labels' => [],
        ]);
        // 2. Blocked card (not overdue)
        $this->makeCard($alice, [
            'title' => 'Blocked only', 'status' => 'prog',
            'labels' => ['blocked'],
        ]);
        // 3. Both overdue AND blocked
        $this->makeCard($alice, [
            'title' => 'Overdue and blocked', 'status' => 'todo',
            'due_at' => now()->subDays(3)->toDateString(), 'labels' => ['blocked'],
        ]);
        // 4. Done card
        $this->makeCard($alice, [
            'title' => 'Done card', 'status' => 'done',
        ]);
        // 5. In-review card
        $this->makeCard($alice, [
            'title' => 'Review card', 'status' => 'review',
        ]);

        $response = $this->actingAsManager()->get('/app/team-board');
        $response->assertOk();

        $teamRows = $response->viewData('teamRows');
        $teamPeople = $response->viewData('teamPeople');

        // Find Alice's rows and aggregate independently from teamRows.
        $aliceRows = $teamRows->filter(fn ($r) => $r['owner_id'] === $alice->id);
        $this->assertCount(5, $aliceRows, 'Alice should have 5 cards');

        // Recount from the rows (the test's own independent derivation).
        $recountOpen = $aliceRows->filter(fn ($r) => $r['item']->status !== 'done')->count();
        $recountOverdue = $aliceRows->filter(fn ($r) => $r['item']->due_at
            && $r['item']->status !== 'done'
            && $r['item']->due_at->lt(today())
        )->count();
        $recountBlocked = $aliceRows->filter(fn ($r) => in_array('blocked', $r['item']->labels ?? [], true))->count();
        $recountInReview = $aliceRows->filter(fn ($r) => $r['item']->status === 'review')->count();
        $recountDone = $aliceRows->filter(fn ($r) => $r['item']->status === 'done')->count();

        // Now compare with the aggregate.
        $aliceAggregate = $teamPeople->firstWhere('id', $alice->id);
        $this->assertNotNull($aliceAggregate, 'Alice must appear in teamPeople');

        $this->assertSame($recountOpen, $aliceAggregate['open'], 'open count mismatch');
        $this->assertSame($recountOverdue, $aliceAggregate['overdue'], 'overdue count mismatch');
        $this->assertSame($recountBlocked, $aliceAggregate['blocked'], 'blocked count mismatch');
        $this->assertSame($recountInReview, $aliceAggregate['in_review'], 'in_review count mismatch');
        $this->assertSame($recountDone, $aliceAggregate['done'], 'done count mismatch');

        // Sanity-check the expected values too.
        $this->assertSame(4, $recountOpen);
        $this->assertSame(2, $recountOverdue);
        $this->assertSame(2, $recountBlocked);
        $this->assertSame(1, $recountInReview);
        $this->assertSame(1, $recountDone);
    }

    public function test_done_cards_older_than_thirty_days_are_absent(): void
    {
        $alice = $this->makeEmployee('Alice');
        $old = $this->makeCard($alice, [
            'title' => 'Ancient done', 'status' => 'done',
        ]);
        // Backdate its updated_at to 40 days ago.
        WorkItem::where('id', $old->id)->update(['updated_at' => now()->subDays(40)]);

        // A recent done card should still appear.
        $this->makeCard($alice, ['title' => 'Recent done', 'status' => 'done']);

        $response = $this->actingAsManager()->get('/app/team-board');
        $response->assertOk();

        $teamRows = $response->viewData('teamRows');
        $titles = $teamRows->pluck('item.title')->all();

        $this->assertNotContains('Ancient done', $titles);
        $this->assertContains('Recent done', $titles);
    }

    public function test_people_without_cards_are_absent(): void
    {
        $alice = $this->makeEmployee('Alice');
        $bob = $this->makeEmployee('Bob');

        // Only Alice gets a card.
        $this->makeCard($alice, ['title' => 'Alice task']);

        $response = $this->actingAsManager()->get('/app/team-board');
        $response->assertOk();

        $teamPeople = $response->viewData('teamPeople');
        $personIds = $teamPeople->pluck('id')->all();

        $this->assertContains($alice->id, $personIds);
        $this->assertNotContains($bob->id, $personIds);
    }

    public function test_data_scope_still_applies(): void
    {
        // Create a team-scoped manager who only sees their direct reports.
        $mgrUser = User::create(['name' => 'Team Mgr', 'email' => 'tmgr@example.com', 'password' => Hash::make('password')]);
        $mgrUser->tenants()->attach($this->tenant->id, ['role' => 'manager', 'data_scope' => 'team']);
        $mgrEmp = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $mgrUser->id,
            'name' => 'Team Mgr', 'status' => 'active', 'workload' => 'green',
        ]);

        // A direct report with a card.
        $report = $this->makeEmployee('Direct Report', ['reports_to_id' => $mgrEmp->id]);
        $this->makeCard($report, ['title' => 'Report task']);

        // An unrelated employee with a card.
        $outsider = $this->makeEmployee('Outsider');
        $this->makeCard($outsider, ['title' => 'Outsider task']);

        $response = $this->actingAs($mgrUser)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/team-board');
        $response->assertOk();

        $teamRows = $response->viewData('teamRows');
        $ownerIds = $teamRows->pluck('owner_id')->unique()->all();

        // Should see the direct report's card (and possibly self), but not the outsider.
        $this->assertContains($report->id, $ownerIds, 'Direct report must be visible');
        $this->assertNotContains($outsider->id, $ownerIds, 'Outsider must not be visible under team scope');
    }
}
