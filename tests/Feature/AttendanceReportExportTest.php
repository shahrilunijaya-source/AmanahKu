<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The ledger as a CSV Excel opens on a double-click. The file is built from the
 * screen's own payload, so it can never disagree with the table it came from.
 */
class AttendanceReportExportTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-08-20 10:00:00'));

        $this->tenant = Tenant::create(['slug' => 'alpha', 'name' => 'Alpha', 'initials' => 'AL']);
        $this->hr = User::create([
            'name' => 'HR', 'email' => 'hr@example.com', 'password' => Hash::make('password'),
        ]);
        $this->hr->tenants()->attach($this->tenant->id, ['role' => 'hr']);

        $employee = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->hr->id,
            'name' => 'Alice Tan', 'status' => 'active', 'workload' => 'green',
        ]);

        AttendanceRecord::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $employee->id,
            'date' => '2026-08-18', 'clock_in' => '08:52:00', 'clock_out' => '17:35:00',
            'status' => 'on_time', 'worked_minutes' => 523, 'flags' => [],
        ]);
    }

    /** @param array<string, string> $query */
    private function download(array $query = []): string
    {
        $response = $this->actingAs($this->hr)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/attendance-report/export?'.http_build_query($query));

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

        return $response->streamedContent();
    }

    /** @return array<string, string> */
    private function oneDay(): array
    {
        return ['gran' => 'custom', 'from' => '2026-08-18', 'to' => '2026-08-18'];
    }

    public function test_it_exports_a_header_and_one_row_per_person_day(): void
    {
        $csv = $this->download($this->oneDay());
        $lines = array_values(array_filter(explode("\n", trim($csv))));

        // PHP quotes any field carrying a space, so "Clock in" arrives quoted.
        $this->assertStringContainsString(
            'Date,Staff,Department,"Clock in","Clock out",Hours,Status,Flags',
            $lines[0]
        );
        $this->assertStringContainsString('2026-08-18', $lines[1]);
        $this->assertStringContainsString('Alice Tan', $lines[1]);
        $this->assertStringContainsString('08:52', $lines[1]);
        $this->assertStringContainsString('17:35', $lines[1]);
        $this->assertStringContainsString('8.72', $lines[1]);
        $this->assertStringContainsString('On time', $lines[1]);
    }

    public function test_the_filename_names_the_period(): void
    {
        $this->actingAs($this->hr)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/attendance-report/export?gran=custom&from=2026-08-10&to=2026-08-14')
            ->assertDownload('attendance-2026-08-10-to-2026-08-14.csv');
    }

    public function test_the_export_honours_the_lens(): void
    {
        $all = $this->download($this->oneDay());
        $lensed = $this->download($this->oneDay() + ['lens' => 'miss']);

        $this->assertGreaterThan(
            substr_count(trim($lensed), "\n"),
            substr_count(trim($all), "\n"),
            'exporting what you filtered to is the whole point'
        );
    }

    public function test_a_name_beginning_with_a_formula_character_is_neutralised(): void
    {
        Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => '=cmd|calc',
            'status' => 'active', 'workload' => 'green',
        ]);

        $csv = $this->download($this->oneDay());

        $this->assertStringContainsString("'=cmd|calc", $csv, 'CWE-1236: must not open as a formula');
    }

    public function test_the_export_is_recorded_in_the_audit_trail(): void
    {
        $this->download($this->oneDay());

        $this->assertDatabaseHas('audit_logs', ['action' => 'Exported attendance report']);
    }

    public function test_a_plain_employee_cannot_export(): void
    {
        $staff = User::create([
            'name' => 'Staff', 'email' => 'staff@example.com', 'password' => Hash::make('password'),
        ]);
        $staff->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $staff->id,
            'name' => 'Staff Person', 'status' => 'active', 'workload' => 'green',
        ]);

        $this->actingAs($staff)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/attendance-report/export')
            ->assertForbidden();
    }

    public function test_a_manager_with_a_direct_report_can_export(): void
    {
        // The screen gate (Permissions::canSeeAll) admits an employee-role user who has
        // somebody reporting to them. The export must not be narrower than the screen it
        // came from, or the button is visible and dead.
        $lead = User::create([
            'name' => 'Lead', 'email' => 'lead@example.com', 'password' => Hash::make('password'),
        ]);
        $lead->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $leadEmployee = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $lead->id,
            'name' => 'Lead Person', 'status' => 'active', 'workload' => 'green',
        ]);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Reports To Lead',
            'reports_to_id' => $leadEmployee->id, 'status' => 'active', 'workload' => 'green',
        ]);

        $this->actingAs($lead)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/attendance-report/export?'.http_build_query($this->oneDay()))
            ->assertOk();
    }
}
