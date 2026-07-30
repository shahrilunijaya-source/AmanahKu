<?php

namespace Tests\Feature;

use App\Http\Controllers\TimesheetController;
use App\Models\AppNotification;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\Timesheet;
use App\Models\TimesheetCategory;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TimesheetChaseCardTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $hrUser;

    private Employee $hrEmployee;

    private Employee $targetEmployee;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-06-19 12:00:00');

        $this->tenant = Tenant::create([
            'slug' => 'unijaya',
            'name' => 'Unijaya',
            'initials' => 'UJ',
        ]);

        $this->hrUser = User::create([
            'name' => 'HR Admin',
            'email' => 'hr@example.com',
            'password' => Hash::make('password'),
        ]);
        $this->hrUser->tenants()->attach($this->tenant->id, ['role' => 'hr']);

        $this->hrEmployee = Employee::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->hrUser->id,
            'name' => 'HR Admin',
            'status' => 'active',
            'workload' => 'green',
        ]);

        $targetUser = User::create([
            'name' => 'Target Worker',
            'email' => 'worker@example.com',
            'password' => Hash::make('password'),
        ]);
        $targetUser->tenants()->attach($this->tenant->id, ['role' => 'employee']);

        $this->targetEmployee = Employee::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $targetUser->id,
            'name' => 'Target Worker',
            'status' => 'active',
            'workload' => 'green',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function actingInTenant(User $user): self
    {
        $this->actingAs($user)->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    public function test_hr_can_nudge_a_person_who_owes(): void
    {
        $response = $this->actingInTenant($this->hrUser)
            ->post("/app/timesheet-reports/nudge/{$this->targetEmployee->id}");

        $response->assertRedirect();
        $this->assertDatabaseHas('app_notifications', [
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->targetEmployee->user_id,
            'title' => 'Timesheet reminder',
        ]);
    }

    public function test_nudging_twice_in_one_week_sends_one_bell(): void
    {
        $this->actingInTenant($this->hrUser)
            ->post("/app/timesheet-reports/nudge/{$this->targetEmployee->id}")
            ->assertRedirect();

        $this->actingInTenant($this->hrUser)
            ->post("/app/timesheet-reports/nudge/{$this->targetEmployee->id}")
            ->assertRedirect();

        $count = AppNotification::where('user_id', $this->targetEmployee->user_id)->count();
        $this->assertSame(1, $count);
    }

    public function test_a_manager_cannot_nudge(): void
    {
        $mgrUser = User::create(['name' => 'Mgr', 'email' => 'mgr@example.com', 'password' => Hash::make('password')]);
        $mgrUser->tenants()->attach($this->tenant->id, ['role' => 'manager']);

        $response = $this->actingInTenant($mgrUser)
            ->post("/app/timesheet-reports/nudge/{$this->targetEmployee->id}");

        $response->assertStatus(403);
    }

    public function test_a_plain_employee_cannot_nudge(): void
    {
        $plainUser = User::create(['name' => 'Plain', 'email' => 'plain@example.com', 'password' => Hash::make('password')]);
        $plainUser->tenants()->attach($this->tenant->id, ['role' => 'employee']);

        $response = $this->actingInTenant($plainUser)
            ->post("/app/timesheet-reports/nudge/{$this->targetEmployee->id}");

        $response->assertStatus(403);
    }

    public function test_an_employee_in_another_tenant_cannot_be_nudged(): void
    {
        $otherTenant = Tenant::create(['slug' => 'other', 'name' => 'Other', 'initials' => 'OT']);
        $otherEmployee = Employee::create([
            'tenant_id' => $otherTenant->id,
            'name' => 'Other Worker',
            'status' => 'active',
            'workload' => 'green',
        ]);

        $response = $this->actingInTenant($this->hrUser)
            ->post("/app/timesheet-reports/nudge/{$otherEmployee->id}");

        $response->assertStatus(403);
    }

    public function test_an_employee_with_no_login_cannot_be_nudged(): void
    {
        $noLoginEmp = Employee::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => null,
            'name' => 'No Login',
            'status' => 'active',
            'workload' => 'green',
        ]);

        $response = $this->actingInTenant($this->hrUser)
            ->post("/app/timesheet-reports/nudge/{$noLoginEmp->id}");

        $response->assertStatus(422);
        $this->assertDatabaseMissing('app_notifications', [
            'tenant_id' => $this->tenant->id,
            'title' => 'Timesheet reminder',
        ]);
    }

    public function test_the_roster_on_the_report_respects_data_scope(): void
    {
        $mgrUser = User::create(['name' => 'Manager User', 'email' => 'mgr_user@example.com', 'password' => Hash::make('password')]);
        $mgrUser->tenants()->attach($this->tenant->id, ['role' => 'manager']);

        $mgrEmp = Employee::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $mgrUser->id,
            'name' => 'Manager Worker',
            'status' => 'active',
            'workload' => 'green',
        ]);

        // Target worker reports to manager
        $this->targetEmployee->update(['reports_to_id' => $mgrEmp->id]);

        // Out of scope employee
        $otherEmp = Employee::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Out Of Scope',
            'status' => 'active',
            'workload' => 'green',
        ]);

        app(CurrentTenant::class)->set($this->tenant);

        $request = Request::create('/app/timesheet-reports', 'GET');
        $request->attributes->set('tenantRole', 'manager');
        $request->attributes->set('tenantScope', 'team');

        $data = app(TimesheetController::class)->reportData($request, $mgrEmp);

        $this->assertArrayHasKey('tsRoster', $data);
        $rosterEmpIds = collect($data['tsRoster'])->pluck('employee.id')->all();

        $this->assertContains($this->targetEmployee->id, $rosterEmpIds);
        $this->assertNotContains($otherEmp->id, $rosterEmpIds);
    }

    private function fillFullWeek(Employee $emp, string $weekStart = '2026-06-15'): void
    {
        $cat = TimesheetCategory::firstOrCreate(
            ['tenant_id' => $this->tenant->id, 'name' => 'Others'],
            ['requires_project' => false]
        );
        $ts = Timesheet::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $emp->id,
            'week_start' => $weekStart,
            'status' => 'submitted',
            'total_hours' => 40,
        ]);
        $mon = Carbon::parse($weekStart);
        for ($i = 0; $i < 5; $i++) {
            $ts->entries()->create([
                'tenant_id' => $this->tenant->id,
                'entry_date' => $mon->copy()->addDays($i)->toDateString(),
                'category_id' => $cat->id,
                'percentage' => 100,
                'hours' => 8,
            ]);
        }
    }

    public function test_a_person_who_owes_gets_a_row_and_a_done_person_does_not(): void
    {
        $this->fillFullWeek($this->hrEmployee);

        $response = $this->actingInTenant($this->hrUser)
            ->get('/app/timesheet-reports');

        $response->assertOk();
        $response->assertSee('uj-tr-owe', false);
        $response->assertSee('Target Worker');

        $html = $response->getContent();
        $this->assertSame(1, substr_count($html, 'uj-tr-owe'));
    }

    public function test_the_lead_line_counts_sheets_in(): void
    {
        $this->fillFullWeek($this->hrEmployee);

        $response = $this->actingInTenant($this->hrUser)
            ->get('/app/timesheet-reports');

        $response->assertOk();
        $response->assertSeeInOrder(['1', 'of', '2', 'sheets in'], false);
    }

    public function test_the_deadline_renders_in_full(): void
    {
        $response = $this->actingInTenant($this->hrUser)
            ->get('/app/timesheet-reports');

        $response->assertOk();
        $response->assertSee('19:00', false);
    }

    public function test_a_person_with_no_entries_reads_nothing_yet(): void
    {
        $response = $this->actingInTenant($this->hrUser)
            ->get('/app/timesheet-reports');

        $response->assertOk();
        $response->assertSee('Nothing yet', false);
        $response->assertDontSee('0 of', false);
    }

    public function test_an_already_nudged_person_shows_reminded(): void
    {
        $this->actingInTenant($this->hrUser)
            ->post("/app/timesheet-reports/nudge/{$this->targetEmployee->id}")
            ->assertRedirect();

        $response = $this->actingInTenant($this->hrUser)
            ->get('/app/timesheet-reports');

        $response->assertOk();
        $response->assertSee('Reminded', false);
        $response->assertDontSee(route('timesheet.reports.nudge', $this->targetEmployee), false);
    }

    public function test_everyone_in_collapses_the_card(): void
    {
        $this->fillFullWeek($this->hrEmployee);
        $this->fillFullWeek($this->targetEmployee);

        $response = $this->actingInTenant($this->hrUser)
            ->get('/app/timesheet-reports');

        $response->assertOk();
        $response->assertSee('Every sheet is in for this week.', false);
        $response->assertDontSee('uj-tr-owe', false);
    }

    public function test_the_old_chip_wording_is_gone(): void
    {
        $response = $this->actingInTenant($this->hrUser)
            ->get('/app/timesheet-reports');

        $response->assertOk();
        $response->assertDontSee('PENDING', false);
    }
}
