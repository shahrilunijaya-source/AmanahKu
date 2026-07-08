<?php

namespace Tests\Feature;

use App\Attendance\ScheduleResolver;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Attendance Setup: HR registers a work-from-home / hybrid staff's home geofence from
 * the map, instead of waiting for first-clock-in auto-capture. Covers role gating,
 * arrangement gating, validation, and tenant isolation. Harness mirrors OrgStructureAdminTest.
 */
class AttendanceAdminTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
    }

    private function actor(string $role): User
    {
        $this->seq++;
        $user = User::create(['name' => $role, 'email' => "{$role}{$this->seq}@example.com", 'password' => Hash::make('password')]);
        $user->tenants()->attach($this->tenant->id, ['role' => $role]);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'name' => ucfirst($role), 'status' => 'active', 'workload' => 'green',
        ]);

        return $user;
    }

    private function actingAsRole(string $role): self
    {
        $this->actingAs($this->actor($role))->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    private function staff(string $arrangement, ?int $tenantId = null): Employee
    {
        return Employee::create([
            'tenant_id' => $tenantId ?? $this->tenant->id,
            'name' => 'WFH Worker', 'status' => 'active', 'workload' => 'green',
            'work_arrangement' => $arrangement,
        ]);
    }

    // --- Screen render ------------------------------------------------------

    public function test_attendance_setup_screen_renders(): void
    {
        Branch::create([
            'tenant_id' => $this->tenant->id, 'name' => 'KL', 'state' => 'WP',
            'work_start' => '10:00', 'work_end' => '19:00', 'min_hours' => 8,
        ]);
        $this->staff('wfh');
        $this->staff('hybrid');

        $html = $this->actingAsRole('hr')->get('/app/attendance-admin')->assertOk()->getContent();

        $this->assertStringContainsString('Branch geofences', $html);
        $this->assertStringContainsString('Client sites', $html);
        $this->assertStringContainsString('Work from home', $html);
        $this->assertStringContainsString('Registered home addresses', $html);
        $this->assertStringContainsString('Staff work arrangements', $html);
    }

    // --- Register home address ----------------------------------------------

    public function test_hr_can_register_a_wfh_staff_home_address(): void
    {
        $this->actingAsRole('hr');
        $e = $this->staff('wfh');

        $this->post("/app/attendance-admin/staff/{$e->id}/home", [
            'home_latitude' => 3.0449,
            'home_longitude' => 101.4451,
        ])->assertRedirect()->assertSessionHas('ok');

        $e->refresh();
        $this->assertSame('3.0449000', $e->home_latitude);
        $this->assertSame('101.4451000', $e->home_longitude);
        $this->assertNotNull($e->home_locked_at);
    }

    public function test_hr_can_register_a_hybrid_staff_home_address(): void
    {
        $this->actingAsRole('hr');
        $e = $this->staff('hybrid');

        $this->post("/app/attendance-admin/staff/{$e->id}/home", [
            'home_latitude' => 3.1614385,
            'home_longitude' => 101.7171362,
        ])->assertRedirect()->assertSessionHas('ok');

        $this->assertNotNull($e->fresh()->home_locked_at);
    }

    public function test_registering_a_home_for_an_office_staff_is_rejected(): void
    {
        $this->actingAsRole('hr');
        $e = $this->staff('office');

        $this->post("/app/attendance-admin/staff/{$e->id}/home", [
            'home_latitude' => 3.0,
            'home_longitude' => 101.0,
        ])->assertStatus(422);

        $this->assertNull($e->fresh()->home_latitude);
    }

    public function test_coordinates_are_required_and_range_checked(): void
    {
        $this->actingAsRole('hr');
        $e = $this->staff('wfh');

        $this->post("/app/attendance-admin/staff/{$e->id}/home", [])
            ->assertSessionHasErrors(['home_latitude', 'home_longitude']);

        $this->post("/app/attendance-admin/staff/{$e->id}/home", [
            'home_latitude' => 999,
            'home_longitude' => 999,
        ])->assertSessionHasErrors(['home_latitude', 'home_longitude']);

        $this->assertNull($e->fresh()->home_latitude);
    }

    // --- Company-wide WFH hours ---------------------------------------------

    public function test_hr_can_set_the_company_wfh_policy(): void
    {
        $this->actingAsRole('hr')
            ->post('/app/attendance-admin/wfh-policy', [
                'wfh_work_start' => '10:00',
                'wfh_work_end' => '16:00',
                'wfh_min_hours' => 6,
                'wfh_radius_m' => 500,
            ])->assertRedirect()->assertSessionHas('ok');

        $t = $this->tenant->fresh();
        // DB time format differs by driver (SQLite 'HH:MM' / MySQL 'HH:MM:SS'); compare on HH:MM.
        $this->assertSame('10:00', substr((string) $t->wfh_work_start, 0, 5));
        $this->assertSame('16:00', substr((string) $t->wfh_work_end, 0, 5));
        $this->assertEquals(6.0, (float) $t->wfh_min_hours);
        $this->assertSame(500, $t->wfh_radius_m);
    }

    public function test_wfh_follows_company_hours_not_the_staffs_own_branch(): void
    {
        // Staff belongs to a branch with its own hours...
        $branch = Branch::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Seremban 2', 'state' => 'Negeri Sembilan',
            'work_start' => '08:00', 'work_end' => '17:00', 'min_hours' => 9,
        ]);
        // ...but the company WFH standard wins on home days.
        $this->tenant->update([
            'wfh_work_start' => '10:00', 'wfh_work_end' => '16:00', 'wfh_min_hours' => 6, 'wfh_radius_m' => 500,
        ]);
        $e = Employee::create([
            'tenant_id' => $this->tenant->id, 'branch_id' => $branch->id,
            'name' => 'Home Dev', 'status' => 'active', 'workload' => 'green',
            'work_arrangement' => 'wfh', 'home_latitude' => 3.0, 'home_longitude' => 101.0,
        ]);

        $spec = app(ScheduleResolver::class)->resolve($e->fresh(), now());

        $this->assertSame('home', $spec->type);
        $this->assertSame('10:00', $spec->workStart);   // company WFH, not Seremban 2's 08:00
        $this->assertSame('16:00', $spec->workEnd);
        $this->assertSame(6.0, $spec->minHours);
        $this->assertSame(500, $spec->radiusM);
    }

    public function test_hybrid_home_days_also_follow_company_hours(): void
    {
        $branch = Branch::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Klang', 'state' => 'Selangor',
            'work_start' => '08:00', 'work_end' => '17:00',
        ]);
        $this->tenant->update(['wfh_work_start' => '09:30', 'wfh_work_end' => '18:30']);
        $e = Employee::create([
            'tenant_id' => $this->tenant->id, 'branch_id' => $branch->id,
            'name' => 'Hybrid Dev', 'status' => 'active', 'workload' => 'green',
            'work_arrangement' => 'hybrid', 'hybrid_office_days' => [1], // office Mon only
            'home_latitude' => 3.0, 'home_longitude' => 101.0,
        ]);

        // Pick a Tuesday (a home day) so the resolver returns the home site.
        $tuesday = now()->next(Carbon::TUESDAY);
        $spec = app(ScheduleResolver::class)->resolve($e->fresh(), $tuesday);

        $this->assertSame('home', $spec->type);
        $this->assertSame('09:30', $spec->workStart);
    }

    public function test_wfh_falls_back_to_branch_hours_when_company_policy_blank(): void
    {
        $branch = Branch::create([
            'tenant_id' => $this->tenant->id, 'name' => 'KL', 'state' => 'WP',
            'work_start' => '09:00', 'work_end' => '18:00', 'min_hours' => 8,
        ]);
        $e = Employee::create([
            'tenant_id' => $this->tenant->id, 'branch_id' => $branch->id,
            'name' => 'Home Dev', 'status' => 'active', 'workload' => 'green',
            'work_arrangement' => 'wfh', 'home_latitude' => 3.0, 'home_longitude' => 101.0,
        ]);

        $spec = app(ScheduleResolver::class)->resolve($e->fresh(), now());

        // No company policy set → borrow the staff's branch hours, hardcoded 200m radius.
        $this->assertSame('09:00', $spec->workStart);
        $this->assertSame(200, $spec->radiusM);
    }

    public function test_plain_employee_cannot_set_the_wfh_policy(): void
    {
        $this->actingAsRole('employee')
            ->post('/app/attendance-admin/wfh-policy', ['wfh_radius_m' => 500])
            ->assertForbidden();

        $this->assertNull($this->tenant->fresh()->wfh_radius_m);
    }

    // --- Authorization + isolation ------------------------------------------

    public function test_plain_employee_cannot_register_a_home_address(): void
    {
        $this->actingAsRole('employee');
        $e = $this->staff('wfh');

        $this->post("/app/attendance-admin/staff/{$e->id}/home", [
            'home_latitude' => 3.0, 'home_longitude' => 101.0,
        ])->assertForbidden();

        $this->assertNull($e->fresh()->home_latitude);
    }

    public function test_hr_cannot_register_a_home_for_another_tenants_staff(): void
    {
        $other = Tenant::create(['slug' => 'other', 'name' => 'Other', 'initials' => 'OT']);
        $foreign = $this->staff('wfh', $other->id);

        $this->actingAsRole('hr')
            ->post("/app/attendance-admin/staff/{$foreign->id}/home", [
                'home_latitude' => 3.0, 'home_longitude' => 101.0,
            ])->assertForbidden();

        $this->assertNull($foreign->fresh()->home_latitude);
    }
}
