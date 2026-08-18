<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\AttendanceReportController;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AttendanceReportLocationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Employee $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-07-15 10:00:00'));

        $this->tenant = Tenant::create([
            'slug' => 'alpha',
            'name' => 'Alpha',
            'initials' => 'AL',
        ]);

        $user = User::create([
            'name' => 'Viewer',
            'email' => 'viewer@example.com',
            'password' => Hash::make('password'),
        ]);
        $user->tenants()->attach($this->tenant->id, ['role' => 'hr']);

        $this->viewer = Employee::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $user->id,
            'name' => 'Viewer',
            'status' => 'active',
            'workload' => 'green',
        ]);
    }

    private function screenDataAs(string $role): array
    {
        $request = Request::create('/app/attendance-report', 'GET');
        $request->attributes->set('tenantRole', $role);
        $request->attributes->set('tenantScope', 'company');
        $request->attributes->set('employee', $this->viewer);

        return app(AttendanceReportController::class)->screenData($request);
    }

    public function test_oversight_roles_may_see_punch_coordinates(): void
    {
        foreach (['hr', 'manager', 'management', 'director'] as $role) {
            $this->assertTrue(
                $this->screenDataAs($role)['canSeeLocation'],
                "Role [{$role}] should be allowed to see punch locations."
            );
        }
    }

    /**
     * canSeeAll() lets an employee-role user reach this screen purely by having a
     * direct report. That route stops short of coordinates.
     */
    public function test_an_employee_role_viewer_may_not_see_punch_coordinates(): void
    {
        $this->assertFalse($this->screenDataAs('employee')['canSeeLocation']);
    }

    private function offSiteRecord(Employee $emp): AttendanceRecord
    {
        return AttendanceRecord::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $emp->id,
            'date' => '2026-07-14',
            'status' => 'on_time',
            'clock_in' => '09:31:00',
            'latitude' => 3.1627800,
            'longitude' => 101.7172189,
            'in_radius' => false,
            'flags' => ['out_of_radius_in'],
        ]);
    }

    private function subject(): Employee
    {
        return Employee::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Off Site Staff',
            'status' => 'active',
            'workload' => 'green',
        ]);
    }

    private function openDrillAs(string $role, Employee $subject)
    {
        $user = User::where('email', 'viewer@example.com')->firstOrFail();
        $user->tenants()->updateExistingPivot($this->tenant->id, ['role' => $role]);

        return $this->actingAs($user)
            ->withSession(['current_tenant' => $this->tenant->id, 'persona' => $role])
            ->get('/app/attendance-report?period=week&emp='.$subject->id);
    }

    public function test_an_off_site_punch_offers_a_location_control(): void
    {
        $subject = $this->subject();
        $this->offSiteRecord($subject);

        $this->openDrillAs('hr', $subject)
            ->assertOk()
            ->assertSee('open-map-view', false)
            ->assertSee('101.717', false);
    }

    public function test_an_on_site_punch_exposes_no_coordinates(): void
    {
        $subject = $this->subject();

        AttendanceRecord::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $subject->id,
            'date' => '2026-07-14',
            'status' => 'on_time',
            'clock_in' => '08:55:00',
            'latitude' => 3.1627800,
            'longitude' => 101.7172189,
            'in_radius' => true,
            'flags' => [],
        ]);

        $this->openDrillAs('hr', $subject)
            ->assertOk()
            ->assertDontSee('open-map-view', false)
            ->assertDontSee('101.717', false);
    }

    public function test_a_viewer_without_the_role_gets_no_coordinates(): void
    {
        $subject = $this->subject();
        $subject->update(['reports_to_id' => $this->viewer->id]);
        $this->offSiteRecord($subject);

        $this->openDrillAs('employee', $subject)
            ->assertOk()
            ->assertDontSee('open-map-view', false)
            ->assertDontSee('101.717', false);
    }
}
