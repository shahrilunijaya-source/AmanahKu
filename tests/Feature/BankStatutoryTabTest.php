<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\SalaryStructure;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BankStatutoryTabTest extends TestCase
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
            'tenant_id' => $this->tenant->id, 'name' => $name, 'status' => 'active', 'workload' => 'green', 'joined_at' => '2026-01-05',
        ], $attrs));
    }

    public function test_salary_save_persists_bank_and_statutory_fields(): void
    {
        $this->login('hr');
        $e = $this->emp('Adibah');
        $this->from("/app/profile?emp={$e->id}&tab=bank")->post('/app/payroll/salary', [
            'employee_id' => $e->id, 'basic_salary' => 3500, 'bank_name' => 'Maybank', 'bank_account_no' => '112233445566', 'bank_holder_name' => 'Adibah Z',
            'tax_no' => 'SG123', 'tax_resident' => '0', 'tax_category' => '3', 'employee_tax_status' => 'normal',
            'child_relief' => ['under_18' => ['100' => 2, '50' => 0], 'disabled' => ['100' => 0, '50' => 1]],
            'epf_no' => 'E1', 'epf_scheme' => 'statutory', 'socso_no' => 'S1', 'socso_category' => 'category_1', 'children_relief_count' => 3,
        ])->assertRedirect("/app/profile?emp={$e->id}&tab=bank");
        $s = SalaryStructure::where('employee_id', $e->id)->firstOrFail();
        $this->assertSame('Adibah Z', $s->bank_holder_name);
        $this->assertFalse($s->tax_resident);
        $this->assertSame('3', $s->tax_category);
        $this->assertSame(2, $s->child_relief_breakdown['under_18']['100']);
        $this->assertSame(1, $s->child_relief_breakdown['disabled']['50']);
        $this->assertSame(0, $s->child_relief_breakdown['over_18_education']['100']);
        $this->assertSame('category_1', $s->socso_category);
        $this->assertSame(3, $s->children_relief_count);
    }

    public function test_invalid_statutory_options_rejected(): void
    {
        $this->login('hr');
        $e = $this->emp('Adibah');
        $this->post('/app/payroll/salary', ['employee_id' => $e->id, 'basic_salary' => 1000, 'tax_category' => '9', 'epf_scheme' => 'x', 'socso_category' => 'y'])
            ->assertSessionHasErrors(['tax_category', 'epf_scheme', 'socso_category']);
    }

    public function test_existing_payroll_salary_form_still_saves_without_new_fields(): void
    {
        $this->login('hr');
        $e = $this->emp('Adibah');
        $this->post('/app/payroll/salary', ['employee_id' => $e->id, 'basic_salary' => 2500])->assertRedirect();
        $s = SalaryStructure::where('employee_id', $e->id)->firstOrFail();
        $this->assertTrue($s->tax_resident);
        $this->assertNull($s->child_relief_breakdown);
        $this->assertSame(0, $s->children_relief_count);
    }
}
