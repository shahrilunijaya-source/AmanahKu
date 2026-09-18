<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Payroll\FormEData;
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
        $this->post(route('admin.settings.update'), [
            'name' => 'Acme',
            'employer_tin' => '1234567890',
            'epf_employer_no' => ' 12345678 ',
            'socso_employer_code' => 'A1234567890X',
            'hrdf_registration_no' => '1234567-K',
            'employer_category' => '2',
            'employer_status' => '1',
            'paying_bank_code' => 'MBBEMYKL',
            'paying_bank_account_no' => '514011223344',
            'payroll_contact_name' => 'Aini',
            'payroll_contact_phone' => '0123456789',
        ])->assertSessionHasNoErrors();

        $t = $this->tenant->fresh();
        $this->assertSame('12345678', $t->epf_employer_no);
        $this->assertSame('A1234567890X', $t->socso_employer_code);
        $this->assertSame('1234567-K', $t->hrdf_registration_no);
        $this->assertSame('2', $t->employer_category);
        $this->assertSame('MBBEMYKL', $t->paying_bank_code);
        $this->assertSame('Aini', $t->payroll_contact_name);
    }

    public function test_epf_number_rejects_letters_beyond_digits_and_hyphens(): void
    {
        $this->post(route('admin.settings.update'), ['name' => 'Acme', 'epf_employer_no' => '12 34!'])
            ->assertSessionHasErrors('epf_employer_no');
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
