<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\PayrollCp38Month;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\SalaryStructure;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Payroll\PayslipPdfData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** CP38 the Worksy way: a 12-month grid per employee, one amount per month. */
class PayrollCp38Test extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $hr;

    private Employee $emp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC',
            'employer_tin' => '1234567890', 'epf_employer_no' => '12345678', 'socso_employer_code' => 'A123']);
        PayrollItem::seedFor($this->tenant);
        $this->hr = User::create(['name' => 'Boss', 'email' => 'boss@example.com', 'password' => Hash::make('password')]);
        $this->hr->tenants()->attach($this->tenant->id, ['role' => 'hr']);
        $this->emp = Employee::create(['tenant_id' => $this->tenant->id, 'name' => 'Worker', 'staff_id' => 'AC-1', 'status' => 'active', 'workload' => 'green',
            'nric' => '880101-14-5500', 'date_of_birth' => '1988-01-01', 'joined_at' => '2020-01-01', 'salary' => 3000]);
        SalaryStructure::forceCreate(['tenant_id' => $this->tenant->id, 'employee_id' => $this->emp->id, 'basic_salary' => 3000, 'nationality' => 'citizen',
            'bank_name' => 'Maybank', 'bank_code' => 'MBBEMYKL', 'bank_account_no' => '1', 'epf_no' => '1', 'socso_no' => '1', 'tax_no' => 'SG1']);
        $this->actingAs($this->hr)->withSession(['current_tenant' => $this->tenant->id]);
    }

    /** Create and finalize one monthly run, and give back the employee's payslip. */
    private function runMonth(string $period): Payslip
    {
        $this->post(route('payroll.runs.create'), ['period' => $period, 'payment_date' => $period.'-28'])->assertSessionHasNoErrors();
        $run = PayrollRun::where('period', $period)->firstOrFail();
        $this->post(route('payroll.runs.finalize', $run), ['payment_date' => $period.'-28'])->assertSessionHasNoErrors();

        return Payslip::where('payroll_run_id', $run->id)->where('employee_id', $this->emp->id)->firstOrFail();
    }

    /** @param array<int, float|string|null> $byMonth month number => amount; other months blank */
    private function saveGrid(int $year, array $byMonth): TestResponse
    {
        $amounts = [];
        foreach (range(1, 12) as $m) {
            $amounts[] = $byMonth[$m] ?? null;
        }

        return $this->post(route('payroll.cp38.update'), ['employee_id' => $this->emp->id, 'year' => $year, 'amounts' => $amounts]);
    }

    public function test_saving_the_grid_stores_one_row_per_month_with_an_amount(): void
    {
        $this->saveGrid(2026, [7 => 1818, 8 => 1818, 9 => '0'])
            ->assertRedirect(route('app.screen', ['screen' => 'payroll-transaction', 'tab' => 'cp38', 'emp' => $this->emp->id, 'cp38_year' => 2026]));

        $this->assertSame(['2026-07' => 1818.0, '2026-08' => 1818.0],
            PayrollCp38Month::orderBy('period')->pluck('amount', 'period')->all());
        $this->assertSame(1, AuditLog::where('action', 'Updated CP38')->count());
    }

    public function test_blanking_a_month_removes_it(): void
    {
        $this->saveGrid(2026, [7 => 500, 8 => 500]);
        $this->saveGrid(2026, [7 => 500]);

        $this->assertSame(['2026-07'], PayrollCp38Month::pluck('period')->all());
    }

    public function test_each_run_deducts_that_months_figure(): void
    {
        $this->saveGrid(2026, [6 => 300, 7 => 120.5]);

        $this->assertSame(300.0, (float) $this->runMonth('2026-06')->cp38);
        $this->assertSame(120.5, (float) $this->runMonth('2026-07')->cp38);
        $this->assertSame(0.0, (float) $this->runMonth('2026-08')->cp38);
    }

    public function test_a_finalized_month_cannot_be_changed(): void
    {
        $this->saveGrid(2026, [6 => 300]);
        $this->runMonth('2026-06');

        $this->saveGrid(2026, [6 => 999, 7 => 50])->assertSessionHasNoErrors();

        $this->assertSame(['2026-06' => 300.0, '2026-07' => 50.0], PayrollCp38Month::orderBy('period')->pluck('amount', 'period')->all());
    }

    public function test_the_months_amount_prints_on_the_payslip_pdf(): void
    {
        $this->saveGrid(2026, [6 => 300]);
        $payslip = $this->runMonth('2026-06');

        $data = app(PayslipPdfData::class)->build($payslip->fresh(['lines']));
        $cp38 = $data['deductions']->firstWhere('description', 'CP38');
        $this->assertNotNull($cp38);
        $this->assertSame(300.0, $cp38['total']);

        $response = $this->get(route('payroll.payslips.pdf', $payslip))->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
    }

    public function test_grid_must_have_twelve_non_negative_months(): void
    {
        $this->post(route('payroll.cp38.update'), ['employee_id' => $this->emp->id, 'year' => 2026, 'amounts' => [1, 2]])->assertSessionHasErrors('amounts');
        $this->saveGrid(2026, [1 => -5])->assertSessionHasErrors('amounts.0');
        $this->assertSame(0, PayrollCp38Month::count());
    }

    public function test_another_tenants_employee_cannot_be_set(): void
    {
        $other = Tenant::create(['slug' => 'rival', 'name' => 'Rival', 'initials' => 'RV']);
        $theirs = Employee::create(['tenant_id' => $other->id, 'name' => 'Theirs', 'status' => 'active', 'workload' => 'green']);

        $this->post(route('payroll.cp38.update'), ['employee_id' => $theirs->id, 'year' => 2026, 'amounts' => array_fill(0, 12, 10)])
            ->assertSessionHasErrors('employee_id');
        $this->assertSame(0, PayrollCp38Month::withoutGlobalScopes()->count());
    }

    public function test_a_manager_cannot_set_cp38(): void
    {
        $manager = User::create(['name' => 'Mgr', 'email' => 'mgr@example.com', 'password' => Hash::make('password')]);
        $manager->tenants()->attach($this->tenant->id, ['role' => 'manager']);

        $this->actingAs($manager)->withSession(['current_tenant' => $this->tenant->id])
            ->post(route('payroll.cp38.update'), ['employee_id' => $this->emp->id, 'year' => 2026, 'amounts' => array_fill(0, 12, 10)])
            ->assertForbidden();
    }
}
