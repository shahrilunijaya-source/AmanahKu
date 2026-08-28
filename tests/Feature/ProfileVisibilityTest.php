<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\StaffLevel;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkItem;
use App\Services\FeatureManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The profile screen's visibility gate: ?emp= is reachable in one click from the
 * directory and the header people-search, so canViewFull is the only thing standing
 * between "any signed-in employee" and "any colleague's full record". Covers the
 * slim-card fallback, the money tier, the module-flag gate on section data, and the
 * removed showcase fallback.
 */
class ProfileVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
    }

    /** Signed-in user with an employee record of their own, in the given tenant role. */
    private function actor(string $role, ?StaffLevel $level = null): Employee
    {
        $this->seq++;
        $user = User::create(['name' => $role, 'email' => "{$role}{$this->seq}@example.com", 'password' => Hash::make('password')]);
        $user->tenants()->attach($this->tenant->id, ['role' => $role]);
        $employee = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'name' => ucfirst($role), 'status' => 'active', 'workload' => 'green',
            'staff_level_id' => $level?->id,
        ]);

        $this->actingAs($user)->withSession(['current_tenant' => $this->tenant->id]);

        return $employee;
    }

    /** A colleague with no login of their own. */
    private function staff(string $name, ?int $reportsToId = null, ?StaffLevel $level = null): Employee
    {
        return Employee::create([
            'tenant_id' => $this->tenant->id,
            'name' => $name, 'status' => 'active', 'workload' => 'green',
            'reports_to_id' => $reportsToId,
            'staff_level_id' => $level?->id,
        ]);
    }

    /** A staff level at a given seniority rank (lower = more senior), for pinning rank tests. */
    private function level(string $name, ?int $rank): StaffLevel
    {
        $this->seq++;

        return StaffLevel::create([
            'tenant_id' => $this->tenant->id, 'name' => "{$name} {$this->seq}", 'rank' => $rank,
        ]);
    }

    public function test_plain_employee_opening_a_colleague_gets_a_slim_card(): void
    {
        $this->actor('employee');
        $colleague = $this->staff('Colleague One');

        $response = $this->get("/app/profile?emp={$colleague->id}")->assertOk();

        $response->assertViewHas('canViewFull', false);
        $response->assertViewHas('canSeeMoney', false);
        $response->assertViewHas('canSeeAttendance', false);
        $response->assertSee($colleague->name);
        $response->assertDontSee('Attendance · this month');
        $response->assertDontSee('name="salary"', false);
        // Not a 403 — the deep link must not dead-end.
        $response->assertDontSee('KPI · H1');
        // Slim card leaks nothing (spec §10): no stat row, no workload label, no open-task count.
        $response->assertDontSee('Workload');
        $response->assertDontSee('Open tasks');
        $response->assertDontSee('Annual leave');
        $response->assertDontSee('Work & Tasks');
        $response->assertDontSee('Assigned tasks');
    }

    public function test_manager_opening_a_direct_report_gets_full_profile_without_money(): void
    {
        $manager = $this->actor('manager');
        $report = $this->staff('Direct Report', $manager->id);

        $response = $this->get("/app/profile?emp={$report->id}")->assertOk();

        $response->assertViewHas('canViewFull', true);
        $response->assertViewHas('canSeeMoney', false);
        $response->assertDontSee('name="salary"', false);
        // No Money tab for a manager, even on a direct report.
        $response->assertDontSee('Money</button>', false);
        $response->assertSee('Work & Tasks');
    }

    public function test_work_items_list_only_shows_in_progress_items(): void
    {
        $manager = $this->actor('manager');
        $report = $this->staff('Direct Report', $manager->id);

        WorkItem::create(['tenant_id' => $this->tenant->id, 'employee_id' => $report->id, 'title' => 'Todo item', 'type' => 'task', 'priority' => 'medium', 'status' => 'todo']);
        WorkItem::create(['tenant_id' => $this->tenant->id, 'employee_id' => $report->id, 'title' => 'In progress item', 'type' => 'task', 'priority' => 'medium', 'status' => 'prog']);
        WorkItem::create(['tenant_id' => $this->tenant->id, 'employee_id' => $report->id, 'title' => 'In review item', 'type' => 'task', 'priority' => 'medium', 'status' => 'review']);
        WorkItem::create(['tenant_id' => $this->tenant->id, 'employee_id' => $report->id, 'title' => 'Done item', 'type' => 'task', 'priority' => 'medium', 'status' => 'done']);

        $response = $this->get("/app/profile?emp={$report->id}")->assertOk();

        $response->assertSee('In progress item');
        $response->assertDontSee('Todo item');
        $response->assertDontSee('In review item');
        $response->assertDontSee('Done item');
    }

    public function test_manager_opening_a_non_report_gets_a_slim_card(): void
    {
        // Pin staff levels explicitly (addendum §H): once ranks are backfilled, a
        // Manager-level viewer would outrank an unpinned (null-rank) stranger by
        // accident of empty fixture data. Give the stranger the MORE senior level so this
        // keeps testing the reporting-line boundary, not the rank rule.
        $managerLevel = $this->level('Manager', 3);
        $seniorLevel = $this->level('Director', 1);
        $this->actor('manager', $managerLevel);
        $stranger = $this->staff('Not My Report', null, $seniorLevel);

        $response = $this->get("/app/profile?emp={$stranger->id}")->assertOk();

        $response->assertViewHas('canViewFull', false);
    }

    public function test_hr_opening_anyone_gets_full_profile_including_money(): void
    {
        $this->actor('hr');
        $anyone = $this->staff('Anyone Staff');

        $response = $this->get("/app/profile?emp={$anyone->id}")->assertOk();

        $response->assertViewHas('canViewFull', true);
        $response->assertViewHas('canSeeMoney', true);
        $response->assertSee('Money</button>', false);
    }

    public function test_employee_opening_own_record_gets_full_profile_including_own_money(): void
    {
        $employee = $this->actor('employee');

        $response = $this->get("/app/profile?emp={$employee->id}")->assertOk();

        $response->assertViewHas('canViewFull', true);
        $response->assertViewHas('canSeeMoney', true);
    }

    public function test_no_emp_and_no_employee_record_renders_empty_state_not_a_strangers_profile(): void
    {
        $this->seq++;
        $user = User::create(['name' => 'no-employee', 'email' => "noemployee{$this->seq}@example.com", 'password' => Hash::make('password')]);
        $user->tenants()->attach($this->tenant->id, ['role' => 'hr']);
        $this->actingAs($user)->withSession(['current_tenant' => $this->tenant->id]);

        // An arbitrary employee exists in the tenant; the old showcase fallback would
        // have picked this one up. It must NOT appear on a profile screen with no ?emp=.
        $decoy = $this->staff('Zzyzx Decoyperson');

        $response = $this->get('/app/profile')->assertOk();

        $response->assertViewHas('profile', null);
        $response->assertDontSee($decoy->name);
        $response->assertSee('ready for build-out');
    }

    public function test_tab_hidden_when_its_module_flag_is_off(): void
    {
        // The base TestCase forces every descoped module ON platform-wide (see
        // TestCase::setUp) so the rest of the suite can exercise them; drop back to the
        // real shipped defaults to see module.performance actually OFF.
        $this->useShippedModuleDefaults();

        $this->actor('hr');
        $anyone = $this->staff('Kpi Person');

        // module.performance defaults OFF (see Features::OFF) — kpiGate is false even
        // though HR can otherwise view the full profile.
        $off = $this->get("/app/profile?emp={$anyone->id}")->assertOk();
        $off->assertViewHas('kpiGate', false);
        $off->assertDontSee('KPI · H1');

        app(FeatureManager::class)->setTenant($this->tenant, 'module.performance', true);

        $on = $this->get("/app/profile?emp={$anyone->id}")->assertOk();
        $on->assertViewHas('kpiGate', true);
        $on->assertSee('KPI · H1');
    }

    public function test_sr_manager_opens_someone_three_steps_below_via_transitive_ancestor(): void
    {
        $srManagerLevel = $this->level('Sr Manager', 2);
        $srManager = $this->actor('employee', $srManagerLevel);
        $step1 = $this->staff('Step One', $srManager->id);
        $step2 = $this->staff('Step Two', $step1->id);
        $step3 = $this->staff('Step Three', $step2->id);

        $response = $this->get("/app/profile?emp={$step3->id}")->assertOk();

        $response->assertViewHas('canViewFull', true);
    }

    public function test_director_level_with_zero_reports_opens_a_manager_level_stranger_via_rank(): void
    {
        $directorLevel = $this->level('Director', 1);
        $managerLevel = $this->level('Manager', 3);
        $this->actor('employee', $directorLevel);
        $stranger = $this->staff('Other Branch Manager', null, $managerLevel);

        $response = $this->get("/app/profile?emp={$stranger->id}")->assertOk();

        $response->assertViewHas('canViewFull', true);
    }

    public function test_two_directors_at_the_same_level_get_slim_cards_on_each_other(): void
    {
        $directorLevel = $this->level('Director', 1);
        $this->actor('employee', $directorLevel);
        $peer = $this->staff('Peer Director', null, $directorLevel);

        $response = $this->get("/app/profile?emp={$peer->id}")->assertOk();

        $response->assertViewHas('canViewFull', false);
    }

    public function test_exec_who_manages_a_manager_level_report_sees_them_ancestor_beats_rank(): void
    {
        $execLevel = $this->level('Exec', 4);
        $managerLevel = $this->level('Manager', 3);
        $exec = $this->actor('employee', $execLevel);
        $report = $this->staff('Manager Tagged Report', $exec->id, $managerLevel);

        $response = $this->get("/app/profile?emp={$report->id}")->assertOk();

        $response->assertViewHas('canViewFull', true);
    }

    public function test_viewer_with_a_null_rank_level_gets_a_slim_card_on_a_junior_fail_closed(): void
    {
        $unrankedLevel = $this->level('Some Level', null);
        $internLevel = $this->level('Intern', 6);
        $this->actor('employee', $unrankedLevel);
        $junior = $this->staff('Junior Person', null, $internLevel);

        $response = $this->get("/app/profile?emp={$junior->id}")->assertOk();

        $response->assertViewHas('canViewFull', false);
    }

    public function test_subject_with_no_staff_level_gets_a_slim_card_for_a_rank_only_viewer(): void
    {
        $directorLevel = $this->level('Director', 1);
        $this->actor('employee', $directorLevel);
        $noLevelSubject = $this->staff('No Level Person');

        $response = $this->get("/app/profile?emp={$noLevelSubject->id}")->assertOk();

        $response->assertViewHas('canViewFull', false);
    }
}
