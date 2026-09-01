<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Who gets which dashboard widget, against the seeded tenant.
 *
 * Two redesigns happened here: the four-persona preview switcher became a
 * two-scope (`me` / `company`) switch, and that in turn became ONE grid of
 * role-gated widgets. There is no scope any more — a plain employee simply never
 * has the team widgets built, and no query parameter or stored preference can
 * hand them one (the AK-AUTHZ-02 guard style, now applied per widget).
 *
 * DashboardWidgetsTest covers the same rules against a bare tenant plus the
 * preference endpoint; this file is the seeded-data counterpart.
 */
class DashboardWidgetAccessTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->tenant = Tenant::where('slug', 'unijaya')->firstOrFail();
    }

    private function userWithRole(string $role): User
    {
        $user = User::create([
            'name' => 'Test '.$role,
            'email' => $role.'@persona.test',
            'password' => Hash::make('password'),
        ]);
        $user->tenants()->attach($this->tenant->id, ['role' => $role]);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'name' => 'Test '.$role, 'status' => 'active', 'workload' => 'green',
        ]);

        return $user;
    }

    private function actAs(User $user): void
    {
        $this->actingAs($user)->withSession(['current_tenant' => $this->tenant->id]);
    }

    /** Every widget laid out for the signed-in user. @return list<string> */
    private function widgetsFor(User $user): array
    {
        $this->actAs($user);
        $response = $this->get('/app/dash');
        $response->assertOk();

        return array_merge(...array_values($response->viewData('widgetLayout')));
    }

    public function test_employee_gets_no_team_widget(): void
    {
        $shown = $this->widgetsFor($this->userWithRole('employee'));

        $this->assertContains('tasks', $shown);
        $this->assertNotContains('attendance', $shown);
        $this->assertNotContains('stuck', $shown);
        $this->assertNotContains('pulse', $shown);
    }

    public function test_manager_gets_team_attendance_but_not_the_oversight_pair(): void
    {
        $shown = $this->widgetsFor($this->userWithRole('manager'));

        $this->assertContains('attendance', $shown);
        $this->assertNotContains('stuck', $shown);
        $this->assertNotContains('pulse', $shown);
    }

    public function test_management_and_hr_both_get_the_oversight_widgets(): void
    {
        foreach (['management', 'hr'] as $role) {
            $shown = $this->widgetsFor($this->userWithRole($role));

            $this->assertContains('stuck', $shown, "{$role} must get the stuck-request list.");
            $this->assertContains('pulse', $shown, "{$role} must get the company pulse.");
        }
    }

    /**
     * A stored preference is a display choice, never a grant: an employee whose prefs
     * name a director-only widget still does not get it built or rendered.
     */
    public function test_a_stored_preference_cannot_hand_an_employee_a_gated_widget(): void
    {
        $employee = $this->userWithRole('employee');
        $employee->dashboard_prefs = ['dash' => [
            'hidden' => [],
            'order' => ['left' => ['pulse', 'stuck', 'tasks'], 'right' => []],
        ]];
        $employee->save();

        $shown = $this->widgetsFor($employee);

        $this->assertNotContains('pulse', $shown);
        $this->assertNotContains('stuck', $shown);
        $this->assertContains('tasks', $shown);
    }

    /** A leftover ?scope= from a bookmark is inert now, not an error. */
    public function test_a_stale_scope_query_parameter_is_ignored(): void
    {
        $this->actAs($this->userWithRole('employee'));

        $this->get('/app/dash?scope=company')->assertOk();
    }
}
