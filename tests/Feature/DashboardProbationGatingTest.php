<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\ProbationReview;
use App\Models\Tenant;
use App\Models\User;
use App\Services\FeatureManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Probation is a descoped module (Features::OFF) with no presence in the new
 * two-scope dashboard at all — the old HR persona's heading clause and stat tile
 * ("N confirmations due this month") were part of the four-persona dashboard this
 * trait replaced (BuildsDashboardData::dashStats/hrStats, both removed). This test
 * now pins the negative: toggling `module.probation` must not resurrect any
 * probation copy on the dash, on either scope, for an hr-role viewer.
 */
class DashboardProbationGatingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);

        $this->hr = User::create(['name' => 'Hr', 'email' => 'hr@example.com', 'password' => Hash::make('password')]);
        $this->hr->tenants()->attach($this->tenant->id, ['role' => 'hr']);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->hr->id,
            'name' => 'Hr', 'status' => 'active', 'workload' => 'green',
        ]);

        // One employee on probation with a live review ending this month — present
        // regardless of the module flag, to prove the dash never reads it either way.
        $probationer = Employee::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Probationer', 'status' => 'probation', 'workload' => 'green',
        ]);
        ProbationReview::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $probationer->id,
            'status' => 'active',
            'start_date' => now()->subMonths(3)->toDateString(),
            'end_date' => now()->endOfMonth()->toDateString(),
        ]);
    }

    private function hrDash(string $scope = 'company')
    {
        return $this->actingAs($this->hr)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/dash?scope='.$scope);
    }

    public function test_dash_carries_no_probation_copy_when_the_module_is_off(): void
    {
        // Asserts the shipped default, so opt out of the suite-wide "every module on".
        $this->useShippedModuleDefaults();

        $this->hrDash()->assertOk()
            ->assertDontSee('confirmations pending')
            ->assertDontSee('confirmation due this month')
            ->assertDontSee('on probation');
    }

    public function test_dash_still_carries_no_probation_copy_when_the_module_is_on(): void
    {
        app(FeatureManager::class)->setTenant($this->tenant, 'module.probation', true);

        $this->hrDash()->assertOk()
            ->assertDontSee('confirmations pending')
            ->assertDontSee('confirmation due this month')
            ->assertDontSee('on probation');
    }

    public function test_me_scope_also_carries_no_probation_copy(): void
    {
        $this->hrDash('me')->assertOk()
            ->assertDontSee('confirmations pending')
            ->assertDontSee('confirmation due this month')
            ->assertDontSee('on probation');
    }
}
