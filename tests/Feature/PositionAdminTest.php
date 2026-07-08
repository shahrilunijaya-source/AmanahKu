<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\StaffLevel;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Position rate-card admin: privileged (HR/management) CRUD + assignment, with
 * role gating and tenant isolation. Harness mirrors TimesheetTest.
 */
class PositionAdminTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Employee $employee;

    private Department $department;

    private StaffLevel $staffLevel;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $this->employee = Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Staff', 'status' => 'active', 'workload' => 'green',
        ]);
        $this->department = Department::create(['tenant_id' => $this->tenant->id, 'name' => 'Operation']);
        $this->staffLevel = StaffLevel::create(['tenant_id' => $this->tenant->id, 'name' => 'Manager']);
    }

    /** A user+employee in the tenant with the given pivot role. */
    private function actor(string $role): User
    {
        $this->seq++;
        $user = User::create(['name' => $role, 'email' => "{$role}{$this->seq}@example.com", 'password' => Hash::make('password')]);
        $user->tenants()->attach($this->tenant->id, ['role' => $role]);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'name' => ucfirst($role), 'status' => 'active', 'workload' => 'green',
        ]);

        return $user;
    }

    private function actingAsRole(string $role): self
    {
        $this->actingAs($this->actor($role))->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    private function positionPayload(array $overrides = []): array
    {
        return array_merge([
            'department_id' => $this->department->id, 'staff_level_id' => $this->staffLevel->id,
            'title' => 'Project Manager', 'max_salary' => 10000,
        ], $overrides);
    }

    public function test_hr_can_create_a_position(): void
    {
        $this->actingAsRole('hr')->post('/app/position', $this->positionPayload())->assertRedirect();

        $position = Position::where('title', 'Project Manager')->first();
        $this->assertNotNull($position);
        $this->assertSame($this->tenant->id, $position->tenant_id);
        // (10000 * 1.8) / 20 = 900/day.
        $this->assertSame(900.0, $position->mandayRate());
    }

    public function test_hr_can_assign_a_position_to_an_employee(): void
    {
        $this->actingAsRole('hr');
        $position = Position::create($this->positionPayload(['tenant_id' => $this->tenant->id]));

        $this->post("/app/position/assign/{$this->employee->id}", ['position_id' => $position->id])->assertRedirect();

        $this->assertSame($position->id, $this->employee->fresh()->position_id);
    }

    public function test_assigning_via_ajax_returns_json(): void
    {
        $this->actingAsRole('hr');
        $position = Position::create($this->positionPayload(['tenant_id' => $this->tenant->id]));

        $this->postJson("/app/position/assign/{$this->employee->id}", ['position_id' => $position->id])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertSame($position->id, $this->employee->fresh()->position_id);
    }

    public function test_deleting_a_position_unassigns_its_staff(): void
    {
        $this->actingAsRole('hr');
        $position = Position::create($this->positionPayload(['tenant_id' => $this->tenant->id]));
        $this->employee->update(['position_id' => $position->id]);

        $this->post("/app/position/{$position->id}/delete")->assertRedirect();

        $this->assertNull($this->employee->fresh()->position_id);
        $this->assertNull(Position::find($position->id));
    }

    public function test_plain_employee_cannot_create_a_position(): void
    {
        $this->actingAsRole('employee')->post('/app/position', $this->positionPayload())->assertForbidden();
        $this->assertSame(0, Position::count());
    }

    public function test_line_manager_cannot_create_a_position(): void
    {
        $this->actingAsRole('manager')->post('/app/position', $this->positionPayload())->assertForbidden();
        $this->assertSame(0, Position::count());
    }

    public function test_hr_can_download_the_import_template(): void
    {
        $res = $this->actingAsRole('hr')->get('/app/position/import-template');

        $res->assertOk();
        $res->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('department,level,title', $res->getContent());
    }

    public function test_hr_can_bulk_import_positions_creating_missing_lookups(): void
    {
        $csv = "department,level,title,code,max_salary,default_role,is_managerial,sort,description,status\n"
            ."Operation,Manager,Project Manager,PM-09,12000,manager,yes,1,Leads,active\n"
            ."Finance,Director,Finance Head,FH-01,20000,management,yes,0,,active\n";
        $file = UploadedFile::fake()->createWithContent('positions.csv', $csv);

        $this->actingAsRole('hr')->post('/app/position/import', ['file' => $file])->assertRedirect();

        // Both bands created; the existing Operation/Manager lookups are reused.
        $this->assertSame(2, Position::count());
        $pm = Position::where('title', 'Project Manager')->first();
        $this->assertSame($this->department->id, $pm->department_id);
        $this->assertSame($this->staffLevel->id, $pm->staff_level_id);
        $this->assertSame(12000.0, $pm->mandayRate() * 20 / 1.8); // round-trips the max salary

        // Finance + Director did not exist — the import created them in this tenant.
        $finance = Position::where('title', 'Finance Head')->first();
        $this->assertSame('Finance', $finance->department->name);
        $this->assertSame('Director', $finance->staffLevel->name);
        $this->assertTrue($finance->is_managerial);
    }

    public function test_import_ties_to_existing_department_and_level_ignoring_casing(): void
    {
        // CSV uses different casing/whitespace than the seeded 'Operation' / 'Manager'.
        $csv = "department,level,title,max_salary\n"
            ." operation ,MANAGER,Ops Lead,9000\n";
        $file = UploadedFile::fake()->createWithContent('positions.csv', $csv);

        $this->actingAsRole('hr')->post('/app/position/import', ['file' => $file])->assertRedirect();

        // No duplicate lookups spawned — the band ties to the existing records.
        $this->assertSame(1, Department::count());
        $this->assertSame(1, StaffLevel::count());
        $band = Position::where('title', 'Ops Lead')->first();
        $this->assertSame($this->department->id, $band->department_id);
        $this->assertSame($this->staffLevel->id, $band->staff_level_id);
    }

    public function test_import_skips_rows_with_a_duplicate_code(): void
    {
        Position::create($this->positionPayload(['tenant_id' => $this->tenant->id, 'title' => 'Existing', 'code' => 'DUP-1']));

        $csv = "department,level,title,code,max_salary\n"
            ."Operation,Manager,New One,DUP-1,9000\n";
        $file = UploadedFile::fake()->createWithContent('positions.csv', $csv);

        $this->actingAsRole('hr')->post('/app/position/import', ['file' => $file])
            ->assertRedirect()
            ->assertSessionHas('error');

        // The duplicate-code row was rejected, so only the pre-existing band remains.
        $this->assertSame(1, Position::count());
        $this->assertNull(Position::where('title', 'New One')->first());
    }

    public function test_plain_employee_cannot_import_positions(): void
    {
        $file = UploadedFile::fake()->createWithContent('positions.csv', "department,level,title,max_salary\nOperation,Manager,X,5000\n");

        $this->actingAsRole('employee')->post('/app/position/import', ['file' => $file])->assertForbidden();
        $this->assertSame(0, Position::count());
    }

    public function test_hr_cannot_touch_another_tenants_position(): void
    {
        // Arrange — a position that belongs to a different tenant.
        $other = Tenant::create(['slug' => 'other', 'name' => 'Other', 'initials' => 'OT']);
        $otherDept = Department::create(['tenant_id' => $other->id, 'name' => 'Operation']);
        $otherLevel = StaffLevel::create(['tenant_id' => $other->id, 'name' => 'Manager']);
        $foreign = Position::create($this->positionPayload([
            'tenant_id' => $other->id, 'title' => 'Foreign PM',
            'department_id' => $otherDept->id, 'staff_level_id' => $otherLevel->id,
        ]));

        // Act — our HR tries to edit it. assertTenant() in the controller blocks the
        // cross-tenant write (403); the row is never mutated.
        $this->actingAsRole('hr')
            ->post("/app/position/{$foreign->id}", $this->positionPayload(['title' => 'Hijacked']))
            ->assertForbidden();

        // Assert — untouched.
        $this->assertSame('Foreign PM', $foreign->fresh()->title);
    }
}
