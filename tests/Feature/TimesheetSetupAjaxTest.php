<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The Timesheet Setup screen adds categories / projects / sub-pillars via AJAX so the
 * embedded setup frame never full-reloads mid-entry. Each store must, when asked for
 * JSON, return the server-rendered row (same partial the page uses) so it can be
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

    public function test_project_ajax_add_returns_rendered_row(): void
    {
        $res = $this->actingHr()->postJson(route('timesheet.admin.projects.store'), [
            'name' => 'KPT: RMS', 'code' => 'KPT', 'sort' => 0,
        ]);

        $res->assertOk()->assertJsonStructure(['html', 'count_sel']);
        $this->assertStringContainsString('KPT: RMS', $res->json('html'));
        $this->assertSame('#ts-proj-count', $res->json('count_sel'));
        $this->assertDatabaseHas('projects', ['tenant_id' => $this->tenant->id, 'name' => 'KPT: RMS']);
    }

    public function test_category_and_subpillar_ajax_add_return_rows(): void
    {
        $cat = $this->actingHr()->postJson(route('timesheet.admin.categories.store'), [
            'name' => 'Development', 'requires_project' => 1,
        ]);
        $cat->assertOk();
        $this->assertStringContainsString('Development', $cat->json('html'));
        $this->assertSame('#ts-cat-count', $cat->json('count_sel'));

        $project = Project::create(['tenant_id' => $this->tenant->id, 'name' => 'Proj A']);
        $sp = $this->actingHr()->postJson(route('timesheet.admin.subpillars.store', $project), ['name' => 'Frontend']);
        $sp->assertOk();
        $this->assertStringContainsString('Frontend', $sp->json('html'));
        // Sub-pillar count is bumped relative to its project card, so no global selector.
        $this->assertNull($sp->json('count_sel'));
    }

    public function test_validation_error_returns_422_json_not_a_redirect(): void
    {
        $res = $this->actingHr()->postJson(route('timesheet.admin.projects.store'), ['name' => '']);
        $res->assertStatus(422)->assertJsonValidationErrors('name');
    }
}
