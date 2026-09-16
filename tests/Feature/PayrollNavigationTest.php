<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\Tenant;
use App\Models\User;
use App\Services\FeatureManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PayrollNavigationTest extends TestCase
{
    use RefreshDatabase;

    private const HR_SCREENS = ['payroll-transaction', 'payroll-process', 'payroll-review', 'payroll-payment', 'payroll-form'];

    private const ALL_SCREENS = ['payroll', 'payroll-my', 'payroll-transaction', 'payroll-process', 'payroll-review', 'payroll-payment', 'payroll-form'];

    private Tenant $tenant;

    private User $hr;

    private User $manager;

    private User $empUser;

    private Employee $emp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);

        $this->hr = User::create(['name' => 'Boss', 'email' => 'boss@example.com', 'password' => Hash::make('password')]);
        $this->hr->tenants()->attach($this->tenant->id, ['role' => 'hr']);
        Employee::create(['tenant_id' => $this->tenant->id, 'user_id' => $this->hr->id, 'name' => 'Boss', 'status' => 'active', 'workload' => 'green']);

        $this->manager = User::create(['name' => 'Lead', 'email' => 'lead@example.com', 'password' => Hash::make('password')]);
        $this->manager->tenants()->attach($this->tenant->id, ['role' => 'manager']);
        Employee::create(['tenant_id' => $this->tenant->id, 'user_id' => $this->manager->id, 'name' => 'Lead', 'status' => 'active', 'workload' => 'green']);

        $this->empUser = User::create(['name' => 'Worker', 'email' => 'worker@example.com', 'password' => Hash::make('password')]);
        $this->empUser->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $this->emp = Employee::create(['tenant_id' => $this->tenant->id, 'user_id' => $this->empUser->id, 'name' => 'Worker', 'status' => 'active', 'workload' => 'green', 'salary' => 4000]);
    }

    private function acting(User $user): self
    {
        $this->actingAs($user)->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    public function test_hr_opens_every_payroll_screen(): void
    {
        foreach (array_merge(['payroll-my'], self::HR_SCREENS) as $screen) {
            $this->acting($this->hr)->get("/app/{$screen}")->assertOk();
        }
    }

    public function test_employee_opens_my_payroll_but_not_the_hr_screens(): void
    {
        $this->acting($this->empUser)->get('/app/payroll-my')->assertOk();

        foreach (self::HR_SCREENS as $screen) {
            $this->acting($this->empUser)->get("/app/{$screen}")->assertForbidden();
        }
    }

    public function test_manager_cannot_open_the_hr_screens(): void
    {
        foreach (self::HR_SCREENS as $screen) {
            $this->acting($this->manager)->get("/app/{$screen}")->assertForbidden();
        }
    }

    public function test_parent_payroll_lands_by_role(): void
    {
        $this->acting($this->hr)->get('/app/payroll')->assertRedirect('/app/payroll-process');
        $this->acting($this->empUser)->get('/app/payroll')->assertRedirect('/app/payroll-my');

        $director = User::create(['name' => 'Chief', 'email' => 'chief@example.com', 'password' => Hash::make('password')]);
        $director->tenants()->attach($this->tenant->id, ['role' => 'director']);
        $this->acting($director)->get('/app/payroll')->assertRedirect('/app/payroll-process');
        $this->acting($director)->get('/app/payroll-payment')->assertOk();
    }

    public function test_sidebar_lists_the_six_children_for_hr_and_only_my_payroll_for_staff(): void
    {
        $hr = $this->acting($this->hr)->get('/app/dash')->assertOk()->getContent();
        foreach (self::HR_SCREENS as $screen) {
            $this->assertStringContainsString(route('app.screen', ['screen' => $screen]), $hr);
        }
        $this->assertStringContainsString(route('app.screen', ['screen' => 'payroll-my']), $hr);

        $staff = $this->acting($this->empUser)->get('/app/dash')->assertOk()->getContent();
        $this->assertStringContainsString(route('app.screen', ['screen' => 'payroll-my']), $staff);
        foreach (self::HR_SCREENS as $screen) {
            $this->assertStringNotContainsString(route('app.screen', ['screen' => $screen]).'"', $staff);
        }
    }

    public function test_module_off_hides_all_seven_ids(): void
    {
        app(FeatureManager::class)->setTenant($this->tenant, 'module.payroll', false);

        foreach (self::ALL_SCREENS as $screen) {
            $this->acting($this->hr)->get("/app/{$screen}")->assertNotFound();
        }

        $html = $this->acting($this->hr)->get('/app/dash')->assertOk()->getContent();
        $this->assertStringNotContainsString('>Payroll<', $html);
        $this->assertStringNotContainsString(route('app.screen', ['screen' => 'payroll-my']), $html);
    }

    /** @return array<string, list<string>> screen => tab ids that are stubs */
    private static function stubTabs(): array
    {
        return [
            'payroll-my' => ['tp1'],
            'payroll-transaction' => ['cp38', 'rebate', 'tp1'],
            'payroll-process' => ['bonus', 'control'],
            'payroll-review' => ['batch-remove'],
            'payroll-payment' => ['audit'],
            'payroll-form' => ['borang-a', 'borang-8a', 'cp39', 'cp21', 'cp22', 'cp22a', 'sip2', 'pcb2', 'zakat', 'hrdf'],
        ];
    }

    public function test_every_stub_tab_renders_the_not_yet_available_card(): void
    {
        foreach (self::stubTabs() as $screen => $tabs) {
            $html = $this->acting($this->hr)->get("/app/{$screen}")->assertOk()->getContent();
            foreach ($tabs as $tab) {
                $this->assertStringContainsString("x-show=\"tab === '{$tab}'\"", $html, "{$screen} lacks tab {$tab}");
            }
            $this->assertStringContainsString('Not yet available', $html);
            $this->assertMatchesRegularExpression('/Spec F\d+|Follow-up/', $html);
        }
    }

    public function test_tab_query_selects_the_opening_tab(): void
    {
        $this->acting($this->hr)->get('/app/payroll-form?tab=hrdf')->assertOk()->assertSee("x-data=\"{ tab: 'hrdf' }\"", false);
        $this->acting($this->hr)->get('/app/payroll-form?tab=nope')->assertOk()->assertSee("x-data=\"{ tab: 'form-e' }\"", false);
    }

    private function finalizedPayslipFor(Employee $employee, string $period = '2026-03'): Payslip
    {
        $run = PayrollRun::forceCreate(['tenant_id' => $this->tenant->id, 'period' => $period, 'status' => 'finalized', 'finalized_at' => now()]);
        $slip = new Payslip(['employee_id' => $employee->id]);
        $slip->tenant_id = $this->tenant->id;
        $slip->payroll_run_id = $run->id;
        $slip->forceFill(['basic' => 4000, 'gross' => 4000, 'net_pay' => 3500])->save();

        return $slip;
    }

    public function test_my_payroll_shows_own_slip_and_acknowledges_it_once(): void
    {
        $slip = $this->finalizedPayslipFor($this->emp);

        $this->acting($this->empUser)->get('/app/payroll-my?payslip='.$slip->id)->assertOk()
            ->assertSee(route('payroll.payslips.acknowledge', $slip), false)
            ->assertSee('EA Form');

        $this->acting($this->empUser)->post(route('payroll.payslips.acknowledge', $slip))
            ->assertRedirect(route('app.screen', ['screen' => 'payroll-my', 'payslip' => $slip->id]));
        $this->assertNotNull($slip->fresh()->acknowledged_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'Acknowledged payslip']);

        $this->acting($this->empUser)->post(route('payroll.payslips.acknowledge', $slip))->assertStatus(422);
        $this->acting($this->empUser)->get('/app/payroll-my?payslip='.$slip->id)->assertOk()->assertSee('Acknowledged on');
    }

    public function test_cannot_acknowledge_another_persons_payslip(): void
    {
        $other = Employee::create(['tenant_id' => $this->tenant->id, 'user_id' => $this->manager->id, 'name' => 'Lead2', 'status' => 'active', 'workload' => 'green']);
        $slip = $this->finalizedPayslipFor($other);

        $this->acting($this->empUser)->post(route('payroll.payslips.acknowledge', $slip))->assertForbidden();
        $this->assertNull($slip->fresh()->acknowledged_at);
    }

    public function test_employee_can_view_own_ea_form_but_not_anothers(): void
    {
        $this->finalizedPayslipFor($this->emp);
        $this->acting($this->empUser)->get(route('payroll.ea-form.show', ['employee' => $this->emp->id, 'year' => 2026]))->assertOk();

        $other = Employee::create(['tenant_id' => $this->tenant->id, 'name' => 'Other', 'status' => 'active', 'workload' => 'green']);
        $this->acting($this->empUser)->get(route('payroll.ea-form.show', ['employee' => $other->id, 'year' => 2026]))->assertForbidden();
    }

    public function test_transaction_screen_carries_the_fixed_individual_takeon_and_items_forms(): void
    {
        $html = $this->acting($this->hr)->get('/app/payroll-transaction')->assertOk()->getContent();

        $this->assertStringContainsString(route('payroll.fixed-transactions.store'), $html);
        $this->assertStringContainsString(route('payroll.individual-transactions.store'), $html);
        $this->assertStringContainsString(route('payroll.opening'), $html);
        $this->assertStringContainsString('Payroll Figures Take On', $html);
        $this->assertStringContainsString(route('app.screen', 'directory'), $html);
        $this->assertStringNotContainsString(route('payroll.salary'), $html);
    }

    public function test_individual_transaction_period_filter_targets_the_transaction_screen(): void
    {
        $this->acting($this->hr)->get('/app/payroll-transaction?tab=individual&itx_period=2026-02')->assertOk()
            ->assertSee('action="'.route('app.screen', 'payroll-transaction').'"', false)
            ->assertSee("x-data=\"{ tab: 'individual' }\"", false);
    }
}
