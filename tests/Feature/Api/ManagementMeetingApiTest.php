<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Employee;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkItem;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** GET /api/v1/management-meeting: what Track needs to time its meeting pack (CR-34). */
class ManagementMeetingApiTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'alpha', 'name' => 'Alpha', 'initials' => 'AL']);
        $this->hr = User::create(['name' => 'HR Ann', 'email' => 'hr@example.com', 'password' => Hash::make('password')]);
        $this->hr->tenants()->attach($this->tenant->id, ['role' => 'hr']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(CurrentTenant::class)->set(null);
        parent::tearDown();
    }

    public function test_it_returns_defaults_the_meeting_date_and_who_has_updated(): void
    {
        Carbon::setTestNow('2026-09-09 10:00:00'); // a Wednesday
        app(CurrentTenant::class)->set($this->tenant);
        $project = Project::create(['tenant_id' => $this->tenant->id, 'code' => 'MM', 'name' => 'URSB : Management meeting']);
        $ahmad = Employee::create(['tenant_id' => $this->tenant->id, 'name' => 'Ahmad', 'status' => 'active', 'workload' => 'green']);
        $nurin = Employee::create(['tenant_id' => $this->tenant->id, 'name' => 'Nurin', 'status' => 'active', 'workload' => 'green']);
        foreach ([[$ahmad, 'done'], [$nurin, 'todo']] as [$who, $status]) {
            WorkItem::create([
                'tenant_id' => $this->tenant->id, 'employee_id' => $who->id, 'project_id' => $project->id, 'type' => 'task',
                'title' => 'Update Track for management meeting', 'status' => $status, 'due_at' => '2026-09-11',
                'source' => 'management_meeting', 'source_ref' => '2026-09-11',
            ]);
        }
        app(CurrentTenant::class)->set(null);

        $token = $this->hr->mintApiToken($this->tenant, 'test')->plainTextToken;
        $data = $this->getJson('/api/v1/management-meeting', ['Authorization' => 'Bearer '.$token])->assertOk()->json('data');

        $this->assertSame(5, $data['meeting_day']);
        $this->assertSame('17:00', $data['meeting_time']);
        $this->assertSame('2026-09-11', $data['meeting_date']);
        $this->assertSame([['Ahmad', true], ['Nurin', false]], array_map(fn ($m) => [$m['name'], $m['done']], $data['managers']));
    }
}
