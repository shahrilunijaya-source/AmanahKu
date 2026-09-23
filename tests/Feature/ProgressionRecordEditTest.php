<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeProgression;
use App\Models\Tenant;
use App\Models\User;
use App\Services\EmploymentRecordService;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Correcting a saved progression row: remark, effective date, and a resigned row's last working day. */
class ProgressionRecordEditTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        app(CurrentTenant::class)->set($this->tenant);
    }

    private function login(string $role): Employee
    {
        $user = User::create(['name' => ucfirst($role), 'email' => $role.'@example.com', 'password' => Hash::make('password')]);
        $user->tenants()->attach($this->tenant->id, ['role' => $role]);
        $employee = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'name' => ucfirst($role), 'status' => 'active', 'workload' => 'green', 'joined_at' => '2025-01-06',
        ]);
        $this->actingAs($user)->withSession(['current_tenant' => $this->tenant->id]);

        return $employee;
    }

    private function confirmedRow(): EmployeeProgression
    {
        $staff = Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Aiman', 'status' => 'probation',
            'workload' => 'green', 'joined_at' => '2026-01-05',
        ]);

        return app(EmploymentRecordService::class)->confirm($staff, '2026-09-18', [], 'Confirmd after probaton', null);
    }

    private function resignedRow(): EmployeeProgression
    {
        $staff = Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Halim', 'status' => 'active',
            'workload' => 'green', 'joined_at' => '2025-06-02',
        ]);

        return app(EmploymentRecordService::class)->resign($staff, '2026-08-20', '2026-08-24', 'resigned', null, null);
    }

    public function test_hr_corrects_the_remark_and_date_and_the_employee_date_follows(): void
    {
        $this->login('hr');
        $row = $this->confirmedRow();
        $snapshot = $row->snapshot;

        $this->post('/app/progression/record/'.$row->id, [
            'effective_on' => '2026-09-15',
            'remark' => 'Confirmed after probation',
        ])->assertRedirect();

        $row->refresh();
        $this->assertSame('Confirmed after probation', $row->remark);
        $this->assertSame('2026-09-15', $row->effective_on->toDateString());
        $this->assertSame($snapshot, $row->snapshot);
        $this->assertSame('2026-09-15', $row->employee->refresh()->confirmed_at->toDateString());
        $this->assertDatabaseHas('audit_logs', ['action' => 'Edited progression record']);
    }

    public function test_the_edit_form_shows_on_both_the_progression_screen_and_the_profile_timeline_for_hr(): void
    {
        $this->login('hr');
        $row = $this->confirmedRow();

        $this->get('/app/progression?emp='.$row->employee_id.'&action=confirmation')
            ->assertOk()->assertSee(route('progression.record.update', $row), false);
        $this->get('/app/profile?emp='.$row->employee_id.'&tab=timeline')
            ->assertOk()->assertSee(route('progression.record.update', $row), false);
    }

    public function test_the_edit_form_does_not_show_on_the_profile_timeline_for_a_manager(): void
    {
        $row = $this->confirmedRow();
        $this->login('manager');

        $this->get('/app/profile?emp='.$row->employee_id.'&tab=timeline')
            ->assertOk()->assertDontSee(route('progression.record.update', $row), false);
    }

    public function test_hr_corrects_the_last_working_day_on_a_resigned_row(): void
    {
        $this->login('hr');
        $row = $this->resignedRow();

        $this->post('/app/progression/record/'.$row->id, [
            'effective_on' => '2026-08-20',
            'last_working_day' => '2026-08-31',
            'remark' => null,
        ])->assertRedirect();

        $row->refresh();
        $this->assertSame('2026-08-31', $row->snapshot['last_working_day']);
        $this->assertSame('2026-08-31', $row->employee->refresh()->last_working_day->toDateString());
    }

    public function test_the_last_working_day_cannot_move_before_the_resignation_date(): void
    {
        $this->login('hr');
        $row = $this->resignedRow();

        $this->post('/app/progression/record/'.$row->id, [
            'effective_on' => '2026-08-20',
            'last_working_day' => '2026-08-10',
        ])->assertSessionHasErrors('last_working_day');
        $this->assertSame('2026-08-24', $row->refresh()->snapshot['last_working_day']);
    }

    public function test_a_correction_that_sends_no_optional_fields_still_saves(): void
    {
        $this->login('hr');
        $row = $this->resignedRow();

        $this->post('/app/progression/record/'.$row->id, ['effective_on' => '2026-08-21'])->assertRedirect();

        $row->refresh();
        $this->assertSame('2026-08-21', $row->effective_on->toDateString());
        $this->assertNull($row->remark);
        $this->assertSame('2026-08-24', $row->snapshot['last_working_day'], 'an absent field leaves the date alone');
    }

    public function test_hr_clears_the_last_working_day_and_the_open_cessation_notice_goes_with_it(): void
    {
        $this->login('hr');
        $row = $this->resignedRow();
        $this->assertDatabaseHas('payroll_notices', ['employee_id' => $row->employee_id, 'type' => 'cp22a', 'filed_on' => null]);

        $this->post('/app/progression/record/'.$row->id, [
            'effective_on' => '2026-08-20',
            'last_working_day' => '',
        ])->assertRedirect();

        $this->assertNull($row->refresh()->snapshot['last_working_day']);
        $this->assertNull($row->employee->refresh()->last_working_day);
        $this->assertDatabaseMissing('payroll_notices', ['employee_id' => $row->employee_id, 'type' => 'cp22a', 'filed_on' => null]);
    }

    public function test_correcting_a_resignation_the_person_was_rehired_after_leaves_their_live_record_alone(): void
    {
        $this->login('hr');
        $row = $this->resignedRow();
        app(EmploymentRecordService::class)->rehire($row->employee->refresh(), '2026-09-01', [], null, null);

        // On or after the rehire date, so the hire-date guard lets the correction through.
        $this->post('/app/progression/record/'.$row->id, [
            'effective_on' => '2026-09-05',
            'last_working_day' => '2026-09-30',
        ])->assertRedirect();

        $this->assertSame('2026-09-30', $row->refresh()->snapshot['last_working_day'], 'the historical row is still corrected');
        $employee = $row->employee->refresh();
        $this->assertNull($employee->last_working_day);
        $this->assertNull($employee->resigned_at);
        $this->assertSame('probation', $employee->status);
    }

    public function test_a_resignation_can_be_corrected_to_its_true_date_before_the_rehire(): void
    {
        $this->login('hr');
        $row = $this->resignedRow();
        app(EmploymentRecordService::class)->rehire($row->employee->refresh(), '2026-09-01', [], null, null);

        $this->post('/app/progression/record/'.$row->id, [
            'effective_on' => '2026-08-15',
            'last_working_day' => '2026-08-18',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $row->refresh();
        $this->assertSame('2026-08-15', $row->effective_on->toDateString());
        $this->assertSame('2026-08-18', $row->snapshot['last_working_day']);

        $employee = $row->employee->refresh();
        $this->assertNull($employee->resigned_at, 'the live record still belongs to the rehire');
        $this->assertNull($employee->last_working_day);
        $this->assertSame('2026-09-01', $employee->joined_at->toDateString());
        $this->assertSame('probation', $employee->status);
    }

    public function test_the_date_still_cannot_move_before_the_employment_the_row_belonged_to(): void
    {
        $this->login('hr');
        $service = app(EmploymentRecordService::class);
        $staff = Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Halim', 'status' => 'active',
            'workload' => 'green', 'joined_at' => '2025-06-02',
        ]);
        $service->hire($staff);
        $row = $service->resign($staff, '2026-08-20', '2026-08-24', 'resigned', null, null);
        $service->rehire($staff->refresh(), '2026-09-01', [], null, null);

        $this->post('/app/progression/record/'.$row->id, ['effective_on' => '2025-05-01'])
            ->assertSessionHasErrors('effective_on');
        $this->assertSame('2026-08-20', $row->refresh()->effective_on->toDateString());
    }

    public function test_a_rejected_correction_shows_the_reason_on_the_screen_it_came_from(): void
    {
        $this->login('hr');
        $row = $this->resignedRow();
        $from = '/app/profile?emp='.$row->employee_id.'&tab=timeline';

        $this->from($from)->post('/app/progression/record/'.$row->id, [
            'row' => $row->id,
            'effective_on' => '2026-08-20',
            'last_working_day' => '2026-08-10',
        ])->assertRedirect($from);

        $this->get($from)->assertOk()->assertSee('Last working day cannot be before the resignation date.');
    }

    /** @return array<string, mixed> */
    private function correctionPayload(EmployeeProgression $row, array $overrides = []): array
    {
        return $overrides + [
            'effective_on' => $row->effective_on->toDateString(),
            'status' => $row->snapshot['status'],
        ];
    }

    public function test_hr_corrects_the_snapshot_fields_and_the_live_record_is_left_alone(): void
    {
        $this->login('hr');
        $row = $this->confirmedRow();
        $department = Department::create(['tenant_id' => $this->tenant->id, 'name' => 'Finance']);

        $this->post('/app/progression/record/'.$row->id, $this->correctionPayload($row, [
            'department_id' => $department->id,
            'division' => 'Corporate',
            'job_grade' => 'G7',
            'probation_months' => '6',
            'salary' => '4200.50',
            'pay_mode' => 'monthly',
            'status' => 'active',
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $snapshot = $row->refresh()->snapshot;
        $this->assertSame('Finance', $snapshot['department']);
        $this->assertSame('Corporate', $snapshot['division']);
        $this->assertSame('G7', $snapshot['job_grade']);
        $this->assertSame(6, $snapshot['probation_months']);
        $this->assertSame(4200.50, $snapshot['basic_salary']);
        $this->assertSame('monthly', $snapshot['pay_mode']);

        $employee = $row->employee->refresh();
        $this->assertNull($employee->department_id, 'a correction is history, not a new employment event');
        $this->assertNull($employee->division);
        $this->assertNull($employee->salary);
    }

    public function test_the_correction_writes_one_audit_row_per_changed_field(): void
    {
        $this->login('hr');
        $row = $this->confirmedRow();

        $this->post('/app/progression/record/'.$row->id, $this->correctionPayload($row, [
            'division' => 'Corporate',
            'job_grade' => 'G7',
        ]))->assertRedirect();

        foreach (['division' => 'Corporate', 'job_grade' => 'G7'] as $field => $new) {
            $this->assertDatabaseHas('audit_logs', [
                'action' => 'employee_progression.'.$field,
                'subject_id' => $row->id,
                'old_value' => json_encode(null),
                'new_value' => json_encode($new),
            ]);
        }
        $this->assertDatabaseMissing('audit_logs', ['action' => 'employee_progression.category']);
    }

    public function test_the_reporting_manager_is_stored_back_as_an_id_and_name_pair(): void
    {
        $manager = $this->login('hr');
        $row = $this->confirmedRow();

        $this->post('/app/progression/record/'.$row->id, $this->correctionPayload($row, ['reports_to_id' => $manager->id]))
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(['id' => $manager->id, 'name' => $manager->name], $row->refresh()->snapshot['reports_to']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'employee_progression.reports_to', 'new_value' => json_encode($manager->name)]);
    }

    public function test_management_may_correct_a_record_but_not_its_salary(): void
    {
        $this->login('management');
        $row = $this->confirmedRow();

        $this->post('/app/progression/record/'.$row->id, $this->correctionPayload($row, [
            'division' => 'Corporate',
            'salary' => '9999',
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $snapshot = $row->refresh()->snapshot;
        $this->assertSame('Corporate', $snapshot['division']);
        $this->assertNull($snapshot['basic_salary'], 'only director and hr may touch salary');
    }

    public function test_the_salary_field_is_hidden_from_the_edit_form_for_management(): void
    {
        $row = $this->confirmedRow();
        $this->login('management');

        $this->get('/app/profile?emp='.$row->employee_id.'&tab=timeline')
            ->assertOk()
            ->assertSee(route('progression.record.update', $row), false)
            ->assertSee('name="division"', false)
            ->assertDontSee('name="salary"', false);
    }

    public function test_the_edit_form_shows_every_snapshot_field_to_hr(): void
    {
        $this->login('hr');
        $row = $this->confirmedRow();

        $page = $this->get('/app/profile?emp='.$row->employee_id.'&tab=timeline')->assertOk();
        foreach (['status', 'department_id', 'position_id', 'branch_id', 'employment_type_id', 'reports_to_id', 'division', 'section', 'job_grade', 'category', 'line', 'probation_months', 'probation_days', 'salary', 'pay_mode', 'payment_term', 'payment_method'] as $field) {
            $page->assertSee('name="'.$field.'"', false);
        }
    }

    public function test_a_manager_cannot_edit_a_record(): void
    {
        $row = $this->confirmedRow();
        $this->login('manager');

        $this->post('/app/progression/record/'.$row->id, ['effective_on' => '2026-09-15', 'remark' => 'x'])->assertForbidden();
        $this->assertSame('Confirmd after probaton', $row->refresh()->remark);
    }

    public function test_the_date_cannot_move_before_the_hire_date(): void
    {
        $this->login('hr');
        $row = $this->confirmedRow();

        $this->post('/app/progression/record/'.$row->id, ['effective_on' => '2025-12-01', 'remark' => null])
            ->assertSessionHasErrors('effective_on');
        $this->assertSame('2026-09-18', $row->refresh()->effective_on->toDateString());
    }
}
