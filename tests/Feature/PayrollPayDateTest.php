<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PayrollRun;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PayrollPayDateTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private PayrollRun $run;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $hr = User::create(['name' => 'Boss', 'email' => 'boss@example.com', 'password' => Hash::make('password')]);
        $hr->tenants()->attach($this->tenant->id, ['role' => 'hr']);
        $this->run = PayrollRun::forceCreate(['tenant_id' => $this->tenant->id, 'period' => '2026-08', 'label' => 'August 2026', 'status' => 'draft']);
        $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id]);
    }

    public function test_pay_by_date_is_the_seventh_day_after_period_end(): void
    {
        $this->assertSame('2026-09-07', $this->run->payByDate()->toDateString());
    }

    public function test_finalize_refuses_the_eighth_day_and_accepts_the_seventh(): void
    {
        $this->post(route('payroll.runs.finalize', $this->run), ['payment_date' => '2026-09-08'])->assertSessionHasErrors('payment_date');
        $this->assertStringContainsString('s.19', (string) session('errors')->first('payment_date'));
        $this->assertSame('draft', $this->run->fresh()->status);

        $this->post(route('payroll.runs.finalize', $this->run), ['payment_date' => '2026-09-07'])->assertSessionHasNoErrors();
        $this->assertSame('finalized', $this->run->fresh()->status);
        $this->assertSame('2026-09-07', $this->run->fresh()->payment_date->toDateString());
    }

    public function test_finalize_without_a_pay_date_is_refused(): void
    {
        $this->post(route('payroll.runs.finalize', $this->run), [])->assertSessionHasErrors('payment_date');
    }

    public function test_override_with_reason_is_allowed_and_audited(): void
    {
        $this->post(route('payroll.runs.finalize', $this->run), ['payment_date' => '2026-09-10', 'pay_date_override_reason' => 'Overtime portion paid with September wages'])
            ->assertSessionHasNoErrors();
        $r = $this->run->fresh();
        $this->assertSame('finalized', $r->status);
        $this->assertSame('Overtime portion paid with September wages', $r->pay_date_override_reason);
        $this->assertDatabaseHas('audit_logs', ['action' => 'Pay date later than seven days']);
    }

    public function test_mark_paid_sets_paid_at_on_a_finalized_run_only(): void
    {
        $this->post(route('payroll.runs.mark-paid', $this->run))->assertStatus(422);
        $this->run->forceFill(['status' => 'finalized', 'finalized_at' => now(), 'payment_date' => '2026-09-05'])->save();
        $this->post(route('payroll.runs.mark-paid', $this->run))->assertSessionHasNoErrors();
        $this->assertNotNull($this->run->fresh()->paid_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'Marked payroll paid']);
    }

    public function test_the_dashboard_card_shows_the_unpaid_run_not_a_paid_one_in_the_same_month(): void
    {
        $this->run->forceFill(['status' => 'finalized', 'finalized_at' => now(), 'payment_date' => '2026-09-05'])->save();
        $bonus = PayrollRun::forceCreate(['tenant_id' => $this->tenant->id, 'period' => '2026-08', 'kind' => 'bonus',
            'label' => 'August 2026 bonus', 'status' => 'finalized', 'finalized_at' => now(), 'payment_date' => '2026-08-20', 'paid_at' => now()]);

        $this->assertSame($this->run->id, PayrollRun::forPayByCard()?->id);

        // Once everything is paid, the newest run is shown.
        $this->run->forceFill(['paid_at' => now()])->save();
        $this->assertSame($bonus->id, PayrollRun::forPayByCard()?->id);
    }
}
