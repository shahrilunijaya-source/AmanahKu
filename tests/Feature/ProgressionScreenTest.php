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

/** Progression screen: gate, staff picker, selected header, and the four actions. */
class ProgressionScreenTest extends TestCase
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

    public function test_hr_and_director_open_the_screen_manager_and_employee_are_forbidden(): void
    {
        $this->login('hr');
        $this->get('/app/progression')->assertOk()->assertSee('Progression');
        $this->login('director');
        $this->get('/app/progression')->assertOk();
        $this->login('manager');
        $this->get('/app/progression')->assertForbidden();
        $this->login('employee');
        $this->get('/app/progression')->assertForbidden();
    }

    public function test_manager_sidebar_has_no_progression_item(): void
    {
        $this->login('manager');
        $this->get('/app/dash')->assertOk()->assertDontSee('/app/progression');
    }

    public function test_selecting_an_employee_shows_their_header(): void
    {
        $this->login('hr');
        $e = $this->emp('Adibah');
        $this->get("/app/progression?emp={$e->id}&action=confirmation")->assertOk()->assertSee('Adibah')->assertSee('data-action="confirmation"', false);
    }

    public function test_cannot_select_an_employee_from_another_tenant(): void
    {
        $this->login('hr');
        $other = Tenant::create(['slug' => 'zed', 'name' => 'Zed', 'initials' => 'ZD']);
        $stranger = Employee::withoutGlobalScopes()->create(['tenant_id' => $other->id, 'name' => 'Stranger', 'status' => 'active', 'workload' => 'green']);
        $this->get("/app/progression?emp={$stranger->id}")->assertOk()->assertDontSee('Stranger');
    }

    public function test_every_action_pane_renders(): void
    {
        $this->login('hr');
        $e = $this->emp('Adibah', ['salary' => 3000]);
        app(EmploymentRecordService::class)->hire($e);
        foreach (['confirmation', 'update', 'resignation', 'rehire'] as $a) {
            $this->get("/app/progression?emp={$e->id}&action={$a}")->assertOk()->assertSee('data-action="'.$a.'"', false);
        }
    }

    public function test_confirm_via_screen(): void
    {
        $this->login('hr');
        $e = $this->emp('Adibah');
        $this->post("/app/progression/{$e->id}/confirm", ['confirmed_on' => '2026-07-05', 'division' => 'Senior'])
            ->assertRedirect("/app/progression?emp={$e->id}&action=confirmation");
        $this->assertSame('active', $e->fresh()->status);
        $this->assertSame('confirmed', $e->progressions()->first()->type);
    }

    public function test_update_via_screen(): void
    {
        $this->login('hr');
        $e = $this->emp('Adibah', ['status' => 'active']);
        $this->post("/app/progression/{$e->id}/update", ['effective_on' => '2026-03-01', 'update_type' => 'promotion', 'section' => 'PMO', 'remark' => 'Moved'])->assertRedirect();
        $this->assertSame('PMO', $e->fresh()->section);
        $this->assertSame('Moved', $e->progressions()->first()->remark);
        $this->assertSame('promotion', $e->progressions()->first()->snapshot['update_type']);
        $this->get("/app/profile?emp={$e->id}")->assertOk()->assertSee('Promotion');
    }

    public function test_resign_and_rehire_via_screen(): void
    {
        $this->login('hr');
        $e = $this->emp('Adibah', ['status' => 'active']);
        $this->post("/app/progression/{$e->id}/resign", ['resigned_on' => '2026-08-01', 'last_working_day' => '2026-08-31', 'reason' => 'resigned'])->assertRedirect();
        $this->assertSame('resigned', $e->fresh()->status);
        $this->post("/app/progression/{$e->id}/rehire", ['hired_on' => '2026-10-01', 'division' => 'Mid'])->assertRedirect();
        $this->assertSame('probation', $e->fresh()->status);
        $this->assertSame('rehired', $e->progressions()->first()->type);
    }

    public function test_transition_error_returns_to_the_form(): void
    {
        $this->login('hr');
        $e = $this->emp('Adibah', ['status' => 'active']);
        $this->post("/app/progression/{$e->id}/confirm", ['confirmed_on' => '2026-07-05'])->assertSessionHasErrors('confirmed_on');
    }

    public function test_manager_is_forbidden(): void
    {
        $this->login('manager');
        $e = $this->emp('Adibah');
        $this->post("/app/progression/{$e->id}/confirm", ['confirmed_on' => '2026-07-05'])->assertForbidden();
    }

    public function test_other_tenant_employee_cannot_be_acted_on(): void
    {
        $this->login('hr');
        $other = Tenant::create(['slug' => 'zed', 'name' => 'Zed', 'initials' => 'ZD']);
        $stranger = Employee::withoutGlobalScopes()->create(['tenant_id' => $other->id, 'name' => 'S', 'status' => 'probation', 'workload' => 'green']);
        $this->post("/app/progression/{$stranger->id}/confirm", ['confirmed_on' => '2026-07-05'])->assertNotFound();
        $this->assertSame('probation', Employee::withoutGlobalScopes()->find($stranger->id)->status);
    }

    public function test_batch_modes_render_for_hr_and_salary_mode_hidden_from_plain_management(): void
    {
        $this->login('hr');
        $a = $this->emp('A', ['salary' => 1000]);
        $this->get('/app/progression?batch=update')->assertOk()->assertSee('name="fields[]"', false)->assertSee('data-emp="'.$a->id.'"', false)->assertSee(route('progression.batch.update'));
        $this->get('/app/progression?batch=salary')->assertOk()->assertSee('name="mode"', false)->assertSee('data-salary="1000.00"', false)->assertSee(route('progression.batch.salary'));
        $this->login('management');
        $this->get('/app/progression')->assertOk()->assertDontSee('data-batch-mode="salary"', false)->assertSee('data-batch-mode="update"', false);
        $this->get('/app/progression?batch=salary')->assertOk()->assertDontSee('name="mode"', false);
    }
}
