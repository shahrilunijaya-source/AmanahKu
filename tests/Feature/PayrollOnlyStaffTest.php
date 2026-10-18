<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Attendance\ReminderTargets;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Payroll\PayrollReadiness;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * "Payroll only" staff: still paid, but left out of attendance, timesheets and reports,
 * and hidden from the directory for anyone who is not HR/director.
 */
class PayrollOnlyStaffTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Branch $branch;

    private Employee $hidden;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-07-15 10:00:00'));
        $this->tenant = Tenant::create(['slug' => 'alpha', 'name' => 'Alpha', 'initials' => 'AL']);
        app(CurrentTenant::class)->set($this->tenant);
        $this->branch = Branch::create([
            'name' => 'HQ', 'latitude' => 3.1390, 'longitude' => 101.6869, 'radius_m' => 200,
            'work_start' => '09:00:00', 'work_end' => '18:00:00', 'min_hours' => 8,
        ]);

        $this->hidden = Employee::create([
            'name' => 'Hidden Family', 'status' => 'active', 'workload' => 'green',
            'branch_id' => $this->branch->id, 'work_arrangement' => 'office', 'payroll_only' => true,
        ]);
    }

    protected function tearDown(): void
    {
        app(CurrentTenant::class)->set(null);
        parent::tearDown();
    }

    private function member(string $role, string $name): User
    {
        $user = User::create(['name' => $name, 'email' => strtolower(str_replace(' ', '.', $name)).'@example.com', 'password' => Hash::make('password')]);
        $user->tenants()->attach($this->tenant->id, ['role' => $role]);
        Employee::create(['user_id' => $user->id, 'name' => $name, 'status' => 'active', 'workload' => 'green', 'branch_id' => $this->branch->id, 'work_arrangement' => 'office']);

        return $user;
    }

    private function as(User $user, string $role): self
    {
        return $this->actingAs($user)->withSession(['current_tenant' => $this->tenant->id, 'persona' => $role]);
    }

    public function test_they_are_left_out_of_the_attendance_report_and_export(): void
    {
        $hr = $this->member('hr', 'HR Person');
        Employee::create(['name' => 'Tracked Staff', 'status' => 'active', 'workload' => 'green']);

        $this->as($hr, 'hr')->get('/app/attendance-report')
            ->assertOk()->assertSee('Tracked Staff')->assertDontSee('Hidden Family');

        $this->as($hr, 'hr')->get('/app/attendance-report?emp='.$this->hidden->id)
            ->assertOk()->assertDontSee('Hidden Family');
    }

    public function test_they_get_no_clock_in_reminder(): void
    {
        $due = app(ReminderTargets::class)->missingClockIn(Carbon::parse('2026-07-15 09:31:00'), 30);

        $this->assertNotContains($this->hidden->id, $due->pluck('id')->all());
        $this->assertFalse($this->hidden->mustClock());
    }

    public function test_they_stay_in_payroll(): void
    {
        $ids = array_map(fn (array $row) => $row['employee']->id, app(PayrollReadiness::class)->employeeRows($this->tenant));

        $this->assertContains($this->hidden->id, $ids);
    }

    public function test_directory_hides_them_from_staff_but_not_from_hr(): void
    {
        $this->as($this->member('employee', 'Plain Staff'), 'employee')->get('/app/directory')
            ->assertOk()->assertDontSee('Hidden Family')->assertDontSee('payroll-only');

        $this->as($this->member('hr', 'HR Person'), 'hr')->get('/app/directory')
            ->assertOk()->assertSee('Hidden Family')->assertSee('Payroll only')
            ->assertSee('employees\\/'.$this->hidden->id.'\\/payroll-only', false);
    }

    public function test_management_sees_no_eye_toggle_in_the_directory(): void
    {
        $this->as($this->member('management', 'Mgmt Person'), 'management')->get('/app/directory')
            ->assertOk()->assertDontSee('payroll-only');
    }

    public function test_hr_turns_it_on_with_a_reason_and_it_is_audited(): void
    {
        $hr = $this->member('hr', 'HR Person');
        $target = Employee::create(['name' => 'Spouse', 'status' => 'active', 'workload' => 'green']);

        $this->as($hr, 'hr')->post(route('employees.payroll-only', $target), ['payroll_only' => 1])
            ->assertSessionHasErrors('reason');
        $this->assertFalse($target->fresh()->payroll_only);

        $this->as($hr, 'hr')->post(route('employees.payroll-only', $target), ['payroll_only' => 1, 'reason' => 'Family of director'])
            ->assertRedirect();
        $this->assertTrue($target->fresh()->payroll_only);

        $row = AuditLog::where('subject_id', $target->id)->where('field', 'payroll_only')->sole();
        $this->assertSame('Family of director', $row->reason);
        $this->assertSame('true', $row->new_value);

        $this->as($hr, 'hr')->post(route('employees.payroll-only', $target), ['payroll_only' => 0])->assertRedirect();
        $this->assertFalse($target->fresh()->payroll_only);
        $this->assertSame(2, AuditLog::where('subject_id', $target->id)->where('field', 'payroll_only')->count());
    }

    public function test_management_and_staff_cannot_change_it(): void
    {
        foreach (['management' => 'Mgmt Person', 'employee' => 'Plain Staff'] as $role => $name) {
            $this->as($this->member($role, $name), $role)
                ->post(route('employees.payroll-only', $this->hidden), ['payroll_only' => 0])
                ->assertForbidden();
        }

        $this->assertTrue($this->hidden->fresh()->payroll_only);
    }
}
