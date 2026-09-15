<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Services\EmploymentRecordService;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Employment + Timeline tabs on the profile and the Employment tab save. */
class ProfileEmploymentTabsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        app(CurrentTenant::class)->set($this->tenant);
    }

    private function login(string $role, array $attrs = []): Employee
    {
        $user = User::create(['name' => ucfirst($role), 'email' => $role.'@example.com', 'password' => Hash::make('password')]);
        $user->tenants()->attach($this->tenant->id, ['role' => $role]);
        $employee = Employee::create(array_merge([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'name' => ucfirst($role), 'status' => 'active', 'workload' => 'green', 'joined_at' => '2025-01-06',
        ], $attrs));
        $this->actingAs($user)->withSession(['current_tenant' => $this->tenant->id]);

        return $employee;
    }

    private function emp(string $name, array $attrs = []): Employee
    {
        return Employee::create(array_merge([
            'tenant_id' => $this->tenant->id, 'name' => $name, 'status' => 'probation', 'workload' => 'green',
            'joined_at' => '2026-01-05',
        ], $attrs));
    }

    public function test_hr_sees_employment_and_timeline_tabs(): void
    {
        $this->login('hr');
        $e = $this->emp('Adibah');
        app(EmploymentRecordService::class)->hire($e);
        $this->get("/app/profile?emp={$e->id}")->assertOk()
            ->assertSee('data-tab="employment"', false)
            ->assertSee('data-tab="timeline"', false);
    }

    public function test_manager_sees_neither_tab(): void
    {
        $m = $this->login('manager');
        $e = $this->emp('Adibah', ['reports_to_id' => $m->id]);
        $this->get("/app/profile?emp={$e->id}")->assertOk()
            ->assertDontSee('data-tab="employment"', false)
            ->assertDontSee('data-tab="timeline"', false);
    }

    public function test_employee_sees_own_tabs_without_salary(): void
    {
        $me = $this->login('employee', ['salary' => 4321, 'status' => 'active']);
        app(EmploymentRecordService::class)->hire($me);
        $this->get('/app/profile')->assertOk()
            ->assertSee('data-tab="employment"', false)
            ->assertSee('data-tab="timeline"', false)
            ->assertDontSee('4,321');
    }

    public function test_hr_updates_employment_and_gets_a_timeline_row(): void
    {
        $this->login('hr');
        $e = $this->emp('Adibah', ['status' => 'active']);
        $this->post("/app/employees/{$e->id}/employment", [
            'effective_on' => '2026-03-01', 'division' => 'Senior', 'pay_mode' => 'monthly', 'payment_term' => 'monthly', 'payment_method' => 'bank',
        ])->assertRedirect("/app/profile?emp={$e->id}&tab=employment");
        $this->assertSame('Senior', $e->fresh()->division);
        $this->assertSame('updated', $e->progressions()->first()->type);
    }

    public function test_manager_cannot_update_employment(): void
    {
        $m = $this->login('manager');
        $e = $this->emp('Adibah', ['reports_to_id' => $m->id, 'status' => 'active']);
        $this->post("/app/employees/{$e->id}/employment", ['effective_on' => '2026-03-01', 'division' => 'X'])->assertForbidden();
    }

    public function test_management_cannot_change_salary_but_hr_can(): void
    {
        $this->login('management');
        $e = $this->emp('Adibah', ['status' => 'active', 'salary' => 5000]);
        $this->post("/app/employees/{$e->id}/employment", ['effective_on' => '2026-03-01', 'salary' => 9000])->assertRedirect();
        $this->assertSame(5000.0, (float) $e->fresh()->salary);

        $this->login('hr');
        $this->post("/app/employees/{$e->id}/employment", ['effective_on' => '2026-03-01', 'salary' => 9000])->assertRedirect();
        $this->assertSame(9000.0, (float) $e->fresh()->salary);
    }

    public function test_reporting_loop_is_rejected(): void
    {
        $this->login('hr');
        $boss = $this->emp('Boss', ['status' => 'active']);
        $e = $this->emp('Adibah', ['status' => 'active', 'reports_to_id' => $boss->id]);
        $this->post("/app/employees/{$boss->id}/employment", ['effective_on' => '2026-03-01', 'reports_to_id' => $e->id])
            ->assertSessionHasErrors('reports_to_id');
    }

    public function test_other_tenant_employee_is_forbidden(): void
    {
        $this->login('hr');
        $other = Tenant::create(['slug' => 'zed', 'name' => 'Zed', 'initials' => 'ZD']);
        $e = Employee::create(['tenant_id' => $other->id, 'name' => 'Stranger', 'status' => 'active', 'workload' => 'green']);
        $this->post("/app/employees/{$e->id}/employment", ['effective_on' => '2026-03-01', 'division' => 'X'])->assertNotFound();
        $this->assertNull($e->fresh()->division);
    }

    public function test_illegal_transition_message_is_shown(): void
    {
        $this->login('hr');
        $e = $this->emp('Adibah', ['status' => 'resigned']);
        $this->post("/app/employees/{$e->id}/employment", ['effective_on' => '2026-03-01', 'division' => 'X'])
            ->assertSessionHasErrors('effective_on');
    }
}
