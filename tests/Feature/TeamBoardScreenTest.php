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
 * Team board screen (Task 2 of the redesign): the summary strip + filterable
 * table replacing the old per-person lanes. Fixture pattern follows
 * TeamBoardDataTest, which pins the data shape this screen renders from.
 */
class TeamBoardScreenTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $managerUser;

    private Employee $managerEmployee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);

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

    public function test_renders_one_row_per_work_item(): void
    {
        $alice = $this->makeEmployee('Alice');
        $this->makeCard($alice, ['title' => 'Card one']);
        $this->makeCard($alice, ['title' => 'Card two']);
        $this->makeCard($this->managerEmployee, ['title' => 'Boss card']);

        $response = $this->actingAsManager()->get('/app/team-board');
        $response->assertOk();

        $teamRows = $response->viewData('teamRows');
        $this->assertGreaterThan(0, $teamRows->count());

        $html = $response->getContent();

        foreach ($teamRows as $row) {
            $this->assertStringContainsString('data-card-id="'.$row['item']->id.'"', $html);
        }

        // Exactly one row per teamRows entry — no duplicates, nothing extra.
        $this->assertSame($teamRows->count(), substr_count($html, 'data-card-id="'));
    }

    public function test_renders_one_strip_entry_per_person(): void
    {
        $alice = $this->makeEmployee('Alice');
        $bob = $this->makeEmployee('Bob');
        $this->makeCard($alice);
        $this->makeCard($bob);

        $response = $this->actingAsManager()->get('/app/team-board');
        $response->assertOk();

        $teamPeople = $response->viewData('teamPeople');
        $this->assertGreaterThan(0, $teamPeople->count());

        $html = $response->getContent();

        foreach ($teamPeople as $person) {
            $this->assertStringContainsString('data-person-id="'.$person['id'].'"', $html);
        }

        // Exactly one strip row per teamPeople entry. The table rows use a
        // differently-named attribute (data-owner-id) for the same purpose,
        // precisely so this count cannot double up against table rows.
        $this->assertSame($teamPeople->count(), substr_count($html, 'data-person-id="'));
    }

    public function test_guide_copy_no_longer_mentions_lanes(): void
    {
        $this->makeCard($this->managerEmployee);

        $response = $this->actingAsManager()->get('/app/team-board');
        $response->assertOk();

        $html = strtolower($response->getContent());
        $this->assertStringNotContainsString('lane', $html);
    }

    public function test_plain_employee_with_no_direct_reports_gets_403(): void
    {
        $plainUser = User::create(['name' => 'Plain', 'email' => 'plain@example.com', 'password' => Hash::make('password')]);
        $plainUser->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $plainUser->id,
            'name' => 'Plain', 'status' => 'active', 'workload' => 'green',
        ]);

        $response = $this->actingAs($plainUser)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/team-board');

        $response->assertForbidden();
    }
}
