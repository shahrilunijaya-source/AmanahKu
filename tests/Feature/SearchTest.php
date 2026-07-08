<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SearchTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create(['name' => 'Demo', 'email' => 'demo@example.com', 'password' => Hash::make('password')]);
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $this->user->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        // Job title now comes from the assigned Position band, not free text.
        $band = Position::create(['tenant_id' => $this->tenant->id, 'title' => 'HR Manager', 'max_salary' => 0]);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->user->id,
            'name' => 'Aisyah Rahman', 'position_id' => $band->id, 'status' => 'active', 'workload' => 'green', 'initials' => 'AR',
        ]);
    }

    private function actingInTenant(): self
    {
        $this->actingAs($this->user)->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    public function test_search_returns_matching_employees(): void
    {
        $this->actingInTenant()->getJson('/app/search?q=Aisyah')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Aisyah Rahman', 'position' => 'HR Manager']);
    }

    public function test_search_matches_band_title_department_and_branch(): void
    {
        $dept = Department::create(['tenant_id' => $this->tenant->id, 'name' => 'Operations']);
        $branch = Branch::create(['tenant_id' => $this->tenant->id, 'name' => 'Penang HQ', 'state' => 'Penang']);
        $band = Position::create(['tenant_id' => $this->tenant->id, 'title' => 'Field Engineer', 'max_salary' => 0]);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Search Target',
            'position_id' => $band->id, 'department_id' => $dept->id, 'branch_id' => $branch->id,
            'status' => 'active', 'workload' => 'green', 'initials' => 'ST',
        ]);

        foreach (['Field Engineer', 'Operations', 'Penang HQ'] as $term) {
            $this->actingInTenant()->getJson('/app/search?q='.urlencode($term))
                ->assertOk()
                ->assertJsonFragment(['name' => 'Search Target']);
        }
    }

    public function test_search_is_tenant_scoped(): void
    {
        // An employee in another tenant must not surface.
        $other = Tenant::create(['slug' => 'other', 'name' => 'Other', 'initials' => 'OT']);
        Employee::create(['tenant_id' => $other->id, 'name' => 'Zarina Outsider', 'status' => 'active', 'workload' => 'green']);

        $this->actingInTenant()->getJson('/app/search?q=Zarina')->assertOk()->assertJsonCount(0);
    }

    public function test_empty_query_returns_no_results(): void
    {
        $this->actingInTenant()->getJson('/app/search?q=')->assertOk()->assertJsonCount(0);
    }
}
