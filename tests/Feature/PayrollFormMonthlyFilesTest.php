<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\SalaryStructure;
use App\Models\Tenant;
use App\Models\User;
use App\Services\FeatureManager;
use App\Services\Payroll\Statutory\MergedPayslips;
use App\Services\Payroll\Statutory\StatutoryFileRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Payroll → Form's monthly tabs: EPF Borang A, Perkeso 8A, Perkeso SIP, CP39, HRDF. */
class PayrollFormMonthlyFilesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $hr;

    private PayrollRun $run;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC',
            'employer_tin' => '9123456708', 'epf_employer_no' => '012345678', 'socso_employer_code' => 'B3200012345Z', 'hrdf_registration_no' => 'HRD-7788']);
        app(FeatureManager::class)->setTenant($this->tenant, 'module.payroll', true);
        app(FeatureManager::class)->setTenant($this->tenant, 'payroll.hrdf', '1');
        $this->hr = User::create(['name' => 'Boss', 'email' => 'boss@example.com', 'password' => Hash::make('password')]);
        $this->hr->tenants()->attach($this->tenant->id, ['role' => 'hr']);

        $this->run = PayrollRun::forceCreate(['tenant_id' => $this->tenant->id, 'period' => '2026-06', 'label' => 'June 2026', 'status' => 'finalized', 'finalized_at' => now()]);
        $this->slip($this->tenant, $this->run, 'Aminah binti Ali', '880101-14-5500');
        $this->slip($this->tenant, $this->run, 'Tan Wei Ming', '900202-10-5511');
    }

    private function slip(Tenant $tenant, PayrollRun $run, string $name, string $nric): void
    {
        $emp = Employee::create(['tenant_id' => $tenant->id, 'name' => $name, 'staff_id' => 'X-'.substr($nric, 0, 4), 'status' => 'active', 'workload' => 'green', 'nric' => $nric]);
        SalaryStructure::forceCreate(['tenant_id' => $tenant->id, 'employee_id' => $emp->id, 'basic_salary' => 5000, 'epf_no' => 'EPF'.substr($nric, 0, 4), 'socso_no' => substr($nric, 0, 6), 'tax_no' => 'SG'.substr($nric, 0, 6)]);
        Payslip::forceCreate(['tenant_id' => $tenant->id, 'payroll_run_id' => $run->id, 'employee_id' => $emp->id,
            'basic' => 5000, 'gross' => 5000, 'epf_employee' => 550, 'epf_employer' => 650, 'socso_employee' => 24.75, 'socso_employer' => 86.65,
            'eis_employee' => 9.90, 'eis_employer' => 9.90, 'pcb' => 120, 'hrdf_levy' => 50, 'net_pay' => 1]);
    }

    /** Just the one tab's card, so names listed elsewhere on the page don't count. */
    private function card(string $tab, ?string $period = '2026-06'): string
    {
        $html = (string) $this->form($tab, $period)->assertOk()->getContent();
        $start = (int) strpos($html, 'data-testid="monthly-file-'.$tab.'"');

        $ends = array_filter([strpos($html, '</table>', $start), ($empty = strpos($html, 'There is no record', $start)) === false ? false : $empty + 60]);
        $end = min($ends);

        return substr($html, $start, $end - $start);
    }

    private function form(string $tab, ?string $period = '2026-06')
    {
        return $this->actingAs($this->hr)->withSession(['current_tenant' => $this->tenant->id])
            ->get(route('app.screen', ['screen' => 'payroll-form', 'tab' => $tab, 'period' => $period]));
    }

    public function test_monthly_tabs_list_the_rows_and_totals_of_a_finalized_month(): void
    {
        $this->form('borang-a')->assertOk()
            ->assertSee('data-testid="monthly-file-borang-a"', false)
            ->assertSeeInOrder(['Aminah Binti Ali', '880101145500', 'EPF8801', '5,000.00', '650.00', '550.00'])
            ->assertSeeInOrder(['Total', '10,000.00', '1,300.00', '1,100.00'])
            ->assertSee(route('payroll.export.statutory-file', [$this->run, 'kwsp-form-a']), false);

        $this->form('borang-8a')->assertOk()->assertSeeInOrder(['Tan Wei Ming', '173.30', '49.50', '19.80', '19.80']);
        $this->form('hrdf')->assertOk()->assertSeeInOrder(['Total', '10,000.00', '100.00'])->assertSee('Download levy worksheet');
    }

    public function test_generator_tabs_offer_the_file_for_the_chosen_month(): void
    {
        $this->form('cp39')->assertOk()->assertSeeText('2 employees in the file')
            ->assertSee(route('payroll.export.statutory-file', [$this->run, 'cp39']), false);
        // Perkeso Monthly SIP is the same combined ASSIST file as Borang 8A.
        $this->form('perkeso-sip')->assertOk()->assertSee(route('payroll.export.statutory-file', [$this->run, 'perkeso-8a']), false);
    }

    public function test_a_month_without_a_finalized_run_shows_worksys_empty_text(): void
    {
        $card = $this->card('borang-a', '2026-09');
        $this->assertStringContainsString('There is no record for Sep 2026.', $card);
        $this->assertStringNotContainsString('Aminah', $card);

        $draft = PayrollRun::forceCreate(['tenant_id' => $this->tenant->id, 'period' => '2026-07', 'label' => 'July 2026', 'status' => 'draft']);
        $this->slip($this->tenant, $draft, 'Draft Person', '770707-07-7777');
        $card = $this->card('borang-a', '2026-07');
        $this->assertStringContainsString('There is no record for Jul 2026.', $card);
        $this->assertStringNotContainsString('Draft Person', $card);
    }

    public function test_the_latest_finalized_month_opens_by_default(): void
    {
        $this->form('borang-a', null)->assertOk()->assertSee('Aminah Binti Ali')->assertSee('value="2026-06"', false);
    }

    public function test_another_companys_run_never_shows(): void
    {
        $other = Tenant::create(['slug' => 'rival', 'name' => 'Rival', 'initials' => 'RV']);
        $theirs = PayrollRun::forceCreate(['tenant_id' => $other->id, 'period' => '2026-06', 'label' => 'June 2026', 'status' => 'finalized', 'finalized_at' => now()]);
        $this->slip($other, $theirs, 'Rival Staff', '660606-06-6666');

        $card = $this->card('borang-a');
        $this->assertStringContainsString('Aminah Binti Ali', $card);
        $this->assertStringNotContainsString('Rival Staff', $card);
    }

    public function test_a_blank_employer_number_points_to_company_settings_instead_of_a_download(): void
    {
        $this->tenant->update(['epf_employer_no' => null]);

        $this->form('borang-a')->assertOk()
            ->assertSee('Set your KWSP employer number in Company Settings.')
            ->assertDontSee(route('payroll.export.statutory-file', [$this->run, 'kwsp-form-a']), false);
    }

    public function test_only_hr_and_management_reach_the_form_screen(): void
    {
        $staff = User::create(['name' => 'Staff', 'email' => 'staff@example.com', 'password' => Hash::make('password')]);
        $staff->tenants()->attach($this->tenant->id, ['role' => 'employee']);

        $this->actingAs($staff)->withSession(['current_tenant' => $this->tenant->id])
            ->get(route('app.screen', ['screen' => 'payroll-form', 'tab' => 'borang-a']))->assertForbidden();
    }

    public function test_rows_add_up_to_what_each_file_writes(): void
    {
        $slips = MergedPayslips::forPeriod($this->tenant, '2026-06');
        $sum = fn (string $key, string $column) => round(array_sum(array_map(fn (array $r) => $r['amounts'][$column], StatutoryFileRegistry::find($key)->rows($slips))), 2);

        // KWSP header line carries the employer and employee totals.
        $kwsp = explode("\n", StatutoryFileRegistry::find('kwsp-form-a')->build($this->run, $this->tenant, $slips))[1];
        $this->assertStringContainsString(number_format($sum('kwsp-form-a', 'employer'), 2, '.', '').','.number_format($sum('kwsp-form-a', 'employee'), 2, '.', ''), $kwsp);

        // CP39 header: MTD total in cents at 26-35.
        $cp39 = StatutoryFileRegistry::find('cp39')->build($this->run, $this->tenant, $slips);
        $this->assertSame((int) round($sum('cp39', 'mtd') * 100), (int) substr($cp39, 27, 10));

        // PERKESO: SOCSO employer per line at 215-220.
        $perkeso = StatutoryFileRegistry::find('perkeso-8a')->build($this->run, $this->tenant, $slips);
        $this->assertSame((int) round($sum('perkeso-8a', 'socso_employer') * 100), array_sum(array_map(fn (string $l) => (int) trim(substr($l, 214, 6)), explode("\r\n", $perkeso))));

        // HRD Corp: TOTAL line.
        $this->assertStringContainsString('TOTAL,,,,10000.00,'.number_format($sum('hrdcorp', 'levy'), 2, '.', ''), StatutoryFileRegistry::find('hrdcorp')->build($this->run, $this->tenant, $slips));
    }
}
