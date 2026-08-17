<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Project;
use App\Models\SubPillar;
use App\Models\Tenant;
use App\Models\Timesheet;
use App\Models\TimesheetCategory;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * A sub-pillar is a kind of work (Management / Meeting / Technical), shared by
 * every project in the tenant — not a part of one project. Unijaya's 24 project
 * records all carried the identical three before this change.
 */
class SubPillarTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
    }

    public function test_a_sub_pillar_belongs_to_a_tenant_and_not_to_a_project(): void
    {
        $sub = SubPillar::create(['tenant_id' => $this->tenant->id, 'name' => 'Technical']);

        $this->assertTrue($sub->is_active);
        $this->assertSame(0, $sub->sort);
        $this->assertFalse(array_key_exists('project_id', $sub->getAttributes()));
    }

    public function test_the_same_name_cannot_be_added_twice_in_one_tenant(): void
    {
        SubPillar::create(['tenant_id' => $this->tenant->id, 'name' => 'Meeting']);

        $this->expectException(QueryException::class);
        SubPillar::create(['tenant_id' => $this->tenant->id, 'name' => 'Meeting']);
    }

    public function test_two_projects_need_only_one_copy_of_a_sub_pillar(): void
    {
        Project::create(['tenant_id' => $this->tenant->id, 'name' => 'JKDM: MyStods']);
        Project::create(['tenant_id' => $this->tenant->id, 'name' => 'KKM: NSFIRM']);
        SubPillar::create(['tenant_id' => $this->tenant->id, 'name' => 'Management']);

        // The old shape stored one row per project. One row now serves both.
        $this->assertSame(2, Project::where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(1, SubPillar::where('tenant_id', $this->tenant->id)->count());
    }

    public function test_any_sub_pillar_can_be_booked_against_any_project(): void
    {
        Carbon::setTestNow('2026-06-19 12:00:00');

        $user = User::create(['name' => 'Demo', 'email' => 'demo@example.com', 'password' => Hash::make('password')]);
        $user->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $employee = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'name' => 'Demo', 'status' => 'active', 'workload' => 'green',
        ]);

        $category = TimesheetCategory::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Development', 'requires_project' => true,
        ]);
        $project = Project::create(['tenant_id' => $this->tenant->id, 'name' => 'JKDM: MyStods']);
        // A sub-pillar created with no reference to that project at all.
        $sub = SubPillar::create(['tenant_id' => $this->tenant->id, 'name' => 'Technical']);

        $this->actingAs($user)->withSession(['current_tenant' => $this->tenant->id])
            ->post('/app/timesheets', [
                'week_start' => '2026-06-15',
                'entries' => [[
                    'entry_date' => '2026-06-15',
                    'category_id' => $category->id,
                    'project_id' => $project->id,
                    'sub_pillar_id' => $sub->id,
                    'percentage' => 100,
                ]],
            ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('timesheet_entries', [
            'project_id' => $project->id,
            'sub_pillar_id' => $sub->id,
        ]);

        $this->assertSame(1, Timesheet::where('employee_id', $employee->id)->count());

        Carbon::setTestNow();
    }
}
