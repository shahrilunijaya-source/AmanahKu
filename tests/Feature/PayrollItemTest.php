<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\FixedTransaction;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\SalaryStructure;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Database\Seeders\PayrollItemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The pay-item catalogue: itemised payslip lines, flag-derived EPF/PERKESO wage bases,
 * and the HR-facing catalogue itself. See CLAUDE.md's "payroll module polish" pass.
 */
class PayrollItemTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $hr;

    private Employee $emp1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $this->hr = User::create(['name' => 'Boss', 'email' => 'boss@example.com', 'password' => Hash::make('password')]);
        $this->hr->tenants()->attach($this->tenant->id, ['role' => 'hr']);

        $this->emp1 = Employee::create(['tenant_id' => $this->tenant->id, 'name' => 'Worker', 'status' => 'active', 'workload' => 'green']);
        SalaryStructure::forceCreate(['tenant_id' => $this->tenant->id, 'employee_id' => $this->emp1->id, 'basic_salary' => 5000]);
    }

    private function actingHr(): self
    {
        $this->actingAs($this->hr)->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    private function createRun(string $period = '2026-06'): PayrollRun
    {
        $this->actingHr()->post('/app/payroll/runs', ['period' => $period])->assertRedirect();

        return PayrollRun::where('period', $period)->firstOrFail();
    }

    public function test_payslip_lines_sum_back_to_the_stored_columns(): void
    {
        PayrollItem::seedFor($this->tenant);
        $fixedAllowance = PayrollItem::where('tenant_id', $this->tenant->id)->where('code', 'fixed-allowance')->firstOrFail();
        $mealAllowance = PayrollItem::where('tenant_id', $this->tenant->id)->where('code', 'meal-allowance')->firstOrFail();
        FixedTransaction::forceCreate(['tenant_id' => $this->tenant->id, 'employee_id' => $this->emp1->id, 'payroll_item_id' => $fixedAllowance->id, 'amount' => 200, 'start_period' => '2026-06']);
        FixedTransaction::forceCreate(['tenant_id' => $this->tenant->id, 'employee_id' => $this->emp1->id, 'payroll_item_id' => $mealAllowance->id, 'amount' => 100, 'start_period' => '2026-06']);

        $run = $this->createRun();
        $slip = $run->payslips()->where('employee_id', $this->emp1->id)->firstOrFail();

        $lines = $slip->lines;
        $this->assertSame((float) $slip->basic, (float) $lines->where('name', 'Basic Salary')->sum('amount'));
        $this->assertSame((float) $slip->allowances_total, (float) $lines->whereIn('name', ['Fixed Allowance', 'Meal Allowance'])->sum('amount'));

        // Editing the payslip adds overtime, a bonus, an addition and unpaid days.
        $this->actingHr()->post("/app/payroll/payslips/{$slip->id}", [
            'overtime_hours' => 10,
            'bonus' => 500,
            'unpaid_days' => 1,
            'add_name' => ['Travel claim'],
            'add_amount' => [80],
        ])->assertRedirect();
        $slip->refresh();

        $lines = $slip->lines;
        $this->assertSame((float) $slip->overtime_amount, (float) $lines->where('name', 'Overtime')->sum('amount'));
        $this->assertSame((float) $slip->bonus, (float) $lines->where('name', 'Bonus')->sum('amount'));
        $this->assertSame(80.0, (float) $lines->where('name', 'Travel claim')->sum('amount'));
        $this->assertSame((float) $slip->unpaid_deduction, (float) $lines->where('name', 'Unpaid Leave Deduction')->sum('amount'));
        // Basic + Fixed Transaction lines survive the edit untouched (source 'salary'/'fixed-transaction').
        $this->assertSame((float) $slip->basic, (float) $lines->where('name', 'Basic Salary')->sum('amount'));
        $this->assertSame((float) $slip->allowances_total, (float) $lines->whereIn('name', ['Fixed Allowance', 'Meal Allowance'])->sum('amount'));
    }

    public function test_epf_liable_false_removes_the_amount_from_the_epf_base_but_not_gross(): void
    {
        PayrollItem::forceCreate([
            'tenant_id' => $this->tenant->id,
            'code' => 'fixed-allowance',
            'name' => 'Fixed Allowance',
            'type' => 'earning',
            'epf_liable' => false,
            'perkeso_liable' => true,
            'pcb_taxable' => true,
            'source' => 'salary',
            'is_system' => true,
            'active' => true,
            'sort_order' => 0,
        ]);
        $item = PayrollItem::where('tenant_id', $this->tenant->id)->where('code', 'fixed-allowance')->firstOrFail();
        FixedTransaction::forceCreate([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->emp1->id, 'payroll_item_id' => $item->id,
            'amount' => 500, 'start_period' => '2026-06', 'remarks' => 'Transport',
        ]);

        $run = $this->createRun();
        $slip = $run->payslips()->where('employee_id', $this->emp1->id)->firstOrFail();

        // Gross still includes the allowance...
        $this->assertSame(5500.0, (float) $slip->gross);
        // ...but EPF is charged on basic alone (11% of 5000 = 550), not on 5500.
        $this->assertSame(550.00, (float) $slip->epf_employee);
    }

    public function test_seeded_catalogue_reproduces_the_hardcoded_bonus_and_overtime_behaviour(): void
    {
        // A second, identical tenant/employee with the catalogue seeded — same salary,
        // bonus and overtime should still produce identical statutory figures.
        $seeded = Tenant::create(['slug' => 'beta', 'name' => 'Beta', 'initials' => 'BT']);
        $hr2 = User::create(['name' => 'Boss2', 'email' => 'boss2@example.com', 'password' => Hash::make('password')]);
        $hr2->tenants()->attach($seeded->id, ['role' => 'hr']);
        $emp = Employee::create(['tenant_id' => $seeded->id, 'name' => 'Worker2', 'status' => 'active', 'workload' => 'green']);
        SalaryStructure::forceCreate(['tenant_id' => $seeded->id, 'employee_id' => $emp->id, 'basic_salary' => 5200]);

        $this->actingAs($hr2)->withSession(['current_tenant' => $seeded->id]);
        (new PayrollItemSeeder)->run();
        $this->post('/app/payroll/runs', ['period' => '2026-06'])->assertRedirect();
        $seededSlip = PayrollRun::where('tenant_id', $seeded->id)->where('period', '2026-06')->firstOrFail()
            ->payslips()->where('employee_id', $emp->id)->firstOrFail();
        $this->post("/app/payroll/payslips/{$seededSlip->id}", ['overtime_hours' => 10, 'bonus' => 500])->assertRedirect();
        $seededSlip->refresh();

        // Baseline: same numbers, no catalogue rows for this tenant at all. Re-establish
        // this tenant's context first — CurrentTenant (a request-scoped singleton, only
        // updated by actual HTTP requests) is still pinned to $seeded from the requests
        // above, and an unscoped update here would silently touch zero rows.
        app(CurrentTenant::class)->set($this->tenant);
        SalaryStructure::where('employee_id', $this->emp1->id)->update(['basic_salary' => 5200]);
        $baseRun = $this->createRun();
        $baseSlip = $baseRun->payslips()->where('employee_id', $this->emp1->id)->firstOrFail();
        $this->actingHr()->post("/app/payroll/payslips/{$baseSlip->id}", ['overtime_hours' => 10, 'bonus' => 500])->assertRedirect();
        $baseSlip->refresh();

        $this->assertSame((float) $baseSlip->epf_employee, (float) $seededSlip->epf_employee);
        $this->assertSame((float) $baseSlip->epf_employer, (float) $seededSlip->epf_employer);
        $this->assertSame((float) $baseSlip->socso_employee, (float) $seededSlip->socso_employee);
        $this->assertSame((float) $baseSlip->socso_employer, (float) $seededSlip->socso_employer);
    }

    public function test_renaming_a_catalogue_item_does_not_alter_an_issued_payslips_line_name(): void
    {
        $run = $this->createRun();
        $slip = $run->payslips()->where('employee_id', $this->emp1->id)->firstOrFail();
        $basicLine = $slip->lines()->where('name', 'Basic Salary')->firstOrFail();
        $this->assertNull($basicLine->payroll_item_id); // no catalogue seeded for this tenant

        // Seed the catalogue, attach it retroactively isn't possible (item_id stays null for
        // pre-existing lines), then rename — the point being the stored line name is a
        // snapshot regardless.
        (new PayrollItemSeeder)->run();
        $item = PayrollItem::where('tenant_id', $this->tenant->id)->where('code', 'basic-salary')->firstOrFail();
        $item->update(['name' => 'Renamed Basic']);

        $basicLine->refresh();
        $this->assertSame('Basic Salary', $basicLine->name);
        $this->assertSame('Renamed Basic', $item->fresh()->name);
    }

    public function test_a_company_cannot_delete_a_system_payroll_item(): void
    {
        (new PayrollItemSeeder)->run();
        $item = PayrollItem::where('tenant_id', $this->tenant->id)->where('code', 'basic-salary')->firstOrFail();
        $this->assertTrue($item->is_system);

        $this->actingHr()->post("/app/payroll/items/{$item->id}/delete")->assertStatus(422);
        $this->assertDatabaseHas('payroll_items', ['id' => $item->id]);
    }

    public function test_seed_for_run_twice_does_not_duplicate_rows(): void
    {
        PayrollItem::seedFor($this->tenant);
        PayrollItem::seedFor($this->tenant);

        $this->assertSame(
            count(PayrollItem::SYSTEM_ITEMS),
            PayrollItem::where('tenant_id', $this->tenant->id)->count()
        );
    }

    public function test_seed_for_does_not_revert_an_hr_edited_item(): void
    {
        PayrollItem::seedFor($this->tenant);
        $item = PayrollItem::where('tenant_id', $this->tenant->id)->where('code', 'bonus')->firstOrFail();
        $item->update(['epf_liable' => false]);

        PayrollItem::seedFor($this->tenant);

        $this->assertFalse($item->fresh()->epf_liable);
        $this->assertSame(
            count(PayrollItem::SYSTEM_ITEMS),
            PayrollItem::where('tenant_id', $this->tenant->id)->count()
        );
    }

    public function test_hr_can_edit_flags_of_any_item_including_system_ones(): void
    {
        (new PayrollItemSeeder)->run();
        $item = PayrollItem::where('tenant_id', $this->tenant->id)->where('code', 'bonus')->firstOrFail();

        $this->actingHr()->post("/app/payroll/items/{$item->id}", [
            'name' => $item->name,
            'epf_liable' => '0',
            'perkeso_liable' => '1',
            'pcb_taxable' => '1',
            'active' => '1',
        ])->assertRedirect();

        $item->refresh();
        $this->assertFalse($item->epf_liable);
        $this->assertTrue($item->perkeso_liable);
    }
}
