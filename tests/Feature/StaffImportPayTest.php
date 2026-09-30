<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\SalaryStructure;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The staff import's pay columns: one upload sets up the salary structure payroll needs,
 * so HR doesn't have to open every profile.
 */
class StaffImportPayTest extends TestCase
{
    use RefreshDatabase;

    private const string HEADER = 'name,staff_id,bank_name,bank_account_no,epf_no,socso_no,tax_no';

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $this->loginAs('hr');
    }

    private function loginAs(string $role): User
    {
        $user = User::create(['name' => ucfirst($role), 'email' => $role.'@example.com', 'password' => Hash::make('password')]);
        $user->tenants()->attach($this->tenant->id, ['role' => $role]);
        $this->actingAs($user)->withSession(['current_tenant' => $this->tenant->id]);

        return $user;
    }

    /** @param list<string> $rows */
    private function importCsv(array $rows): TestResponse
    {
        $csv = implode("\n", [self::HEADER, ...$rows])."\n";

        return $this->post('/app/employees/import', ['file' => UploadedFile::fake()->createWithContent('staff.csv', $csv)]);
    }

    private function structureFor(string $name): ?SalaryStructure
    {
        $employee = Employee::withoutGlobalScopes()->where('name', $name)->firstOrFail();

        return SalaryStructure::withoutGlobalScopes()->where('employee_id', $employee->id)->first();
    }

    public function test_pay_columns_set_up_the_salary_structure_with_the_bank_code(): void
    {
        $this->importCsv(['Adri,UR1,maybank,162272608045,17191228,850315105837,SG1'])->assertRedirect()
            ->assertSessionHas('import_report', [['row' => 2, 'name' => 'Adri', 'outcome' => 'created', 'notes' => ['Pay details set up.']]]);

        $s = $this->structureFor('Adri');
        $this->assertSame('Maybank', $s->bank_name);
        $this->assertSame('MBBEMYKL', $s->bank_code);
        $this->assertSame('162272608045', $s->bank_account_no);
        $this->assertSame('17191228', $s->epf_no);
        $this->assertSame('850315105837', $s->socso_no);
        $this->assertSame('SG1', $s->tax_no);
        $this->assertSame('citizen', $s->nationality);
        $this->assertTrue($s->tax_resident);
        $this->assertNotNull($s->effective_from);
        $this->assertTrue(AuditLog::withoutGlobalScopes()->where('action', 'Updated salary structure')->where('target', 'Adri (staff import)')->exists());
    }

    public function test_a_row_with_no_pay_cells_creates_no_structure(): void
    {
        $this->importCsv(['Adri,UR1,,,,,'])->assertRedirect();

        $this->assertNull($this->structureFor('Adri'));
    }

    public function test_blank_pay_cells_on_a_reupload_keep_what_is_there(): void
    {
        $this->importCsv(['Adri,UR1,Maybank,111,E1,S1,T1']);
        $this->importCsv(['Adri,UR1,,,E2,,'])->assertRedirect()
            ->assertSessionHas('import_report', [['row' => 2, 'name' => 'Adri', 'outcome' => 'updated', 'notes' => ['Pay details updated.']]]);

        $s = $this->structureFor('Adri');
        $this->assertSame('E2', $s->epf_no);
        $this->assertSame('111', $s->bank_account_no);
        $this->assertSame('MBBEMYKL', $s->bank_code);
        $this->assertSame('S1', $s->socso_no);
    }

    public function test_an_unknown_bank_is_kept_by_name_with_no_code_and_flagged(): void
    {
        $this->importCsv(['Adri,UR1,MBB,111,,,'])->assertRedirect()
            ->assertSessionHas('import_report', fn (array $report) => str_contains($report[0]['notes'][1], 'Bank "MBB" not recognised'));

        $s = $this->structureFor('Adri');
        $this->assertSame('MBB', $s->bank_name);
        $this->assertNull($s->bank_code);
    }

    public function test_skipped_rows_are_reported_with_their_reason(): void
    {
        $this->post('/app/employees/import', ['file' => UploadedFile::fake()->createWithContent('staff.csv', "name,email\nAdri,not-an-email\n")])
            ->assertSessionHas('import_report', [['row' => 2, 'name' => 'Adri', 'outcome' => 'skipped', 'notes' => ['Invalid email.']]]);
    }

    public function test_the_import_screen_lists_each_row_with_skipped_rows_first(): void
    {
        $this->followingRedirects()
            ->from('/app/staff-load')
            ->post('/app/employees/import', ['file' => UploadedFile::fake()->createWithContent('staff.csv', "name,email,bank_name\nAdri,,Maybank\nBad,not-an-email,\n")])
            ->assertOk()
            ->assertSee('Last import')
            ->assertSeeInOrder(['Bad', 'Invalid email.', 'Adri', 'Pay details set up.'])
            ->assertSee('ready for payroll');
    }

    public function test_someone_given_import_access_without_a_pay_role_cannot_set_pay(): void
    {
        $manager = $this->loginAs('manager');
        UserPermission::forceCreate(['tenant_id' => $this->tenant->id, 'user_id' => $manager->id, 'permission' => 'staff.import', 'granted' => true]);

        $this->importCsv(['Adri,UR1,Maybank,111,E1,S1,T1'])->assertRedirect()
            ->assertSessionHas('import_report', fn (array $report) => $report[0]['outcome'] === 'created'
                && $report[0]['notes'] === ['Pay details not saved: only HR or management can set pay.']);

        $this->assertNull($this->structureFor('Adri'));
    }
}
