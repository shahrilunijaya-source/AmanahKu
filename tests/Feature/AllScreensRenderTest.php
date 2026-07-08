<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkItem;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Proves every navigable screen renders live against seeded data — no screen is a
 * hardcoded mock that 500s when wired to the database. Drives the full seeder and
 * loads each screen as the HR persona (which can reach every module).
 */
class AllScreensRenderTest extends TestCase
{
    use RefreshDatabase;

    private User $hr;

    private Tenant $tenant;

    /** Every screen id reachable from the sidebar, plus the security settings page. */
    private const SCREENS = [
        'dash', 'board', 'team-board', 'timesheets',
        'directory', 'profile', 'orgchart',
        'attendance', 'roster', 'shiftswap', 'leave', 'calendar', 'overtime',
        'events', 'rooms', 'vehicles',
        'payroll', 'loans', 'pettycash', 'benefits', 'wellness',
        'kpi', 'achievements', 'reviews', 'goals', 'skills',
        'onboarding', 'probation', 'resignation', 'offboarding', 'compliance',
        'recruitment', 'referrals', 'cases', 'training', 'learning', 'handbook',
        'documents', 'claims', 'expenses', 'helpdesk', 'travel', 'assets', 'shared-resources',
        'reports', 'surveys', 'ideas', 'workload', 'messages',
        'settings', 'roles', 'audit', 'security', 'position', 'attendance-report', 'leave-setup',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->hr = User::where('email', 'aisyah.rahman@unijaya.example')->firstOrFail();
        $this->tenant = Tenant::where('slug', 'unijaya')->firstOrFail();
    }

    public function test_every_screen_renders_for_the_hr_persona(): void
    {
        $this->actingAs($this->hr)->withSession([
            'current_tenant' => $this->tenant->id,
            'persona' => 'hr',
        ]);

        foreach (self::SCREENS as $screen) {
            $response = $this->get("/app/{$screen}");

            $this->assertContains(
                $response->status(),
                [200],
                "Screen '{$screen}' did not render (status {$response->status()})."
            );
        }
    }

    /**
     * The dashboard persona switcher must only render for privileged roles — the
     * switch is server-gated, so showing it to a plain employee is a dead control.
     * Aisyah is HR on Unijaya but a plain employee on Petron, so one user exercises
     * both sides of the gate.
     */
    public function test_persona_switcher_renders_only_for_privileged_roles(): void
    {
        $petron = Tenant::where('slug', 'petron-tl')->firstOrFail();

        $this->actingAs($this->hr)->withSession([
            'current_tenant' => $this->tenant->id,
            'persona' => 'hr',
        ])->get('/app/dash')->assertSee('persona=manager');

        $this->actingAs($this->hr)->withSession([
            'current_tenant' => $petron->id,
            'persona' => 'employee',
        ])->get('/app/dash')->assertDontSee('persona=manager');
    }

    /**
     * The manager dashboard's Team-status table must (1) list only the viewer's own direct
     * reports, not any six active staff, and (2) show a LIVE workload derived from each
     * report's open work-item count — not the frozen `workload` seed column. Aisyah has three
     * seeded reports (Nurul, Farah, Siti); Siti is on leave. Nurul's frozen column says
     * "Healthy", so loading her with seven open items proves the live derivation wins. The
     * subtitle must echo the same reporting line, replacing the old hardcoded "8 direct reports".
     */
    public function test_manager_team_status_is_scoped_to_reports_with_live_workload(): void
    {
        $nurul = Employee::where('email', 'nurul.iman@unijaya.example')->firstOrFail();

        // Seven open (not-done) items pushes Nurul past the overloaded threshold live, even
        // though her persisted workload column is still the seeded 'green'/'Healthy'.
        for ($i = 0; $i < 7; $i++) {
            WorkItem::create([
                'tenant_id' => $this->tenant->id,
                'employee_id' => $nurul->id,
                'title' => "Open task {$i}",
                'status' => 'todo',
            ]);
        }

        $res = $this->actingAs($this->hr)->withSession([
            'current_tenant' => $this->tenant->id,
            'persona' => 'manager',
        ])->get('/app/dash')->assertOk();

        // A direct report is listed in the Team-status table.
        $res->assertSee('Nurul Iman binti Hassan');

        // Live workload (7 open items) overrides Nurul's frozen 'Healthy' column. Faizal/Hafiz
        // also carry a seeded 'Overloaded' label but are not this manager's reports, so the only
        // "Overloaded" that can reach the manager dash is Nurul's freshly computed one.
        $res->assertSee('Overloaded');

        // Header + table are scoped to the real reporting line (three reports, one on leave),
        // replacing the old hardcoded "8 direct reports" copy. A name-based "don't see" control
        // is unreliable here: any staff member can surface in the Knowledge Bank / recs blobs
        // merged into every screen, so the report COUNT is the scoping signal instead.
        $res->assertSee('3 direct reports');
        $res->assertSee('1 on leave');
        $res->assertDontSee('8 direct reports');
    }

