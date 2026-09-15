<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeProgression;
use App\Models\Position;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Batch Salary Adjustment: maths, band ceiling, role gate. */
class BatchSalaryAdjustmentTest extends TestCase
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
            'name' => ucfirst($role), 'status' => 'active', 'workload' => 'green', 'joined_at' => '2025-01-06',
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

    public function test_percentage_fixed_and_set_maths_round_to_2dp(): void
    {
        $this->login('hr');
        $a = $this->emp('A', ['salary' => 1234.56]);
        $b = $this->emp('B', ['salary' => 1000]);
        $this->post('/app/progression/batch/salary', ['employee_ids' => [$a->id, $b->id], 'effective_on' => '2026-03-01', 'mode' => 'increase_percent', 'value' => 10])->assertRedirect('/app/progression?batch=salary');
        $this->assertSame(1358.02, (float) $a->fresh()->salary);
        $this->assertSame(1100.0, (float) $b->fresh()->salary);
        $this->post('/app/progression/batch/salary', ['employee_ids' => [$a->id], 'effective_on' => '2026-03-02', 'mode' => 'increase_amount', 'value' => 100.005])->assertRedirect();
        $this->assertSame(1458.03, (float) $a->fresh()->salary);
        $this->post('/app/progression/batch/salary', ['employee_ids' => [$a->id], 'effective_on' => '2026-03-03', 'mode' => 'set_amount', 'value' => 2000])->assertRedirect();
        $this->assertSame(2000.0, (float) $a->fresh()->salary);
        $this->assertSame(3, EmployeeProgression::where('employee_id', $a->id)->where('type', 'updated')->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'Batch salary adjustment']);
    }

    public function test_band_maximum_refused_without_override_and_logged_with_it(): void
    {
        $this->login('hr');
        $band = Position::create(['tenant_id' => $this->tenant->id, 'title' => 'Junior', 'max_salary' => 3000]);
        $a = $this->emp('A', ['salary' => 2900, 'position_id' => $band->id]);
        $this->from('/app/progression?batch=salary')->post('/app/progression/batch/salary', ['employee_ids' => [$a->id], 'effective_on' => '2026-03-01', 'mode' => 'increase_amount', 'value' => 500])
            ->assertSessionHasErrors('employee_ids');
        $this->assertSame(2900.0, (float) $a->fresh()->salary);
        $this->post('/app/progression/batch/salary', ['employee_ids' => [$a->id], 'effective_on' => '2026-03-01', 'mode' => 'increase_amount', 'value' => 500, 'override_band' => 1])->assertRedirect();
        $this->assertSame(3400.0, (float) $a->fresh()->salary);
        $this->assertDatabaseHas('audit_logs', ['action' => 'Batch salary band override']);
    }

    public function test_management_without_director_is_forbidden_director_allowed(): void
    {
        $a = $this->emp('A', ['salary' => 1000]);
        $this->login('management');
        $this->post('/app/progression/batch/salary', ['employee_ids' => [$a->id], 'effective_on' => '2026-03-01', 'mode' => 'set_amount', 'value' => 1])->assertForbidden();
        $this->login('director');
        $this->post('/app/progression/batch/salary', ['employee_ids' => [$a->id], 'effective_on' => '2026-03-01', 'mode' => 'set_amount', 'value' => 1500])->assertRedirect();
        $this->assertSame(1500.0, (float) $a->fresh()->salary);
    }
}
