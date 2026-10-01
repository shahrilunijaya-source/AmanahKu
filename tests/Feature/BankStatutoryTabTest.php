<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\SalaryStructure;
use App\Models\Tenant;
use App\Models\User;
use App\Support\StatutoryOptions;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BankStatutoryTabTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        app(CurrentTenant::class)->set($this->tenant);
    }

    private function login(string $role, array $attrs = []): Employee
    {
        $user = User::create(['name' => ucfirst($role), 'email' => $role.'@example.com', 'password' => Hash::make('password')]);
        $user->tenants()->attach($this->tenant->id, ['role' => $role]);
        $employee = Employee::create(array_merge([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'name' => ucfirst($role), 'status' => 'active', 'workload' => 'green', 'joined_at' => '2025-01-06',
        ], $attrs));
        $this->actingAs($user)->withSession(['current_tenant' => $this->tenant->id]);

        return $employee;
    }

    private function emp(string $name, array $attrs = []): Employee
    {
        return Employee::create(array_merge([
            'tenant_id' => $this->tenant->id, 'name' => $name, 'status' => 'active', 'workload' => 'green', 'joined_at' => '2026-01-05',
        ], $attrs));
    }

    public function test_salary_save_persists_bank_and_statutory_fields(): void
    {
        $this->login('hr');
        $e = $this->emp('Adibah');
        $this->from("/app/profile?emp={$e->id}&tab=bank")->post('/app/payroll/salary', [
            'employee_id' => $e->id, 'basic_salary' => 3500, 'bank_name' => 'Maybank', 'bank_account_no' => '112233445566', 'bank_holder_name' => 'Adibah Z',
            'tax_no' => 'SG123', 'tax_resident' => '0', 'tax_category' => '3', 'employee_tax_status' => 'normal',
            'child_relief' => ['under_18' => ['100' => 2, '50' => 0], 'disabled' => ['100' => 0, '50' => 1]],
            'epf_no' => 'E1', 'epf_scheme' => 'statutory', 'socso_no' => 'S1', 'socso_category' => 'category_1', 'children_relief_count' => 3,
        ])->assertRedirect("/app/profile?emp={$e->id}&tab=bank");
        $s = SalaryStructure::where('employee_id', $e->id)->firstOrFail();
        $this->assertSame('Adibah Z', $s->bank_holder_name);
        $this->assertFalse($s->tax_resident);
        $this->assertSame('3', $s->tax_category);
        $this->assertSame(2, $s->child_relief_breakdown['under_18']['100']);
        $this->assertSame(1, $s->child_relief_breakdown['disabled']['50']);
        $this->assertSame(0, $s->child_relief_breakdown['over_18_education']['100']);
        $this->assertSame('category_1', $s->socso_category);
        $this->assertSame(3, $s->children_relief_count);
    }

    public function test_invalid_statutory_options_rejected(): void
    {
        $this->login('hr');
        $e = $this->emp('Adibah');
        $this->post('/app/payroll/salary', ['employee_id' => $e->id, 'basic_salary' => 1000, 'tax_category' => '9', 'epf_scheme' => 'x', 'socso_category' => 'y'])
            ->assertSessionHasErrors(['tax_category', 'epf_scheme', 'socso_category']);
    }

    /** SKBBK is a PERKESO scheme, so it sits with SOCSO / EIS rather than under Zakat. */
    public function test_skbbk_is_shown_and_edited_with_socso_not_zakat(): void
    {
        $this->login('hr');
        $e = $this->emp('Adibah');
        $this->post('/app/payroll/salary', ['employee_id' => $e->id, 'basic_salary' => 2500, 'skbbk_opt_in' => '1'])->assertRedirect();
        $this->assertTrue(SalaryStructure::where('employee_id', $e->id)->firstOrFail()->skbbk_opt_in);

        $this->get("/app/profile?emp={$e->id}&tab=bank")->assertOk()
            ->assertDontSee('Zakat · SKBBK')
            ->assertDontSee('Zakat / SKBBK')
            ->assertSeeInOrder(['SOCSO / EIS', 'SKBBK (Lindung 24 Jam)', 'Zakat (monthly)'], false)
            ->assertSeeInOrder(['EPF · SOCSO / EIS', 'name="skbbk_opt_in"', '<div class="uj-section-head">Zakat</div>'], false);
    }

    /** The payroll wizard's "Set up pay" link adds edit=bank, which opens the form once and then drops itself from the URL. */
    public function test_edit_bank_query_opens_the_form_on_arrival(): void
    {
        $this->login('hr');
        $e = $this->emp('Adibah');
        $this->get("/app/profile?emp={$e->id}&tab=bank&edit=bank")->assertOk()
            ->assertSee("get('edit') === 'bank'", false)
            ->assertSee("location.href.replace('&edit=bank', '')", false);
    }

    public function test_existing_payroll_salary_form_still_saves_without_new_fields(): void
    {
        $this->login('hr');
        $e = $this->emp('Adibah');
        $this->post('/app/payroll/salary', ['employee_id' => $e->id, 'basic_salary' => 2500])->assertRedirect();
        $s = SalaryStructure::where('employee_id', $e->id)->firstOrFail();
        $this->assertTrue($s->tax_resident);
        $this->assertNull($s->child_relief_breakdown);
        $this->assertSame(0, $s->children_relief_count);
    }

    /** Old rows typed "MBB" had no bank code, so payroll kept flagging them. The list name and code are filled on save. */
    public function test_a_bank_shorthand_is_saved_as_the_list_bank_with_its_code(): void
    {
        $this->assertSame('Maybank', StatutoryOptions::bankFor('MBB'));
        $this->assertSame('Maybank', StatutoryOptions::bankFor(' maybank '));
        $this->assertNull(StatutoryOptions::bankFor('Other'));
        $this->assertNull(StatutoryOptions::bankFor(''));

        $this->login('hr');
        $e = $this->emp('Haryati');
        $this->post('/app/payroll/salary', ['employee_id' => $e->id, 'basic_salary' => 5000, 'bank_name' => 'MBB', 'bank_account_no' => '111'])->assertRedirect();
        $s = SalaryStructure::where('employee_id', $e->id)->firstOrFail();
        $this->assertSame('Maybank', $s->bank_name);
        $this->assertSame('MBBEMYKL', $s->bank_code);

        $s->forceFill(['bank_name' => 'MBB', 'bank_code' => null])->save();
        $this->get("/app/profile?emp={$e->id}&tab=bank")->assertOk()->assertSee('<option value="Maybank" selected', false);
    }

    /** A super admin looking around a tenant (no membership) gets the HR view of Bank & Statutory. */
    public function test_a_super_admin_observer_sees_bank_and_statutory(): void
    {
        $e = $this->emp('Haryati');
        $admin = User::create(['name' => 'Root', 'email' => 'root@example.com', 'password' => Hash::make('password')]);
        $admin->forceFill(['is_super_admin' => true])->save();
        $this->actingAs($admin)->withSession(['current_tenant' => $this->tenant->id]);

        $this->get("/app/profile?emp={$e->id}&tab=bank")->assertOk()->assertSee('Bank &amp; Statutory', false);
    }

    /** Worksy's EPF block: Custom keeps its two rates; switching to Statutory drops them and keeps the Additional. */
    public function test_epf_custom_rate_and_additional_are_saved_for_the_chosen_scheme_only(): void
    {
        $this->login('hr');
        $e = $this->emp('Haryati');
        $post = fn (array $epf) => $this->post('/app/payroll/salary', ['employee_id' => $e->id] + $epf)->assertSessionHasNoErrors();

        $post(['epf_scheme' => 'custom', 'epf_employee_rate_override' => 20, 'epf_employer_rate_override' => 13, 'epf_additional_employee' => 5]);
        $s = SalaryStructure::where('employee_id', $e->id)->firstOrFail();
        $this->assertSame([20.0, 13.0, null], [$s->epf_employee_rate_override, $s->epf_employer_rate_override, $s->epf_additional_employee]);

        $post(['epf_scheme' => 'statutory', 'epf_employee_rate_override' => 20, 'epf_additional_by' => 'amount', 'epf_additional_employee' => 100]);
        $s->refresh();
        $this->assertSame([null, null, 'amount', 100.0], [$s->epf_employee_rate_override, $s->epf_employer_rate_override, $s->epf_additional_by, $s->epf_additional_employee]);

        $this->get("/app/profile?emp={$e->id}&tab=bank")->assertOk()->assertSee('Additional Employee')->assertSee('RM 100.00');
    }

    public function test_a_custom_epf_scheme_needs_both_rates(): void
    {
        $this->login('hr');
        $e = $this->emp('Haryati');

        $this->post('/app/payroll/salary', ['employee_id' => $e->id, 'epf_scheme' => 'custom', 'epf_employee_rate_override' => 20])
            ->assertSessionHasErrors('epf_employer_rate_override');
    }
}
