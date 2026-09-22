<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeProgression;
use App\Models\Tenant;
use App\Models\User;
use App\Services\EmploymentRecordService;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Correcting a saved progression row: remark and effective date only, snapshot untouched. */
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
