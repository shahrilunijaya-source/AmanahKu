<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The company-wide analytics hub (headcount, department capacity, workload split)
 * sat unguarded: no abort_unless(canSeeAll) on the screen, and no nav-hide for plain
 * employees, unlike every other screen in the same Insights/oversight family.
 */
class ReportsScreenTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $employeeUser;

    private User $hrUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'slug' => 'alpha',
            'name' => 'Alpha',
            'initials' => 'AL',
        ]);

        $this->employeeUser = User::create([
            'name' => 'Employee User',
            'email' => 'employee@example.com',
            'password' => Hash::make('password'),
        ]);
        $this->employeeUser->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        Employee::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->employeeUser->id,
            'name' => 'Employee User',
            'status' => 'active',
            'workload' => 'green',
        ]);

        $this->hrUser = User::create([
            'name' => 'HR User',
            'email' => 'hr@example.com',
            'password' => Hash::make('password'),
        ]);
        $this->hrUser->tenants()->attach($this->tenant->id, ['role' => 'hr']);
        Employee::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->hrUser->id,
            'name' => 'HR User',
            'status' => 'active',
            'workload' => 'green',
        ]);
    }

    public function test_a_plain_employee_cannot_open_the_reports_screen(): void
    {
        $response = $this->actingAs($this->employeeUser)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/reports');

        $response->assertForbidden();
    }

    public function test_hr_can_open_the_reports_screen(): void
    {
        $response = $this->actingAs($this->hrUser)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/reports');

        $response->assertOk();
    }

    public function test_a_plain_employee_does_not_see_the_reports_link_in_the_sidebar(): void
    {
        $response = $this->actingAs($this->employeeUser)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/dash');

        $response->assertOk();
        $response->assertDontSee(route('app.screen', 'reports'), false);
    }

    public function test_hr_sees_the_reports_link_in_the_sidebar(): void
    {
        $response = $this->actingAs($this->hrUser)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/dash');

        $response->assertOk();
        $response->assertSee(route('app.screen', 'reports'), false);
    }
}
