<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\AttendanceReportController;
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
}
