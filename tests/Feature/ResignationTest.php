<?php

namespace Tests\Feature;

use App\Http\Controllers\ResignationController;
use App\Models\Employee;
use App\Models\ExitInterview;
use App\Models\Resignation;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ResignationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Tenant $tenant;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create(['name' => 'Demo', 'email' => 'demo@example.com', 'password' => Hash::make('password')]);
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $this->user->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $this->employee = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->user->id,
            'name' => 'Demo', 'status' => 'active', 'workload' => 'green',
        ]);
    }

    private function actingInTenant(): self
    {
        $this->actingAs($this->user)->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    private function hrActor(): User
    {
        $hr = User::create(['name' => 'Boss', 'email' => 'boss@example.com', 'password' => Hash::make('password')]);
        $hr->tenants()->attach($this->tenant->id, ['role' => 'hr']);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $hr->id,
            'name' => 'Boss', 'status' => 'active', 'workload' => 'green',
        ]);

        return $hr;
    }

    public function test_employee_submits_their_own_resignation(): void
    {
        // Arrange + Act
        $this->actingInTenant()->post('/app/resignation', [
            'last_working_date' => now()->addDays(30)->toDateString(),
            'notice_days' => 30,
            'reason' => 'Moving on to a new opportunity.',
        ])->assertRedirect();

        // Assert
        $this->assertDatabaseHas('resignations', [
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'status' => 'submitted',
            'notice_days' => 30,
        ]);
    }

    public function test_owner_withdraws_their_resignation_while_submitted(): void
    {
        // Arrange
        $resignation = Resignation::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'submitted_at' => now(), 'last_working_date' => now()->addDays(30)->toDateString(),
            'notice_days' => 30, 'reason' => 'Personal reasons.', 'status' => 'submitted',
        ]);

        // Act
        $this->actingInTenant()->post("/app/resignation/{$resignation->id}/withdraw")->assertRedirect();

        // Assert
        $this->assertSame('withdrawn', $resignation->fresh()->status);
    }

    public function test_privileged_hr_acknowledges_a_resignation(): void
    {
        // Arrange
        $resignation = Resignation::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'submitted_at' => now(), 'last_working_date' => now()->addDays(30)->toDateString(),
            'notice_days' => 30, 'reason' => 'New role elsewhere.', 'status' => 'submitted',
        ]);

        // Act
        $this->actingAs($this->hrActor())->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/resignation/{$resignation->id}/acknowledge")->assertRedirect();

        // Assert
        $this->assertSame('acknowledged', $resignation->fresh()->status);
    }

    public function test_non_privileged_user_cannot_acknowledge(): void
    {
        // Arrange
        $resignation = Resignation::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'submitted_at' => now(), 'last_working_date' => now()->addDays(30)->toDateString(),
            'notice_days' => 30, 'reason' => 'Reason.', 'status' => 'submitted',
        ]);

        // Act + Assert
        $this->actingInTenant()->post("/app/resignation/{$resignation->id}/acknowledge")->assertForbidden();
        $this->assertSame('submitted', $resignation->fresh()->status);
    }

    public function test_non_privileged_user_cannot_record_an_exit_interview(): void
    {
        // Arrange
        $resignation = Resignation::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'submitted_at' => now(), 'last_working_date' => now()->addDays(30)->toDateString(),
            'notice_days' => 30, 'reason' => 'Reason.', 'status' => 'acknowledged',
        ]);

        // Act + Assert
        $this->actingInTenant()->post("/app/resignation/{$resignation->id}/interview", [
            'reason_category' => 'Personal',
        ])->assertForbidden();

        $this->assertDatabaseMissing('exit_interviews', ['resignation_id' => $resignation->id]);
    }

    public function test_privileged_hr_records_an_exit_interview_with_ratings(): void
    {
        // Arrange
        $resignation = Resignation::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'submitted_at' => now(), 'last_working_date' => now()->addDays(30)->toDateString(),
            'notice_days' => 30, 'reason' => 'Relocating.', 'status' => 'acknowledged',
        ]);

        // Act
        $this->actingAs($this->hrActor())->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/resignation/{$resignation->id}/interview", [
                'reason_category' => 'Relocation',
                'would_recommend' => 1,
                'feedback' => 'Great team, clearer growth path needed.',
                'ratings' => ['management' => 4, 'culture' => 5, 'growth' => 3, 'compensation' => 2],
            ])->assertRedirect();

        // Assert
        $interview = ExitInterview::where('resignation_id', $resignation->id)->first();
        $this->assertNotNull($interview);
        $this->assertSame('Relocation', $interview->reason_category);
        $this->assertTrue($interview->would_recommend);
        $this->assertSame(['management' => 4, 'culture' => 5, 'growth' => 3, 'compensation' => 2], $interview->ratings);
    }

    public function test_employee_cannot_see_another_employees_resignation_in_screen_data(): void
    {
        // Arrange — a second employee with an active resignation.
        $other = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => null,
            'name' => 'Other', 'status' => 'active', 'workload' => 'green',
        ]);
        Resignation::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $other->id,
            'submitted_at' => now(), 'last_working_date' => now()->addDays(30)->toDateString(),
            'notice_days' => 30, 'reason' => 'Confidential.', 'status' => 'submitted',
        ]);

        $this->actingInTenant();
        $request = request();
        $request->attributes->set('tenantRole', 'employee');
        $request->attributes->set('employee', $this->employee);
        app(CurrentTenant::class)->set($this->tenant);

        // Act
        $data = app(ResignationController::class)->screenData($request, $this->employee);

        // Assert — sees no resignation of their own and no all-resignations list.
        $this->assertNull($data['myResignation']);
        $this->assertFalse($data['privileged']);
        $this->assertCount(0, $data['allResignations']);
    }
}
