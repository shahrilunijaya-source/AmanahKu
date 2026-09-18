<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\PayrollNotice;
use App\Models\SalaryStructure;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Payroll\LifecycleNotices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Spec F11: CP22 / CP22A / CP21, PERKESO Form 2 and KWSP registration notices opened by
 * a hire or a leaving date, filed by HR, and the PCB 2(II) that goes with a CP22A.
 */
class PayrollNoticeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC',
            'employer_tin' => '1234567890', 'epf_employer_no' => '12345678', 'socso_employer_code' => 'A123']);
        $this->hr = User::create(['name' => 'Boss', 'email' => 'boss@example.com', 'password' => Hash::make('password')]);
        $this->hr->tenants()->attach($this->tenant->id, ['role' => 'hr']);
        $this->actingAs($this->hr)->withSession(['current_tenant' => $this->tenant->id]);
    }

    private function employee(string $joinedAt = '2026-03-01', ?Tenant $tenant = null): Employee
    {
        $tenant ??= $this->tenant;
        $emp = Employee::create(['tenant_id' => $tenant->id, 'name' => 'Worker '.random_int(1, 9999), 'staff_id' => 'AC-'.random_int(1, 9999),
            'status' => 'active', 'workload' => 'green', 'nric' => '880101-14-5500', 'date_of_birth' => '1988-01-01',
            'joined_at' => $joinedAt, 'salary' => 3000]);
        SalaryStructure::forceCreate(['tenant_id' => $tenant->id, 'employee_id' => $emp->id, 'basic_salary' => 3000, 'nationality' => 'citizen',
            'bank_name' => 'Maybank', 'bank_code' => 'MBBEMYKL', 'bank_account_no' => '1', 'epf_no' => '1', 'socso_no' => '1', 'tax_no' => 'SG1']);

        return $emp;
    }

    public function test_a_hire_opens_cp22_socso_form_two_and_the_kwsp_registration(): void
    {
        $emp = $this->employee('2026-03-01');

        $notices = PayrollNotice::where('employee_id', $emp->id)->get();
        $this->assertEqualsCanonicalizing(['cp22', 'socso_form2', 'kwsp_registration'], $notices->pluck('type')->all());
        foreach ($notices as $n) {
            $this->assertSame('2026-03-31', $n->due_on->toDateString());
        }
    }

    public function test_a_leaving_date_opens_a_cp22a_thirty_days_before_the_last_day(): void
    {
        $this->travelTo('2026-05-01');
        $emp = $this->employee();
        $emp->update(['last_working_day' => '2026-06-30']);

        $cp22a = PayrollNotice::where('employee_id', $emp->id)->where('type', 'cp22a')->firstOrFail();
        $this->assertSame('2026-05-31', $cp22a->due_on->toDateString());
    }

    public function test_a_late_leaving_date_is_due_today_rather_than_in_the_past(): void
    {
        $this->travelTo('2026-05-01');
        $emp = $this->employee();
        $emp->update(['last_working_day' => '2026-05-11']);

        $this->assertSame('2026-05-01', PayrollNotice::where('employee_id', $emp->id)->where('type', 'cp22a')->firstOrFail()->due_on->toDateString());
    }

    public function test_filing_records_the_date_reference_and_filer_and_drops_the_dashboard_count(): void
    {
        $emp = $this->employee('2026-03-01');
        $before = $this->get('/app/dash')->assertOk()->viewData('widgets')['payroll']['openNotices'];
        $this->assertSame(3, $before);

        $notice = PayrollNotice::where('employee_id', $emp->id)->where('type', 'cp22')->firstOrFail();
        $this->post(route('payroll.notices.file', $notice), ['filed_on' => '2026-03-10', 'reference' => 'LHDN/9'])
            ->assertSessionHasNoErrors();

        $notice->refresh();
        $this->assertSame('2026-03-10', $notice->filed_on->toDateString());
        $this->assertSame('LHDN/9', $notice->reference);
        $this->assertSame($this->hr->id, $notice->filed_by_id);
        $this->assertTrue(AuditLog::where('action', 'Filed statutory notice')->exists());

        $this->assertSame(2, $this->get('/app/dash')->assertOk()->viewData('widgets')['payroll']['openNotices']);
    }

    public function test_another_tenants_notice_cannot_be_filed(): void
    {
        $other = Tenant::create(['slug' => 'other', 'name' => 'Other', 'initials' => 'OT']);
        $emp = $this->employee('2026-03-01', $other);
        $notice = PayrollNotice::withoutGlobalScopes()->where('employee_id', $emp->id)->firstOrFail();

        $this->post(route('payroll.notices.file', $notice), ['filed_on' => '2026-03-10'])->assertForbidden();
    }

    public function test_pcb_two_two_is_only_issued_for_a_cp22a(): void
    {
        $this->travelTo('2026-05-01');
        $emp = $this->employee();
        $emp->update(['last_working_day' => '2026-06-30']);

        $cp22a = PayrollNotice::where('employee_id', $emp->id)->where('type', 'cp22a')->firstOrFail();
        $this->get(route('payroll.notices.pcb2ii', $cp22a))->assertOk();

        $cp22 = PayrollNotice::where('employee_id', $emp->id)->where('type', 'cp22')->firstOrFail();
        $this->get(route('payroll.notices.pcb2ii', $cp22))->assertNotFound();
    }

    public function test_final_pay_is_held_until_the_cp22a_is_filed_and_cleared(): void
    {
        $this->travelTo('2026-05-01');
        $emp = $this->employee();
        $svc = app(LifecycleNotices::class);
        $this->assertFalse($svc->holdsFinalPay($emp));

        $emp->update(['last_working_day' => '2026-06-30']);
        $this->assertTrue($svc->holdsFinalPay($emp->fresh()));

        $notice = PayrollNotice::where('employee_id', $emp->id)->where('type', 'cp22a')->firstOrFail();
        $this->post(route('payroll.notices.file', $notice), ['filed_on' => '2026-05-10'])->assertSessionHasNoErrors();
        $this->assertTrue($svc->holdsFinalPay($emp->fresh()), 'still held while clearance may still arrive');

        $this->post(route('payroll.notices.clear', $notice), ['cleared_on' => '2026-05-20'])->assertSessionHasNoErrors();
        $this->assertFalse($svc->holdsFinalPay($emp->fresh()));
    }

    public function test_a_cp21_is_opened_by_hand_and_needs_a_last_working_day(): void
    {
        $this->travelTo('2026-05-01');
        $emp = $this->employee();
        $this->post(route('payroll.notices.cp21', $emp))->assertStatus(422);

        $emp->update(['last_working_day' => '2026-08-31']);
        $this->post(route('payroll.notices.cp21', $emp))->assertSessionHasNoErrors();

        $this->assertSame('2026-08-01', PayrollNotice::where('employee_id', $emp->id)->where('type', 'cp21')->firstOrFail()->due_on->toDateString());
    }
}
