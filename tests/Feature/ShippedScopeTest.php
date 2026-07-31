<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Features;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pins the delivery scope: attendance, timesheets, T.A.A. (board), TOT, claims and
 * leave, plus basic HR administration. Everything else is descoped — its code stays
 * in the repo but its screens must read as absent (404) on a default tenant.
 *
 * This is the guard that stops a descoped module drifting back into the app, so it
 * opts out of the suite-wide "every module on" override in Tests\TestCase.
 */
class ShippedScopeTest extends TestCase
{
    use RefreshDatabase;

    /** Screens a default tenant must be able to reach. */
    private const IN_SCOPE = [
        // The six named features.
        'attendance', 'attendance-admin', 'attendance-report',
        'timesheets', 'timesheet-setup', 'timesheet-reports',
        'board', 'team-board',
        'tot', 'tot-roster', 'knowledge-bank',
        'claims', 'claim-approvals',
        'leave', 'calendar', 'leave-setup', 'leave-report',
        // Basic HR administration.
        'directory', 'profile', 'orgchart', 'documents', 'staff-load', 'position',
        'roles', 'setup', 'settings', 'security', 'audit', 'reports', 'messages',
        'dash',
        // Re-scoped in: the welcome wizard links staff straight at the profile test.
        'profile-test', 'profile-test-admin',
    ];

    /** One screen per descoped module — each must read as absent. */
    private const OUT_OF_SCOPE = [
        'roster', 'shiftswap', 'overtime', 'events', 'rooms', 'vehicles',
        'payroll', 'loans', 'pettycash', 'benefits', 'wellness',
        'kpi', 'achievements', 'reviews', 'goals', 'skills',
        'onboarding', 'probation', 'resignation', 'offboarding', 'compliance',
        'recruitment', 'referrals', 'cases', 'training', 'learning', 'handbook',
        'expenses', 'travel', 'helpdesk', 'assets', 'surveys', 'ideas', 'workload',
        'onboarding-content', 'shared-resources',
    ];

    private User $hr;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useShippedModuleDefaults();
        $this->seed(DatabaseSeeder::class);
        $this->hr = User::where('email', 'aisyah.rahman@unijaya.example')->firstOrFail();
        $this->tenant = Tenant::where('slug', 'unijaya')->firstOrFail();
    }

    public function test_in_scope_screens_render_on_a_default_tenant(): void
    {
        foreach (self::IN_SCOPE as $screen) {
            $this->actingAsHr()->get("/app/{$screen}")
                ->assertOk("In-scope screen '{$screen}' must render.");
        }
    }

    public function test_descoped_screens_read_as_absent_on_a_default_tenant(): void
    {
        foreach (self::OUT_OF_SCOPE as $screen) {
            $this->actingAsHr()->get("/app/{$screen}")
                ->assertNotFound("Descoped screen '{$screen}' must 404.");
        }
    }

    public function test_the_sidebar_only_advertises_in_scope_modules(): void
    {
        $html = $this->actingAsHr()->get('/app/dash')->assertOk()->getContent();

        foreach (['Payroll', 'Shift Swaps', 'Room Booking', 'Skills Matrix', 'Helpdesk', 'Suggestion Box'] as $label) {
            $this->assertStringNotContainsString(">{$label}<", $html, "Descoped '{$label}' must not appear in the nav.");
        }
    }

    /**
     * Every screen id named by an enabled module must be in IN_SCOPE, so adding a
     * module to the registry without deciding its scope fails here rather than
     * silently widening the app.
     */
    public function test_no_enabled_module_gates_a_screen_outside_the_scope_list(): void
    {
        foreach (Features::MODULES as $key => [$label, $screens]) {
            if (in_array($key, Features::OFF, true)) {
                continue;
            }

            foreach ($screens as $screen) {
                $this->assertContains($screen, self::IN_SCOPE, "'{$key}' enables '{$screen}', which is not in the shipped scope.");
            }
        }
    }

    private function actingAsHr(): self
    {
        return $this->actingAs($this->hr)->withSession([
            'current_tenant' => $this->tenant->id,
            'persona' => 'hr',
        ]);
    }
}
