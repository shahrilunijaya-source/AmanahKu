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
 * The HR dashboard must not surface Probation-module data while `module.probation`
 * is off (it is, by default — see Features::OFF and the 2026-07-28 scope decision).
 *
 * The `on probation` figure itself is employees.status, basic HR data, so it stays
 * on both sides of the gate. Only the ProbationReview-derived confirmations count
 * and the copy that promises a confirmation workflow follow the module.
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

        // One employee on probation with a live review ending this month, so both the
        // status count and the confirmations count are non-zero when the gate is open.
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

    private function hrDash()
    {
        return $this->actingAs($this->hr)
            ->withSession(['current_tenant' => $this->tenant->id, 'persona' => 'hr'])
            ->get('/app/dash');
    }

    public function test_heading_and_tile_drop_the_confirmations_copy_when_probation_is_off(): void
    {
        // Asserts the shipped default, so opt out of the suite-wide "every module on".
        $this->useShippedModuleDefaults();

        $dash = $this->hrDash()->assertOk();

        $dash->assertSee('2 headcount · 1 on probation.');
        $dash->assertDontSee('due this month');
        $dash->assertDontSee('confirmations pending');
        // The employment-status figure and its tile are basic HR data — still there.
        $dash->assertSee('On probation');
        $dash->assertSee('of active headcount');
    }

    public function test_heading_and_tile_carry_the_confirmations_clause_when_probation_is_on(): void
    {
        app(FeatureManager::class)->setTenant($this->tenant, 'module.probation', true);

        $dash = $this->hrDash()->assertOk();

        $dash->assertSee('2 headcount · 1 on probation · 1 confirmation due this month.');
        $dash->assertSee('confirmations pending');
    }
}
