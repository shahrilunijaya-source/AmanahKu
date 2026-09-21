<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\SalaryStructure;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SalaryStructureBankCodeTest extends TestCase
{
    use RefreshDatabase;

    public function test_saving_a_bank_name_derives_the_bank_code_and_exemptions_persist(): void
    {
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $hr = User::create(['name' => 'Boss', 'email' => 'boss@example.com', 'password' => Hash::make('password')]);
        $hr->tenants()->attach($tenant->id, ['role' => 'hr']);
        $emp = Employee::create(['tenant_id' => $tenant->id, 'name' => 'Worker', 'staff_id' => 'AC-1', 'status' => 'active', 'workload' => 'green']);

        $this->actingAs($hr)->withSession(['current_tenant' => $tenant->id])
            ->post(route('payroll.salary'), [
                'employee_id' => $emp->id, 'bank_name' => 'Maybank', 'bank_account_no' => '514011223344',
                'socso_exempt' => '1', 'hrdf_exempt' => '1',
            ])->assertSessionHasNoErrors();

        $s = SalaryStructure::where('employee_id', $emp->id)->firstOrFail();
        $this->assertSame('MBBEMYKL', $s->bank_code);
        $this->assertTrue($s->socso_exempt);
        $this->assertTrue($s->hrdf_exempt);
    }
}
