<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\PayrollOpeningFigure;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProfileExperienceTabTest extends TestCase
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
            'tenant_id' => $this->tenant->id, 'name' => $name, 'status' => 'active', 'workload' => 'green', 'joined_at' => '2026-01-05',
        ], $attrs));
    }

    public function test_hr_sees_bank_and_experience_tabs_with_full_account_and_tp3(): void
    {
        $this->login('hr');
        $e = $this->emp('Adibah');
        $e->salaryStructure()->create(['tenant_id' => $this->tenant->id, 'basic_salary' => 3000, 'bank_name' => 'Maybank', 'bank_account_no' => '112233445566']);
        $e->certificates()->create(['tenant_id' => $this->tenant->id, 'name' => 'AWS SAA']);
        PayrollOpeningFigure::create(['tenant_id' => $this->tenant->id, 'employee_id' => $e->id, 'year' => 2025, 'gross' => 12000]);
        $this->get("/app/profile?emp={$e->id}")->assertOk()
            ->assertSee('data-tab="bank"', false)->assertSee('data-tab="experience"', false)
            ->assertSee('112233445566')->assertSee('name="bank_holder_name"', false)
            ->assertSee('AWS SAA')->assertSee('12,000.00')->assertSee('name="pcb_paid"', false);
    }

    public function test_employee_own_view_masks_account_and_hides_tp3_but_can_add_experience(): void
    {
        $me = $this->login('employee');
        $me->salaryStructure()->create(['tenant_id' => $this->tenant->id, 'basic_salary' => 3000, 'bank_name' => 'Maybank', 'bank_account_no' => '112233445566']);
        $this->get('/app/profile')->assertOk()
            ->assertSee('data-tab="bank"', false)->assertSee('data-tab="experience"', false)
            ->assertSee('•••• 5566')->assertDontSee('112233445566')->assertDontSee('name="bank_holder_name"', false)
            ->assertDontSee('name="pcb_paid"', false)->assertSee("/app/employees/{$me->id}/experience/language", false);
    }

    public function test_manager_sees_neither_tab_on_a_report(): void
    {
        $m = $this->login('manager');
        $e = $this->emp('Adibah', ['reports_to_id' => $m->id]);
        $this->get("/app/profile?emp={$e->id}")->assertOk()
            ->assertDontSee('data-tab="bank"', false)->assertDontSee('data-tab="experience"', false);
    }

    public function test_training_moved_out_of_assets_tab_into_experience(): void
    {
        $this->login('hr');
        $e = $this->emp('Adibah');
        $html = $this->get("/app/profile?emp={$e->id}")->assertOk()->getContent();
        $assets = substr($html, strpos($html, "x-show=\"tab === 'assets'\""));
        $this->assertStringNotContainsString('No training records', $assets);
        $start = strpos($html, "x-show=\"tab === 'experience'\"");
        $experience = substr($html, $start, strpos($html, "x-show=\"tab === 'work'\"") - $start);
        $this->assertStringContainsString('No training records', $experience);
    }
}
