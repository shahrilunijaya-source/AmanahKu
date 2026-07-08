<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Reporting line (reports_to_id) write path on the profile edit form — the single
 * link the org chart is built from. Covers the happy path, self-reference and
 * cycle guards, clearing, and the resulting org-chart tree depth.
 */
class ReportingLineTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
    }

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

    private function staff(string $name, ?int $reportsToId = null, ?int $departmentId = null): Employee
    {
        return Employee::create([
            'tenant_id' => $this->tenant->id,
            'name' => $name, 'status' => 'active', 'workload' => 'green',
            'reports_to_id' => $reportsToId,
            'department_id' => $departmentId,
        ]);
    }

    /** Minimal valid profile-edit payload (name + status are required). */
    private function payload(Employee $e, array $overrides = []): array
    {
        return array_merge(['name' => $e->name, 'status' => $e->status], $overrides);
    }

    public function test_hr_can_set_a_reporting_line(): void
    {
        $this->actingAsRole('hr');
        $manager = $this->staff('Aisyah Rahman');
        $report = $this->staff('Nurul Iman');

        $this->post("/app/employees/{$report->id}", $this->payload($report, ['reports_to_id' => $manager->id]))
            ->assertRedirect()
            ->assertSessionHas('ok');

        $this->assertSame($manager->id, $report->fresh()->reports_to_id);
    }

    public function test_reporting_line_can_be_cleared(): void
    {
        $this->actingAsRole('hr');
        $manager = $this->staff('Aisyah Rahman');
        $report = $this->staff('Nurul Iman', $manager->id);

        // An empty <select> posts '' — it must clear back to null, not fail validation.
        $this->post("/app/employees/{$report->id}", $this->payload($report, ['reports_to_id' => '']))
            ->assertRedirect()
            ->assertSessionHas('ok');

        $this->assertNull($report->fresh()->reports_to_id);
    }

    public function test_a_person_cannot_report_to_themselves(): void
    {
        $this->actingAsRole('hr');
        $report = $this->staff('Nurul Iman');

        $this->post("/app/employees/{$report->id}", $this->payload($report, ['reports_to_id' => $report->id]))
            ->assertSessionHasErrors('reports_to_id');

        $this->assertNull($report->fresh()->reports_to_id);
    }

    public function test_a_reporting_loop_is_rejected(): void
    {
        $this->actingAsRole('hr');
        // Aisyah already reports to Nurul; pointing Nurul at Aisyah would close the loop.
        $nurul = $this->staff('Nurul Iman');
        $aisyah = $this->staff('Aisyah Rahman', $nurul->id);

        $this->post("/app/employees/{$nurul->id}", $this->payload($nurul, ['reports_to_id' => $aisyah->id]))
            ->assertSessionHasErrors('reports_to_id');

        $this->assertNull($nurul->fresh()->reports_to_id);
    }

    public function test_a_manager_outside_the_tenant_is_rejected(): void
    {
        $this->actingAsRole('hr');
        $report = $this->staff('Nurul Iman');

        $other = Tenant::create(['slug' => 'other', 'name' => 'Other', 'initials' => 'OT']);
        $foreign = Employee::create([
            'tenant_id' => $other->id, 'name' => 'Outsider', 'status' => 'active', 'workload' => 'green',
        ]);

        $this->post("/app/employees/{$report->id}", $this->payload($report, ['reports_to_id' => $foreign->id]))
            ->assertSessionHasErrors('reports_to_id');

        $this->assertNull($report->fresh()->reports_to_id);
    }

    public function test_setting_a_reporting_line_deepens_the_org_chart(): void
    {
        $this->actingAsRole('hr');
        $manager = $this->staff('Aisyah Rahman');
        $report = $this->staff('Nurul Iman');

        // Before any link every person is a flat root — depth 1.
        $this->get('/app/orgchart')->assertOk()->assertSee('Reporting depth');

        $this->post("/app/employees/{$report->id}", $this->payload($report, ['reports_to_id' => $manager->id]))
            ->assertRedirect();

        // After the link the chart nests the report under the manager.
        $html = $this->get('/app/orgchart')->assertOk()->getContent();
        $this->assertStringContainsString('Aisyah Rahman', $html);
        $this->assertStringContainsString('Nurul Iman', $html);
    }

    // --- CSV import reports_to column ---------------------------------------

    private function importCsv(string $csv): TestResponse
    {
        $file = UploadedFile::fake()->createWithContent('staff.csv', $csv);

        return $this->post('/app/employees/import', ['file' => $file]);
    }

    public function test_csv_import_links_manager_named_in_an_earlier_row(): void
    {
        $this->actingAsRole('hr');

        $this->importCsv("name,status,reports_to\nAisyah Rahman,active,\nNurul Iman,active,Aisyah Rahman\n")
            ->assertRedirect();

        $manager = Employee::where('name', 'Aisyah Rahman')->firstOrFail();
        $report = Employee::where('name', 'Nurul Iman')->firstOrFail();
        $this->assertSame($manager->id, $report->reports_to_id);
        $this->assertNull($manager->reports_to_id);
    }

    public function test_csv_import_links_manager_named_in_a_later_row(): void
    {
        $this->actingAsRole('hr');

        // The report appears BEFORE its manager — only the second pass can resolve this.
        $this->importCsv("name,status,reports_to\nNurul Iman,active,Aisyah Rahman\nAisyah Rahman,active,\n")
            ->assertRedirect();

        $manager = Employee::where('name', 'Aisyah Rahman')->firstOrFail();
        $report = Employee::where('name', 'Nurul Iman')->firstOrFail();
        $this->assertSame($manager->id, $report->reports_to_id);
    }

    public function test_csv_import_skips_an_unknown_manager_name_without_failing_the_row(): void
    {
        $this->actingAsRole('hr');

        $this->importCsv("name,status,reports_to\nNurul Iman,active,Nobody Here\n")
            ->assertRedirect();

        $report = Employee::where('name', 'Nurul Iman')->firstOrFail();
        $this->assertNull($report->reports_to_id);
    }

    // --- Bulk org-chart editor ----------------------------------------------

    public function test_hr_can_bulk_set_reporting_lines(): void
    {
        $this->actingAsRole('hr');
        $manager = $this->staff('Aisyah Rahman');
        $a = $this->staff('Nurul Iman');
        $b = $this->staff('Ali Bin');

        $this->post(route('org.reporting-lines'), ['manager' => [
            $a->id => $manager->id,
            $b->id => $manager->id,
        ]])->assertRedirect()->assertSessionHas('ok');

        $this->assertSame($manager->id, $a->fresh()->reports_to_id);
        $this->assertSame($manager->id, $b->fresh()->reports_to_id);
    }

    public function test_bulk_editor_rejects_a_cycle_and_saves_nothing(): void
    {
        $this->actingAsRole('hr');
        $a = $this->staff('Aisyah Rahman');
        $b = $this->staff('Nurul Iman');

        // a → b and b → a at once is a loop; the whole submission must be rejected.
        $this->post(route('org.reporting-lines'), ['manager' => [
            $a->id => $b->id,
            $b->id => $a->id,
        ]])->assertRedirect()->assertSessionHas('error');

        $this->assertNull($a->fresh()->reports_to_id);
        $this->assertNull($b->fresh()->reports_to_id);
    }

    public function test_bulk_editor_ignores_a_self_reference(): void
    {
        $this->actingAsRole('hr');
        $a = $this->staff('Aisyah Rahman');

        $this->post(route('org.reporting-lines'), ['manager' => [$a->id => $a->id]])
            ->assertRedirect();

        $this->assertNull($a->fresh()->reports_to_id);
    }

    public function test_a_plain_employee_cannot_bulk_edit_reporting_lines(): void
    {
        $this->actingAsRole('employee');
        $a = $this->staff('Aisyah Rahman');
        $b = $this->staff('Nurul Iman');

        $this->post(route('org.reporting-lines'), ['manager' => [$b->id => $a->id]])
            ->assertForbidden();

        $this->assertNull($b->fresh()->reports_to_id);
    }

    public function test_org_chart_editor_is_visible_to_hr_only(): void
    {
        $this->staff('Aisyah Rahman');

        // Both the drag toggle and the list editor only render for HR / management.
        $this->actingAsRole('hr')->get('/app/orgchart')->assertOk()->assertSee('Edit as list')->assertSee('orgChart()');
    }

    public function test_org_chart_editor_is_hidden_from_plain_employees(): void
    {
        $this->staff('Aisyah Rahman');

        $this->actingAsRole('employee')->get('/app/orgchart')->assertOk()
            ->assertDontSee('Edit as list')
            ->assertDontSee('orgChart()');
    }

    // --- Drag-and-drop single move (org.move) -------------------------------

    public function test_drag_move_sets_a_manager(): void
    {
        $this->actingAsRole('hr');
        $manager = $this->staff('Aisyah Rahman');
        $report = $this->staff('Nurul Iman');

        $this->postJson(route('org.move'), ['employee_id' => $report->id, 'manager_id' => $manager->id])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertSame($manager->id, $report->fresh()->reports_to_id);
    }

    public function test_drag_to_top_level_clears_the_manager(): void
    {
        $this->actingAsRole('hr');
        $manager = $this->staff('Aisyah Rahman');
        $report = $this->staff('Nurul Iman', $manager->id);

        $this->postJson(route('org.move'), ['employee_id' => $report->id, 'manager_id' => null])
            ->assertOk();

        $this->assertNull($report->fresh()->reports_to_id);
    }

    public function test_drag_move_rejects_a_self_reference(): void
    {
        $this->actingAsRole('hr');
        $report = $this->staff('Nurul Iman');

        $this->postJson(route('org.move'), ['employee_id' => $report->id, 'manager_id' => $report->id])
            ->assertStatus(422);

        $this->assertNull($report->fresh()->reports_to_id);
    }

    public function test_drag_move_rejects_a_loop(): void
    {
        $this->actingAsRole('hr');
        // Aisyah already reports to Nurul; moving Nurul under Aisyah closes the loop.
        $nurul = $this->staff('Nurul Iman');
        $aisyah = $this->staff('Aisyah Rahman', $nurul->id);

        $this->postJson(route('org.move'), ['employee_id' => $nurul->id, 'manager_id' => $aisyah->id])
            ->assertStatus(422);

        $this->assertNull($nurul->fresh()->reports_to_id);
    }

    public function test_drag_move_rejects_a_manager_from_another_tenant(): void
    {
        $this->actingAsRole('hr');
        $report = $this->staff('Nurul Iman');

        $other = Tenant::create(['slug' => 'other', 'name' => 'Other', 'initials' => 'OT']);
        $foreign = Employee::create([
            'tenant_id' => $other->id, 'name' => 'Outsider', 'status' => 'active', 'workload' => 'green',
        ]);

        $this->postJson(route('org.move'), ['employee_id' => $report->id, 'manager_id' => $foreign->id])
            ->assertStatus(422);

        $this->assertNull($report->fresh()->reports_to_id);
    }

    public function test_a_plain_employee_cannot_drag_move(): void
    {
        $this->actingAsRole('employee');
        $manager = $this->staff('Aisyah Rahman');
        $report = $this->staff('Nurul Iman');

        $this->postJson(route('org.move'), ['employee_id' => $report->id, 'manager_id' => $manager->id])
            ->assertStatus(403);

        $this->assertNull($report->fresh()->reports_to_id);
    }

    // --- Department lens (?dept=) -------------------------------------------

    public function test_org_chart_filters_to_a_single_department(): void
    {
        $admin = Department::create(['tenant_id' => $this->tenant->id, 'name' => 'Administration']);
        $ops = Department::create(['tenant_id' => $this->tenant->id, 'name' => 'Operation']);
        $this->staff('Aisyah Admin', null, $admin->id);
        $this->staff('Omar Ops', null, $ops->id);

        // View as a plain employee so the global list editor (HR only) can't leak names.
        $this->actingAsRole('employee');

        $this->get('/app/orgchart?dept=Administration')->assertOk()
            ->assertSee('Aisyah Admin')
            ->assertDontSee('Omar Ops');
    }

    public function test_org_chart_without_a_department_shows_everyone(): void
    {
        $admin = Department::create(['tenant_id' => $this->tenant->id, 'name' => 'Administration']);
        $ops = Department::create(['tenant_id' => $this->tenant->id, 'name' => 'Operation']);
        $this->staff('Aisyah Admin', null, $admin->id);
        $this->staff('Omar Ops', null, $ops->id);

        $this->actingAsRole('employee');

        $this->get('/app/orgchart')->assertOk()
            ->assertSee('Aisyah Admin')
            ->assertSee('Omar Ops');
    }

    public function test_an_unknown_department_filter_falls_back_to_everyone(): void
    {
        $admin = Department::create(['tenant_id' => $this->tenant->id, 'name' => 'Administration']);
        $ops = Department::create(['tenant_id' => $this->tenant->id, 'name' => 'Operation']);
        $this->staff('Aisyah Admin', null, $admin->id);
        $this->staff('Omar Ops', null, $ops->id);

        $this->actingAsRole('employee');

        $this->get('/app/orgchart?dept=Nonexistent')->assertOk()
            ->assertSee('Aisyah Admin')
            ->assertSee('Omar Ops');
    }
}
