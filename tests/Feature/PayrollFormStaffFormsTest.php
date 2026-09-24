<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\PayrollFormOverride;
use App\Models\PayrollNotice;
use App\Models\PayrollRun;
use App\Models\PayrollSubmission;
use App\Models\Payslip;
use App\Models\SalaryStructure;
use App\Models\Tenant;
use App\Models\User;
use App\Services\FeatureManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Payroll → Form's LHDN staff forms: CP21, CP22, CP22A and PCB II. */
class PayrollFormStaffFormsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $hr;

    private Employee $leaver;

    private PayrollRun $run;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC', 'employer_tin' => '9123456708', 'address' => '1 Jalan Satu']);
        app(FeatureManager::class)->setTenant($this->tenant, 'module.payroll', true);
        $this->hr = User::create(['name' => 'Boss', 'email' => 'boss@example.com', 'password' => Hash::make('password')]);
        $this->hr->tenants()->attach($this->tenant->id, ['role' => 'hr']);
        $signatory = Employee::create(['tenant_id' => $this->tenant->id, 'name' => 'Siti Payroll', 'staff_id' => 'S1', 'status' => 'active', 'workload' => 'green']);
        $this->tenant->forceFill(['statutory_signatory_employee_id' => $signatory->id])->save();

        $this->run = PayrollRun::forceCreate(['tenant_id' => $this->tenant->id, 'period' => '2026-06', 'label' => 'June 2026', 'status' => 'finalized', 'finalized_at' => now()]);
        $this->leaver = Employee::create(['tenant_id' => $this->tenant->id, 'name' => 'Aminah binti Ali', 'staff_id' => 'A1', 'status' => 'active', 'workload' => 'green',
            'nric' => '880101-14-5500', 'joined_at' => '2020-01-01', 'salary' => 5000]);
        SalaryStructure::forceCreate(['tenant_id' => $this->tenant->id, 'employee_id' => $this->leaver->id, 'basic_salary' => 5000, 'tax_no' => 'SG10234567080']);
        Payslip::forceCreate(['tenant_id' => $this->tenant->id, 'payroll_run_id' => $this->run->id, 'employee_id' => $this->leaver->id,
            'basic' => 5000, 'gross' => 5000, 'epf_employee' => 550, 'epf_employer' => 650, 'pcb' => 120, 'net_pay' => 1]);
    }

    private function screen(string $tab, int $year = 2026, ?int $employee = null, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->hr)->withSession(['current_tenant' => $this->tenant->id])
            ->get(route('app.screen', ['screen' => 'payroll-form', 'tab' => $tab, 'year' => $year, 'employee' => $employee]));
    }

    /** Just the one tab's card, so names listed elsewhere on the page don't count. */
    private function card(string $tab, int $year = 2026): string
    {
        $html = (string) $this->screen($tab, $year)->assertOk()->getContent();
        $start = (int) strpos($html, 'data-testid="staff-form-'.$tab.'"');

        return substr($html, $start, (int) strpos($html, '</section>', $start) - $start);
    }

    public function test_cp22a_lists_the_years_leavers_with_the_form_filled_from_their_records(): void
    {
        $this->leaver->update(['last_working_day' => '2026-07-31']);

        $card = $this->card('cp22a');
        $this->assertStringContainsString('Aminah Binti Ali', $card);
        $this->assertStringContainsString('SG10234567080', implode('', $this->boxes($card)));
        foreach (['5000.00', '120.00', '550.00', 'Siti Payroll', '31-07-2026'] as $filled) {
            $this->assertStringContainsString($filled, str_replace(',', '', $card));
        }
        $this->assertStringNotContainsString('Aminah', $this->card('cp22a', 2025));
    }

    public function test_cp22_lists_the_years_hires_with_their_monthly_salary(): void
    {
        Employee::create(['tenant_id' => $this->tenant->id, 'name' => 'New Hire', 'staff_id' => 'N1', 'status' => 'active', 'workload' => 'green', 'joined_at' => '2026-03-01', 'salary' => 4200]);

        $card = $this->card('cp22');
        $this->assertStringContainsString('New Hire', $card);
        $this->assertStringContainsString('4,200.00', $card);
        $this->assertStringContainsString('01-03-2026', $card);
        $this->assertStringNotContainsString('Aminah', $card);
        $this->assertStringNotContainsString('New Hire', $this->card('cp22', 2025));
    }

    public function test_pcb2_shows_each_months_tax_and_the_receipt_it_was_paid_under(): void
    {
        PayrollSubmission::forceCreate(['tenant_id' => $this->tenant->id, 'payroll_run_id' => $this->run->id, 'year' => 2026, 'agency' => 'pcb', 'due_on' => '2026-07-15',
            'receipt_reference' => 'RCPT-0601', 'submitted_at' => '2026-07-10 10:00:00', 'amount_paid' => 120]);

        $card = $this->card('pcb2');
        $this->assertStringContainsString('data-testid="pcb2-table"', $card);
        $this->assertMatchesRegularExpression('/Jun<\/td>\s*<td[^>]*>120\.00<\/td>\s*<td[^>]*>-<\/td>\s*<td[^>]*>RCPT-0601<\/td>\s*<td[^>]*>10-07-2026/', $card);
        $this->assertMatchesRegularExpression('/Mei<\/td>\s*<td[^>]*>-<\/td>/', $card);
    }

    public function test_an_edit_keeps_only_what_differs_from_the_records_and_is_audited(): void
    {
        $this->leaver->update(['last_working_day' => '2026-07-31']);

        $this->actingAs($this->hr)->withSession(['current_tenant' => $this->tenant->id])
            ->post(route('payroll.staff-forms.update', [$this->leaver, 'cp22a', 2026]), ['fields' => [
                'a1' => 'Aminah Binti Ali',          // same as the records, not kept
                'a14a' => 'Rahman bin Osman',       // typed over a blank
                'b_total' => '9999.00',
                'not_a_field' => 'ignored',
            ]])->assertRedirect();

        $override = PayrollFormOverride::withoutGlobalScopes()->sole();
        $this->assertSame(['a14a' => 'Rahman bin Osman', 'b_total' => '9999.00'], $override->fields);
        $this->assertSame($this->tenant->id, $override->tenant_id);
        $this->assertStringContainsString('Rahman bin Osman', $this->card('cp22a'));
        $this->assertStringContainsString('9,999.00', $this->card('cp22a'));

        $log = AuditLog::withoutGlobalScopes()->where('action', 'Edited LHDN CP22A')->sole();
        $this->assertStringContainsString('a14a, b_total', $log->target);
    }

    public function test_another_companys_staff_can_neither_be_seen_nor_edited(): void
    {
        $other = Tenant::create(['slug' => 'rival', 'name' => 'Rival', 'initials' => 'RV']);
        $theirs = Employee::create(['tenant_id' => $other->id, 'name' => 'Rival Leaver', 'staff_id' => 'R1', 'status' => 'active', 'workload' => 'green', 'last_working_day' => '2026-05-01']);

        $this->assertStringNotContainsString('Rival Leaver', $this->card('cp22a'));
        $this->actingAs($this->hr)->withSession(['current_tenant' => $this->tenant->id])
            ->post(route('payroll.staff-forms.update', [$theirs, 'cp22a', 2026]), ['fields' => ['a1' => 'x']])->assertNotFound();
        $this->assertSame(0, PayrollFormOverride::withoutGlobalScopes()->count());
    }

    public function test_batch_pdf_is_hr_only_and_audited(): void
    {
        $this->leaver->update(['last_working_day' => '2026-07-31']);
        $staff = User::create(['name' => 'Staff', 'email' => 'staff@example.com', 'password' => Hash::make('password')]);
        $staff->tenants()->attach($this->tenant->id, ['role' => 'employee']);

        $this->actingAs($staff)->withSession(['current_tenant' => $this->tenant->id])
            ->get(route('payroll.staff-forms.pdf', ['cp22a', 2026]))->assertForbidden();

        $response = $this->actingAs($this->hr)->withSession(['current_tenant' => $this->tenant->id])
            ->get(route('payroll.staff-forms.pdf', ['cp22a', 2026]))->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertSame(1, AuditLog::withoutGlobalScopes()->where('action', 'Downloaded LHDN CP22A batch')->count());

        $this->actingAs($this->hr)->withSession(['current_tenant' => $this->tenant->id])
            ->get(route('payroll.staff-forms.pdf', ['cp22a', 2024]))->assertNotFound();
    }

    public function test_a_leaver_on_pcb_needs_no_cp22a_but_one_without_pcb_does(): void
    {
        $this->leaver->update(['last_working_day' => '2026-07-31']);
        $this->assertSame(0, PayrollNotice::withoutGlobalScopes()->where('employee_id', $this->leaver->id)->where('type', 'cp22a')->count());
        $this->assertStringContainsString('data-testid="cp22a-on-pcb"', $this->card('cp22a'));

        $noPcb = Employee::create(['tenant_id' => $this->tenant->id, 'name' => 'Low Earner', 'staff_id' => 'L1', 'status' => 'active', 'workload' => 'green', 'joined_at' => '2020-01-01']);
        $noPcb->update(['last_working_day' => '2026-08-31']);
        $this->assertSame(1, PayrollNotice::withoutGlobalScopes()->where('employee_id', $noPcb->id)->where('type', 'cp22a')->count());
    }

    public function test_notices_link_to_the_matching_form(): void
    {
        $hire = Employee::create(['tenant_id' => $this->tenant->id, 'name' => 'New Hire', 'staff_id' => 'N1', 'status' => 'active', 'workload' => 'green', 'joined_at' => '2026-03-01']);

        $this->screen('notices')->assertOk()
            ->assertSee(route('app.screen', ['screen' => 'payroll-form', 'tab' => 'cp22', 'year' => 2026, 'employee' => $hire->id]));
    }

    /** @return list<string> the characters drawn one per box */
    private function boxes(string $html): array
    {
        preg_match_all('/<td style="border:1px solid #999;[^"]*">(.)<\/td>/u', $html, $m);

        return $m[1];
    }
}
