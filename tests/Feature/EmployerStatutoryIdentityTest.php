<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\SetupController;
use App\Models\AuditLog;
use App\Models\CompanySetupProgress;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Services\FeatureManager;
use App\Services\Payroll\FormEData;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class EmployerStatutoryIdentityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $this->hr = User::create(['name' => 'Boss', 'email' => 'boss@example.com', 'password' => Hash::make('password')]);
        $this->hr->tenants()->attach($this->tenant->id, ['role' => 'hr']);
        $this->actingAs($this->hr)->withSession(['current_tenant' => $this->tenant->id]);
    }

    public function test_settings_save_persists_statutory_registration(): void
    {
        $this->post(route('admin.settings.statutory'), [
            'employer_tin' => 'e 9123456708',
            'epf_employer_no' => ' 12345678 ',
            'socso_employer_code' => 'b32000 12345z',
            'hrdf_registration_no' => '1234567-K',
            'zakat_employer_no' => 'z9001',
            'employer_category' => '2',
            'employer_status' => '1',
            'paying_bank_code' => 'MBBEMYKL',
            'paying_bank_account_no' => '514011223344',
            'payroll_contact_name' => 'Aini',
            'payroll_contact_phone' => '0123456789',
        ])->assertSessionHasNoErrors();

        $t = $this->tenant->fresh();
        $this->assertSame('9123456708', $t->employer_tin);          // "E" prefix and space dropped
        $this->assertSame('12345678', $t->epf_employer_no);
        $this->assertSame('B3200012345Z', $t->socso_employer_code); // spaces out, upper case
        $this->assertSame('1234567-K', $t->hrdf_registration_no);
        $this->assertSame('Z9001', $t->zakat_employer_no);
        $this->assertSame('2', $t->employer_category);
        $this->assertSame('MBBEMYKL', $t->paying_bank_code);
        $this->assertSame('Aini', $t->payroll_contact_name);

        $audit = AuditLog::where('action', 'Updated statutory details')->latest('id')->value('target');
        $this->assertStringContainsString('PERKESO employer code', (string) $audit);
        $this->assertStringContainsString('Zakat employer number', (string) $audit);
    }

    public function test_epf_number_rejects_letters_beyond_digits_and_hyphens(): void
    {
        $this->post(route('admin.settings.statutory'), ['epf_employer_no' => '12 34!'])
            ->assertSessionHasErrorsIn('statutory', 'epf_employer_no');
    }

    public function test_statutory_and_profile_saves_leave_each_other_alone(): void
    {
        $this->tenant->update(['address' => 'Jalan 1', 'socso_employer_code' => 'B3200012345Z']);

        $this->post(route('admin.settings.statutory'), ['epf_employer_no' => '012345678'])->assertSessionHasNoErrors();
        $this->assertSame('Jalan 1', $this->tenant->fresh()->address);

        $this->post(route('admin.settings.update'), ['name' => 'Acme'])->assertSessionHasNoErrors();
        $this->assertSame('012345678', $this->tenant->fresh()->epf_employer_no);
    }

    public function test_signatory_must_be_one_of_this_companys_staff(): void
    {
        $mine = Employee::create(['tenant_id' => $this->tenant->id, 'name' => 'Siti Payroll', 'staff_id' => 'S0066', 'status' => 'active', 'workload' => 'green']);
        $other = Tenant::create(['slug' => 'other', 'name' => 'Other', 'initials' => 'OT']);
        $theirs = Employee::create(['tenant_id' => $other->id, 'name' => 'Stranger', 'staff_id' => 'X1', 'status' => 'active', 'workload' => 'green']);

        $this->post(route('admin.settings.statutory'), ['statutory_signatory_employee_id' => $theirs->id])
            ->assertSessionHasErrorsIn('statutory', 'statutory_signatory_employee_id');

        $this->post(route('admin.settings.statutory'), ['statutory_signatory_employee_id' => $mine->id])->assertSessionHasNoErrors();
        $this->assertSame('Siti Payroll', $this->tenant->fresh()->statutorySignatory?->name);
    }

    public function test_only_hr_and_management_can_save_statutory_details(): void
    {
        $staff = User::create(['name' => 'Staff', 'email' => 'staff@example.com', 'password' => Hash::make('password')]);
        $staff->tenants()->attach($this->tenant->id, ['role' => 'employee']);

        $this->actingAs($staff)->withSession(['current_tenant' => $this->tenant->id])
            ->post(route('admin.settings.statutory'), ['epf_employer_no' => '1'])->assertForbidden();
        $this->assertNull($this->tenant->fresh()->epf_employer_no);
    }

    public function test_setup_step_is_done_only_when_the_employer_numbers_are_set(): void
    {
        app(CurrentTenant::class)->set($this->tenant);
        $features = app(FeatureManager::class);
        $features->setTenant($this->tenant, 'module.payroll', true);
        $step = fn () => collect(app(SetupController::class)->compute()['rows'])->firstWhere('key', 'statutory');

        $this->assertSame(['section' => 'statutory'], app(SetupController::class)->stepDefs()['statutory']['query']);
        $this->assertFalse($step()['done']);

        $this->tenant->update(['employer_tin' => '9123456708', 'epf_employer_no' => '012345678', 'socso_employer_code' => 'B3200012345Z']);
        $this->assertTrue($step()['done']);

        // HRD Corp only counts once the levy is switched on.
        $features->setTenant($this->tenant, 'payroll.hrdf', '1');
        $this->assertFalse($step()['done']);
        $this->tenant->update(['hrdf_registration_no' => '1234567K']);
        $this->assertTrue($step()['done']);
    }

    public function test_company_setup_links_to_company_settings_after_setup_is_finished(): void
    {
        CompanySetupProgress::forceCreate(['tenant_id' => $this->tenant->id, 'completed_at' => now()]);

        $this->get(route('app.screen', 'setup'))->assertOk()
            ->assertSee('data-testid="company-settings-card"', false)
            ->assertSee(route('app.screen', ['screen' => 'settings', 'section' => 'statutory']), false);
        $this->get(route('app.screen', ['screen' => 'settings', 'section' => 'statutory']))->assertOk()
            ->assertSee('Save statutory details');
    }

    public function test_form_e_carries_items_3_to_5(): void
    {
        $this->tenant->update(['employer_tin' => '1234567890', 'employer_category' => '2', 'employer_status' => '1']);
        $data = app(FormEData::class)->build($this->tenant->fresh(), 2026);

        $this->assertSame('2', $data['basic_particulars']['category_of_employer']);
        $this->assertSame('1', $data['basic_particulars']['status_of_employer']);
        // Item 5 has no honest source: the form's own list is 01=IG to 13=LE and nothing
        // we store says which applies, so it stays null and stays on the checklist.
        $this->assertNull($data['basic_particulars']['tin_type_code']);
        $labels = array_column($data['incomplete'], 'box');
        $this->assertNotContains('Item 3', $labels);
        $this->assertNotContains('Item 4', $labels);
        $this->assertContains('Item 5', $labels);
    }
}
