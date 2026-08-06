<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AttendanceReportScreenTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $hrUser;

    private Employee $hrEmployee;

    private LeaveType $leaveType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-07-15 10:00:00'));

        $this->tenant = Tenant::create([
            'slug' => 'alpha',
            'name' => 'Alpha',
            'initials' => 'AL',
        ]);

        $this->hrUser = User::create([
            'name' => 'HR Manager',
            'email' => 'hr@example.com',
            'password' => Hash::make('password'),
        ]);

        $this->hrUser->tenants()->attach($this->tenant->id, ['role' => 'hr']);

        $this->hrEmployee = Employee::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->hrUser->id,
            'name' => 'HR Manager',
            'status' => 'active',
            'workload' => 'green',
        ]);

        $this->leaveType = LeaveType::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Annual Leave',
            'entitlement' => 14,
        ]);
    }

    private function actAsHr(): self
    {
        return $this->actingAs($this->hrUser)->withSession([
            'current_tenant' => $this->tenant->id,
            'persona' => 'hr',
        ]);
    }

    public function test_an_employee_with_no_records_is_named_on_the_screen(): void
    {
        $noRecordEmp = Employee::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Unclocked Staff Member',
            'status' => 'active',
            'workload' => 'green',
        ]);

        $response = $this->actAsHr()->get('/app/attendance-report');

        $response->assertOk();
        $response->assertSee('Unclocked Staff Member');
        $response->assertSee('emp='.$noRecordEmp->id);
    }

    public function test_approved_leave_reads_as_leave_not_absence(): void
    {
        $leaveEmp = Employee::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'On Leave Employee',
            'status' => 'active',
            'workload' => 'green',
        ]);

        LeaveRequest::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $leaveEmp->id,
            'leave_type_id' => $this->leaveType->id,
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-31',
            'days' => 22,
            'status' => 'approved',
        ]);

        $response = $this->actAsHr()->get('/app/attendance-report');

        $response->assertOk();
        $content = $response->getContent();

        $response->assertSee('Approved leave');

        $this->assertStringContainsString('On Leave Employee', $content);
        $rowHtml = strstr($content, 'On Leave Employee');
        $rowHtml = substr($rowHtml, 0, strpos($rowHtml, '</a>') ?: strlen($rowHtml));

        $this->assertStringContainsString('Approved leave', $rowHtml);
        $this->assertStringNotContainsString('No record in', $rowHtml);
    }

    public function test_every_row_renders_one_cell_per_working_day(): void
    {
        $emp1 = Employee::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Employee Alpha',
            'status' => 'active',
            'workload' => 'green',
        ]);

        $emp2 = Employee::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Employee Beta',
            'status' => 'active',
            'workload' => 'green',
        ]);

        AttendanceRecord::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $emp1->id,
            'date' => '2026-07-01',
            'status' => 'on_time',
            'clock_in' => '08:00:00',
        ]);

        $response = $this->actAsHr()->get('/app/attendance-report');
        $response->assertOk();

        $days = $response->viewData('days');
        $expectedCellCount = count($days) * 3;

        $content = $response->getContent();
        $actualCellCount = substr_count($content, 'class="uj-ar-cell"');

        $this->assertSame($expectedCellCount, $actualCellCount);
    }

    public function test_a_plain_employee_cannot_reach_the_report(): void
    {
        $plainUser = User::create([
            'name' => 'Plain Employee User',
            'email' => 'plain@example.com',
            'password' => Hash::make('password'),
        ]);
        $plainUser->tenants()->attach($this->tenant->id, ['role' => 'employee']);

        Employee::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $plainUser->id,
            'name' => 'Plain Employee',
            'status' => 'active',
            'workload' => 'green',
        ]);

        $response = $this->actingAs($plainUser)
            ->withSession([
                'current_tenant' => $this->tenant->id,
                'persona' => 'employee',
            ])
            ->get('/app/attendance-report');

        $this->assertNotEquals(200, $response->getStatusCode());
    }

    public function test_the_never_clocked_sentence_names_at_most_three(): void
    {
        $names = [
            'Alpha NeverName',
            'Beta NeverName',
            'Gamma NeverName',
            'Delta NeverName',
            'Epsilon NeverName',
            'Zeta NeverName',
        ];

        foreach ($names as $name) {
            Employee::create([
                'tenant_id' => $this->tenant->id,
                'name' => $name,
                'status' => 'active',
                'workload' => 'green',
            ]);
        }

        $response = $this->actAsHr()->get('/app/attendance-report');
        $response->assertOk();

        $content = $response->getContent();

        // Scope assertion strictly to the shelf lead sentence HTML block
        $shelfStart = strpos($content, 'class="uj-ar-figsub"');
        $this->assertNotFalse($shelfStart, 'Shelf figsub element must exist.');
        $shelfEnd = strpos($content, 'class="uj-ar-cov"', $shelfStart);
        $this->assertNotFalse($shelfEnd, 'Shelf coverage bar element must exist.');
        $shelfHtml = substr($content, $shelfStart, $shelfEnd - $shelfStart);

        // At most 3 names listed (HR Manager from setUp + Alpha + Beta)
        $this->assertStringContainsString('HR Manager', $shelfHtml);
        $this->assertStringContainsString('Alpha NeverName', $shelfHtml);
        $this->assertStringContainsString('Beta NeverName', $shelfHtml);

        // Overflow wording present (6 seeded + 1 HR Manager from setUp = 7 never clocked; 7 - 3 = 4 others)
        $this->assertStringContainsString('and 4 others', $shelfHtml);

        // Fourth and subsequent names are omitted from the shelf sentence
        $this->assertStringNotContainsString('Gamma NeverName', $shelfHtml);
        $this->assertStringNotContainsString('Delta NeverName', $shelfHtml);
        $this->assertStringNotContainsString('Epsilon NeverName', $shelfHtml);
        $this->assertStringNotContainsString('Zeta NeverName', $shelfHtml);
    }

    // --- Reverse punch button on the drill-down ------------------------------

    private function punchedEmployee(): Employee
    {
        $e = Employee::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Punched Staff',
            'status' => 'active',
            'workload' => 'green',
        ]);
        AttendanceRecord::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $e->id,
            // A day inside the window but short of its exact end boundary — the window's
            // upper edge is unreliable with SQLite's raw string date storage (matches every
            // other date literal in this file), not something this test is about.
            'date' => '2026-07-14',
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
        ]);

        return $e;
    }

    public function test_hr_sees_a_reverse_button_on_the_drill_down(): void
    {
        $e = $this->punchedEmployee();

        $this->actAsHr()->get('/app/attendance-report?emp='.$e->id)
            ->assertOk()
            ->assertSee('attendance-admin/records/', false)
            ->assertSee('Reverse out');
    }

    public function test_a_manager_does_not_see_a_reverse_button_on_the_drill_down(): void
    {
        $managerUser = User::create([
            'name' => 'Line Manager',
            'email' => 'manager@example.com',
            'password' => Hash::make('password'),
        ]);
        $managerUser->tenants()->attach($this->tenant->id, ['role' => 'manager']);
        $managerEmployee = Employee::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $managerUser->id,
            'name' => 'Line Manager',
            'status' => 'active',
            'workload' => 'green',
        ]);
        $e = $this->punchedEmployee();
        $e->update(['reports_to_id' => $managerEmployee->id]);

        $response = $this->actingAs($managerUser)
            ->withSession(['current_tenant' => $this->tenant->id, 'persona' => 'manager'])
            ->get('/app/attendance-report?emp='.$e->id);

        $response->assertOk();
        $response->assertDontSee('attendance-admin/records/', false);
    }
}
