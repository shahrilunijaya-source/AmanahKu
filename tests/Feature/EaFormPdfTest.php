<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\PayrollItem;
use App\Models\PayrollOpeningFigure;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\PayslipLine;
use App\Models\SalaryStructure;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Payroll\EaFormPdfData;
use Dompdf\Dompdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class EaFormPdfTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $hr;

    private User $empUser;

    private Employee $emp;

    private User $otherEmpUser;

    private Employee $otherEmp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme Sdn Bhd', 'initials' => 'AC', 'address' => '1 Jalan Test']);
        PayrollItem::seedFor($this->tenant);

        $this->hr = User::create(['name' => 'Boss', 'email' => 'boss@example.com', 'password' => Hash::make('password')]);
        $this->hr->tenants()->attach($this->tenant->id, ['role' => 'hr']);

        $this->empUser = User::create(['name' => 'Worker', 'email' => 'worker@example.com', 'password' => Hash::make('password')]);
        $this->empUser->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $this->emp = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->empUser->id,
            'name' => 'Worker', 'staff_id' => 'AC-0007', 'status' => 'active', 'workload' => 'green',
            'nric' => '880101-14-5500',
        ]);
        SalaryStructure::forceCreate([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->emp->id, 'basic_salary' => 5000,
            'epf_no' => 'EPF12345678', 'socso_no' => 'SOC99001122', 'tax_no' => 'SG12345678',
            'children_relief_count' => 2,
        ]);

        $this->otherEmpUser = User::create(['name' => 'Someone Else', 'email' => 'other@example.com', 'password' => Hash::make('password')]);
        $this->otherEmpUser->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $this->otherEmp = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->otherEmpUser->id,
            'name' => 'Someone Else', 'status' => 'active', 'workload' => 'green',
        ]);
    }

    private function actingHr(): self
    {
        $this->actingAs($this->hr)->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    private function actingEmployee(): self
    {
        $this->actingAs($this->empUser)->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    private function actingOtherEmployee(): self
    {
        $this->actingAs($this->otherEmpUser)->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    /** Populates one finalized 2026 payslip for $employee with a full set of box-mapped lines. */
    private function finalizedPayslipWithAllBoxes(Employee $employee, string $period = '2026-06'): Payslip
    {
        $run = PayrollRun::where('tenant_id', $this->tenant->id)->where('period', $period)->first()
            ?? PayrollRun::forceCreate([
                'tenant_id' => $this->tenant->id, 'period' => $period, 'label' => 'June 2026', 'status' => 'finalized',
                'finalized_at' => now(),
            ]);
        $payslip = Payslip::forceCreate([
            'tenant_id' => $this->tenant->id, 'payroll_run_id' => $run->id, 'employee_id' => $employee->id,
            'basic' => 5000, 'gross' => 5850,
            'epf_employee' => 550, 'socso_employee' => 25, 'eis_employee' => 10,
            'pcb' => 120, 'zakat' => 15, 'cp38' => 30,
            'total_deductions' => 740, 'net_pay' => 5110, 'employer_cost' => 6440,
        ]);

        $items = PayrollItem::where('tenant_id', $this->tenant->id)->get()->keyBy('code');
        $lines = [
            ['basic-salary', 'Basic Salary', 5000, 'salary'],
            ['bonus', 'Bonus', 500, 'manual'],
            ['fixed-allowance', 'Fixed Allowance', 300, 'salary'],
            ['claim-reimbursement', 'Claim Reimbursement', 50, 'claim'],
        ];
        foreach ($lines as $i => [$code, $name, $amount, $source]) {
            PayslipLine::forceCreate([
                'tenant_id' => $this->tenant->id, 'payslip_id' => $payslip->id, 'payroll_item_id' => $items[$code]->id,
                'name' => $name, 'type' => 'earning', 'amount' => $amount, 'source' => $source, 'sort_order' => $i,
            ]);
        }

        return $payslip;
    }

    public function test_hr_downloads_a_finalized_ea_form_pdf(): void
    {
        $this->finalizedPayslipWithAllBoxes($this->emp);

        $response = $this->actingHr()->get(route('payroll.ea-form.pdf', ['employee' => $this->emp, 'year' => 2026]));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertGreaterThan(1000, strlen($response->getContent()));
    }

    public function test_employee_downloads_own_ea_form_pdf(): void
    {
        $this->finalizedPayslipWithAllBoxes($this->emp);

        $this->actingEmployee()->get(route('payroll.ea-form.pdf', ['employee' => $this->emp, 'year' => 2026]))->assertOk();
    }

    public function test_employee_cannot_download_someone_elses_ea_form(): void
    {
        $this->finalizedPayslipWithAllBoxes($this->emp);

        $this->actingOtherEmployee()->get(route('payroll.ea-form.pdf', ['employee' => $this->emp, 'year' => 2026]))->assertForbidden();
    }

    public function test_hr_sees_the_ea_preview_screen(): void
    {
        $this->finalizedPayslipWithAllBoxes($this->emp);

        $response = $this->actingHr()->get(route('payroll.ea-form.show', ['employee' => $this->emp, 'year' => 2026]));

        $response->assertOk();
        $response->assertSee('Form EA');
        $response->assertSee('Worker');
    }

    public function test_employee_cannot_view_the_hr_preview_screen(): void
    {
        $this->actingEmployee()->get(route('payroll.ea-form.show', ['employee' => $this->emp, 'year' => 2026]))->assertForbidden();
    }

    public function test_employee_cannot_download_the_bulk_pdf(): void
    {
        $this->actingEmployee()->get(route('payroll.export.ea-forms', ['year' => 2026]))->assertForbidden();
    }

    public function test_cannot_download_an_ea_form_for_an_employee_from_another_tenant(): void
    {
        $other = Tenant::create(['slug' => 'rival', 'name' => 'Rival', 'initials' => 'RV']);
        $foreignEmployee = Employee::create(['tenant_id' => $other->id, 'name' => 'Foreigner', 'status' => 'active', 'workload' => 'green']);

        $this->actingHr()->get(route('payroll.ea-form.pdf', ['employee' => $foreignEmployee, 'year' => 2026]))->assertForbidden();
    }

    public function test_bonus_lands_in_b1b_overtime_in_b1a_with_salary_and_reimbursement_in_neither(): void
    {
        $run = PayrollRun::forceCreate([
            'tenant_id' => $this->tenant->id, 'period' => '2026-06', 'status' => 'finalized', 'finalized_at' => now(),
        ]);
        $payslip = Payslip::forceCreate([
            'tenant_id' => $this->tenant->id, 'payroll_run_id' => $run->id, 'employee_id' => $this->emp->id,
            'gross' => 5700, 'total_deductions' => 0, 'net_pay' => 5700, 'employer_cost' => 5700,
        ]);
        $items = PayrollItem::where('tenant_id', $this->tenant->id)->get()->keyBy('code');
        foreach ([
            ['basic-salary', 5000, 'salary'],
            ['overtime', 200, 'overtime'],
            ['bonus', 500, 'manual'],
            ['claim-reimbursement', 100, 'claim'],
        ] as $i => [$code, $amount, $source]) {
            PayslipLine::forceCreate([
                'tenant_id' => $this->tenant->id, 'payslip_id' => $payslip->id, 'payroll_item_id' => $items[$code]->id,
                'name' => $code, 'type' => 'earning', 'amount' => $amount, 'source' => $source, 'sort_order' => $i,
            ]);
        }

        $data = app(EaFormPdfData::class)->build($this->tenant, $this->emp, 2026);

        // Salary (5000) + overtime (200) folded into B1(a), not reported separately.
        $this->assertSame(5200.0, $data['b']['b1a']);
        $this->assertSame(500.0, $data['b']['b1b']);
        // Reimbursement is not employment income at all — it must not appear anywhere.
        $this->assertNull($data['b']['b1c']);
    }

    public function test_e2_socso_includes_eis(): void
    {
        $this->finalizedPayslipWithAllBoxes($this->emp);

        $data = app(EaFormPdfData::class)->build($this->tenant, $this->emp, 2026);

        // socso_employee (25) + eis_employee (10) combined, per guide note 7.
        $this->assertSame(35.0, $data['e']['e2']);
    }

    public function test_previous_employer_figures_are_excluded_from_the_ea_form(): void
    {
        PayrollOpeningFigure::forceCreate([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->emp->id, 'year' => 2026,
            'gross' => 20000, 'epf' => 2200, 'socso' => 100, 'eis' => 40,
            'previous_employer' => 'Old Co Sdn Bhd',
        ]);
        $this->finalizedPayslipWithAllBoxes($this->emp);

        $data = app(EaFormPdfData::class)->build($this->tenant, $this->emp, 2026);

        // Only this employer's 5000+500+300=5800 taxable income (basic+bonus+allowance),
        // never the previous employer's 20000 gross.
        $this->assertSame(5000.0, $data['b']['b1a']);
        $this->assertSame(500.0, $data['b']['b1b']);
        $this->assertSame(300.0, $data['b']['b1c']);
        $this->assertSame(35.0, $data['e']['e2']);
        // The previous-employer block is surfaced separately, for the HR screen's note only.
        $this->assertNotNull($data['previous_employment']);
        $this->assertSame('Old Co Sdn Bhd', $data['previous_employment']['previous_employer']);
    }

    public function test_bulk_pdf_has_one_page_per_employee_with_finalized_pay_that_year(): void
    {
        $this->finalizedPayslipWithAllBoxes($this->emp);
        $this->finalizedPayslipWithAllBoxes($this->otherEmp);

        $forms = collect([$this->emp, $this->otherEmp])->map(fn (Employee $e) => app(EaFormPdfData::class)->build($this->tenant, $e, 2026));
        $this->assertSame(2, $this->renderedPageCount($forms->all()));

        $response = $this->actingHr()->get(route('payroll.export.ea-forms', ['year' => 2026]));
        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
    }

    public function test_incomplete_box_list_names_boxes_that_cannot_be_filled(): void
    {
        $data = app(EaFormPdfData::class)->build($this->tenant, $this->emp, 2026);

        $boxes = collect($data['incomplete'])->pluck('box')->all();

        foreach (['A5', 'A8', 'B1(e)', 'B1(f)', 'B2', 'B3', 'B4', 'C1', 'C2', 'D4', 'D5(a)', 'D5(b)'] as $box) {
            $this->assertContains($box, $boxes, "Expected {$box} to be listed as incomplete.");
        }
        // Header-level gaps (no per-employee data possible) are listed too.
        $this->assertContains('Header', $boxes);

        // A8 (number of children) must never be filled from the relief-unit count —
        // children_relief_count holds RM2,000 units, not a headcount.
        $this->assertNull($data['employee']['children']);
        // D6 (total child relief in ringgit) IS computable from those same units.
        $this->assertSame(4000.0, $data['d']['d6']);
    }

    public function test_employer_tin_and_telephone_fill_from_tenant_and_drop_from_incomplete_list(): void
    {
        // Neither set yet — both boxes are genuinely incomplete.
        $blank = app(EaFormPdfData::class)->build($this->tenant, $this->emp, 2026);
        $this->assertNull($blank['header']['employer_tin']);
        $this->assertNull($blank['employer']['telephone']);
        $blankBoxes = collect($blank['incomplete'])->map(fn ($b) => $b['box'].'|'.$b['label'])->all();
        $this->assertContains('Header|Employer\'s TIN', $blankBoxes);
        $this->assertContains('Footer|Employer\'s Telephone No.', $blankBoxes);

        $this->tenant->update(['employer_tin' => '1234567890', 'contact_number' => '03-1234 5678']);

        $filled = app(EaFormPdfData::class)->build($this->tenant->fresh(), $this->emp, 2026);
        $this->assertSame('1234567890', $filled['header']['employer_tin']);
        $this->assertSame('03-1234 5678', $filled['employer']['telephone']);
        $filledBoxes = collect($filled['incomplete'])->map(fn ($b) => $b['box'].'|'.$b['label'])->all();
        $this->assertNotContains('Header|Employer\'s TIN', $filledBoxes);
        $this->assertNotContains('Footer|Employer\'s Telephone No.', $filledBoxes);

        // Printed with the "E" prefix the form shows, not stored with it.
        $html = view('pdf.ea-form', ['forms' => collect([$filled])])->render();
        $this->assertStringContainsString('E1234567890', $html);
    }

    public function test_hr_can_set_employer_tin_and_telephone_via_the_company_settings_screen(): void
    {
        $this->actingHr()->post(route('admin.settings.update'), [
            'name' => $this->tenant->name,
            'employer_tin' => '9988776655',
            'contact_number' => '03-9999 8888',
        ])->assertRedirect();

        $this->assertSame('9988776655', $this->tenant->fresh()->employer_tin);
        $this->assertSame('03-9999 8888', $this->tenant->fresh()->contact_number);
    }

    /**
     * A visual-only bug (a box's amount cell silently failing to render even though the
     * underlying figure is correct) is exactly what the payslip PDF work missed before —
     * assert the rendered HTML itself, not just the data array, so a broken cell can never
     * hide behind a passing data-layer test again.
     */
    public function test_box_f_renders_the_exempt_total_in_the_pdf_when_there_is_one(): void
    {
        $run = PayrollRun::forceCreate(['tenant_id' => $this->tenant->id, 'period' => '2026-06', 'status' => 'finalized', 'finalized_at' => now()]);
        $exempt = PayrollItem::forceCreate([
            'tenant_id' => $this->tenant->id, 'code' => 'test-exempt', 'name' => 'Exempt Perk', 'type' => 'earning',
            'epf_liable' => false, 'perkeso_liable' => false, 'pcb_taxable' => false,
            'source' => 'manual', 'is_system' => false, 'active' => true, 'sort_order' => 99,
        ]);
        $payslip = Payslip::forceCreate([
            'tenant_id' => $this->tenant->id, 'payroll_run_id' => $run->id, 'employee_id' => $this->emp->id,
            'gross' => 250, 'total_deductions' => 0, 'net_pay' => 250, 'employer_cost' => 250,
        ]);
        PayslipLine::forceCreate([
            'tenant_id' => $this->tenant->id, 'payslip_id' => $payslip->id, 'payroll_item_id' => $exempt->id,
            'name' => 'Exempt Perk', 'type' => 'earning', 'amount' => 250, 'source' => 'manual', 'sort_order' => 0,
        ]);

        $data = app(EaFormPdfData::class)->build($this->tenant, $this->emp, 2026);
        $this->assertSame(250.0, $data['f']);

        $html = view('pdf.ea-form', ['forms' => collect([$data])])->render();
        $this->assertStringContainsString('250.00', $html);
        $this->assertStringContainsString('Total tax exempt allowances', $html);
    }

    public function test_box_f_is_blank_not_zero_when_there_is_no_exempt_income(): void
    {
        $this->finalizedPayslipWithAllBoxes($this->emp); // no exempt-item lines in this fixture

        $data = app(EaFormPdfData::class)->build($this->tenant, $this->emp, 2026);
        $this->assertNull($data['f']);

        $html = view('pdf.ea-form', ['forms' => collect([$data])])->render();
        $this->assertStringNotContainsString('0.00', $this->extractBoxFRow($html));
    }

    private function extractBoxFRow(string $html): string
    {
        preg_match('/Total tax exempt allowances.*?<\/tr>/s', $html, $m);

        return $m[0] ?? '';
    }

    /** @param  array<int, array<string, mixed>>  $forms */
    private function renderedPageCount(array $forms): int
    {
        $html = view('pdf.ea-form', ['forms' => collect($forms)])->render();

        $dompdf = new Dompdf;
        $dompdf->loadHtml($html);
        $dompdf->setPaper('a4');
        $dompdf->render();

        return $dompdf->getCanvas()->get_page_count();
    }
}
