<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\PayrollNotice;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The staff import's leaver rows: people paid earlier in the year through the old payroll
 * system who left before the switch, added so the year-end forms can include them.
 */
class StaffImportLeaverTest extends TestCase
{
    use RefreshDatabase;

    private const string HEADER = 'name,status,joined,nric,last_working_day';

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $hr = User::create(['name' => 'Boss', 'email' => 'boss@example.com', 'password' => Hash::make('password')]);
        $hr->tenants()->attach($this->tenant->id, ['role' => 'hr']);
        $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id]);
    }

    /** @param list<string> $rows */
    private function importCsv(array $rows): TestResponse
    {
        $csv = implode("\n", [self::HEADER, ...$rows])."\n";

        return $this->post('/app/employees/import', ['file' => UploadedFile::fake()->createWithContent('staff.csv', $csv)]);
    }

    public function test_a_leaver_row_is_saved_straight_to_the_archive_with_no_notices(): void
    {
        $this->importCsv(['Chong Wei Lin,resigned,2025-02-03,900101-14-0000,2026-03-31'])->assertRedirect();

        $leaver = Employee::withoutGlobalScopes()->where('name', 'Chong Wei Lin')->firstOrFail();
        $this->assertSame('resigned', $leaver->status);
        $this->assertSame('2026-03-31', $leaver->last_working_day?->toDateString());
        $this->assertSame('2026-03-31', $leaver->archived_at?->toDateString());
        $this->assertSame('900101-14-0000', $leaver->nric);
        $this->assertSame(0, PayrollNotice::withoutGlobalScopes()->where('employee_id', $leaver->id)->count());
    }

    public function test_uploading_the_same_leaver_twice_updates_the_one_record(): void
    {
        $this->importCsv(['Chong Wei Lin,resigned,2025-02-03,,2026-03-31'])->assertRedirect();
        $this->importCsv(['Chong Wei Lin,resigned,2025-02-03,900101-14-0000,2026-03-31'])->assertRedirect();

        $this->assertSame(1, Employee::withoutGlobalScopes()->where('name', 'Chong Wei Lin')->count());
        $this->assertSame('900101-14-0000', Employee::withoutGlobalScopes()->where('name', 'Chong Wei Lin')->first()?->nric);
    }

    public function test_a_current_staff_member_cannot_be_archived_through_the_import(): void
    {
        $current = Employee::create(['tenant_id' => $this->tenant->id, 'name' => 'Devi Raman', 'status' => 'active', 'workload' => 'green']);

        $this->importCsv(['Devi Raman,resigned,,,2026-03-31'])->assertSessionHas('error');

        $current->refresh();
        $this->assertSame('active', $current->status);
        $this->assertNull($current->archived_at);
    }

    public function test_a_last_working_day_without_resigned_status_is_refused(): void
    {
        $this->importCsv(['Chong Wei Lin,active,,,2026-03-31'])->assertSessionHas('error');

        $this->assertSame(0, Employee::withoutGlobalScopes()->where('name', 'Chong Wei Lin')->count());
    }

    public function test_a_new_hire_row_still_opens_the_hiring_notices(): void
    {
        $this->importCsv(['Chong Wei Lin,active,2026-09-01,,'])->assertRedirect();

        $hire = Employee::where('name', 'Chong Wei Lin')->firstOrFail();
        $this->assertTrue(PayrollNotice::withoutGlobalScopes()->where('employee_id', $hire->id)->where('type', 'cp22')->exists());
    }
}
