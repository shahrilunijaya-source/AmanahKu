<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Feature coverage for the read-only time-off calendar.
 *
 * Runs the full DatabaseSeeder, signs in as the seeded HR user
 * (aisyah.rahman@unijaya.example) and enters the Unijaya tenant. Seed contains
 * approved leave for Siti Khadijah 23–27 Jun 2026 and a Hari Raya Aidiladha
 * holiday on 27 Jun 2026 — both fall in the default (current) month.
 */
class CalendarTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        // Pin "now" to June 2026 so the current-month assertions are deterministic
        // regardless of suite order or another test leaking Carbon::setTestNow().
        Carbon::setTestNow('2026-06-24');
        CarbonImmutable::setTestNow('2026-06-24');

        $this->seed(DatabaseSeeder::class);

        $this->user = User::where('email', 'aisyah.rahman@unijaya.example')->firstOrFail();
        $this->tenant = Tenant::where('slug', 'unijaya')->firstOrFail();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    private function actingInTenant(): self
    {
        $this->actingAs($this->user)->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    public function test_calendar_renders_current_month_with_seeded_time_off(): void
    {
        // Act
        $response = $this->actingInTenant()->get('/app/calendar');

        // Assert — current month is June 2026 (app "now"); seeded items appear.
        $response->assertOk();
        $response->assertSee('June 2026');
        $response->assertSee('Hari Raya Aidiladha');
        $response->assertSee('Siti Khadijah');
    }

    public function test_next_month_navigation_returns_ok(): void
    {
        // Act
        $response = $this->actingInTenant()->get('/app/calendar?month=2026-07');

        // Assert
        $response->assertOk();
        $response->assertSee('July 2026');
    }

    public function test_malformed_month_falls_back_to_current_month(): void
    {
        // Act — invalid month value should not error.
        $response = $this->actingInTenant()->get('/app/calendar?month=2026-13');

        // Assert
        $response->assertOk();
        $response->assertSee('June 2026');
    }

    private function employeeWithLogin(string $name, ?int $reportsToId = null): Employee
    {
        $email = strtolower(str_replace(' ', '', $name)).'@unijaya.example';
        $user = User::create(['name' => $name, 'email' => $email, 'password' => Hash::make('password')]);
        $user->tenants()->attach($this->tenant->id, ['role' => 'employee']);

        return Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'name' => $name, 'status' => 'active', 'workload' => 'green',
            'reports_to_id' => $reportsToId,
        ]);
    }

    public function test_private_birthday_is_hidden_from_the_calendar_grid_and_side_card(): void
    {
        $private = $this->employeeWithLogin('Quiet Colleague');
        $private->update(['date_of_birth' => '1990-06-24', 'birthday_private' => true]);

        $visible = $this->employeeWithLogin('Open Colleague');
        $visible->update(['date_of_birth' => '1990-06-24', 'birthday_private' => false]);

        $response = $this->actingInTenant()->get('/app/calendar');

        $response->assertOk()->assertDontSee('Quiet Colleague')->assertSee('Open Colleague');
        $names = $response->viewData('birthdaysThisMonth')->pluck('name');
        $this->assertNotContains('Quiet Colleague', $names);
        $this->assertContains('Open Colleague', $names);
    }

    public function test_verified_leave_shows_only_to_its_verifier_until_approved(): void
    {
        $manager = $this->employeeWithLogin('Await Manager');
        $report = $this->employeeWithLogin('Await Report', $manager->id);
        $approver = $this->employeeWithLogin('Await Approver');
        $type = LeaveType::create(['tenant_id' => $this->tenant->id, 'name' => 'Awaiting Type']);

        $leave = LeaveRequest::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $report->id,
            'leave_type_id' => $type->id, 'status' => 'verified',
            'verified_by_id' => $manager->id, 'verified_at' => now(),
            'date_from' => '2026-06-24', 'date_to' => '2026-06-24', 'days' => 1,
        ]);

        // The verifier sees it, marked as waiting.
        $this->actingAs($manager->user)->withSession(['current_tenant' => $this->tenant->id]);
        $this->get('/app/calendar')->assertOk()
            ->assertSee('Await Report')
            ->assertSee('waiting for approval');

        // Nobody else does — not a stranger, not the eventual approver.
        $this->actingAs($approver->user)->withSession(['current_tenant' => $this->tenant->id]);
        $this->get('/app/calendar')->assertOk()->assertDontSee('Await Report');

        // Once approved, it becomes ordinary leave, visible to everyone.
        $leave->update(['status' => 'approved', 'approved_by_id' => $approver->id, 'approved_at' => now()]);
        $this->get('/app/calendar')->assertOk()->assertSee('Await Report');
    }

    public function test_unverified_leave_is_not_shown_to_the_manager(): void
    {
        $manager = $this->employeeWithLogin('Unverified Manager');
        $report = $this->employeeWithLogin('Unverified Report', $manager->id);
        $type = LeaveType::create(['tenant_id' => $this->tenant->id, 'name' => 'Submitted Type']);

        LeaveRequest::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $report->id,
            'leave_type_id' => $type->id, 'status' => 'submitted',
            'date_from' => '2026-06-24', 'date_to' => '2026-06-24', 'days' => 1,
        ]);

        $this->actingAs($manager->user)->withSession(['current_tenant' => $this->tenant->id]);
        $this->get('/app/calendar')->assertOk()->assertDontSee('Unverified Report');
    }
}
