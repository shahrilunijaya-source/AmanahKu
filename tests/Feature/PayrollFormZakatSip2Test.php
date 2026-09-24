<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\SalaryStructure;
use App\Models\Tenant;
use App\Models\User;
use App\Services\FeatureManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Payroll → Form's Zakat listing and the Borang SIP 2 wizard (spec phase 3). */
class PayrollFormZakatSip2Test extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC', 'zakat_employer_no' => 'Z9001', 'socso_employer_code' => 'B3200012345Z']);
        app(FeatureManager::class)->setTenant($this->tenant, 'module.payroll', true);
        $this->hr = User::create(['name' => 'Boss', 'email' => 'boss@example.com', 'password' => Hash::make('password')]);
        $this->hr->tenants()->attach($this->tenant->id, ['role' => 'hr']);

        $run = PayrollRun::forceCreate(['tenant_id' => $this->tenant->id, 'period' => '2026-06', 'label' => 'June 2026', 'status' => 'finalized', 'finalized_at' => now()]);
        $this->payZakat($run, 'Aminah binti Ali', 'selangor', 85.5);
        $this->payZakat($run, 'Borhan bin Omar', null, 40);
        $this->payZakat($run, 'Chong No Zakat', 'johor', 0);
    }

    private function payZakat(PayrollRun $run, string $name, ?string $authority, float $zakat): void
    {
        $e = Employee::create(['tenant_id' => $run->tenant_id, 'name' => $name, 'staff_id' => 'S-'.substr($name, 0, 3), 'status' => 'active', 'workload' => 'green', 'nric' => '880101-14-55'.random_int(10, 99)]);
        SalaryStructure::forceCreate(['tenant_id' => $run->tenant_id, 'employee_id' => $e->id, 'basic_salary' => 5000, 'zakat_monthly' => $zakat, 'zakat_authority' => $authority]);
        Payslip::forceCreate(['tenant_id' => $run->tenant_id, 'payroll_run_id' => $run->id, 'employee_id' => $e->id, 'basic' => 5000, 'gross' => 5000, 'zakat' => $zakat, 'net_pay' => 1]);
    }

    private function as(): static
    {
        return $this->actingAs($this->hr)->withSession(['current_tenant' => $this->tenant->id]);
    }

    private function zakatCard(array $query): string
    {
        $html = (string) $this->as()->get(route('app.screen', ['screen' => 'payroll-form', 'tab' => 'zakat'] + $query))->assertOk()->getContent();
        $start = (int) strpos($html, 'data-testid="zakat-form"');

        return substr($html, $start, (int) strpos($html, 'Set each person', $start) - $start);
    }

    public function test_zakat_lists_the_months_deductions_with_their_authority_and_total(): void
    {
        $card = $this->zakatCard(['period' => '2026-06']);
        $this->assertStringContainsString('Z9001', $card);
        $this->assertMatchesRegularExpression('/Aminah Binti Ali.*Selangor.*85\.50/s', $card);
        $this->assertMatchesRegularExpression('/Borhan Bin Omar.*No zakat authority set.*40\.00/s', $card);
        $this->assertStringNotContainsString('Chong', $card);
        $this->assertMatchesRegularExpression('/Total.*125\.50/s', $card);

        $this->assertStringContainsString('There is no record for Jul 2026.', $this->zakatCard(['period' => '2026-07']));
    }

    public function test_zakat_filters_by_authority_including_staff_without_one(): void
    {
        $selangor = $this->zakatCard(['period' => '2026-06', 'authority' => 'selangor']);
        $this->assertStringContainsString('Aminah', $selangor);
        $this->assertStringNotContainsString('Borhan', $selangor);

        $unset = $this->zakatCard(['period' => '2026-06', 'authority' => 'none']);
        $this->assertStringContainsString('Borhan', $unset);
        $this->assertStringNotContainsString('Aminah', $unset);
    }

    public function test_zakat_csv_is_audited_and_hr_only(): void
    {
        $csv = $this->as()->get(route('payroll.export.zakat', ['period' => '2026-06']))->assertOk()->streamedContent();
        $this->assertStringContainsString('"Aminah Binti Ali",S-Ami,', $csv);
        $this->assertStringContainsString('Total,,,,125.50', $csv);
        $this->assertSame(1, AuditLog::withoutGlobalScopes()->where('action', 'Exported zakat listing')->count());

        $staff = User::create(['name' => 'Staff', 'email' => 'staff@example.com', 'password' => Hash::make('password')]);
        $staff->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $this->actingAs($staff)->withSession(['current_tenant' => $this->tenant->id])
            ->get(route('payroll.export.zakat', ['period' => '2026-06']))->assertForbidden();
        $this->as()->get(route('payroll.export.zakat', ['period' => '2026-06', 'authority' => 'mars']))->assertNotFound();
    }

    public function test_the_staff_profile_saves_a_zakat_authority(): void
    {
        $e = Employee::withoutGlobalScopes()->where('name', 'Borhan Bin Omar')->sole();
        $this->as()->post(route('payroll.salary'), ['employee_id' => $e->id, 'zakat_monthly' => 40, 'zakat_authority' => 'kedah'])->assertRedirect();
        $this->assertSame('kedah', SalaryStructure::withoutGlobalScopes()->where('employee_id', $e->id)->value('zakat_authority'));

        $this->as()->post(route('payroll.salary'), ['employee_id' => $e->id, 'zakat_authority' => 'mars'])->assertSessionHasErrors('zakat_authority');
    }

    public function test_sip2_lists_new_hires_in_range_and_prints_only_this_companys_staff(): void
    {
        $hire = Employee::create(['tenant_id' => $this->tenant->id, 'name' => 'New Hire', 'staff_id' => 'N1', 'status' => 'active', 'workload' => 'green', 'joined_at' => '2026-03-02', 'gender' => 'female']);
        Employee::create(['tenant_id' => $this->tenant->id, 'name' => 'Old Timer', 'staff_id' => 'O1', 'status' => 'active', 'workload' => 'green', 'joined_at' => '2019-03-02']);
        $other = Tenant::create(['slug' => 'rival', 'name' => 'Rival', 'initials' => 'RV']);
        $theirs = Employee::create(['tenant_id' => $other->id, 'name' => 'Rival Hire', 'staff_id' => 'R1', 'status' => 'active', 'workload' => 'green', 'joined_at' => '2026-03-02']);

        $html = (string) $this->as()->get(route('app.screen', ['screen' => 'payroll-form', 'tab' => 'sip2', 'from' => '2026-01-01', 'to' => '2026-06-30']))->assertOk()->getContent();
        $start = (int) strpos($html, 'data-testid="sip2-wizard"');
        $card = substr($html, $start, (int) strpos($html, 'Generate PDF', $start) - $start);
        $this->assertMatchesRegularExpression('/value="'.$hire->id.'" checked aria-label="New Hire"/', $card);
        $this->assertStringNotContainsString('Old Timer', $card);
        $this->assertStringNotContainsString('Rival Hire', $card);

        $pdf = $this->as()->get(route('payroll.sip2.pdf', ['employees' => [$hire->id, $theirs->id]]))->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('content-type'));
        $this->assertSame(1, AuditLog::withoutGlobalScopes()->where('action', 'Downloaded Borang SIP 2')->where('target', '1 staff')->count());

        $this->as()->get(route('payroll.sip2.pdf', ['employees' => [$theirs->id]]))->assertNotFound();
    }
}
