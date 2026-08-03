<?php

namespace Tests\Feature;

use App\Models\CompanyCategory;
use App\Models\Employee;
use App\Models\ProfileTestQuestion;
use App\Models\Tenant;
use App\Models\User;
use App\Services\FeatureManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The read-only "everyone's answers" screen. Two gates have to hold together:
 * who may open it (canSeeAll) and whose rows they get (resultsData scoping).
 */
class ProfileTestResultsScreenTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $category = CompanyCategory::where('level', 3)->first();
        $this->tenant = Tenant::create([
            'slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC',
            'company_category_id' => $category?->id,
        ]);
        app(FeatureManager::class)->applyCategoryPackage($this->tenant, 3);
    }

    /** @return array{0:User,1:Employee} */
    private function person(string $name, string $role, array $attrs = []): array
    {
        $u = User::create(['name' => $name, 'email' => strtolower($name).'@example.com', 'password' => Hash::make('password')]);
        $u->tenants()->attach($this->tenant->id, ['role' => $role]);
        $e = Employee::create(array_merge([
            'tenant_id' => $this->tenant->id, 'user_id' => $u->id, 'name' => $name,
            'status' => 'active', 'workload' => 'green',
        ], $attrs));

        return [$u, $e];
    }

    private function taken(Employee $e, string $goal): void
    {
        $e->profileTestResult()->create([
            'self_goal' => $goal,
            'self_weaknesses' => $goal.' weakness',
            'animal_archetype' => 'fox',
            'totals' => ['rabbit' => 0, 'tortoise' => 0, 'fox' => 1, 'sloth' => 0],
            'submitted_at' => now(),
        ]);
    }

    private function visit(User $as)
    {
        return $this->actingAs($as)->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/profile-test-results');
    }

    public function test_hr_sees_every_persons_answers(): void
    {
        [$hr] = $this->person('Hr', 'hr');
        [, $alice] = $this->person('Alice', 'employee');
        [, $bob] = $this->person('Bob', 'employee');
        $this->taken($alice, 'Alice goal');
        $this->taken($bob, 'Bob goal');

        $this->visit($hr)->assertOk()->assertSee('Alice goal')->assertSee('Bob goal');
    }

    public function test_a_director_sees_every_persons_answers(): void
    {
        [$director] = $this->person('Dee', 'director');
        [, $alice] = $this->person('Alice', 'employee');
        $this->taken($alice, 'Alice goal');

        $this->visit($director)->assertOk()->assertSee('Alice goal');
    }

    public function test_a_manager_sees_only_their_own_staff(): void
    {
        [$manager, $managerEmp] = $this->person('Mona', 'manager');
        [, $mine] = $this->person('Alice', 'employee', ['reports_to_id' => $managerEmp->id]);
        [, $notMine] = $this->person('Bob', 'employee');
        $this->taken($mine, 'Alice goal');
        $this->taken($notMine, 'Bob goal');

        $this->visit($manager)->assertOk()->assertSee('Alice goal')->assertDontSee('Bob goal');
    }

    public function test_a_dotted_line_report_counts_as_the_managers_staff(): void
    {
        [$manager, $managerEmp] = $this->person('Mona', 'manager');
        [, $dotted] = $this->person('Alice', 'employee');
        $dotted->additionalManagers()->attach($managerEmp->id);
        $this->taken($dotted, 'Alice goal');

        $this->visit($manager)->assertOk()->assertSee('Alice goal');
    }

    public function test_a_plain_employee_cannot_open_the_screen(): void
    {
        [$staff] = $this->person('Sam', 'employee');
        [, $other] = $this->person('Alice', 'employee');
        $this->taken($other, 'Alice goal');

        $this->visit($staff)->assertForbidden();
    }

    public function test_a_person_who_has_not_taken_the_test_is_listed_as_not_taken(): void
    {
        [$hr] = $this->person('Hr', 'hr');
        $this->person('Alice', 'employee');

        $this->visit($hr)->assertOk()->assertSee('Alice')->assertSee('Not taken');
    }

    public function test_the_screen_shows_each_answer_against_its_question(): void
    {
        [$hr] = $this->person('Hr', 'hr');
        [, $alice] = $this->person('Alice', 'employee');

        $q = ProfileTestQuestion::create(['section' => 'working_style', 'prompt_en' => 'How do you start?', 'position' => 1]);
        $picked = $q->options()->create(['label_en' => 'Straight in', 'animal' => 'rabbit', 'position' => 1]);
        $q->options()->create(['label_en' => 'Plan first', 'animal' => 'tortoise', 'position' => 2]);

        $alice->profileTestResult()->create([
            'working_style_answers' => [$q->id => $picked->id],
            'animal_archetype' => 'rabbit',
            'totals' => ['rabbit' => 1, 'tortoise' => 0, 'fox' => 0, 'sloth' => 0],
            'submitted_at' => now(),
        ]);

        $this->visit($hr)->assertOk()->assertSee('How do you start?')->assertSee('Straight in');
    }
}
