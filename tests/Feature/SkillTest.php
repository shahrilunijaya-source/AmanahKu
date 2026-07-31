<?php

namespace Tests\Feature;

use App\Http\Controllers\SkillController;
use App\Models\Employee;
use App\Models\EmployeeSkill;
use App\Models\Skill;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SkillSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Feature coverage for the Skills matrix / competency module.
 *
 * Drives the real DatabaseSeeder + SkillSeeder so the seeded catalogue and
 * ratings exercise the matrix and gap analysis end-to-end. Aisyah (HR) is the
 * privileged actor; a plain employee is hand-built to test the data-layer gate.
 */
class SkillTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Tenant $tenant;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->seed(SkillSeeder::class);

        $this->user = User::where('email', 'aisyah.rahman@unijaya.example')->firstOrFail();
        $this->tenant = Tenant::where('slug', 'unijaya')->firstOrFail();
        $this->employee = Employee::where('tenant_id', $this->tenant->id)
            ->where('user_id', $this->user->id)->firstOrFail();
    }

    private function actingInTenant(): self
    {
        $this->actingAs($this->user)->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    /** A plain employee (no privileged role) attached to the same tenant. */
    private function plainEmployee(): User
    {
        $user = User::create(['name' => 'Plain', 'email' => 'plain@example.com', 'password' => Hash::make('password')]);
        $user->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'name' => 'Plain Staff', 'status' => 'active', 'workload' => 'green',
        ]);

        return $user;
    }

    // ── Self-rating ───────────────────────────────────────────────

    public function test_employee_self_rates_a_skill(): void
    {
        // Arrange — a skill the actor has not yet rated.
        $skill = Skill::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Negotiation', 'category' => 'Communication',
        ]);

        // Act
        $response = $this->actingInTenant()->post('/app/skills/rate', [
            'skill_id' => $skill->id,
            'level' => 4,
        ]);

        // Assert
        $response->assertRedirect();
        $this->assertDatabaseHas('employee_skills', [
            'tenant_id' => $this->tenant->id,
            'skill_id' => $skill->id,
            'employee_id' => $this->employee->id,
            'level' => 4,
            'verified' => false,
        ]);
    }

    public function test_re_rating_updates_the_same_row_not_a_duplicate(): void
    {
        // Arrange — actor rates once.
        $skill = Skill::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Negotiation', 'category' => 'Communication',
        ]);
        $this->actingInTenant()->post('/app/skills/rate', ['skill_id' => $skill->id, 'level' => 2]);

        // Act — actor re-rates the same skill.
        $response = $this->actingInTenant()->post('/app/skills/rate', ['skill_id' => $skill->id, 'level' => 5]);

        // Assert — one row, updated in place (unique constraint respected).
        $response->assertRedirect();
        $this->assertSame(1, EmployeeSkill::where('skill_id', $skill->id)
            ->where('employee_id', $this->employee->id)->count());
        $this->assertDatabaseHas('employee_skills', [
            'skill_id' => $skill->id, 'employee_id' => $this->employee->id, 'level' => 5,
        ]);
    }

    public function test_rate_requires_valid_level(): void
    {
        $skill = Skill::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Negotiation', 'category' => 'Communication',
        ]);

        $this->actingInTenant()->post('/app/skills/rate', ['skill_id' => $skill->id, 'level' => 9])
            ->assertSessionHasErrors('level');
    }

    // ── Catalogue management ──────────────────────────────────────

    public function test_privileged_user_adds_a_catalogue_skill(): void
    {
        // Act — Aisyah is HR.
        $response = $this->actingInTenant()->post('/app/skills/catalog', [
            'name' => 'Change Management',
            'category' => 'Leadership',
            'description' => 'Guiding teams through organisational change.',
        ]);

        // Assert
        $response->assertRedirect();
        $this->assertDatabaseHas('skills', [
            'tenant_id' => $this->tenant->id,
            'name' => 'Change Management',
            'category' => 'Leadership',
        ]);
    }

    public function test_plain_employee_cannot_add_a_catalogue_skill(): void
    {
        // Arrange
        $plain = $this->plainEmployee();

        // Act
        $response = $this->actingAs($plain)->withSession(['current_tenant' => $this->tenant->id])
            ->post('/app/skills/catalog', ['name' => 'Sneaky Skill', 'category' => 'Technical']);

        // Assert
        $response->assertForbidden();
        $this->assertDatabaseMissing('skills', ['name' => 'Sneaky Skill']);
    }

    // ── Verification ──────────────────────────────────────────────

    public function test_privileged_user_verifies_a_rating(): void
    {
        // Arrange — an unverified rating for a colleague.
        $colleague = Employee::where('tenant_id', $this->tenant->id)
            ->where('id', '!=', $this->employee->id)->firstOrFail();
        $skill = Skill::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Negotiation', 'category' => 'Communication',
        ]);
        $rating = EmployeeSkill::create([
            'tenant_id' => $this->tenant->id, 'skill_id' => $skill->id,
            'employee_id' => $colleague->id, 'level' => 3, 'verified' => false,
        ]);

        // Act
        $response = $this->actingInTenant()->post("/app/skills/verify/{$rating->id}");

        // Assert
        $response->assertRedirect();
        $fresh = $rating->fresh();
        $this->assertTrue((bool) $fresh->verified);
        $this->assertSame($this->employee->id, $fresh->verified_by_id);
    }

    public function test_privileged_user_cannot_verify_their_own_rating(): void
    {
        // Arrange — Aisyah (HR) self-rates a skill, then tries to verify it herself.
        $skill = Skill::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Negotiation', 'category' => 'Communication',
        ]);
        $rating = EmployeeSkill::create([
            'tenant_id' => $this->tenant->id, 'skill_id' => $skill->id,
            'employee_id' => $this->employee->id, 'level' => 4, 'verified' => false,
        ]);

        // Act
        $response = $this->actingInTenant()->post("/app/skills/verify/{$rating->id}");

        // Assert — segregation of duties: self-verification blocked, stays unverified.
        $response->assertForbidden();
        $this->assertFalse((bool) $rating->fresh()->verified);
    }

    public function test_plain_employee_cannot_verify_a_rating(): void
    {
        // Arrange
        $plain = $this->plainEmployee();
        $skill = Skill::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Negotiation', 'category' => 'Communication',
        ]);
        $rating = EmployeeSkill::create([
            'tenant_id' => $this->tenant->id, 'skill_id' => $skill->id,
            'employee_id' => $this->employee->id, 'level' => 3, 'verified' => false,
        ]);

        // Act
        $response = $this->actingAs($plain)->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/skills/verify/{$rating->id}");

        // Assert
        $response->assertForbidden();
        $this->assertFalse((bool) $rating->fresh()->verified);
    }

    // ── Data-layer gate on screenData ─────────────────────────────

    public function test_plain_employee_screen_data_excludes_the_team_matrix(): void
    {
        // Arrange
        $plain = $this->plainEmployee();
        $controller = new SkillController;
        $employee = Employee::where('user_id', $plain->id)->firstOrFail();

        // Act — call screenData with a non-privileged role in request attributes.
        $request = Request::create('/app/skills');
        $request->attributes->set('tenantRole', 'employee');
        $request->attributes->set('employee', $employee);
        app(CurrentTenant::class)->set($this->tenant);
        $data = $controller->screenData($request, $employee);

        // Assert — no team matrix leaks to a plain employee.
        $this->assertFalse($data['canViewMatrix']);
        $this->assertTrue($data['matrixEmployees']->isEmpty());
        $this->assertEmpty($data['matrix']);
        $this->assertTrue($data['gaps']->isEmpty());
    }
}
