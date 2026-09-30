<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\PayrollOpeningFigure;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\SalaryStructure;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class FormEControllerTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $hr;

    private User $empUser;

    private Employee $emp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'slug' => 'acme', 'name' => 'Acme Sdn Bhd', 'initials' => 'AC', 'address' => '1 Jalan Test',
            'employer_tin' => '2900030000',
        ]);
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
        ]);
        Employee::whereKey($this->emp->id)->update(['salary' => 5000]);

        $run = PayrollRun::forceCreate([
            'tenant_id' => $this->tenant->id, 'period' => '2026-03', 'status' => 'finalized', 'finalized_at' => now(),
        ]);
        Payslip::forceCreate([
            'tenant_id' => $this->tenant->id, 'payroll_run_id' => $run->id, 'employee_id' => $this->emp->id,
            'basic' => 5000, 'gross' => 5000, 'pcb' => 120, 'total_deductions' => 120, 'net_pay' => 4880,
            'employer_cost' => 5000,
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

    public function test_hr_sees_the_preview_screen_with_the_incomplete_checklist(): void
    {
        $response = $this->actingHr()->get('/app/payroll/form-e/2026');

        $response->assertOk();
        $response->assertSee('Worker');
        $response->assertSee('Tax borne by employer');
    }

    public function test_hr_downloads_form_e_pdf(): void
    {
        $response = $this->actingHr()->get('/app/payroll/form-e/2026/pdf');

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
    }

    public function test_hr_downloads_the_cp8d_text_file_named_per_spec(): void
    {
        $response = $this->actingHr()->get('/app/payroll/form-e/2026/cp8d');

        $response->assertOk();
        $response->assertHeader('content-disposition', 'attachment; filename=P2900030000_2026.txt');

        $body = $response->streamedContent();
        $this->assertStringContainsString('Worker', $body);
        $this->assertStringContainsString('880101145500', $body); // NRIC, dashes stripped
    }

    public function test_cp8d_download_is_refused_without_an_employer_tin(): void
    {
        $this->tenant->update(['employer_tin' => null]);

        $response = $this->actingHr()->get('/app/payroll/form-e/2026/cp8d');

        $response->assertStatus(422);
        $response->assertSee('Employer TIN', false);
        $response->assertSee('Company Settings', false);
    }

    public function test_form_e_pdf_still_downloads_without_an_employer_tin(): void
    {
        $this->tenant->update(['employer_tin' => null]);

        $response = $this->actingHr()->get('/app/payroll/form-e/2026/pdf');

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
    }

    public function test_preview_screen_disables_the_cp8d_download_without_an_employer_tin(): void
    {
        $this->tenant->update(['employer_tin' => null]);

        $response = $this->actingHr()->get('/app/payroll/form-e/2026');

        $response->assertOk();
        $response->assertSee('cannot be downloaded yet', false);
        $response->assertSee(route('app.screen', 'settings'), false);
    }

    public function test_employee_cannot_view_the_preview_screen(): void
    {
        $this->actingEmployee()->get('/app/payroll/form-e/2026')->assertForbidden();
    }

    public function test_employee_cannot_download_form_e_pdf(): void
    {
        $this->actingEmployee()->get('/app/payroll/form-e/2026/pdf')->assertForbidden();
    }

    public function test_employee_cannot_download_the_cp8d_file(): void
    {
        $this->actingEmployee()->get('/app/payroll/form-e/2026/cp8d')->assertForbidden();
    }

    public function test_there_is_no_employee_scoped_route_for_form_e_or_cp8d(): void
    {
        // Unlike Form EA, this is a company-wide export — no /employees/{employee}/...
        // route exists at all for it.
        $this->assertFalse(Route::has('payroll.form-e.show.employee'));
        $this->assertFalse(Route::has('payroll.form-e.pdf.employee'));
    }

    /** Someone paid only through the old payroll system before the switch still gets a C.P.8D line. */
    public function test_cp8d_lists_a_leaver_paid_only_before_the_switch(): void
    {
        $leaver = Employee::create(['tenant_id' => $this->tenant->id, 'name' => 'Chong Wei Lin', 'status' => 'resigned', 'workload' => 'green',
            'last_working_day' => '2026-03-31', 'archived_at' => '2026-04-02']);
        PayrollOpeningFigure::forceCreate(['tenant_id' => $this->tenant->id, 'employee_id' => $leaver->id, 'year' => 2026, 'gross' => 3000]);

        $fromElsewhere = Employee::create(['tenant_id' => $this->tenant->id, 'name' => 'Devi Raman', 'status' => 'active', 'workload' => 'green']);
        PayrollOpeningFigure::forceCreate(['tenant_id' => $this->tenant->id, 'employee_id' => $fromElsewhere->id, 'year' => 2026,
            'gross' => 9000, 'previous_employer' => 'Other Sdn Bhd']);

        $body = $this->actingHr()->get('/app/payroll/form-e/2026/cp8d')->assertOk()->streamedContent();

        $this->assertStringContainsString('Chong Wei Lin', $body);
        $this->assertStringContainsString('31-03-2026', $body); // end date is the last working day, not the archive day
        $this->assertStringNotContainsString('Devi Raman', $body);
    }
}
