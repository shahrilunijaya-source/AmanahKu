<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\FixedTransaction;
use App\Models\PayrollItem;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Payroll\MinimumWage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PayrollWageFloorTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Employee $emp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        PayrollItem::seedFor($this->tenant);
        $hr = User::create(['name' => 'Boss', 'email' => 'boss@example.com', 'password' => Hash::make('password')]);
        $hr->tenants()->attach($this->tenant->id, ['role' => 'hr']);
        $this->emp = Employee::create(['tenant_id' => $this->tenant->id, 'name' => 'Worker', 'staff_id' => 'AC-1', 'status' => 'active', 'workload' => 'green', 'salary' => 1500]);
        $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id]);
    }

    public function test_floor_constant(): void
    {
        $this->assertSame(1700.00, MinimumWage::MONTHLY);
        $this->assertTrue(MinimumWage::below(1699.99));
        $this->assertFalse(MinimumWage::below(1700.00));
    }

    public function test_saving_a_structure_below_the_floor_warns_but_saves(): void
    {
        $this->post(route('payroll.salary'), ['employee_id' => $this->emp->id, 'bank_name' => 'Maybank', 'bank_account_no' => '1'])
            ->assertSessionHasNoErrors()->assertSessionHas('warn');
        $this->assertStringContainsString('RM1,700', session('warn'));
    }

    public function test_deduction_fixed_transaction_stores_consent_reference(): void
    {
        $loan = PayrollItem::where('tenant_id', $this->tenant->id)->where('code', 'staff-loan')->firstOrFail();
        $this->post(route('payroll.fixed-transactions.store'), [
            'employee_id' => $this->emp->id, 'payroll_item_id' => $loan->id, 'amount' => 100, 'start_period' => '2026-06',
            'consent_reference' => 'Loan agreement LA-2026-03',
        ])->assertSessionHasNoErrors();
        $this->assertSame('Loan agreement LA-2026-03', FixedTransaction::firstOrFail()->consent_reference);
    }
}
