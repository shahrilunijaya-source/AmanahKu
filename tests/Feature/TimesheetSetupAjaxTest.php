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
 * The Timesheet Setup screen adds categories via AJAX so the embedded setup
 * frame never full-reloads mid-entry. The store must, when asked for JSON,
 * return the server-rendered row (same partial the page uses) so it can be
 * appended in place — plus the count target to bump.
 */
class TimesheetSetupAjaxTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $this->hr = User::create(['name' => 'Boss', 'email' => 'boss@example.com', 'password' => Hash::make('password')]);
        $this->hr->tenants()->attach($this->tenant->id, ['role' => 'hr']);
        Employee::create(['tenant_id' => $this->tenant->id, 'user_id' => $this->hr->id, 'name' => 'Boss', 'status' => 'active', 'workload' => 'green']);
    }

    private function actingHr(): self
    {
        $this->actingAs($this->hr)->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    public function test_category_ajax_add_returns_a_rendered_row(): void
    {
        $cat = $this->actingHr()->postJson(route('timesheet.admin.categories.store'), [
            'name' => 'Development', 'requires_project' => 1,
        ]);

        $cat->assertOk();
        $this->assertStringContainsString('Development', $cat->json('html'));
        $this->assertSame('#ts-cat-count', $cat->json('count_sel'));
    }
}
