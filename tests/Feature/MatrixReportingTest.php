<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Position;
use App\Models\Tenant;
use App\Models\User;
use App\Support\StuckRequests;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Matrix reporting + directors.
 *
 * A person may answer to a primary superior (reports_to_id) AND additional (dotted-line)
 * managers. Either kind may VERIFY that person's leave/claim/overtime — the "either manager
 * verifies" rule. Directors are pinned to the top of the chart with a badge via is_director.
 */
class MatrixReportingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private LeaveType $type;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $this->type = LeaveType::create(['tenant_id' => $this->tenant->id, 'name' => 'Annual', 'entitlement' => 18]);
    }

    private function emp(string $name, string $role = 'employee', ?int $reportsToId = null): Employee
    {
        $this->seq++;
        $user = User::create(['name' => $name, 'email' => "user{$this->seq}@example.com", 'password' => Hash::make('password')]);
        $user->tenants()->attach($this->tenant->id, ['role' => $role]);

        return Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'name' => $name, 'status' => 'active', 'workload' => 'green',
            'reports_to_id' => $reportsToId,
        ]);
    }

    private function actingAsEmployee(Employee $e): self
    {
        $this->actingAs($e->user)->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    private function submittedLeave(Employee $employee): LeaveRequest
    {
        return LeaveRequest::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $employee->id,
            'leave_type_id' => $this->type->id, 'date_from' => '2026-07-01', 'date_to' => '2026-07-02',
            'days' => 2, 'status' => 'submitted',
        ]);
    }

    // --- Either manager verifies -------------------------------------------

    public function test_an_additional_manager_can_verify(): void
    {
        $primary = $this->emp('Primary Boss', 'manager');
        $extra = $this->emp('Second Boss', 'manager');
        $report = $this->emp('Reportee', 'employee', $primary->id);
        $report->additionalManagers()->attach($extra->id);
        $req = $this->submittedLeave($report);

        $this->actingAsEmployee($extra)->post("/app/leave/{$req->id}/verify")
            ->assertRedirect()->assertSessionHas('ok');

        $fresh = $req->fresh();
        $this->assertSame('verified', $fresh->status);
        $this->assertSame($extra->id, $fresh->verified_by_id);
    }

    public function test_the_primary_superior_still_verifies_when_extra_managers_exist(): void
    {
        $primary = $this->emp('Primary Boss', 'manager');
        $extra = $this->emp('Second Boss', 'manager');
        $report = $this->emp('Reportee', 'employee', $primary->id);
        $report->additionalManagers()->attach($extra->id);
        $req = $this->submittedLeave($report);

        $this->actingAsEmployee($primary)->post("/app/leave/{$req->id}/verify")
            ->assertRedirect()->assertSessionHas('ok');

        $this->assertSame('verified', $req->fresh()->status);
    }

    public function test_someone_who_is_no_kind_of_manager_cannot_verify(): void
    {
        $primary = $this->emp('Primary Boss', 'manager');
        $extra = $this->emp('Second Boss', 'manager');
        $stranger = $this->emp('Stranger', 'manager');
        $report = $this->emp('Reportee', 'employee', $primary->id);
        $report->additionalManagers()->attach($extra->id);
        $req = $this->submittedLeave($report);

        $this->actingAsEmployee($stranger)->post("/app/leave/{$req->id}/verify")->assertForbidden();
        $this->assertSame('submitted', $req->fresh()->status);
    }

    public function test_an_additional_manager_sees_the_request_in_their_verify_queue(): void
    {
        $primary = $this->emp('Primary Boss', 'manager');
        $extra = $this->emp('Second Boss', 'manager');
        $mine = $this->emp('Dotted Reportee', 'employee', $primary->id);
        $mine->additionalManagers()->attach($extra->id);
        $stranger = $this->emp('Stranger Person', 'employee');
        $this->submittedLeave($mine);
        $this->submittedLeave($stranger);

        $this->actingAsEmployee($extra)->get('/app/leave')->assertOk()
            ->assertSee('To verify')
            ->assertSee('Dotted Reportee')
            ->assertDontSee('Stranger Person');
    }

    public function test_submitting_notifies_both_primary_and_additional_managers(): void
    {
        $primary = $this->emp('Primary Boss', 'manager');
        $extra = $this->emp('Second Boss', 'manager');
        $report = $this->emp('Reportee', 'employee', $primary->id);
        $report->additionalManagers()->attach($extra->id);

        $this->actingAsEmployee($report)->post('/app/leave', [
            'leave_type_id' => $this->type->id, 'date_from' => '2026-07-10', 'date_to' => '2026-07-11',
        ])->assertRedirect();

        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $primary->user_id, 'title' => 'Leave awaiting your verification',
        ]);
        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $extra->user_id, 'title' => 'Leave awaiting your verification',
        ]);
    }

    // --- Stuck requests -----------------------------------------------------

    public function test_a_person_with_only_an_additional_manager_is_not_stuck(): void
    {
        app(CurrentTenant::class)->set($this->tenant);

        $extra = $this->emp('Second Boss', 'manager');
        $report = $this->emp('No Primary', 'employee'); // reports_to_id null
        $report->additionalManagers()->attach($extra->id);
        $this->submittedLeave($report);

        // An additional manager can verify, so this is routed — not stuck.
        $this->assertCount(0, app(StuckRequests::class)->forCurrentTenant());
    }

    public function test_a_person_with_no_manager_of_any_kind_is_still_stuck(): void
    {
        app(CurrentTenant::class)->set($this->tenant);

        $orphan = $this->emp('Orphan', 'employee'); // no primary, no extras
        $this->submittedLeave($orphan);

        $this->assertCount(1, app(StuckRequests::class)->forCurrentTenant());
    }

    // --- Org-chart editor: extra managers + director ------------------------

    public function test_bulk_editor_saves_extra_managers_and_excludes_self_and_primary(): void
    {
        $hr = $this->emp('HR Admin', 'hr');
        $primary = $this->emp('Primary Boss', 'manager');
        $extra = $this->emp('Second Boss', 'manager');
        $report = $this->emp('Reportee', 'employee');

        $this->actingAsEmployee($hr)->post(route('org.reporting-lines'), [
            'manager' => [$report->id => $primary->id],
            'extra_managers' => [$report->id => [$extra->id, $report->id, $primary->id]],
        ])->assertRedirect()->assertSessionHas('ok');

        // Self ($report) and the primary ($primary, already verifies) are dropped; only $extra remains.
        $this->assertSame([$extra->id], $report->fresh()->additionalManagers->modelKeys());
        $this->assertSame($primary->id, $report->fresh()->reports_to_id);
    }

    public function test_a_plain_employee_cannot_save_extra_managers(): void
    {
        $plain = $this->emp('Plain', 'employee');
        $report = $this->emp('Reportee', 'employee');
        $extra = $this->emp('Second Boss', 'manager');

        $this->actingAsEmployee($plain)->post(route('org.reporting-lines'), [
            'manager' => [$report->id => ''],
            'extra_managers' => [$report->id => [$extra->id]],
        ])->assertForbidden();

        $this->assertCount(0, $report->fresh()->additionalManagers);
    }

    // --- Director badge in the chart ----------------------------------------

    public function test_director_shows_a_badge_in_the_chart(): void
    {
        $viewer = $this->emp('Viewer', 'employee');
        $this->emp('Aisyah Rahman', 'director');

        // Viewer is a plain employee, so no list editor renders — the only "Director" on the
        // page is the badge on Aisyah's band card.
        $this->actingAsEmployee($viewer)->get('/app/orgchart')->assertOk()
            ->assertSee('Aisyah Rahman')
            ->assertSee('Director');
    }

    public function test_a_director_with_no_reports_still_appears_in_the_top_band(): void
    {
        // The whole point: a director nobody reports to (Suandy) still sits up top, in the
        // Directors band, level with directors who do have teams (Shahril).
        $viewer = $this->emp('Viewer', 'employee');
        $this->emp('Suandy Solo', 'director');

        $this->actingAsEmployee($viewer)->get('/app/orgchart')->assertOk()
            ->assertSee('Directors')
            ->assertSee('Suandy Solo');
    }

    public function test_a_director_is_not_duplicated_when_they_have_reports(): void
    {
        $viewer = $this->emp('Viewer', 'employee');
        $director = $this->emp('Solo Director', 'director');
        $this->emp('Their Reportee', 'employee', $director->id);

        $html = $this->actingAsEmployee($viewer)->get('/app/orgchart')->assertOk()->getContent();

        // Director is drawn once (in the band), with their report nested beneath — never a
        // second time as a plain root of the tree.
        $this->assertSame(1, substr_count($html, 'Solo Director'));
        $this->assertStringContainsString('Their Reportee', $html);
    }

    public function test_directors_band_persists_in_a_department_lens(): void
    {
        // Directors are company-wide leadership, above every department. Filtering the chart
        // to one department must NOT drop a director who belongs to another.
        $admin = Department::create(['tenant_id' => $this->tenant->id, 'name' => 'Administration']);
        $ops = Department::create(['tenant_id' => $this->tenant->id, 'name' => 'Operation']);

        $viewer = $this->emp('Viewer', 'employee');
        $director = $this->emp('Admin Director', 'director');
        $director->update(['department_id' => $admin->id]);
        $opsStaff = $this->emp('Ops Person', 'employee');
        $opsStaff->update(['department_id' => $ops->id]);

        // Operation lens: the Administration director still appears in the band up top.
        $this->actingAsEmployee($viewer)->get('/app/orgchart?dept=Operation')->assertOk()
            ->assertSee('Directors')
            ->assertSee('Admin Director')
            ->assertSee('Ops Person');
    }

    // --- Director by position (rank band) ----------------------------------

    public function test_a_director_flagged_position_pins_its_holder_to_the_band(): void
    {
        // Being a director is a STAFF attribute here: a position (rank band) flagged as a
        // Director band pins its holder to the top band with NO tenant `director` role.
        $ops = Department::create(['tenant_id' => $this->tenant->id, 'name' => 'Operation']);
        $band = Position::create([
            'tenant_id' => $this->tenant->id, 'department_id' => $ops->id,
            'title' => 'Operation Director', 'max_salary' => 0, 'is_director' => true,
        ]);

        $viewer = $this->emp('Viewer', 'employee');
        $boss = $this->emp('Ops Boss', 'employee'); // plain login role — the pin is the position
        $boss->update(['position_id' => $band->id]);
        $this->emp('Line Staff', 'employee', $boss->id);

        $html = $this->actingAsEmployee($viewer)->get('/app/orgchart')->assertOk()->getContent();

        // In the band up top, drawn once (never also nested as a tree root), with their report
        // surfaced beneath in the tree.
        $this->assertStringContainsString('Directors', $html);
        $this->assertSame(1, substr_count($html, 'Ops Boss'));
        $this->assertStringContainsString('Line Staff', $html);
    }

    public function test_a_directory_only_director_with_no_login_still_pins(): void
    {
        // "It only applies to staff": a director who is a directory record with no login
        // account must still land in the band — the position flag, not a user role, decides.
        $band = Position::create([
            'tenant_id' => $this->tenant->id,
            'title' => 'Managing Director', 'max_salary' => 0, 'is_director' => true,
        ]);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => null,
            'name' => 'No Login Director', 'status' => 'active', 'workload' => 'green',
            'position_id' => $band->id,
        ]);

        $viewer = $this->emp('Viewer', 'employee');
        $this->actingAsEmployee($viewer)->get('/app/orgchart')->assertOk()
            ->assertSee('Directors')
            ->assertSee('No Login Director');
    }

    // --- Director role: authority ------------------------------------------

    public function test_a_director_gives_final_approval(): void
    {
        // Director carries management-tier approval authority — a verified request is theirs
        // to approve, exactly like management.
        $manager = $this->emp('Manager', 'manager');
        $director = $this->emp('Board Director', 'director');
        $report = $this->emp('Reportee', 'employee', $manager->id);
        LeaveBalance::create(['employee_id' => $report->id, 'leave_type_id' => $this->type->id, 'balance' => 10]);
        $req = LeaveRequest::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $report->id,
            'leave_type_id' => $this->type->id, 'date_from' => '2026-07-01', 'date_to' => '2026-07-02',
            'days' => 2, 'status' => 'verified', 'verified_by_id' => $manager->id,
        ]);

        $this->actingAsEmployee($director)->post("/app/leave/{$req->id}/approve")
            ->assertRedirect()->assertSessionHas('ok');

        $this->assertSame('approved', $req->fresh()->status);
    }

    public function test_a_director_inherits_management_screen_access(): void
    {
        // A management-only screen (Roles & access) must open for a director — they inherit
        // every management gate.
        $director = $this->emp('Board Director', 'director');
        $this->actingAsEmployee($director)->get('/app/roles')->assertOk();

        // ...but a plain employee is still refused, proving the gate is real.
        $plain = $this->emp('Plain', 'employee');
        $this->actingAsEmployee($plain)->get('/app/roles')->assertForbidden();
    }

    public function test_hr_can_assign_the_director_role_from_the_roles_screen(): void
    {
        $hr = $this->emp('HR Admin', 'hr');
        $target = $this->emp('New Director', 'management');

        $this->actingAsEmployee($hr)->post(route('admin.roles.update', $target->user), ['role' => 'director'])
            ->assertRedirect()->assertSessionHas('ok');

        $this->assertSame('director', $target->user->fresh()->roleIn($this->tenant));
    }
}