    /**
     * Workload colour/label is derived LIVE from each person's open work-item count via the
     * Employee accessor — the frozen `workload` seed column is no longer read. The seeder gives
     * Faizal eight open items (red), Nurul none (green), and Siti is on leave (grey). The recs
     * engine reads the same signal, so the AI Workforce Intelligence screen and dashboard agree.
     */
    public function test_workload_is_derived_live_from_open_work_items(): void
    {
        $faizal = Employee::where('name', 'Faizal Othman')->firstOrFail();
        $nurul = Employee::where('email', 'nurul.iman@unijaya.example')->firstOrFail();
        $siti = Employee::where('name', 'Siti Khadijah')->firstOrFail();

        $this->assertSame('red', $faizal->workload);
        $this->assertSame('Overloaded', $faizal->workload_label);
        $this->assertSame('green', $nurul->workload);          // zero open items
        $this->assertSame('grey', $siti->workload);            // on leave overrides load

        $overloaded = app(\App\Support\WorkforceInsights::class)->overloaded()->pluck('name');
        $this->assertTrue($overloaded->contains('Faizal Othman'));
        $this->assertTrue($overloaded->contains('Hafiz Zulkifli'));
        $this->assertFalse($overloaded->contains('Nurul Iman binti Hassan'));

        // The most-available peer is a real green (lightest-loaded) employee, not an overloaded one.
        $available = app(\App\Support\WorkforceInsights::class)->available();
        $this->assertNotNull($available);
        $this->assertSame('green', $available->workload);
    }

    /**
     * Disabling the Performance module must also hide the KPI widgets embedded on the
     * profile screen (the "KPI · H1" stat card + the "KPI History" tab), not just the
     * Performance nav group. Before/after around the toggle proves the gate, not the seed.
     *
     * The tab is matched by its rendered element (`KPI History</button>`), not the bare phrase
     * "KPI History": a What's New changelog entry ("switching off Performance also removes …
     * the KPI History tab") renders on every screen and would otherwise false-match the plain
     * string in the assertDontSee below — the widget hides correctly, the prose just names it.
     */
    public function test_disabling_performance_hides_embedded_kpi_widgets_on_profile(): void
    {
        $this->actingAs($this->hr);
        $session = ['current_tenant' => $this->tenant->id, 'persona' => 'hr'];

        // Baseline: Performance ON → KPI widgets are present on the profile.
        $on = $this->withSession($session)->get('/app/profile')->assertOk();
        $on->assertSee('KPI · H1');
        $on->assertSee('KPI History</button>', false);

        // Turn Performance OFF → both KPI widgets disappear; sibling cards untouched.
        app(\App\Services\FeatureManager::class)->setTenant($this->tenant, 'module.performance', false);

        $off = $this->withSession($session)->get('/app/profile')->assertOk();
        $off->assertDontSee('KPI · H1');
        $off->assertDontSee('KPI History</button>', false);
        $off->assertSee('Leave balance');
    }
}
