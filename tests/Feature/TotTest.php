<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\TotSession;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Feature coverage for the TOT sessions board.
 * Harness (setUp / actingInTenant / hrActor) copied from IdeaTest.
 */
class TotTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Tenant $tenant;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create(['name' => 'Demo', 'email' => 'demo@example.com', 'password' => Hash::make('password')]);
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $this->user->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $this->employee = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->user->id,
            'name' => 'Demo', 'status' => 'active', 'workload' => 'green',
        ]);
    }

    private function actingInTenant(): self
    {
        $this->actingAs($this->user)->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    private function hrActor(): User
    {
        $hr = User::create(['name' => 'Boss', 'email' => 'boss@example.com', 'password' => Hash::make('password')]);
        $hr->tenants()->attach($this->tenant->id, ['role' => 'hr']);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $hr->id,
            'name' => 'Boss', 'status' => 'active', 'workload' => 'green',
        ]);

        return $hr;
    }

    private function makeSession(array $overrides = []): TotSession
    {
        return TotSession::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'year' => 2026,
            'month' => 3,
            'presenter_employee_id' => $this->employee->id,
            'title' => 'Install git on our own server',
            'status' => 'done',
            'links' => [['label' => 'Slides', 'url' => 'https://example.com/slides']],
        ], $overrides));
    }

    // ── Schema ────────────────────────────────────────────────────

    public function test_a_session_stores_its_links_as_an_array(): void
    {
        $session = $this->makeSession();

        $this->assertSame('Slides', $session->fresh()->links[0]['label']);
    }

    public function test_one_slot_per_tenant_year_and_month(): void
    {
        $this->makeSession();

        $this->expectException(QueryException::class);

        $this->makeSession(['title' => 'Duplicate slot']);
    }

    public function test_first_saturday_is_computed_per_month(): void
    {
        $this->assertSame('2026-03-07', TotSession::firstSaturday(2026, 3)->toDateString());
        $this->assertSame('2026-08-01', TotSession::firstSaturday(2026, 8)->toDateString());
        $this->assertSame('2027-01-02', TotSession::firstSaturday(2027, 1)->toDateString());
    }
}
