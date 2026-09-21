<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\PayrollSubmission;
use App\Models\SalaryStructure;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\PayrollDeadlineDigest;
use App\Services\FeatureManager;
use App\Services\Payroll\StatutoryCalendar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Spec F12: the statutory submissions log — rows opened by a finalized run, the file
 * download that marks one ready, the receipt HR records, and the reminder digest.
 */
class PayrollSubmissionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC',
            'employer_tin' => '1234567890', 'epf_employer_no' => '12345678', 'socso_employer_code' => 'A123']);
        PayrollItem::seedFor($this->tenant);
        $this->hr = User::create(['name' => 'Boss', 'email' => 'boss@example.com', 'password' => Hash::make('password')]);
        $this->hr->tenants()->attach($this->tenant->id, ['role' => 'hr']);

        $emp = Employee::create(['tenant_id' => $this->tenant->id, 'name' => 'Worker', 'staff_id' => 'AC-1', 'status' => 'active', 'workload' => 'green',
            'nric' => '880101-14-5500', 'date_of_birth' => '1988-01-01', 'joined_at' => '2020-01-01', 'salary' => 3000]);
        SalaryStructure::forceCreate(['tenant_id' => $this->tenant->id, 'employee_id' => $emp->id, 'basic_salary' => 3000, 'nationality' => 'citizen',
            'bank_name' => 'Maybank', 'bank_code' => 'MBBEMYKL', 'bank_account_no' => '1', 'epf_no' => '1', 'socso_no' => '1', 'tax_no' => 'SG1']);

        $this->actingAs($this->hr)->withSession(['current_tenant' => $this->tenant->id]);
    }

    private function finalize(string $period = '2026-08'): PayrollRun
    {
        $this->post(route('payroll.runs.create'), ['period' => $period, 'payment_date' => $period.'-28'])->assertSessionHasNoErrors();
        $run = PayrollRun::where('period', $period)->firstOrFail();
        $this->post(route('payroll.runs.finalize', $run), ['payment_date' => $period.'-28'])->assertSessionHasNoErrors();

        return $run->fresh();
    }

    public function test_the_fifteenth_is_the_due_date_even_on_a_weekend(): void
    {
        // 15 November 2026 is a Sunday; the agencies do not move the deadline.
        $this->assertSame('2026-11-15', StatutoryCalendar::monthlyDueDate('2026-10')->toDateString());
        $this->assertSame('2027-02-28', StatutoryCalendar::annualDueDate('ea', 2026)->toDateString());
        $this->assertSame('2027-03-31', StatutoryCalendar::annualDueDate('form_e', 2026)->toDateString());
    }

    public function test_finalizing_with_the_levy_on_opens_four_filings_due_on_the_fifteenth(): void
    {
        $this->travelTo('2026-09-01 09:00');
        app(FeatureManager::class)->setTenant($this->tenant, 'payroll.hrdf', '1');
        $this->tenant->update(['hrdf_registration_no' => 'HRD-1']);
        $run = $this->finalize();

        $rows = PayrollSubmission::where('payroll_run_id', $run->id)->get();
        $this->assertEqualsCanonicalizing(['epf', 'socso_eis', 'pcb', 'hrdcorp'], $rows->pluck('agency')->all());
        foreach ($rows as $row) {
            $this->assertSame('2026-09-15', $row->due_on->toDateString());
            $this->assertSame('not_started', $row->state());
        }
    }

    public function test_without_the_levy_only_three_filings_open(): void
    {
        $run = $this->finalize();
        $this->assertEqualsCanonicalizing(['epf', 'socso_eis', 'pcb'], PayrollSubmission::where('payroll_run_id', $run->id)->pluck('agency')->all());
    }

    public function test_a_december_run_also_opens_form_ea_and_form_e(): void
    {
        $run = $this->finalize('2026-12');
        $annual = PayrollSubmission::whereNull('payroll_run_id')->get();
        $this->assertEqualsCanonicalizing(['ea', 'form_e'], $annual->pluck('agency')->all());
        $this->assertSame(2026, $annual->first()->year);
        $this->assertSame('2027-01-15', PayrollSubmission::where('payroll_run_id', $run->id)->first()->due_on->toDateString());
    }

    public function test_downloading_the_cp39_marks_the_pcb_filing_file_ready(): void
    {
        $this->travelTo('2026-09-01 09:00');
        $run = $this->finalize();
        $this->get(route('payroll.export.statutory-file', ['run' => $run, 'key' => 'cp39']))->assertOk();

        $pcb = PayrollSubmission::where('payroll_run_id', $run->id)->where('agency', 'pcb')->firstOrFail();
        $this->assertNotNull($pcb->downloaded_at);
        $this->assertSame('file_ready', $pcb->state());
        $this->assertSame('not_started', PayrollSubmission::where('payroll_run_id', $run->id)->where('agency', 'epf')->firstOrFail()->state());
    }

    public function test_recording_a_receipt_marks_it_submitted_and_audits(): void
    {
        $run = $this->finalize();
        $pcb = PayrollSubmission::where('payroll_run_id', $run->id)->where('agency', 'pcb')->firstOrFail();

        $this->post(route('payroll.submissions.submit', $pcb), ['receipt_reference' => 'LHDN-778', 'amount_paid' => 120.50])
            ->assertSessionHasNoErrors();

        $pcb->refresh();
        $this->assertSame('submitted', $pcb->state());
        $this->assertSame('LHDN-778', $pcb->receipt_reference);
        $this->assertSame(120.5, $pcb->amount_paid);
        $this->assertSame($this->hr->id, $pcb->submitted_by_id);
        $this->assertTrue(AuditLog::where('action', 'Recorded statutory submission')->exists());
    }

    public function test_a_run_with_a_filed_submission_cannot_be_deleted(): void
    {
        $run = $this->finalize();
        $pcb = PayrollSubmission::where('payroll_run_id', $run->id)->where('agency', 'pcb')->firstOrFail();
        $this->post(route('payroll.submissions.submit', $pcb), ['receipt_reference' => 'LHDN-778']);

        $this->hr->tenants()->updateExistingPivot($this->tenant->id, ['role' => 'management']);
        $this->actingAs($this->hr->fresh())->withSession(['current_tenant' => $this->tenant->id]);
        $this->post(route('payroll.runs.delete', $run), ['confirm_period' => $run->period])->assertStatus(422);
        $this->assertNotNull(PayrollRun::find($run->id));
    }

    public function test_deleting_a_run_takes_its_unsubmitted_filings_with_it(): void
    {
        $run = $this->finalize();
        $this->hr->tenants()->updateExistingPivot($this->tenant->id, ['role' => 'management']);
        $this->actingAs($this->hr->fresh())->withSession(['current_tenant' => $this->tenant->id]);
        $this->post(route('payroll.runs.delete', $run), ['confirm_period' => $run->period])->assertSessionHasNoErrors();

        $this->assertSame(0, PayrollSubmission::withoutGlobalScopes()->count());
    }

    public function test_another_tenant_cannot_record_a_submission(): void
    {
        $run = $this->finalize();
        $pcb = PayrollSubmission::where('payroll_run_id', $run->id)->where('agency', 'pcb')->firstOrFail();

        $other = Tenant::create(['slug' => 'other', 'name' => 'Other', 'initials' => 'OT']);
        $intruder = User::create(['name' => 'Nosy', 'email' => 'nosy@example.com', 'password' => Hash::make('password')]);
        $intruder->tenants()->attach($other->id, ['role' => 'hr']);

        $this->actingAs($intruder)->withSession(['current_tenant' => $other->id])
            ->post(route('payroll.submissions.submit', $pcb), ['receipt_reference' => 'X'])->assertForbidden();
    }

    public function test_the_digest_goes_out_on_the_fourteenth_and_skips_the_thirteenth(): void
    {
        Notification::fake();
        $run = $this->finalize();
        $pcb = PayrollSubmission::where('payroll_run_id', $run->id)->where('agency', 'pcb')->firstOrFail();
        $this->post(route('payroll.submissions.submit', $pcb), ['receipt_reference' => 'LHDN-778']);

        $this->travelTo('2026-09-13 08:00');
        $this->artisan('payroll:deadline-digest')->assertSuccessful();
        Notification::assertNothingSent();

        $this->travelTo('2026-09-14 08:00');
        $this->artisan('payroll:deadline-digest')->assertSuccessful();
        Notification::assertSentToTimes($this->hr, PayrollDeadlineDigest::class, 1);
    }

    public function test_nothing_is_sent_when_every_filing_is_already_submitted(): void
    {
        Notification::fake();
        $run = $this->finalize();
        foreach (PayrollSubmission::where('payroll_run_id', $run->id)->get() as $row) {
            $this->post(route('payroll.submissions.submit', $row), ['receipt_reference' => 'R'.$row->id]);
        }

        $this->travelTo('2026-09-14 08:00');
        $this->artisan('payroll:deadline-digest')->assertSuccessful();
        Notification::assertNothingSent();
    }
}
