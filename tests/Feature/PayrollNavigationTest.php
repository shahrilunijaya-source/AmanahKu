<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Services\FeatureManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PayrollNavigationTest extends TestCase
{
    use RefreshDatabase;

    private const HR_SCREENS = ['payroll-transaction', 'payroll-process', 'payroll-review', 'payroll-payment', 'payroll-form'];

    private const ALL_SCREENS = ['payroll', 'payroll-my', 'payroll-transaction', 'payroll-process', 'payroll-review', 'payroll-payment', 'payroll-form'];

    private Tenant $tenant;

    private User $hr;

    private User $manager;

    private User $empUser;

    private Employee $emp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);

        $this->hr = User::create(['name' => 'Boss', 'email' => 'boss@example.com', 'password' => Hash::make('password')]);
        $this->hr->tenants()->attach($this->tenant->id, ['role' => 'hr']);
        Employee::create(['tenant_id' => $this->tenant->id, 'user_id' => $this->hr->id, 'name' => 'Boss', 'status' => 'active', 'workload' => 'green']);

        $this->manager = User::create(['name' => 'Lead', 'email' => 'lead@example.com', 'password' => Hash::make('password')]);
        $this->manager->tenants()->attach($this->tenant->id, ['role' => 'manager']);
        Employee::create(['tenant_id' => $this->tenant->id, 'user_id' => $this->manager->id, 'name' => 'Lead', 'status' => 'active', 'workload' => 'green']);

        $this->empUser = User::create(['name' => 'Worker', 'email' => 'worker@example.com', 'password' => Hash::make('password')]);
        $this->empUser->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $this->emp = Employee::create(['tenant_id' => $this->tenant->id, 'user_id' => $this->empUser->id, 'name' => 'Worker', 'status' => 'active', 'workload' => 'green', 'salary' => 4000]);
    }

    private function acting(User $user): self
    {
        $this->actingAs($user)->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    public function test_hr_opens_every_payroll_screen(): void
    {
        foreach (array_merge(['payroll-my'], self::HR_SCREENS) as $screen) {
            $this->acting($this->hr)->get("/app/{$screen}")->assertOk();
        }
    }

    public function test_employee_opens_my_payroll_but_not_the_hr_screens(): void
    {
        $this->acting($this->empUser)->get('/app/payroll-my')->assertOk();

        foreach (self::HR_SCREENS as $screen) {
            $this->acting($this->empUser)->get("/app/{$screen}")->assertForbidden();
        }
    }

    public function test_manager_cannot_open_the_hr_screens(): void
    {
        foreach (self::HR_SCREENS as $screen) {
            $this->acting($this->manager)->get("/app/{$screen}")->assertForbidden();
        }
    }

    public function test_parent_payroll_lands_by_role(): void
    {
        $this->acting($this->hr)->get('/app/payroll')->assertRedirect('/app/payroll-process');
        $this->acting($this->empUser)->get('/app/payroll')->assertRedirect('/app/payroll-my');

        $director = User::create(['name' => 'Chief', 'email' => 'chief@example.com', 'password' => Hash::make('password')]);
        $director->tenants()->attach($this->tenant->id, ['role' => 'director']);
        $this->acting($director)->get('/app/payroll')->assertRedirect('/app/payroll-process');
        $this->acting($director)->get('/app/payroll-payment')->assertOk();
    }

    public function test_sidebar_lists_the_six_children_for_hr_and_only_my_payroll_for_staff(): void
    {
        $hr = $this->acting($this->hr)->get('/app/dash')->assertOk()->getContent();
        foreach (self::HR_SCREENS as $screen) {
            $this->assertStringContainsString(route('app.screen', ['screen' => $screen]), $hr);
        }
        $this->assertStringContainsString(route('app.screen', ['screen' => 'payroll-my']), $hr);

        $staff = $this->acting($this->empUser)->get('/app/dash')->assertOk()->getContent();
        $this->assertStringContainsString(route('app.screen', ['screen' => 'payroll-my']), $staff);
        foreach (self::HR_SCREENS as $screen) {
            $this->assertStringNotContainsString(route('app.screen', ['screen' => $screen]).'"', $staff);
        }
    }

    public function test_module_off_hides_all_seven_ids(): void
    {
        app(FeatureManager::class)->setTenant($this->tenant, 'module.payroll', false);

        foreach (self::ALL_SCREENS as $screen) {
            $this->acting($this->hr)->get("/app/{$screen}")->assertNotFound();
        }

        $html = $this->acting($this->hr)->get('/app/dash')->assertOk()->getContent();
        $this->assertStringNotContainsString('>Payroll<', $html);
        $this->assertStringNotContainsString(route('app.screen', ['screen' => 'payroll-my']), $html);
    }
}
