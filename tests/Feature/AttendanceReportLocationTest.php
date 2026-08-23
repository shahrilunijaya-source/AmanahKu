<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\AttendanceReportController;
use App\Models\AttendanceRecord;
use App\Models\Branch;
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

    /**
     * @param  array<int, string>  $flags
     */
    private function offSiteRecordWith(Employee $emp, array $flags, bool $withIn, bool $withOut): AttendanceRecord
    {
        return AttendanceRecord::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $emp->id,
            'date' => '2026-07-14',
            'status' => 'on_time',
            'clock_in' => '09:31:00',
            'clock_out' => '18:05:00',
            'latitude' => $withIn ? 3.1627800 : null,
            'longitude' => $withIn ? 101.7172189 : null,
            'clock_out_latitude' => $withOut ? 3.1699900 : null,
            'clock_out_longitude' => $withOut ? 101.7233300 : null,
            'in_radius' => false,
            'flags' => $flags,
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

    public function test_the_map_point_carries_both_language_variants(): void
    {
        $subject = $this->subject();
        $this->offSiteRecord($subject);

        $this->openDrillAs('hr', $subject)
            ->assertOk()
            ->assertSee('Clocked in 09:31', false)
            ->assertSee('Clock in 09:31', false);
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
            ->assertSee('Off Site Staff', false)
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
            ->assertSee('Off Site Staff', false)
            // The ledger has one Off-site chip per row rather than a per-slot badge:
            // the row is the day, and both punches belong to it.
            ->assertSee('Off-site', false)
            ->assertDontSee('open-map-view', false)
            ->assertDontSee('101.717', false);
    }

    public function test_an_off_site_clock_out_only_offers_a_location_control(): void
    {
        $subject = $this->subject();
        $this->offSiteRecordWith($subject, ['out_of_radius_out'], withIn: false, withOut: true);

        $this->openDrillAs('hr', $subject)
            ->assertOk()
            ->assertSee('open-map-view', false)
            ->assertSee('Clocked out 18:05', false)
            ->assertSee('101.723', false);
    }

    public function test_off_site_on_both_in_and_out_carries_two_points(): void
    {
        $subject = $this->subject();
        $this->offSiteRecordWith($subject, ['out_of_radius_in', 'out_of_radius_out'], withIn: true, withOut: true);

        $response = $this->openDrillAs('hr', $subject)->assertOk();
        $response->assertSee('open-map-view', false)
            ->assertSee('Clocked in 09:31', false)
            ->assertSee('Clocked out 18:05', false)
            ->assertSee('101.717', false)
            ->assertSee('101.723', false);
    }

    public function test_off_site_flag_with_null_coordinates_renders_no_control(): void
    {
        $subject = $this->subject();
        $this->offSiteRecordWith($subject, ['out_of_radius_in', 'out_of_radius_out'], withIn: false, withOut: false);

        $this->openDrillAs('hr', $subject)
            ->assertOk()
            ->assertSee('Off Site Staff', false)
            ->assertDontSee('open-map-view', false)
            ->assertDontSee('101.7', false);
    }

    /** @return array<string, mixed> The subject's row for the off-site day. */
    private function offSiteDay(Employee $subject, string $role): ?array
    {
        $person = $this->openDrillAs($role, $subject)->assertOk()->viewData('person');

        return $person === null ? null : $person['days']->firstWhere('date', '2026-07-14');
    }

    private function fencedBranch(): Branch
    {
        return Branch::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Head Office', 'state' => 'WP',
            'latitude' => 3.1390, 'longitude' => 101.6869, 'radius_m' => 150,
        ]);
    }

    public function test_the_map_carries_the_distance_and_the_fence_it_cleared(): void
    {
        // A pin alone says "somewhere near here". Whether that is a problem depends on
        // how far it is and how wide the fence was, so both travel with the point.
        $subject = $this->subject();
        $subject->update(['branch_id' => $this->fencedBranch()->id, 'work_arrangement' => 'office']);
        $this->offSiteRecord($subject);

        $day = $this->offSiteDay($subject, 'hr');

        $this->assertSame(['name' => 'Head Office', 'radiusM' => 150, 'hasGeofence' => true], $day['site']);
        // ~4.3km east-north-east of the office. Delta rather than an exact figure:
        // the claim is that it is measured and far outside the fence, not haversine's
        // last metre.
        $this->assertEqualsWithDelta(4300, $day['points'][0]['awayM'], 200);
        $this->assertGreaterThan($day['site']['radiusM'], $day['points'][0]['awayM']);
    }

    public function test_a_viewer_without_the_role_gets_no_distance_either(): void
    {
        // The distance and the site are derived from the coordinates, so handing them
        // over would leak the location this viewer may not see, one step removed.
        $subject = $this->subject();
        $subject->update([
            'branch_id' => $this->fencedBranch()->id,
            'work_arrangement' => 'office',
            'reports_to_id' => $this->viewer->id,
        ]);
        $this->offSiteRecord($subject);

        $day = $this->offSiteDay($subject, 'employee');

        $this->assertSame([], $day['points']);
        $this->assertNull($day['site']);
    }
}
