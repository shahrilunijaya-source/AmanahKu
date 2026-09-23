<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeProgression;
use App\Models\Tenant;
use App\Models\User;
use App\Services\EmploymentRecordService;
use App\Services\EmploymentTransitionException;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class EmploymentRecordServiceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        app(CurrentTenant::class)->set($this->tenant);
    }

    private function login(string $role, array $attrs = []): Employee
    {
        $user = User::create(['name' => ucfirst($role), 'email' => $role.'@example.com', 'password' => Hash::make('password')]);
        $user->tenants()->attach($this->tenant->id, ['role' => $role]);
        $employee = Employee::create(array_merge([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'name' => ucfirst($role), 'status' => 'active', 'workload' => 'green',
        ], $attrs));
        $this->actingAs($user)->withSession(['current_tenant' => $this->tenant->id]);

        return $employee;
    }

    private function emp(string $name, array $attrs = []): Employee
    {
        return Employee::create(array_merge([
            'tenant_id' => $this->tenant->id, 'name' => $name, 'status' => 'probation', 'workload' => 'green',
            'joined_at' => '2026-01-05',
        ], $attrs));
    }

    public function test_confirm_moves_probation_to_active_and_writes_a_row(): void
    {
        $hr = $this->login('hr');
        $e = $this->emp('Adibah');

        $row = app(EmploymentRecordService::class)->confirm($e, '2026-07-05', ['division' => 'Senior'], 'Passed review', $hr);

        $e->refresh();
        $this->assertSame('active', $e->status);
        $this->assertSame('2026-07-05', $e->confirmed_at->toDateString());
        $this->assertSame('Senior', $e->division);
        $this->assertSame('confirmed', $row->type);
        $this->assertEqualsCanonicalizing(['status', 'division'], $row->changed_fields);
        $this->assertSame($hr->id, $row->recorded_by_employee_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'Confirmed employee', 'target' => 'Adibah']);
    }

    public function test_confirm_refuses_an_active_employee(): void
    {
        $e = $this->emp('Adibah', ['status' => 'active']);
        $this->expectException(EmploymentTransitionException::class);
        app(EmploymentRecordService::class)->confirm($e, '2026-07-05', [], null, null);
    }

    public function test_update_with_no_change_writes_no_row(): void
    {
        $e = $this->emp('Adibah', ['division' => 'Senior']);
        $this->assertNull(app(EmploymentRecordService::class)->update($e, '2026-03-01', ['division' => 'Senior'], null, null));
        $this->assertSame(0, EmployeeProgression::count());
    }

    public function test_update_records_changed_fields_and_snapshot_names(): void
    {
        $dept = Department::create(['tenant_id' => $this->tenant->id, 'name' => 'DevOps']);
        $e = $this->emp('Adibah', ['status' => 'active', 'salary' => 5000]);

        $row = app(EmploymentRecordService::class)->update($e, '2026-03-01', ['department_id' => $dept->id, 'salary' => 5500], 'Promo', null);

        $this->assertEqualsCanonicalizing(['department', 'basic_salary'], $row->changed_fields);
        $this->assertSame('DevOps', $row->snapshot['department']);
        $this->assertEquals(5500, $row->snapshot['basic_salary']);
        $this->assertSame('Promo', $row->remark);
    }

    public function test_effective_date_before_hire_date_is_refused(): void
    {
        $e = $this->emp('Adibah', ['status' => 'active']);
        $this->expectException(EmploymentTransitionException::class);
        app(EmploymentRecordService::class)->update($e, '2025-12-31', ['division' => 'X'], null, null);
    }

    public function test_resign_then_rehire(): void
    {
        $e = $this->emp('Adibah', ['status' => 'active', 'confirmed_at' => '2026-07-05']);
        $svc = app(EmploymentRecordService::class);

        $svc->resign($e, '2026-08-01', '2026-08-31', 'resigned', null, null);
        $e->refresh();
        $this->assertSame('resigned', $e->status);
        $this->assertSame('2026-08-31', $e->last_working_day->toDateString());

        $svc->rehire($e, '2026-10-01', ['division' => 'Mid'], null, null);
        $e->refresh();
        $this->assertSame('probation', $e->status);
        $this->assertSame('2026-10-01', $e->joined_at->toDateString());
        $this->assertNull($e->resigned_at);
        $this->assertNull($e->confirmed_at);
        $this->assertSame(['resigned', 'rehired'], EmployeeProgression::orderBy('id')->pluck('type')->all());
    }

    public function test_resign_keeps_status_while_serving_notice(): void
    {
        $e = $this->emp('Adibah', ['status' => 'active']);
        $svc = app(EmploymentRecordService::class);

        $svc->resign($e, now()->toDateString(), now()->addMonth()->toDateString(), 'resigned', null, null);
        $e->refresh();
        $this->assertSame('active', $e->status);
        $this->assertNotNull($e->resigned_at);

        $this->expectException(EmploymentTransitionException::class);
        $svc->resign($e, now()->toDateString(), now()->addMonth()->toDateString(), 'resigned', null, null);
    }

    public function test_withdrawing_a_resignation_during_notice_clears_the_leaving_dates(): void
    {
        $e = $this->emp('Adibah');
        $svc = app(EmploymentRecordService::class);
        $svc->resign($e, now()->toDateString(), now()->addMonth()->toDateString(), 'resigned', null, null);

        $svc->withdrawResignation($e->refresh(), 'Changed her mind', null);
        $e->refresh();

        $this->assertSame('probation', $e->status);
        $this->assertNull($e->resigned_at);
        $this->assertNull($e->last_working_day);
        $this->assertSame(['resigned', 'withdrawn'], EmployeeProgression::orderBy('id')->pluck('type')->all());
    }

    public function test_a_resignation_whose_last_day_has_passed_cannot_be_withdrawn(): void
    {
        $e = $this->emp('Adibah', ['status' => 'active']);
        app(EmploymentRecordService::class)->resign($e, '2026-08-01', '2026-08-31', 'resigned', null, null);

        $this->expectException(EmploymentTransitionException::class);
        app(EmploymentRecordService::class)->withdrawResignation($e->refresh(), null, null);
    }

    public function test_hr_withdraws_a_resignation_from_the_progression_screen(): void
    {
        $this->login('hr');
        $e = $this->emp('Adibah', ['status' => 'active']);
        app(EmploymentRecordService::class)->resign($e, now()->toDateString(), now()->addWeek()->toDateString(), 'resigned', null, null);

        $this->post(route('progression.withdraw', $e), ['remark' => 'Stays on'])->assertRedirect();

        $this->assertNull($e->refresh()->resigned_at);
        $this->assertSame('Stays on', EmployeeProgression::where('type', 'withdrawn')->value('remark'));
    }

    public function test_rehire_refuses_a_non_resigned_employee(): void
    {
        $e = $this->emp('Adibah', ['status' => 'active']);
        $this->expectException(EmploymentTransitionException::class);
        app(EmploymentRecordService::class)->rehire($e, '2026-10-01', [], null, null);
    }

    public function test_resign_last_day_before_resigned_date_is_refused(): void
    {
        $e = $this->emp('Adibah', ['status' => 'active']);
        $this->expectException(EmploymentTransitionException::class);
        app(EmploymentRecordService::class)->resign($e, '2026-08-10', '2026-08-01', 'resigned', null, null);
    }
}
