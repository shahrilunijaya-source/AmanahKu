<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Employee;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\SalaryStructure;
use App\Models\Tenant;
use App\Models\User;
use App\Services\FeatureManager;
use App\Services\Payroll\PayslipPdfData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Spec F13: finalize and publish are two steps, staff see a payslip only after publish,
 * the PDF carries the full pay-statement particulars, and acknowledgement is optional.
 */
class PayrollPublishTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $hr;

    private User $staffUser;

    private Employee $staff;

    private Employee $noLogin;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC',
            'employer_tin' => '1234567890', 'epf_employer_no' => '12345678', 'socso_employer_code' => 'A1234567890']);
        PayrollItem::seedFor($this->tenant);

        $this->hr = User::create(['name' => 'Boss', 'email' => 'boss@example.com', 'password' => Hash::make('password')]);
        $this->hr->tenants()->attach($this->tenant->id, ['role' => 'hr']);

        $this->staffUser = User::create(['name' => 'Worker', 'email' => 'worker@example.com', 'password' => Hash::make('password')]);
        $this->staffUser->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $this->staff = $this->employee('Worker', $this->staffUser->id);
        $this->noLogin = $this->employee('Nobody', null);

        $this->actingHr();
    }

    private function employee(string $name, ?int $userId): Employee
    {
        $emp = Employee::create(['tenant_id' => $this->tenant->id, 'user_id' => $userId, 'name' => $name, 'staff_id' => 'AC-'.$name,
            'status' => 'active', 'workload' => 'green', 'nric' => '880101-14-5500', 'date_of_birth' => '1988-01-01',
            'joined_at' => '2020-01-01', 'salary' => 3000]);
        SalaryStructure::forceCreate(['tenant_id' => $this->tenant->id, 'employee_id' => $emp->id, 'basic_salary' => 3000,
            'nationality' => 'citizen', 'bank_name' => 'Maybank', 'bank_code' => 'MBBEMYKL', 'bank_account_no' => '1',
            'epf_no' => '1', 'socso_no' => '1', 'tax_no' => 'SG1']);

        return $emp;
    }

    private function actingHr(): self
    {
        $this->actingAs($this->hr->fresh())->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    private function actingStaff(): self
    {
        $this->actingAs($this->staffUser->fresh())->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    /** Creates and finalizes a run, leaving it unpublished unless $publishNow. */
    private function finalizedRun(bool $publishNow = false): PayrollRun
    {
        $this->actingHr()->post(route('payroll.runs.create'), ['period' => '2026-06', 'payment_date' => '2026-06-30'])
            ->assertSessionHasNoErrors();
        $run = PayrollRun::firstOrFail();
        $this->actingHr()->post(route('payroll.runs.finalize', $run), array_filter([
            'payment_date' => '2026-06-30',
            'publish_now' => $publishNow ? '1' : null,
        ]))->assertSessionHasNoErrors();

        return $run->fresh();
    }

    private function slipFor(Employee $employee): Payslip
    {
        return Payslip::where('employee_id', $employee->id)->firstOrFail();
    }

    public function test_finalizing_does_not_publish_and_staff_cannot_see_the_payslip(): void
    {
        $run = $this->finalizedRun();
        $this->assertNull($run->published_at);

        $slip = $this->slipFor($this->staff);
        $this->actingStaff()->get(route('payroll.payslips.pdf', $slip))->assertForbidden();
        $this->actingStaff()->get('/app/payroll-my')->assertOk()->assertDontSee('?payslip='.$slip->id, false);

        // HR is still free to check the finalized figures before releasing them.
        $this->actingHr()->get(route('payroll.payslips.pdf', $slip))->assertOk();

        Notification::assertNothingSent();
        $this->assertDatabaseMissing('app_notifications', ['title' => 'Payslip ready']);
    }

    public function test_publishing_releases_the_payslips_and_notifies_once_per_employee_with_a_login(): void
    {
        $run = $this->finalizedRun();

        $this->actingHr()->post(route('payroll.runs.publish', $run))->assertSessionHasNoErrors();

        $this->assertNotNull($run->fresh()->published_at);
        $slip = $this->slipFor($this->staff);
        $this->actingStaff()->get(route('payroll.payslips.pdf', $slip))->assertOk();
        $this->actingStaff()->get('/app/payroll-my')->assertOk()->assertSee('?payslip='.$slip->id, false);

        // One in-app notice for the employee with a login, none for the one without. The email waits for the 5th.
        Notification::assertNothingSent();
        $this->assertSame(1, AppNotification::where('title', 'Payslip ready')->count());
        $this->assertSame($this->staffUser->id, AppNotification::where('title', 'Payslip ready')->value('user_id'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'Published payroll run']);
    }

    public function test_a_second_publish_is_rejected(): void
    {
        $run = $this->finalizedRun();
        $this->actingHr()->post(route('payroll.runs.publish', $run))->assertSessionHasNoErrors();

        $this->actingHr()->post(route('payroll.runs.publish', $run->fresh()))->assertStatus(422);
        $this->assertSame(1, AppNotification::where('title', 'Payslip ready')->count());
    }

    public function test_a_draft_run_cannot_be_published(): void
    {
        $this->actingHr()->post(route('payroll.runs.create'), ['period' => '2026-06', 'payment_date' => '2026-06-30'])
            ->assertSessionHasNoErrors();

        $this->actingHr()->post(route('payroll.runs.publish', PayrollRun::firstOrFail()))->assertStatus(422);
    }

    public function test_publish_now_on_finalize_does_both(): void
    {
        $run = $this->finalizedRun(publishNow: true);

        $this->assertNotNull($run->published_at);
        $this->actingStaff()->get(route('payroll.payslips.pdf', $this->slipFor($this->staff)))->assertOk();
        Notification::assertNothingSent();
    }

    public function test_a_backfilled_run_stays_visible_to_staff(): void
    {
        // What the migration does to runs finalized before publishing existed.
        $run = $this->finalizedRun();
        $run->forceFill(['published_at' => $run->finalized_at])->save();

        $this->actingStaff()->get(route('payroll.payslips.pdf', $this->slipFor($this->staff)))->assertOk();
    }

    public function test_publish_cannot_cross_tenants(): void
    {
        $run = $this->finalizedRun();
        $other = Tenant::create(['slug' => 'other', 'name' => 'Other', 'initials' => 'OT']);
        $intruder = User::create(['name' => 'Intruder', 'email' => 'intruder@example.com', 'password' => Hash::make('password')]);
        $intruder->tenants()->attach($other->id, ['role' => 'hr']);

        $this->actingAs($intruder)->withSession(['current_tenant' => $other->id])
            ->post(route('payroll.runs.publish', $run))->assertForbidden();
        $this->assertNull($run->fresh()->published_at);
    }

    public function test_pdf_data_carries_the_pay_statement_particulars(): void
    {
        $run = $this->finalizedRun(publishNow: true);
        $slip = $this->slipFor($this->staff)->load(['employee.salaryStructure', 'payrollRun', 'lines']);

        $data = app(PayslipPdfData::class)->build($slip);
        $p = $data['particulars'];

        $this->assertSame(3000.0, $p['monthlyRate']);
        $this->assertSame(30, $p['daysEmployed']);
        $this->assertSame(30, $p['daysInMonth']);
        $this->assertSame('30/06/2026', $p['paymentDate']);
        $this->assertSame('12345678', $p['employerEpfNo']);
        $this->assertSame('A1234567890', $p['employerSocsoNo']);
        $this->assertSame($run->label, $slip->payrollRun->label);
    }

    public function test_daily_and_hourly_rates_appear_once_unpaid_leave_is_on_the_payslip(): void
    {
        $this->finalizedRun(publishNow: true);
        $slip = $this->slipFor($this->staff);
        $slip->forceFill(['unpaid_days' => 2, 'unpaid_deduction' => 230.77])->save();

        $p = app(PayslipPdfData::class)->build($slip->fresh()->load(['employee.salaryStructure', 'payrollRun', 'lines']))['particulars'];

        // Ordinary rate of pay, s.60I: monthly basic ÷ 26, then ÷ 8 for the hourly rate.
        $this->assertSame(115.38, $p['dailyRate']);
        $this->assertSame(14.42, $p['hourlyRate']);
        $this->assertSame(2.0, $p['unpaidDays']);
    }

    public function test_acknowledgement_is_rejected_while_the_setting_is_off_and_works_when_on(): void
    {
        $run = $this->finalizedRun(publishNow: true);
        $slip = $this->slipFor($this->staff);

        $this->actingStaff()->post(route('payroll.payslips.acknowledge', $slip))->assertStatus(422);
        $this->assertNull($slip->fresh()->acknowledged_at);
        $this->actingStaff()->get('/app/payroll-my?payslip='.$slip->id)->assertOk()
            ->assertDontSee(route('payroll.payslips.acknowledge', $slip), false);

        app(FeatureManager::class)->setTenant($this->tenant, 'payroll.payslip_acknowledgement', true);

        $this->actingStaff()->get('/app/payroll-my?payslip='.$slip->id)->assertOk()
            ->assertSee(route('payroll.payslips.acknowledge', $slip), false);
        $this->actingStaff()->post(route('payroll.payslips.acknowledge', $slip))->assertSessionHasNoErrors();
        $this->assertNotNull($slip->fresh()->acknowledged_at);

        // The run review names whoever has not pressed it yet.
        $this->actingHr()->get('/app/payroll-payment?tab=payout&run='.$run->id)->assertOk()
            ->assertSee('Not acknowledged yet')->assertSee('Nobody');
    }

    public function test_acknowledgement_needs_a_published_run(): void
    {
        app(FeatureManager::class)->setTenant($this->tenant, 'payroll.payslip_acknowledgement', true);
        $this->finalizedRun();

        $this->actingStaff()->post(route('payroll.payslips.acknowledge', $this->slipFor($this->staff)))->assertStatus(422);
    }
}
